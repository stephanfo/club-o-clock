<?php

namespace App\Services;

use App\Models\User;
use App\Support\AgeCategory;
use App\Support\Logging\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

// Import CSV adhérents (PRD §3.1, §4.2 ; ROADMAP_DEV J6.5). Début de saison : parsing,
// validation ligne à ligne, classification création/mise à jour, rapport d'erreurs, commit en lot.
//
// Décisions produit (cadrées en amont) :
//  - Catégorie principale TOUJOURS dérivée de la date de naissance (la colonne CSV « catégorie »,
//    présente pour la lisibilité humaine, n'est jamais lue) → cohérent avec création + nouvelle année.
//  - Doublon : email déjà en base ⇒ MISE À JOUR (nom/prénom/DOB + recalcul catégorie), jamais
//    création. Les lignes sans email (mineurs P1) sont toujours des créations.
//  - TOUT OU RIEN : une seule erreur bloque l'import entier ; aucune ligne créée tant que le
//    fichier n'est pas 100 % propre. Le rapport liste toutes les lignes fautives.
//  - Garant : un mineur dont le parent_email ne résout aucun adulte (ni en base, ni ligne adulte
//    du même CSV) ⇒ ligne en erreur. parent_email vide ⇒ mineur créé sans garant (rattachable
//    ensuite, comme la création manuelle).
class MemberImportService
{
    /** Colonnes attendues → clé canonique. La « catégorie » est acceptée mais ignorée (dérivée du DOB). */
    private const HEADER_ALIASES = [
        'nom' => 'last_name',
        'last_name' => 'last_name',
        'prenom' => 'first_name',
        'first_name' => 'first_name',
        'email' => 'email',
        'mail' => 'email',
        'courriel' => 'email',
        'categorie' => '_ignored',
        'cat' => '_ignored',
        'date_nais' => 'dob',
        'date_naissance' => 'dob',
        'naissance' => 'dob',
        'ddn' => 'dob',
        'dob' => 'dob',
        'parent_email' => 'parent_email',
        'parent' => 'parent_email',
        'parent_mail' => 'parent_email',
        'email_parent' => 'parent_email',
        'garant' => 'parent_email',
    ];

    private const REQUIRED_HEADERS = ['last_name', 'first_name', 'dob'];

    /**
     * Parse + valide + classe le contenu CSV sans rien muter. Le résultat sert à la fois à l'aperçu
     * (compteurs + rapport d'erreurs) et au commit (clé `rows`).
     *
     * @return array{
     *     fatal: ?string,
     *     total: int, new: int, update: int,
     *     errors: array<int,array{line:int,message:string}>,
     *     preview: array<int,array<string,string>>,
     *     rows: array<int,array<string,mixed>>
     * }
     */
    public function analyze(string $csv): array
    {
        $empty = ['fatal' => null, 'total' => 0, 'new' => 0, 'update' => 0, 'errors' => [], 'preview' => [], 'rows' => []];

        $lines = $this->splitLines($csv);
        if ($lines === []) {
            return ['fatal' => 'Fichier vide.'] + $empty;
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $rawHeaders = $this->parseLine(array_shift($lines), $delimiter);
        $map = $this->mapHeaders($rawHeaders);

        $missing = array_diff(self::REQUIRED_HEADERS, array_values($map));
        if ($missing !== []) {
            $labels = ['last_name' => 'nom', 'first_name' => 'prénom', 'dob' => 'date_nais'];
            $names = array_map(fn ($k) => $labels[$k] ?? $k, $missing);

            return ['fatal' => 'Colonnes obligatoires manquantes : '.implode(', ', $names).'.'] + $empty;
        }

        // 1re passe de lecture : normalise chaque ligne en enregistrement brut + n° de ligne CSV.
        $records = [];
        $lineNo = 1; // la ligne 1 est l'en-tête
        foreach ($lines as $line) {
            $lineNo++;
            if (trim($line) === '') {
                continue; // lignes blanches ignorées (pas comptées)
            }
            $records[] = $this->readRecord($this->parseLine($line, $delimiter), $map, $lineNo);
        }

        if ($records === []) {
            return ['fatal' => 'Aucune ligne de données.'] + $empty;
        }

        // Ensembles de résolution partagés, calculés une fois.
        $emailCounts = $this->emailOccurrences($records);
        $guardianSet = $this->resolvableGuardianEmails($records);
        $existingByEmail = $this->existingUsersByEmail($records);

        $errors = [];
        $new = 0;
        $update = 0;
        foreach ($records as &$r) {
            $this->classify($r, $emailCounts, $guardianSet, $existingByEmail);
            if ($r['action'] === 'error') {
                foreach ($r['errors'] as $msg) {
                    $errors[] = ['line' => $r['line'], 'message' => $msg];
                }
            } elseif ($r['action'] === 'update') {
                $update++;
            } else {
                $new++;
            }
        }
        unset($r);

        // Aperçu : 3 premières lignes de données, mappées sur les colonnes connues.
        $preview = array_map(fn ($r) => [
            'last_name' => $r['data']['last_name'],
            'first_name' => $r['data']['first_name'],
            'email' => $r['data']['email'] ?? '',
            'dob' => $r['raw_dob'],
            'parent_email' => $r['parent_email'] ?? '',
        ], array_slice($records, 0, 3));

        return [
            'fatal' => null,
            'total' => count($records),
            'new' => $new,
            'update' => $update,
            'errors' => $errors,
            'preview' => $preview,
            'rows' => $records,
        ];
    }

    /**
     * Commit en lot, transactionnel (tout ou rien). Deux passes pour résoudre les liens de tutelle :
     * pass A crée/met à jour adultes + mineurs sans garant (les garants entrent en base) ; pass B
     * crée les mineurs avec garant en résolvant le parent désormais présent.
     *
     * @return array{created:int,updated:int,created_ids:list<int>}
     *
     * @throws RuntimeException si l'analyse comporte des erreurs (garde redondante avec l'UI).
     */
    public function commit(array $analysis, User $actor): array
    {
        if ($analysis['fatal'] !== null || $analysis['errors'] !== []) {
            throw new RuntimeException('Import refusé : le fichier comporte des erreurs.');
        }

        $service = app(MemberService::class);

        return DB::transaction(function () use ($analysis, $actor, $service) {
            $created = 0;
            $updated = 0;
            /** @var list<int> $createdIds ids des comptes créés — l'appelant leur envoie l'invitation. */
            $createdIds = [];

            // Pass A — tout sauf les créations de mineurs avec garant.
            foreach ($analysis['rows'] as $r) {
                $isChildWithGuardian = $r['action'] === 'create' && $r['is_minor'] && ($r['parent_email'] ?? '') !== '';
                if ($isChildWithGuardian) {
                    continue;
                }

                if ($r['action'] === 'update') {
                    $service->importUpdate(User::findOrFail($r['existing_id']), $r['data'], $actor);
                    $updated++;
                } else {
                    $createdIds[] = $service->create($r['data'] + ['roles' => ['athlete']], $actor)->id;
                    $created++;
                }
            }

            // Pass B — créations de mineurs avec garant (les parents existent désormais en base).
            // Une seule requête pour bâtir email→id, au lieu d'un scan par enfant.
            $guardians = $this->guardianMap($analysis['rows']);
            foreach ($analysis['rows'] as $r) {
                if (! ($r['action'] === 'create' && $r['is_minor'] && ($r['parent_email'] ?? '') !== '')) {
                    continue;
                }

                $guardianId = $guardians[$r['parent_email']] ?? null;
                if ($guardianId === null) {
                    // Validé en amont ; garde d'intégrité → rollback de tout l'import.
                    throw new RuntimeException("Garant introuvable au commit pour la ligne {$r['line']}.");
                }

                $createdIds[] = $service->create($r['data'] + ['roles' => ['athlete'], 'guardian_id' => $guardianId], $actor)->id;
                $created++;
            }

            // Synthèse métier (les créations individuelles sont déjà tracées par MemberService).
            ActivityLogger::record('members_imported', $actor, ['created' => $created, 'updated' => $updated]);

            return ['created' => $created, 'updated' => $updated, 'created_ids' => $createdIds];
        });
    }

    // ── Lecture / normalisation ──

    /**
     * Découpe en lignes (la 1re est l'en-tête). Les lignes blanches internes sont conservées ici et
     * ignorées plus loin ; on ne retire qu'un éventuel saut de ligne final. @return array<int,string>
     */
    private function splitLines(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv); // BOM UTF-8
        $csv = str_replace(["\r\n", "\r"], "\n", rtrim($csv, "\r\n"));

        return trim($csv) === '' ? [] : explode("\n", $csv);
    }

    private function detectDelimiter(string $headerLine): string
    {
        return substr_count($headerLine, ';') > substr_count($headerLine, ',') ? ';' : ',';
    }

    /**
     * Découpe une ligne CSV. `escape: ''` désactive l'échappement antislash legacy (déprécié dès
     * PHP 8.4, et qui fusionne les champs sur un `\` final) — on s'en tient au guillemet RFC 4180.
     *
     * @return array<int,string>
     */
    private function parseLine(string $line, string $delimiter): array
    {
        return str_getcsv($line, $delimiter, '"', '');
    }

    /**
     * @param  array<int,string>  $rawHeaders
     * @return array<int,string> position → clé canonique
     */
    private function mapHeaders(array $rawHeaders): array
    {
        $map = [];
        foreach ($rawHeaders as $i => $h) {
            $key = $this->normalize($h);
            $map[$i] = self::HEADER_ALIASES[$key] ?? '_ignored';
        }

        return $map;
    }

    private function normalize(string $value): string
    {
        $ascii = Str::ascii(trim($value));
        $ascii = strtolower($ascii);
        $ascii = preg_replace('/[^a-z0-9]+/', '_', $ascii);

        return trim($ascii, '_');
    }

    /**
     * @param  array<int,string>  $cells
     * @param  array<int,string>  $map
     * @return array<string,mixed>
     */
    private function readRecord(array $cells, array $map, int $lineNo): array
    {
        $fields = ['last_name' => '', 'first_name' => '', 'email' => '', 'dob' => '', 'parent_email' => ''];
        foreach ($map as $i => $key) {
            if ($key === '_ignored') {
                continue;
            }
            $fields[$key] = trim($cells[$i] ?? '');
        }

        $dob = $this->parseDob($fields['dob']);

        return [
            'line' => $lineNo,
            'raw_dob' => $fields['dob'],
            'dob' => $dob, // Carbon|null
            'is_minor' => $dob !== null && AgeCategory::isMinor($dob),
            'email_lc' => $fields['email'] !== '' ? mb_strtolower($fields['email']) : null,
            'parent_email' => $fields['parent_email'] !== '' ? mb_strtolower($fields['parent_email']) : null,
            'data' => [
                'first_name' => $fields['first_name'],
                'last_name' => $fields['last_name'],
                'dob' => $dob?->toDateString() ?? '',
                'email' => $fields['email'] !== '' ? $fields['email'] : null,
            ],
            'action' => 'create', // affiné par classify()
            'errors' => [],
            'existing_id' => null,
        ];
    }

    private function parseDob(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                // Carbon lève une exception sur entrée non conforme : on essaie le format suivant.
                $d = Carbon::createFromFormat('!'.$format, $value);
            } catch (\Throwable) {
                continue;
            }
            if ($d->format($format) === $value) {
                // Bornes de plausibilité alignées sur les formulaires (MemberCreate/MemberShow) :
                // future ou antérieure à 1900 → traitée comme invalide.
                if ($d->gte(Carbon::today()) || $d->year <= 1900) {
                    return null;
                }

                return $d->startOfDay();
            }
        }

        return null;
    }

    // ── Résolution / classification ──

    /** @param array<int,array<string,mixed>> $records @return array<string,int> email lc → occurrences */
    private function emailOccurrences(array $records): array
    {
        $counts = [];
        foreach ($records as $r) {
            if ($r['email_lc'] !== null) {
                $counts[$r['email_lc']] = ($counts[$r['email_lc']] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Emails capables de jouer le rôle de garant : adultes existants en base + lignes adultes du CSV
     * (non-mineur avec email). @param array<int,array<string,mixed>> $records @return array<string,true>
     */
    private function resolvableGuardianEmails(array $records): array
    {
        $set = [];
        foreach ($records as $r) {
            // Source de garant valide : ligne adulte au DOB exploitable (une ligne au DOB invalide a
            // is_minor=false mais ne doit pas servir de garant — son erreur bloquera l'import).
            if ($r['dob'] !== null && ! $r['is_minor'] && $r['email_lc'] !== null) {
                $set[$r['email_lc']] = true;
            }
        }

        $referenced = array_filter(array_map(fn ($r) => $r['parent_email'], $records));
        if ($referenced !== []) {
            User::query()->whereNull('anonymized_at')->where('is_minor', false)->whereNotNull('email')
                ->get(['email'])
                ->each(function (User $u) use (&$set, $referenced) {
                    $lc = mb_strtolower($u->email);
                    if (in_array($lc, $referenced, true)) {
                        $set[$lc] = true;
                    }
                });
        }

        return $set;
    }

    /** @param array<int,array<string,mixed>> $records @return array<string,int> email lc → user id (non anonymisé) */
    private function existingUsersByEmail(array $records): array
    {
        $emails = array_values(array_filter(array_map(fn ($r) => $r['email_lc'], $records)));
        if ($emails === []) {
            return [];
        }

        $byEmail = [];
        User::query()->whereNull('anonymized_at')->whereNotNull('email')->get(['id', 'email'])
            ->each(function (User $u) use (&$byEmail, $emails) {
                $lc = mb_strtolower($u->email);
                if (in_array($lc, $emails, true)) {
                    $byEmail[$lc] = $u->id;
                }
            });

        return $byEmail;
    }

    /**
     * @param  array<string,mixed>  $r  (par référence)
     * @param  array<string,int>  $emailCounts
     * @param  array<string,true>  $guardianSet
     * @param  array<string,int>  $existingByEmail
     */
    private function classify(array &$r, array $emailCounts, array $guardianSet, array $existingByEmail): void
    {
        $errors = [];

        if ($r['data']['last_name'] === '') {
            $errors[] = 'nom manquant';
        }
        if ($r['data']['first_name'] === '') {
            $errors[] = 'prénom manquant';
        }

        if ($r['dob'] === null) {
            $errors[] = $r['raw_dob'] === ''
                ? 'date de naissance manquante'
                : "date de naissance invalide : « {$r['raw_dob']} » (formats acceptés : AAAA-MM-JJ, JJ/MM/AAAA)";
        } elseif ($r['dob']->gte(Carbon::today())) {
            $errors[] = 'date de naissance dans le futur';
        }

        $email = $r['email_lc'];
        if ($email !== null) {
            if (filter_var($r['data']['email'], FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = "email invalide : « {$r['data']['email']} »";
            } elseif (($emailCounts[$email] ?? 0) > 1) {
                $errors[] = "email en double dans le fichier : « {$r['data']['email']} »";
            }
        } elseif (! $r['is_minor'] && $r['dob'] !== null) {
            // Adulte sans email : interdit (un compte adulte a toujours un identifiant de connexion).
            $errors[] = 'email requis pour un adulte';
        }

        // Garant : seuls les mineurs avec un parent_email renseigné sont concernés.
        if ($r['is_minor'] && $r['parent_email'] !== null && ! isset($guardianSet[$r['parent_email']])) {
            $errors[] = "garant introuvable : « {$r['parent_email']} »";
        }

        if ($errors !== []) {
            $r['action'] = 'error';
            $r['errors'] = $errors;

            return;
        }

        // Doublon email en base → mise à jour ; sinon création.
        if ($email !== null && isset($existingByEmail[$email])) {
            $r['action'] = 'update';
            $r['existing_id'] = $existingByEmail[$email];
        } else {
            $r['action'] = 'create';
        }
    }

    /**
     * Map email (lc) → id des garants référencés par les mineurs créés, en une requête. Appelée après
     * la pass A (les garants issus du CSV sont alors persistés). @param array<int,array<string,mixed>> $rows
     *
     * @return array<string,int>
     */
    private function guardianMap(array $rows): array
    {
        $refs = [];
        foreach ($rows as $r) {
            if ($r['action'] === 'create' && $r['is_minor'] && ($r['parent_email'] ?? '') !== '') {
                $refs[$r['parent_email']] = true;
            }
        }
        if ($refs === []) {
            return [];
        }

        $map = [];
        User::query()->whereNull('anonymized_at')->where('is_minor', false)->whereNotNull('email')
            ->get(['id', 'email'])
            ->each(function (User $u) use (&$map, $refs) {
                $lc = mb_strtolower($u->email);
                if (isset($refs[$lc])) {
                    $map[$lc] = $u->id;
                }
            });

        return $map;
    }
}

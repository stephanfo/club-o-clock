<?php

namespace App\Services;

use App\Models\ClubSettings;
use App\Models\Session;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Lecture des journaux Audit/Activity (PRD §4.18.5 — J6.7). Page admin « Journaux » : consultation
// filtrée (acteur, action, cible, séance, période) des deux journaux, séparés ou fusionnés (« Tous »),
// chronologie DESC paginée + autocomplete. Pur lecture : aucune écriture. L'anonymisation est déjà
// portée par le tombstone (la ligne User scrubée s'affiche « Compte supprimé ») → rien à masquer ici.
class JournalService
{
    /** Sources consultables. */
    public const SOURCES = ['all', 'audit', 'activity'];

    /** Périodes prédéfinies (défaut 30 j, PRD §4.18.5). */
    public const PERIODS = ['30d', '90d', 'season', 'all'];

    /** Hiérarchie des rôles pour résumer un snapshot multi-rôles à l'étiquette la plus forte. */
    private const ROLE_RANK = ['admin' => 3, 'coach' => 2, 'parent' => 1, 'athlete' => 0];

    /**
     * Fenêtre temporelle résolue. `season` = début de l'année sportive courante → maintenant
     * (mois d'ouverture réglable par le club, §4.17).
     * `all` = depuis toujours (from null).
     *
     * @return array{from:?Carbon,to:Carbon,label:string}
     */
    public function resolvePeriod(string $period): array
    {
        $now = Carbon::now();

        return match ($period) {
            '90d' => ['from' => $now->copy()->subDays(90), 'to' => $now, 'label' => '90 derniers jours'],
            'season' => ['from' => $this->seasonStart($now), 'to' => $now, 'label' => 'Saison en cours'],
            'all' => ['from' => null, 'to' => $now, 'label' => 'Tout l\'historique'],
            default => ['from' => $now->copy()->subDays(30), 'to' => $now, 'label' => '30 derniers jours'],
        };
    }

    private function seasonStart(Carbon $now): Carbon
    {
        // Le mois d'ouverture est réglable par le club (§4.17) : on délègue au singleton, seul
        // endroit qui connaît la règle, plutôt que de recoder « septembre » ici.
        return ClubSettings::current()->seasonStart($now);
    }

    /**
     * Page « charger plus » : N premières lignes décorées + total filtré.
     *
     * @param  array<string,mixed>  $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public function page(array $filters, int $perPage): array
    {
        $query = $this->buildQuery($filters);

        return [
            'total' => (clone $query)->count(),
            'rows' => $this->decorate((clone $query)->limit($perPage)->get()),
        ];
    }

    /**
     * Toutes les lignes décorées pour les filtres donnés (sert l'export XLSX). Plafond de sécurité
     * pour éviter un classeur démesuré ; volumétrie attendue faible (§4.18.4 — rétention indéfinie).
     *
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    public function rows(array $filters, int $cap = 10000): array
    {
        return $this->decorate($this->buildQuery($filters)->limit($cap)->get());
    }

    /**
     * Une ligne décorée par (source, id) — sert le drawer détail. Null si introuvable.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $source, int $id): ?array
    {
        if (! in_array($source, ['audit', 'activity'], true)) {
            return null;
        }
        $rows = $this->decorate($this->buildQuery(['source' => $source])->where('id', $id)->get());

        return $rows[0] ?? null;
    }

    /**
     * Construit la requête fusionnée (UNION ALL des deux journaux selon la source), triée DESC.
     *
     * @param  array<string,mixed>  $filters
     */
    public function buildQuery(array $filters): Builder
    {
        $source = $filters['source'] ?? 'all';
        $targetType = $filters['target_type'] ?? null;

        // Un filtre « type de cible » est propre à l'AuditLog : l'ActivityLog n'a pas de cible
        // polymorphe → on exclut alors la partie Activity.
        $wantAudit = $source !== 'activity';
        $wantActivity = $source !== 'audit' && $targetType === null;

        $parts = [];
        if ($wantAudit) {
            $parts[] = $this->auditPart($filters);
        }
        if ($wantActivity) {
            $parts[] = $this->activityPart($filters);
        }

        // Aucune source retenue (ex. source=activity + type de cible) → requête vide cohérente.
        if ($parts === []) {
            $parts[] = $this->auditPart($filters)->whereRaw('1 = 0');
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        return DB::query()->fromSub($union, 'j')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /** Projection normalisée de l'AuditLog (colonnes alignées sur l'ActivityLog pour l'UNION). */
    private function auditPart(array $filters): Builder
    {
        $q = DB::table('audit_logs')->selectRaw(
            "'audit' as source, id, created_at, actor_id, actor_role, NULL as actor_is_system, ".
            'action, target_type, target_id, session_id, NULL as concerned_user_id, motif'
        );

        $this->applyCommonFilters($q, $filters);
        if (! empty($filters['target_type'])) {
            $q->where('target_type', $filters['target_type']);
        }

        return $q;
    }

    /** Projection normalisée de l'ActivityLog. */
    private function activityPart(array $filters): Builder
    {
        $q = DB::table('activity_logs')->selectRaw(
            "'activity' as source, id, created_at, actor_id, NULL as actor_role, actor_is_system, ".
            'action, NULL as target_type, NULL as target_id, session_id, user_id as concerned_user_id, NULL as motif'
        );

        $this->applyCommonFilters($q, $filters);

        return $q;
    }

    /** Filtres communs aux deux journaux : acteur, actions, séance, fenêtre temporelle. */
    private function applyCommonFilters(Builder $q, array $filters): void
    {
        if (! empty($filters['actor_id'])) {
            $q->where('actor_id', $filters['actor_id']);
        }
        if (! empty($filters['actions'])) {
            $q->whereIn('action', (array) $filters['actions']);
        }
        if (! empty($filters['session_id'])) {
            $q->where('session_id', $filters['session_id']);
        }
        if (! empty($filters['from'])) {
            $q->where('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->where('created_at', '<=', $filters['to']);
        }
    }

    /**
     * Hydrate les lignes brutes en lignes d'affichage (noms d'acteur/cible/séance résolus en lot).
     *
     * @param  Collection<int,object>  $rows
     * @return array<int,array<string,mixed>>
     */
    private function decorate(Collection $rows): array
    {
        // Résolution en lot : un seul SELECT users + un seul SELECT sessions pour toute la page.
        $userIds = $rows->flatMap(fn ($r) => [
            $r->actor_id,
            $r->concerned_user_id,
            $r->target_type === User::class ? $r->target_id : null,
        ])->filter()->unique();
        $sessionIds = $rows->pluck('session_id')->filter()->unique();

        $users = User::whereIn('id', $userIds)->get(['id', 'first_name', 'last_name', 'roles', 'anonymized_at'])->keyBy('id');
        $sessions = Session::whereIn('id', $sessionIds)->get(['id', 'title', 'start_at'])->keyBy('id');

        // ID liable vers une fiche membre : l'utilisateur existe encore ET n'est pas anonymisé
        // (le tombstone « Compte supprimé » reste affiché en texte inerte, sans lien mort).
        $linkableUserId = fn (?int $id) => $id && ($u = $users->get($id)) && $u->anonymized_at === null ? $id : null;

        return $rows->map(function ($r) use ($users, $sessions, $linkableUserId) {
            $session = $r->session_id ? $sessions->get($r->session_id) : null;
            // ID de l'utilisateur concerné (cible), si la ligne le vise : activity → concerned_user_id,
            // audit → target_id quand la cible est un User. Sert à lier « Cible » vers sa fiche membre.
            $targetUserId = $r->source === 'activity'
                ? $r->concerned_user_id
                : ($r->target_type === User::class ? $r->target_id : null);

            return [
                'source' => $r->source,
                'id' => $r->id,
                'at' => Carbon::parse($r->created_at),
                'actor' => $this->actorName($r, $users),
                'actor_id' => $linkableUserId($r->actor_id),
                'actor_role' => $this->actorRole($r, $users),
                'action' => $r->action,
                'target' => $this->targetLabel($r, $users),
                'target_user_id' => $linkableUserId($targetUserId),
                'session' => $session?->title,
                'session_id' => $session?->id,
                'session_at' => $session?->start_at,
                'motif' => $r->motif,
            ];
        })->all();
    }

    private function actorName(object $r, Collection $users): string
    {
        if ($r->actor_id && ($u = $users->get($r->actor_id))) {
            return trim($u->first_name.' '.$u->last_name);
        }

        // Activity : FK nulle + flag = action automatique (promotions, cascades).
        if ($r->source === 'activity' && $r->actor_is_system) {
            return 'Système';
        }

        return '—';
    }

    private function actorRole(object $r, Collection $users): ?string
    {
        // Audit : snapshot du rôle effectif au moment de l'acte (survit à l'anonymisation).
        if ($r->actor_role) {
            return $this->topRole(explode(',', $r->actor_role));
        }
        // Activity : pas de snapshot → rôle vivant de l'acteur, à défaut rien.
        if ($r->actor_id && ($u = $users->get($r->actor_id))) {
            return $this->topRole($u->roles ?? []);
        }

        return null;
    }

    /** Réduit une liste de rôles à l'étiquette la plus forte (admin > coach > parent > athlete). */
    private function topRole(array $roles): ?string
    {
        $roles = array_filter($roles);
        if ($roles === []) {
            return null;
        }
        usort($roles, fn ($a, $b) => (self::ROLE_RANK[$b] ?? -1) <=> (self::ROLE_RANK[$a] ?? -1));

        return $roles[0];
    }

    /** Libellé de la cible : nom d'utilisateur si cible = User, sinon « Type #id », sinon « — ». */
    private function targetLabel(object $r, Collection $users): string
    {
        // Activity : la « cible » est l'utilisateur concerné (peut différer de l'acteur).
        if ($r->source === 'activity') {
            if ($r->concerned_user_id && ($u = $users->get($r->concerned_user_id))) {
                return trim($u->first_name.' '.$u->last_name);
            }

            return '—';
        }

        // Audit : cible polymorphe (target_type/target_id).
        if ($r->target_type === User::class && $r->target_id && ($u = $users->get($r->target_id))) {
            return trim($u->first_name.' '.$u->last_name);
        }
        if ($r->target_type) {
            return class_basename($r->target_type).($r->target_id ? ' #'.$r->target_id : '');
        }

        return '—';
    }

    // ── Options de filtrage / autocomplete ──

    /**
     * Acteurs correspondant à la saisie (autocomplete, PRD §4.18.5). Inclut les comptes anonymisés
     * (affichés « Compte supprimé ») pour filtrer un historique laissé par un compte supprimé.
     *
     * @return array<int,array{id:int,label:string}>
     */
    public function actorSuggestions(string $term, int $limit = 8): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }

        return User::query()
            ->where(fn ($w) => $w->where('first_name', 'like', "%{$term}%")->orWhere('last_name', 'like', "%{$term}%"))
            ->orderBy('last_name')->orderBy('first_name')
            ->limit($limit)
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn ($u) => ['id' => $u->id, 'label' => trim($u->first_name.' '.$u->last_name)])
            ->all();
    }

    /**
     * Séances correspondant à la saisie (autocomplete sur le titre).
     *
     * @return array<int,array{id:int,label:string}>
     */
    public function sessionSuggestions(string $term, int $limit = 8): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }

        return Session::query()
            ->where('title', 'like', "%{$term}%")
            ->orderByDesc('start_at')
            ->limit($limit)
            ->get(['id', 'title', 'start_at'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'label' => $s->title.' · '.$s->start_at->format('d/m/Y'),
            ])
            ->all();
    }

    /**
     * Actions distinctes présentes, groupées par source (alimente le multi-select).
     *
     * @return array{audit:array<int,string>,activity:array<int,string>}
     */
    public function availableActions(): array
    {
        return [
            'audit' => DB::table('audit_logs')->distinct()->orderBy('action')->pluck('action')->all(),
            'activity' => DB::table('activity_logs')->distinct()->orderBy('action')->pluck('action')->all(),
        ];
    }

    /**
     * Types de cible distincts de l'AuditLog (clé = FQCN stocké, valeur = nom court affiché).
     *
     * @return array<string,string>
     */
    public function targetTypes(): array
    {
        return DB::table('audit_logs')->whereNotNull('target_type')->distinct()->orderBy('target_type')
            ->pluck('target_type')
            ->mapWithKeys(fn ($t) => [$t => class_basename($t)])
            ->all();
    }
}

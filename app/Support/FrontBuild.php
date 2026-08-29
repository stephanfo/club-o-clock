<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

// Empreinte des sources front, et confrontation avec celle inscrite au dernier build.
//
// Pourquoi : `public/build/` est gitignoré (INSTALL.md §5.1 — on ne versionne pas d'artefacts) et
// se transfère à la main. Un bundle périmé ne provoque AUCUNE erreur : le site s'affiche avec
// l'ancien CSS/JS, et rien ne le signale. C'est le piège documenté au §10 « le code est déployé
// mais le style ne suit pas » — constaté pour de vrai le 2026-08-29, une source modifiée après le
// dernier `npm run build`.
//
// Le motif est celui de schema:check-drift : l'artefact dérivé n'est pas mis en doute, il est
// CONFRONTÉ à ce dont il est dérivé, et l'écart fait échouer `composer check`. Différence avec le
// dump de schéma, qui explique qu'on ne versionne pas celui-ci : le dump est UN fichier réécrit
// par-dessus lui-même, alors que Vite hashe ses noms de sortie — chaque build ajouterait des
// fichiers neufs qu'un dépôt ne reprend jamais, indéfiniment, dans un dépôt public.
//
// L'empreinte est un HACHAGE DE CONTENU, jamais une date de modification : `git checkout` et
// `git rebase` réécrivent les mtimes de tous les fichiers touchés, ce qui rendrait le contrôle
// tantôt rouge sans raison, tantôt vert à tort après une bascule de branche.
class FrontBuild
{
    /** Nom du fichier d'empreinte, déposé DANS le bundle : il décrit ce build-là et voyage avec lui
     *  jusqu'au serveur, où le même contrôle peut donc être rejoué après transfert. */
    public const STAMP = '.front-stamp.json';

    /**
     * Entrées du build, relatives à la racine du projet. Tout ce qui change la sortie de Vite :
     * les sources déclarées en `input` (vite.config.js) et ce qu'elles importent, la config elle-même,
     * et les versions de dépendances — une montée de version change les bundles sans qu'aucune
     * source du projet n'ait bougé.
     *
     * @var list<string>
     */
    public const SOURCES = [
        'resources/css',
        'resources/js',
        'vite.config.js',
        'package.json',
        'package-lock.json',
    ];

    public function __construct(
        private readonly string $root,
        private readonly string $buildPath,
    ) {}

    public static function make(): self
    {
        return new self(base_path(), public_path('build'));
    }

    /** Le bundle existe-t-il ? Faux sur un dépôt fraîchement cloné et en CI, qui ne buildent pas. */
    public function isBuilt(): bool
    {
        return File::isDirectory($this->buildPath) && File::exists($this->buildPath.'/manifest.json');
    }

    public function stampPath(): string
    {
        return $this->buildPath.'/'.self::STAMP;
    }

    /**
     * Empreinte des sources telles qu'elles sont MAINTENANT : chemin relatif → sha256 du contenu.
     * Triée par chemin pour être indépendante de l'ordre de parcours du système de fichiers.
     *
     * @return array<string, string>
     */
    public function fingerprint(): array
    {
        $out = [];

        foreach (self::SOURCES as $source) {
            $absolute = $this->root.'/'.$source;

            if (File::isDirectory($absolute)) {
                foreach (File::allFiles($absolute) as $file) {
                    $relative = str_replace($this->root.'/', '', $file->getPathname());
                    $out[$relative] = hash_file('sha256', $file->getPathname());
                }

                continue;
            }

            if (File::exists($absolute)) {
                $out[$source] = hash_file('sha256', $absolute);
            }
        }

        ksort($out);

        return $out;
    }

    /** Inscrit l'empreinte courante dans le bundle. Appelé par `npm run build`, après Vite. */
    public function writeStamp(): void
    {
        File::put($this->stampPath(), (string) json_encode([
            'built_at' => now()->toIso8601String(),
            'files' => $this->fingerprint(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Empreinte inscrite au dernier build, ou null si le bundle n'en porte pas (build antérieur à
     * ce garde-fou, ou produit par un `vite build` lancé sans le script npm).
     *
     * @return array<string, string>|null
     */
    public function readStamp(): ?array
    {
        if (! File::exists($this->stampPath())) {
            return null;
        }

        $data = json_decode(File::get($this->stampPath()), true);

        if (! is_array($data) || ! isset($data['files']) || ! is_array($data['files'])) {
            return null;
        }

        /** @var array<string, string> $files */
        $files = $data['files'];

        return $files;
    }

    /**
     * Sources modifiées, ajoutées ou supprimées depuis le build, en clair.
     *
     * @param  array<string, string>  $stamp
     * @return list<string>
     */
    public function drift(array $stamp): array
    {
        $now = $this->fingerprint();
        $lines = [];

        foreach ($now as $path => $hash) {
            if (! isset($stamp[$path])) {
                $lines[] = "+ ajouté depuis le build   {$path}";
            } elseif ($stamp[$path] !== $hash) {
                $lines[] = "~ modifié depuis le build  {$path}";
            }
        }

        foreach ($stamp as $path => $hash) {
            if (! isset($now[$path])) {
                $lines[] = "- supprimé depuis le build {$path}";
            }
        }

        sort($lines);

        return $lines;
    }
}

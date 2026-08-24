<?php

namespace Tests\Feature;

use Tests\TestCase;

// Intégrité de la table d'icônes de `<x-icon>` (fidélité au design system).
//
// Le trou que ce test ferme : `icon.blade.php` résout un nom inconnu en `$paths[$name] ?? ''`, donc
// une faute de frappe ou une icône jamais ajoutée rend un `<svg>` VIDE — sans erreur, sans warning,
// sans rien dans les logs. Un CTA principal peut ainsi partir en production amputé de son icône
// (c'est arrivé à `log-in` sur les deux écrans de saisie du code à usage unique).
class IconesTest extends TestCase
{
    /** Noms déclarés dans la table de chemins du composant. */
    private function iconesDeclarees(): array
    {
        $source = file_get_contents(resource_path('views/components/icon.blade.php'));
        preg_match_all("/^\s*'([a-z0-9-]+)' => '/m", $source, $m);

        return $m[1];
    }

    /** Noms LITTÉRAUX passés à `<x-icon name=\"…\">` dans les vues, avec leur fichier d'origine. */
    private function iconesUtilisees(): array
    {
        $usages = [];
        $vues = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($vues as $fichier) {
            if (! $fichier->isFile() || ! str_ends_with($fichier->getFilename(), '.blade.php')) {
                continue;
            }
            // Uniquement les noms EN DUR : `:name="$var"` et `name="{{ … }}"` sont résolus à
            // l'exécution, donc indécidables ici — ils sont volontairement hors périmètre.
            preg_match_all('/<x-icon\b[^>]*?\sname="([a-z0-9-]+)"/', file_get_contents($fichier->getPathname()), $m);
            foreach ($m[1] as $nom) {
                $usages[$nom][] = str_replace(resource_path('views').'/', '', $fichier->getPathname());
            }
        }

        return $usages;
    }

    public function test_every_icon_used_in_a_view_exists_in_the_table(): void
    {
        $declarees = $this->iconesDeclarees();

        // Contrôle positif apparié : sans lui, une regex cassée rendrait le test vert sur une
        // liste vide — il ne prouverait alors plus rien.
        $utilisees = $this->iconesUtilisees();
        $this->assertGreaterThan(30, count($utilisees), 'Le scan des vues n’a presque rien trouvé : la détection est cassée.');
        $this->assertGreaterThan(50, count($declarees), 'La table d’icônes n’a presque rien : la détection est cassée.');

        $manquantes = [];
        foreach ($utilisees as $nom => $fichiers) {
            if (! in_array($nom, $declarees, true)) {
                $manquantes[] = $nom.' ('.implode(', ', array_unique($fichiers)).')';
            }
        }

        $this->assertSame([], $manquantes,
            "Icônes absentes de icon.blade.php — elles rendent un SVG vide : \n".implode("\n", $manquantes));
    }

    public function test_the_table_has_no_duplicate_name(): void
    {
        // Un doublon serait silencieux aussi : la seconde entrée écrase la première, donc une
        // icône changerait de dessin sans que son appelant ne bouge.
        $declarees = $this->iconesDeclarees();
        $doublons = array_keys(array_filter(array_count_values($declarees), fn ($n) => $n > 1));

        $this->assertSame([], $doublons, 'Noms déclarés deux fois dans icon.blade.php : '.implode(', ', $doublons));
    }
}

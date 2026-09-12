<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Pied des dialogs (#36). Sur mobile, `.dialog-foot` empile ses boutons en `column-reverse` : le
 * markup écrit (secondaire, principal) remonte l'action principale en tête, ce qui est la bonne
 * convention quand le dialog demande « laquelle de ces deux actions veux-tu ? ».
 *
 * Un dialog `danger` ne pose pas cette question : il demande « confirmes-tu quelque chose que tu ne
 * pourras pas défaire ? ». La sortie sûre y est le geste attendu, et l'action irréversible ne doit
 * pas se retrouver au-dessus d'elle. Même raisonnement que la dérogation déjà en place pour
 * `footStack` (trois choix ou plus, en `column` et non `column-reverse`).
 *
 * Le test porte sur le CROCHET que le CSS utilise : la classe `danger` doit atteindre le pied.
 */
class DialogFooterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le rendu d'un <x-dialog> avec slot laisse un tampon de sortie ouvert hors d'une requête HTTP
     * — PHPUnit marque alors le test « risky » (« did not close its own output buffers »). On
     * rétablit soi-même le niveau : c'est un artefact du rendu hors requête, pas un défaut du
     * composant, qui se rend normalement dans une vue.
     */
    private function render(string $blade): string
    {
        $niveau = ob_get_level();
        $html = Blade::render($blade);
        while (ob_get_level() > $niveau) {
            ob_end_clean();
        }

        return $html;
    }

    public function test_a_danger_dialog_marks_its_footer(): void
    {
        $html = $this->render(
            '<x-dialog title="Supprimer" danger><x-slot:footer><button>Garder</button></x-slot:footer>Corps</x-dialog>'
        );

        $this->assertStringContainsString('class="dialog-foot danger"', $html);
    }

    /** Contrôle positif apparié : un dialog ordinaire garde le pied par défaut, donc l'inversion. */
    public function test_an_ordinary_dialog_keeps_the_default_footer(): void
    {
        $html = $this->render(
            '<x-dialog title="Notifier ?"><x-slot:footer><button>Plus tard</button></x-slot:footer>Corps</x-dialog>'
        );

        $this->assertStringContainsString('class="dialog-foot"', $html);
        $this->assertStringNotContainsString('dialog-foot danger', $html);
    }

    /** Les trois-choix gardent leur propre dérogation, cumulable avec `danger`. */
    public function test_a_stacked_footer_keeps_its_own_class(): void
    {
        $html = $this->render(
            '<x-dialog title="Trois voies" :foot-stack="true"><x-slot:footer><button>A</button></x-slot:footer>Corps</x-dialog>'
        );

        $this->assertStringContainsString('dialog-foot-stack', $html);
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\SessionForm;
use App\Models\Discipline;
use App\Models\Session;
use App\Models\User;
use App\Support\Markup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// WYSIWYG contenu de séance / agenda (PRD §4.12.1) : sanitisation serveur au stockage.
class WysiwygContentTest extends TestCase
{
    use RefreshDatabase;

    private function discipline(): Discipline
    {
        return Discipline::create(['label' => 'Natation', 'sort_order' => 0]);
    }

    public function test_training_content_is_sanitized_on_save(): void
    {
        $coach = User::factory()->coach()->create();
        $disc = $this->discipline();

        Livewire::actingAs($coach)->test(SessionForm::class)
            ->set('kind', 'training')
            ->set('title', 'Natation seuil')
            ->set('discipline_id', $disc->id)
            ->set('start_at', Carbon::now()->addDays(2)->format('Y-m-d\TH:i'))
            ->set('duration_min', 60)
            ->set('content_markdown', "**Échauffement** 400 m\n\n<script>alert(1)</script>\n\n[infos](https://club.example)")
            ->call('save')
            ->assertHasNoErrors();

        $stored = Session::where('title', 'Natation seuil')->first()->content_markdown;

        $this->assertStringContainsString('**Échauffement**', $stored);
        $this->assertStringNotContainsString('<script', $stored);

        $html = (string) Markup::render($stored);
        $this->assertStringNotContainsString('alert', $html);
        $this->assertStringContainsString('<strong>Échauffement</strong>', $html);
        $this->assertStringContainsString('target="_blank"', $html);
    }

    public function test_club_event_agenda_is_sanitized_on_save(): void
    {
        $coach = User::factory()->coach()->create();

        Livewire::actingAs($coach)->test(SessionForm::class)
            ->set('kind', 'club_event')
            ->set('title', 'AG du club')
            ->set('start_at', Carbon::now()->addWeek()->format('Y-m-d\TH:i'))
            ->set('duration_min', 120)
            ->set('agenda', "## Programme\n\n- Accueil\n- [lien](javascript:alert(1))")
            ->call('save')
            ->assertHasNoErrors();

        $stored = Session::where('title', 'AG du club')->first()->agenda;
        $html = (string) Markup::render($stored);

        $this->assertStringContainsString('<h2>Programme</h2>', $html);
        $this->assertStringContainsString('<li>Accueil</li>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_empty_content_stores_null(): void
    {
        $coach = User::factory()->coach()->create();
        $disc = $this->discipline();

        Livewire::actingAs($coach)->test(SessionForm::class)
            ->set('kind', 'training')
            ->set('title', 'Sans contenu')
            ->set('discipline_id', $disc->id)
            ->set('start_at', Carbon::now()->addDays(2)->format('Y-m-d\TH:i'))
            ->set('duration_min', 60)
            ->set('content_markdown', '   ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(Session::where('title', 'Sans contenu')->first()->content_markdown);
    }
}

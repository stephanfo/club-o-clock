<?php

namespace Tests\Feature;

use App\Livewire\SessionForm;
use App\Livewire\SessionShow;
use App\Models\Category;
use App\Models\Discipline;
use App\Models\EventType;
use App\Models\GpxRoute;
use App\Models\Location;
use App\Models\QuotaTag;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Duplication d'une séance (#176) : la création pré-remplie depuis `?depuis={id}`. Ce qui décrit la
 * séance suit, ce qui appartient à l'occurrence (contenu, parcours, inscriptions…) ne suit pas.
 */
class SessionDuplicationTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    protected function setUp(): void
    {
        parent::setUp();
        // Mercredi 4 novembre 2026, 10:00 heure club.
        $this->travelTo(Carbon::parse('2026-11-04 10:00', 'Europe/Paris'));
        $this->coach = User::factory()->coach()->create();
    }

    /** @param  array<string, mixed>  $attrs */
    private function source(array $attrs = []): Session
    {
        return Session::create([
            'kind' => 'training',
            'title' => 'Fractionné piste',
            'discipline_id' => Discipline::create(['label' => 'Course', 'sort_order' => 0])->id,
            // Jeudi 5 novembre, 18:30 heure club.
            'start_at' => Carbon::parse('2026-11-05 18:30', 'Europe/Paris'),
            'duration_min' => 90,
            'created_by' => User::factory()->admin()->create()->id,
            ...$attrs,
        ]);
    }

    private function duplicate(Session $source, ?User $as = null)
    {
        return Livewire::actingAs($as ?? $this->coach)
            ->withQueryParams(['depuis' => $source->id])
            ->test(SessionForm::class);
    }

    public function test_les_champs_descriptifs_sont_recopies(): void
    {
        $lieu = Location::create(['name' => 'Stade', 'created_by' => $this->coach->id]);
        $tag = QuotaTag::create(['code' => 'piste', 'label' => 'Piste', 'max_per_week' => 1]);
        $cat = Category::create(['label' => 'Adultes', 'age_min' => 18, 'age_max' => 99, 'sort_order' => 0]);
        $autreCoach = User::factory()->coach()->create();
        $source = $this->source([
            'location_id' => $lieu->id,
            'location_text' => 'Tribune nord',
            'capacity' => 16,
            'quota_tag_id' => $tag->id,
            'external_staff_label' => 'Entraîneur de ligue',
        ]);
        $source->categories()->sync([$cat->id]);
        $source->coaches()->sync([$autreCoach->id]);

        $this->duplicate($source)
            ->assertSet('session', null)
            ->assertSet('source_id', $source->id)
            ->assertSet('kind', 'training')
            ->assertSet('title', 'Fractionné piste')
            ->assertSet('discipline_id', $source->discipline_id)
            ->assertSet('duration_min', 90)
            ->assertSet('location_id', $lieu->id)
            ->assertSet('location_text', 'Tribune nord')
            ->assertSet('capacity', 16)
            ->assertSet('quota_tag_id', $tag->id)
            ->assertSet('external_staff_label', 'Entraîneur de ligue')
            ->assertSet('category_ids', [$cat->id])
            ->assertSet('coach_ids', [$autreCoach->id])
            // Même heure, une semaine plus tard.
            ->assertSet('start_at', '2026-11-12T18:30');
    }

    public function test_le_contenu_et_le_parcours_ne_sont_pas_recopies(): void
    {
        $route = GpxRoute::factory()->create();
        $source = $this->source([
            'content_markdown' => '<p>10 × 400 m</p>',
            'route_id' => $route->id,
            'route_openrunner_embed_url' => 'https://www.openrunner.com/embed.html?code=abc',
            'route_openrunner_public_url' => 'https://www.openrunner.com/route-details/1',
        ]);

        $this->duplicate($source)
            // Contrôle positif : le formulaire a bien été rempli depuis la source.
            ->assertSet('title', 'Fractionné piste')
            ->assertSet('content_markdown', '')
            ->assertSet('route_id', null)
            ->assertSet('gpxStats', null)
            ->assertSet('route_openrunner_embed_url', '')
            ->assertSet('route_openrunner_public_url', '');
    }

    public function test_competition_garde_type_distance_et_lien_mais_pas_album_ni_ouverture(): void
    {
        $type = EventType::create(['label' => 'Triathlon', 'sort_order' => 0]);
        $source = $this->source([
            'kind' => 'competition',
            'discipline_id' => null,
            'title' => 'Tri de Nantes',
            'event_type_id' => $type->id,
            'distance' => 'S',
            'external_url' => 'https://tri.example.org',
            'photos_album_url' => 'https://photos.example.org/album',
            'registration_opens_at' => Carbon::parse('2026-11-01 09:00', 'Europe/Paris'),
            'registration_opens_has_time' => true,
        ]);

        $this->duplicate($source)
            ->assertSet('kind', 'competition')
            ->assertSet('event_type_id', $type->id)
            ->assertSet('distance', 'S')
            ->assertSet('external_url', 'https://tri.example.org')
            ->assertSet('photos_album_url', '')
            ->assertSet('registration_opens_date', '')
            ->assertSet('registration_opens_time', '');
    }

    public function test_evenement_club_ne_reprend_pas_l_agenda(): void
    {
        $source = $this->source([
            'kind' => 'club_event', 'discipline_id' => null, 'title' => 'AG',
            'agenda' => '<p>Ordre du jour</p>', 'external_url' => 'https://visio.example.org',
        ]);

        $this->duplicate($source)
            ->assertSet('kind', 'club_event')
            ->assertSet('external_url', 'https://visio.example.org')
            ->assertSet('agenda', '');
    }

    public function test_une_source_passee_glisse_au_meme_jour_apres_aujourd_hui(): void
    {
        // Jeudi 8 octobre : +1 semaine (15/10) est encore passé ; le jeudi suivant aujourd'hui est le 5/11.
        $source = $this->source(['start_at' => Carbon::parse('2026-10-08 18:30', 'Europe/Paris')]);

        $this->duplicate($source)->assertSet('start_at', '2026-11-05T18:30');
    }

    public function test_l_heure_club_est_conservee_a_travers_le_changement_d_heure(): void
    {
        // Jeudi 22 octobre 18:30 (heure d'été) → jeudi 29 octobre 18:30 (heure d'hiver), pas 17:30.
        $this->travelTo(Carbon::parse('2026-10-20 10:00', 'Europe/Paris'));
        $source = $this->source(['start_at' => Carbon::parse('2026-10-22 18:30', 'Europe/Paris')]);

        $this->duplicate($source)->assertSet('start_at', '2026-10-29T18:30');
    }

    public function test_les_valeurs_qui_ne_sont_plus_selectionnables_sont_ecartees(): void
    {
        $lieu = Location::create(['name' => 'Ancien stade', 'created_by' => $this->coach->id, 'is_archived' => true]);
        $active = Category::create(['label' => 'Adultes', 'age_min' => 18, 'age_max' => 99, 'sort_order' => 0]);
        $archivee = Category::create(['label' => 'Vétérans', 'age_min' => 40, 'age_max' => 99, 'sort_order' => 1, 'archived_at' => now()]);
        $exCoach = User::factory()->create();
        $coach = User::factory()->coach()->create();
        $source = $this->source(['location_id' => $lieu->id]);
        $source->discipline->update(['archived_at' => now()]);
        $source->categories()->sync([$active->id, $archivee->id]);
        $source->coaches()->sync([$exCoach->id, $coach->id]);

        $this->duplicate($source)
            ->assertSet('discipline_id', null)
            ->assertSet('location_id', null)
            ->assertSet('category_ids', [$active->id])
            ->assertSet('coach_ids', [$coach->id]);
    }

    public function test_enregistrer_cree_une_seance_independante(): void
    {
        $athlete = User::factory()->create();
        $source = $this->source(['content_markdown' => '<p>Ancien</p>']);
        Registration::create([
            'session_id' => $source->id, 'user_id' => $athlete->id,
            'status' => 'participating', 'registered_at' => now(),
        ]);

        $this->duplicate($source)->call('save')->assertHasNoErrors();

        $copie = Session::whereKeyNot($source->id)->sole();
        $this->assertSame('Fractionné piste', $copie->title);
        $this->assertSame($this->coach->id, $copie->created_by);
        $this->assertNull($copie->source_template_id);
        $this->assertNull($copie->content_markdown ?: null);
        $this->assertTrue($copie->start_at->equalTo(Carbon::parse('2026-11-12 18:30', 'Europe/Paris')));
        $this->assertSame(0, $copie->registrations()->count());
        // La source n'est pas touchée.
        $this->assertSame('<p>Ancien</p>', $source->fresh()->content_markdown);
        $this->assertSame(1, $source->registrations()->count());
    }

    public function test_un_athlete_ne_peut_pas_dupliquer(): void
    {
        $source = $this->source();

        $this->actingAs(User::factory()->create())
            ->get(route('sessions.create', ['depuis' => $source->id]))
            ->assertForbidden();
    }

    public function test_une_source_inexistante_renvoie_404(): void
    {
        $this->actingAs($this->coach)
            ->get(route('sessions.create', ['depuis' => 999999]))
            ->assertNotFound();
    }

    public function test_l_ecran_annonce_la_copie(): void
    {
        $this->actingAs($this->coach)
            ->get(route('sessions.create', ['depuis' => $this->source()->id]))
            ->assertOk()
            ->assertSee('Dupliquer la séance')
            ->assertSee('Copie de Fractionné piste · jeu. 5 nov.');
    }

    public function test_avertit_sans_bloquer_quand_la_seance_existe_deja(): void
    {
        $source = $this->source();
        // Déjà générée la semaine suivante, par exemple par un template.
        $existante = $this->source(['start_at' => Carbon::parse('2026-11-12 18:30', 'Europe/Paris')]);

        $form = $this->duplicate($source)->assertSee('existe déjà à cette date');
        $this->assertSame($existante->id, $form->instance()->doublon()?->id);

        $form->call('save')->assertHasNoErrors();
        $this->assertSame(3, Session::count());
    }

    public function test_pas_d_avertissement_si_le_creneau_differe_ou_si_l_existante_est_annulee(): void
    {
        $source = $this->source();
        $annulee = $this->source(['start_at' => Carbon::parse('2026-11-12 18:30', 'Europe/Paris')]);
        $annulee->forceFill(['cancelled_at' => now()])->save();

        $form = $this->duplicate($source);
        // Contrôle positif : le titre et le créneau coïncident bien avec la séance annulée.
        $this->assertTrue($annulee->start_at->equalTo(Carbon::parse($form->get('start_at'), 'Europe/Paris')));
        $form->assertDontSee('existe déjà à cette date');

        $annulee->forceFill(['cancelled_at' => null])->save();
        $form->set('start_at', '2026-11-12T19:00')->assertDontSee('existe déjà à cette date');
    }

    public function test_l_edition_ne_se_signale_pas_comme_son_propre_doublon(): void
    {
        $source = $this->source();

        $form = Livewire::actingAs($this->coach)->test(SessionForm::class, ['session' => $source]);
        $this->assertNull($form->instance()->doublon());
    }

    public function test_la_fiche_propose_dupliquer_aux_coachs_seulement(): void
    {
        $source = $this->source();

        Livewire::actingAs($this->coach)->test(SessionShow::class, ['session' => $source])
            ->assertSee(route('sessions.create', ['depuis' => $source->id]), false);

        Livewire::actingAs(User::factory()->create())->test(SessionShow::class, ['session' => $source])
            ->assertSee('Fractionné piste')
            ->assertDontSee(route('sessions.create', ['depuis' => $source->id]), false);
    }

    public function test_les_marqueurs_structurants_ne_s_affichent_qu_en_edition(): void
    {
        $source = $this->source();

        // `$session?->exists` valait null hors édition, et `:show="null"` retombait sur le défaut
        // `true` du composant : les cloches s'affichaient en création comme en duplication.
        Livewire::actingAs($this->coach)->test(SessionForm::class)
            ->assertDontSee('Champ structurant');
        $this->duplicate($source)->assertSee('Fractionné piste')->assertDontSee('Champ structurant');
        // Contrôle positif : en édition, ils sont bien là.
        Livewire::actingAs($this->coach)->test(SessionForm::class, ['session' => $source])
            ->assertSee('Champ structurant');
    }
}

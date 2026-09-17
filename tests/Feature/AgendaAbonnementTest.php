<?php

namespace Tests\Feature;

use App\Livewire\Profil;
use App\Models\CalendarFeed;
use App\Models\Discipline;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Services\CalendarFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Abonnement personnel à l'agenda (#39, PRD §4.21.2) : jeton, flux public, réglages du profil.
class AgendaAbonnementTest extends TestCase
{
    use RefreshDatabase;

    private function seance(string $titre, array $attrs = []): Session
    {
        return Session::create($attrs + [
            'kind' => 'training', 'title' => $titre,
            'discipline_id' => Discipline::firstOrCreate(['label' => 'Natation'], ['sort_order' => 0])->id,
            'start_at' => Carbon::now()->addDays(3), 'duration_min' => 60,
        ]);
    }

    private function inscrire(User $u, Session $s, string $statut = 'participating'): void
    {
        Registration::create(['session_id' => $s->id, 'user_id' => $u->id, 'status' => $statut, 'registered_at' => Carbon::now()]);
    }

    private function flux(User $u, array $reglages = []): CalendarFeed
    {
        $feed = app(CalendarFeedService::class)->regenerate($u);
        $feed->update($reglages);

        return $feed;
    }

    private function lire(CalendarFeed $feed): string
    {
        $reponse = $this->get(route('agenda.feed', $feed->token))->assertOk();

        return str_replace("\r\n ", '', $reponse->getContent());
    }

    public function test_le_flux_sert_les_inscriptions_sans_session(): void
    {
        $membre = User::factory()->create();
        $this->inscrire($membre, $this->seance('Natation du mardi'));
        $feed = $this->flux($membre);

        $reponse = $this->get(route('agenda.feed', $feed->token));

        $reponse->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->assertHeader('ETag');
        $ics = $reponse->getContent();
        $this->assertStringContainsString("SUMMARY:Natation du mardi\r\n", $ics);
        $this->assertStringContainsString("REFRESH-INTERVAL;VALUE=DURATION:PT1H\r\n", $ics);
        $this->assertNotNull($feed->fresh()->last_used_at);
    }

    public function test_jeton_inconnu_404_et_revoque_410(): void
    {
        $membre = User::factory()->create();
        $feed = $this->flux($membre);
        $this->get(route('agenda.feed', $feed->token))->assertOk(); // contrôle positif

        $this->get(route('agenda.feed', str_repeat('a', 64)))->assertNotFound();

        app(CalendarFeedService::class)->revoke($membre);
        $this->get(route('agenda.feed', $feed->token))->assertStatus(410);
    }

    public function test_la_regeneration_revoque_lancienne_adresse_et_garde_les_reglages(): void
    {
        $membre = User::factory()->create();
        $ancienne = $this->flux($membre, ['include_waitlist' => false, 'reminder' => '120']);

        $nouvelle = app(CalendarFeedService::class)->regenerate($membre);

        $this->assertNotSame($ancienne->token, $nouvelle->token);
        $this->get(route('agenda.feed', $ancienne->token))->assertStatus(410);
        $this->get(route('agenda.feed', $nouvelle->token))->assertOk();
        $this->assertFalse($nouvelle->include_waitlist);
        $this->assertSame('120', $nouvelle->reminder);
    }

    public function test_flux_ferme_pour_acces_suspendu_compte_desactive_ou_anonymise(): void
    {
        foreach ([
            ['athlete_access_suspended' => true],
            ['is_active' => false],
            ['anonymized_at' => Carbon::now()],
        ] as $etat) {
            $membre = User::factory()->create();
            $feed = $this->flux($membre);
            $this->get(route('agenda.feed', $feed->token))->assertOk(); // contrôle positif

            $membre->forceFill($etat)->save();
            $this->get(route('agenda.feed', $feed->token))->assertForbidden();
        }
    }

    public function test_etag_renvoie_304_puis_change_quand_la_seance_change(): void
    {
        $membre = User::factory()->create();
        $seance = $this->seance('Natation');
        $this->inscrire($membre, $seance);
        $feed = $this->flux($membre);

        $etag = $this->get(route('agenda.feed', $feed->token))->headers->get('ETag');
        $this->get(route('agenda.feed', $feed->token), ['If-None-Match' => $etag])->assertStatus(304);

        $this->travel(1)->seconds();
        $seance->update(['title' => 'Natation (bassin 2)']);
        $this->get(route('agenda.feed', $feed->token), ['If-None-Match' => $etag])->assertOk();
    }

    public function test_seance_annulee_presente_sans_rappel_et_seance_supprimee_absente(): void
    {
        $membre = User::factory()->create();
        $annulee = $this->seance('Vélo');
        $supprimee = $this->seance('Course');
        $maintenue = $this->seance('Natation');
        foreach ([$annulee, $supprimee, $maintenue] as $s) {
            $this->inscrire($membre, $s);
        }
        $annulee->forceFill(['cancelled_at' => Carbon::now()])->save();
        $supprimee->registrations()->delete();
        $supprimee->delete();
        $ics = $this->lire($this->flux($membre, ['reminder' => '60']));

        $this->assertStringContainsString("SUMMARY:Annulé — Vélo\r\n", $ics);
        $this->assertStringContainsString("STATUS:CANCELLED\r\n", $ics);
        $this->assertStringContainsString("TRANSP:TRANSPARENT\r\n", $ics);
        $this->assertStringNotContainsString('Course', $ics);
        // Un seul rappel : celui de la séance maintenue.
        $this->assertStringContainsString("SUMMARY:Natation\r\n", $ics);
        $this->assertSame(1, substr_count($ics, 'BEGIN:VALARM'));
    }

    public function test_liste_dattente_provisoire_et_case_decochee(): void
    {
        $membre = User::factory()->create();
        $this->inscrire($membre, $this->seance('Natation'));
        $this->inscrire($membre, $this->seance('Vélo'), 'waitlist');
        $feed = $this->flux($membre);

        $ics = $this->lire($feed);
        $this->assertStringContainsString("SUMMARY:⏳ Liste d'attente — Vélo\r\n", $ics);
        $this->assertStringContainsString("STATUS:TENTATIVE\r\n", $ics);

        $feed->update(['include_waitlist' => false]);
        $ics = $this->lire($feed);
        $this->assertStringContainsString('SUMMARY:Natation', $ics);
        $this->assertStringNotContainsString('Vélo', $ics);
    }

    public function test_seances_encadrees_prefixees_et_case_decochee(): void
    {
        $coach = User::factory()->coach()->create();
        $this->inscrire($coach, $this->seance('Natation'));
        $this->seance('Vélo')->coaches()->attach($coach->id);
        $feed = $this->flux($coach);

        $this->assertStringContainsString("SUMMARY:Coach — Vélo\r\n", $this->lire($feed));

        $feed->update(['include_coaching' => false]);
        $ics = $this->lire($feed);
        $this->assertStringContainsString('SUMMARY:Natation', $ics);
        $this->assertStringNotContainsString('Vélo', $ics);
    }

    public function test_seances_des_enfants_prefixees_du_prenom_avec_uid_distinct(): void
    {
        $parent = User::factory()->create();
        $enfant = User::factory()->create(['first_name' => 'Jade', 'dob' => '2014-04-02', 'guardian_id' => $parent->id, 'email' => null]);
        $seance = $this->seance('Natation');
        $this->inscrire($parent, $seance);
        $this->inscrire($enfant, $seance);
        $feed = $this->flux($parent);

        $ics = $this->lire($feed);
        $this->assertStringContainsString("SUMMARY:Jade — Natation\r\n", $ics);
        $this->assertStringContainsString("SUMMARY:Natation\r\n", $ics);
        $this->assertStringContainsString("UID:seance-{$seance->id}-u{$parent->id}@", $ics);
        $this->assertStringContainsString("UID:seance-{$seance->id}-u{$enfant->id}@", $ics);

        $feed->update(['include_wards' => false]);
        $ics = $this->lire($feed);
        $this->assertStringContainsString("SUMMARY:Natation\r\n", $ics);
        $this->assertStringNotContainsString('Jade', $ics);
    }

    public function test_fenetre_de_60_jours_passes_a_365_jours_a_venir(): void
    {
        $membre = User::factory()->create();
        $this->inscrire($membre, $this->seance('Récente', ['start_at' => Carbon::now()->subDays(59)]));
        $this->inscrire($membre, $this->seance('Ancienne', ['start_at' => Carbon::now()->subDays(61)]));
        $this->inscrire($membre, $this->seance('Lointaine', ['start_at' => Carbon::now()->addDays(366)]));

        $ics = $this->lire($this->flux($membre));

        $this->assertStringContainsString('Récente', $ics);
        $this->assertStringNotContainsString('Ancienne', $ics);
        $this->assertStringNotContainsString('Lointaine', $ics);
    }

    public function test_le_profil_cree_regle_et_revoque_ladresse(): void
    {
        $membre = User::factory()->create();

        $composant = Livewire::actingAs($membre)->test(Profil::class)->set('tab', 'notifs')
            ->assertSee('Créer mon adresse d\'abonnement', false)
            ->call('regenerateFeed');

        $feed = CalendarFeed::where('user_id', $membre->id)->sole();
        $composant->assertSee($feed->url())
            ->assertSee('jusqu\'à 24 h chez Google', false)
            ->assertDontSee('Les séances que j\'encadre', false)
            ->assertDontSee('Les séances de mes enfants')
            ->call('toggleFeedSource', 'include_waitlist')
            ->call('toggleFeedSource', 'user_id') // clé hors liste : ignorée
            ->call('setFeedReminder', 'veille')
            ->call('setFeedReminder', '15'); // hors liste : ignoré

        $feed->refresh();
        $this->assertFalse($feed->include_waitlist);
        $this->assertSame($membre->id, $feed->user_id);
        $this->assertSame('veille', $feed->reminder);

        $composant->call('revokeFeed')->assertSee('Créer mon adresse d\'abonnement', false);
        $this->assertNotNull($feed->fresh()->revoked_at);
    }

    public function test_les_cases_coach_et_enfants_apparaissent_seulement_si_pertinentes(): void
    {
        $coach = User::factory()->coach()->create();
        User::factory()->create(['guardian_id' => $coach->id, 'dob' => '2014-04-02', 'email' => null]);
        $this->flux($coach);

        Livewire::actingAs($coach)->test(Profil::class)->set('tab', 'notifs')
            ->assertSee('Les séances que j\'encadre', false)
            ->assertSee('Les séances de mes enfants');
    }
}

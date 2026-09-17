<?php

namespace Tests\Feature;

use App\Models\Discipline;
use App\Models\Location;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Support\Ics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Ajout d'une séance à l'agenda perso (#39, PRD §4.21) : fichier .ics de la fiche séance.
class AgendaIcsTest extends TestCase
{
    use RefreshDatabase;

    private function seance(array $attrs = []): Session
    {
        return Session::create($attrs + [
            'kind' => 'training', 'title' => 'Natation',
            'discipline_id' => Discipline::create(['label' => 'Natation', 'sort_order' => 0])->id,
            // 19:30 à Paris en été = 17:30 UTC.
            'start_at' => Carbon::parse('2030-06-12 19:30', 'Europe/Paris'), 'duration_min' => 90,
        ]);
    }

    private function membre(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    /** Recolle les lignes pliées (RFC 5545 §3.1) pour chercher une propriété entière. */
    private function deplier(string $ics): string
    {
        return str_replace("\r\n ", '', $ics);
    }

    public function test_un_membre_telecharge_le_ics_de_la_seance(): void
    {
        $admin = User::factory()->admin()->create();
        $lieu = Location::create(['name' => 'Piscine Lamartine', 'address' => '1 rue du Port, Nantes', 'created_by' => $admin->id]);
        $seance = $this->seance(['location_id' => $lieu->id]);
        $membre = $this->membre();

        $reponse = $this->actingAs($membre)->get(route('sessions.ics', $seance));

        $reponse->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="seance-2030-06-12-natation.ics"');
        $ics = $this->deplier($reponse->getContent());
        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics);
        $this->assertStringEndsWith("END:VEVENT\r\nEND:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString("\r\nDTSTART:20300612T173000Z\r\n", $ics);
        $this->assertStringContainsString("\r\nDTEND:20300612T190000Z\r\n", $ics);
        $this->assertStringContainsString("\r\nSUMMARY:Natation\r\n", $ics);
        $this->assertStringContainsString("\r\nSTATUS:CONFIRMED\r\n", $ics);
        $this->assertStringContainsString("\r\nLOCATION:Piscine Lamartine\\, 1 rue du Port\\, Nantes\r\n", $ics);
        $this->assertStringContainsString("\r\nURL:".route('sessions.show', $seance)."\r\n", $ics);
        $this->assertMatchesRegularExpression('/\r\nUID:seance-'.$seance->id.'-u'.$membre->id.'@[^\r]+\r\n/', $ics);
        $this->assertStringNotContainsString('VALARM', $ics);
    }

    public function test_un_visiteur_non_connecte_est_renvoye_a_la_connexion(): void
    {
        $this->get(route('sessions.ics', $this->seance()))->assertRedirect(route('login'));
    }

    /** Minimisation : le fichier finit dans un agenda qui peut être partagé — aucun tiers nommé. */
    public function test_le_fichier_ne_nomme_aucun_inscrit(): void
    {
        $seance = $this->seance();
        $autre = User::factory()->create(['first_name' => 'Rosalie', 'last_name' => 'Trouvé']);
        Registration::create([
            'session_id' => $seance->id, 'user_id' => $autre->id,
            'status' => 'participating', 'registered_at' => Carbon::now(),
        ]);

        $ics = $this->deplier($this->actingAs($this->membre())->get(route('sessions.ics', $seance))->getContent());

        $this->assertStringContainsString('SUMMARY:Natation', $ics);
        $this->assertStringNotContainsString('Rosalie', $ics);
        $this->assertStringNotContainsString('Trouvé', $ics);
    }

    public function test_une_seance_annulee_porte_les_trois_marqueurs_et_aucun_rappel(): void
    {
        $seance = $this->seance();
        $seance->forceFill(['cancelled_at' => Carbon::now()])->save();

        $ics = $this->deplier(Ics::event($seance, $this->membre(), '', 60));

        $this->assertStringContainsString("SUMMARY:Annulé — Natation\r\n", $ics);
        $this->assertStringContainsString("STATUS:CANCELLED\r\n", $ics);
        $this->assertStringContainsString("TRANSP:TRANSPARENT\r\n", $ics);
        $this->assertStringNotContainsString('VALARM', $ics);
    }

    public function test_les_rappels_avant_le_debut_et_la_veille_au_soir(): void
    {
        $seance = $this->seance();
        $membre = $this->membre();

        $this->assertStringContainsString("TRIGGER:-PT120M\r\n", Ics::event($seance, $membre, '', 120));
        // La veille à 20 h à Paris (été) = 18 h UTC.
        $this->assertStringContainsString("TRIGGER;VALUE=DATE-TIME:20300611T180000Z\r\n", Ics::event($seance, $membre, '', Ics::RAPPEL_VEILLE));
        $this->assertStringNotContainsString('VALARM', Ics::event($seance, $membre));
    }

    /** Deux personnes, même séance, même flux : deux UID, sinon le client en écrase un. */
    public function test_luid_depend_de_la_personne(): void
    {
        $seance = $this->seance();
        preg_match('/UID:(\S+)/', Ics::event($seance, $this->membre()), $a);
        preg_match('/UID:(\S+)/', Ics::event($seance, $this->membre()), $b);

        $this->assertNotSame($a[1], $b[1]);
    }

    public function test_echappement_et_pliage_des_longues_lignes(): void
    {
        $titre = 'Sortie longue; vélo, côte « éprouvante » \\ retour par la côte sauvage et le pont de Saint-Nazaire';
        $ics = Ics::event($this->seance(['title' => $titre]), $this->membre());

        foreach (explode("\r\n", $ics) as $ligne) {
            $this->assertLessThanOrEqual(75, strlen($ligne), "Ligne trop longue : {$ligne}");
            $this->assertTrue(mb_check_encoding($ligne, 'UTF-8'), 'Le pliage a coupé un caractère multi-octets.');
        }
        $this->assertStringContainsString(
            'SUMMARY:Sortie longue\; vélo\, côte « éprouvante » \\\\ retour par la côte sauvage et le pont de Saint-Nazaire',
            $this->deplier($ics),
        );
    }

    public function test_la_fiche_propose_lajout_seulement_pour_une_seance_a_venir(): void
    {
        $membre = $this->membre();
        $avenir = $this->seance(['start_at' => Carbon::now()->addDay()]);
        $annulee = $this->seance(['start_at' => Carbon::now()->addDay()]);
        $annulee->forceFill(['cancelled_at' => Carbon::now()])->save();
        $passee = $this->seance(['start_at' => Carbon::now()->subDays(2)]);

        $this->actingAs($membre)->get(route('sessions.show', $avenir))
            ->assertOk()->assertSee('Ajouter à mon agenda')->assertSee(route('sessions.ics', $avenir), false);
        $this->actingAs($membre)->get(route('sessions.show', $annulee))
            ->assertOk()->assertDontSee('Ajouter à mon agenda');
        $this->actingAs($membre)->get(route('sessions.show', $passee))
            ->assertOk()->assertDontSee('Ajouter à mon agenda');
    }
}

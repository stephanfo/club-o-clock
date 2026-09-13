<?php

namespace Tests\Feature;

use App\Livewire\Home;
use App\Livewire\SessionForm;
use App\Livewire\SessionShow;
use App\Models\Location;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adresse ponctuelle géocodée sur une séance (#37, PRD §4.13.4) : une compétition se tient dans un
 * endroit qui ne revient pas, elle doit avoir météo et carte sans pour autant entrer au catalogue
 * de lieux favoris.
 *
 * Aucun appel réseau : Photon et Open-Meteo sont intégralement `Http::fake()`.
 */
class SessionAdHocLocationTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- contrat d'affichage

    public function test_place_label_prefers_favourite_location(): void
    {
        $s = $this->seance(['location_id' => $this->lieu()->id, 'location_text' => 'RDV parking nord']);

        $this->assertSame('Stade nautique', $s->placeLabel());
    }

    public function test_place_label_falls_back_to_ad_hoc_address(): void
    {
        $s = $this->seance([
            'ad_hoc_address' => '12 av. des Sports, 03200 Vichy',
            'ad_hoc_latitude' => 46.1234567, 'ad_hoc_longitude' => 3.4234567,
            'location_text' => 'RDV parking nord',
        ]);

        $this->assertSame('12 av. des Sports, 03200 Vichy', $s->placeLabel());
    }

    /**
     * Rétrocompatibilité : un `location_text` déjà saisi qui contient en réalité une adresse
     * continue de s'afficher tel quel. C'est ce dernier terme de `placeLabel()` qui dispense de
     * toute migration de données.
     */
    public function test_place_label_keeps_legacy_free_text(): void
    {
        $s = $this->seance(['location_text' => 'Parking du vieux pont, rive sud']);

        $this->assertSame('Parking du vieux pont, rive sud', $s->placeLabel());
    }

    public function test_coordinates_come_from_location_then_ad_hoc_then_null(): void
    {
        $this->assertSame(
            ['lat' => 47.37, 'lng' => -1.17],
            $this->seance(['location_id' => $this->lieu()->id])->coordinates(),
        );
        $this->assertSame(
            ['lat' => 46.1234567, 'lng' => 3.4234567],
            $this->seance(['ad_hoc_address' => 'Vichy', 'ad_hoc_latitude' => 46.1234567, 'ad_hoc_longitude' => 3.4234567])->coordinates(),
        );
        $this->assertNull($this->seance(['location_text' => 'au parc'])->coordinates());
    }

    // ---------------------------------------------------------------- météo & carte sur la fiche

    public function test_weather_and_map_on_ad_hoc_geocoded_session(): void
    {
        $slot = Carbon::now()->addDays(3)->setTime(19, 0);
        $this->openMeteoFake($slot);

        $s = $this->seance([
            'start_at' => $slot,
            'ad_hoc_address' => '12 av. des Sports, 03200 Vichy',
            'ad_hoc_latitude' => 46.1234567, 'ad_hoc_longitude' => 3.4234567,
        ]);

        Livewire::actingAs($this->coach())->test(SessionShow::class, ['session' => $s])
            ->assertViewHas('weatherState', 'full')
            ->assertSee('12 av. des Sports, 03200 Vichy')
            // La carte n'est rendue que géocodée : `locationMap` est le composant Alpine du bloc.
            ->assertSee('locationMap', false);
    }

    /**
     * Assertion négative APPARIÉE à son contrôle positif : sans le premier cas, « la carte est
     * absente » ne prouverait rien (elle pourrait l'être partout).
     */
    public function test_no_weather_nor_map_when_ad_hoc_address_has_no_coordinates(): void
    {
        $s = $this->seance([
            'ad_hoc_address' => 'Quelque part sans coordonnées',
        ]);

        Livewire::actingAs($this->coach())->test(SessionShow::class, ['session' => $s])
            ->assertViewHas('weatherState', 'nogeo')
            // Contrôle positif : l'adresse s'affiche bien, c'est la seule carte qui manque.
            ->assertSee('Quelque part sans coordonnées')
            ->assertDontSee('locationMap', false);
    }

    // ---------------------------------------------------------------- refus serveur

    /**
     * Le grisage client ne suffit jamais : l'état vient du client. Renvoyer les deux champs
     * remplis doit être refusé côté serveur.
     */
    public function test_server_refuses_both_location_and_ad_hoc_address(): void
    {
        $lieu = $this->lieu();
        $coach = $this->coach();

        Livewire::actingAs($coach)->test(SessionForm::class)
            ->set('kind', 'club_event')
            ->set('title', 'Sortie club')
            ->set('start_at', Carbon::now()->addDays(2)->setTime(9, 0)->format('Y-m-d\TH:i'))
            ->set('duration_min', 120)
            ->set('location_id', $lieu->id)
            ->set('ad_hoc_address', '12 av. des Sports, 03200 Vichy')
            ->set('ad_hoc_latitude', 46.1234567)
            ->set('ad_hoc_longitude', 3.4234567)
            ->call('save')
            ->assertHasErrors('ad_hoc_address');

        $this->assertSame(0, Session::where('title', 'Sortie club')->count());
    }

    public function test_ad_hoc_address_alone_is_accepted_and_stored(): void
    {
        $coach = $this->coach();

        Livewire::actingAs($coach)->test(SessionForm::class)
            ->set('kind', 'club_event')
            ->set('title', 'Sortie club')
            ->set('start_at', Carbon::now()->addDays(2)->setTime(9, 0)->format('Y-m-d\TH:i'))
            ->set('duration_min', 120)
            ->set('locationMode', 'adhoc')
            ->set('ad_hoc_address', '12 av. des Sports, 03200 Vichy')
            ->set('ad_hoc_latitude', 46.1234567)
            ->set('ad_hoc_longitude', 3.4234567)
            ->set('location_text', 'RDV parking nord')
            ->call('save')
            ->assertHasNoErrors();

        $s = Session::where('title', 'Sortie club')->firstOrFail();
        $this->assertSame('12 av. des Sports, 03200 Vichy', $s->ad_hoc_address);
        $this->assertNull($s->location_id);
        $this->assertSame('RDV parking nord', $s->location_text);
        $this->assertSame(['lat' => 46.1234567, 'lng' => 3.4234567], $s->coordinates());
    }

    /** Choisir le lieu favori efface l'adresse ponctuelle : les deux champs sont exclusifs en base. */
    public function test_choosing_favourite_location_clears_ad_hoc_columns(): void
    {
        $coach = $this->coach();
        $s = $this->seance([
            'ad_hoc_address' => 'Vichy', 'ad_hoc_latitude' => 46.12, 'ad_hoc_longitude' => 3.42,
        ]);

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $s])
            ->set('locationMode', 'favori')
            ->set('location_id', $this->lieu()->id)
            ->call('save')
            ->assertHasNoErrors();

        $s->refresh();
        $this->assertNull($s->ad_hoc_address);
        $this->assertNull($s->ad_hoc_latitude);
        $this->assertNull($s->ad_hoc_longitude);
    }

    // ---------------------------------------------------------------- géocodage du formulaire

    public function test_form_suggestion_fills_address_and_coordinates(): void
    {
        // ⚠️ `coordinates` est `[longitude, latitude]` chez Photon (cf. GeocodingTest).
        Http::fake(['photon.komoot.io/*' => Http::response(['features' => [[
            'geometry' => ['coordinates' => [3.4234567, 46.1234567]],
            'properties' => [
                'name' => 'Piscine olympique', 'housenumber' => '12', 'street' => 'av. des Sports',
                'postcode' => '03200', 'city' => 'Vichy', 'country' => 'France',
                'osm_key' => 'leisure', 'osm_value' => 'swimming_pool',
            ],
        ]]])]);

        Livewire::actingAs($this->coach())->test(SessionForm::class)
            ->set('locationMode', 'adhoc')
            ->set('ad_hoc_address', 'piscine vichy')
            ->call('pickSuggestion', 0)
            ->assertSet('ad_hoc_address', '12 av. des Sports, 03200 Vichy, France')
            ->assertSet('ad_hoc_latitude', 46.1234567)
            ->assertSet('ad_hoc_longitude', 3.4234567);
    }

    /**
     * Repli du PRD §4.13.4 quand le géocodeur ne trouve rien : aucune suggestion, pas de plantage,
     * et les coordonnées saisies à la main suffisent à porter météo et carte. C'est ce repli qui
     * dispense d'un second bouton « Géocoder » — il taperait le même Photon que l'autocomplétion.
     */
    public function test_manual_coordinates_are_the_fallback_when_geocoder_finds_nothing(): void
    {
        Http::fake(['photon.komoot.io/*' => Http::response(['features' => []])]);

        $composant = Livewire::actingAs($this->coach())->test(SessionForm::class)
            ->set('locationMode', 'adhoc')
            ->set('ad_hoc_address', 'lieu introuvable xyz')
            ->assertSet('addressSuggestions', [])
            ->set('ad_hoc_latitude', 46.1234567)
            ->set('ad_hoc_longitude', 3.4234567)
            ->set('kind', 'club_event')
            ->set('title', 'Sortie hors catalogue')
            ->set('start_at', Carbon::now()->addDays(2)->setTime(9, 0)->format('Y-m-d\TH:i'))
            ->set('duration_min', 120)
            ->call('save');

        $composant->assertHasNoErrors();
        $s = Session::where('title', 'Sortie hors catalogue')->firstOrFail();
        $this->assertSame(['lat' => 46.1234567, 'lng' => 3.4234567], $s->coordinates());
    }

    /**
     * Revue avant mise en production — en français, on tape « 47,37 ». Les propriétés typées
     * `?float` refusaient la chaîne AVANT toute validation : TypeError, donc erreur 500.
     */
    public function test_manual_coordinates_accept_a_decimal_comma(): void
    {
        Http::fake(['photon.komoot.io/*' => Http::response(['features' => []])]);

        $composant = Livewire::actingAs($this->coach())->test(SessionForm::class)
            ->set('locationMode', 'adhoc')
            ->set('ad_hoc_address', 'Plan d\'eau de la Ganguise')
            ->set('ad_hoc_latitude', ' 46,1234567 ')
            ->set('ad_hoc_longitude', '3,4234567')
            ->set('kind', 'club_event')
            ->set('title', 'Sortie virgule')
            ->set('start_at', Carbon::now()->addDays(2)->setTime(9, 0)->format('Y-m-d\TH:i'))
            ->set('duration_min', 120)
            ->call('save');

        $composant->assertHasNoErrors();
        $s = Session::where('title', 'Sortie virgule')->firstOrFail();
        $this->assertSame(['lat' => 46.1234567, 'lng' => 3.4234567], $s->coordinates());
    }

    /** Une saisie qui n'est pas un nombre est refusée par la validation, pas par une 500. */
    public function test_non_numeric_coordinates_are_refused_by_validation(): void
    {
        Http::fake(['photon.komoot.io/*' => Http::response(['features' => []])]);

        Livewire::actingAs($this->coach())->test(SessionForm::class)
            ->set('locationMode', 'adhoc')
            ->set('ad_hoc_address', 'Plan d\'eau de la Ganguise')
            ->set('ad_hoc_latitude', 'nord')
            ->set('ad_hoc_longitude', '3.42')
            ->set('kind', 'club_event')
            ->set('title', 'Sortie invalide')
            ->set('start_at', Carbon::now()->addDays(2)->setTime(9, 0)->format('Y-m-d\TH:i'))
            ->set('duration_min', 120)
            ->call('save')
            ->assertHasErrors('ad_hoc_latitude');

        $this->assertFalse(Session::where('title', 'Sortie invalide')->exists());
    }

    // ---------------------------------------------------------------- pré-calcul météo

    public function test_refresh_command_picks_up_ad_hoc_sessions(): void
    {
        $slot = Carbon::now()->addDays(4)->setTime(8, 0);
        $this->openMeteoFake($slot);

        $this->seance([
            'start_at' => $slot,
            'ad_hoc_address' => '12 av. des Sports, 03200 Vichy',
            'ad_hoc_latitude' => 46.1234567, 'ad_hoc_longitude' => 3.4234567,
        ]);

        $this->artisan('weather:refresh')->expectsOutputToContain('1 séance(s)')->assertExitCode(0);
    }

    /** Contrôle positif/négatif apparié : une séance sans coordonnées n'est pas comptée. */
    public function test_refresh_command_ignores_sessions_without_coordinates(): void
    {
        $slot = Carbon::now()->addDays(4)->setTime(8, 0);
        $this->openMeteoFake($slot);

        $this->seance(['start_at' => $slot, 'location_text' => 'au parc']);

        $this->artisan('weather:refresh')->expectsOutputToContain('0 séance(s)')->assertExitCode(0);
    }

    // ---------------------------------------------------------------- journal d'audit

    public function test_audit_diff_names_the_ad_hoc_address(): void
    {
        $coach = $this->coach();
        $s = $this->seance(['location_id' => $this->lieu()->id]);
        // Le diff de lieu n'est calculé que si la séance a des inscrits actifs à prévenir.
        $s->registrations()->create([
            'user_id' => User::factory()->create(['roles' => ['athlete']])->id,
            'status' => 'participating',
            'registered_at' => Carbon::now(),
        ]);

        $composant = Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $s])
            ->set('locationMode', 'adhoc')
            ->set('location_id', null)
            ->set('ad_hoc_address', '12 av. des Sports, 03200 Vichy')
            ->set('ad_hoc_latitude', 46.1234567)
            ->set('ad_hoc_longitude', 3.4234567)
            ->call('save');

        $changes = collect($composant->get('pendingChanges'));
        $lieu = $changes->firstWhere('label', 'Lieu');
        $this->assertNotNull($lieu, 'Le diff doit signaler le changement de lieu.');
        $this->assertSame('Stade nautique', $lieu['before']);
        $this->assertSame('12 av. des Sports, 03200 Vichy', $lieu['after']);
    }

    // ---------------------------------------------------------------- non-régression accueil

    /**
     * Rendu legacy : une séance n'ayant qu'un vieux `location_text` s'affiche comme avant sur
     * l'accueil. L'accueil rend UNE séance à venir en hero — d'où l'inscription unique.
     */
    public function test_legacy_free_text_still_shown_on_home(): void
    {
        $athlete = User::factory()->create(['roles' => ['athlete']]);
        $s = $this->seance([
            'start_at' => Carbon::now()->addDays(2)->setTime(9, 0),
            'location_text' => 'Parking du vieux pont, rive sud',
        ]);
        $s->registrations()->create([
            'user_id' => $athlete->id, 'status' => 'participating', 'registered_at' => Carbon::now(),
        ]);

        Livewire::actingAs($athlete)->test(Home::class)
            ->assertSee('Parking du vieux pont, rive sud');
    }

    // ---------------------------------------------------------------- fabriques locales

    private function coach(): User
    {
        return $this->coach ??= User::factory()->coach()->create();
    }

    private ?User $coach = null;

    private function lieu(): Location
    {
        return Location::firstOrCreate(
            ['name' => 'Stade nautique'],
            ['latitude' => 47.37, 'longitude' => -1.17, 'created_by' => $this->coach()->id],
        );
    }

    /** @param array<string, mixed> $attrs */
    private function seance(array $attrs = []): Session
    {
        return Session::create(array_merge([
            'kind' => 'club_event',
            'title' => 'Sortie',
            'start_at' => Carbon::now()->addDays(3)->setTime(19, 0),
            'duration_min' => 60,
            'created_by' => $this->coach()->id,
        ], $attrs));
    }

    private function openMeteoFake(Carbon $slot): void
    {
        $time = $slot->copy()->setTime($slot->hour, 0, 0)->format('Y-m-d\TH:00');
        Http::fake(['api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => [$time],
                'temperature_2m' => [14.2],
                'precipitation_probability' => [30],
                'precipitation' => [0.4],
                'wind_speed_10m' => [12.0],
                'wind_direction_10m' => [225],
                'weather_code' => [3],
            ],
        ])]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\InvitationToken;
use App\Models\MagicLinkToken;
use App\Models\User;
use App\Models\WeatherCacheEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Élagage du cache météo (#57) : la table n'était purgée par rien et croissait indéfiniment.
// Le critère est `slot` (créneau passé = donnée morte), jamais `fetched_at` — une entrée périmée
// mais à venir reste la réserve du stale-while-error de WeatherService.
class WeatherCachePruneTest extends TestCase
{
    use RefreshDatabase;

    /** Crée une entrée de cache pour un créneau donné, récupérée il y a $fetchedHoursAgo heures. */
    private function entree(Carbon $slot, float $fetchedHoursAgo = 0): WeatherCacheEntry
    {
        return WeatherCacheEntry::create([
            'latitude' => 47.37,
            'longitude' => -1.17,
            'slot' => $slot,
            'forecast' => ['temp' => 12.0, 'code' => 0],
            'fetched_at' => Carbon::now()->subMinutes((int) round($fetchedHoursAgo * 60)),
        ]);
    }

    public function test_model_prune_elague_les_creneaux_passes_et_garde_les_creneaux_a_venir(): void
    {
        $passe = $this->entree(Carbon::now()->subHours(2));
        // Contrôle positif apparié : périmée pour le TTL (3 h), mais son créneau est à venir —
        // c'est exactement l'entrée que sert le mode dégradé quand Open-Meteo est injoignable.
        $aVenir = $this->entree(Carbon::now()->addHours(5), fetchedHoursAgo: 6);

        $this->artisan('model:prune')->assertSuccessful();

        $this->assertDatabaseMissing('weather_cache_entries', ['id' => $passe->id]);
        $this->assertDatabaseHas('weather_cache_entries', ['id' => $aVenir->id]);
    }

    public function test_club_prune_tokens_ramasse_le_cache_meteo_sans_option_model(): void
    {
        $passe = $this->entree(Carbon::now()->subDay());
        $aVenir = $this->entree(Carbon::now()->addDay());

        $this->artisan('club:prune-tokens')->assertSuccessful();

        $this->assertDatabaseMissing('weather_cache_entries', ['id' => $passe->id]);
        $this->assertDatabaseHas('weather_cache_entries', ['id' => $aVenir->id]);
    }

    public function test_club_prune_tokens_elague_toujours_les_jetons_dauth(): void
    {
        $expire = MagicLinkToken::create([
            'email' => 'expire@demo.club',
            'token_hash' => hash('sha256', 'a'),
            'expires_at' => Carbon::now()->subHour(),
        ]);
        $vivant = MagicLinkToken::create([
            'email' => 'vivant@demo.club',
            'token_hash' => hash('sha256', 'b'),
            'expires_at' => Carbon::now()->addHour(),
        ]);
        $invitExpiree = InvitationToken::create([
            'user_id' => User::factory()->create()->id,
            'token_hash' => hash('sha256', 'c'),
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $this->artisan('club:prune-tokens')->assertSuccessful();

        $this->assertDatabaseMissing('magic_link_tokens', ['id' => $expire->id]);
        $this->assertDatabaseHas('magic_link_tokens', ['id' => $vivant->id]);
        $this->assertDatabaseMissing('invitation_tokens', ['id' => $invitExpiree->id]);
    }
}

<?php

namespace App\Livewire;

use App\Services\AuthMethodService;
use App\Services\MemberService;
use App\Services\NotificationPreferenceService;
use App\Services\QuotaService;
use App\Support\Logging\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

// Profil de l'utilisateur connecté (porté de screen-profil.jsx). Quatre onglets :
//   Identité  — nom éditable + champs gérés par le bureau (lecture) ;
//   Notifs    — matrice type×canal (§4.15.3) + pause globale (§4.15.4) ;
//   Quotas    — usage hebdo par tag de la semaine courante (§4.10) ;
//   Connexion — méthodes de login liées, sessions actives, déconnexion (suppression compte §4.3 à part).
// Lecture/écriture toujours sur le compte courant (auth()->user()) — aucune action sur un tiers.
#[Layout('layouts.app')]
#[Title('Profil')]
class Profil extends Component
{
    /** Onglet actif : identite | notifs | quotas | connexion. */
    #[Url]
    public string $tab = 'identite';

    // ── Identité (éditable) ──
    public string $first_name = '';

    public string $last_name = '';

    // ── Notifs ──
    /** Pause globale (§4.15.4). */
    public bool $paused = false;

    /** Matrice normalisée des types visibles : [typeValue => [canal => bool]]. */
    public array $matrix = [];

    /**
     * Canaux ouverts à l'échelle du club (§4.17) : [canal => bool]. Une colonne dont le canal est
     * coupé s'affiche inerte — le réglage personnel reste stocké pour une éventuelle réactivation.
     */
    public array $clubChannels = [];

    // ── Connexion : suppression de compte ──
    /** Modale de confirmation « supprimer mon compte » ouverte. */
    public bool $showDeleteDialog = false;

    public function mount(NotificationPreferenceService $prefs): void
    {
        $u = auth()->user();
        $this->first_name = (string) $u->first_name;
        $this->last_name = (string) $u->last_name;

        $state = $prefs->state($u); // pause + matrice + interrupteurs club en une seule lecture
        $this->paused = $state['paused'];
        $this->matrix = $state['matrix'];
        $this->clubChannels = $state['clubChannels'];
    }

    // ── Identité ──

    public function saveIdentity(): void
    {
        $data = $this->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
        ]);

        auth()->user()->update($data);
        session()->flash('status', 'Profil mis à jour.');
    }

    // ── Notifs (§4.15.3 / §4.15.4) ──

    public function togglePause(NotificationPreferenceService $prefs): void
    {
        $this->paused = ! $this->paused;
        $prefs->setPaused(auth()->user(), $this->paused);
    }

    public function togglePref(string $typeValue, string $channel, NotificationPreferenceService $prefs): void
    {
        // (type, canal) viennent du client : n'agir que sur une cellule réellement présente dans l'état
        // normalisé au mount (type visible + canal éligible). Bloque l'injection de clés arbitraires.
        if (! isset($this->matrix[$typeValue][$channel])) {
            return;
        }

        $next = ! $this->matrix[$typeValue][$channel];
        $this->matrix[$typeValue][$channel] = $next;
        $prefs->setCell(auth()->user(), $typeValue, $channel, $next);
    }

    // ── Connexion : méthodes de login (§4.1.1) ──

    /** Délie une identité OAuth (Google). Refuse si c'est le dernier moyen de se connecter. */
    public function revokeMethod(int $identityId, AuthMethodService $authMethods): void
    {
        $u = auth()->user();
        $identity = $u->authIdentities()->find($identityId);
        if ($identity === null) {
            return;
        }

        // Garde-fou : ne pas verrouiller l'utilisateur dehors. Il garde un accès s'il a un mot de
        // passe, un email (lien magique) ou une autre identité liée.
        //
        // Chaque voie n'est un accès que si le club l'a laissée ouverte (§4.17) : un email ne sauve
        // plus rien si le lien magique est coupé, et une autre identité Google ne sauve rien si
        // Google est coupé. Le mot de passe, lui, est toujours utilisable.
        $hasOtherWay = $u->password !== null
            || ($u->email !== null && $authMethods->magicLinkEnabled())
            || ($authMethods->googleEnabled() && $u->authIdentities()->where('id', '!=', $identityId)->exists());
        if (! $hasOtherWay) {
            session()->flash('warn', 'Impossible de délier ta seule méthode de connexion.');

            return;
        }

        $provider = $identity->provider;
        $identity->delete();
        AuditLogger::record('auth_method_unlinked', $u, ['motif' => $provider]);
        session()->flash('status', ucfirst($provider).' délié de ton compte.');
    }

    // ── Connexion : sessions actives (driver base de données) ──

    /** Révoque une session précise (déconnecte cet appareil). Jamais la session courante. */
    public function revokeSession(string $sessionId): void
    {
        if ($sessionId === session()->getId()) {
            return;
        }

        DB::table(config('session.table'))
            ->where('user_id', auth()->id())
            ->where('id', $sessionId)
            ->delete();
        $this->invalidateRememberCookies();
        session()->flash('status', 'Appareil déconnecté.');
    }

    /** Déconnecte tous les autres appareils (conserve la session courante). */
    public function revokeOtherSessions(): void
    {
        DB::table(config('session.table'))
            ->where('user_id', auth()->id())
            ->where('id', '!=', session()->getId())
            ->delete();
        $this->invalidateRememberCookies();
        session()->flash('status', 'Déconnecté de tous les autres appareils.');
    }

    /**
     * Supprimer une ligne de session ne suffit pas à déconnecter un appareil « souviens-toi de moi » :
     * les deux chemins de login posent un cookie remember (MagicLink/OAuth), et Laravel ré-authentifie
     * sinon l'appareil au prochain passage. On régénère donc `remember_token` (invalide TOUS les cookies
     * remember — le jeton est par-utilisateur, pas par-session) puis on ré-émet celui de l'appareil
     * courant pour ne pas se déconnecter soi-même. Effet de bord assumé : une révocation ciblée invalide
     * aussi le remember des autres appareils encore ouverts (ils gardent leur session active en cours).
     */
    private function invalidateRememberCookies(): void
    {
        $u = auth()->user();
        $u->setRememberToken(Str::random(60));
        $u->save();
        Auth::login($u, remember: true); // régénère l'id de session courante + rafraîchit son cookie remember
    }

    // ── Connexion : suppression de compte (§4.3 voie 1) ──
    // Choix produit : la demande de l'athlète ouvre directement le tampon bloquant de 7 j (réutilise
    // le même flow que la voie admin). L'admin garde la main — il confirme (ou non) la suppression
    // définitive à J+7. `is_active=false` ne bloque que les NOUVEAUX logins (§4.3), pas la session
    // courante : l'athlète peut donc se rétracter in-app tant que la demande est ouverte.

    public function confirmDeleteAccount(): void
    {
        $this->showDeleteDialog = true;
    }

    public function dismissDeleteDialog(): void
    {
        $this->showDeleteDialog = false;
    }

    public function requestDeletion(MemberService $service): void
    {
        $this->showDeleteDialog = false;

        try {
            $service->requestDeletion(auth()->user(), auth()->user());
        } catch (\RuntimeException $e) {
            session()->flash('warn', $e->getMessage()); // ex. dernier admin actif

            return;
        }

        session()->flash('status', 'Demande de suppression envoyée au bureau.');
    }

    public function cancelDeletion(MemberService $service): void
    {
        $service->cancelDeletion(auth()->user(), auth()->user());
        session()->flash('status', 'Demande de suppression annulée.');
    }

    // ── Rendu ──

    public function render(QuotaService $quota, NotificationPreferenceService $prefs, MemberService $members, AuthMethodService $authMethods)
    {
        [$from, $to] = $quota->weekBounds(Carbon::now());

        return view('livewire.profil', [
            'user' => auth()->user(),
            'groups' => $prefs->visibleGroups(auth()->user()),
            'methods' => $this->linkedMethods($authMethods),
            'sessions' => $this->activeSessions(),
            'quotas' => $quota->weeklyUsage(auth()->user(), Carbon::now()),
            'lastAdmin' => $members->isLastActiveAdmin(auth()->user()),
            'weekLabel' => 'Semaine du '.$from->locale('fr')->isoFormat('D MMMM').' au '.$to->locale('fr')->isoFormat('D MMMM'),
        ]);
    }

    /**
     * Méthodes de connexion liées au compte (§4.1.1) : identités OAuth + mot de passe + lien magique.
     *
     * `off` marque une méthode liée au compte mais fermée par le club (§4.17) : elle reste listée —
     * elle redeviendra utilisable telle quelle à la réactivation — mais elle est affichée comme
     * inopérante, sinon l'écran promet un accès qui ne fonctionne pas.
     */
    private function linkedMethods(AuthMethodService $authMethods): array
    {
        $u = auth()->user();
        $methods = [];
        $googleOn = $authMethods->googleEnabled();

        foreach ($u->authIdentities()->orderBy('provider')->get() as $i) {
            $methods[] = [
                'id' => $i->id,
                'label' => ucfirst($i->provider),
                'sub' => $i->email_at_link ?: '—',
                'revocable' => true,
                'off' => ! $googleOn,
            ];
        }
        if ($u->password !== null) {
            $methods[] = ['id' => null, 'label' => 'Mot de passe', 'sub' => 'Défini', 'revocable' => false, 'off' => false];
        }
        if ($u->email !== null) {
            $methods[] = [
                'id' => null,
                'label' => 'Lien magique',
                'sub' => $u->email,
                'revocable' => false,
                'off' => ! $authMethods->magicLinkEnabled(),
            ];
        }

        return $methods;
    }

    /** Sessions actives de l'utilisateur depuis la table de sessions HTTP (driver base). */
    private function activeSessions(): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        $current = session()->getId();

        return DB::table(config('session.table'))
            ->where('user_id', auth()->id())
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'current' => $s->id === $current,
                'device' => $this->deviceLabel($s->user_agent),
                'last' => Carbon::createFromTimestamp($s->last_activity)->diffForHumans(),
            ])
            ->all();
    }

    /** Étiquette appareil heuristique à partir du User-Agent (présentation, best-effort). */
    private function deviceLabel(?string $ua): string
    {
        if ($ua === null || $ua === '') {
            return 'Appareil inconnu';
        }

        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Appareil',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Navigateur',
        };

        return "{$os} · {$browser}";
    }
}

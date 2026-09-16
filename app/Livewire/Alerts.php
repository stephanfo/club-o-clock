<?php

namespace App\Livewire;

use App\Models\ClubSettings;
use App\Models\NotificationOutbox;
use App\Models\Session;
use App\Models\User;
use App\Notifications\NotificationType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

// Page Alertes — liste les notifications push envoyées à l'utilisateur courant (outbox sent).
// Source de vérité : notification_outbox, filtrée par NotificationOutbox::alertsFor (visibilité #79 :
// J+7 après la séance, 60 jours sans séance, alertes masquées exclues).
// État lu/non-lu (revue UX 2026-07-11) : ouvrir la page marque tout lu → le badge (cloche,
// nav) retombe à zéro. Le marquage est en bloc, pas par ligne (geste unique, pas de gestion).
#[Layout('layouts.app')]
#[Title('Alertes')]
class Alerts extends Component
{
    /** Cartes affichées au plus. */
    private const MAX_CARDS = 50;

    /** Écart maximal entre deux lignes d'un même envoi (routage parent/enfant, §4.15.5). */
    private const GROUP_WINDOW_SECONDS = 120;

    /**
     * Dialog « Tout effacer » ouvert ? Le retrait d'UNE carte est anodin et réversible d'un coup
     * d'œil (wire:confirm natif) ; vider la liste entière ne l'est pas — il porte aussi sur les
     * alertes que la liste ne montre pas (elle plafonne à MAX_CARDS) et ne se défait pas depuis
     * l'écran. D'où un dialog de niveau 2 qui en énonce les conséquences.
     */
    public bool $confirmingDismissAll = false;

    public function mount(): void
    {
        NotificationOutbox::alertsFor(auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        // Le badge (sidebar + cloche) est rendu après ce mount : invalider le cache par requête
        // pour qu'il reflète le marquage lu qu'on vient d'appliquer.
        NotificationOutbox::forgetUnreadCount(auth()->id());
    }

    /**
     * Retire une carte (#79) — toutes les lignes de son groupe. Le filtre par destinataire est la
     * garde : un id étranger forgé côté client ne touche rien. La ligne reste pour l'écran des envois.
     *
     * @param  array<int,int|string>  $ids
     */
    public function dismiss(array $ids): void
    {
        NotificationOutbox::alertsFor(auth()->id())
            ->whereIn('id', array_map('intval', $ids))
            ->update(['dismissed_at' => now()]);

        NotificationOutbox::forgetUnreadCount(auth()->id());
    }

    public function openDismissAllConfirm(): void
    {
        $this->confirmingDismissAll = true;
    }

    public function dismissDismissAllConfirm(): void
    {
        $this->confirmingDismissAll = false;
    }

    /** « Tout effacer » : masque toutes les alertes visibles de l'utilisateur. */
    public function dismissAll(): void
    {
        NotificationOutbox::alertsFor(auth()->id())->update(['dismissed_at' => now()]);

        NotificationOutbox::forgetUnreadCount(auth()->id());
        $this->confirmingDismissAll = false;
    }

    public function render()
    {
        // Marge sur la limite : une carte regroupée consomme plusieurs lignes.
        $outboxItems = NotificationOutbox::alertsFor(auth()->id())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_CARDS * 4)
            ->get();

        $groups = self::group($outboxItems)->take(self::MAX_CARDS);

        // Résoudre les session_id en séances (batch). On lit la base plutôt que le titre figé au
        // payload : cet écran se consulte longtemps après l'envoi, une séance renommée depuis doit
        // s'y voir sous son nom actuel. Le payload sert de repli quand la séance a été supprimée.
        $sessionIds = $outboxItems->pluck('payload')->map(fn ($p) => $p['session_id'] ?? null)->filter()->unique()->values()->all();
        $sessions = Session::whereIn('id', $sessionIds)->get()->keyBy('id');

        // Prénom du sujet : re-résolu depuis subject_id, car le prénom figé au payload est purgé à
        // l'envoi (NotificationOutbox::VOLATILE_PAYLOAD_KEYS) et ces lignes sont toutes `sent`.
        $subjectIds = $outboxItems->pluck('payload')->map(fn ($p) => $p['subject_id'] ?? null)->filter()->unique()->values()->all();
        $subjects = User::whereIn('id', $subjectIds)->pluck('first_name', 'id');

        $tz = ClubSettings::current()->timezone;

        $alerts = $groups->map(function (Collection $rows) use ($sessions, $subjects, $tz) {
            /** @var NotificationOutbox $row */
            $row = $rows->first();
            $type = NotificationType::tryFrom($row->type);
            $sessionId = $row->payload['session_id'] ?? null;
            $session = $sessionId ? ($sessions[$sessionId] ?? null) : null;
            $label = $type?->label() ?? $row->type;

            return [
                'ids' => $rows->pluck('id')->all(),
                'title' => self::titre($rows, $label, $subjects),
                'sub' => self::sousTitre($session, $row->payload ?? [], $tz),
                'when' => $row->sent_at?->diffForHumans() ?? '',
                'icon' => self::iconFor($type),
                'tintBg' => self::tintBgFor($type),
                'tintFg' => self::tintFgFor($type),
                'sessionId' => $sessionId,
            ];
        })->values();

        return view('livewire.alerts', ['alerts' => $alerts]);
    }

    /**
     * Regroupe en une carte les lignes d'un même envoi (#79) : même type, même séance, sujets
     * différents, créées à moins de GROUP_WINDOW_SECONDS d'écart. Aucun identifiant ne relie ces
     * lignes — le routage §4.15.5 appelle le dispatcher une fois par sujet —, d'où la proximité
     * temporelle. Un sujet déjà présent ouvre une nouvelle carte : deux modifications successives
     * d'une même séance restent deux cartes.
     *
     * @param  Collection<int,NotificationOutbox>  $rows  triées de la plus récente à la plus ancienne
     * @return Collection<int,Collection<int,NotificationOutbox>>
     */
    private static function group(Collection $rows): Collection
    {
        $groups = [];
        $open = []; // clé type|séance → index du groupe ouvert

        foreach ($rows as $row) {
            $sessionId = $row->payload['session_id'] ?? null;
            $subject = $row->payload['subject_id'] ?? $row->user_id;
            $key = $sessionId !== null ? $row->type.'|'.$sessionId : null;

            if ($key !== null && isset($open[$key])) {
                $group = $groups[$open[$key]];
                $oldest = $group->last();
                $close = $oldest->created_at->diffInSeconds($row->created_at, true) <= self::GROUP_WINDOW_SECONDS;
                $sujets = $group->map(fn ($r) => $r->payload['subject_id'] ?? $r->user_id);

                if ($close && ! $sujets->contains($subject)) {
                    $groups[$open[$key]]->push($row);

                    continue;
                }
            }

            $groups[] = collect([$row]);
            if ($key !== null) {
                $open[$key] = array_key_last($groups);
            }
        }

        return collect($groups);
    }

    /**
     * Titre d'une carte. Ligne seule : le prénom du sujet quand il n'est pas le destinataire
     * (même composition que le push). Groupe : « Toi, Léa et Tom · … », « Toi » en tête.
     *
     * @param  Collection<int,NotificationOutbox>  $rows
     * @param  Collection<int,string>  $subjects
     */
    private static function titre(Collection $rows, string $label, Collection $subjects): string
    {
        $self = false;
        $prenoms = [];
        foreach ($rows as $row) {
            $subjectId = $row->payload['subject_id'] ?? null;
            if ($subjectId === null || $subjectId === $row->user_id) {
                $self = true;
            } elseif (isset($subjects[$subjectId])) {
                $prenoms[] = $subjects[$subjectId];
            }
        }

        sort($prenoms);
        if ($rows->count() > 1 && $self) {
            array_unshift($prenoms, 'Toi');
        }

        if ($prenoms === []) {
            return $label;
        }

        $noms = count($prenoms) > 1
            ? implode(', ', array_slice($prenoms, 0, -1)).' et '.end($prenoms)
            : $prenoms[0];

        return $noms.' · '.$label;
    }

    /**
     * Sous-titre d'une alerte : la séance et son créneau, à l'heure du club. Le titre vient de la
     * base (frais) ; le payload prend le relais si la séance a été supprimée depuis — sans lui, une
     * alerte d'annulation devenait une ligne muette au moment où elle sert encore.
     *
     * @param  array<string,mixed>  $payload
     */
    private static function sousTitre(?Session $session, array $payload, string $tz): ?string
    {
        $titre = $session->title ?? ($payload['session_title'] ?? null);

        if ($titre === null) {
            return null;
        }

        // Le créneau suit le même repli que le titre. Sans lui, une séance supprimée (§4.7) laissait
        // une ligne réduite au seul titre — « SwimRun St Nazaire » sans date —, alors que le payload
        // porte la réponse. Le repli ne servait à rien tant que rien ne supprimait de séance.
        // Carbon::parse accepte aussi bien l'instance de la base que la chaîne ISO du payload.
        $quand = $session->start_at ?? ($payload['session_start_at'] ?? null);

        if ($quand === null) {
            return $titre;
        }

        $start = Carbon::parse($quand)->setTimezone($tz)->locale('fr');

        return $titre.' · '.$start->isoFormat('ddd D MMM').' · '.$start->format('H:i');
    }

    private static function iconFor(?NotificationType $t): string
    {
        return match ($t) {
            NotificationType::WaitlistPromoted => 'check',
            NotificationType::SessionCancelled => 'x',
            NotificationType::SessionRestored => 'rotate-ccw',
            NotificationType::EnrolledByCoach, NotificationType::CoachOverride => 'user-check',
            NotificationType::SessionModified, NotificationType::SessionContent => 'pen-line',
            NotificationType::NewDebrief => 'pen-line',
            NotificationType::CoachAssigned, NotificationType::CoachRegistration => 'user-check',
            NotificationType::EventCreated => 'calendar',
            default => 'bell',
        };
    }

    private static function tintBgFor(?NotificationType $t): string
    {
        return match ($t) {
            NotificationType::WaitlistPromoted, NotificationType::EnrolledByCoach,
            NotificationType::CoachOverride, NotificationType::CoachAssigned => 'var(--brand-50)',
            NotificationType::SessionCancelled => 'var(--accent-50)',
            NotificationType::SessionRestored => 'var(--brand-50)',
            NotificationType::SessionModified, NotificationType::SessionContent,
            NotificationType::EventCreated => 'var(--info-50)',
            default => 'var(--slate-100)',
        };
    }

    private static function tintFgFor(?NotificationType $t): string
    {
        return match ($t) {
            NotificationType::WaitlistPromoted, NotificationType::EnrolledByCoach,
            NotificationType::CoachOverride, NotificationType::CoachAssigned,
            NotificationType::SessionRestored => 'var(--brand-700)',
            NotificationType::SessionCancelled => 'var(--accent-700)',
            NotificationType::SessionModified, NotificationType::SessionContent,
            NotificationType::EventCreated => 'var(--info-700)',
            default => 'var(--slate-700)',
        };
    }
}

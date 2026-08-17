<?php

namespace App\Services;

use App\Models\ClubSettings;
use App\Models\Session;
use App\Models\SessionTemplate;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationType;
use App\Support\Logging\ActivityLogger;
use App\Support\Logging\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Générateur de séances (PRD §4.8). Un SessionTemplate produit N Session INDÉPENDANTES sur une
// plage [start, end] : une par occurrence hebdomadaire du jour choisi. Pas de RRULE/EXDATE, pas
// de lien comportemental retour (sourceTemplateId = audit-only). Réutilisable : relancer sur une
// nouvelle plage ajoute des Session sans écraser les précédentes (§4.8 Réutilisation).
class TemplateGenerationService
{
    /** Garde-fou anti-boucle : une plage ne peut couvrir plus de ~2 ans de semaines. */
    private const MAX_OCCURRENCES = 160;

    public function __construct(private NotificationDispatcher $notifier) {}

    /**
     * Dates (Carbon, début de journée) du jour de semaine du template dans [start, end] inclus.
     * day_of_week est ISO 1..7 (lundi..dimanche) — aligné sur Carbon::dayOfWeekIso.
     *
     * @return Collection<int, Carbon>
     */
    public function occurrences(SessionTemplate $template, Carbon $start, Carbon $end): Collection
    {
        $out = collect();
        if ($start->gt($end)) {
            return $out;
        }

        // Avance jusqu'au 1er jour-cible ≥ start, puis saute de semaine en semaine.
        $cursor = $start->copy()->startOfDay();
        $delta = ($template->day_of_week - $cursor->dayOfWeekIso + 7) % 7;
        $cursor->addDays($delta);

        $endDay = $end->copy()->startOfDay();
        while ($cursor->lte($endDay) && $out->count() < self::MAX_OCCURRENCES) {
            $out->push($cursor->copy());
            $cursor->addWeek();
        }

        return $out;
    }

    /**
     * Génère les Session pour la plage [start, end] (par défaut = la plage stockée du template).
     * Chaque Session : identité propre, createdBy = admin (= template->created_by), coaches[] =
     * defaultCoachIds, sourceTemplateId = template (audit). N entrées ActivityLog coach_registered
     * par coach (traçabilité fine §4.8) ; une notif récapitulative UNIQUE par coach (coach_template_recap)
     * est émise après commit, au lieu d'une par séance générée.
     *
     * @return Collection<int, Session> les séances créées (vide si aucune occurrence).
     */
    public function generate(SessionTemplate $template, User $actor, ?Carbon $start = null, ?Carbon $end = null): Collection
    {
        $start ??= $template->generation_start_date;
        $end ??= $template->generation_end_date;

        $template->loadMissing(['categories', 'defaultCoaches']);
        $coachIds = $template->defaultCoaches->pluck('id')->all();
        $categoryIds = $template->categories->pluck('id')->all();

        [$h, $m] = $this->timeParts($template->start_time_of_day);
        // start_at construit dans le fuseau du club (comme SessionForm) : 19:00 = 19:00 heure
        // locale, pas UTC. Sans ça les séances générées seraient décalées (ex. +2 h l'été).
        $tz = ClubSettings::current()->timezone;

        $created = DB::transaction(function () use ($template, $actor, $start, $end, $coachIds, $categoryIds, $h, $m, $tz) {
            $created = collect();

            foreach ($this->occurrences($template, $start, $end) as $day) {
                $session = Session::create([
                    'kind' => $template->kind,
                    'title' => $template->label,
                    'discipline_id' => $template->discipline_id,
                    // Heure locale club : on construit l'instant dans $tz (comme SessionForm). Le
                    // mutateur start_at du modèle le convertit en UTC à l'écriture → la séance
                    // générée à 19:00 s'affiche à 19:00 partout, cohérente avec la saisie manuelle.
                    'start_at' => Carbon::create($day->year, $day->month, $day->day, $h, $m, 0, $tz),
                    'duration_min' => $template->duration_min,
                    'location_id' => $template->location_id,
                    'location_text' => $template->location_text,
                    'capacity' => $template->capacity,
                    'quota_tag_id' => $template->kind === 'training' ? $template->quota_tag_id : null,
                    'created_by' => $template->created_by,
                    'source_template_id' => $template->id,
                ]);

                if ($categoryIds) {
                    $session->categories()->sync($categoryIds);
                }

                if ($coachIds) {
                    $session->coaches()->sync($coachIds);
                    // Traçabilité fine : une entrée par (séance, coach), actor = admin générateur.
                    foreach ($coachIds as $coachId) {
                        ActivityLogger::record('coach_registered', $actor, [
                            'user_id' => $coachId,
                            'session_id' => $session->id,
                        ]);
                    }
                }

                $created->push($session);
            }

            AuditLogger::record('generate_sessions', $actor, [
                'target_type' => SessionTemplate::class,
                'target_id' => $template->id,
                'motif' => $created->count().' séances · '.$start->toDateString().' → '.$end->toDateString(),
            ]);

            return $created;
        });

        // Récap unique par coach par défaut (§4.15.2) : une seule notif au lieu d'une par séance
        // générée. Émise APRÈS commit, seulement si des séances ont effectivement été créées.
        if ($coachIds !== [] && $created->isNotEmpty()) {
            $payload = [
                'template_id' => $template->id,
                'count' => $created->count(),
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ];
            foreach (User::whereIn('id', $coachIds)->get() as $coach) {
                $this->notifier->dispatch(NotificationType::CoachTemplateRecap, $coach, $payload);
            }
        }

        return $created;
    }

    /**
     * Relance / prolongation (§4.8 Réutilisation) : régénère sur une NOUVELLE plage sans toucher
     * aux séances déjà générées. La plage stockée du template n'est pas écrasée.
     *
     * @return Collection<int, Session>
     */
    public function relaunch(SessionTemplate $template, User $actor, Carbon $start, Carbon $end): Collection
    {
        return $this->generate($template, $actor, $start, $end);
    }

    /** @return array{0:int,1:int} [heure, minute] d'un start_time_of_day (cast string "HH:MM[:SS]"). */
    private function timeParts(string $time): array
    {
        $parts = explode(':', $time);

        return [(int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0)];
    }
}

<?php

namespace App\Livewire\Concerns\SessionShow;

use App\Models\Debrief;
use App\Services\DebriefService;
use RuntimeException;

// Débriefs de compétition (§4.12.5) : rédaction, édition, archivage réversible.
trait ManagesDebriefs
{
    /** Éditeur ouvert ? */
    public bool $debriefOpen = false;

    /** Débrief en cours d'édition (id) ou null = nouveau débrief de l'utilisateur. */
    public ?int $debriefId = null;

    /** Markdown de travail (synchronisé par l'îlot TipTap). */
    public string $debriefMarkdown = '';

    /** Débrief en attente de confirmation d'archivage (id) ou null. */
    public ?int $debriefArchiveId = null;

    /** Ouvre l'éditeur : nouveau débrief (id null) ou édition d'un débrief existant. */
    public function openDebrief(?int $id = null): void
    {
        if ($id === null) {
            // Garde « rédiger » : participant + compétition commencée + pas de débrief existant.
            // Atteignable sur état périmé (second onglet resté ouvert) : on le dit, sinon le bouton
            // paraît mort. Le refresh fait disparaître le bouton devenu caduc.
            if (! $this->canWriteDebrief()) {
                session()->flash('warn', "Rédaction impossible : la séance ou ton inscription a changé depuis l'ouverture de la page.");
                $this->refreshSession();

                return;
            }
            $this->debriefId = null;
            $this->debriefMarkdown = '';
        } else {
            $debrief = Debrief::findOrFail($id);
            $this->authorize('update', $debrief);
            $this->debriefId = $debrief->id;
            $this->debriefMarkdown = $debrief->content_markdown;
        }

        $this->debriefOpen = true;
    }

    public function closeDebrief(): void
    {
        $this->debriefOpen = false;
        $this->debriefId = null;
        $this->debriefMarkdown = '';
    }

    public function saveDebrief(DebriefService $service): void
    {
        try {
            if ($this->debriefId === null) {
                $service->publish($this->session, auth()->user(), $this->debriefMarkdown);
            } else {
                $debrief = Debrief::findOrFail($this->debriefId);
                $this->authorize('update', $debrief);
                $service->update($debrief, auth()->user(), $this->debriefMarkdown);
            }
            $this->closeDebrief();
        } catch (RuntimeException $e) {
            session()->flash('warn', $e->getMessage());
        }

        $this->refreshSession();
    }

    public function confirmArchiveDebrief(int $id): void
    {
        $this->authorize('archive', Debrief::findOrFail($id));
        $this->debriefArchiveId = $id;
    }

    public function cancelArchiveDebrief(): void
    {
        $this->debriefArchiveId = null;
    }

    public function archiveDebrief(DebriefService $service, int $id): void
    {
        $debrief = Debrief::findOrFail($id);
        $this->authorize('archive', $debrief);
        $service->archive($debrief, auth()->user());
        $this->debriefArchiveId = null;
        $this->refreshSession();
    }

    public function restoreDebrief(DebriefService $service, int $id): void
    {
        $debrief = Debrief::findOrFail($id);
        $this->authorize('archive', $debrief);
        $service->restore($debrief, auth()->user());
        $this->refreshSession();
    }

    /** Peut rédiger un nouveau débrief : compétition commencée + participe + aucun débrief sien. */
    private function canWriteDebrief(): bool
    {
        $me = auth()->user();
        if ($me === null || $this->session->kind !== 'competition' || ! $this->session->hasStarted()) {
            return false;
        }
        $myReg = $this->session->registrations->firstWhere('user_id', $me->id);
        $iParticipate = $myReg !== null && $myReg->status === 'participating';
        $alreadyAuthored = $this->session->debriefs->contains('author_id', $me->id);

        return $iParticipate && ! $alreadyAuthored;
    }
}

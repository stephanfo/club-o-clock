<?php

namespace App\Livewire\Concerns\SessionShow;

use App\Models\User;
use App\Services\AperoService;
use RuntimeException;

// Flag apéro (§4.14) : geste personnel + modération coach/admin.
trait ManagesApero
{
    /** Motif libre optionnel saisi avant de flagger (max 140 — §4.14.2). */
    public string $aperoMotif = '';

    /** J'offre l'apéro (geste personnel, jamais par procuration §4.14.1). */
    public function flagApero(AperoService $apero): void
    {
        try {
            $apero->flag($this->session, auth()->user(), $this->aperoMotif);
            $this->aperoMotif = '';
        } catch (RuntimeException $e) {
            session()->flash('warn', $e->getMessage());
        }

        $this->refreshSession();
    }

    /**
     * Retire un flag : self-déflag ($payerId == moi, voie 1) ou modération coach/admin (voie 2).
     */
    public function unflagApero(AperoService $apero, int $payerId): void
    {
        $me = auth()->user();
        if ($payerId !== $me->id) {
            $this->authorize('moderateApero', $this->session); // voie 2 : coach/admin uniquement.
        }

        $payer = User::findOrFail($payerId);

        try {
            $apero->unflag($this->session, $payer, $me);
        } catch (RuntimeException $e) {
            session()->flash('warn', $e->getMessage());
        }

        $this->refreshSession();
    }
}

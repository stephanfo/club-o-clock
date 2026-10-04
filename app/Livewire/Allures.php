<?php

namespace App\Livewire;

use App\Models\AllureLevel;
use App\Models\AllureZone;
use App\Models\ReferenceValue;
use App\Models\User;
use App\Services\AlluresService;
use App\Support\Allures\Calculateur;
use App\Support\Allures\Referentiel;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

// Écran « Allures course » (#114), ouvert depuis le profil. Lecture et écriture toujours sur le
// compte connecté : la VMA n'est visible que de la personne (ni coach, ni admin, ni parent).
// Les calculs vivent dans App\Support\Allures\Calculateur (testé) ; seul le curseur de % libre
// recalcule côté navigateur. Les chronos saisis pour l'estimation ne sont jamais enregistrés.
#[Layout('layouts.app')]
#[Title('Allures course')]
class Allures extends Component
{
    /** VMA affichée (km/h, saisie libre « 13,6 ») — modifiable sans être enregistrée. */
    public string $vma = '';

    /** Estimation : distance (clé du référentiel) et temps saisi (« 47:45 », « 1h49 »). */
    public string $estDistance = '10k';

    public string $estTime = '';

    /** Niveau de la table club (id), choisi à chaque usage et jamais stocké. */
    public ?int $levelId = null;

    private function referentiel(): Referentiel
    {
        return Referentiel::Course;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function mount(): void
    {
        $ref = $this->user()->referenceValue($this->referentiel());
        $this->vma = $ref !== null ? Calculateur::formatVma($ref->value) : '';
        $this->levelId = AllureLevel::forReferentiel($this->referentiel())->first()?->id;
    }

    /** « 13,6 » → 13.6 ; null si vide ou illisible. */
    private function parsedVma(): ?float
    {
        $v = str_replace(',', '.', trim($this->vma));

        return is_numeric($v) ? (float) $v : null;
    }

    /** Niveau choisi ; le premier de la table si le choix n'existe plus ; null sans table (Riegel). */
    private function level(): ?AllureLevel
    {
        $levels = AllureLevel::forReferentiel($this->referentiel());

        return $levels->firstWhere('id', $this->levelId) ?? $levels->first();
    }

    public function saveVma(AlluresService $service): void
    {
        $value = $this->parsedVma();
        if ($value === null) {
            session()->flash('warn', 'Saisis ta VMA en km/h, par exemple 13,5.');

            return;
        }
        $this->store($service, $value, ReferenceValue::SOURCE_SAISIE, null);
    }

    public function useEstimate(AlluresService $service): void
    {
        $seconds = Calculateur::parseTemps($this->estTime);
        $est = $seconds !== null ? $service->estimate($this->referentiel(), $this->estDistance, $seconds, $this->level()) : null;
        if ($est === null || $est['improbable']) {
            session()->flash('warn', 'Estimation impossible : vérifie la distance et le temps.');

            return;
        }
        $this->store($service, $est['mid'], ReferenceValue::SOURCE_ESTIMATION, $this->estDistance);
    }

    private function store(AlluresService $service, float $value, string $source, ?string $distance): void
    {
        try {
            $row = $service->setReference($this->user(), $this->referentiel(), $value, $source, $distance);
        } catch (InvalidArgumentException) {
            [$min, $max] = $this->referentiel()->bounds();
            session()->flash('warn', 'VMA improbable : elle doit être comprise entre '.(int) $min.' et '.(int) $max.' km/h.');

            return;
        }
        $this->vma = Calculateur::formatVma($row->value);
        session()->flash('status', 'VMA enregistrée : '.$this->vma.' km/h.');
    }

    public function clearVma(AlluresService $service): void
    {
        $service->clearReference($this->user(), $this->referentiel());
        $this->vma = '';
        session()->flash('status', 'VMA effacée.');
    }

    public function render(AlluresService $service)
    {
        $referentiel = $this->referentiel();
        [$min, $max] = $referentiel->bounds();
        $vma = $this->parsedVma();
        $vmaOk = $vma !== null && $vma >= $min && $vma <= $max;
        $levels = AllureLevel::forReferentiel($referentiel);
        $level = $levels->firstWhere('id', $this->levelId) ?? $levels->first();

        $seconds = Calculateur::parseTemps($this->estTime);
        $estimate = $seconds !== null ? $service->estimate($referentiel, $this->estDistance, $seconds, $level) : null;

        return view('livewire.allures', [
            'referentiel' => $referentiel,
            'reference' => $this->user()->referenceValue($referentiel),
            'vmaValue' => $vmaOk ? $vma : null,
            'vmaInvalid' => trim($this->vma) !== '' && ! $vmaOk,
            'zones' => AllureZone::activeFor($referentiel),
            'levels' => $levels,
            'level' => $level,
            'estimate' => $estimate,
            'estTimeInvalid' => trim($this->estTime) !== '' && $seconds === null,
            'projection' => $vmaOk ? $service->project($referentiel, $vma, $level) : [],
        ]);
    }
}

<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesAdminGate;
use App\Models\AllureLevel;
use App\Support\Allures\Referentiel;
use App\Support\Logging\AuditLogger;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

// Table club des allures cibles (#114) : % de VMA tenable par niveau × distance, saisi par le
// coach sur l'instance de son club (ses coefficients ne vont jamais dans le dépôt). Vide, l'écran
// Allures applique le modèle de Riegel. Édition en bloc : la table entière est réécrite à
// l'enregistrement, l'ordre des lignes fait l'ordre des niveaux. Admin uniquement.
#[Layout('layouts.app')]
#[Title('Allures cibles')]
class AllureLevels extends Component
{
    use AuthorizesAdminGate;

    protected function adminGate(): ?string
    {
        return 'manage-catalogues';
    }

    /**
     * Lignes éditées : [['label' => …, 'targets' => [distance => ['min' => …, 'max' => …]]]].
     * Bornes en chaîne pour la saisie ; max vide = borne ouverte (« 95+ »).
     */
    public array $rows = [];

    private function referentiel(): Referentiel
    {
        return Referentiel::Course;
    }

    public function mount(): void
    {
        $this->rows = AllureLevel::forReferentiel($this->referentiel())->map(fn (AllureLevel $l) => [
            'label' => $l->label,
            'targets' => collect($this->referentiel()->distances())->mapWithKeys(function ($d, $key) use ($l) {
                $t = $l->target($key);

                return [$key => ['min' => $t ? $this->fmt($t[0]) : '', 'max' => $t && $t[1] !== null ? $this->fmt($t[1]) : '']];
            })->all(),
        ])->values()->all();
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    public function addRow(): void
    {
        $this->rows[] = [
            'label' => '',
            'targets' => collect($this->referentiel()->distances())->mapWithKeys(fn ($d, $key) => [$key => ['min' => '', 'max' => '']])->all(),
        ];
    }

    public function removeRow(int $i): void
    {
        unset($this->rows[$i]);
        $this->rows = array_values($this->rows);
    }

    public function moveUp(int $i): void
    {
        if ($i > 0 && isset($this->rows[$i])) {
            [$this->rows[$i - 1], $this->rows[$i]] = [$this->rows[$i], $this->rows[$i - 1]];
        }
    }

    protected function rules(): array
    {
        $rules = ['rows' => ['array', 'max:10'], 'rows.*.label' => ['required', 'string', 'max:60']];
        foreach (array_keys($this->referentiel()->distances()) as $key) {
            $rules["rows.*.targets.{$key}.min"] = ['required', 'numeric', 'min:30', 'max:120'];
            $rules["rows.*.targets.{$key}.max"] = ['nullable', 'numeric', 'min:30', 'max:120', "gte:rows.*.targets.{$key}.min"];
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'rows.*.label.required' => 'Chaque niveau a besoin d’un libellé.',
            'rows.*.targets.*.min.required' => 'Chaque distance a besoin d’un % minimum.',
            'rows.*.targets.*.max.gte' => 'Le % maximum doit être supérieur ou égal au minimum.',
        ];
    }

    public function save(): void
    {
        $this->validate();
        $referentiel = $this->referentiel();

        DB::transaction(function () use ($referentiel) {
            AllureLevel::where('referentiel', $referentiel->value)->delete();
            foreach (array_values($this->rows) as $i => $row) {
                AllureLevel::create([
                    'referentiel' => $referentiel->value,
                    'label' => trim($row['label']),
                    'sort_order' => $i,
                    'targets' => collect($row['targets'])->map(fn ($t) => [
                        (float) $t['min'],
                        ($t['max'] ?? '') === '' ? null : (float) $t['max'],
                    ])->all(),
                ]);
            }
            AuditLogger::record('allure_levels_modified', auth()->user(), [
                'target_type' => AllureLevel::class,
                'motif' => count($this->rows).' niveau(x)',
            ]);
        });

        session()->flash('status', $this->rows === []
            ? 'Table vidée : le modèle de Riegel s’applique.'
            : 'Table des allures cibles enregistrée.');
    }

    public function render()
    {
        return view('livewire.admin.allure-levels', [
            'distances' => $this->referentiel()->distances(),
        ]);
    }
}

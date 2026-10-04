<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesAdminGate;
use App\Models\AllureLevel;
use App\Support\Allures\Calculateur;
use App\Support\Allures\Referentiel;
use App\Support\Logging\AuditLogger;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

// Table club des allures cibles (#114) : % de VMA tenable par niveau × distance, saisi par le
// coach sur l'instance de son club (ses coefficients ne vont jamais dans le dépôt). Le modèle de
// Riegel y figure d'office comme un niveau calculé, en lecture seule, montré par l'exemple (ses %
// dépendent de la VMA) : déplaçable et désactivable, jamais supprimable. Au moins un niveau reste
// proposé aux membres. Édition en bloc : la table entière est réécrite à l'enregistrement, l'ordre
// des lignes fait l'ordre des niveaux (le premier actif est le choix par défaut). Admin uniquement.
#[Layout('layouts.app')]
#[Title('Allures cibles')]
class AllureLevels extends Component
{
    use AuthorizesAdminGate;

    protected function adminGate(): ?string
    {
        return 'manage-catalogues';
    }

    /** VMA (km/h) entre lesquelles la ligne Riegel affiche ses % en exemple. */
    public const RIEGEL_VMA_EXEMPLE = [10.0, 18.0];

    /**
     * Lignes éditées : [['label' => …, 'model' => 'riegel'|null, 'active' => bool,
     * 'targets' => [distance => ['min' => …, 'max' => …]]]].
     * Bornes en chaîne pour la saisie ; max vide = borne ouverte (« 95+ »). Riegel : bornes vides.
     */
    public array $rows = [];

    private function referentiel(): Referentiel
    {
        return Referentiel::Course;
    }

    public function mount(): void
    {
        $levels = AllureLevel::forReferentiel($this->referentiel());
        $this->rows = $levels->map(fn (AllureLevel $l) => [
            'label' => $l->isRiegel() ? AllureLevel::LABEL_RIEGEL : $l->label,
            'model' => $l->isRiegel() ? AllureLevel::MODEL_RIEGEL : null,
            'active' => $l->active,
            'targets' => collect($this->referentiel()->distances())->mapWithKeys(function ($d, $key) use ($l) {
                $t = $l->isRiegel() ? null : $l->target($key);

                return [$key => ['min' => $t ? $this->fmt($t[0]) : '', 'max' => $t && $t[1] !== null ? $this->fmt($t[1]) : '']];
            })->all(),
        ])->values()->all();
    }

    /** @return array{label: string, model: ?string, active: bool, targets: array<string, array{min: string, max: string}>} */
    private function blankRow(): array
    {
        return [
            'label' => '',
            'model' => null,
            'active' => true,
            'targets' => collect($this->referentiel()->distances())->mapWithKeys(fn ($d, $key) => [$key => ['min' => '', 'max' => '']])->all(),
        ];
    }

    private function isRiegelRow(int $i): bool
    {
        return ($this->rows[$i]['model'] ?? null) === AllureLevel::MODEL_RIEGEL;
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    public function addRow(): void
    {
        $this->rows[] = $this->blankRow();
    }

    public function toggleActive(int $i): void
    {
        if (isset($this->rows[$i])) {
            $this->rows[$i]['active'] = ! ($this->rows[$i]['active'] ?? true);
        }
    }

    public function removeRow(int $i): void
    {
        if ($this->isRiegelRow($i)) {
            return;
        }
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
        $rules = ['rows' => ['array', 'max:11'], 'rows.*.label' => ['required', 'string', 'max:60']];
        // Riegel n'a pas de bornes : seules les lignes saisies par le club sont contrôlées.
        foreach (array_keys($this->rows) as $i) {
            if ($this->isRiegelRow($i)) {
                continue;
            }
            foreach (array_keys($this->referentiel()->distances()) as $key) {
                $rules["rows.{$i}.targets.{$key}.min"] = ['required', 'numeric', 'min:30', 'max:120'];
                $rules["rows.{$i}.targets.{$key}.max"] = ['nullable', 'numeric', 'min:30', 'max:120', "gte:rows.{$i}.targets.{$key}.min"];
            }
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
        // Virgule décimale acceptée (« 85,5 ») : le contrôle numérique porte sur le point.
        foreach ($this->rows as $i => $row) {
            foreach ($row['targets'] ?? [] as $key => $t) {
                foreach (['min', 'max'] as $b) {
                    $this->rows[$i]['targets'][$key][$b] = str_replace(',', '.', trim((string) ($t[$b] ?? '')));
                }
            }
        }
        $this->validate();
        $referentiel = $this->referentiel();
        // La ligne Riegel est une donnée (migration, seed) : jamais supprimée ni recréée ici, seuls
        // son rang et son état « proposé » changent. Gardé côté serveur : les lignes viennent du
        // client, une ligne Riegel absente de l'envoi garde son état en base.
        $riegel = AllureLevel::where('referentiel', $referentiel->value)->where('model', AllureLevel::MODEL_RIEGEL)->first();
        $envoyees = collect($this->rows)->filter(fn ($r) => ($r['model'] ?? null) !== AllureLevel::MODEL_RIEGEL || $riegel !== null);
        $riegelEnvoye = $envoyees->contains(fn ($r) => ($r['model'] ?? null) === AllureLevel::MODEL_RIEGEL);
        if (! $envoyees->contains(fn ($r) => (bool) ($r['active'] ?? true)) && ! ($riegel?->active && ! $riegelEnvoye)) {
            session()->flash('warn', 'Au moins un niveau doit rester proposé aux membres.');

            return;
        }

        DB::transaction(function () use ($referentiel, $riegel) {
            AllureLevel::where('referentiel', $referentiel->value)->whereKeyNot($riegel->id ?? 0)->delete();
            foreach (array_values($this->rows) as $i => $row) {
                if (($row['model'] ?? null) === AllureLevel::MODEL_RIEGEL) {
                    $riegel?->update(['sort_order' => $i, 'active' => (bool) ($row['active'] ?? true)]);

                    continue;
                }
                AllureLevel::create([
                    'referentiel' => $referentiel->value,
                    'label' => trim($row['label']),
                    'sort_order' => $i,
                    'active' => (bool) ($row['active'] ?? true),
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

        session()->flash('status', 'Table des allures cibles enregistrée.');
    }

    public function render()
    {
        return view('livewire.admin.allure-levels', [
            'distances' => $this->referentiel()->distances(),
            'riegel' => $this->riegelExample(),
        ]);
    }

    /**
     * % de VMA tenus selon Riegel, par distance, pour les VMA d'exemple : [clé => [bas, haut]].
     *
     * @return array<string, array{float, float}>
     */
    private function riegelExample(): array
    {
        [$slow, $fast] = self::RIEGEL_VMA_EXEMPLE;

        return collect($this->referentiel()->distances())->map(fn ($d) => [
            Calculateur::pctRiegel(Calculateur::tempsRiegel($d[1], $slow)),
            Calculateur::pctRiegel(Calculateur::tempsRiegel($d[1], $fast)),
        ])->all();
    }
}

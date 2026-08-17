<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesAdminGate;
use App\Models\Category;
use App\Models\Qualification;
use App\Models\User;
use App\Services\MemberService;
use App\Support\AgeCategory;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

// « Ajouter un adhérent » (PRD §4.17.1) — porté de screen-adherent-create.jsx.
// Catégorie principale DÉRIVÉE de la date de naissance ; mineurs ouvrent le bloc parent garant
// (P1 sans compte / P2 compte + parent). Aperçu vivant à droite. Admin uniquement.
#[Layout('layouts.app')]
#[Title('Nouvel adhérent')]
class MemberCreate extends Component
{
    use AuthorizesAdminGate;

    protected function adminGate(): ?string
    {
        return 'manage-members';
    }

    public string $first_name = '';

    public string $last_name = '';

    public string $dob = '';

    public string $email = '';

    /** @var array<string,bool> */
    public array $roles = ['athlete' => true, 'coach' => false, 'admin' => false];

    /** Surclassements choisis (ids de catégorie). @var array<int,int> */
    public array $surclassements = [];

    /** Qualifications choisies (ids). @var array<int,int> */
    public array $qualifications = [];

    /** Phase de tutelle pour un mineur : P1 (géré par le parent) | P2 (compte + parent). */
    public string $phase = 'P1';

    public ?int $guardian_id = null;

    // Bascules d'ajout (UI proto : panneau « choisir une catégorie / qualif »).
    public bool $addingCat = false;

    public bool $addingQual = false;

    public function mount(): void {}

    public function getAgeProperty(): ?int
    {
        return $this->dob !== '' ? AgeCategory::seasonAge(Carbon::parse($this->dob)) : null;
    }

    public function getIsMinorProperty(): bool
    {
        return $this->age !== null && $this->age < 18;
    }

    /** En P1 (mineur sans compte) : pas d'email, accès géré par le parent. */
    public function getIsP1Property(): bool
    {
        return $this->isMinor && $this->phase === 'P1';
    }

    public function getPrimaryCategoryProperty(): ?Category
    {
        return $this->dob !== '' ? AgeCategory::derive(Carbon::parse($this->dob)) : null;
    }

    public function getReadyProperty(): bool
    {
        return trim($this->first_name) !== ''
            && trim($this->last_name) !== ''
            && $this->dob !== ''
            && ($this->isP1 || trim($this->email) !== '');
    }

    public function toggleRole(string $role): void
    {
        if (array_key_exists($role, $this->roles)) {
            $this->roles[$role] = ! $this->roles[$role];
        }
    }

    public function addSurclassement(int $id): void
    {
        if (! in_array($id, $this->surclassements, true)) {
            $this->surclassements[] = $id;
        }
        $this->addingCat = false;
    }

    public function removeSurclassement(int $id): void
    {
        $this->surclassements = array_values(array_filter($this->surclassements, fn ($c) => $c !== $id));
    }

    public function addQualification(int $id): void
    {
        if (! in_array($id, $this->qualifications, true)) {
            $this->qualifications[] = $id;
        }
        $this->addingQual = false;
    }

    public function removeQualification(int $id): void
    {
        $this->qualifications = array_values(array_filter($this->qualifications, fn ($q) => $q !== $id));
    }

    public function create(MemberService $service)
    {
        $this->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'dob' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            'email' => [$this->isP1 ? 'nullable' : 'required', 'email', 'max:255', 'unique:users,email'],
            'guardian_id' => [$this->isMinor ? 'nullable' : 'prohibited', 'nullable', 'exists:users,id,is_minor,0,anonymized_at,NULL'],
        ]);

        $member = $service->create([
            'first_name' => trim($this->first_name),
            'last_name' => trim($this->last_name),
            'dob' => $this->dob,
            'email' => $this->isP1 ? null : trim($this->email),
            'roles' => array_keys(array_filter($this->roles)),
            'surclassements' => $this->surclassements,
            'qualifications' => $this->qualifications,
            'guardian_id' => $this->isMinor ? $this->guardian_id : null,
        ], auth()->user());

        session()->flash('status', $member->fullName().' créé·e.');

        return $this->redirect(route('admin.members.show', $member), navigate: true);
    }

    public function render()
    {
        $primary = $this->primaryCategory;

        $availableCats = Category::query()->whereNull('archived_at')->orderBy('label')->get()
            ->reject(fn (Category $c) => $c->id === $primary?->id || in_array($c->id, $this->surclassements, true));

        $availableQuals = Qualification::query()->whereNull('archived_at')->orderBy('label')->get()
            ->reject(fn (Qualification $q) => in_array($q->id, $this->qualifications, true));

        $chosenCats = $primary !== null
            ? Category::query()->whereIn('id', $this->surclassements)->get()
            : collect();
        $chosenQuals = Qualification::query()->whereIn('id', $this->qualifications)->get();

        // Parents garants possibles : adultes existants (non mineurs, non anonymisés).
        $guardians = User::query()->whereNull('anonymized_at')->where('is_minor', false)
            ->orderBy('last_name')->orderBy('first_name')->get();

        return view('livewire.admin.member-create', [
            'primaryCat' => $primary,
            'availableCats' => $availableCats,
            'availableQuals' => $availableQuals,
            'chosenCats' => $chosenCats,
            'chosenQuals' => $chosenQuals,
            'guardians' => $guardians,
        ]);
    }
}

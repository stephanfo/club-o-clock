<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesAdminGate;
use App\Models\User;
use App\Policies\TrombiPolicy;
use App\Services\ConsentService;
use App\Services\MemberService;
use App\Services\TrombiService;
use App\Support\Trombi\BoardRole;
use App\Support\Trombi\ConsentType;
use App\Support\Trombi\TrombiCard;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

// Trombinoscope d'un adhérent, côté admin (#72, PRD §4.23.1, §4.17.1) : fonction au bureau, retrait
// d'un accord à la demande, enregistrement d'un accord recueilli hors de l'app (source obligatoire),
// modération de la photo, affichée pour en juger. L'admin ne dépose ni photo ni numéro, et ne voit
// pas une photo masquée.
// Imbriqué dans l'onglet « Trombinoscope » de la fiche adhérent.
class MemberTrombi extends Component
{
    use AuthorizesAdminGate;

    protected function adminGate(): ?string
    {
        return 'manage-members';
    }

    #[Locked]
    public int $memberId;

    public string $boardRole = '';

    /** @var list<string> types cochés pour l'enregistrement */
    public array $recordTypes = [];

    public string $recordSource = '';

    public bool $confirmingRecord = false;

    /** Accusé de réception du dialog d'enregistrement — jamais pré-coché. */
    public bool $recordCheck = false;

    public bool $confirmingPhotoRemoval = false;

    public function mount(int $memberId): void
    {
        $this->memberId = $memberId;
        $this->boardRole = (string) $this->member()->getRawOriginal('board_role');
    }

    private function member(): User
    {
        return User::query()->with(['unrevokedConsents.grantor', 'guardian'])->findOrFail($this->memberId);
    }

    public function saveBoardRole(MemberService $members): void
    {
        $role = $this->boardRole === '' ? null : BoardRole::tryFrom($this->boardRole);
        if ($this->boardRole !== '' && $role === null) {
            return;
        }
        $this->run(fn () => $members->setBoardRole($this->member(), $role, auth()->user()), 'Fonction au bureau enregistrée.');
    }

    public function revokeConsent(string $type, ConsentService $consents): void
    {
        $type = ConsentType::tryFrom($type);
        if ($type === null) {
            return;
        }
        $this->run(fn () => $consents->revokeByAdmin($this->member(), $type, auth()->user()), 'Accord retiré : la donnée est masquée aux adhérents.');
    }

    public function openRecord(): void
    {
        $this->validate([
            'recordTypes' => ['required', 'array', 'min:1'],
            'recordTypes.*' => ['string'],
            'recordSource' => ['required', 'string', 'max:120'],
        ], [
            'recordTypes.required' => 'Coche au moins un accord.',
            'recordTypes.min' => 'Coche au moins un accord.',
            'recordSource.required' => 'Indique d\'où vient l\'accord (ex. bulletin d\'adhésion 2027).',
            'recordSource.max' => '120 caractères au plus.',
        ]);
        $this->recordCheck = false;
        $this->confirmingRecord = true;
    }

    public function cancelRecord(): void
    {
        $this->confirmingRecord = false;
        $this->recordCheck = false;
    }

    public function recordConsents(ConsentService $consents): void
    {
        // Garde serveur : l'enregistrement prévient l'adhérent (ou son parent), sans retour possible.
        if (! $this->recordCheck) {
            return;
        }
        $types = array_values(array_filter(array_map(fn ($v) => ConsentType::tryFrom((string) $v), $this->recordTypes)));

        $this->run(function () use ($consents, $types) {
            $done = $consents->recordByAdmin($this->member(), $types, auth()->user(), $this->recordSource);
            $this->recordTypes = [];
            $this->recordSource = '';
            session()->flash('status', $done === [] ? 'Ces accords étaient déjà actifs.' : count($done).' accord(s) enregistré(s), adhérent prévenu.');
        }, null);
        $this->cancelRecord();
    }

    public function removePhoto(TrombiService $service): void
    {
        $this->run(fn () => $service->removePhotoByAdmin($this->member(), auth()->user()), 'Photo supprimée.');
        $this->confirmingPhotoRemoval = false;
    }

    private function run(callable $action, ?string $success): void
    {
        try {
            $action();
            if ($success !== null) {
                session()->flash('status', $success);
            }
        } catch (RuntimeException $e) {
            session()->flash('warn', $e->getMessage());
        }
    }

    public function render(ConsentService $consents, TrombiPolicy $policy)
    {
        $member = $this->member();
        $types = $consents->applicableTypes($member);
        $active = collect($member->unrevokedConsents)->filter->isActive()->keyBy(fn ($c) => $c->type->value);

        return view('livewire.admin.member-trombi', [
            'member' => $member,
            'types' => $types,
            'active' => $active,
            // Proposés à l'enregistrement : ceux qui ne sont pas actifs, et le téléphone seulement
            // s'il existe un numéro (l'admin n'en saisit jamais).
            'recordable' => array_values(array_filter($types, fn (ConsentType $t) => ! $active->has($t->value)
                && ($t !== ConsentType::Phone || $member->phone !== null))),
            'recipient' => $member->isLegallyMinor() ? $member->guardian : $member,
            // Ce que voient les adhérents, donc ce qui peut demander une modération : rien si masquée.
            'photo' => TrombiCard::photoUrl(auth()->user(), $member, 512, $policy),
        ]);
    }
}

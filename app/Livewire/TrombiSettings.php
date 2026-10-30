<?php

namespace App\Livewire;

use App\Models\User;
use App\Policies\TrombiPolicy;
use App\Services\ConsentService;
use App\Services\TrombiService;
use App\Support\Trombi\ConsentType;
use App\Support\Trombi\PhoneNumber;
use App\Support\Trombi\TrombiCard;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

// Réglages du trombinoscope d'un adhérent (#72, PRD §4.23.6) : aperçu de sa carte telle que les
// autres la voient, photo, téléphone, six accords et l'interrupteur « Tout ». Imbriqué dans
// l'onglet du profil (sujet = soi) et dans la page d'un enfant (sujet = l'enfant, acteur = son
// parent garant). Chaque geste repasse par les services, qui jugent les droits côté serveur.
class TrombiSettings extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $subjectId;

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    /**
     * Cadrage choisi dans le dialog, posé par le navigateur AVANT l'envoi du fichier (même requête
     * que la fin de l'envoi). Vide = recadrage automatique. Cf. TrombiService::storePhoto.
     *
     * @var array<string, mixed>
     */
    public array $crop = [];

    public string $phone = '';

    public function mount(int $subjectId): void
    {
        $subject = User::findOrFail($subjectId);
        $viewer = auth()->user();
        abort_unless($viewer->id === $subject->id || ($subject->guardian_id !== null && $viewer->id === $subject->guardian_id), 403);

        $this->subjectId = $subjectId;
        $this->phone = $subject->phone ? PhoneNumber::format($subject->phone) : '';
    }

    private function subject(): User
    {
        return User::query()->with(TrombiCard::EAGER)->findOrFail($this->subjectId);
    }

    /** Dépôt dès le choix du fichier : pas de bouton « Enregistrer » à oublier. */
    public function updatedPhoto(TrombiService $service): void
    {
        try {
            // Dans le try : un envoi refusé (trop lourd, mauvais format) ne doit pas laisser
            // l'original, EXIF compris, dans le dossier temporaire.
            $this->validate([
                'photo' => ['image', 'mimes:jpeg,png,webp', 'max:5120'],
                'crop.x' => ['required_with:crop', 'numeric', 'between:0,1'],
                'crop.y' => ['required_with:crop', 'numeric', 'between:0,1'],
                'crop.s' => ['required_with:crop', 'numeric', 'between:0.05,1'],
                'crop.r' => ['required_with:crop', 'integer', 'in:0,90,180,270'],
            ], [
                'photo.image' => 'Choisis une image.',
                'photo.mimes' => 'Formats acceptés : JPEG, PNG ou WebP.',
                'photo.max' => 'La photo dépasse 5 Mo.',
                'crop.*' => 'Cadrage invalide : choisis la photo à nouveau.',
            ]);
            $crop = $this->crop === [] ? null : [
                'x' => (float) $this->crop['x'], 'y' => (float) $this->crop['y'],
                's' => (float) $this->crop['s'], 'r' => (int) $this->crop['r'],
            ];
            $service->storePhoto($this->subject(), $this->photo->getRealPath(), auth()->user(), $crop);
            session()->flash('status', 'Photo enregistrée.');
        } catch (RuntimeException $e) {
            session()->flash('warn', $e->getMessage());
        } finally {
            // L'original n'est jamais conservé, pas même dans le dossier temporaire des envois.
            $this->photo?->delete();
            $this->photo = null;
            $this->crop = [];
        }
    }

    public function deletePhoto(TrombiService $service): void
    {
        $this->run(fn () => $service->deletePhoto($this->subject(), auth()->user()), 'Photo supprimée.');
    }

    public function savePhone(TrombiService $service): void
    {
        $this->run(function () use ($service) {
            $subject = $this->subject();
            $service->setPhone($subject, $this->phone, auth()->user());
            $this->phone = $subject->phone ? PhoneNumber::format($subject->phone) : '';
        }, trim($this->phone) === '' ? 'Numéro supprimé.' : 'Numéro enregistré.');
    }

    public function deletePhone(TrombiService $service): void
    {
        $this->run(function () use ($service) {
            $service->deletePhone($this->subject(), auth()->user());
            $this->phone = '';
        }, 'Numéro supprimé.');
    }

    public function toggleConsent(string $type, ConsentService $consents): void
    {
        $type = ConsentType::tryFrom($type);
        if ($type === null) {
            return;
        }
        $subject = $this->subject();
        $this->run(
            fn () => $subject->hasConsent($type)
                ? $consents->revoke($subject, $type, auth()->user())
                : $consents->grant($subject, $type, auth()->user()),
            null
        );
    }

    /** « Tout » : depuis l'état mixte, un geste active tout (§4.23.1). */
    public function toggleAll(ConsentService $consents): void
    {
        $subject = $this->subject();
        // Qui ne peut pas accorder (l'ado) ne fait que retirer : « Tout » éteint.
        $on = $consents->canGrant(auth()->user(), $subject) && $this->state($subject, $consents) !== 'on';
        $this->run(fn () => $consents->setAll($subject, $on, auth()->user()), null);
    }

    /** on | off | mixed, sur les accords que « Tout » peut allumer. */
    private function state(User $subject, ConsentService $consents): string
    {
        $types = $consents->grantableTypes($subject);
        $active = array_filter($types, fn (ConsentType $t) => $subject->hasConsent($t));

        return match (true) {
            $types !== [] && count($active) === count($types) => 'on',
            $active === [] && ! collect(ConsentType::cases())->contains(fn ($t) => $subject->hasConsent($t)) => 'off',
            default => 'mixed',
        };
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

    public function render(ConsentService $consents, TrombiService $service, TrombiPolicy $policy)
    {
        $subject = $this->subject();
        $viewer = auth()->user();
        $minor = $subject->isLegallyMinor();

        return view('livewire.trombi-settings', [
            'subject' => $subject,
            'self' => $viewer->id === $subject->id,
            'minor' => $minor,
            'preview' => TrombiCard::asSeenByMembers($subject),
            'ownPhoto' => TrombiCard::photoUrl($viewer, $subject, 192, $policy),
            'types' => $consents->applicableTypes($subject),
            'canGrant' => $consents->canGrant($viewer, $subject),
            'canEdit' => $service->canEdit($viewer, $subject),
            'allState' => $this->state($subject, $consents),
        ]);
    }
}

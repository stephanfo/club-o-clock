<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesAdminGate;
use App\Models\InformationPage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

// Écran admin « Pages d'information ». Liste actives + archivées, actions
// épingler / archiver / restaurer / supprimer. Admin uniquement (Gate manage-information-pages).
#[Layout('layouts.app')]
#[Title('Pages d\'info')]
class InformationPageList extends Component
{
    use AuthorizesAdminGate;

    protected function adminGate(): ?string
    {
        return 'manage-information-pages';
    }

    public bool $showArchived = false;

    /** Id de la page en attente de suppression définitive (pilote la modale danger). Null = fermée. */
    public ?int $confirmingDeleteId = null;

    public function mount(): void {}

    /** Remonte la page dans l'ordre d'affichage (échange de position avec la voisine du dessus). */
    public function moveUp(int $id): void
    {
        $this->swapWithNeighbor($id, -1);
    }

    /** Descend la page dans l'ordre d'affichage. */
    public function moveDown(int $id): void
    {
        $this->swapWithNeighbor($id, +1);
    }

    /**
     * Échange la position de la page avec sa voisine active (au-dessus si $dir=-1, en dessous si +1),
     * dans l'ordre canonique (pinned d'abord, puis position). Bornée : sans voisine, ne fait rien.
     */
    private function swapWithNeighbor(int $id, int $dir): void
    {
        $active = InformationPage::query()->active()->ordered()->get()->values();
        $index = $active->search(fn ($p) => $p->id === $id);
        if ($index === false) {
            return;
        }

        $neighbor = $active->get($index + $dir);
        if ($neighbor === null) {
            return;
        }

        $current = $active->get($index);
        // Réécrit les positions de tout le bloc pour repartir d'un ordre dense et cohérent,
        // même si les positions étaient égales (ex. données seedées à 0) ou en désordre.
        $active->splice($index, 1);
        $active->splice($index + $dir, 0, [$current]);
        foreach ($active as $pos => $page) {
            if ($page->position !== $pos) {
                $page->update(['position' => $pos]);
            }
        }
    }

    /** Bascule le flag « épinglé en bannière d'accueil ». */
    public function togglePin(int $id): void
    {
        $page = InformationPage::findOrFail($id);
        $page->update(['pinned' => ! $page->pinned]);
        session()->flash('status', $page->pinned ? 'Page épinglée en bannière.' : 'Page désépinglée.');
    }

    /** Archivage soft : retire la page de la consultation, désépingle. */
    public function archive(int $id): void
    {
        InformationPage::findOrFail($id)->update([
            'archived_at' => now(),
            'archived_by' => auth()->id(),
            'pinned' => false,
        ]);
        session()->flash('status', 'Page archivée.');
    }

    public function restore(int $id): void
    {
        InformationPage::findOrFail($id)->update([
            'archived_at' => null,
            'archived_by' => null,
        ]);
        session()->flash('status', 'Page restaurée.');
    }

    /** Ouvre la confirmation de suppression définitive (modale danger, cf. member-show). */
    public function confirmDelete(int $id): void
    {
        $this->confirmingDeleteId = $id;
    }

    /** Ferme la confirmation sans supprimer. */
    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    /** Suppression dure (irréversible), depuis la liste des archivées. */
    public function delete(int $id): void
    {
        InformationPage::findOrFail($id)->delete();
        $this->confirmingDeleteId = null;
        session()->flash('status', 'Page supprimée.');
    }

    public function render()
    {
        return view('livewire.admin.information-page-list', [
            // Actives dans l'ordre d'affichage réel (pinned puis position) : les flèches agissent dessus.
            'active' => InformationPage::query()->active()->ordered()->get(),
            'archived' => InformationPage::query()->whereNotNull('archived_at')->latest()->get(),
            // Page ciblée par la confirmation de suppression définitive (titre affiché dans la modale).
            'deleting' => $this->confirmingDeleteId ? InformationPage::find($this->confirmingDeleteId) : null,
        ]);
    }
}

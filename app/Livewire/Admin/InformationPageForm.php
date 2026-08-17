<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesAdminGate;
use App\Models\InformationPage;
use App\Support\Markup;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

// Création / édition d'une page d'information (note club). Admin uniquement
// (Gate manage-information-pages). Le contenu WYSIWYG est sanitisé serveur (Markup::clean).
#[Layout('layouts.app')]
#[Title('Page d\'info — édition')]
class InformationPageForm extends Component
{
    use AuthorizesAdminGate;

    protected function adminGate(): ?string
    {
        return 'manage-information-pages';
    }

    public ?InformationPage $page = null;

    public string $title = '';

    public ?string $content_markdown = null;

    public string $visibility = 'all';

    public bool $pinned = false;

    public function mount(?InformationPage $page = null): void
    {
        if ($page && $page->exists) {
            $this->page = $page;
            $this->title = $page->title;
            $this->content_markdown = $page->content_markdown;
            $this->visibility = $page->visibility;
            $this->pinned = (bool) $page->pinned;
        }
    }

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'content_markdown' => ['nullable', 'string'],
            'visibility' => ['required', Rule::in(InformationPage::VISIBILITIES)],
            'pinned' => ['boolean'],
        ];
    }

    public function save()
    {
        $data = $this->validate();

        $attributes = [
            'title' => trim($data['title']),
            'content_markdown' => Markup::clean($data['content_markdown']),
            'visibility' => $data['visibility'],
            'pinned' => $data['pinned'],
        ];

        if ($this->page && $this->page->exists) {
            $this->page->update($attributes);
            session()->flash('status', 'Page mise à jour.');
        } else {
            InformationPage::create($attributes + ['created_by' => auth()->id()]);
            session()->flash('status', 'Page créée.');
        }

        return $this->redirect(route('admin.infos'), navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.information-page-form', [
            'isEdit' => $this->page && $this->page->exists,
        ]);
    }
}

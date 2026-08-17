<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Support\SubjectContext;
use Illuminate\Support\Collection;

/** Branche le sélecteur de sujet parent (SubjectSwitcher, §4.2) sur un composant Livewire. */
trait WithSubject
{
    /** Bascule le sujet consulté (pilote Accueil/Planning — proto shell.jsx). */
    public function setSubject(?int $subjectId): void
    {
        SubjectContext::set(auth()->user(), $subjectId);
    }

    protected function subject(): User
    {
        return SubjectContext::current(auth()->user());
    }

    /** @return Collection<int, User> */
    protected function subjectWards(): Collection
    {
        return SubjectContext::wards(auth()->user());
    }

    /** Variables communes aux vues portant le switcher (subj-*). */
    protected function subjectViewData(): array
    {
        $subject = $this->subject();

        return [
            'subjectUser' => $subject,
            'subjectWards' => $this->subjectWards(),
            'subjectFirstName' => SubjectContext::firstNameIfChild(auth()->user(), $subject),
        ];
    }
}

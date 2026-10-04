{{-- Onglet Identité — porté de screen-profil.jsx PrIdentite. Nom/prénom éditables par l'athlète ;
     le reste (email, naissance, catégorie, rôles, qualifications) est géré par le bureau (lecture). --}}
<div style="display:flex;flex-direction:column;gap:16px">
    <div class="flex ac g14" style="gap:14px">
        <x-avatar :name="$user->fullName()" size="xl" tint="tint-run" />
        <div>
            <div class="dsp-7" style="font-size:24px">{{ $user->fullName() }}</div>
            @if ($memberSince ?? false)
                <div class="meta">Membre depuis {{ $memberSince }}</div>
            @endif
        </div>
    </div>

    {{-- Modifiable par l'athlète --}}
    <div>
        <div class="sect-head"><span class="sect-title">Modifiable</span></div>
        <div style="display:flex;flex-direction:column;gap:10px">
            <div>
                <label class="field-label" for="pf-first">Prénom</label>
                <input id="pf-first" type="text" class="input" wire:model.blur="first_name">
                @error('first_name') <div class="meta" style="color:var(--danger);font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
            </div>
            <div>
                <label class="field-label" for="pf-last">Nom</label>
                <input id="pf-last" type="text" class="input" wire:model.blur="last_name">
                @error('last_name') <div class="meta" style="color:var(--danger);font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
            </div>
            <button type="button" wire:click="saveIdentity" class="btn btn-primary btn-sm" style="align-self:flex-start"
                wire:loading.attr="disabled">Enregistrer</button>
        </div>
    </div>

    {{-- Géré par le bureau (lecture seule) --}}
    @php
        $cat = $user->primaryCategory();
        $quals = $user->qualifications->pluck('label')->all();
        $managed = [
            ['Email', $user->email ?? '—'],
            ['Date de naissance', $user->dob?->locale('fr')->isoFormat('D MMMM YYYY') ?? '—'],
            ['Catégorie', $cat?->label ?? '—'],
            ['Rôles', implode(', ', $roles ?? []) ?: '—'],
            ['Qualifications', $quals ? implode(', ', $quals) : '—'],
        ];
    @endphp
    <div>
        <div class="sect-head"><span class="sect-title">Géré par le bureau</span></div>
        <div class="card" style="overflow:hidden">
            @foreach ($managed as $i => [$label, $value])
                <div class="flex ac jb" style="padding:12px 14px;{{ $i < count($managed) - 1 ? 'border-bottom:1px solid var(--divider)' : '' }}">
                    <div>
                        <div class="eyebrow">{{ $label }}</div>
                        <div style="font-size:14px;margin-top:2px">{{ $value }}</div>
                    </div>
                    <span class="meta" style="font-size:12px">Contacter le bureau</span>
                </div>
            @endforeach
        </div>
    </div>

    <x-banner kind="info">L'édition côté athlète est limitée. Email, date de naissance, catégorie et rôles sont gérés par les admins du club.</x-banner>
</div>

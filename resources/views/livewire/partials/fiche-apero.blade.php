{{-- Section apéro (§4.14.5 affordance 2) — porté de screen-fiche.jsx <FApero>.
     Partagé entre l'onglet « Apéro » mobile et la colonne droite desktop.
     Reçoit : $session, $aperoPayers (flags actifs), $nameLabels, $iAmAperoPayer, $canFlagApero. --}}
<div style="display:flex;flex-direction:column;gap:14px">
    @if ($aperoPayers->isNotEmpty())
        <div class="card card-pad">
            <div class="flex ac g12">
                <span class="apero-disc"><x-chope :size="26" /></span>
                <div>
                    <div class="dsp-7" style="font-size:20px">Apéro offert par</div>
                    <div class="meta">
                        {{ $aperoPayers->count() }} personne{{ $aperoPayers->count() > 1 ? 's' : '' }} pour cette séance
                    </div>
                </div>
            </div>

            <div style="margin-top:14px;display:flex;flex-direction:column;gap:8px">
                @foreach ($aperoPayers as $flag)
                    @php $label = $nameLabels[$flag->user_id] ?? 'Membre'; @endphp
                    <div class="flex ac g10" style="padding:10px;background:var(--bg-alt);border-radius:var(--radius-md)">
                        <x-avatar :name="$label" size="sm" />
                        <span class="f1" style="min-width:0">
                            <span style="font-weight:700;font-size:14px">{{ $label }}</span>
                            @if ($flag->motif)
                                <span class="meta" title="{{ $flag->motif }}" style="font-size:12px;margin-left:6px">· {{ \Illuminate\Support\Str::limit($flag->motif, 60) }}</span>
                            @endif
                        </span>
                        {{-- $canUnflagApero : le retrait est figé au début de la séance (§4.14.3). --}}
                        @can('moderateApero', $session)
                            @unless ($flag->user_id === auth()->id() || ! ($canUnflagApero ?? false))
                                <button wire:click="unflagApero({{ $flag->user_id }})" class="iconbtn" title="Retirer ce flag" aria-label="Retirer ce flag">
                                    <x-icon name="x" :size="14" />
                                </button>
                            @endunless
                        @endcan
                        <x-chope :size="15" style="color:var(--apero)" />
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($iAmAperoPayer && ($canUnflagApero ?? false))
        <button wire:click="unflagApero({{ auth()->id() }})" wire:loading.attr="disabled" wire:target="unflagApero" class="btn btn-ghost btn-block">Je ne l'offre plus</button>
    @elseif ($canFlagApero)
        <div style="display:flex;flex-direction:column;gap:10px" x-data="{ n: 0 }">
            <div>
                <label class="field-label flex ac jb">
                    <span>Motif (optionnel)</span>
                    <span class="meta" style="font-size:11px"><span x-text="n">0</span>/140</span>
                </label>
                <input class="input" maxlength="140" wire:model.blur="aperoMotif" x-on:input="n = $event.target.value.length"
                       placeholder="ex. mon anniversaire, podium dimanche…">
            </div>
            <button wire:click="flagApero" wire:loading.attr="disabled" wire:target="flagApero" class="btn btn-pink btn-block"><x-chope :size="16" /> J'offre l'apéro aussi</button>
        </div>
    @endif

    <x-banner kind="info">Plusieurs personnes peuvent offrir l'apéro ; le motif s'affiche en infobulle. <b>Aucune notification</b> n'est envoyée — diffusion par le planning et le home.</x-banner>
</div>

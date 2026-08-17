{{-- Bloc liste d'inscrits / waitlist — porté du WlBlock de screen-fiche.jsx. --}}
<div class="card card-pad">
    <div class="flex ac jb" style="margin-bottom:10px">
        <div>
            <div class="ct" style="font-weight:700">{{ $title }}</div>
            <div class="meta" style="font-size:var(--text-xs)">{{ $sub }}</div>
        </div>
        <span class="chip chip-sm chip-line">{{ $list->count() }}</span>
    </div>
    @if ($list->isEmpty())
        <div class="meta">Personne pour le moment.</div>
    @else
        <ol class="reg-list">
            @foreach ($list as $i => $reg)
                @php($label = ($nameLabels[$reg->user_id] ?? null) ?: trim(($reg->user?->first_name ?? '?').' '.($reg->user?->last_name ?? '')))
                <li class="reg-item">
                    <span class="reg-rank">{{ $i + 1 }}</span>
                    <x-avatar :name="$label" tint="tint-run" />
                    <span class="reg-name">{{ $label }}</span>
                    {{-- Badge override : visible coachs/admins seulement (§4.10.5). --}}
                    @if (($isStaff ?? false) && $reg->override_by)
                        <span class="chip chip-sm chip-ink" @if ($reg->override_reason) title="{{ $reg->override_reason }}" @endif>override</span>
                    @endif
                    {{-- Retrait d'un athlète par le bureau (§4.9.7) — inscrits + waitlist. --}}
                    @if (($isStaff ?? false) && ! empty($removeMethod ?? null))
                        <button type="button" class="iconbtn" style="margin-left:auto"
                                wire:click="{{ $removeMethod }}({{ $reg->user_id }})"
                                wire:confirm="Retirer {{ $label }} de la séance ?" title="Retirer" aria-label="Retirer {{ $label }}">
                            <x-icon name="trash" :size="16" />
                        </button>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</div>

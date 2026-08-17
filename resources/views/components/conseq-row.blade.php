{{-- Ligne de conséquence (icône + label + texte) — porté de modals.jsx <ConseqRow>.
     tone : null (neutre) | warn | danger. --}}
@props(['icon', 'label', 'tone' => null])
@php
    $bg = $tone === 'danger' ? 'var(--danger-bg)' : ($tone === 'warn' ? 'var(--warning-bg)' : 'var(--slate-100)');
    $fg = $tone === 'danger' ? 'var(--danger)' : ($tone === 'warn' ? 'var(--warning-text)' : 'var(--fg-soft)');
@endphp
<div class="flex g10" style="align-items:flex-start">
    <span style="width:30px;height:30px;border-radius:var(--radius-md);background:{{ $bg }};color:{{ $fg }};display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto">
        <x-icon :name="$icon" :size="16" />
    </span>
    <div class="f1" style="min-width:0">
        <div class="eyebrow" style="font-size:10px">{{ $label }}</div>
        <div style="font-size:13.5px;margin-top:2px;line-height:1.5">{{ $slot }}</div>
    </div>
</div>

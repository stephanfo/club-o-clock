{{-- Une carte alerte avec son bouton de retrait (#79). Reçoit $alert et $shell (m|d, préfixe des clés).
     Le × est un <button> frère du lien, jamais dedans : pas de wire:click empilé sur wire:navigate. --}}
<div class="card card-pad flex ac g12" wire:key="alerte-{{ $shell }}-{{ implode('-', $alert['ids']) }}">
    @if ($alert['sessionId'])
        <a href="{{ route('sessions.show', $alert['sessionId']) }}" wire:navigate
           class="flex ac g12 f1" style="min-width:0;text-decoration:none;color:inherit;border-bottom:0">
            @include('livewire.partials.alert-card', ['alert' => $alert])
        </a>
    @else
        <div class="flex ac g12 f1" style="min-width:0">
            @include('livewire.partials.alert-card', ['alert' => $alert])
        </div>
    @endif
    <button type="button" class="iconbtn" style="flex:0 0 auto;align-self:flex-start;margin:-8px -8px 0 0"
            wire:click="dismiss(@js($alert['ids']))" wire:loading.attr="disabled" wire:target="dismiss"
            title="Retirer l'alerte" aria-label="Retirer l'alerte">
        <x-icon name="x" :size="16" />
    </button>
</div>

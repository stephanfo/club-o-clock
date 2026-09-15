{{-- Déblocage du quota (mécanisme C §4.10.4, #66) — geste coach, inclus sur mobile et desktop.
     Visible dès qu'une séance future et non annulée porte un tag, file quota vide comprise : le
     coach ouvre la séance la veille, avant que quiconque n'attende. Refermer est un geste anodin
     et réversible (les promu·e·s restent inscrit·e·s) → simple wire:confirm. Débloquer notifie les
     promu·e·s → dialog avec accusé de réception (cf. session-show).
     Clés DISTINCTES sur les deux boutons : sans elles, le morphing réutilise le même <button> en
     changeant ses attributs, et le gestionnaire wire:confirm posé à son initialisation SURVIT au
     retrait de l'attribut — « Débloquer » demandait alors la confirmation de « Refermer ».
     $quotaKey préfixe par inclusion (deux en mobile, une en desktop). --}}
<div style="display:flex;flex-direction:column;gap:var(--space-2)">
    @if ($session->isQuotaReleased())
        <button type="button" wire:key="{{ $quotaKey }}-quota-refermer" wire:click="closeQuota" wire:loading.attr="disabled" wire:target="closeQuota"
                wire:confirm="Refermer le quota ? Les athlètes déjà promu·e·s restent inscrit·e·s ; les prochaines inscriptions retrouvent la règle du quota."
                class="btn btn-ghost btn-block">
            <x-icon name="lock" :size="16" /> Refermer le quota
        </button>
        <div class="meta" style="font-size:var(--text-xs)">Quota débloqué jusqu'à la séance : les athlètes hors quota s'inscrivent tant qu'il reste des places.</div>
    @else
        <button type="button" wire:key="{{ $quotaKey }}-quota-debloquer" wire:click="openReleaseConfirm" wire:loading.attr="disabled" wire:target="openReleaseConfirm"
                class="btn btn-primary btn-block">
            <x-icon name="unlock" :size="16" /> Débloquer le quota
        </button>
        <div class="meta" style="font-size:var(--text-xs)">Jusqu'à la séance, le quota #{{ $session->quotaTag?->code }} ne bloquera plus les inscriptions.</div>
    @endif
</div>

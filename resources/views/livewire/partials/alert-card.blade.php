{{-- Contenu d'une carte alerte (partagé mobile/desktop). Reçoit $alert. --}}
<span class="disc-badge" style="background:{{ $alert['tintBg'] }};color:{{ $alert['tintFg'] }};flex:0 0 auto">
    <x-icon :name="$alert['icon']" :size="17" />
</span>
<div class="f1" style="min-width:0">
    <div style="font-weight:700;font-size:14px">{{ $alert['title'] }}</div>
    @if ($alert['sub'])
        <div class="meta" style="font-size:13px;margin-top:2px">{{ $alert['sub'] }}</div>
    @endif
</div>
<span class="meta" style="font-size:12px;align-self:flex-start;white-space:nowrap">{{ $alert['when'] }}</span>

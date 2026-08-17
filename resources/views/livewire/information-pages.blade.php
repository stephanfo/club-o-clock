{{-- Consultation des pages d'information (notes club). Liste filtrée par visibilité
     (scopeVisibleTo). Contenu court déplié en carte ; épinglées en tête. --}}
<div class="form-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />
    {{-- ─── Topbar verte fixe (mobile) — la page n'avait qu'un dk-topbar pensé desktop. --}}
    <x-topbar title="Infos du club" :back="route('home')" back-label="Retour accueil" />
    {{-- ─── Topbar desktop (dk-topbar) — masquée sur mobile au profit de la topbar verte. --}}
    <div class="dk-topbar">
        <div class="f1">
            <div class="dsp" style="font-size:24px">Infos du club</div>
            <div class="meta">Bons plans partenaires, codes et informations générales.</div>
        </div>
    </div>

    <div class="dk-body">
        <div style="max-width:760px;margin:0 auto;display:flex;flex-direction:column;gap:14px">
            @forelse ($pages as $page)
                <div id="page-{{ $page->id }}" class="card card-pad">
                    <div class="flex ac g6 wrap" style="margin-bottom:8px">
                        @if ($page->pinned)
                            <span class="chip chip-sm"><x-icon name="star" :size="12" /> Épinglée</span>
                        @endif
                        <span class="sect-title f1">{{ $page->title }}</span>
                    </div>
                    @if ($page->content_markdown)
                        <div class="db-prose">{!! \App\Support\Markup::render($page->content_markdown) !!}</div>
                    @else
                        <div class="meta">Aucun détail.</div>
                    @endif
                </div>
            @empty
                <div class="card card-pad meta" style="text-align:center;border-style:dashed">
                    Aucune information pour le moment.
                </div>
            @endforelse
        </div>
    </div>
</div>

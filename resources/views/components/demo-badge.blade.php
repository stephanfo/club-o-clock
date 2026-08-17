{{-- Rappel permanent « instance de démonstration » (plan open source OS7).

     Deux emplacements, un seul visible à la fois (bascule CSS à 768px, cf. .demo-badge / .demo-badge-bar
     dans app.css) :
     - `bar` : rendu PAR <x-topbar>, dans la barre verte fixe elle-même. C'est la variante MOBILE.
       Poser la pastille dans le chrome plutôt qu'au-dessus est la seule façon de garantir qu'elle
       ne recouvre rien : en `fixed` sous la topbar, elle tombait sur la première ligne de contenu
       (chevron « Suivant » du planning, carte d'identité du profil).
     - défaut (flottant) : rendu par le layout, coin bas-droit. C'est la variante DESKTOP, où le
       coin est libre et où la topbar verte n'existe pas (la sidebar tient le chrome).

     Pastille et non bande pleine largeur : plusieurs écrans calculent leur réserve de défilement au
     pixel sur --topbar-total / --botnav-top. Une bande obligerait à reprendre chacun de ces calculs.

     Le texte est raccourci à « Démo » dans la barre : la largeur y est disputée (titre + actions +
     cloche). La mention du reset nocturne reste sur desktop et sur l'écran de connexion. --}}
@props(['mode' => 'float'])
@if (App\Support\DemoMode::enabled())
    @if ($mode === 'bar')
        <div class="demo-badge-bar" role="status" title="Instance de démonstration · remise à zéro chaque nuit">
            <x-icon name="alert-triangle" :size="13" />
            <span>Démo</span>
        </div>
    @else
        <div class="demo-badge" role="status">
            <x-icon name="alert-triangle" :size="14" />
            <span><b>Démo</b> · remise à zéro chaque nuit</span>
        </div>
    @endif
@endif

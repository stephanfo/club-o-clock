{{-- Comptes de l'instance de démonstration (plan open source OS7), affichés seulement si
     DEMO_MODE est actif. Un visiteur doit pouvoir entrer sans lire la doc : c'est la
     vitrine du projet, pas un club réel.

     Volontairement un extrait, pas la liste complète : cinq profils suffisent à montrer les
     rôles cumulables et la tutelle parentale — le reste vit dans doc/COMPTES_DEMO.md.
     Les emails sont cliquables : ils remplissent le champ du formulaire mot de passe, pour
     éviter la recopie à la main sur un téléphone.

     DEUX comptes parents, et non un seul, parce qu'ils démontrent des choses différentes que
     la tutelle §4.2 est seule à porter :
       • Olivier — garant « pur » (aucun rôle propre), un pupille en P2 (l'enfant a son compte).
         Le cas le plus lisible : le parent, rien d'autre.
       • Sandrine — DEUX pupilles à deux niveaux d'autonomie (Jade en P1, sans compte ; Noah en
         P2, avec le sien), et athlète du club elle-même. C'est le seul compte où le sélecteur
         « Mes enfants » a réellement deux entrées, et où l'on voit qu'être garant est une
         RELATION (guardian_id) et non un rôle — un garant peut s'entraîner comme les autres. --}}
@php($demoAccounts = [
    ['email' => 'admin@demo.club', 'role' => 'Admin', 'note' => 'tout le back-office'],
    ['email' => 'vincent@demo.club', 'role' => 'Coach', 'note' => 'encadre, crée des séances'],
    ['email' => 'marie@demo.club', 'role' => 'Athlète', 'note' => 's\'inscrit aux séances'],
    ['email' => 'olivier@demo.club', 'role' => 'Parent garant', 'note' => 'gère son enfant'],
    ['email' => 'sandrine@demo.club', 'role' => 'Parent garant + athlète', 'note' => '2 enfants, et s\'entraîne'],
])
<div class="demo-accounts">
    <x-banner kind="warn" icon="alert-triangle" style="margin-bottom:12px">
        <b>Instance de démonstration.</b> Les données sont fictives et <b>réinitialisées chaque
        nuit</b> — inutile de faire attention, tout est effacé. Aucun email ni notification ne
        peut sortir d'ici.
    </x-banner>

    <div class="eyebrow">Comptes de démonstration</div>
    <ul class="demo-list">
        @foreach ($demoAccounts as $account)
            <li class="demo-item">
                {{-- Le clic remplit les deux formulaires possibles (lien magique et mot de passe) :
                     on ne sait pas lequel est affiché, et le sélecteur reste valable si le club
                     de démo coupe un moyen de connexion. --}}
                <button type="button" class="demo-mail"
                        onclick="document.querySelectorAll('input[type=email]').forEach(i => i.value = this.dataset.mail)"
                        data-mail="{{ $account['email'] }}">{{ $account['email'] }}</button>
                <span class="demo-role">{{ $account['role'] }}</span>
                <span class="demo-note meta">{{ $account['note'] }}</span>
            </li>
        @endforeach
    </ul>
    <p class="auth-fine">
        Mot de passe commun : <b>password</b>. Les autres comptes sont listés dans la
        documentation du projet.
    </p>
</div>

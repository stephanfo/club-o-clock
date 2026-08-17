# Plan de tests — testeurs de l'appli

> Merci de tester l'appli du club 🙏 **Aucune compétence technique nécessaire** : tu utilises
> uniquement l'interface, comme un vrai adhérent. Suis ton parcours, coche `[x]` quand ce qui est
> décrit se produit, et **note (ou capture) tout ce qui te semble bizarre, cassé ou peu clair**.
>
> - **Sur quel appareil ?** Idéalement teste **sur ton téléphone ET sur ordinateur** : la mise en
>   page change un peu, mais tout doit marcher pareil.
> - **Adresse de l'appli + ton compte** : fournis par l'organisateur du test. Le mot de passe est le
>   même pour tous les comptes de démo : **`password`**.
> - **Tu ne casses rien** : c'est un environnement de démo, prévu pour être malmené. N'hésite pas.
> - Certaines vérifications (emails techniques, historique d'envois) sont réservées à l'organisateur
>   et **ne figurent pas ici** : tu ne testes que ce que tu peux constater toi-même à l'écran.

Trouve ci-dessous **le parcours qui correspond au compte qu'on t'a donné**. Tu n'as pas besoin de
faire les autres.

| Ton compte ressemble à… | Va au parcours |
|---|---|
| Un athlète adulte (ex. `marie@demo.club`) | **§1 — Athlète** |
| Un athlète « suspendu » (ex. `kevin@demo.club`) | **§2 — Athlète suspendu** |
| Un coach (ex. `vincent@demo.club`) | **§3 — Coach** |
| Un coach qui s'entraîne aussi (ex. `mathieu@demo.club`) | **§4 — Coach-athlète** |
| Un parent (ex. `florence@demo.club`, `sandrine@demo.club`, `olivier@demo.club`) | **§5, §6 ou §7 — Parent** |

> 💡 Descriptif des comptes et de leurs particularités : voir la fiche **Comptes de démo** si on te
> l'a transmise. Sinon, contente-toi du compte qu'on t'a attribué.

---

## 1. Parcours Athlète — `marie@demo.club`

> 🔑 **Connexion** — Email : `marie@demo.club` · Mot de passe : `password`

### 1.1 Se connecter
- [ ] Ouvrir l'appli, se connecter avec **l'email + le mot de passe** → tu arrives sur l'**Accueil**.
- [ ] Te déconnecter (**Profil → onglet Connexion**), puis te reconnecter → tout refonctionne.

### 1.2 L'Accueil
- [ ] Une **salutation à ton prénom** et ta **prochaine séance** mise en avant (avec un badge
      « Tu participes » si tu es inscrite).
- [ ] Une liste **« Mes prochaines séances »** : uniquement les séances où **tu es inscrite** (pas
      tout le planning). La séance déjà mise en avant plus haut n'apparaît pas en double.
- [ ] Dans cette liste, une séance où tu es **en liste d'attente** (ex. la « Natation du mercredi soir »
      où tu es en attente pour cause de quota) apparaît bien, avec un badge **« Liste d'attente »**
      distinct du badge vert « Tu participes ». Elle n'est **pas** masquée.
- [ ] Un petit bloc **quotas de la semaine** (ex. « Natation 0/1 » ou « 1/1 »).
- [ ] Un bloc **« Apéro à venir »** si quelqu'un offre l'apéro sur une séance à venir.
- [ ] Une **bannière d'info épinglée** en haut (la note « Sport Attitude ») ; cliquer dessus t'emmène à
      la page **Infos** ouverte sur cette note.

### 1.3 Le Planning
- [ ] Basculer entre les vues **Semaine / Jour / Mois** ; naviguer précédent / suivant / aujourd'hui.
- [ ] Utiliser les **filtres** : Tout / Natation / Vélo / Course / Compét., et la case
      « Mes inscriptions ».
- [ ] En vue **Mois** : des pastilles de couleur par discipline ; cliquer un jour ouvre la vue Jour.
- [ ] Une séance **annulée** (Course à pied jeudi) apparaît **barrée / grisée** avec une
      étiquette « Annulée ».
- [ ] Tu vois bien les séances **adultes** et celles **ouvertes à tous**, mais **pas** les séances
      réservées aux jeunes (ex. « Natation samedi matin — jeunes »).

### 1.4 S'inscrire / se désinscrire
- [ ] **S'inscrire** à une séance future avec de la place → le badge « Tu participes » apparaît tout
      de suite (bouton `+` sur mobile, ou depuis la fiche de la séance).
- [ ] **Se désinscrire** → une confirmation est demandée, puis le badge disparaît.
- [ ] **Séance pleine** : sur « Natation samedi matin — jeunes » (capacité 6, déjà saturée) → le bouton devient
      **« Rejoindre la liste d'attente »**, puis ton statut affiche « En liste d'attente · rang ».
- [ ] **Quota** : tu as déjà 1 natation cette semaine. Essaie de t'inscrire à une **2ᵉ natation la
      même semaine** → une fenêtre « Quota atteint » s'affiche. Si tu confirmes → tu passes en liste
      d'attente. Si tu annules → rien ne se passe.
- [ ] **Chevauchement d'horaires** : t'inscrire à une séance qui tombe en même temps qu'une autre où
      tu es déjà inscrite → un avertissement s'affiche, mais **tu peux quand même** t'inscrire.
- [ ] **Séance déjà commencée ou passée** : plus **aucun bouton** pour s'inscrire ou se désinscrire.
- [ ] **Séance réservée aux jeunes** (« Enchaînement — jeunes ») : **aucun bouton d'inscription**
      pour toi (Marie est adulte).

### 1.5 La fiche d'une séance
- [ ] Des onglets : **Infos / Encadrement / Inscrits / Liste d'attente / Parcours / Apéro** (et
      **Débriefs** sur une compétition).
- [ ] **Inscrits** : les autres athlètes apparaissent en **« Prénom + initiale »** (pas le nom complet).
- [ ] **Encadrement** : les coachs et leurs qualifications ; sur une séance encadrée par Vincent, un
      badge **« PSC1 expirée »**.
- [ ] **Contenu** : une séance détaillée s'affiche proprement (gras, listes) ; un fichier joint est
      téléchargeable s'il y en a un.
- [ ] **Parcours** : sur un « Vélo — Cuisses » du dimanche → un onglet **Parcours**. Sur
      « Sortie trail nature » → juste un lieu en texte, sans carte ni météo.
- [ ] **Météo** : sur une séance à venir (moins de 16 jours) avec un vrai lieu → une **prévision
      météo**. Au-delà → « trop loin ».
- [ ] Sur la séance **annulée**, un bandeau « Séance annulée » et aucune action possible.

### 1.6 L'apéro 🍻
- [ ] Sur une séance future où **tu participes** : un bouton **« J'offre l'apéro »** + un petit motif
      (140 caractères max).
- [ ] Une **chope** apparaît alors sur la carte du planning et sur la fiche ; retirer ton apéro → elle
      disparaît.
- [ ] Te désinscrire de la séance → ton apéro disparaît **aussi** automatiquement.

### 1.7 Compétitions et débriefs
- [ ] La compétition **à venir** (« Triathlon M du lac ») : sa fiche montre le type
      d'épreuve, la distance, un lien externe, un album photos ; tu peux **déclarer que tu y vas**.
- [ ] La compétition **passée** (« Triathlon M de printemps ») : onglet **Débriefs** → 2 débriefs déjà
      publiés (Marie et Lucas).
- [ ] Comme tu y as participé, tu peux **modifier ton débrief** — mais pas en écrire un deuxième.

### 1.8 Alertes & notifications
- [ ] L'écran **Alertes** n'est **pas vide** (annulation de séance, promotions en liste d'attente…).
- [ ] **Profil → Notifications** : un tableau type × canal que tu peux modifier ; un bouton
      **« pause générale »** des notifications.

### 1.9 Ton profil
- [ ] Ton **identité** et ta **catégorie** ; des compteurs de **quotas de la semaine** cohérents avec
      l'Accueil.
- [ ] Tes **méthodes de connexion** listées ; tu ne peux pas supprimer la dernière (sinon tu ne
      pourrais plus te connecter).
- [ ] **Demander la suppression de ton compte** → un message « demande envoyée » + un bandeau te
      propose de l'**annuler** pendant 7 jours. **Annule** ta demande pour ce test.

### 1.10 La page Infos
- [ ] Menu **Infos** : tu vois **exactement 2 notes** — les 2 codes promo (« Sport Attitude » et
      « Aquagliss »). Tu ne vois **ni** le code de la piscine (réservé aux coachs) **ni** la fiche
      identifiants extranet (réservée au bureau).
- [ ] Le contenu (gras, listes, liens) s'affiche proprement ; la note « Sport Attitude » est la même que
      celle épinglée en **bannière d'accueil** (§1.2).
- [ ] **Aucun bouton pour créer ou modifier** une note (tu es en lecture seule).

---

## 2. Parcours Athlète suspendu — `kevin@demo.club`

> 🔑 **Connexion** — Email : `kevin@demo.club` · Mot de passe : `password`

- [ ] Tu **peux te connecter** normalement.
- [ ] Tu peux **consulter** le planning et les fiches, mais **aucune inscription** n'est possible : un
      message t'indique « accès aux inscriptions suspendu — contacte le bureau ».

---

## 3. Parcours Coach — `vincent@demo.club`

> 🔑 **Connexion** — Email : `vincent@demo.club` · Mot de passe : `password`

### 3.1 Créer / modifier une séance
- [ ] **« Créer une séance »** : remplir un entraînement complet (discipline, date **future**, durée,
      lieu, capacité, **catégories concernées**, contenu détaillé, éventuellement une pièce jointe)
      → la séance apparaît au planning.
- [ ] Créer une séance en cochant **seulement une catégorie jeune** → elle apparaît chez les jeunes
      concernés mais **pas** chez les adultes. Laisser les catégories **vides** → la séance est
      visible et ouverte à **tout le monde**.
- [ ] Créer une **compétition** (type d'épreuve, distance, lien) et un **événement du club** (agenda).
- [ ] Modifier une séance **qui a déjà des inscrits** en changeant la date/l'heure/le lieu → une
      fenêtre te demande **quoi notifier** aux inscrits.

### 3.2 Annuler / restaurer une séance
- [ ] **Annuler** une séance future **avec des inscrits** → bandeau « annulée » ; les inscrits sont
      prévenus (visible dans leur écran Alertes). Un éventuel apéro est mis « en pause ».
- [ ] **Restaurer** la séance → les inscriptions reviennent telles quelles, l'apéro revient.

### 3.3 Parcours / carte
- [ ] Coller une **URL de parcours OpenRunner** valide → la carte s'affiche sur la fiche ; une URL
      non autorisée est refusée.
- [ ] Téléverser un fichier **GPX** → le tracé s'affiche sur une carte, avec un bouton de
      téléchargement.

### 3.4 Gérer les inscrits
- [ ] Fiche → **Inscrits → « Inscrire un athlète »** : la liste propose les athlètes actifs, **sans
      Kévin** (suspendu), sans ceux déjà inscrits ; la recherche par nom fonctionne.
- [ ] Inscrire un athlète qui a **encore de la place dans son quota** → il devient participant (il
      reçoit une notification, visible dans **son** écran Alertes).
- [ ] Inscrire un athlète **au-dessus de son quota** → une fenêtre propose soit de le mettre en liste
      d'attente, soit de **forcer** avec un motif → un badge « override » apparaît sur l'inscrit.
- [ ] **Retirer** un inscrit d'une séance pleine → la 1ʳᵉ personne en liste d'attente est
      automatiquement **promue** participante.
- [ ] **Augmenter la capacité** d'une séance pleine qui a une liste d'attente → les personnes en
      attente sont promues automatiquement, dans l'ordre.

### 3.5 Encadrement
- [ ] T'inscrire comme **encadrant** d'une séance ; tes qualifications (BF2, PSC1 expirée)
      apparaissent sur la fiche.
- [ ] Inscrire **un autre coach** comme encadrant.
- [ ] Retirer le **dernier coach** d'une séance → une confirmation explicite « séance sans
      encadrement ».
- [ ] Sur une séance que tu encadres, si tu cliques **« Je participe »** (comme athlète) → une
      fenêtre te demande de **basculer** de rôle (tu ne peux pas être les deux à la fois sur la même
      séance).

### 3.6 La page Infos
- [ ] Menu **Infos** : tu vois **3 notes** — les 2 codes promo **plus** le code de la piscine réservé
      aux coachs. Tu ne vois **pas** la fiche identifiants extranet (réservée au bureau). Lecture seule.

---

## 4. Parcours Coach-athlète — `mathieu@demo.club`

> 🔑 **Connexion** — Email : `mathieu@demo.club` · Mot de passe : `password`

- [ ] Tu vois **les deux mondes** dans la navigation (athlète **et** coach).
- [ ] Tu peux **t'inscrire comme athlète** à une séance, **et encadrer** une autre : les deux badges
      « Tu participes » / « Tu encadres » coexistent sur des séances différentes.
- [ ] Sur **une même** séance, jamais les deux à la fois : une bascule t'est demandée (§3.5).

---

## 5. Parcours Parent — `florence@demo.club` (parent de Lucie)

> 🔑 **Connexion** — Email : `florence@demo.club` · Mot de passe : `password`
>
> Lucie n'a **pas** de compte à elle : tu t'occupes d'elle depuis **ton** compte.

- [ ] À la connexion, tu vois une entrée **« Mes enfants »** dans la navigation, et un sélecteur
      **« Tu consultes : Moi / Lucie »** sur l'Accueil et le Planning.
- [ ] **Mes enfants** : une carte Lucie (âge, catégorie, « tu agis en son nom ») + jusqu'à ses
      **3 prochaines séances** ; cliquer la carte bascule l'affichage sur Lucie.
- [ ] Choisir **Lucie** dans le sélecteur → un bandeau « Tu agis pour Lucie » ; l'Accueil et le
      Planning montrent **ses** séances et quotas.
- [ ] En consultant **pour Lucie** (une jeune), le planning montre les séances **jeunes** + ouvertes à
      tous, **pas** les séances adultes.
- [ ] **Inscrire Lucie** à une séance future (bouton « Inscrire Lucie ») → l'inscription est bien au
      nom de **Lucie**, pas au tien.
- [ ] **Désinscrire Lucie** → une confirmation à son nom.
- [ ] Essayer d'inscrire Lucie à **2 natations la même semaine** → la fenêtre de quota s'affiche à son
      nom.
- [ ] Les **notifications concernant Lucie** (annulation, promotion) arrivent dans **ton** écran
      Alertes (c'est toi qui les reçois pour elle).
- [ ] Revenir sur **« Moi »** dans le sélecteur → tu retrouves **tes propres** séances.

---

## 6. Parcours Parent de plusieurs enfants — `sandrine@demo.club`

> 🔑 **Connexion** — Email : `sandrine@demo.club` · Mot de passe : `password`
>
> Sandrine est **elle-même athlète**, et parent de **Jade** et **Noah**.

- [ ] Le sélecteur propose **3 choix** : Moi / Jade / Noah.
- [ ] T'inscrire **pour toi-même** (« Moi »), puis basculer sur **Jade** et l'inscrire, puis sur
      **Noah** → trois inscriptions distinctes, chacune au bon nom.
- [ ] **Mes enfants** : deux cartes (Jade et Noah). Noah affiche la catégorie **Cadets** + un
      **surclassement Juniors**.

---

## 7. Parcours Parent + enfant autonome — `olivier@demo.club`

> 🔑 **Connexion** — Email : `olivier@demo.club` · Mot de passe : `password`
> (le fils **Théo** : `theo.mercier@demo.club` · même mot de passe `password`)
>
> Olivier est **uniquement parent** (il ne s'entraîne pas lui-même). Son fils **Théo Mercier** a son
> propre compte.

- [ ] Olivier se connecte, voit **Théo** dans **« Mes enfants »**, et peut l'**inscrire /
      désinscrire** via le sélecteur.
- [ ] Olivier **ne peut pas s'inscrire lui-même** à une séance (il n'est pas athlète) → un message le
      lui indique.
- [ ] *(Si on t'a donné le compte de Théo)* Théo peut se connecter **tout seul** avec son propre
      compte et s'inscrire lui-même à une séance.

---

## Installer l'appli sur ton téléphone (facultatif)

- [ ] Dans le menu du navigateur, **« Installer l'application » / « Sur l'écran d'accueil »** →
      l'appli apparaît comme une vraie appli (icône, plein écran).
- [ ] Charger le planning, **couper le réseau**, recharger → le planning reste consultable ; en
      rétablissant le réseau, il se remet à jour.

---

## Comment nous remonter ce que tu constates

Pour chaque point qui **ne se passe pas comme décrit** :

1. **Où** tu étais (quel écran, quel bouton).
2. **Ce que tu attendais** vs **ce qui s'est passé**.
3. Une **capture d'écran** si possible.
4. **Téléphone ou ordinateur** (et lequel).

Merci ! 🙌

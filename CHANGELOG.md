# Journal des modifications

Toutes les évolutions notables de Club'O'Clock sont consignées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), et le projet applique le
[versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

### Corrigé

- **La notification de réactivation d'un accès athlète menait le parent au mauvais écran.** Quand
  c'est un enfant mineur qui est réactivé, c'est son garant qui reçoit le message — « Ton accès
  athlète est réactivé » l'envoyait sur **son** tableau de bord à lui, où rien n'avait changé — et le
  tutoyait, lui, pour un accès qui n'était pas le sien. Elle nomme désormais l'enfant, dit « L'accès
  athlète de Jade est réactivé » et ouvre « Mes enfants ». La notification de rupture de tutelle nomme elle
  aussi l'enfant concerné, ce qui manquait à un parent garant de plusieurs enfants ; elle continue de
  mener au profil de chacun — surtout pas à « Mes enfants », d'où le lien vient justement de
  disparaître.

- **Chaque clic dans une modale levait une erreur JavaScript.** Invisible à l'écran — rien ne cassait,
  l'action se faisait —, mais la console de chaque utilisateur recevait une `SyntaxError` à chaque
  bouton de pied de modale. Le composant portait un `wire:click.stop` **sans valeur** : Livewire
  évalue tout `wire:<événement>` comme `$wire.` suivi de l'expression, donc ici `$wire.` tout court.
  C'est Alpine qui arrêtait réellement la propagation vers le voile ; l'attribut Livewire posé à côté
  n'ajoutait que l'erreur. Défaut présent depuis l'écriture du composant, sorti au grand jour à la
  première mise en production. Deux garde-fous l'empêchent de revenir : un test refuse tout
  `wire:` sans valeur dans une modale rendue, et le harnais navigateur **échoue désormais sur toute
  erreur JavaScript en console** — il cliquait jusqu'ici sans jamais la regarder.

- **Les modales débordaient de la fenêtre, boutons du pied hors écran.** Visible sur Safari
  seulement, et donc invisible du harnais E2E qui tourne sous Chromium : le voile de la modale ne
  déclarait pas de hauteur, WebKit renonçait alors à borner la boîte, et le surplus sortait **des
  deux côtés à la fois** — titre en haut, boutons en bas — sans rien de rattrapable au défilement.
  La modale tient désormais dans la fenêtre sur tous les navigateurs, seul son corps défile, les
  encoches de téléphone sont réservées, et le fond ne défile plus derrière elle. Les pieds portant
  **trois** actions (« Quota dépassé ») les empilent au lieu de les serrer jusqu'à les couper.

- **Une modale déjà validée réapparaissait au retour arrière.** Après avoir enregistré une séance
  et choisi de prévenir les inscrits, tout retour sur le formulaire — bouton du navigateur, geste,
  chevron — rouvrait la modale de confirmation, déjà validée et la séance déjà enregistrée. La
  navigation instantanée photographie la page quittée pour la rejouer au retour ; la modale était
  encore à l'écran au moment de la photo. Elle est désormais refermée avant, des deux côtés.
  *(Aucun envoi en double n'était possible : la garde d'idempotence tenait déjà. Le défaut était
  déroutant, pas destructeur.)*

- **La confirmation d'enregistrement d'une séance promettait des canaux fermés.** Elle annonçait
  « push + email » en dur, y compris quand le bureau avait coupé l'un des deux — ou les deux — dans
  les réglages du club. Elle énonce maintenant les seuls canaux réellement ouverts, et remplace le
  bouton par un avertissement quand plus aucun envoi n'est possible. Elle annonçait aussi « un champ
  structurant a changé » alors qu'elle s'ouvre également sur un simple changement de contenu (texte,
  parcours) : les deux cas sont désormais distingués.

- **La fiche d'un parent garant tombait en erreur côté admin.** Le bloc « Pupilles » affiche la
  catégorie d'âge de chaque enfant, mais celle-ci n'était pas chargée avec la fiche. Le défaut ne se
  déclenchait que sur un garant d'**au moins deux** enfants, ce qui l'avait rendu invisible jusqu'ici.

- **Le jeu de démonstration affichait « actif » un compte injoignable.** Une ancienne adhérente y
  était marquée désactivée alors qu'aucun écran ne dérive de pastille de ce drapeau : la liste et la
  fiche la donnaient « active » pendant que le filtre « Actifs » l'écartait sans rien en dire. Elle
  porte maintenant l'accès athlète suspendu, qui est l'état que l'application sait produire et
  défaire. Un test refuse désormais tout compte désactivé hors procédure de suppression.

- **Le planificateur ne tournait pas sur hébergement mutualisé.** La documentation prescrivait un
  cron `* * * * *`, qu'OVH ne permet pas : une exécution par heure au maximum, à une minute imposée
  par l'hébergeur, et une tâche qui ne peut pointer qu'un fichier PHP sans arguments. Deux
  conséquences, dont la seconde silencieuse : les notifications différées attendaient jusqu'à une
  heure, et surtout les tâches planifiées à une minute fixe (`hourly()`, `daily()`) pouvaient n'être
  exécutées **jamais** — la boucle de rattrapage couvrant 55 minutes sur 60, le trou restant se
  déplace avec la minute imposée, et aucune minute d'horloge n'est sûre. Météo et purge des jetons
  ne partaient donc pas, sans le moindre signal.

  Le point d'entrée devient `cron.php` (tâche horaire au manager), qui lance `schedule:run` chaque
  minute pendant 55 min. Toutes les tâches récurrentes sont désormais planifiées fréquemment et
  **rattrapables** : chacune n'honore qu'une exécution par période, et reprend une échéance manquée
  à la passe suivante au lieu de la perdre. Un test balaie les 60 minutes de démarrage possibles et
  échoue si une tâche redevient dépendante d'une minute absolue.

### Modifié

- ⚠️ **Une séance dont le créneau est terminé ne peut plus être annulée.** La borne est la **fin**
  (début + durée) et non le début : une séance annulée sur place — orage, gymnase fermé — l'est
  souvent quelques minutes après l'heure, et les inscrits doivent être prévenus. Passé la fin, la
  séance a **eu lieu** : l'annuler l'effacerait rétroactivement des statistiques de fréquentation et
  notifierait les inscrits d'une annulation sans objet. Le bouton disparaît alors de lui-même.

  *Changement de comportement pour les coachs : annuler une séance passée « pour faire le ménage »
  n'est plus possible.* Entre le début et la fin du créneau, l'annulation reste possible mais devient
  **définitive** — la restauration, elle, est bornée au début — et la confirmation l'annonce
  désormais explicitement au lieu de promettre une réversibilité qui n'existait plus.

- **Trois gestes destructifs demandent un accusé de réception.** Annuler une séance, suspendre
  l'accès athlète d'un adhérent et rompre une tutelle notifient des tiers sans possibilité de se
  dédire. Comme la bascule de saison le faisait déjà, le bouton n'est armé qu'une fois cochée une
  case qui **chiffre** la conséquence (« Je comprends que 12 inscrit·e·s seront prévenu·e·s »). La
  garde est **serveur** : un bouton grisé contourné ne déclenche rien.

### Ajouté

- **Les notifications disent enfin qui elles concernent et de quelle séance il s'agit.** Un parent
  garant est souvent adhérent lui-même : ses notifications et celles de ses enfants arrivaient sur le
  même compte, dans la même boîte, avec des textes **rigoureusement identiques**. « Une séance à
  laquelle tu es inscrit·e est annulée » — la sienne ou celle de son enfant ? Deux enfants inscrits à
  deux séances différentes produisaient deux messages indiscernables. Et le lien menait à une fiche
  qui parlait du parent, alors que la notification parlait de l'enfant.

  Une notification adressée au garant **nomme désormais l'enfant** (« Hugo · Annulation de séance ») et
  son lien ouvre la fiche **avec cet enfant pour sujet consulté** — le parent voit son inscription et
  peut agir pour lui sans rien rebasculer à la main. Ses propres notifications, elles, restent nues :
  le préfixe signale précisément qu'il s'agit de quelqu'un d'autre.

  Dans la foulée, le corps du message dit **quelle** séance et **quand** (« Natation jeunes · sam. 5
  sept. · 18:00 ») au lieu de répéter le titre sous une autre forme, et le récapitulatif d'affectation
  d'un coach annonce son volume et sa plage. Une notification se comprend maintenant sans ouvrir
  l'application — ce qui décide, en pratique, de la garder activée. Le prénom transporté pour composer
  le titre est **effacé de la file dès l'envoi** : il a servi, il n'a pas à y dormir.

- **Un garde-fou contre le déploiement d'un affichage périmé.** Les fichiers que le navigateur lit
  vraiment ne sont pas ceux qu'on écrit : une moulinette (`npm run build`) compresse les seconds en
  premiers, et seuls les premiers partent en ligne — hors Git, par transfert séparé. Oublier de
  relancer la moulinette ne provoquait **aucune erreur** : le site tournait avec l'ancien affichage,
  et rien ne le signalait. C'est arrivé pendant le développement de cette version.

  `npm run build` inscrit désormais dans le résultat l'empreinte des fichiers dont il sort, et
  `composer check` la confronte aux fichiers actuels — en nommant ceux qui ont bougé depuis. Même
  principe que le contrôle qui existait déjà pour la structure de la base. Un bundle *sans*
  empreinte est refusé lui aussi : ne pas savoir d'où il sort ne doit pas se lire comme une garantie
  qu'il est à jour. Le contrôle est rejouable sur le serveur après transfert.

- **Annuler une séance depuis un téléphone.** L'annulation était la seule action d'encadrement
  absente du format mobile — restaurer, inscrire un athlète et remplir la file quota y étaient déjà.
  Elle rejoint le bloc « Gestion » de l'onglet Infos. Volontairement hors de la barre d'action
  collante : un bouton rouge qui prévient tous les inscrits n'a rien à faire sous le pouce, à côté
  du bouton d'inscription.

- **La touche Échap ferme la modale ouverte.** Sauf l'éditeur de débrief, où une touche mal placée
  ferait perdre le texte en cours.

- **Icônes PWA personnalisables par le club.** Elles étaient des fichiers du dépôt
  (`public/icons/`) : un club qui les remplaçait entrait en conflit à chaque `git pull`,
  indéfiniment. Elles se téléversent désormais depuis *Paramètres du club* — trois PNG aux
  dimensions exactes (192, 512, et 180 pour iOS) — et sont stockées comme le logo, hors de l'arbre
  Git. Sans téléversement, l'application sert le jeu livré : une instance neuve reste installable
  en PWA sans aucune étape, et la démo publique ne porte le branding d'aucun club. Un bouton
  rétablit le jeu par défaut. L'icône iOS est aplatie sur fond blanc à la réception (iOS rend
  autrement la transparence en noir), et les dimensions sont refusées si elles ne sont pas exactes
  — une icône hors format casse l'installation PWA sans le moindre message d'erreur.

  ⚠️ Un appareil où la PWA est **déjà installée** conserve l'ancienne icône jusqu'à sa
  réinstallation : limite des PWA, sans contournement côté serveur.

- **Tests navigateur (E2E)** : harnais Playwright rejouant 20 scénarios dans un vrai navigateur —
  clics, attente des mises à jour Livewire, vérification en base et captures aux formats mobile et
  desktop. Couvre les gardes d'inscription, la bascule de rôle coach/athlète, la tutelle parentale,
  le cloisonnement admin, et — derrière un drapeau explicite — les parcours destructifs (RGPD,
  rupture de tutelle, bascule de saison). Le déblocage coach de la file quota (mécanisme C, §4.10.4)
  y est couvert de bout en bout : bouton actif et désactivé, promotion effective, `AuditLog` émis.
  Volontairement **hors de `composer check`** : la porte de qualité reste PHPUnit.
  Voir [`tests/E2E/README.md`](https://github.com/stephanfo/club-o-clock/blob/main/tests/E2E/README.md).
- **Poste de développement en conteneurs** ([`doc/DOCKER_LOCAL.md`](doc/DOCKER_LOCAL.md), et deux
  `Dockerfile` versionnés dans `docker/`) : de quoi lancer l'application, la porte de qualité et le
  harnais navigateur **sans installer PHP, MySQL ni les navigateurs** sur sa machine — seuls Git,
  Docker et Node restent nécessaires. Le mode d'emploi consigne les deux pièges qui coûtent le plus
  de temps : le harnais E2E code son adresse en dur et exige donc de partager la pile réseau du
  conteneur applicatif, et le paquet `default-mysql-client` de Debian installe en réalité le client
  MariaDB — `schema:dump` lancé là produit un dump MySQL que l'intégration continue rejette. Le
  garde-fou qui empêche un envoi réel vers les adresses du jeu de démonstration est posé sur le
  conteneur, jamais dans le `.env`.

  Ça ne change **rien à la cible de déploiement** : l'application se déploie toujours sur un
  hébergement mutualisé, sans Docker.
- **Supervision du traitement automatique** : l'écran des envois indique si le cron tourne encore.
  Sans lui, une tâche planifiée interrompue (quota d'hébergement, chemin PHP changé, crontab perdue
  au transfert) laissait les notifications s'accumuler sans qu'aucun signe ne l'annonce — le premier
  symptôme étant un adhérent non prévenu d'une annulation. Trois états : actif, interrompu depuis
  plus de 15 minutes, jamais observé (installation neuve, sans alarme).
- **Tests du club vide** : les écrans membre et administration sont vérifiés dans l'état d'un club
  fraîchement installé — catalogues seedés, un seul administrateur, aucune séance ni adhérent.
  C'est l'état que le développement ne rencontre jamais et que chaque club rencontre en premier.
- **Procédure de restauration de sauvegarde** ([`doc/INSTALL.md`](doc/INSTALL.md) §9.1), à répéter
  avant la mise en production : restauration dans une base séparée, contrôle des clés étrangères et
  des index, vérification qu'aucune migration n'est en attente.

### Corrigé

- Fiche séance : le bloc « Je participe » d'un coach-athlète ignorait les gardes de catégorie et de
  suspension, laissant une action que le serveur refusait systématiquement. Le motif du refus est
  désormais affiché à la place du bouton, sur mobile comme sur desktop.
- Fiche séance : un parent consultant une séance pour son enfant perdait ses propres actions
  d'inscription.
- Fiche adhérent : un coach sans date de naissance déclenchait un avertissement de catégorie
  trompeur.
- Modèles de séances : une séance déplacée par le bureau était recréée à son horaire d'origine lors
  d'une régénération.

## [1.0.0] — 2026-08-17

Première version publique. Application complète de gestion du planning d'entraînement pour club
sportif associatif, **déployée et éprouvée sur hébergement mutualisé**, avec une
[démonstration publique](https://demo.cluboclock.ratelet.fr/) réinitialisée chaque nuit.

### Planning et inscriptions

- Planning des séances en vue **semaine, mois et liste**, filtrable par discipline et par catégorie.
- **Inscription et désinscription** depuis la fiche de séance, avec **liste d'attente** et
  promotion automatique dès qu'une place se libère.
- **Capacité** par séance et **quotas** d'inscription paramétrables par étiquette.
- **Séances récurrentes** générées depuis des **modèles** (jour, heure, lieu, discipline, coach,
  capacité, catégories visées).
- **Ciblage par catégorie d'âge** : une séance ne s'ouvre qu'aux catégories visées.
- **Événements** et **sorties** en plus des séances d'entraînement classiques.

### Rôles et comptes

- Rôles **cumulables** : athlète, coach, admin.
- **Tutelle parentale** pour les mineurs, en trois configurations : enfant sans compte propre géré
  par un garant, enfant avec compte propre sous tutelle, adhérent autonome.
- Interface **parent** dédiée : inscrire ses enfants, suivre leurs séances, recevoir leurs
  notifications.
- Connexion par **mot de passe**, **lien magique** ou **Google** (optionnel).
- Amorçage du premier administrateur en ligne de commande (`club:create-admin`).

### Communication

- **Notifications** par email et **push web** (VAPID natif, sans service tiers) : annulation de
  séance, promotion depuis la liste d'attente, rappels, vie du club.
- File d'envoi (**outbox**) avec relance et backoff, consultable par l'admin.
- **Alertes** et **pages d'information** publiées par l'admin, avec périmètre de visibilité par
  rôle.
- **Débriefs** de séance rédigés par les coachs.

### Parcours et météo

- **Bibliothèque de parcours GPX** : import, tracé cartographique, profil altimétrique, distance et
  dénivelé calculés côté client.
- Intégration **OpenRunner** (optionnelle) pour l'affichage interactif d'un parcours.
- **Météo** de la séance sur 16 jours via Open-Meteo (service européen, sans clé, mis en cache).

### Administration

- **Paramètres du club** : nom, baseline, logo, palette de couleurs (les déclinaisons et la couleur
  de texte lisible sont calculées automatiquement), fuseau horaire, mois de bascule de saison.
- **Catalogues** adaptables : catégories d'âge, disciplines, qualifications, types d'événement,
  étiquettes de quota, lieux — archivage réversible, jamais de suppression.
- **Journaux** d'audit et d'activité, avec export XLSX.
- Gestion des adhérents : identité, rôles, surclassements, qualifications, suspension d'accès.

### Conformité et vie privée

- **Minimisation RGPD** : ni numéro de téléphone, ni certificat médical, ni numéro de licence
  fédérale.
- **Suppression de compte** à la demande, avec délai de grâce de 7 jours puis anonymisation.
- Page publique **mentions légales & confidentialité**, à compléter par le club.
- Polices **auto-hébergées** ; aucun flux vers un service non européen dans le chemin critique.

### Technique

- **PWA installable** : service worker maison, manifest servi dynamiquement d'après les paramètres
  du club.
- Conçue pour l'**hébergement mutualisé** : ni Docker, ni Node en fonctionnement continu, ni
  WebSocket, ni file d'attente externe — un cron minute suffit.
- **735 tests** automatisés, analyse statique PHPStan niveau 5, rejoués par la CI à chaque poussée.
- Modèle **une instance par club** : pas de multi-tenant, chaque club est propriétaire de ses
  données.

[Non publié]: https://github.com/stephanfo/club-o-clock/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/stephanfo/club-o-clock/releases/tag/v1.0.0

# Journal des modifications

Toutes les évolutions notables de Club'O'Clock sont consignées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), et le projet applique le
[versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

### Ajouté

- **On peut survoler un parcours GPX.** Un tracé statique dit mal par où on passe (sens, portions
  qui se croisent, aller-retour) et où tombent les montées. Sur la fiche séance comme sur la fiche
  parcours, le bouton « Survoler » fait avancer un point le long du tracé, carte qui le suit : lecture
  et pause, curseur pour se placer n'importe où, vitesse ×1, ×2 ou ×4. La distance parcourue,
  l'altitude et la pente du moment s'affichent, et un curseur avance sur le profil altimétrique — que
  la fiche séance affiche désormais elle aussi. Rien ne démarre tout seul, et la carte n'a pas besoin
  d'être déverrouillée. Au téléphone, la page se cale d'elle-même pour que carte, commandes et profil
  tiennent ensemble à l'écran, sans masquer le bouton d'inscription.
- **Un nouveau parent garant est prévenu.** Quand l'admin rattache un mineur à un garant ou lui en
  change, le garant entrant reçoit « Nouveau parent garant » (push et email), qui nomme l'enfant et
  ouvre « Mes enfants ». L'enfant qui a son propre compte est prévenu aussi, avec le nom de son
  nouveau garant. Jusqu'ici, seul le garant sortant l'était. Comme ces envois ne se rattrapent pas,
  « Lier ce garant » et « Rattacher » demandent désormais une confirmation avec case à cocher, qui
  nomme les personnes prévenues.

- **Une séance peut avoir sa propre adresse, avec carte et météo.** Une compétition ou un événement
  club se tient souvent dans un endroit qui ne reviendra pas. Le seul moyen de le renseigner était un
  texte libre, jamais géocodé : ces séances n'avaient ni carte ni météo. L'autre voie, créer un lieu
  favori pour chaque adresse, encombrait la bibliothèque d'entrées à usage unique. Le formulaire de
  séance propose désormais, **au choix**, un lieu favori ou une **adresse ponctuelle** : elle est
  géocodée comme un lieu du catalogue, affiche la même carte et la même météo, et ne vit que sur la
  séance. Retaper l'adresse efface les coordonnées de la précédente, pour qu'une carte ne pointe
  jamais ailleurs que ce qui est écrit ; des coordonnées saisies à la main acceptent la virgule.

- **Un intervenant extérieur peut être signalé sur un entraînement.** Un surveillant de baignade ou
  un MNS prestataire n'a pas de compte : la séance paraissait « sans encadrement » alors qu'elle
  l'était. Un champ facultatif le mentionne sans nommer la personne, sur la séance ou directement sur
  le modèle, qui le recopie sur chaque séance générée. La fiche l'affiche dans l'onglet Encadrement,
  le bandeau « Pas de coach inscrit » disparaît, et la séance sort du compteur admin des séances sans
  coach. Retirer le dernier coach du club le rappelle au lieu d'annoncer une séance sans encadrement.

- **La fiche adhérent permet de corriger le nom d'un enfant.** Un enfant sans compte ne peut pas
  corriger lui-même son identité, et la fiche admin n'éditait que l'email et la date de naissance :
  une faute de frappe dans son nom était définitive. La correction est tracée dans les journaux
  d'audit et d'activité ; elle n'ouvre aucun accès et ne recalcule aucune catégorie.

- **La vue Semaine sur téléphone affiche l'heure de fin.** Chaque carte répétait sous l'en-tête le
  jour qui y était déjà affiché. Sa colonne de gauche porte maintenant la plage horaire, début et fin
  : l'heure de fin n'apparaissait jusqu'ici que sur la fiche de séance.

- **La vue Semaine sur téléphone s'ouvre sur le jour courant.** Elle s'ouvrait en haut de liste,
  donc sur lundi, même un samedi : il fallait défiler à chaque arrivée pour atteindre la suite. La
  liste se positionne maintenant sur aujourd'hui, ou sur le prochain jour de la semaine qui a des
  séances ; les jours passés restent accessibles en remontant. Le positionnement n'a lieu qu'à
  l'arrivée : s'inscrire depuis une carte ou revenir sur la semaine ne fait jamais sauter la liste.
  Au retour d'une fiche séance, la liste reprend là où on l'avait laissée, et changer de semaine
  repart du haut au lieu de garder le défilement de la semaine quittée.

- **Le panneau de détail d'un modèle montre tout ce qu'il génère** : type, discipline, lieu,
  capacité et catégories ciblées, soit la moitié du formulaire qui manquait à la relecture.

- **Suppression définitive d'une séance annulée, réservée à l'admin.** L'annulation couvrait la
  séance qui n'a pas lieu ; rien ne couvrait la séance qui **n'aurait jamais dû exister** — un
  doublon, une saisie erronée. Elle restait affichée « annulée » indéfiniment, ce qui était faux :
  rien n'avait été annulé, la ligne était de trop, et seul un accès SQL permettait de la retirer.
  Le geste n'est offert que sur une séance **déjà annulée** : c'est l'annulation qui prévient les
  inscrits, la suppression n'a donc personne à notifier. Elle est **refusée tant qu'un débrief est
  rattaché** — du texte écrit par un membre ne s'efface pas au passage —, et l'écran explique le
  blocage au lieu de le laisser découvrir au clic. Les journaux survivent, avec le titre et le
  créneau en clair ; les notifications déjà reçues restent lisibles dans les cloches — titre et
  créneau compris — mais cessent de renvoyer vers une séance disparue, et celles encore en file
  partent quand même, vers le planning. Le geste est offert **aux deux formats** : l'annulation,
  qui le précède obligatoirement, est disponible au téléphone — réserver le second temps au bureau
  aurait obligé à changer d'appareil au milieu. Il vit dans le bloc « Gestion » de la fiche et non
  dans la barre collante, qui garde le geste réversible sous le pouce.

- **Le parent garant d'un enfant peut enfin être changé.** Le lien se posait à la création de la
  fiche — formulaire ou import — et ne se reprenait plus jamais : pour en changer, il fallait rompre
  puis rattacher, or la rupture est refusée à un enfant sans compte propre, et à raison — elle le
  laisserait sans garant **et** sans accès. Le garant d'un enfant était donc **définitif**. Un
  divorce, un décès, un changement de responsable légal, une simple erreur de saisie : aucune issue
  dans l'outil, et un message de la fiche qui conseillait de « reparenter » un geste qui n'existait
  pas. Une action **Changer de garant** rompt et rattache dans la même transaction : l'état « sans
  garant » n'existe à aucun instant, pas même en cas d'échec, ce qui rend le geste sûr là où
  l'enchaînement de deux actions ne l'était pas. Elle demande une confirmation forte — la
  conséquence est nommée, la case à cocher arme le bouton, et le refus est gardé côté serveur. Seul
  le garant sortant est prévenu : l'enfant, lui, n'a rien perdu. Le geste sert aussi le pupille
  arrivé à dix-huit ans sans compte propre — celui dont le garant était autrement indéplaçable **et**
  insupprimable —, sans pour autant permettre de placer un adulte sous tutelle : substituer un
  garant n'est pas en créer un.

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

### Modifié

- **« Tout effacer » les alertes demande une vraie confirmation.** Le bouton se contentait d'une
  petite fenêtre du navigateur, celle qui convient à un geste anodin qu'on refait d'un clic. Or il
  vide toute la liste, y compris les alertes plus anciennes qu'elle n'affiche pas, et rien ne les
  rappelle ensuite. Il ouvre maintenant la même fenêtre de confirmation que les autres actions
  destructives, qui énonce ce qui disparaît et rappelle que les notifications elles-mêmes restent
  tracées. Le retrait d'une alerte à l'unité, lui, ne change pas.

- **Le déblocage du quota reste actif jusqu'à la séance.** Débloquer la file « quota dépassé » ne
  valait que pour l'instant du clic : un athlète hors quota qui s'inscrivait ensuite repartait en
  attente malgré les places libres, et un désistement ou une hausse de capacité ne profitait qu'à la
  file « séance pleine ». Le bouton **« Débloquer le quota »** pose désormais un état sur la séance.
  Jusqu'à la séance, l'athlète hors quota s'inscrit directement tant qu'il reste des places ; une
  place qui se libère revient au premier de la file quota une fois la file « séance pleine » servie.
  Séance pleine, les hors-quota attendent dans l'ordre d'arrivée, et celui qui attendait avant le
  déblocage n'est pas doublé. Le déblocage est possible file vide (ouvrir la séance la veille), passe
  par un dialog qui nomme les promu·e·s et demande un accusé de réception dès qu'il notifie, se
  referme sans désinscrire personne, et repart à zéro si le tag de quota change ou si la séance est
  réactivée. Une chip « Quota débloqué » le signale sur la fiche, athlètes compris. (#66)

- **La météo couvre toute la durée de la séance, et plus seulement son départ.** Une sortie de trois
  heures affichait la température et le vent de la première heure. La fiche donne désormais la plage
  de température, le vent minimal et maximal, et retient le temps le plus défavorable du créneau
  (une neige forte passe devant une averse faible).

- **Le géocodage d'adresse passe de Nominatim à Photon.** Même donnée OpenStreetMap, même service
  ouvert, européen et sans clé, mais un moteur conçu pour la recherche au fil de la frappe. Nominatim
  ignorait le code postal et la commune sur une saisie approximative : « 5 rue du Stade 44150
  Ancenis » rendait une rue du Stade **à Pannecé**, à 25 km, une adresse fausse mais plausible qu'on
  valide sans y penser. Les suggestions sont en outre classées au plus près des lieux du club, sans
  écarter une compétition lointaine. Les mentions légales, le cadrage et le guide d'installation
  suivent. *Aucun réglage à changer sur une instance existante.*

- **Rompre une tutelle depuis l'administration demande un accusé de réception.** Le geste prévient le
  jeune et son garant sans pouvoir se dédire ; l'écran admin se contentait d'un bouton rouge, là où
  le même geste côté parent exigeait déjà une case cochée. La confirmation nomme maintenant les deux
  personnes prévenues, et le refus est gardé côté serveur.

- **L'écran des modèles ne promet plus une génération qui n'a pas lieu.** Modifier un modèle ne crée
  aucune séance, mais l'écran annonçait « N séances seront créées ». Il dit désormais ce que le modèle
  a déjà généré, et renvoie vers « Relancer / prolonger » pour une nouvelle plage. Le bouton
  « Générer & enregistrer », qui rejouait la plage déjà générée et affichait « 0 séance générée » en
  vert, est retiré. La relance ne compte plus que les séances réellement manquantes, refuse une
  plage où il n'en manque aucune et signale une plage dont les dates sont inversées.

- **Sur téléphone, les dialogs d'action définitive gardent la sortie sûre en haut.** Le pied des
  dialogs remonte l'action principale au-dessus d'« Annuler », convention juste pour un choix, pas
  pour une suppression : l'action irréversible se retrouvait sous le pouce. Les dialogs destructifs
  font désormais l'inverse ; rien ne change sur ordinateur.

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

### Corrigé

- **Les listes de garants de la fiche adhérent étaient triées par prénom.** « Choisir un garant »,
  « Remplacer le garant » et « Rattacher un mineur sans garant » suivent désormais l'ordre de la liste
  des adhérents et de l'écran de création : nom, puis prénom.

- **Deux avertissements de l'écran Modèles collaient au bloc précédent.** Dans le détail d'un modèle
  et dans la fenêtre « Relancer / prolonger la saison », l'avertissement est de nouveau séparé du
  bloc au-dessus.

- **Le bouton retour ramenait au formulaire qu'on venait d'enregistrer.** Après l'édition d'une
  séance, « Planning » renvoyait au formulaire, parce que l'écran quitté restait dans l'historique du
  navigateur. Le retour remonte maintenant directement à l'écran qu'il annonce. Enregistrer plusieurs
  fois un parcours n'oblige plus non plus à autant de retours pour en sortir.

- **Revenir au planning après s'être inscrit réaffichait l'état d'avant.** Le retour restaurait la
  page telle qu'on l'avait quittée, sans « Tu participes ». Elle se met à jour dès l'arrivée, et
  seulement si une action a eu lieu entre-temps.

- **Les cartes de séance pouvaient afficher le contenu d'une autre séance** après un changement de
  semaine ou de filtre : l'affichage les mettait à jour par position à l'écran et non par identité.
  Sur iPhone, les en-têtes de jour de la vue Semaine gardaient de même les dates de la semaine
  précédente.

- **Les apéros à venir s'affichaient sans date sur l'accueil.** « ven. 18:30 » deux fois de suite
  pour deux apéros à trois semaines d'écart : l'accueil affiche maintenant le quantième et le mois.

- **Retirer le fichier GPX déposé dans un parcours ouvrait une erreur 500.** Le retrait annule
  désormais le dépôt en cours et, en édition, ramène aux statistiques du parcours enregistré.

- **L'historique d'inscriptions de la fiche adhérent n'affichait que les lieux favoris** : les
  séances à adresse libre y apparaissaient sans lieu.

- **Le cache météo n'était jamais purgé** et grossissait indéfiniment, une ligne par créneau. Les
  créneaux passés sont désormais élagués par la tâche de nettoyage existante ; les prévisions à venir
  restent en réserve, servies si Open-Meteo est injoignable.

- **Un adhérent de 17 ans pouvait être traité comme majeur, et perdre son parent garant.** La
  minorité se calculait sur l'« âge de saison » — l'âge atteint au 31 août de fin de saison —, une
  convention juste pour la **catégorie sportive**, où l'on court toute l'année dans la catégorie de
  l'âge qu'on y atteindra, mais fausse pour la **tutelle**, qui est un fait juridique. Un adhérent né
  fin août était compté majeur dès le 1er septembre, onze mois avant ses dix-huit ans : impossible de
  lui déclarer un garant à la création, impossible de lui en rattacher un, sa ligne d'import refusée
  faute d'email et le `parent_email` du fichier **ignoré sans un mot**. Les deux âges sont désormais
  distincts : la catégorie garde l'âge de saison, la tutelle prend l'âge réel du jour.

- **La minorité stockée en base ne vieillissait jamais.** Écrite à la création, à l'édition de la
  date de naissance et à l'import, elle n'était recalculée nulle part — pas même à la bascule de
  saison, qui recalcule pourtant les catégories. Un enfant inscrit à dix ans restait mineur en base
  bien après ses dix-huit ans ; à l'inverse, un adulte créé mineur des années plus tôt se voyait
  **refuser le rôle de parent garant** par une valeur périmée. La colonne est supprimée : la
  minorité se déduit de la date de naissance à chaque fois qu'on la demande.

- **Un enfant sans compte propre bloquait en dur les réglages d'authentification du club.** Le
  décompte des comptes « qui n'auraient plus aucun moyen de se connecter » exemptait les mineurs
  sans email — mais sur le critère de l'âge, si bien qu'un pupille que l'âge de saison comptait
  majeur y rentrait et interdisait de couper le lien magique ou Google, au nom d'un accès qu'il
  n'avait pas. L'exemption porte désormais sur ce qu'elle protège vraiment : l'absence de tout moyen
  de connexion.

- **Un pupille devenu majeur n'avait pas de sortie.** L'ouverture d'un compte autonome lui était
  refusée — elle exigeait un mineur — et la rupture de tutelle lui était offerte : elle produisait un
  compte sans garant ni accès, que plus rien ne pouvait reprendre. L'ouverture de compte ne regarde
  plus l'âge, et la rupture est refusée tant qu'il n'y a pas de compte propre, quel que soit l'âge.

- **Le jeu de démonstration vieillissait d'une catégorie par an.** Ses dates de naissance étaient
  écrites en dur : à chaque 1er septembre, la bascule d'année sportive faisait monter tout le monde
  d'un cran. Les « benjamins » du jeu sont devenus cadets, puis juniors, et les séances jeunes se
  sont vidées de leurs inscrits — sans le moindre message, puisque rien n'était faux, seulement
  périmé. Au 1er septembre 2026, le cas limite est tombé : l'athlète surclassé de la démo a rejoint
  pour de bon la catégorie dans laquelle il n'était que surclassé, et le rattachement qui le
  démontrait — posé en « non-principale » — lui a retiré sa **seule** catégorie. Un compte que la
  démo propose et que le serveur refuse partout. Les âges sont désormais exprimés relativement à la
  saison en cours, comme le sont déjà les séances du jeu, et le surclassement se dérive de la
  catégorie réelle au lieu d'être nommé en dur. Un test place la démo trois saisons plus loin et
  vérifie que ce qu'elle prétend montrer tient toujours.

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

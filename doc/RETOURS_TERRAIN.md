# Retours terrain

Carnet des frictions rencontrées **en exploitation réelle** : ce que l'usage révèle et que le
cadrage initial n'avait pas vu. Une entrée par constat, datée, déposée au fil de l'eau.

> ⚠️ **Ce document n'est pas une source de vérité et rien n'y est arbitré.** Il ne décide ni du
> périmètre, ni du produit, ni de la technique — le [PRD](PRD.md) et le
> [cadrage technique](CADRAGE_TECHNIQUE.md) restent seuls à faire foi. Les pistes notées ici sont
> des intuitions de terrain, pas des décisions.
>
> **Consolidation** : au moment de préparer la version suivante, chaque entrée est reprise, arbitrée
> et, si elle est retenue, portée dans le PRD (périmètre §3.1, hors-périmètre §3.2, ou spécification
> dédiée). L'entrée reste ici, marquée du sort qu'elle a connu.

**Format d'une entrée** — quatre rubriques, dans cet ordre : ce qu'on observe (le symptôme concret,
pas la solution), ce qu'on fait aujourd'hui (le contournement en vigueur, pour que quelqu'un
d'autre puisse s'en sortir sans reposer la question), la piste envisagée, et les traces (fichiers ou
sections du PRD concernés, pour retrouver le contexte dans six mois). Les entrées sont **datées du
jour du constat** et ajoutées **à la suite**, en ordre chronologique.

---

## 2026-09-03 — Parents non-athlètes et pré-inscription des familles

**Ce qu'on observe.** Le modèle traite le garant comme une **relation** (§4.2), pas comme un rôle —
c'est juste, mais la création d'un « parent pur » (quelqu'un qui n'existe dans le club que comme
garant) n'est outillée nulle part et se heurte à quatre frottements :

1. **La date de naissance est obligatoire** alors qu'on ne l'a pas et qu'elle ne sert à rien pour
   lui. Elle est `required` au formulaire comme à l'import, bien que la colonne soit `nullable` en
   base. Elle ne pilote que `is_minor` et la catégorie dérivée — deux choses sans objet pour un
   compte sans rôle `athlete`.
2. **L'import CSV force le rôle `athlete`** sur toutes les lignes, sans exception et sans colonne
   de rôle. Un parent pur importé arrive donc athlète, et il faut y repenser après coup, fiche par
   fiche. Rien ne le signale.
3. **Les bornes de date divergent** entre les deux chemins de création : le formulaire accepte à
   partir du 1900-01-02 (`after:1900-01-01`), l'import refuse toute l'année 1900 (`year <= 1900`).
   Une fiche saisie à la main peut donc faire échouer un import ultérieur — et comme l'import est
   tout-ou-rien, elle bloque le fichier entier en désignant une date que l'admin a lui-même saisie.
4. **Le lien enfant → garant ne se noue que si le garant préexiste**, en base ou dans le même CSV.
   L'ordre de constitution du fichier n'a pas d'importance (résolution en deux passes), mais une
   famille dont le parent n'est nulle part produit une ligne en erreur.

Plus en amont : **il n'y a pas de pré-inscription**. Toute saisie passe par le bureau, qui doit
deviner pour chaque enfant s'il relève de P1 (fiche sans email, parent agissant pour lui) ou de P2
(compte propre sous tutelle) — une information que seule la famille détient.

**Ce qu'on fait aujourd'hui.** Créer le parent pur **à la main** (Admin → Adhérents → Ajouter) en
décochant « athlète » — aucun rôle n'est requis, `roles` peut rester vide. Pour la date de
naissance, saisir une valeur conventionnelle d'adulte, `01/01/1980` : acceptée par les deux chemins,
lisible comme un remplissage, et le `Master` qui s'affiche alors en colonne « Cat. » est purement
cosmétique. Sur un import de rentrée, il reste plus rapide de tout importer puis de retirer le rôle
`athlete` des parents purs depuis leur fiche — étape manuelle, à ne pas oublier.

**La piste.** Une **pré-inscription par la plateforme** : la famille se déclare elle-même, désigne
qui est parent et qui est enfant, et **choisit le cas** (enfant sans compte propre, ou adolescent
avec sa propre adresse) plutôt que de laisser le bureau le déduire. Le bureau valide et n'a plus
qu'à vérifier. Corollaires à trancher au moment de l'arbitrage — l'absence d'inscription publique
est aujourd'hui une **décision de sécurité assumée** (§4.1.3 : il n'existe aucun point d'entrée
public, l'amorçage passe par `club:create-admin`), donc y ouvrir une brèche demande un dispositif
propre : lien d'invitation de campagne à durée limitée ? file de validation par le bureau ? Et le
statut de garant doit rester une relation, sans dériver vers un rôle `parent` dans `roles[]`.

**Traces.** PRD §4.1.3 (flow de création), §4.2 (P1/P2/P3) · [MemberCreate.php](../app/Livewire/Admin/MemberCreate.php)
(règles de validation, `guardian_id`) · [MemberImportService.php](../app/Services/MemberImportService.php)
(`parseDob`, rôle forcé au commit) · [DemoSeeder.php](../database/seeders/DemoSeeder.php)
(Olivier Mercier : le parent pur `roles => []` existe déjà dans le jeu de démo).

---

## 2026-09-03 — Suivi de délivrabilité des emails (Brevo)

**Ce qu'on observe.** L'outbox s'arrête à `sent`
([migration](../database/migrations/2026_01_01_000220_create_notification_outbox_table.php)), qui
signifie exactement « remis à l'API Brevo » — pas « arrivé dans la boîte ». Quand un adhérent dit
« je n'ai jamais reçu mon invitation », le bureau ne peut pas trancher entre : jamais parti,
adresse invalide, rejeté par le serveur destinataire, classé en indésirable, ou bien reçu et
ignoré. Or c'est précisément sur l'**invitation** et le **magic link** que ça compte : l'accès au
compte est en jeu, et le seul geste disponible aujourd'hui — « renvoyer l'invitation » — se joue à
l'aveugle, sans savoir si le problème est l'adresse ou l'attention du destinataire.

> ⚠️ Ne pas se laisser abuser par `notification_outbox.read_at` : ce champ existe déjà mais porte
> une **autre sémantique** — la lecture **in-app** de l'alerte, posée par
> [Alerts.php](../app/Livewire/Alerts.php) à l'affichage. Le réutiliser pour une ouverture d'email
> mélangerait deux faits sans rapport.

**Ce qu'on fait aujourd'hui.** Rien dans l'application : on ouvre le tableau de bord Brevo à la
main, hors de l'outil, et on croise sur l'adresse email. Puis on renvoie l'invitation depuis la
fiche adhérent.

**La piste.** Brevo expose ces événements, et le bridge `symfony/brevo-mailer` **déjà installé** en
fournit une partie du câblage — ce qui réduit beaucoup le coût estimé :

- `BrevoApiTransport` pose déjà le `messageId` sur le `SentMessage` (ligne 71 du transport). La
  corrélation envoi ↔ événement est donc disponible **gratuitement à l'envoi** : il « suffit » de
  la persister sur la ligne d'outbox.
- Le bridge livre `Webhook/BrevoRequestParser` et `RemoteEvent/BrevoPayloadConverter`, qui
  traduisent déjà les événements Brevo en événements Symfony normalisés.

Deux voies d'acquisition à départager :

- **Webhook** (Brevo pousse vers l'instance) : temps réel, largement outillé par le bridge, mais
  ouvre une **route publique** à authentifier, et le traitement doit rester court — le mutualisé
  n'a pas de file d'attente.
- **Polling par cron** (`GET /v3/smtp/statistics/events`) : s'aligne sur le drain d'outbox déjà en
  place (cadrage §7.13), n'ajoute **aucune surface publique**, et une latence de quelques minutes
  est sans conséquence pour un diagnostic. Plus proche de l'architecture existante — c'est la voie
  que je regarderais en premier, sans l'avoir tranchée.

**Le vrai arbitrage n'est pas technique.** Le bridge sépare déjà nettement deux familles, et cette
frontière est la bonne question produit :

- **Délivrabilité** (`MailerDeliveryEvent` : reçu, différé, délivré, bounce, rejeté) — un
  **diagnostic technique** sur un message qu'on a soi-même envoyé. Répond exactement au besoin
  ci-dessus.
- **Engagement** (`MailerEngagementEvent` : ouverture, clic, plainte, désinscription) — de la
  **mesure de comportement** sur des personnes. Sur un projet qui a fait de la minimisation un
  principe (ni téléphone, ni certificat médical, ni numéro de licence), afficher au bureau « X a
  ouvert la convocation » n'est pas un détail d'implémentation.

À quoi s'ajoute un argument de fiabilité : l'ouverture repose sur un **pixel traceur**, et le
converter du bridge liste `proxy_open` / `unique_proxy_open` — c'est-à-dire les préchargements
d'Apple Mail Privacy Protection, qui déclenchent une « ouverture » sans que personne n'ait lu quoi
que ce soit. Une donnée à la fois intrusive **et** fausse.

**Piste à privilégier, donc** : prendre la délivrabilité, laisser l'engagement de côté — ou à tout
le moins le traiter comme une décision séparée, avec son propre motif. La question « l'email
est-il arrivé ? » se répond entièrement sans savoir s'il a été lu.

**Traces.** Cadrage §6.3 (email transactionnel UE), §7.13 (outbox/cron) ·
[notification_outbox](../database/migrations/2026_01_01_000220_create_notification_outbox_table.php)
(statuts `pending|sent|failed|cancelled`, `read_at` = lecture in-app) ·
[MailServiceProvider.php](../app/Providers/MailServiceProvider.php) (branchement du transport) ·
`vendor/symfony/brevo-mailer/` (transport, `Webhook/`, `RemoteEvent/`).

---

## 2026-09-03 — L'import CSV écrase une identité sans le dire

**Ce qu'on observe.** À l'import, une adresse email déjà présente en base ne produit pas d'erreur :
la ligne bascule en **mise à jour**, et `importUpdate()` écrase `first_name`, `last_name` et `dob`
de la fiche qui porte cette adresse. Saisir par mégarde l'email de Jean sur la ligne de Marie —
l'erreur exacte que fait un couple partageant une boîte, puisque deux adultes ne peuvent pas
partager une adresse — renomme la fiche de Jean en Marie, avec l'historique d'inscriptions de Jean.

Le seul signal avant validation est le compteur de l'aperçu : « 42 lignes · 41 nouveaux ·
**1 mise à jour** · 0 erreur ». Un chiffre, sans dire *qui* ni *quoi* est écrasé — et sur un import
de rentrée où des mises à jour sont attendues de toute façon, il ne ressort pas.

Ce qui rend ce piège différent des deux autres relevés le même jour (rôle `athlete` forcé, bornes
de date divergentes) : **ceux-là font du bruit, celui-ci est muet**. Il n'échoue pas, il réussit
mal. Noter que le comportement lui-même n'est pas fautif — la mise à jour sur email est une
décision produit assumée et documentée dans l'en-tête du service. C'est son **absence de
matérialisation dans l'aperçu** qui pose problème.

**Ce qu'on fait aujourd'hui.** Rien dans l'outil. La parade est humaine, et désormais écrite dans
[INSTALL.md](INSTALL.md) : si le nombre de mises à jour annoncé par l'aperçu ne correspond pas à ce
qu'on attend, ne pas importer et relire les adresses du fichier. Après coup, l'opération est tracée
(`AuditLog member_updated` + `ActivityLog member_imported_update`), donc **détectable** — mais les
journaux ne conservent pas les valeurs d'avant : pas de rétablissement automatique, il faut
connaître le nom d'origine.

Dégâts circonscrits, au moins : `importUpdate` ne touche ni l'email, ni les rôles, ni les
qualifications, ni le lien de tutelle, et aucune invitation n'est renvoyée (seules les créations
alimentent `created_ids`).

**La piste.** Ne rien changer au comportement — juste le **rendre visible avant le clic** : nommer
les fiches concernées dans l'aperçu, « 1 mise à jour : Jean Dupont → Marie Dupont », au lieu d'un
compteur nu. L'information est déjà disponible au moment de l'analyse (`classify()` connaît
`existing_id`), il ne manque que la jointure et l'affichage. À voir si le diff mérite d'être limité
aux lignes dont le **nom change** — une mise à jour qui ne modifie que la date de naissance est
banale en début de saison, la lister à chaque fois noierait le signal.

Si le carnet donne lieu à un chantier « fiabiliser l'import », cette entrée se traite avec les
points 2 et 3 de l'entrée sur les parents non-athlètes : même écran, même service.

**Traces.** [MemberService::importUpdate()](../app/Services/MemberService.php) (contrat explicite :
ne touche ni email, ni rôles, ni tutelle) · [MemberImportService::classify()](../app/Services/MemberImportService.php)
(bascule `update` sur email connu) · [member-list.blade.php](../resources/views/livewire/admin/member-list.blade.php)
(aperçu et compteurs de la modale) · [INSTALL.md](INSTALL.md) §4 (avertissement à l'exploitant).

---

## 2026-09-04 — La tutelle ne se reprend pas, et `is_minor` ne dit pas ce qu'on croit

**Ce qu'on observe.** Cinq constats distincts qui convergent vers la même impasse : le lien de
tutelle se pose à la création et ne se reprend plus, sur la foi d'un `is_minor` qui répond à une
autre question que celle qu'on lui pose.

1. **Aucun changement de garant.** `guardian_id` ne s'écrit qu'à la création
   ([MemberCreate](../app/Livewire/Admin/MemberCreate.php)) ou à l'import. Ensuite, deux gestes
   seulement : `sever()` et `link()` — ce dernier refusant tout mineur qui a déjà un garant.
   Changer de garant, c'est donc rompre puis rattacher. Or `sever()` refuse un **P1 mineur** — à
   raison : sans garant ni compte propre, l'enfant deviendrait ingérable — et le bouton est masqué
   en conséquence ([member-show.blade.php:159](../resources/views/livewire/admin/member-show.blade.php)).
   Pour un P1, c'est-à-dire le cas courant de l'enfant sans email, **le garant est définitif**.
   Divorce, décès, changement de responsable légal, erreur de saisie : aucune issue dans l'outil.
   La fiche adhérent conseille d'ailleurs, ligne 242, « autonomiser ou **reparenter** d'abord » —
   un geste qui n'existe nulle part.

2. **`is_minor` est un âge de saison, pas l'âge légal.** `AgeCategory::isMinor()` s'appuie sur
   `seasonAge()`, qui évalue l'âge atteint **à la clôture de la saison** (31 août pour sept→août).
   Un adhérent né le 25 août 2009 est donc marqué `is_minor = 0` dès le 1er septembre 2026, alors
   qu'il a 17 ans et n'atteindra la majorité qu'en août 2027 : **mineur pendant onze mois de la
   saison, majeur pour l'application dès le premier jour**. Les conséquences s'enchaînent, toutes
   soit bloquantes, soit muettes :
   - `MemberCreate` refuse `guardian_id` (`prohibited`) — impossible de déclarer son garant à la
     saisie ;
   - à l'import, sa ligne exige un email (« email requis pour un adulte ») et son `parent_email`
     est **ignoré sans un mot**, la tutelle disparaît sans produire d'erreur ;
   - `link()` le refuse également ;
   - s'il était déjà sous tutelle, sa fiche affiche « Ce pupille est **majeur** : romps le lien de
     tutelle pour le rendre indépendant » — l'application invite l'admin à couper la tutelle d'un
     mineur de 17 ans ;
   - et s'il est en P1, l'impasse est complète : `invite()` exige `is_minor`, donc pas
     d'autonomisation possible, tandis que la rupture le laisserait sans garant **et** sans accès.

   Le calcul n'est pas fautif : il est juste pour la **catégorie sportive** (§4.5 — l'année
   sportive est la bonne unité, un athlète court toute la saison dans la catégorie de l'âge qu'il
   y atteindra). Le problème est qu'un même booléen sert aussi à la **tutelle** (§4.2), où seul
   l'âge légal du jour a un sens. Une réponse, deux questions.

3. **`is_minor` ne vieillit pas.** Il n'est écrit qu'à la création, à l'édition de la date de
   naissance et à l'import. La bascule de saison recalcule la **catégorie**
   ([SeasonService.php:117](../app/Services/SeasonService.php)) mais **pas** `is_minor` : la valeur
   reste celle du jour de la saisie. Un enfant inscrit à 10 ans reste `is_minor = 1` en base
   longtemps après ses 18 ans, jusqu'à ce que quelqu'un rouvre sa date de naissance. Le décalage du
   point 2 se fige donc aussi bien dans un sens que dans l'autre, et rien ne le rattrape.

4. **Impossible de rattacher un majeur à un garant.** Trois verrous concordants (`link()`,
   la validation `prohibited` de `MemberCreate`, `MemberService::create()` qui force `null`).
   Pourtant le modèle le supporte déjà sans rien changer : le routage des notifications déduit la
   phase du seul couple `(guardian_id, email)` et **ne lit jamais `is_minor`**
   ([NotificationDispatcher.php:193-216](../app/Notifications/NotificationDispatcher.php)), tout
   comme `SessionPolicy::122` et `RegistrationService::80`. Le besoin est réel — majeur protégé,
   adhérent en situation de handicap accompagné par un proche — et la mécanique existe déjà : elle
   est simplement interdite à l'entrée.

5. **Et par ricochet : le « compte famille » existe déjà, sans avoir été décidé.** Lever le verrou
   du point 4 ne fait pas qu'ouvrir le cas du majeur protégé — il produit mécaniquement le compte
   unique par foyer. Parent A détient l'email et le compte, parent B lui est rattaché sans adresse :
   A voit B dans son sélecteur de sujet, l'inscrit, reçoit ses convocations, et B conserve sa propre
   identité d'athlète (catégorie, quotas, historique, lignes d'audit). C'est la réponse directe à
   une contrainte déjà relevée le 3 septembre — `users.email` est `unique`, donc **deux adultes ne
   peuvent pas partager une boîte**, alors que beaucoup de foyers n'en ont qu'une. Le besoin est
   donc réel, mais l'obtenir ainsi ferait porter deux choses différentes à une même arête du
   graphe, avec quatre effets qu'aucun `if` relâché ne règle :
   - **le vocabulaire**, partout : B s'afficherait sous « Mes enfants », dans une carte
     « Pupilles · 2 enfants sous tutelle », avec « Parent garant · A » sur sa fiche. Une dizaine de
     vues, plus les libellés de notification ;
   - **la base juridique**, qui n'est pas la même : l'autorité parentale fonde qu'un tiers agisse
     et reçoive à la place d'un mineur ; entre deux adultes, c'est une **délégation consentie**, et
     l'outil n'offre aucun endroit où B l'accorde, ni où il la retire ;
   - **les préférences de notification de B**, court-circuitées : n'étant jamais destinataire
     (pas d'email), c'est la matrice de **A** qui décide pour lui — voulu pour un enfant P1,
     discutable pour un conjoint ;
   - **les chaînes** : `link()` vérifie que le garant est adulte, actif et non anonymisé, mais pas
     qu'il n'est pas lui-même pupille. Inerte aujourd'hui — un pupille sans email ne se connecte
     pas — mais à garder si on ouvre la porte aux adultes.

**Ce qu'on fait aujourd'hui.** Deux contournements, selon le cas.

**a) Rattacher un mineur que l'âge de saison déclare majeur (point 2) — sans quitter l'outil.**
Aller-retour sur la date de naissance, en trois gestes **enchaînés** : créer la fiche avec une date
qui le place franchement sous les 18 ans de saison (16 ans, par exemple), poser le garant dans la
foulée, puis rétablir la vraie date depuis la fiche. `updateDob()` recalcule `is_minor` et la
catégorie principale mais **ne touche ni `guardian_id` ni `guardianship_linked_at`**
([MemberService.php:143-175](../app/Services/MemberService.php)) : le lien survit à la correction.
Enchaîner les trois gestes avant toute inscription — entre-temps, la catégorie dérivée est celle de
la fausse date. Trois pièges en découlent, tous dus au `is_minor = 0` final et non à la manœuvre :

- **Le bouton de rupture s'arme, et il est chez le parent.** La carte enfant teste
  `phase === 'P1' && is_minor` ([child-card.blade.php:53](../resources/views/livewire/partials/child-card.blade.php)) :
  « Accès autonome » devient « **Rompre la tutelle** » sur l'écran « Mes enfants » du garant, qui
  n'a aucune raison de s'en méfier. Un clic, et le compte est orphelin définitif. Prévenir le
  parent.
- **Les réglages d'authentification du club se bloquent.** `lockedOutBy()` exempte les P1 sur le
  critère `is_minor = true` **et** `email IS NULL`
  ([AuthMethodService.php:118-121](../app/Services/AuthMethodService.php)) : sans mot de passe, sans
  email et sans identité OAuth, ce compte est compté comme verrouillé dehors, et
  [ClubSettingsForm.php:189-195](../app/Livewire/Admin/ClubSettingsForm.php) **refuse** dès lors de
  couper le magic link ou Google — « 1 compte(s) actif(s) n'auraient plus aucun moyen de se
  connecter » — pour un compte qui n'a aucun accès à perdre. Refus valable pour les deux
  interrupteurs, tant que l'état dure. Déblocage : lui poser un `password` en base, ce qui ne lui
  ouvre rien puisqu'il n'a pas d'identifiant de connexion.
- **L'autonomisation est fermée** (`invite()` exige `is_minor`), précisément pour le jour de ses
  18 ans réels, en cours de saison. Sortie : rejouer l'aller-retour — date à 16 ans, « Accès
  autonome », date correcte.

Le journal ne racontera rien de tout cela : deux lignes, la création puis `member_updated` motif
`dob_changed`, sans les valeurs antérieures. Noter le cas hors de l'outil pour qui reprendra le
dossier.

**b) Tout le reste — changer de garant, rattacher un majeur — passe par la base.**
Le lien n'a qu'une seule copie (`users.guardian_id` + `guardianship_linked_at`) et tout le reste le
lit à la volée — aucun cache, aucune dénormalisation, aucun compteur agrégé —, donc l'effet est
immédiat et propre :

```sql
UPDATE users
   SET guardian_id = <id_garant>, guardianship_linked_at = NOW()
 WHERE id = <id_pupille>;
```

Restent à vérifier **à la main** les invariants que `link()` posait : pupille non anonymisé, garant
adulte actif non anonymisé, pas d'auto-référence ni de cycle. La FK est auto-référente en
`nullOnDelete` : elle garantit que l'id existe, rien d'autre. Trois pertes à assumer — l'absence de
trace (`guardianship_linked` / `guardianship_severed` en `audit_logs`, à réinsérer à la main sur un
lien de tutelle si l'on tient à l'auditabilité), l'absence de notification aux parties, et les
lignes `notification_outbox` déjà `pending` au nom de l'ancien garant, qui partiront quand même
(le destinataire y est résolu au dispatch).

⚠️ **Le rattachement forcé d'un majeur est un aller sans retour.** Sur ce profil, la fiche propose
la rupture (`sever()` ne garde que les mineurs) — mais elle laisse alors un compte sans garant
**et** sans email, que plus personne ne peut ré-associer puisque `link()` exige `is_minor`. Même
issue si le garant est supprimé : `MemberService.php:471` détache ses pupilles. Un admin bien
intentionné, ou une simple suppression RGPD du parent, produit un compte orphelin définitif.

**La piste.** Le fond commun aux cinq constats est un manque de **reprise en main côté admin** :
le modèle de tutelle est correct, c'est son outillage qui suppose que rien ne bouge après la
création. Cinq directions, à arbitrer ensemble :

- **Séparer les deux questions d'âge.** Garder `seasonAge()` pour la catégorie, et fonder la
  minorité de la tutelle sur l'âge légal au jour dit. Accessoirement, cesser de figer la réponse :
  une minorité **dérivée à la lecture** de `dob` supprime d'un coup le point 3 — reste à mesurer ce
  que la colonne stockée porte comme requêtes avant de la retirer.
- **Une action « changer de garant »** atomique (rupture + rattachement dans une transaction).
  Atomique, elle peut lever la garde P1 sans danger : le trou qu'elle protège — l'enfant sans
  garant ni accès — n'existe jamais entre les deux écritures.
- **Autoriser `link()` sur un majeur sans compte propre**, ce qui rend l'état du point 4 légitime
  et surtout réversible, au lieu du piège actuel. Réversible suppose ici de relâcher aussi
  `invite()`, qui exige `is_minor` : sans quoi un adulte rattaché ne pourra jamais ouvrir son
  propre compte, et la seule sortie restera la rupture — donc l'orphelin.
- **Nommer la relation pour ce qu'elle est**, si le point 5 est retenu : passer d'un lien de
  *tutelle* à une **gestion déléguée**, dont la tutelle parentale devient un cas particulier. Un
  motif porté par le lien (`tutelle_parentale` | `delegation_adulte`) suffirait à piloter le
  vocabulaire des écrans, l'accord de la personne concernée et les gestes offerts, sans toucher au
  graphe lui-même. Le travail est modeste ; c'est la **décision produit** qui doit être explicite,
  pas un effet de bord.
- **Tracer et notifier comme aujourd'hui** : quel que soit le geste retenu, il reste un
  `AuditLog` + une notification aux parties — c'est ce qu'on perd avec le contournement SQL, et
  c'est précisément ce qu'un contrôle viendrait chercher sur un lien de tutelle.

À noter au passage, sans rapport avec le fond : la seconde colonne de la fiche adhérent affiche
encore un bloc « Lien de tutelle » en lecture seule annonçant « Gestion de la tutelle — bientôt
disponible » avec un bouton désactivé (ligne 334), alors que la carte *Tutelle* de la première
colonne fait le travail depuis J7.7. Les deux s'affichent simultanément.

**Traces.** PRD §4.2 (P1/P2/P3, transitions), §4.5 (âge de saison et catégories) ·
[GuardianshipService.php](../app/Services/GuardianshipService.php) (`invite`, `sever`, `link` et
leurs gardes) · [AgeCategory.php](../app/Support/AgeCategory.php) (`seasonAge`, `isMinor`) ·
[SeasonService.php](../app/Services/SeasonService.php) (recalcul des catégories à la bascule, sans
`is_minor`) · [MemberService.php](../app/Services/MemberService.php) (`create`, `updateDob`,
détachement des pupilles à l'anonymisation) ·
[MemberImportService.php](../app/Services/MemberImportService.php) (« email requis pour un adulte »,
lien de tutelle réservé aux mineurs) ·
[NotificationDispatcher.php](../app/Notifications/NotificationDispatcher.php) (`recipients()` :
phase déduite de `(guardian_id, email)`) ·
[SubjectContext.php](../app/Support/SubjectContext.php) (`wards()` : ni filtre d'âge ni filtre de
phase) · [AuthMethodService.php](../app/Services/AuthMethodService.php) et
[ClubSettingsForm.php](../app/Livewire/Admin/ClubSettingsForm.php) (exemption P1 du décompte des
comptes verrouillés dehors, refus de couper un moyen d'auth) ·
[child-card.blade.php](../resources/views/livewire/partials/child-card.blade.php) (bascule
« Accès autonome » → « Rompre la tutelle » sur `is_minor`) · [ParentChildren.php](../app/Livewire/ParentChildren.php) et
[parent-children.blade.php](../resources/views/livewire/parent-children.blade.php) (vocabulaire
« Mes enfants ») · entrée du 2026-09-03 sur l'import CSV (l'email unique et le couple qui partage
une boîte) ·
[member-show.blade.php](../resources/views/livewire/admin/member-show.blade.php) (carte Tutelle,
masquage de la rupture, bloc résiduel « bientôt disponible »).

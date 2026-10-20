# Kit RGPD pour le club

Deux documents prêts à l'emploi, à compléter avec les informations du club : un **texte
d'information** pour les adhérents, et la **fiche du traitement** à reporter dans le registre du
club. Les passages entre crochets — `[Nom du club]` — sont à remplacer.

> **Qui est responsable ?** Le **club**. Il installe l'application sur son propre hébergement et
> décide de son usage : c'est lui le *responsable de traitement* au sens du RGPD. L'auteur du
> logiciel n'a accès à aucune donnée. Ce kit décrit ce que fait l'application ; il ne remplace pas
> un conseil juridique. La CNIL publie un
> [guide RGPD pour les associations](https://www.cnil.fr/fr/mon-quotidien/associations) et un
> [modèle de registre](https://www.cnil.fr/fr/RGDP-le-registre-des-activites-de-traitement).

**À faire avant d'ouvrir l'application aux adhérents :**

1. Compléter les **mentions légales** dans l'application : *Admin → Paramètres du club → Mentions
   légales* ([guide d'installation](INSTALL.md), §4).
2. Envoyer le **texte d'information** ci-dessous aux adhérents, ou l'ajouter au bulletin
   d'adhésion.
3. Reporter la **fiche du traitement** ci-dessous dans le registre du club.
4. Vérifier que le contrat de **sous-traitance** (souvent appelé *DPA*) de l'hébergeur et du
   service d'email est accepté. Chez OVH et Brevo, il fait partie des conditions générales.

---

## 1. Texte d'information pour les adhérents

À copier dans un email, sur le site du club ou dans le bulletin d'adhésion.

> **Le planning d'entraînement de [Nom du club] et vos données**
>
> Pour organiser les séances, [Nom du club] utilise une application de planning, installée sur
> l'hébergement du club chez [Nom de l'hébergeur, ex. OVH] : vous y consultez les séances, vous vous
> y inscrivez, et vous recevez les annulations et les changements.
>
> **Ce que l'application enregistre** : votre nom, votre prénom, votre adresse email, votre date de
> naissance (pour votre catégorie d'âge), votre rôle au club (athlète, coach, membre du bureau),
> vos inscriptions aux séances et votre historique de participation. Pour un adhérent mineur :
> l'identité de son représentant légal, qui crée et gère son compte. Si vous le souhaitez, vous
> pouvez aussi y renseigner votre VMA ; elle n'est visible que par vous.
>
> **Ce qu'elle n'enregistre jamais** : ni numéro de téléphone, ni certificat médical, ni numéro de
> licence.
>
> **Pourquoi** : uniquement pour organiser les entraînements et la vie sportive du club, dans le
> cadre de votre adhésion. Aucune publicité, aucune revente, aucun outil de mesure d'audience.
>
> **Qui voit quoi** : les membres du bureau gèrent les comptes ; les coachs et les autres adhérents
> voient les noms des inscrits à une séance. Les emails sont envoyés par [Nom du service d'email,
> ex. Brevo], un prestataire européen.
>
> **Combien de temps** : le temps de votre adhésion. À votre départ, ou à votre demande, votre
> compte est supprimé sept jours plus tard au plus tôt ; les traces de vos inscriptions passées
> sont alors anonymisées, et les débriefs que vous avez rédigés restent publiés sans votre nom.
>
> **Vos droits** : vous pouvez consulter et corriger vos informations depuis votre profil, et
> demander la suppression de votre compte depuis ce même profil. Pour toute autre demande, écrivez
> à [adresse email de contact du club]. Si vous estimez que vos droits ne sont pas respectés, vous
> pouvez saisir la CNIL (cnil.fr).

**Si le club active des options**, ajouter la phrase correspondante :

- *Connexion avec Google* : « Vous pouvez, si vous le souhaitez, vous connecter avec votre compte
  Google ; Google est alors informé de cette connexion. »
- *Parcours OpenRunner* : « L'affichage de certains parcours fait appel au service OpenRunner, qui
  reçoit alors l'adresse IP de votre appareil. »
- *Albums photos* : un album photo est un lien vers un service choisi par le club (l'application ne
  stocke pas les photos). Le droit à l'image des personnes photographiées se gère comme pour tout
  album du club.

---

## 2. Fiche pour le registre des traitements

Une ligne du registre, dans l'ordre des rubriques du modèle de la CNIL.

| Rubrique | À reporter |
|---|---|
| **Nom du traitement** | Planning d'entraînement et inscriptions aux séances |
| **Responsable** | [Nom du club], [adresse du siège], représenté par [président·e] |
| **Contact pour les données** | [adresse email de contact du club] |
| **Finalités** | Publier le planning des séances ; gérer les inscriptions, la liste d'attente et les quotas ; prévenir les inscrits (annulation, place libérée, ouverture des inscriptions à une compétition) ; partager les débriefs de course ; établir les statistiques de fréquentation du bureau |
| **Base légale** | Exécution du contrat d'adhésion au club. Options facultatives (notifications sur le téléphone, connexion Google, VMA) : choix de l'adhérent, qui peut y renoncer à tout moment depuis son profil |
| **Personnes concernées** | Adhérents (adultes et mineurs), coachs, membres du bureau, représentants légaux des mineurs |
| **Données** | Nom, prénom, email, date de naissance ; rôles et qualifications au club ; lien entre un mineur et son représentant légal ; inscriptions et historique de participation ; textes et réactions publiés dans l'application ; VMA si l'adhérent la saisit ; données techniques de connexion (adresse IP et navigateur pendant la session, abonnement aux notifications de l'appareil) ; journal des actions faites dans l'application |
| **Données sensibles** | Aucune. Ni téléphone, ni certificat médical, ni numéro de licence |
| **Destinataires** | Membres du bureau (gestion des comptes) ; coachs et adhérents (noms des inscrits aux séances) |
| **Sous-traitants** | Hébergement : [Nom de l'hébergeur, ex. OVH — France]. Envoi des emails : [Nom du service, ex. Brevo — France]. Notifications sur le téléphone : service du navigateur de l'adhérent (Google, Apple ou Mozilla), qui transmet un message chiffré qu'il ne peut pas lire |
| **Services sans donnée personnelle** | Météo : Open-Meteo (coordonnées du lieu de séance). Adresses : Photon / OpenStreetMap (adresse d'un lieu, saisie par un coach) |
| **Transferts hors Union européenne** | Aucun pour les données du club. Notifications sur le téléphone : le service du navigateur (Google, Apple) peut être situé hors UE ; il ne reçoit qu'un message chiffré et l'adresse de l'appareil. Connexion Google, si le club l'active (Google, États-Unis) |
| **Durée de conservation** | Le temps de l'adhésion. Le bureau supprime le compte au départ de l'adhérent ou à sa demande (*Admin → Adhérents*) : suppression effective sept jours après la demande, puis anonymisation des traces. Il n'y a pas de suppression automatique : prévoir une revue des comptes à chaque début de saison. Liens de connexion et invitations : effacés automatiquement à expiration. Sauvegardes : selon la politique de l'hébergeur |
| **Sécurité** | Connexion chiffrée (HTTPS) ; mots de passe stockés sous une forme illisible, que le bureau ne peut ni voir ni choisir ; liens de connexion à usage unique et à durée limitée ; accès selon le rôle (athlète, coach, administrateur) ; journal des actions des administrateurs ; aucun traqueur ni outil de mesure d'audience ; sauvegardes de la base ([guide d'installation](INSTALL.md), §9) |

---

## Pour aller plus loin

- **[Guide d'installation](INSTALL.md), §8 « Flux réseau sortants »** — le détail technique de
  chaque service contacté par l'application.
- **[Guide d'installation](INSTALL.md), §9.4 « Récupérer les données du club »** — exports Excel
  et sauvegarde complète.
- **[Formulaire de contact](https://stephane.ratelet.fr/#contact)** — une question sur ce kit.

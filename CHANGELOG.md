# Journal des modifications

Toutes les évolutions notables de Club'O'Clock sont consignées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), et le projet applique le
[versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

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

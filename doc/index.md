# Documentation

Club'O'Clock est une application web installable (PWA) de gestion du planning
d'entraînement, pensée pour un **club sportif associatif de 50 à 150 adhérents administré
par des bénévoles**.

Chaque club installe **sa propre instance** : ses données restent chez lui, et il n'existe
aucun compte central. C'est le principe qui structure aussi cette documentation — elle
s'adresse à celui qui va héberger, exploiter et faire vivre l'application.

## Par où commencer

| Vous voulez… | Lire |
|---|---|
| **Voir l'application** sans rien installer | La [démo publique](https://demo.cluboclock.ratelet.fr/), ou [l'application en images](CAPTURES) |
| **Installer une instance** pour votre club | [Installation & déploiement](INSTALL) |
| **Explorer le jeu de démonstration** | [Comptes de démonstration](COMPTES_DEMO) |
| **Comprendre ce que fait le produit**, et ce qu'il ne fait pas | [Spécification produit](PRD) |
| **Comprendre les choix techniques** et ce qu'ils coûtent | [Cadrage technique](CADRAGE_TECHNIQUE) |
| **Recetter votre instance** avant l'ouverture aux adhérents | [Plan de tests — membres](PLAN_TESTS_MEMBRES) |
| **Contribuer** au projet | [Guide de contribution](../CONTRIBUTING) |

## Les documents

### Démarrer

- **[Installation & déploiement](INSTALL)** — du poste de développement à la production.
  Prérequis, configuration (email, OAuth, notifications push), premier démarrage,
  déploiement sur mutualisé ou sur VPS, maintenance et dépannage.
- **[Comptes de démonstration](COMPTES_DEMO)** — le jeu de données du club fictif
  « TEAM44 » : qui est qui, quels cas chaque compte illustre, et la semaine type seedée.
- **[L'application en images](CAPTURES)** — treize écrans commentés, côté adhérent, coach
  et bureau.

### Comprendre

- **[Spécification produit](PRD)** — la source de vérité fonctionnelle. Rôles, cycle de vie
  des comptes mineurs, quotas, liste d'attente, encadrement, notifications, traçabilité.
  Précise aussi ce qui est **hors périmètre**, et pourquoi.
- **[Cadrage technique](CADRAGE_TECHNIQUE)** — les décisions techniques et leurs
  contreparties assumées : pourquoi un monolithe, pourquoi pas de SPA, ce que l'hébergement
  mutualisé impose.

### Recetter

- **[Plan de tests — membres](PLAN_TESTS_MEMBRES)** — parcours de recette entièrement en
  interface, sans ligne de commande. Destiné aux testeurs du club.
- **[Plan de tests — technique](PLAN_TESTS)** — la même recette côté organisateur, avec
  accès aux journaux, aux commandes Artisan et à la base.

### Contribuer

- **[Guide de contribution](../CONTRIBUTING)** — porte de qualité, conventions, périmètre.
- **[Politique de sécurité](../SECURITY)** — signaler une vulnérabilité.
- **[Journal des versions](../CHANGELOG)** — ce qui change à chaque version.

## Bon à savoir

**Le projet est en français** — code, commentaires, interface et documentation. Ce n'est pas
un oubli d'internationalisation : c'est un choix assumé pour la version 1.

**L'administration est conçue pour le bureau, pas pour le téléphone.** L'application est
majoritairement consultée au mobile par les adhérents, mais les écrans d'administration
supposent un grand écran.

**Aucune donnée sensible n'est stockée** : ni numéro de téléphone, ni certificat médical, ni
numéro de licence fédérale. C'est une contrainte de conception, pas un réglage.

---

> Une question sans réponse dans ces pages ? Les
> [discussions GitHub](https://github.com/stephanfo/club-o-clock/discussions) sont ouvertes.
> Merci de n'y faire figurer **aucune donnée réelle d'adhérent**.

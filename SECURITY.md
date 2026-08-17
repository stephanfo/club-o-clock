# Politique de sécurité

## Signaler une vulnérabilité

**N'ouvre pas d'issue publique** pour une faille de sécurité : une instance de Club'O'Clock héberge
des données personnelles d'adhérents, parfois mineurs. Une divulgation publique avant correctif
expose tous les clubs qui l'ont déployée.

Utilise le signalement privé de GitHub :

> **Onglet [Security](https://github.com/stephanfo/club-o-clock/security) du dépôt → « Report a
> vulnerability »**

Le fil de discussion reste privé entre toi et le mainteneur jusqu'à publication du correctif. Un
compte GitHub (gratuit) suffit.

### Ce qui aide à traiter vite

- le **type** de faille et le chemin d'exploitation ;
- le **rôle** nécessaire pour l'exploiter (visiteur non connecté, athlète, coach, admin) — c'est le
  premier facteur de gravité ;
- une reproduction pas à pas, si possible sur le **jeu de démonstration** ;
- la version ou le commit concerné.

> ⚠️ **Aucune donnée réelle d'adhérent** dans un rapport : ni nom, ni email, ni capture non
> floutée. Reproduis sur des données de démonstration.

## Délais

Projet maintenu **bénévolement, par une seule personne**. Les délais annoncés sont tenus de bonne
foi, sans engagement contractuel :

| Étape | Délai visé |
|---|---|
| Accusé de réception | 7 jours |
| Évaluation et qualification | 30 jours |
| Correctif pour une faille critique | dès que possible après qualification |

Si tu n'as pas de réponse au bout de 30 jours, relance — le signalement a pu passer inaperçu.

## Divulgation

Divulgation **coordonnée** : publication du correctif d'abord, du détail ensuite. Sauf demande
contraire de ta part, tu seras crédité·e dans l'avis de sécurité et le [CHANGELOG](CHANGELOG.md).

## Versions supportées

Seule la **dernière version publiée** reçoit des correctifs de sécurité. Le modèle est
one-instance-per-club : chaque club met à jour son installation depuis la branche `main`.

| Version | Supportée |
|---|---|
| Dernière release | ✅ |
| Versions antérieures | ❌ |

## Périmètre

**Dans le périmètre** — le code de ce dépôt : contournement d'authentification ou d'autorisation
(accéder aux données d'un autre adhérent, agir sans en avoir le rôle), injection SQL, XSS, CSRF,
fuite de données personnelles, faille dans la gestion des tokens (liens magiques, invitations).

**Hors périmètre** — ce qui relève de l'exploitant de l'instance, pas du code :

- une instance mal configurée (`.env` exposé, `APP_DEBUG=true` en production, racine web pointant
  ailleurs que sur `public/`) ;
- l'hébergement, le serveur web, la base de données, le certificat TLS ;
- les vulnérabilités des dépendances tierces — signale-les **en amont**, chez le projet concerné ;
- l'absence d'en-têtes de durcissement optionnels, sans impact démontré.

## Responsabilité de l'exploitant

Chaque club exploite **sa propre instance** et reste responsable de ses données au sens du RGPD.
Points de vigilance : appliquer les mises à jour, garder `APP_DEBUG=false` en production, servir le
site en HTTPS, exposer `public/` et rien d'autre, sauvegarder régulièrement (cf.
[doc/INSTALL.md](doc/INSTALL.md) §7).

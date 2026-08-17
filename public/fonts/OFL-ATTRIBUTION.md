# Polices auto-hébergées

Le fichier de ce dossier est une variable font extraite du sous-ensemble
`latin` de Google Fonts, sous licence [SIL Open Font License 1.1](https://scripts.sil.org/OFL)
(libre redistribution, y compris embarquée dans une application).

| Fichier | Police | Auteur | Plage `wght` couverte |
|---|---|---|---|
| `manrope-latin-variable.woff2` | Manrope | Mikhail Sharanda | 200–800 |

Auto-hébergement retenu (plutôt qu'un `@import` Google Fonts) pour éviter tout
appel réseau vers un serveur hors UE au chargement de l'app (RGPD, données de
mineurs) — cf. `resources/css/club-tokens.css`.

> Archivo / Archivo Narrow (titres) ont été retirées le 2026-08-06 : `--font-display`
> pointe désormais sur la police système, divergence assumée par l'utilisateur par
> rapport au design de référence (`design/`, titres condensés Archivo Narrow).

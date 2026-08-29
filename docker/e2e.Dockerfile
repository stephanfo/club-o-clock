# Image du harnais E2E — poste de développement uniquement (cf. docker/php.Dockerfile).
#
# Playwright a besoin de navigateurs complets et de leurs dépendances système : plus d'un Go de
# binaires que l'image officielle Microsoft fournit déjà installés et testés ensemble.
#
# PHP est ajouté par-dessus parce que le harnais ne se contente pas de cliquer : tests/E2E/lib.mjs
# appelle `php auth.php` (ouverture de session par magic link) et `php sql.php` (vérification de
# l'état en base). Sans PHP dans la MÊME image, `session()` et `sql()` échouent au premier scénario.
#
# La version de l'image DOIT suivre celle du paquet `playwright` de package.json : Playwright refuse
# de piloter des navigateurs dont la révision ne correspond pas à celle qu'il attend.
FROM mcr.microsoft.com/playwright:v1.62.1-noble

# PHP CLI d'Ubuntu (8.3) et les extensions dont l'amorçage de Laravel a besoin. La version n'a pas
# à égaler celle de l'application : ces deux scripts ne font qu'ouvrir une session et lire la base.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      php8.3-cli php8.3-mysql php8.3-gd php8.3-mbstring php8.3-xml php8.3-zip \
      php8.3-curl php8.3-intl php8.3-bcmath php8.3-gmp \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app

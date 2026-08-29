# Image PHP du poste de développement — JAMAIS de la production.
#
# Club'O'Clock se déploie sur un hébergement mutualisé, sans Docker (cf. doc/CADRAGE_TECHNIQUE.md).
# Cette image sert uniquement à faire tourner l'application et les commandes du dépôt SANS installer
# PHP, ses extensions et un client MySQL sur la machine de l'auteur. Elle ne change rien à la cible
# de déploiement, et rien ici ne doit devenir un prérequis d'exécution.
#
# Le code n'est pas copié : il arrive par un montage (`-v "$PWD":/app`), pour qu'un `git checkout`
# se voie immédiatement dans le conteneur sans reconstruire quoi que ce soit.
#
# Voir doc/DOCKER_LOCAL.md pour la construction, le lancement et les pièges.
FROM php:8.4-cli

# Les extensions attendues par le projet (cf. doc/INSTALL.md §1). `install-php-extensions` évite
# d'énumérer à la main les -dev de chaque bibliothèque système.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions gd zip intl bcmath gmp pdo_mysql exif

# memory_limit : PHPStan analyse le projet entier et dépasse la valeur par défaut.
# variables_order : `artisan serve` a besoin de $_ENV pour lire les variables passées par `docker run`.
RUN printf 'memory_limit=1G\nvariables_order=EGPCS\n' > /usr/local/etc/php/conf.d/zz-app.ini

# Client en ligne de commande, pour inspecter la base depuis le conteneur — et parce que Laravel
# s'en sert pour charger le dump de schéma au début de `migrate`.
#
# ⚠️ Sur Debian, `default-mysql-client` installe le client MARIADB (`mysqldump --version` répond
# « from …-MariaDB »). Il lit et écrit très bien la base, mais `php artisan schema:dump` lancé ici
# contre MySQL produirait un dump en dialecte MariaDB, illisible par l'intégration continue.
# Le dump MySQL se régénère avec le client d'Oracle — cf. CONTRIBUTING.md et doc/DOCKER_LOCAL.md §3.
#
# ssl-verify-server-cert : le serveur MySQL de développement présente un certificat auto-signé.
RUN apt-get update \
 && apt-get install -y --no-install-recommends default-mysql-client \
 && rm -rf /var/lib/apt/lists/* \
 && printf '[client]\nssl-verify-server-cert=0\n' > /root/.my.cnf

# Composer, pour que la porte de qualité (`composer check`) soit jouable dans le conteneur.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

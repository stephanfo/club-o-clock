#!/usr/bin/env bash
#
# Assemble le site complet dans _site/ : la vitrine à la racine, la documentation sous /doc/.
#
#   ./site/build-local.sh            construit
#   ./site/build-local.sh --serve    construit puis sert sur http://localhost:8080
#
# Ce script est la RÉFÉRENCE du workflow GitHub Actions : ce qui marche ici doit marcher en CI,
# parce que ce sont les mêmes étapes dans le même ordre. Toute divergence entre les deux est un
# bug en attente.

set -euo pipefail

cd "$(dirname "$0")/.."          # racine du dépôt
OUT="_site"

echo "→ Nettoyage"
rm -rf "$OUT" site/.vitepress/dist

# --- La police ---------------------------------------------------------------------------------
# L'application est la source de vérité du fichier : il n'est pas versionné une seconde fois ici.
# Le chemin diffère entre les deux contextes — /fonts/ servi par Laravel, /assets/ sur le site —
# et c'est la déclaration @font-face qui est réécrite, pas le fichier.
echo "→ Copie de la police depuis l'application"
mkdir -p site/public/assets
cp public/fonts/manrope-latin-variable.woff2 site/public/assets/

# --- Les captures de la vitrine ----------------------------------------------------------------
# doc/img/ reste la source unique : GitHub y lit les mêmes fichiers. On les expose sous /img/
# pour la page d'accueil, sans les dupliquer dans le dépôt.
echo "→ Copie des captures de la vitrine"
mkdir -p site/public/img
for f in planning-semaine seance-parcours mes-enfants admin-adherents; do
  cp "doc/img/$f.png" site/public/img/
done

# --- La documentation --------------------------------------------------------------------------
# Échoue sur lien mort : c'est voulu, cf. `ignoreDeadLinks` dans la configuration.
echo "→ Construction de la documentation"
npm run doc:build

# --- Montage -----------------------------------------------------------------------------------
echo "→ Montage de $OUT"
mkdir -p "$OUT"
cp -r site/.vitepress/dist/. "$OUT"/          # doc + assets publics (police, logos, og)
cp site/index.html site/style.css "$OUT"/     # la vitrine écrase l'index de VitePress

# Sans ce fichier, GitHub Pages passe la sortie dans Jekyll, qui ignore tout chemin commençant
# par un souligné.
touch "$OUT/.nojekyll"

echo "cluboclock.ratelet.fr" > "$OUT/CNAME"

cat > "$OUT/robots.txt" <<'ROBOTS'
User-agent: *
Allow: /

Sitemap: https://cluboclock.ratelet.fr/sitemap.xml
ROBOTS

# --- Vérifications -----------------------------------------------------------------------------
# Ces trois contrôles couvrent des pannes silencieuses : un site qui se construit sans erreur
# mais dont la police ne charge pas, ou dont les images manquent, n'a l'air cassé pour personne.
echo "→ Vérifications"
fail=0
for f in index.html style.css assets/manrope-latin-variable.woff2 assets/logo.svg \
         assets/favicon.svg assets/og.png img/planning-semaine.png doc/index.html doc/PRD.html; do
  if [ ! -e "$OUT/$f" ]; then echo "   ✗ manquant : $f"; fail=1; fi
done

# La police doit être référencée sur /assets/ : si la déclaration pointait encore /fonts/ (le
# chemin de l'application), le site retomberait en silence sur la police système.
if ! grep -q "/assets/manrope-latin-variable.woff2" "$OUT/style.css"; then
  echo "   ✗ style.css ne référence pas la police sur /assets/"; fail=1
fi

if grep -rqs "inter-roman-latin" "$OUT/assets"/*.css 2>/dev/null; then
  echo "   ✗ Inter est encore embarquée (theme-without-fonts ?)"; fail=1
fi

[ "$fail" -eq 0 ] && echo "   ✓ tout est en place"
[ "$fail" -eq 0 ] || { echo "Assemblage incomplet."; exit 1; }

echo
echo "Site assemblé dans $OUT/ ($(du -sh "$OUT" | cut -f1))"

if [ "${1:-}" = "--serve" ]; then
  echo "→ http://localhost:8080  (Ctrl+C pour arrêter)"
  # Pour vérifier le rendu mobile, utiliser la bascule d'appareil des outils de développement
  # du navigateur. À NE PAS faire : une capture Chrome `--headless --window-size=390,…` —
  # le mode headless impose un viewport d'au moins 500 px de large, rend la page pour 500 px
  # puis la capture à 390, ce qui simule un débordement qui n'existe pas.
  # Serveur minimal qui résout /doc/PRD vers doc/PRD.html, comme le fait GitHub Pages.
  # `python3 -m http.server` ne le fait PAS : il renverrait 404 sur toutes les pages et
  # donnerait l'illusion d'un site cassé.
  cd "$OUT" && python3 - <<'SERVE'
import http.server, os, socketserver

class H(http.server.SimpleHTTPRequestHandler):
    def translate_path(self, path):
        p = super().translate_path(path)
        if not os.path.exists(p) and not path.endswith('/') and os.path.exists(p + '.html'):
            return p + '.html'
        return p

    def log_message(self, *a):
        pass

with socketserver.TCPServer(('', 8080), H) as s:
    s.serve_forever()
SERVE
fi

// Îlot GPX (PRD §4.13.2) : parsing CLIENT UNIQUEMENT (jamais serveur → surface d'attaque réduite),
// tracé sur fond OpenStreetMap (Leaflet). Deux composants Alpine :
//   gpxField — zone d'upload du formulaire : valide (≤ 5 Mo, .gpx), parse, expose les métadonnées.
//   gpxMap   — fiche séance : récupère le GPX stocké, le parse, dessine le tracé OSM.
// Leaflet (JS + CSS) est chargé À LA DEMANDE (import dynamique dans gpxMap), pas en haut de bundle :
// la carte ne sert que sur les fiches GPX, l'inclure partout déclenchait un avertissement de preload
// CSS inutile sur toutes les autres pages.

const MAX_BYTES = 5 * 1024 * 1024; // 5 Mo (§4.13.2)

// Secteurs cardinaux en FRANÇAIS (O, pas W). Doit rester aligné sur GpxRoute::SECTORS côté PHP :
// le serveur recalcule le secteur depuis le cap et ferait autorité en cas de divergence.
const SECTORS = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'];
const LOOP_METERS = 250;          // départ ≈ arrivée → parcours en boucle
const MAX_POLYLINE_POINTS = 200;  // budget de simplification (le serveur retronque à 250)

// Distance haversine entre deux points (mètres).
function haversine(a, b) {
    const R = 6371000;
    const toRad = (d) => (d * Math.PI) / 180;
    const dLat = toRad(b.lat - a.lat);
    const dLon = toRad(b.lon - a.lon);
    const s = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLon / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(s));
}

// Parse un GPX (texte) → tracé + métadonnées. Lève si illisible.
export function parseGpx(text) {
    const doc = new DOMParser().parseFromString(text, 'application/xml');
    if (doc.querySelector('parsererror')) throw new Error('Fichier GPX illisible.');

    const nodes = [...doc.querySelectorAll('trkpt, rtept')];
    const pts = nodes.map((p) => {
        const ele = p.querySelector('ele');
        const time = p.querySelector('time');
        return {
            lat: parseFloat(p.getAttribute('lat')),
            lon: parseFloat(p.getAttribute('lon')),
            ele: ele ? parseFloat(ele.textContent) : null,
            time: time ? Date.parse(time.textContent) : null,
        };
    }).filter((p) => Number.isFinite(p.lat) && Number.isFinite(p.lon));

    if (pts.length < 2) throw new Error('Aucun tracé exploitable dans ce GPX.');

    let dist = 0;
    let dplus = 0;
    let dmoins = 0;
    let altMin = Infinity;
    let altMax = -Infinity;
    const eleThreshold = 3; // mètres : filtre le bruit altimétrique GPS

    let prevEle = null;
    for (let i = 0; i < pts.length; i++) {
        if (i > 0) dist += haversine(pts[i - 1], pts[i]);
        const e = pts[i].ele;
        if (Number.isFinite(e)) {
            altMin = Math.min(altMin, e);
            altMax = Math.max(altMax, e);
            if (prevEle !== null) {
                const d = e - prevEle;
                if (d >= eleThreshold) { dplus += d; prevEle = e; }
                else if (d <= -eleThreshold) { dmoins += -d; prevEle = e; }
            } else {
                prevEle = e;
            }
        }
    }

    const times = pts.map((p) => p.time).filter(Number.isFinite);
    const durationMin = times.length >= 2 ? Math.round((Math.max(...times) - Math.min(...times)) / 60000) : null;
    const hasEle = Number.isFinite(altMin);

    // ── Bloc géo (§4.20) : persisté en base pour filtrer par direction et dessiner la carte
    // d'ensemble sans re-télécharger les fichiers. Extrait ici, côté client, car le serveur ne
    // parse jamais de GPX (cadrage §7.6) ; il se contente de borner et de rejeter.
    const start = { lat: pts[0].lat, lon: pts[0].lon };
    const end = { lat: pts[pts.length - 1].lat, lon: pts[pts.length - 1].lon };

    let minLat = Infinity, maxLat = -Infinity, minLon = Infinity, maxLon = -Infinity;
    for (const p of pts) {
        if (p.lat < minLat) minLat = p.lat;
        if (p.lat > maxLat) maxLat = p.lat;
        if (p.lon < minLon) minLon = p.lon;
        if (p.lon > maxLon) maxLon = p.lon;
    }
    // L'allongement du circuit (arrondi vs étiré) n'est PAS calculé ici : il se déduit entièrement
    // de cette bbox, et le serveur le recalcule dans GpxStats::elongation() — une valeur de moins
    // à transporter, et une de moins à ne pas croire.
    const bbox = { minLat, minLon, maxLat, maxLon };

    // Cap vers le CENTROÏDE de la bbox, pas vers le point le plus éloigné : sur une boucle — le cas
    // dominant en vélo — le point le plus éloigné est arbitraire, et deux exports de la même trace
    // partant d'endroits différents donneraient des secteurs différents. Le centroïde est stable
    // quels que soient le point de départ et le sens de parcours.
    const bearing = bearingBetween(start, { lat: (minLat + maxLat) / 2, lon: (minLon + maxLon) / 2 });

    return {
        points: pts.map((p) => [p.lat, p.lon]),
        pointCount: pts.length,
        distanceKm: Math.round((dist / 1000) * 10) / 10,
        dplus: hasEle ? Math.round(dplus) : null,
        dmoins: hasEle ? Math.round(dmoins) : null,
        altMin: hasEle ? Math.round(altMin) : null,
        altMax: hasEle ? Math.round(altMax) : null,
        durationMin,
        start,
        end,
        isLoop: haversine(start, end) < LOOP_METERS,
        bbox,
        bearing,
        sector: SECTORS[Math.round(bearing / 45) % 8],
        polyline: simplifyAdaptive(pts, MAX_POLYLINE_POINTS),
        elevationProfile: hasEle ? elevationProfileFrom(pts, dist) : null,
        // Bornes kilométriques pour la carte. Calculées sur `dist` NON arrondie (distanceKm l'est au
        // 100 m) et sur les points bruts, pas la polyline simplifiée : la simplification coupe les
        // virages et raccourcit le tracé, ce qui décalerait les bornes de plusieurs centaines de
        // mètres en fin de parcours.
        kmMarkers: kmMarkersFrom(pts, dist),
    };
}

// Pas de graduation kilométrique en fonction de la longueur totale.
//
// ⚠️ DOIT rester synchronisé avec resources/views/components/alt-profile.blade.php ($stepKm) :
// les pastilles de la carte et les graduations du profil doivent tomber sur les MÊMES kilomètres,
// sinon lire les deux côte à côte devient un exercice de conversion mentale.
export function kmStepFor(totalKm) {
    if (totalKm <= 2) return 0.5;
    if (totalKm <= 6) return 1;
    if (totalKm <= 12) return 2;
    if (totalKm <= 30) return 5;
    if (totalKm <= 60) return 10;
    if (totalKm <= 120) return 20;
    return 50;
}

// Bornes kilométriques le long du tracé : [{ km, lat, lon }, …].
//
// Le point exact est INTERPOLÉ sur le segment qui franchit la borne, et non arrondi au point GPX le
// plus proche : avec un enregistrement à 10 s, deux points consécutifs peuvent être distants de
// 100 m à vélo — une pastille « 20 km » posée à 80 m de sa vraie position se verrait.
// Le km 0 est omis (c'est le départ, déjà marqué par sa propre épingle).
export function kmMarkersFrom(pts, totalMeters) {
    const totalKm = totalMeters / 1000;
    const step = kmStepFor(totalKm);
    const out = [];
    let acc = 0;
    let next = step * 1000;

    for (let i = 1; i < pts.length; i++) {
        const segment = haversine(pts[i - 1], pts[i]);
        if (segment <= 0) continue;

        // `while` et non `if` : un segment long (perte de signal, tunnel) peut franchir plusieurs
        // bornes d'un coup — sur un pas de 0,5 km, un trou de 2 km en vaut quatre.
        while (acc + segment >= next && out.length < 200) {
            const ratio = (next - acc) / segment;
            out.push({
                km: Math.round(next / 100) / 10,   // évite 14.999999999 sur les pas fractionnaires
                lat: pts[i - 1].lat + (pts[i].lat - pts[i - 1].lat) * ratio,
                lon: pts[i - 1].lon + (pts[i].lon - pts[i - 1].lon) * ratio,
            });
            next += step * 1000;
        }
        acc += segment;
    }
    return out;
}

// Cap great-circle initial de a vers b, en degrés 0..359.
function bearingBetween(a, b) {
    const toRad = (d) => (d * Math.PI) / 180;
    const phi1 = toRad(a.lat);
    const phi2 = toRad(b.lat);
    const dLambda = toRad(b.lon - a.lon);
    const y = Math.sin(dLambda) * Math.cos(phi2);
    const x = Math.cos(phi1) * Math.sin(phi2) - Math.sin(phi1) * Math.cos(phi2) * Math.cos(dLambda);
    return (Math.round((Math.atan2(y, x) * 180) / Math.PI) % 360 + 360) % 360;
}

// Distance perpendiculaire d'un point à un segment, dans un plan local (mètres).
// La projection x = lon·cos(latMoy) rend les degrés de longitude comparables à ceux de latitude ;
// sans elle, une trace est-ouest serait simplifiée bien plus agressivement qu'une nord-sud.
function perpendicularDistance(p, a, b, cosLat) {
    const M = 111320; // mètres par degré de latitude
    const px = (p.lon - a.lon) * cosLat * M;
    const py = (p.lat - a.lat) * M;
    const bx = (b.lon - a.lon) * cosLat * M;
    const by = (b.lat - a.lat) * M;
    const len2 = bx * bx + by * by;
    if (len2 === 0) return Math.hypot(px, py);
    const t = Math.max(0, Math.min(1, (px * bx + py * by) / len2));
    return Math.hypot(px - t * bx, py - t * by);
}

// Douglas-Peucker itératif (pile explicite : une trace de 50 000 points ferait sauter la récursion).
function douglasPeucker(pts, tolerance, cosLat) {
    const keep = new Uint8Array(pts.length);
    keep[0] = 1;
    keep[pts.length - 1] = 1;
    const stack = [[0, pts.length - 1]];

    while (stack.length) {
        const [first, last] = stack.pop();
        let maxDist = 0;
        let index = -1;
        for (let i = first + 1; i < last; i++) {
            const d = perpendicularDistance(pts[i], pts[first], pts[last], cosLat);
            if (d > maxDist) { maxDist = d; index = i; }
        }
        if (index !== -1 && maxDist > tolerance) {
            keep[index] = 1;
            stack.push([first, index], [index, last]);
        }
    }

    const out = [];
    for (let i = 0; i < pts.length; i++) if (keep[i]) out.push(pts[i]);
    return out;
}

// Tolérance ADAPTATIVE par dichotomie plutôt que fixe : à tolérance constante, une boucle de 5 km
// tombe à ~40 points quand un trail de 80 km en garde ~900. On cherche la tolérance qui approche
// `budget` points par le dessous, en ~12 itérations.
function simplifyAdaptive(pts, budget) {
    const round5 = (arr) => arr.map((p) => [Math.round(p.lat * 1e5) / 1e5, Math.round(p.lon * 1e5) / 1e5]);
    if (pts.length <= budget) return round5(pts);

    const cosLat = Math.cos((pts[0].lat * Math.PI) / 180);
    let lo = 1;        // mètres
    let hi = 2000;
    let best = douglasPeucker(pts, hi, cosLat);

    for (let i = 0; i < 12; i++) {
        const mid = (lo + hi) / 2;
        const candidate = douglasPeucker(pts, mid, cosLat);
        if (candidate.length > budget) {
            lo = mid;              // trop de points : il faut simplifier davantage
        } else {
            best = candidate;      // tient dans le budget : on tente plus fin
            hi = mid;
        }
    }
    return round5(best);
}

// Profil altimétrique échantillonné à pas de distance constant : [[distKm, altM], …].
// Rendu SERVEUR ensuite (<x-alt-profile>), donc ni JS ni fetch sur la fiche.
function elevationProfileFrom(pts, totalMeters) {
    const samples = 120;
    if (!(totalMeters > 0)) return null;

    const step = totalMeters / samples;
    const out = [];
    let acc = 0;
    let next = 0;
    let lastEle = null;

    for (let i = 0; i < pts.length; i++) {
        if (i > 0) acc += haversine(pts[i - 1], pts[i]);
        if (Number.isFinite(pts[i].ele)) lastEle = pts[i].ele;
        // Tant qu'aucune altitude n'est connue, on NE FAIT PAS avancer `next` : sinon les échantillons
        // antérieurs au premier <ele> seraient définitivement perdus et le profil démarrerait au
        // milieu du parcours (cas réel : exports Garmin/Strava qui enregistrent avant le calage
        // altimétrique GPS). <x-alt-profile> étire les points reçus sur toute la largeur du cadre —
        // un profil amputé de sa tête mentirait donc en prétendant couvrir tout le tracé.
        if (lastEle === null) continue;
        while (acc >= next && out.length <= samples) {
            out.push([Math.round((next / 1000) * 1000) / 1000, Math.round(lastEle * 10) / 10]);
            next += step;
        }
    }
    return out.length >= 2 ? out : null;
}

// ── Composant formulaire : drop / parse / métadonnées, pousse vers Livewire ──
function gpxField({ stats }) {
    return {
        meta: stats || null,   // métadonnées affichées (depuis le serveur en édition, ou après parse)
        drag: false,
        error: '',

        async onPick(event) {
            const file = event.target.files?.[0];
            if (!file) return;
            await this.handle(file);
        },

        async onDrop(event) {
            this.drag = false;
            const file = event.dataTransfer?.files?.[0];
            if (!file) return;
            // Reflète le fichier dans l'input pour que wire:model déclenche l'upload.
            this.$refs.file.files = event.dataTransfer.files;
            this.$refs.file.dispatchEvent(new Event('change', { bubbles: true }));
            await this.handle(file);
        },

        async handle(file) {
            this.error = '';
            if (!/\.gpx$/i.test(file.name)) { this.error = 'Format attendu : .gpx'; return; }
            if (file.size > MAX_BYTES) { this.error = 'Fichier trop volumineux (max 5 Mo).'; return; }
            try {
                const text = await file.text();
                const parsed = parseGpx(text);
                this.meta = {
                    name: file.name,
                    sizeKo: Math.round(file.size / 1024),
                    distanceKm: parsed.distanceKm,
                    dplus: parsed.dplus,
                    dmoins: parsed.dmoins,
                    altMin: parsed.altMin,
                    altMax: parsed.altMax,
                    pointCount: parsed.pointCount,
                    durationMin: parsed.durationMin,
                };
                // Les métadonnées (parsées client) accompagnent le fichier brut (uploadé par wire:model).
                // Le bloc géo est isolé sous `geo` : il pèse ~6 ko (polyline + profil), il ne doit ni
                // polluer l'affichage (this.meta) ni voyager à chaque frappe — d'où le deferred (false)
                // et l'absence de tout wire:model.live sur ce formulaire.
                this.$wire.set('gpxStats', {
                    ...this.meta,
                    geo: {
                        start: parsed.start,
                        end: parsed.end,
                        isLoop: parsed.isLoop,
                        bbox: parsed.bbox,
                        bearing: parsed.bearing,
                        sector: parsed.sector,
                        polyline: parsed.polyline,
                        elevationProfile: parsed.elevationProfile,
                    },
                }, false);
            } catch (e) {
                this.error = e.message || 'GPX illisible.';
            }
        },

        remove() {
            this.meta = null;
            this.error = '';
            this.$refs.file.value = '';
            this.$wire.set('gpxStats', null, false);
            this.$wire.removeGpx();
        },
    };
}

// ── Composant fiche : récupère le GPX stocké et trace sur OSM ──
function gpxMap({ url, lockable = false }) {
    // L'instance Leaflet vit dans la closure, PAS dans le state Alpine : Alpine proxifierait l'objet
    // map et casserait les comparaisons d'identité internes de Leaflet (layers, events, DOM). Même
    // garde que l'éditeur TipTap dans wysiwyg.js.
    let map = null;
    let bounds = null;     // bornes du tracé, conservées pour recadrer après invalidateSize
    let resizeObs = null;

    return {
        failed: false,
        // Verrou d'interaction opt-in, même mécanique que locationMap : une carte de tracé occupe
        // beaucoup de hauteur, et non verrouillée elle capture le scroll de la page. Verrouillée au
        // montage ; un tap sur le voile la libère.
        lockable,
        locked: lockable,
        // Fullscreen API : indisponible sur iOS Safari (réservé aux <video>). On masque le bouton
        // quand l'API n'existe pas plutôt que d'offrir un contrôle no-op (cf. limite iOS documentée).
        fsSupported: typeof document !== 'undefined' && document.fullscreenEnabled === true,
        isFs: false,

        async init() {
            await this.$nextTick();
            try {
                const res = await fetch(url, { headers: { Accept: 'application/gpx+xml' } });
                if (!res.ok) throw new Error('GPX indisponible');
                const parsed = parseGpx(await res.text());

                // Chargement à la demande : Leaflet + son CSS ne sont tirés que lorsqu'une carte s'affiche.
                const { default: L } = await import('leaflet');
                await import('leaflet/dist/leaflet.css');

                map = L.map(this.$refs.map, { scrollWheelZoom: false, attributionControl: true });
                this.applyLock();
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 18,
                    attribution: '&copy; OpenStreetMap',
                }).addTo(map);
                const line = L.polyline(parsed.points, { color: '#d4282e', weight: 2 }).addTo(map);
                bounds = line.getBounds();
                map.fitBounds(bounds, { padding: [16, 16] });

                // Pastilles du tracé : bornes kilométriques + départ/arrivée. divIcon plutôt qu'une
                // image : la pastille est du HTML, donc stylée aux tokens du design system et nette
                // sur écran dense.
                //
                // Repères PASSIFS (`interactive: false`) : une pastille informe, elle n'est pas un
                // contrôle — et sur un tracé replié sur lui-même elles intercepteraient les gestes
                // de déplacement de la carte.
                const dot = (lat, lon, text, variant, z) => L.marker([lat, lon], {
                    icon: L.divIcon({
                        className: 'gpx-kmdot-wrap',
                        html: `<span class="gpx-kmdot ${variant}">${text}</span>`,
                        iconSize: [26, 16],
                        iconAnchor: [13, 8],
                    }),
                    keyboard: false,
                    interactive: false,
                    zIndexOffset: z,
                }).addTo(map);

                // Bornes kilométriques, au même pas que les graduations du profil altimétrique
                // (kmStepFor) : lire les deux côte à côte ne doit demander aucune conversion.
                for (const m of parsed.kmMarkers) {
                    dot(m.lat, m.lon, m.km, '', 0);
                }

                // Départ / arrivée par-dessus les bornes (zIndexOffset) : sur une boucle, la borne du
                // dernier kilomètre tombe souvent à quelques mètres du départ, et masquerait le repère
                // le plus utile de la carte.
                if (parsed.isLoop) {
                    // Boucle : départ et arrivée sont à moins de 250 m (LOOP_METERS). Deux pastilles
                    // se chevaucheraient sans rien apprendre — une seule dit « on revient ici ».
                    dot(parsed.start.lat, parsed.start.lon, 'D/A', 'is-loop', 1000);
                } else {
                    dot(parsed.start.lat, parsed.start.lon, 'D', 'is-start', 1000);
                    dot(parsed.end.lat, parsed.end.lon, 'A', 'is-end', 1000);
                }

                // Si la carte est initialisée alors que son conteneur est masqué (onglet « Tracé GPX »
                // pas actif, ou page encore animée), Leaflet la dimensionne à 0×0 : tuiles mal placées,
                // tracé hors cadre. On observe la taille réelle du conteneur ; dès qu'il prend des
                // dimensions non nulles, on force le recalcul et on recadre sur le tracé.
                resizeObs = new ResizeObserver(() => {
                    if (!map) return;
                    const el = this.$refs.map;
                    if (el.clientWidth > 0 && el.clientHeight > 0) {
                        map.invalidateSize();
                        if (bounds) map.fitBounds(bounds, { padding: [16, 16] });
                    }
                });
                resizeObs.observe(this.$refs.map);

                // Synchronise l'état du bouton quand l'utilisateur quitte le plein écran autrement
                // que par le bouton (touche Échap, geste navigateur).
                this._onFsChange = () => {
                    this.isFs = document.fullscreenElement === this.$refs.fsWrap;
                    // En plein écran il n'y a plus de page à faire défiler : le verrou n'a plus de
                    // raison d'être et rendrait la carte inutilisable. On le lève à l'entrée, et on
                    // le repose à la sortie — sinon on ressortirait sur une carte qui capture le scroll.
                    if (this.lockable) {
                        this.locked = !this.isFs;
                        this.applyLock();
                    }
                };
                document.addEventListener('fullscreenchange', this._onFsChange);
            } catch (e) {
                this.failed = true;
            }
        },

        // Applique l'état `locked` aux handlers d'interaction Leaflet — tout est natif, chaque
        // handler expose enable()/disable(). Verrouillé = simple aperçu, le scroll de la page passe
        // au travers ; déverrouillé = drag + zoom (molette, tap, pincer). Cf. locationMap.applyLock.
        applyLock() {
            if (!map || !this.lockable) return;
            const handlers = ['dragging', 'touchZoom', 'doubleClickZoom', 'boxZoom', 'keyboard'];
            for (const h of handlers) map[h] && (this.locked ? map[h].disable() : map[h].enable());
            this.locked ? map.scrollWheelZoom.disable() : map.scrollWheelZoom.enable();
            if (map.tap) this.locked ? map.tap.disable() : map.tap.enable();
        },

        toggleLock() {
            this.locked = !this.locked;
            this.applyLock();
        },

        async toggleFullscreen() {
            if (!this.fsSupported) return;
            try {
                if (document.fullscreenElement === this.$refs.fsWrap) {
                    await document.exitFullscreen();
                } else {
                    await this.$refs.fsWrap.requestFullscreen();
                }
            } catch (e) {
                // Échec silencieux : le navigateur peut refuser (hors interaction utilisateur, etc.).
            }
        },

        destroy() {
            if (this._onFsChange) document.removeEventListener('fullscreenchange', this._onFsChange);
            resizeObs?.disconnect();
            resizeObs = null;
            map?.remove();
            map = null;
            bounds = null;
        },
    };
}

// ── Composant carte d'ensemble de la bibliothèque (J10.C bis, §4.20) ──
// Dessine N tracés simplifiés sur un seul fond OSM. Aucun parsing GPX ici, contrairement à gpxMap :
// les polylines viennent déjà simplifiées de la base (endpoint /parcours-traces), donc une requête
// pour toute la carte au lieu d'une par parcours.
function gpxRoutesMap({ url, lockable = false }) {
    let map = null;
    let layer = null;      // LayerGroup de tous les tracés, vidé/reconstruit à chaque jeu de filtres
    let L = null;          // module Leaflet mémorisé (import dynamique fait une seule fois)
    let resizeObs = null;
    let reqId = 0;         // garde anti-course : seule la dernière requête émise a le droit de peindre
    let wanted = url;      // dernière URL demandée, y compris pendant que Leaflet se charge encore

    // Palette du design, parcourue cycliquement : deux tracés voisins doivent se distinguer, et une
    // couleur par discipline ne suffirait pas (le club roule presque tout en vélo).
    const COLORS = ['#d4282e', '#1d6fb8', '#69bf2d', '#e08a1e', '#7b4bc4', '#0f8f7e'];

    return {
        // Verrou d'interaction, même dispositif que gpxMap et locationMap : cette carte fait 62vh,
        // elle capturerait le scroll de la page sur toute sa hauteur.
        lockable,
        locked: lockable,
        loading: true,
        failed: false,
        empty: false,
        truncated: false,
        count: 0,

        async init() {
            await this.$nextTick();
            try {
                // preferCanvas : en SVG, chaque tracé est un nœud DOM de 200 points. À 18 parcours
                // le rendu tient, mais le pan devient saccadé sur mobile ; le canvas peint tout d'un
                // coup et reste fluide bien au-delà du plafond de MAX_TRACES.
                ({ default: L } = await import('leaflet'));
                await import('leaflet/dist/leaflet.css');

                // renderer explicite pour porter `tolerance` : en canvas, la zone cliquable d'un
                // tracé vaut `weight / 2 + tolerance`, soit 1,5 px avec un trait de 3 — inatteignable
                // au doigt. 10 px de tolérance donnent une cible de ~11,5 px, cohérente avec les
                // recommandations tactiles, sans épaissir le trait ni gêner la lecture.
                map = L.map(this.$refs.map, {
                    scrollWheelZoom: false,
                    attributionControl: true,
                    preferCanvas: true,
                    renderer: L.canvas({ tolerance: 10 }),
                });
                this.applyLock();
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 18,
                    attribution: '&copy; OpenStreetMap',
                }).addTo(map);
                layer = L.layerGroup().addTo(map);

                // Même garde que gpxMap : la carte naît dans un conteneur masqué (mode « liste »
                // actif) et Leaflet la dimensionne alors à 0×0.
                resizeObs = new ResizeObserver(() => {
                    if (!map) return;
                    const el = this.$refs.map;
                    if (el.clientWidth > 0 && el.clientHeight > 0) map.invalidateSize();
                });
                resizeObs.observe(this.$refs.map);

                // `wanted` et non `url` : l'import dynamique de Leaflet prend quelques centaines de
                // ms, pendant lesquelles l'utilisateur peut déjà avoir coché une chip. On charge
                // l'état le plus récent, pas celui du montage.
                await this.load(wanted);
            } catch (e) {
                this.failed = true;
                this.loading = false;
            }
        },

        // Applique l'état `locked` aux handlers d'interaction Leaflet — tout est natif, chaque
        // handler expose enable()/disable(). Verrouillée = simple aperçu que le scroll traverse.
        applyLock() {
            if (!map) return;
            const handlers = ['dragging', 'touchZoom', 'doubleClickZoom', 'boxZoom', 'keyboard'];
            for (const h of handlers) map[h] && (this.locked ? map[h].disable() : map[h].enable());
            this.locked ? map.scrollWheelZoom.disable() : map.scrollWheelZoom.enable();
            if (map.tap) this.locked ? map.tap.disable() : map.tap.enable();
        },

        toggleLock() {
            this.locked = !this.locked;
            this.applyLock();
            // Un popup ouvert avant le re-verrouillage resterait affiché et interceptrait les clics
            // au-dessus du voile : on referme, la carte redevient un aperçu inerte.
            if (this.locked) map?.closePopup();
        },

        // Appelée à l'événement `gpx-routes-filtered` quand les filtres changent (et une fois au
        // montage). L'îlot est en wire:ignore : aucune interpolation Blade ne l'atteindrait, seul un
        // événement franchit la frontière — et il ne transporte que des paramètres, pas des tracés.
        async load(nextUrl) {
            wanted = nextUrl;
            if (!map) return;   // Leaflet pas encore prêt : init() lira `wanted` en fin de chargement
            const mine = ++reqId;
            this.loading = true;
            this.failed = false;
            try {
                const res = await fetch(nextUrl, { headers: { Accept: 'application/json' } });
                if (!res.ok) throw new Error('Tracés indisponibles');
                const data = await res.json();
                // Une réponse plus lente qu'une requête émise après elle ne doit pas écraser
                // l'affichage : on abandonne tout ce qui n'est pas la dernière demande.
                if (mine !== reqId) return;
                this.draw(data);
            } catch (e) {
                if (mine === reqId) { this.failed = true; this.loading = false; }
            }
        },

        draw(data) {
            layer.clearLayers();
            const routes = data.routes || [];
            this.count = routes.length;
            this.truncated = !!data.truncated;
            this.empty = routes.length === 0;
            this.loading = false;
            if (this.empty) return;

            const all = [];
            routes.forEach((r, i) => {
                if (!Array.isArray(r.points) || r.points.length < 2) return;
                const line = L.polyline(r.points, {
                    color: COLORS[i % COLORS.length],
                    weight: 3,
                    opacity: 0.8,
                    // Le clic ne doit pas remonter à la carte après avoir ouvert le popup du tracé
                    // (l'élargissement de la cible, lui, vient de `tolerance` sur le renderer).
                    bubblingMouseEvents: false,
                }).addTo(layer);
                line.bindPopup(this.popupHtml(r));
                // Survol : remonte le tracé au premier plan et l'épaissit, pour le distinguer dans
                // un faisceau. Sans effet au doigt, mais sans coût non plus.
                line.on('mouseover', () => line.setStyle({ weight: 5, opacity: 1 }));
                line.on('mouseout', () => line.setStyle({ weight: 3, opacity: 0.8 }));
                all.push(line.getBounds());
            });

            if (all.length) {
                map.fitBounds(all.reduce((acc, b) => acc.extend(b)), { padding: [20, 20] });
            }
        },

        // Popup construit en DOM et non par concaténation de chaînes : les noms de parcours sont
        // saisis par les coachs, un `<` dans un nom ne doit pas pouvoir injecter de balise.
        popupHtml(r) {
            const box = document.createElement('div');
            box.className = 'routes-pop';

            const title = document.createElement('strong');
            title.textContent = r.name;
            box.appendChild(title);

            const bits = [
                r.distanceKm !== null ? `${String(r.distanceKm).replace('.', ',')} km` : null,
                r.dplus !== null ? `${r.dplus} m D+` : null,
                r.grade,
                r.sector ? `secteur ${r.sector}` : null,
            ].filter(Boolean);
            if (bits.length) {
                const meta = document.createElement('div');
                meta.className = 'routes-pop-meta';
                meta.textContent = bits.join(' · ');
                box.appendChild(meta);
            }

            const link = document.createElement('a');
            link.href = r.url;
            link.className = 'routes-pop-link';
            link.textContent = 'Voir la fiche →';
            // Pas de wire:navigate : le popup vit hors du DOM géré par Livewire (wire:ignore),
            // l'attribut n'y serait jamais câblé. Navigation pleine page, assumée.
            box.appendChild(link);

            return box;
        },

        destroy() {
            reqId++;               // invalide toute requête en vol : plus rien ne peindra après
            resizeObs?.disconnect();
            resizeObs = null;
            map?.remove();
            map = null;
            layer = null;
        },
    };
}

// ── Composant carte « lieu » : un marqueur sur fond OSM (§4.13.4) ──
// Sert sur le formulaire Lieux (recentré en direct quand on choisit une suggestion) et sur la fiche
// séance (consultation, lieu géocodé). Même garde que gpxMap : l'instance Leaflet vit dans la closure,
// hors state Alpine, et Leaflet est chargé À LA DEMANDE (la carte ne sert que sur ces écrans).
function locationMap({ lat, lng, lockable = false }) {
    let map = null;
    let marker = null;
    let resizeObs = null;

    return {
        // Verrou d'interaction opt-in (fiche séance) : verrouillée au montage, on déverrouille au tap.
        // Ailleurs (form Lieux admin) lockable=false → carte interactive comme avant, pas de voile.
        lockable,
        failed: false,
        locked: lockable,
        hasCoords: Number.isFinite(lat) && Number.isFinite(lng),

        async init() {
            if (!this.hasCoords) return;
            await this.$nextTick();
            try {
                const { default: L } = await import('leaflet');
                await import('leaflet/dist/leaflet.css');
                // Leaflet pointe son icône de marqueur par défaut vers un chemin relatif au script ;
                // sous bundler (Vite), ce chemin casse → marqueur invisible. On résout les images via
                // les imports d'assets (Vite réécrit l'URL finale) et on les fixe sur l'icône par défaut.
                const [iconUrl, iconRetinaUrl, shadowUrl] = await Promise.all([
                    import('leaflet/dist/images/marker-icon.png'),
                    import('leaflet/dist/images/marker-icon-2x.png'),
                    import('leaflet/dist/images/marker-shadow.png'),
                ]);

                map = L.map(this.$refs.map, { scrollWheelZoom: false, attributionControl: true });
                // Verrou d'interaction : au montage on coupe drag/tap/zoom pour que le scroll de la
                // page ne soit pas « capturé » par la carte (elle passait devant la topbar au scroll).
                // Le bouton overlay bascule ce verrou (cf. applyLock / toggle).
                this.applyLock();
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 18,
                    attribution: '&copy; OpenStreetMap',
                }).addTo(map);
                const icon = L.icon({
                    iconUrl: iconUrl.default,
                    iconRetinaUrl: iconRetinaUrl.default,
                    shadowUrl: shadowUrl.default,
                    iconSize: [25, 41],
                    iconAnchor: [12, 41],
                    popupAnchor: [1, -34],
                    shadowSize: [41, 41],
                });
                marker = L.marker([lat, lng], { icon }).addTo(map);
                map.setView([lat, lng], 14);

                // Même souci que gpxMap : carte parfois initialisée dans un conteneur masqué (0×0).
                resizeObs = new ResizeObserver(() => {
                    if (!map) return;
                    const el = this.$refs.map;
                    if (el.clientWidth > 0 && el.clientHeight > 0) {
                        map.invalidateSize();
                        map.setView(marker.getLatLng(), map.getZoom());
                    }
                });
                resizeObs.observe(this.$refs.map);
            } catch (e) {
                this.failed = true;
            }
        },

        // Applique l'état `locked` aux handlers d'interaction Leaflet (tout est natif : chaque
        // handler expose enable()/disable()). Verrouillé = la carte est un simple aperçu, le
        // scroll/tap de la page la traverse ; déverrouillé = drag + zoom (molette, tap, pincer).
        applyLock() {
            if (!map) return;
            const handlers = ['dragging', 'touchZoom', 'doubleClickZoom', 'boxZoom', 'keyboard'];
            for (const h of handlers) map[h] && (this.locked ? map[h].disable() : map[h].enable());
            this.locked ? map.scrollWheelZoom.disable() : map.scrollWheelZoom.enable();
            if (map.tap) this.locked ? map.tap.disable() : map.tap.enable();
        },

        toggleLock() {
            this.locked = !this.locked;
            this.applyLock();
        },

        // Recentre le marqueur quand le formulaire Lieux dispatch `location-located` (sélection d'une
        // suggestion ou géocodage) : l'îlot est en wire:ignore, Livewire ne le re-render pas.
        relocate(detail) {
            const nlat = Number(detail?.lat);
            const nlng = Number(detail?.lng);
            if (!Number.isFinite(nlat) || !Number.isFinite(nlng)) return;
            this.failed = false;
            if (!map) { lat = nlat; lng = nlng; this.hasCoords = true; this.init(); return; }
            marker.setLatLng([nlat, nlng]);
            map.setView([nlat, nlng], 14);
        },

        destroy() {
            resizeObs?.disconnect();
            resizeObs = null;
            map?.remove();
            map = null;
            marker = null;
        },
    };
}

// Enregistrement des composants Alpine via `alpine:init` (cohérent avec wysiwyg.js) → disponibles
// quand Alpine évalue x-data, quel que soit l'ordre de chargement des modules Vite.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('gpxField', gpxField);
    window.Alpine.data('gpxMap', gpxMap);
    window.Alpine.data('gpxRoutesMap', gpxRoutesMap);
    window.Alpine.data('locationMap', locationMap);
});

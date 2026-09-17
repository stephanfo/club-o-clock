// Géométrie d'un tracé GPX, sans DOM ni Leaflet : distance entre points, et lecture d'un tracé
// « à la distance » pour le survol animé (#67). Module pur, pour être testé sous Node
// (`node --test tests/E2E/track.test.mjs`) — gpx.js, lui, s'enregistre auprès d'Alpine au chargement.

// Distance haversine entre deux points (mètres).
export function haversine(a, b) {
    const R = 6371000;
    const toRad = (d) => (d * Math.PI) / 180;
    const dLat = toRad(b.lat - a.lat);
    const dLon = toRad(b.lon - a.lon);
    const s = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLon / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(s));
}

// Tracé indexé par la distance cumulée : { lat[], lon[], ele[] | null, cum[], total }.
//
// Construit sur les points BRUTS, pas la polyline simplifiée : la simplification coupe les virages,
// le point animé sortirait de la route dessinée.
//
// Altitudes manquantes : les trous sont bouchés par la dernière valeur connue, et la tête (avant le
// premier <ele>, cas des exports Garmin/Strava) par la première — même lecture que le profil
// altimétrique, qui ne doit pas afficher une altitude que la carte contredirait. Aucune altitude
// dans tout le fichier : `ele` vaut null, et l'écran n'affiche ni altitude ni pente.
export function buildTrack(pts) {
    const n = pts.length;
    const lat = new Array(n);
    const lon = new Array(n);
    const cum = new Array(n);
    let ele = new Array(n);
    let firstEle = null;
    let lastEle = null;

    for (let i = 0; i < n; i++) {
        lat[i] = pts[i].lat;
        lon[i] = pts[i].lon;
        cum[i] = i === 0 ? 0 : cum[i - 1] + haversine(pts[i - 1], pts[i]);
        if (Number.isFinite(pts[i].ele)) {
            lastEle = pts[i].ele;
            if (firstEle === null) firstEle = lastEle;
        }
        ele[i] = lastEle;
    }

    if (firstEle === null) {
        ele = null;
    } else {
        for (let i = 0; i < n && ele[i] === null; i++) ele[i] = firstEle;
    }

    return { lat, lon, ele, cum, total: n ? cum[n - 1] : 0 };
}

// Position à la distance `d` (mètres) : { i, lat, lon, ele }, où `i` est l'index du point qui
// ouvre le segment franchi. Le point est INTERPOLÉ sur ce segment, comme les bornes kilométriques :
// avec un enregistrement à 10 s, deux points peuvent être distants de 100 m à vélo, et le point
// animé avancerait par sauts.
export function locate(track, d) {
    const { cum } = track;
    const last = cum.length - 1;
    const x = Math.min(Math.max(d, 0), track.total);

    // Recherche dichotomique du dernier index tel que cum[i] <= x : un tracé de 5 Mo compte des
    // dizaines de milliers de points, et la lecture tourne à chaque image.
    let lo = 0;
    let hi = last;
    while (lo < hi) {
        const mid = (lo + hi + 1) >> 1;
        if (cum[mid] <= x) lo = mid;
        else hi = mid - 1;
    }

    const i = Math.min(lo, Math.max(last - 1, 0));
    const j = Math.min(i + 1, last);
    const span = cum[j] - cum[i];
    const r = span > 0 ? Math.min(Math.max((x - cum[i]) / span, 0), 1) : 0;

    return {
        i,
        lat: track.lat[i] + (track.lat[j] - track.lat[i]) * r,
        lon: track.lon[i] + (track.lon[j] - track.lon[i]) * r,
        ele: track.ele ? track.ele[i] + (track.ele[j] - track.ele[i]) * r : null,
    };
}

// Pente locale en % autour de `d`, sur une fenêtre glissante de ±`half` mètres (100 m au total).
//
// Point par point, le bruit altimétrique GPS la rend illisible : deux points à 5 m d'écart et 1 m
// de bruit donnent déjà 20 %. Aux extrémités la fenêtre est rognée ; en dessous de 20 m de fenêtre
// effective (tracé minuscule), la valeur n'aurait plus de sens et on renvoie null.
export function slopeAt(track, d, half = 50) {
    if (!track.ele) return null;
    const a = Math.max(d - half, 0);
    const b = Math.min(d + half, track.total);
    if (b - a < 20) return null;
    return ((locate(track, b).ele - locate(track, a).ele) / (b - a)) * 100;
}

// Tests de la géométrie du survol de tracé (resources/js/track.js) — `node --test tests/E2E/track.test.mjs`.
//
// Pourquoi ici et pas dans PHPUnit : `composer check` ne voit pas le JavaScript. Le module est pur
// (ni DOM ni Leaflet), le runner intégré de Node suffit ; le rendu, lui, est couvert par parcours.mjs.

import assert from 'node:assert/strict';
import test from 'node:test';
import { buildTrack, haversine, locate, slopeAt } from '../../resources/js/track.js';

// 1° de latitude ≈ 111 195 m : 0,0009° ≈ 100 m vers le nord.
const PAS = 0.0009;
const nord = (k, ele) => ({ lat: 45 + k * PAS, lon: 1, ele });

test('la distance cumulée suit les points, sa fin est la longueur totale', () => {
    const t = buildTrack([nord(0, 100), nord(1, 100), nord(2, 100)]);
    assert.equal(t.cum[0], 0);
    assert.ok(Math.abs(t.total - 2 * haversine(nord(0), nord(1))) < 1e-6);
});

test('la position est interpolée à l’intérieur d’un segment, pas arrondie au point', () => {
    const t = buildTrack([nord(0, 100), nord(1, 200)]);
    const p = locate(t, t.total / 4);
    assert.equal(p.i, 0);
    assert.ok(Math.abs(p.lat - (45 + PAS / 4)) < 1e-9);
    assert.ok(Math.abs(p.ele - 125) < 1e-9);
});

test('une distance hors du tracé est bornée au départ et à l’arrivée', () => {
    const t = buildTrack([nord(0, 1), nord(1, 2), nord(2, 3)]);
    assert.equal(locate(t, -50).lat, 45);
    assert.ok(Math.abs(locate(t, t.total + 500).lat - (45 + 2 * PAS)) < 1e-9);
    assert.equal(locate(t, t.total).ele, 3);
});

test('des points confondus (arrêt, doublon) ne produisent pas de division par zéro', () => {
    const t = buildTrack([nord(0, 1), nord(0, 1), nord(1, 2)]);
    const p = locate(t, 10);
    assert.ok(Number.isFinite(p.lat) && Number.isFinite(p.ele));
});

test('la pente se lit sur 100 m glissants, et gomme le bruit d’un point isolé', () => {
    // Montée régulière de 5 m tous les 10 m (50 %)… sauf un point bruité de +8 m au milieu.
    const pts = Array.from({ length: 41 }, (_, k) => ({ lat: 45 + (k * PAS) / 10, lon: 1, ele: k * 5 }));
    pts[20].ele += 8;
    const t = buildTrack(pts);
    const pente = slopeAt(t, t.cum[20]);
    assert.ok(Math.abs(pente - 50) < 1, `pente lue : ${pente}`);
});

test('sans aucune altitude, ni altitude ni pente', () => {
    const t = buildTrack([nord(0), nord(1), nord(2)]);
    assert.equal(t.ele, null);
    assert.equal(locate(t, 50).ele, null);
    assert.equal(slopeAt(t, 50), null);
});

test('la tête sans altitude reprend la première altitude connue, les trous la dernière', () => {
    const t = buildTrack([nord(0), nord(1, 120), nord(2), nord(3, 90)]);
    assert.deepEqual(t.ele, [120, 120, 120, 90]);
});

test('un tracé minuscule n’a pas de pente', () => {
    const t = buildTrack([{ lat: 45, lon: 1, ele: 0 }, { lat: 45.0001, lon: 1, ele: 3 }]);
    assert.equal(slopeAt(t, 5), null);
});

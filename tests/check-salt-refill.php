<?php

declare(strict_types=1);

/*
 * Salzvorrat setzen (Nachfüllen) über die Variable saltLevel.
 *
 * Die JUDO-API kennt kein „Nachfüllen", sondern nur das Setzen des absoluten Salzgewichts:
 * Kommando 56 der Connectivity-Modul-Liste („Salzvorrat lesen oder schreiben", 2 Byte
 * Gramm, Beispiel 56004448 = 18500 g) bzw. in der Cloud-API derselbe Index 94 wie beim Lesen,
 * Daten als 2 Byte Little-Endian — dasselbe Format wie bei den Leckageschutz-Grenzwerten.
 *
 * Außerdem: Die Gerätekommandos tragen den Gerätetyp der Instanz (dt), nicht fest 0x33 —
 * eine i-soft K SAFE+ (0x67) muss ihre eigene Kennung senden.
 *
 * Aufruf: git submodule update --init (einmalig), dann
 *         php tests/check-salt-refill.php (Exit-Code 1 bei Fehlern)
 */

require_once __DIR__ . '/harness.php';

function schalte(JuControlHarness $m, string $ident, mixed $wert, string $titel): void
{
    echo "\n$titel\n";
    $m->logs      = [];
    $m->kommandos = [];
    try {
        $m->RequestAction($ident, $wert);
        pruefe(true, 'kein Abbruch');
    } catch (Throwable $t) {
        pruefe(false, 'kein Abbruch — ' . get_class($t) . ': ' . $t->getMessage());
    }
}

function letztesKommando(JuControlHarness $m): string
{
    return $m->kommandos === [] ? '' : end($m->kommandos);
}

/** Enthält die zuletzt abgesetzte URL den Teil? Hex-Daten vergleicht die Cloud unabhängig von der Schreibweise. */
function enthaelt(JuControlHarness $m, string $teil): bool
{
    return str_contains(strtolower(letztesKommando($m)), strtolower($teil));
}

/* A. i-soft SAFE+: Salzvorrat auf 25 kg setzen */
$m = neueInstanz('0x33');
schalte($m, 'saltLevel', 25, 'A. SAFE+: Salzvorrat 25 kg');
pruefe(count($m->kommandos) === 1, 'genau ein Gerätekommando abgesetzt (' . count($m->kommandos) . ')');
pruefe(enthaelt($m, 'command=write%20data&dt=0x33&index=94&data=A861&da=0x1'), 'Index 94 mit 25000 g little-endian (A861) und dt=0x33 — ' . letztesKommando($m));
pruefe(enthaelt($m, '&serial_number='), 'Seriennummer als serial_number');
pruefe($m->werte()['saltLevel'] === 25, 'Variable saltLevel auf 25 gesetzt');
pruefe(($m->timer['SleepTimer'] ?? null) === 10000 && ($m->timer['RefreshTimer'] ?? null) === 0, 'Refresh pausiert (SleepTimer 10 s, RefreshTimer aus)');
pruefe(logsMitStufe($m, KL_ERROR) === [], 'kein Fehler protokolliert');

/* B. Grenzwerte: 50 kg (voller Behälter) geht, 0 kg geht */
schalte($m, 'saltLevel', 50, 'B. SAFE+: Salzvorrat 50 kg');
pruefe(enthaelt($m, 'index=94&data=50C3&da=0x1'), '50000 g = 50C3');
pruefe($m->werte()['saltLevel'] === 50, 'Variable saltLevel auf 50');
schalte($m, 'saltLevel', 0, 'B. SAFE+: Salzvorrat 0 kg');
pruefe(enthaelt($m, 'index=94&data=0000&da=0x1'), '0 g = 0000');

/* C. Außerhalb des Bereichs: kein Kommando, Warnung, Wert bleibt */
schalte($m, 'saltLevel', 51, 'C. SAFE+: 51 kg (über Behältergröße)');
pruefe($m->kommandos === [], 'kein Gerätekommando abgesetzt');
pruefe($m->werte()['saltLevel'] === 0, 'Variable saltLevel unverändert (0)');
pruefeProtokoll($m, 1, 0);
schalte($m, 'saltLevel', -1, 'C. SAFE+: -1 kg');
pruefe($m->kommandos === [], 'kein Gerätekommando abgesetzt');
pruefeProtokoll($m, 1, 0);

/* D. Cloud antwortet mit Fehler: Wert bleibt, Fehler im Protokoll */
$m->kommandoAntwort = '{"status":"error"}';
schalte($m, 'saltLevel', 10, 'D. SAFE+: Cloud lehnt ab');
pruefe(count($m->kommandos) === 1, 'Gerätekommando abgesetzt');
pruefe($m->werte()['saltLevel'] === 0, 'Variable saltLevel unverändert (0)');
pruefe(logsMitStufe($m, KL_ERROR) !== [], 'Fehler protokolliert');
$m->kommandoAntwort = '{"status":"ok"}';

/* E. i-soft K SAFE+ (0x67): dt folgt dem Gerätetyp — beim Salz und bei den bestehenden Kommandos */
$k = neueInstanz('0x67');
schalte($k, 'saltLevel', 30, 'E. K SAFE+: Salzvorrat 30 kg');
pruefe(enthaelt($k, 'command=write%20data&dt=0x67&index=94&data=3075&da=0x1'), 'dt=0x67, 30000 g = 3075 — ' . letztesKommando($k));
pruefe($k->werte()['saltLevel'] === 30, 'Variable saltLevel auf 30');
schalte($k, 'Regeneration', true, 'E. K SAFE+: Regeneration starten');
pruefe(enthaelt($k, 'dt=0x67&index=65&data=&da=0x1'), 'Regeneration mit dt=0x67 — ' . letztesKommando($k));
schalte($k, 'wsMaxWaterFlow', 2000, 'E. K SAFE+: Max. Durchfluss 2000 l/h');
pruefe(enthaelt($k, 'dt=0x67&index=75&data=D007&da=0x1'), 'Grenzwert mit dt=0x67 — ' . letztesKommando($k));
schalte($k, 'Hardness_Normal', 8, 'E. K SAFE+: Wunschhärte 8 °dH');
pruefe(enthaelt($k, 'dt=0x67&index=60&data=8&da=0x1'), 'Wunschhärte mit dt=0x67 — ' . letztesKommando($k));

/* F. SAFE+ bleibt bei dt=0x33 */
schalte($m, 'Regeneration', true, 'F. SAFE+: Regeneration starten');
pruefe(enthaelt($m, 'dt=0x33&index=65&data=&da=0x1'), 'Regeneration mit dt=0x33 — ' . letztesKommando($m));

ergebnis();

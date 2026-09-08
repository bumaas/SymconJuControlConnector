<?php

declare(strict_types=1);

/*
 * Regressionstest: Unvollständige Gerätedaten der JUDO-Cloud dürfen weder abstürzen noch
 * Variablen überschreiben — und sie dürfen nur die betroffenen Werte zurückhalten.
 *
 * Beobachtet auf dem nuc (06.09. und 08.09.2026, jeweils ab 03:00 Uhr, rund 40 Minuten
 * im Minutentakt): Die JUDO-Cloud meldet das Gerät als „online" und die Datenblöcke mit
 * `st: OK`, liefert aber leere bzw. zu kurze Datenfelder. getInValue() gab dafür einen
 * Leerstring zurück, der in RefreshData_iSoftSafe() ungeprüft an decbin() ging:
 *
 *   Fatal error: Uncaught TypeError: decbin(): Argument #1 ($num) must be of type int,
 *   string given in .../JuControlDevice/module.php:974
 *
 * Vor dem Absturz hatte das Modul Geräte-ID, Versionen und Notstrommodul bereits mit
 * Leerwerten überschrieben.
 *
 * Fixture: tests/fixtures/devicedata_isoft_safe_plus.json ist der echte Datensatz einer
 * i-soft SAFE+ (Attribut DeviceData der Instanz, Stand 08.09.2026). Der unvollständige
 * Datensatz aus dem Störfenster konnte nicht mitgeschnitten werden; die Fehlerfälle
 * werden deshalb aus der echten Fixture abgeleitet (Datenfelder geleert, gekürzt, ohne
 * Blockpräfix, Block fehlt ganz, Datenliste leer).
 *
 * Alle Fälle laufen nacheinander auf DERSELBEN Instanz: Erst füllt ein vollständiger
 * Datensatz die Variablen, danach müssen die gestörten Varianten Werte und Attribut auf
 * genau diesem Stand lassen. Ein Block, den das Gerät gar nicht liefert, ist keine
 * Störung — die übrigen Werte werden weiter verarbeitet. Die Störung wird beim ersten
 * Auftreten als Warnung und bei Erholung als Hinweis protokolliert, nicht in jedem Lauf.
 *
 * Testrahmen: tests/harness.php (offizieller Kernel-Stub symcon/SymconStubs als Submodul).
 *
 * Aufruf: git submodule update --init (einmalig), dann
 *         php tests/check-incomplete-devicedata.php (Exit-Code 1 bei Fehlern)
 */

require_once __DIR__ . '/harness.php';

$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/devicedata_isoft_safe_plus.json'), true, 512, JSON_THROW_ON_ERROR);

/** Hülle um den Datensatz, wie sie RefreshData() aus „get device data" an RefreshData_iSoftSafe() reicht. */
function geraet(mixed $deviceData, string $szene = 'normal', int|string $disableTime = ''): array
{
    return [
        'serialnumber'      => 'e8eb1bee706d',
        'status'            => 'online',
        'installation_date' => '2022-03-15',
        'waterscene'        => $szene,
        'waterscene_normal' => 4,
        'hardness_shower'   => 8,
        'hardness_heater'   => 6,
        'hardness_watering' => 12,
        'hardness_washing'  => 2,
        'disable_time'      => $disableTime,
        'data'              => [['dt' => '0x33', 'sv' => '3.2n', 'data' => $deviceData]],
    ];
}

/** Antwort der Cloud auf „get device data" mit genau diesem Gerät */
function cloudAntwort(array $device): string
{
    return json_encode(['status' => 'ok', 'data' => [$device]], JSON_THROW_ON_ERROR);
}

/** Alle Datenfelder geleert — so sah der Störfall nach den Variablenänderungen aus. */
function alleFelderLeer(array $deviceData): array
{
    foreach ($deviceData as $k => $e) {
        if (is_array($e) && array_key_exists('data', $e)) {
            $deviceData[$k]['data'] = '';
        }
    }
    return $deviceData;
}

/** Führt RefreshData_iSoftSafe auf der übergebenen Instanz aus; Logs werden je Lauf gesammelt. */
function lauf(JuControlHarness $m, array $device, string $titel): void
{
    echo "\n$titel\n";
    $m->logs   = [];
    $m->writes = [];
    try {
        $m->refresh($device);
        pruefe(true, 'kein Abbruch');
    } catch (Throwable $t) {
        pruefe(false, 'kein Abbruch — ' . get_class($t) . ': ' . $t->getMessage());
    }
}

/** Idents, deren Wert vom Soll abweicht (ohne die ausgenommenen) */
function abweichungen(array $ist, array $soll, array $ausser = []): array
{
    $diff = [];
    foreach ($soll as $ident => $wert) {
        if (!in_array($ident, $ausser, true) && ($ist[$ident] ?? null) !== $wert) {
            $diff[] = sprintf('%s=%s statt %s', $ident, var_export($ist[$ident] ?? null, true), var_export($wert, true));
        }
    }
    return $diff;
}

function pruefeUnveraendert(JuControlHarness $m, array $soll, string $attrSoll, array $ausser = []): void
{
    $diff = abweichungen($m->werte(), $soll, $ausser);
    pruefe($diff === [], 'Variablen unverändert' . ($diff === [] ? '' : ' — ' . implode(', ', $diff)));
    pruefe($m->attr('DeviceData') === $attrSoll, 'Attribut DeviceData unverändert');
}

$m = neueInstanz('0x33');

/* A. Vollständiger Datensatz: wird normal verarbeitet und liefert den Sollstand */
lauf($m, geraet($fixture), 'A. Vollständiger Datensatz');
$werte = $m->werte();
pruefe($werte['deviceID'] === '236812', 'Geräte-ID 236812 aus Block 3');
pruefe($werte['swVersion'] === '3.02', 'Softwareversion 3.02 aus Block 1');
pruefe($werte['hwVersion'] === '5.10', 'Hardwareversion 5.10 aus Block 2');
pruefe($werte['hasEmergencySupply'] === true, 'Notstrommodul erkannt (Block 790)');
pruefe($werte['batteryState'] === 25, 'Batteriestand 25 % aus Block 93');
pruefe($werte['nextService'] === 29, 'nächste Wartung in 29 Tagen aus Block 7');
pruefe($werte['wsMaxWaterFlow'] === 2000, 'Max. Durchfluss 2000 l/h aus Block 792');
pruefe($werte['activeScene'] === 0 && $werte['remainingTime'] === 0, 'keine Wasserszene aktiv');
pruefe($m->attr('DeviceData') === json_encode($fixture), 'Attribut DeviceData übernommen');
pruefeProtokoll($m, 0, 0);
$soll     = $werte;
$attrSoll = $m->attr('DeviceData');

/* B. Alle Felder leer, Blöcke melden weiterhin st=OK: nichts wird angefasst, eine Warnung */
lauf($m, geraet(alleFelderLeer($fixture)), 'B. Alle Datenfelder leer (st=OK)');
pruefeUnveraendert($m, $soll, $attrSoll);
pruefe($m->writes === [], 'kein SetValue (' . count($m->writes) . ' Schreibvorgänge)');
pruefeProtokoll($m, 1, 0);
pruefe(str_contains(logsMitStufe($m, KL_WARNING)[0] ?? '', '790'), 'Warnung nennt die betroffenen Blöcke');

/* C. Nur Block 792 gekürzt, dazu läuft eine Duschszene: blockunabhängige Werte werden weiter gepflegt */
$teil               = $fixture;
$teil['792']['data'] = '2:003C';
lauf($m, geraet($teil, 'shower', time() + 3600), 'C. Nur Block 792 gekürzt, Duschszene aktiv');
pruefeUnveraendert($m, $soll, $attrSoll, ['activeScene', 'remainingTime', 'targetHardness']);
$werte = $m->werte();
pruefe($werte['activeScene'] === 1, 'Wasserszene Dusche trotzdem übernommen');
pruefe($werte['remainingTime'] >= 59, 'Restlaufzeit der Szene trotzdem gepflegt (' . $werte['remainingTime'] . ' min)');
pruefe($werte['targetHardness'] === 8, 'Sollhärte der Duschszene trotzdem gesetzt');
pruefe($werte['wsMaxWaterFlow'] === 2000, 'Block-792-Werte unverändert');
pruefeProtokoll($m, 0, 0); // Störung besteht seit B, keine erneute Warnung

/* D. Wieder vollständig: Sollstand, ein Hinweis auf die Erholung */
lauf($m, geraet($fixture), 'D. Wieder vollständig');
pruefeUnveraendert($m, $soll, $attrSoll);
pruefeProtokoll($m, 0, 1);

/* E. Nur Block 3 leer, die großen Blöcke 790–792 intakt: Geräte-ID bleibt stehen */
$nur3        = $fixture;
$nur3['3']['data'] = '';
$nur3['1']['data'] = '';
$nur3['7']['data'] = '';
$nur3['93']['data'] = '';
lauf($m, geraet($nur3), 'E. Blöcke 1, 3, 7 und 93 leer, 790–792 intakt');
pruefeUnveraendert($m, $soll, $attrSoll);
pruefeProtokoll($m, 1, 0);
pruefe(str_contains(logsMitStufe($m, KL_WARNING)[0] ?? '', '3'), 'Warnung nennt Block 3');

/* F. Wieder vollständig */
lauf($m, geraet($fixture), 'F. Wieder vollständig');
pruefeUnveraendert($m, $soll, $attrSoll);
pruefeProtokoll($m, 0, 1);

/* G. Block 791 fehlt ganz (Gerät liefert ihn nicht), Block 1 meldet eine neue Version */
$ohne791 = $fixture;
unset($ohne791['791']);
$ohne791['1']['data'] = '6E0303';
lauf($m, geraet($ohne791), 'G. Block 791 fehlt, neue Softwareversion in Block 1');
$diff = abweichungen($m->werte(), $soll, ['swVersion']);
pruefe($diff === [], 'übrige Variablen unverändert' . ($diff === [] ? '' : ' — ' . implode(', ', $diff)));
pruefe($m->werte()['swVersion'] === '3.03', 'Softwareversion 3.03 übernommen');
pruefe($m->attr('DeviceData') === json_encode($ohne791), 'Attribut DeviceData übernommen (fehlender Block ist keine Störung)');
pruefeProtokoll($m, 0, 0);

/* H. Block 792 fehlt ganz (Gerät ohne Leckageschutz-Block): Rest wird verarbeitet */
$ohne792 = $fixture;
unset($ohne792['792']);
lauf($m, geraet($ohne792), 'H. Block 792 fehlt, 790/791 vollständig');
$diff = abweichungen($m->werte(), $soll, ['swVersion']);
pruefe($diff === [], 'Variablen unverändert' . ($diff === [] ? '' : ' — ' . implode(', ', $diff)));
pruefe($m->attr('DeviceData') === json_encode($ohne792), 'Attribut DeviceData übernommen');
pruefeProtokoll($m, 0, 0);

/* I. Urlaubsmodus schalten, während das Attribut keinen Block 792 hat */
echo "\nI. RequestAction Urlaubsmodus ohne Block 792 im Attribut\n";
$m->logs   = [];
$m->writes = [];
try {
    $m->RequestAction('wsHolidayMode', 1);
    pruefe(true, 'kein Abbruch');
} catch (Throwable $t) {
    pruefe(false, 'kein Abbruch — ' . get_class($t) . ': ' . $t->getMessage());
}
pruefe($m->writes === [], 'Urlaubsmodus nicht geschrieben');
pruefeProtokoll($m, 1, 0);

/* J. Block 790 mit 66 Zeichen, aber ohne „N:"-Präfix */
$ohnePraefix = $fixture;
$ohnePraefix['790']['data'] = substr($fixture['790']['data'], 2) . '00';
$attrVorher = $m->attr('DeviceData');
$sollVorher = $m->werte();
lauf($m, geraet($ohnePraefix), 'J. Block 790 ohne Blockpräfix (66 Zeichen)');
pruefeUnveraendert($m, $sollVorher, $attrVorher);
pruefeProtokoll($m, 1, 0);

/* K. Datenliste enthält statt des Blockfelds einen Leerstring */
lauf($m, geraet(''), 'K. Datenfeld ist kein Array');
pruefeUnveraendert($m, $sollVorher, $attrVorher);
pruefeProtokoll($m, 0, 0); // Störung besteht seit J

/* L. Wieder vollständig: zurück auf den Sollstand */
lauf($m, geraet($fixture), 'L. Wieder vollständig');
pruefeUnveraendert($m, $soll, $attrSoll);
pruefeProtokoll($m, 0, 1);

/* M. Frische Instanz (Attribut leer): Urlaubsmodus schalten darf nicht abstürzen */
echo "\nM. RequestAction Urlaubsmodus auf frischer Instanz\n";
$frisch = neueInstanz();
try {
    $frisch->RequestAction('wsHolidayMode', 1);
    pruefe(true, 'kein Abbruch');
} catch (Throwable $t) {
    pruefe(false, 'kein Abbruch — ' . get_class($t) . ': ' . $t->getMessage());
}
pruefe($frisch->writes === [], 'Urlaubsmodus nicht geschrieben');
pruefeProtokoll($frisch, 1, 0);

/* N. RefreshData(): Gerät ohne Datenliste ist kein falscher Gerätetyp und stoppt den Timer nicht */
echo "\nN. RefreshData() mit leerer Datenliste\n";
$rd = neueInstanz();
$geraetOhneDaten         = geraet($fixture);
$geraetOhneDaten['data'] = [];
$rd->cloudAntwort        = cloudAntwort($geraetOhneDaten);
try {
    $ergebnis = $rd->RefreshData();
    pruefe(true, 'kein Abbruch');
} catch (Throwable $t) {
    $ergebnis = null;
    pruefe(false, 'kein Abbruch — ' . get_class($t) . ': ' . $t->getMessage());
}
pruefe(!in_array(202, $rd->status, true), 'kein Status „falscher Gerätetyp" (' . implode(',', $rd->status) . ')');
pruefe(($rd->timer['RefreshTimer'] ?? null) !== 0, 'RefreshTimer nicht abgeschaltet');
pruefe($ergebnis === false, 'RefreshData meldet Misserfolg');
pruefeProtokoll($rd, 1, 0);

/* O. RefreshData() mit vollständigem Datensatz danach: Erfolg, Erholung protokolliert */
echo "\nO. RefreshData() mit vollständigem Datensatz\n";
$rd->logs         = [];
$rd->cloudAntwort = cloudAntwort(geraet($fixture));
try {
    $ergebnis = $rd->RefreshData();
    pruefe(true, 'kein Abbruch');
} catch (Throwable $t) {
    $ergebnis = null;
    pruefe(false, 'kein Abbruch — ' . get_class($t) . ': ' . $t->getMessage());
}
pruefe($ergebnis === true, 'RefreshData meldet Erfolg');
pruefe($rd->werte()['deviceType'] === 'i-soft SAFE+' && $rd->werte()['deviceID'] === '236812', 'Gerätetyp und Geräte-ID gesetzt');
pruefe(abweichungen($rd->werte(), $soll, ['Hardness_Washing', 'Hardness_Shower', 'Hardness_Heater', 'Hardness_Watering', 'Hardness_Normal', 'Time_Shower', 'Time_Washing', 'Time_Heater', 'Time_Watering', 'deviceType']) === [], 'Variablen wie beim direkten Lauf');
pruefeProtokoll($rd, 0, 1);

ergebnis();

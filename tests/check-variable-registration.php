<?php

declare(strict_types=1);

/*
 * Registrierung der Statusvariablen (JuControlDevice).
 *
 * Anlass (09.09.2026): In RegisterVariables() stand derselbe Block für
 * wsMaxPeriodOfUse zweimal hintereinander — ein Copy-Paste-Rest. Der zweite Aufruf
 * überschreibt die Variable folgenlos, verbraucht dabei aber ein weiteres `++$position`.
 * Folge: In der Positionsfolge klaffte eine Lücke, und alle nachfolgenden Variablen
 * standen um eine Stelle zu weit hinten. Im Formular fällt das nicht auf, im Objektbaum
 * schon.
 *
 * Geprüft wird deshalb die Registrierung als Ganzes: jede Position genau einmal vergeben,
 * keine Lücke, jeder Ident nur einmal. Das fängt denselben Fehler künftig für jede
 * Variable ab, nicht nur für diese eine.
 *
 * Aufruf: git submodule update --init (einmalig), dann
 *         php tests/check-variable-registration.php (Exit-Code 1 bei Fehlern)
 */

require_once __DIR__ . '/harness.php';

$m = neueInstanz();

/* Positionen und Idents aller Statusvariablen der Instanz einsammeln */
$nachPosition = [];
$nachIdent    = [];
foreach (IPS_GetChildrenIDs($m->id()) as $kind) {
    $o = IPS_GetObject($kind);
    if ($o['ObjectType'] !== 2 /* Variable */) {
        continue;
    }
    $nachPosition[$o['ObjectPosition']][] = $o['ObjectIdent'];
    $nachIdent[$o['ObjectIdent']][]       = $o['ObjectPosition'];
}
ksort($nachPosition);

echo "\nA. Bestand\n";
pruefe($nachPosition !== [], count($nachIdent) . ' Statusvariablen registriert');
$hoechste = $nachPosition === [] ? 0 : max(array_keys($nachPosition));

echo "\nB. Jede Position genau einmal vergeben\n";
$doppelt = [];
foreach ($nachPosition as $position => $idents) {
    if (count($idents) > 1) {
        $doppelt[] = $position . ': ' . implode(', ', $idents);
    }
}
pruefe($doppelt === [], 'keine Position doppelt belegt' . ($doppelt ? ' — ' . implode(' | ', $doppelt) : ''));

echo "\nC. Keine Lücke in der Positionsfolge\n";
$luecken = [];
for ($i = 1; $i <= $hoechste; $i++) {
    if (!isset($nachPosition[$i])) {
        $luecken[] = $i;
    }
}
pruefe(
    $luecken === [],
    sprintf('Positionen 1..%d lückenlos%s', $hoechste, $luecken ? ' — fehlen: ' . implode(', ', $luecken) : '')
);

echo "\nD. Kein Ident doppelt registriert\n";
$mehrfach = [];
foreach ($nachIdent as $ident => $positionen) {
    if (count($positionen) > 1) {
        $mehrfach[] = $ident;
    }
}
pruefe($mehrfach === [], 'jeder Ident nur einmal' . ($mehrfach ? ' — mehrfach: ' . implode(', ', $mehrfach) : ''));

ergebnis();

<?php

declare(strict_types=1);

/*
 * Aufteilen einer HTTP-Antwort in Kopfzeilen und Rumpf (WebClient).
 *
 * Anlass (09.09.2026): WebClient ist die einzige Netzschicht des Moduls — jeder Cloud-Aufruf
 * läuft über SendCommand() und sendDeviceCommand(). Das Zerlegen der Antwort geschah bisher
 * ungeprüft: `strpos($output, "\r\n\r\n")` liefert `false`, wenn eine 200-Antwort keinen
 * Kopfzeilen-Trenner enthält (abgeschnittene Antwort, Abbruch mitten in den Kopfzeilen).
 * Zwei Folgen:
 *
 *  1. Ohne strict_types wurde daraus stillschweigend 0 — `substr($output, 0 + 4)` schnitt
 *     dem Rumpf die ersten vier Zeichen ab, und niemand merkte es.
 *  2. Mit `declare(strict_types=1)` in der Datei würfe `substr($output, 0, false)` einen
 *     TypeError — mitten im Netzpfad jedes Gerätekommandos.
 *
 * Deshalb ist das Zerlegen als eigene Funktion herausgezogen und wird hier geprüft; erst
 * danach ist die Regel `declare_strict_types` im Style-Regelwerk aufgenommen.
 *
 * Aufruf: php tests/check-webclient-response.php (Exit-Code 1 bei Fehlern)
 */

require_once __DIR__ . '/../libs/WebClient.php';

$pruefungen = 0;
$fehler     = [];

function pruefe(bool $ok, string $text): void
{
    global $pruefungen, $fehler;
    $pruefungen++;
    if (!$ok) {
        $fehler[] = $text;
    }
    echo($ok ? '  ok   ' : '  FEHL ') . $text . "\n";
}

/* A. Normalfall: Kopfzeilen, Trenner, Rumpf */
echo "\nA. Antwort mit Kopfzeilen-Trenner\n";
$antwort = "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nSet-Cookie: sid=abc\r\n\r\n{\"status\":\"ok\"}";
$teile   = WebClient::splitResponse($antwort);
pruefe($teile['html'] === '{"status":"ok"}', 'Rumpf vollständig: ' . var_export($teile['html'], true));
pruefe(($teile['headers']['Content-Type'] ?? null) === 'text/html', 'Kopfzeile Content-Type gelesen');
pruefe(($teile['headers']['Set-Cookie'] ?? null) === 'sid=abc', 'Kopfzeile Set-Cookie gelesen');

/* B. Der Fall, der den TypeError ausgelöst hätte: kein Trenner */
echo "\nB. Antwort ohne Kopfzeilen-Trenner\n";
$teile = WebClient::splitResponse('{"status":"ok"}');
pruefe($teile['html'] === '{"status":"ok"}', 'ganze Antwort gilt als Rumpf, nichts abgeschnitten: ' . var_export($teile['html'], true));
pruefe($teile['headers'] === [], 'keine Kopfzeilen gemeldet');

/* C. Leere Antwort */
echo "\nC. Leere Antwort\n";
$teile = WebClient::splitResponse('');
pruefe($teile['html'] === '', 'leerer Rumpf');
pruefe($teile['headers'] === [], 'keine Kopfzeilen');

/* D. Nur Kopfzeilen, leerer Rumpf */
echo "\nD. Kopfzeilen ohne Rumpf\n";
$teile = WebClient::splitResponse("HTTP/1.1 200 OK\r\nX-Test: 1\r\n\r\n");
pruefe($teile['html'] === '', 'leerer Rumpf');
pruefe(($teile['headers']['X-Test'] ?? null) === '1', 'Kopfzeile gelesen');

/* E. Kopfzeile mit Doppelpunkt im Wert (Uhrzeit) bleibt unangetastet —
 *    die Zerlegung nimmt nur Zeilen mit genau einem Doppelpunkt, das ist Bestand. */
echo "\nE. Kopfzeile mit mehreren Doppelpunkten\n";
$teile = WebClient::splitResponse("HTTP/1.1 200 OK\r\nDate: Tue, 09 Sep 2026 08:15:00 GMT\r\nX-Test: 1\r\n\r\nrumpf");
pruefe($teile['html'] === 'rumpf', 'Rumpf trotz mehrteiliger Kopfzeile');
pruefe(($teile['headers']['X-Test'] ?? null) === '1', 'nachfolgende Kopfzeile weiterhin gelesen');

echo "\n$pruefungen Prüfungen, " . count($fehler) . " Fehler\n";
exit($fehler === [] ? 0 : 1);

<?php

declare(strict_types=1);

/*
 * Regressionstest: Fehlgeschlagene Anfragen an die JUDO-Cloud (SendCommand).
 *
 * Anlass (nuc, 26.09.2026): #58649 (i-soft SAFE+, Abruf alle 100 s) schrieb zweimal
 *
 *   ERROR | PHPModule | Error during request to JuControl API:
 *     https://www.myjudo.eu/interface/?group=register&command=get+device+data&token=2db0…
 *
 * und lief beim nächsten Abruf wieder. Drei Mängel daran:
 *
 *  1. Die Ursache fehlt: weder HTTP-Code noch curl-Fehler stehen im Protokoll oder Debug.
 *  2. Das Token steht im Klartext darin (Passwort und nohash waren schon maskiert).
 *  3. Jeder einzelne Aussetzer ist ein ERROR. Wie bei den unvollständigen Cloud-Daten soll
 *     erst eine anhaltende Störung (3 Fehlschläge in Folge, ≈ 5 min bei 100 s) einmal als
 *     Warnung und die Erholung einmal als Hinweis protokolliert werden.
 *
 * Fixtures: die curl-Fehlertexte stammen aus echten Läufen des CLI-PHP (26.09.2026:
 * Verbindung zu 10.255.255.1 mit 2 s Timeout, Auflösung von nonexistent.invalid). Das Token
 * ist das aus dem Log des nuc.
 *
 * Testrahmen: tests/harness.php (offizieller Kernel-Stub symcon/SymconStubs als Submodul).
 * Die Netz-Naht ist JuControlDevice::httpGet(); SendCommand() selbst läuft echt.
 *
 * Aufruf: php tests/check-request-failures.php (Exit-Code 1 bei Fehlern)
 */

require_once __DIR__ . '/harness.php';

const TOKEN       = '2db06d967f1627494bfe5dbff3709624';
const ANTWORT_OK  = '{"status":"ok","data":[]}';
const CURL_TIMEOUT = [false, 0, 28, 'Connection timed out after 2006 milliseconds'];
const CURL_DNS     = [false, 0, 6, 'Could not resolve host: nonexistent.invalid'];
const HTTP_503     = [false, 503, 0, ''];

/* A. Fehlerbeschreibung des WebClient */
echo "A. WebClient::describeError()\n";
if (!method_exists(WebClient::class, 'describeError')) {
    pruefe(false, 'WebClient::describeError() vorhanden');
} else {
    pruefe(WebClient::describeError(200, 0, '') === '', 'HTTP 200 ohne curl-Fehler: keine Beschreibung');
    pruefe(WebClient::describeError(503, 0, '') === 'HTTP 503', 'HTTP 503: "HTTP 503"');
    pruefe(
        WebClient::describeError(0, 28, 'Connection timed out after 2006 milliseconds') === 'curl error 28: Connection timed out after 2006 milliseconds',
        'curl-Timeout: Nummer und Text'
    );
    pruefe(
        WebClient::describeError(0, 6, 'Could not resolve host: nonexistent.invalid') === 'curl error 6: Could not resolve host: nonexistent.invalid',
        'DNS-Fehler: Nummer und Text'
    );
    pruefe(WebClient::describeError(0, 0, '') === 'no response', 'weder Code noch curl-Fehler: "no response"');
}

$m = neueInstanz('0x33');
(new ReflectionMethod(JuControlDevice::class, 'WriteAttributeString'))->invoke($m, 'AccessTokenMyJudoEU', TOKEN);

if (!method_exists(JuControlDevice::class, 'httpGet')) {
    pruefe(false, 'Netz-Naht JuControlDevice::httpGet() vorhanden (SendCommand sonst nicht ohne Netz prüfbar)');
    ergebnis();
}
$m->netz = [];

/** Führt einen Abruf „get device data" mit der gegebenen Netzantwort aus. */
function abruf(JuControlHarness $m, array $antwort): string|false
{
    $m->netz[] = $antwort;
    return $m->SendCommand('https://www.myjudo.eu/interface', ['group' => 'register', 'command' => 'get device data']);
}

/** Alle Protokoll- und Debugtexte der Instanz seit Testbeginn */
function alleTexte(JuControlHarness $m): string
{
    $texte = array_map(static fn(array $l): string => $l['Message'], IPS\LogServer::getLogMessages((string)$m->id()));
    foreach (IPS\DebugServer::getDebugMessages($m->id()) as $d) {
        $texte[] = $d['Message'] . ' ' . $d['Data'];
    }
    return implode("\n", $texte);
}

function debugTexte(JuControlHarness $m): string
{
    return implode("\n", array_map(static fn(array $d): string => $d['Message'] . ' ' . $d['Data'], IPS\DebugServer::getDebugMessages($m->id())));
}

/* B. Einzelner Aussetzer */
echo "B. einzelner Aussetzer (HTTP 503)\n";
$m->logsZuruecksetzen();
pruefe(abruf($m, HTTP_503) === false, 'SendCommand liefert false');
pruefe(logsMitStufe($m, KL_ERROR) === [], 'kein ERROR im Protokoll');
pruefeProtokoll($m, 0, 0);
pruefe(str_contains(debugTexte($m), 'HTTP 503'), 'Debug nennt "HTTP 503"');
pruefe(str_contains($m->netzUrls[0] ?? '', 'token=' . TOKEN), 'die Anfrage selbst trägt das echte Token');

/* C. Erfolg nach einem einzelnen Aussetzer: kein Hinweis, denn es gab keine Warnung */
echo "C. Erfolg danach\n";
$m->logsZuruecksetzen();
pruefe(abruf($m, [ANTWORT_OK, 200, 0, '']) === ANTWORT_OK, 'SendCommand liefert die Antwort');
pruefeProtokoll($m, 0, 0);

/* D. Anhaltende Störung: Warnung genau einmal, beim dritten Fehlschlag in Folge */
echo "D. drei und mehr Fehlschläge in Folge\n";
$m->logsZuruecksetzen();
abruf($m, CURL_TIMEOUT);
abruf($m, CURL_DNS);
pruefeProtokoll($m, 0, 0);
abruf($m, CURL_TIMEOUT);
$w = logsMitStufe($m, KL_WARNING);
pruefe(count($w) === 1, 'nach dem dritten Fehlschlag genau eine Warnung');
pruefe(str_contains($w[0] ?? '', 'curl error 28: Connection timed out after 2006 milliseconds'), 'Warnung nennt den curl-Fehler');
pruefe(str_contains($w[0] ?? '', '3'), 'Warnung nennt die Zahl der Fehlschläge');
pruefe(!str_contains($w[0] ?? '', 'password') && !str_contains($w[0] ?? '', 'nohash'), 'Warnung ohne Zugangsdaten');
abruf($m, HTTP_503);
abruf($m, CURL_TIMEOUT);
pruefeProtokoll($m, 1, 0);
pruefe(logsMitStufe($m, KL_ERROR) === [], 'kein ERROR im Protokoll');

/* E. Erholung: Hinweis genau einmal */
echo "E. Erholung\n";
$m->logsZuruecksetzen();
abruf($m, [ANTWORT_OK, 200, 0, '']);
$h = logsMitStufe($m, KL_NOTIFY);
pruefeProtokoll($m, 0, 1);
pruefe(str_contains($h[0] ?? '', 'reachable again'), 'Hinweis meldet die Erholung');
abruf($m, [ANTWORT_OK, 200, 0, '']);
abruf($m, HTTP_503);
pruefeProtokoll($m, 0, 1);

/* F. Das Token taucht weder im Protokoll noch im Debug auf — auch nicht bei der Anmeldung */
echo "F. Token maskiert\n";
abruf($m, CURL_TIMEOUT);
abruf($m, CURL_TIMEOUT);
$m->netz[] = HTTP_503;
$m->SendCommand('https://www.myjudo.eu/interface', ['group' => 'register', 'command' => 'login', 'user' => 'Bumaas', 'password' => md5('geheim'), 'nohash' => 'geheim', 'token' => TOKEN]);
$m->RequestAction('saltLevel', 25); // Gerätekommando: eigener Pfad über sendDeviceCommand()
pruefe(str_contains(end($m->kommandos), 'token=' . TOKEN), 'das Gerätekommando selbst trägt das echte Token');
$texte = alleTexte($m);
pruefe(!str_contains($texte, TOKEN), 'Token nirgends im Klartext');
pruefe(!str_contains($texte, 'geheim') && !str_contains($texte, md5('geheim')), 'Passwort nirgends im Klartext');
pruefe(str_contains($texte, 'token=***'), 'Token lesbar als *** kenntlich');
pruefe(!str_contains($texte, '%2A'), 'keine URL-kodierten Sternchen');

ergebnis();

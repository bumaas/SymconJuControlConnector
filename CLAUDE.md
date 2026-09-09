# JuControlConnector — Projekt-Hinweise

Symcon-Modul zur Anbindung einer JUDO-Wasserenthärtungsanlage über die Hersteller-Cloud.
Ursprünglich von tlowcode, seit 2024 hier weitergepflegt.

## Struktur

- `JuControlDevice/` — das einzige Modul (Präfix `JCD`, `type: 3`, kein Parent)
  - `module.php` — Anmeldung, zyklischer Datenabruf, Gerätekommandos, Statusvariablen
  - `form.json` — Konfigurationsformular; **englische Texte sind zugleich die
    Übersetzungsschlüssel**
  - `locale.json` — deutsche Übersetzungen (nur `de`)
- `libs/WebClient.php` — die **einzige** Netzschicht, curl mit `CURLOPT_HEADER`
  (Fremdcode von wolbolar, deshalb im Stil abweichend)
- `libs/DebugHelper.php` — Debug-Ausgaben
- `tests/` — vier Prüfskripte plus der Kernel-Stub als Submodul (siehe unten)
- `library.json` — Version, Build, Datum (Konvention siehe globale `CLAUDE.md`)

Die Modulklasse erbt **`IPSModule`**, nicht `IPSModuleStrict` — anders als die neueren
eigenen Module. Beim Umstellen wäre der Datenfluss zu beachten (hex statt utf8), hier
allerdings ohne Parent bedeutungslos.

## Prüfen

```bash
C:/php/php tests/check-incomplete-devicedata.php   # unvollständige Cloud-Daten (90 Prüfungen)
C:/php/php tests/check-salt-refill.php             # Salzvorrat und Gerätekennung (56)
C:/php/php tests/check-variable-registration.php   # Positionen der Statusvariablen (4)
C:/php/php tests/check-webclient-response.php      # Zerlegen der HTTP-Antwort (11)
C:/php/php tests/check_locale.php                  # Übersetzungs-Vollständigkeit
```

Style (Punkt 7 der Referenz-Checkliste): eigenes schlankes Regelwerk `.php-cs-fixer.php`,
**nicht** das volle StylePHP von Symcon. Prüfen mit

```bash
php php-cs-fixer.phar fix --dry-run --diff --allow-risky=yes
```

Die CI (`.github/workflows/check.yml`, PHP 8.4) fährt genau diese Schritte plus `php -l`
und JSON-Validität. **Vor dem Commit lokal dasselbe laufen lassen** — das eingebettete PHP
von Symcon ist nicht das CLI-PHP, `php -l` findet nur Syntaxfehler.

Bibliothek auf der Produktivanlage ohne Kernel-Neustart einlesen:

```bash
C:/php/php C:/Users/Burkhard/.claude/tools/symcon_rpc.php MC_ReloadModule 51062 '"SymconJuControlConnector"'
```

## Die Cloud-Anbindung

Zwei Endpunkte, beide über `SendCommand($url, $data)`:

- `SERVER_KNM` = `https://www.myjudo.eu/interface` — Anmeldung und die meisten Kommandos
- `SERVER_JUDO` = `https://www.my-judo.com:8124` — Wasserszenen und einige Abfragen

Der Token kommt aus dem jeweiligen Attribut (`AccessTokenMyJudoEU` bzw.
`AccessTokenMyJudoCom`) und wird angehängt, wenn er nicht schon im Datensatz steht.
Passwort und `nohash` sind im Debug-Log maskiert.

**Gerätekommandos** haben die Form
`write%20data&dt=<Kennung>&index=<Register>&data=<Wert>&da=0x1`. Zwei Fallstricke:

- **Die Kennung `dt` stammt aus dem Cloud-Datensatz** (Attribut `DeviceDt`), nicht fest aus
  der Property: `0x33` = i-soft SAFE+, `0x67` = i-soft K SAFE+. Vor dem ersten `RefreshData`
  greift ersatzweise die Property; fehlt beides, wird das Kommando **nicht** gesendet
  (sonst entstünde `dt=&index=…`).
- **Register nicht aus der Dezimaldarstellung des Kommandobytes ableiten.** Für den
  Salzvorrat ist `index=94` richtig, obwohl das JUDO-Kommandobyte 0x56 = 86 wäre — am Gerät
  gemessen (SAFE+ #58649, 08.09.2026): mit 94 übernimmt die Anlage den Wert, mit 86 nicht.
  Mehrbyte-Werte gehen als Little-Endian durch `formatEndian()`.

## Cloud-Daten und ihre Störungen

`RefreshData()` holt einen Datensatz aus nummerierten **Blöcken**; ausgewertet werden nur
die in `SAFE_BLOCK_LENGTHS` genannten. Ein Block ist erst brauchbar, wenn `st === 'OK'`,
`data` ein String **und** die Länge eine der erwarteten ist (`isBlockUsable()`); die Blöcke
ab 790 müssen zusätzlich der Form `<zahl>:<64 Hexzeichen>` entsprechen.

Das ist kein Selbstzweck: Die Cloud meldet regelmäßig `st=OK` mit unbrauchbarem Datenfeld.
Ohne Prüfung landete das als `decbin()`-TypeError im Log oder als Unsinn in den Variablen.
**Unvollständige Blöcke werden blockweise zurückgehalten**, der Rest des Datensatzes wird
weiterhin ausgewertet (build 13). Gegen 03:00 Uhr liefert die Cloud regelmäßig „online" mit
leeren Blöcken — solche Läufe überspringt das Modul (build 12).

## Tests auf dem offiziellen Kernel-Stub

`tests/stubs` ist ein Submodul auf `symcon/SymconStubs`, **auf einen festen Commit gepinnt**
(Stand `bf2950f`); nie `submodule update --remote`. Einmalig `git submodule update --init`.

`tests/harness.php` hängt `JuControlDevice` an den Stub, macht die privaten Methoden
aufrufbar und ersetzt jeden Netzzugriff (`SendCommand`, `sendDeviceCommand`). Der Stub ist
strenger als eine Attrappe: `RegisterVariable*` verlangt existierende Profile mit passendem
Typ, `SetValue` castet nicht, `ReadAttribute*` wirft bei unregistriertem Attribut. **Befunde
des Stubs sind Modulfehler und werden im Modul behoben, nicht im Test kaschiert** — so kam
der fehlende `JCD.Hours`-Profileintrag ans Licht (build 14).

Seit `symcon/SymconStubs#74` zeichnet der Stub `LogMessage` selbst auf
(`IPS\LogServer::getLogMessages()`); der Harness merkt sich nur einen Offset je
Testabschnitt, weil `reset()` global wirkt.

## Stolpersteine, die schon Zeit gekostet haben

- **`WebClient::exec()` zerlegt die Antwort selbst.** Fehlt der Trenner `"\r\n\r\n"` —
  bei einer abgeschnittenen 200-Antwort denkbar —, galt früher `strpos() === false` als 0
  und schnitt dem Rumpf vier Zeichen ab. Heute fängt `splitResponse()` das ab. Der Trenner
  muss als **Escape-Sequenz** im Quelltext stehen, nicht als echte Zeilenumbrüche: Sonst
  entfernen `line_ending` und Gits `text=auto` das CR, und aus `"\r\n\r\n"` wird `"\n\n"`
  (CI rot, lokal grün — build 24).
- **Übersetzungsschlüssel sind englisch.** `Translate('Batterielaufzeit (H:MM:SS)')` stand
  jahrelang mit deutschem Text im Code, während der englische Schlüssel verwaist in der
  `locale.json` lag — auf englischer Oberfläche hieß die Variable dann deutsch (build 26).
  Gerätebezeichnungen des Herstellers (i-soft SAFE+ …) werden nicht übersetzt und stehen in
  der Ausnahmeliste `$keineUebersetzung` des Prüfskripts.
- **`RegisterVariables()` vergibt Positionen über `++$position`.** Ein doppelter
  Registrierungsblock verbrennt still eine Nummer und verschiebt alle folgenden Variablen
  (build 27). `tests/check-variable-registration.php` fängt das ab.
- **Kein Style-Workflow ohne `--dry-run`.** Der frühere `stylechecker.yml` korrigierte nur
  im Runner und meldete jahrelang grün, ohne je zu prüfen (build 25).

## Geräteabdeckung

Erprobt wird gegen eine **i-soft SAFE+** (`0x33`); die kompakte **i-soft K SAFE+** (`0x67`)
ist nur über Rückmeldungen eines Anwenders abgesichert — Änderungen an Kommandos oder
Registern dort also nicht ohne Gegenprobe. Die **i-soft plus** wird im Formular angeboten,
liefert aber einen anderen Datensatz (`RefreshData_iSoftPlus`) und ist am wenigsten erprobt.

Anlagenspezifisches (Instanz-IDs, wer welches Gerät beisteuert) gehört nicht hierher,
sondern in die nicht committete `CLAUDE.local.md`.

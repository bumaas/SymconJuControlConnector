<?php

declare(strict_types=1);

/*
 * Gemeinsamer Testrahmen: bindet JuControlDevice an den offiziellen Kernel-Stub
 * (symcon/SymconStubs, Submodul tests/stubs, gepinnt), macht die privaten Methoden für Tests
 * aufrufbar und ersetzt jeden Netzzugriff. Der Kernel trägt Properties, Attribute, Variablen,
 * Profile und Debug; der Harness zeichnet nur auf, was der Stub nicht beobachtbar macht
 * (SetValue, SetStatus, SetTimerInterval, LogMessage) sowie die abgesetzten Gerätekommandos.
 *
 * Einbinden mit require_once __DIR__ . '/harness.php'; Instanzen über neueInstanz().
 */

require_once __DIR__ . '/stubs/autoload.php';

// PHP-Warnungen/-Notices und trigger_error() des Moduls sollen Tests abbrechen, nicht still
// durchlaufen. E_USER_NOTICE bleibt außen vor (der Stub meldet so einen unbekannten Ident und
// liefert false — normaler Ablauf beim Registrieren), ebenso E_DEPRECATED (module.php meldet
// unter PHP >= 8.4 beim Laden eine Deprecation).
set_error_handler(static function (int $nr, string $text, string $datei, int $zeile): bool {
    if (!(error_reporting() & $nr)) {
        return false; // mit @ unterdrückt (z. B. @strpos in RefreshData_iSoftPlus) — kein Testfehler
    }
    if ($nr & (E_USER_ERROR | E_USER_WARNING | E_WARNING | E_NOTICE)) {
        throw new ErrorException($text, 0, $nr, $datei, $zeile);
    }
    return false;
});

require_once dirname(__DIR__) . '/JuControlDevice/module.php';

final class JuControlHarness extends JuControlDevice
{
    public const MODULE_ID = '{017837A8-4FBD-DA9C-8A3A-EEE53D12DA69}'; // JuControlDevice/module.json

    /** Antwort, die SendCommand() für jede Cloud-Anfrage liefert (RefreshData, Login) */
    public string $cloudAntwort = '';
    /** Antwort auf ein Gerätekommando aus RequestAction */
    public string $kommandoAntwort = '{"status":"ok"}';
    /** @var list<string> abgesetzte Gerätekommando-URLs aus RequestAction */
    public array $kommandos = [];
    /** @var list<array{0: string, 1: mixed}> jedes SetValue */
    public array $writes = [];
    /**
     * LogMessage zeichnet seit symcon/SymconStubs#74 (bf2950f) der Stub selbst auf
     * (IPS\LogServer, je Instanz-ID). Der Harness merkt sich nur, ab welchem Eintrag
     * ein Testabschnitt beginnt — so bleibt das Protokoll je Instanz abgrenzbar, ohne
     * den globalen LogServer zurückzusetzen.
     */
    private int $logOffset = 0;
    /** @var list<int> jedes SetStatus (auch die aus Create/ApplyChanges) */
    public array $status = [];
    /** @var array<string, int> letztes SetTimerInterval je Timer */
    public array $timer = [];

    public function id(): int
    {
        return $this->InstanceID;
    }

    /** Gerätetyp setzen, Variablen registrieren, Gerät „online" — wie eine eingerichtete Instanz. */
    public function anlegen(string $deviceType): void
    {
        self::systemProfile();
        $this->SetProperty('DeviceType', $deviceType);
        $this->ApplyChanges(); // Pending → Current; ohne Zugangsdaten nur SetStatus(205), kein Login
        (new ReflectionMethod(JuControlDevice::class, 'RegisterVariables'))->invoke($this, $deviceType);
        // direkt in den Kernel, nicht über den Recorder — sonst versucht RefreshData() erst ein Login
        SetValue(IPS_GetObjectIDByIdent('deviceState', $this->InstanceID), 'online');
    }

    /** Gerätetyp nachträglich ändern (auch auf ""), wie ein erneutes Speichern des Formulars. */
    public function geraetetypAendern(string $deviceType): void
    {
        $this->SetProperty('DeviceType', $deviceType);
        $this->ApplyChanges();
    }

    public function refresh(array $device): bool
    {
        return (new ReflectionMethod(JuControlDevice::class, 'RefreshData_iSoftSafe'))->invoke($this, $device);
    }

    public function attr(string $name): string
    {
        return $this->ReadAttributeString($name);
    }

    /** Alle Variablenwerte der Instanz (Ident → Wert) aus dem Kernel, typgetreu */
    public function werte(): array
    {
        $werte = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $vid) {
            $werte[IPS_GetObject($vid)['ObjectIdent']] = GetValue($vid);
        }
        return $werte;
    }

    public function SendCommand(string $url, array $data): string
    {
        return $this->cloudAntwort;
    }

    /** Netz-Naht der Gerätekommandos (RequestAction) */
    protected function sendDeviceCommand(string $url): string|false
    {
        $this->kommandos[] = $url;
        return $this->kommandoAntwort;
    }

    /* --- Stub-Overrides: Signaturen exakt wie tests/stubs/ModuleStubs.php (untypisierte Parameter bleiben untypisiert) --- */

    protected function getTime(): int
    {
        return time(); // RegisterTimer/SetTimerInterval brauchen eine Uhr
    }

    protected function SetValue(string $Ident, $Value): bool
    {
        $ok = parent::SetValue($Ident, $Value); // typstreng: TypeError statt Cast
        $this->writes[] = [$Ident, $Value];
        return $ok;
    }

    protected function SetStatus($Status): void
    {
        $this->status[] = $Status;
        parent::SetStatus($Status);
    }

    protected function SetTimerInterval(string $Ident, int $Milliseconds, ?int $start = null): void
    {
        $this->timer[$Ident] = $Milliseconds;
        parent::SetTimerInterval($Ident, $Milliseconds, $start);
    }

    /** Startpunkt für das Protokoll des nächsten Testabschnitts setzen. */
    public function logsZuruecksetzen(): void
    {
        $this->logOffset = count(IPS\LogServer::getLogMessages((string)$this->InstanceID));
    }

    /** @return list<array{Message: string, Type: int}> Einträge seit dem letzten Zurücksetzen */
    public function logsSeitMarke(): array
    {
        return array_values(array_slice(IPS\LogServer::getLogMessages((string)$this->InstanceID), $this->logOffset));
    }

    /** Systemprofile, die der Stub nicht mitbringt (sein ProfileManager startet leer) */
    private static function systemProfile(): void
    {
        foreach (['~Switch' => VARIABLETYPE_BOOLEAN, '~Intensity.100' => VARIABLETYPE_INTEGER, '~UnixTimestampDate' => VARIABLETYPE_INTEGER] as $name => $typ) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, $typ);
            }
        }
    }
}

/** Legt eine eingerichtete Instanz im Kernel-Stub an (Create + ApplyChanges laufen in createInstance). */
function neueInstanz(string $deviceType = '0x33'): JuControlHarness
{
    $id = IPS\ObjectManager::registerObject(1 /* Instance */);
    IPS\InstanceManager::createInstance($id, [
        'ModuleID'   => JuControlHarness::MODULE_ID,
        'ModuleName' => 'JuControlDevice',
        'ModuleType' => 3,
        'Class'      => JuControlHarness::class,
    ]);
    $m = IPS\InstanceManager::getInstanceInterface($id);
    $m->anlegen($deviceType);
    return $m;
}

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

function logsMitStufe(JuControlHarness $m, int $stufe): array
{
    return array_values(array_map(
        static fn(array $l): string => $l['Message'],
        array_filter($m->logsSeitMarke(), static fn(array $l): bool => $l['Type'] === $stufe)
    ));
}

function pruefeProtokoll(JuControlHarness $m, int $warnungen, int $hinweise): void
{
    $w = logsMitStufe($m, KL_WARNING);
    $h = logsMitStufe($m, KL_NOTIFY);
    pruefe(count($w) === $warnungen, sprintf('%d Warnung(en) protokolliert (%d: %s)', $warnungen, count($w), implode(' | ', $w)));
    pruefe(count($h) === $hinweise, sprintf('%d Hinweis(e) protokolliert (%d: %s)', $hinweise, count($h), implode(' | ', $h)));
}

/** Schlusszeile und Exit-Code */
function ergebnis(): never
{
    global $pruefungen, $fehler;
    echo "\n$pruefungen Prüfungen, " . count($fehler) . " Fehler\n";
    exit($fehler === [] ? 0 : 1);
}

IPS\Kernel::reset(); // einmal je Testlauf; weitere Instanzen entstehen im selben Kernel

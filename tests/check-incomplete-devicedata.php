<?php

declare(strict_types=1);

/*
 * Regressionstest: Ein unvollständiger Gerätedatensatz darf weder abstürzen noch
 * Variablen überschreiben.
 *
 * Beobachtet auf dem nuc (06.09. und 08.09.2026, jeweils ab 03:00 Uhr, rund 40 Minuten
 * im Minutentakt): Die JUDO-Cloud meldet das Gerät als „online" und die Datenblöcke mit
 * `st: OK`, liefert aber leere bzw. zu kurze Datenfelder. getInValue() gibt dafür einen
 * Leerstring zurück, der in RefreshData_iSoftSafe() ungeprüft an decbin() ging:
 *
 *   Fatal error: Uncaught TypeError: decbin(): Argument #1 ($num) must be of type int,
 *   string given in .../JuControlDevice/module.php:974
 *
 * Vor dem Absturz hatte das Modul Geräte-ID, Versionen und Notstrommodul bereits mit
 * Leerwerten überschrieben (alle vier Variablen wurden im ersten fehlerfreien Lauf
 * danach, 03:39:04, wieder auf ihre echten Werte geändert).
 *
 * Fixture: tests/fixtures/devicedata_isoft_safe_plus.json ist der echte Datensatz einer
 * i-soft SAFE+ (Attribut DeviceData der Instanz, Stand 08.09.2026). Der unvollständige
 * Datensatz aus dem Störfenster konnte nicht mitgeschnitten werden; die Fehlerfälle
 * werden deshalb aus der echten Fixture abgeleitet (Datenfelder geleert bzw. gekürzt).
 *
 * Aufruf: php tests/check-incomplete-devicedata.php (Exit-Code 1 bei Fehlern)
 */

foreach ([
    'VARIABLETYPE_BOOLEAN' => 0,
    'VARIABLETYPE_INTEGER' => 1,
    'VARIABLETYPE_FLOAT'   => 2,
    'VARIABLETYPE_STRING'  => 3,
    'IS_ACTIVE'            => 102,
    'IS_INACTIVE'          => 104,
    'KR_READY'             => 10103,
    'KL_MESSAGE'           => 10201,
    'KL_SUCCESS'           => 10202,
    'KL_NOTIFY'            => 10203,
    'KL_WARNING'           => 10204,
    'KL_ERROR'             => 10205,
] as $name => $wert) {
    if (!defined($name)) {
        define($name, $wert);
    }
}

/** Minimaler Symcon-Ersatz: merkt sich Variablentypen, Werte und jedes SetValue. */
abstract class IPSModule
{
    /** @var list<array{0: string, 1: mixed}> */
    public array $writes = [];
    /** @var list<string> */
    public array $logs = [];
    /** @var list<string> */
    public array $debug = [];
    /** Attribut DeviceData vor dem Lauf, zur Kontrolle */
    public string $attrVorher = '';

    /** @var array<string, int> Ident → Variablentyp */
    public array $variableTypes = [];
    /** @var array<int, string> Variablen-ID → Ident */
    public array $idents = [];

    protected array $properties = [];
    protected array $attributes = [];
    protected array $values     = [];

    public int $InstanceID = 12345;

    public function Create(): void {}

    public function ApplyChanges(): void {}

    public function RegisterPropertyInteger(string $ident, int $vorgabe): void
    {
        $this->properties[$ident] ??= $vorgabe;
    }

    public function RegisterPropertyString(string $ident, string $vorgabe): void
    {
        $this->properties[$ident] ??= $vorgabe;
    }

    public function RegisterAttributeString(string $ident, string $vorgabe): void
    {
        $this->attributes[$ident] ??= $vorgabe;
    }

    public function RegisterTimer(string $ident, int $intervall, string $skript): void {}

    public function SetTimerInterval(string $ident, int $intervall): void {}

    public function ReadPropertyInteger(string $ident): int
    {
        return (int)($this->properties[$ident] ?? 0);
    }

    public function ReadPropertyString(string $ident): string
    {
        return (string)($this->properties[$ident] ?? '');
    }

    public function ReadAttributeString(string $ident): string
    {
        return (string)($this->attributes[$ident] ?? '');
    }

    public function WriteAttributeString(string $ident, string $wert): void
    {
        $this->attributes[$ident] = $wert;
    }

    private function registerVariable(string $ident, int $typ): void
    {
        $this->variableTypes[$ident] = $typ;
        $id                          = 10000 + count($this->idents);
        $this->idents[$id]           = $ident;
        $this->values[$ident] ??= match ($typ) {
            VARIABLETYPE_BOOLEAN => false,
            VARIABLETYPE_INTEGER => 0,
            VARIABLETYPE_FLOAT => 0.0,
            default => '',
        };
    }

    public function RegisterVariableBoolean(string $ident, string $name, string $profil = '', int $position = 0): void
    {
        $this->registerVariable($ident, VARIABLETYPE_BOOLEAN);
    }

    public function RegisterVariableInteger(string $ident, string $name, string $profil = '', int $position = 0): void
    {
        $this->registerVariable($ident, VARIABLETYPE_INTEGER);
    }

    public function RegisterVariableFloat(string $ident, string $name, string $profil = '', int $position = 0): void
    {
        $this->registerVariable($ident, VARIABLETYPE_FLOAT);
    }

    public function RegisterVariableString(string $ident, string $name, string $profil = '', int $position = 0): void
    {
        $this->registerVariable($ident, VARIABLETYPE_STRING);
    }

    public function EnableAction(string $ident): void {}

    public function GetIDForIdent(string $ident): int
    {
        $id = array_search($ident, $this->idents, true);
        if ($id === false) {
            throw new RuntimeException("Unbekannter Ident: $ident");
        }
        return $id;
    }

    public function SetValue(string $ident, mixed $wert): void
    {
        $this->writes[]       = [$ident, $wert];
        $this->values[$ident] = $wert;
    }

    public function GetValue(string $ident): mixed
    {
        return $this->values[$ident];
    }

    public function SendDebug(string $kanal, string $text, int $format): void
    {
        $this->debug[] = "$kanal: $text";
    }

    public function LogMessage(string $text, int $stufe): void
    {
        $this->logs[] = $text;
    }

    public function Translate(string $text): string
    {
        return $text;
    }

    public function SetStatus(int $status): void {}
}

/** @var IPSModule|null Instanz, deren Variablentypen IPS_GetVariable() beantwortet */
$GLOBALS['jcdTestModul'] = null;

if (!function_exists('IPS_GetVariable')) {
    function IPS_GetVariable(int $id): array
    {
        $m = $GLOBALS['jcdTestModul'];
        return ['VariableType' => $m->variableTypes[$m->idents[$id]]];
    }
}
if (!function_exists('IPS_GetKernelRunlevel')) {
    function IPS_GetKernelRunlevel(): int
    {
        return KR_READY;
    }
}
foreach ([
    'IPS_VariableProfileExists'         => false,
    'IPS_CreateVariableProfile'         => null,
    'IPS_SetVariableProfileText'        => null,
    'IPS_SetVariableProfileIcon'        => null,
    'IPS_SetVariableProfileValues'      => null,
    'IPS_SetVariableProfileAssociation' => null,
    'IPS_GetVariableProfile'            => ['ProfileType' => 1],
] as $fn => $rueckgabe) {
    if (!function_exists($fn)) {
        eval("function $fn(...\$args) { return " . var_export($rueckgabe, true) . '; }');
    }
}

require_once dirname(__DIR__) . '/JuControlDevice/module.php';

/** Macht die privaten Methoden des Moduls für den Test aufrufbar. */
final class JuControlHarness extends JuControlDevice
{
    public function anlegen(): void
    {
        $this->Create();
        $this->properties['DeviceType'] = '0x33'; // i-soft SAFE+
        $ref = new ReflectionMethod(JuControlDevice::class, 'RegisterVariables');
        $ref->invoke($this, '0x33');
    }

    public function refresh(array $device): void
    {
        $ref = new ReflectionMethod(JuControlDevice::class, 'RefreshData_iSoftSafe');
        $ref->invoke($this, $device);
    }
}

$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/devicedata_isoft_safe_plus.json'), true, 512, JSON_THROW_ON_ERROR);

/** Hülle um den Datensatz, wie sie RefreshData() aus „get device data" an RefreshData_iSoftSafe() reicht. */
function geraet(array $deviceData): array
{
    return [
        'serialnumber'      => 'e8eb1bee706d',
        'installation_date' => '2022-03-15',
        'waterscene'        => 'normal',
        'data'              => [['dt' => '0x33', 'sv' => '3.2n', 'data' => $deviceData]],
    ];
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

function lauf(array $device, string $titel): JuControlHarness
{
    echo "$titel\n";
    $m = new JuControlHarness();
    $GLOBALS['jcdTestModul'] = $m;
    $m->anlegen();
    $m->writes = [];
    $attrVorher = $m->ReadAttributeString('DeviceData');
    try {
        $m->refresh($device);
        pruefe(true, 'kein Abbruch');
    } catch (Throwable $t) {
        pruefe(false, 'kein Abbruch — ' . get_class($t) . ': ' . $t->getMessage());
    }
    $m->attrVorher = $attrVorher;
    return $m;
}

/* 1. Vollständiger Datensatz: wird normal verarbeitet */
$m = lauf(geraet($fixture), 'Vollständiger Datensatz');
$werte = [];
foreach ($m->writes as [$ident, $wert]) {
    $werte[$ident] = $wert;
}
pruefe(($werte['deviceID'] ?? null) === (string)hexdec('00039D0C'), 'Geräte-ID aus Index 3 gesetzt');
pruefe(($werte['swVersion'] ?? null) === '3.02', 'Softwareversion 3.02 aus Index 1');
pruefe(($werte['hasEmergencySupply'] ?? null) === true, 'Notstrommodul erkannt');
pruefe(($werte['wsMaxWaterFlow'] ?? null) === 2000, 'Max. Durchfluss 2000 l/h aus Block 792 (steht hinter der decbin-Zeile)');
pruefe($m->ReadAttributeString('DeviceData') === json_encode($fixture), 'Attribut DeviceData übernommen');

/* 2. Alle Felder leer, Blöcke melden weiterhin st=OK */
$m = lauf(geraet(alleFelderLeer($fixture)), 'Alle Datenfelder leer (st=OK)');
pruefe($m->writes === [], 'keine Variable geschrieben (' . count($m->writes) . ' Schreibvorgänge)');
pruefe($m->ReadAttributeString('DeviceData') === $m->attrVorher, 'Attribut DeviceData unverändert');

/* 3. Nur Block 792 gekürzt, Rest intakt */
$teil        = $fixture;
$teil['792']['data'] = '2:003C';
$m = lauf(geraet($teil), 'Nur Block 792 gekürzt');
pruefe($m->writes === [], 'keine Variable geschrieben (' . count($m->writes) . ' Schreibvorgänge)');

/* 4. Block 790 fehlt ganz */
$ohne790 = $fixture;
unset($ohne790['790']);
$m = lauf(geraet($ohne790), 'Block 790 fehlt');
pruefe($m->writes === [], 'keine Variable geschrieben (' . count($m->writes) . ' Schreibvorgänge)');

/* 5. Block 792 fehlt ganz (Gerät ohne Leckageschutz-Block): Rest wird verarbeitet */
$ohne792 = $fixture;
unset($ohne792['792']);
$m = lauf(geraet($ohne792), 'Block 792 fehlt, 790/791 vollständig');
$werte = [];
foreach ($m->writes as [$ident, $wert]) {
    $werte[$ident] = $wert;
}
pruefe(($werte['swVersion'] ?? null) === '3.02', 'Softwareversion trotzdem gesetzt');
pruefe(!array_key_exists('wsMaxWaterFlow', $werte), 'Block-792-Werte nicht angefasst');

echo "\n$pruefungen Prüfungen, " . count($fehler) . " Fehler\n";
exit($fehler === [] ? 0 : 1);

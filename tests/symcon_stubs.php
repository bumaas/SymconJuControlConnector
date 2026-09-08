<?php

declare(strict_types=1);

/*
 * Minimaler Symcon-Ersatz für die Tests dieses Repos.
 *
 * Konstantenwerte wie im offiziellen SDK (PhpStorm-Stub symcon.php). Die Basisklasse
 * IPSModule merkt sich Properties, Attribute, Variablentypen und Werte, zeichnet jedes
 * SetValue, SetStatus, SetTimerInterval, LogMessage und SendDebug auf und beantwortet
 * IPS_GetVariable() aus der zuletzt erzeugten Instanz.
 *
 * Stubs mit demselben Zuschnitt: ebusdMQTT/tests/symcon_stubs.php,
 * IPSymconDenon/tests/symcon_stubs.php (je Repo eine Datei, damit die CI ohne Submodul auskommt).
 */

foreach ([
    'VARIABLETYPE_BOOLEAN' => 0,
    'VARIABLETYPE_INTEGER' => 1,
    'VARIABLETYPE_FLOAT'   => 2,
    'VARIABLETYPE_STRING'  => 3,
    'IS_ACTIVE'            => 102,
    'IS_INACTIVE'          => 104,
    'KL_NOTIFY'            => 10203,
    'KL_WARNING'           => 10204,
    'KL_ERROR'             => 10205,
] as $name => $wert) {
    if (!defined($name)) {
        define($name, $wert);
    }
}

abstract class IPSModule
{
    /** @var IPSModule|null zuletzt erzeugte Instanz — beantwortet IPS_GetVariable() */
    public static ?IPSModule $aktuell = null;

    /** @var list<array{0: string, 1: mixed}> jedes SetValue */
    public array $writes = [];
    /** @var list<array{0: int, 1: string}> jedes LogMessage als [Stufe, Text] */
    public array $logs = [];
    /** @var list<string> */
    public array $debug = [];
    /** @var list<int> jedes SetStatus */
    public array $status = [];
    /** @var array<string, int> letztes SetTimerInterval je Timer */
    public array $timer = [];

    /** @var array<string, int> Ident → Variablentyp */
    public array $variableTypes = [];
    /** @var array<int, string> Variablen-ID → Ident */
    public array $idents = [];

    protected array $properties = [];
    protected array $attributes = [];
    protected array $values     = [];

    public int $InstanceID = 12345;

    public function __construct()
    {
        self::$aktuell = $this;
    }

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

    public function SetTimerInterval(string $ident, int $intervall): void
    {
        $this->timer[$ident] = $intervall;
    }

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
        if (!array_key_exists($ident, $this->values)) {
            throw new RuntimeException("SetValue auf unbekannten Ident: $ident");
        }
        $this->writes[]       = [$ident, $wert];
        $this->values[$ident] = $wert;
    }

    public function GetValue(string $ident): mixed
    {
        if (!array_key_exists($ident, $this->values)) {
            throw new RuntimeException("GetValue auf unbekannten Ident: $ident");
        }
        return $this->values[$ident];
    }

    /** Alle Variablenwerte (Ident → Wert) für Vorher/Nachher-Vergleiche */
    public function werte(): array
    {
        return $this->values;
    }

    public function SendDebug(string $kanal, string $text, int $format): void
    {
        $this->debug[] = "$kanal: $text";
    }

    public function LogMessage(string $text, int $stufe): void
    {
        $this->logs[] = [$stufe, $text];
    }

    public function Translate(string $text): string
    {
        return $text;
    }

    public function SetStatus(int $status): void
    {
        $this->status[] = $status;
    }
}

if (!function_exists('IPS_GetVariable')) {
    function IPS_GetVariable(int $id): array
    {
        $m = IPSModule::$aktuell;
        return ['VariableType' => $m->variableTypes[$m->idents[$id]]];
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

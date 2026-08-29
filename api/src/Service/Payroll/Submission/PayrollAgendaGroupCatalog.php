<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSubmissionService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSubmissionService;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelSubmissionBridgeService;

/**
 * Zařazení agendy do skupiny, kterou ukazuje jeden panel přehledu podání.
 *
 * Dřív žila klasifikace jako ručně psaný REGEXP v SQL repozitáře a byla to
 * druhá pravda vedle konstant `AGENDA_CODE`. Rozešly se: výraz vyžadoval hned
 * za kódem `[_-]` nebo konec řetězce, jenže reálné kódy nesou ROČNÍK
 * (`JMHZ25`, `PREZEC26`, `REGZELDOPL25`) — a `ELDP` v něm nebyl vůbec.
 * Všechny takové povinnosti padaly do `other`, což je skupina, kterou žádný
 * panel nefiltroval, takže je uživatel v přehledu vůbec neviděl.
 *
 * Katalog proto vychází PŘÍMO z konstant jednotlivých služeb: ročník se z kódu
 * odřízne a zbyde základ (`JMHZ`, `PREZEC`, `REGZELDOPL`), který se zařadí do
 * skupiny. Když se konstanta příští rok posune na `JMHZ26`, klasifikace se
 * posune s ní a HISTORICKÉ řádky s `JMHZ25` zůstanou zařazené taky — proto se
 * porovnává základ, ne celý kód.
 *
 * Klasifikace zůstává na SERVERU (dřívější komentář v repozitáři platí dál):
 * přehled se stránkuje na serveru, takže kdyby si skupinu filtroval až panel
 * z přijaté stránky, pager i souhrny by počítaly řádky obou agend.
 */
final class PayrollAgendaGroupCatalog
{
    /** Podání ČSSZ — sociální zabezpečení a nemocenské pojištění. */
    public const GROUP_CSSZ = 'jmhz';

    /** Podání zdravotním pojišťovnám. */
    public const GROUP_HEALTH = 'health';

    /**
     * Zbytek. Skupina není mrtvá: `agenda_code` je u povinnosti volný text
     * (48 znaků, viz {@see PayrollObligationService::register()}), takže se do
     * ní může dostat kód, který katalog nezná — a UI pro ni musí mít panel.
     */
    public const GROUP_OTHER = 'other';

    /** @var list<string> */
    public const GROUPS = [self::GROUP_CSSZ, self::GROUP_HEALTH, self::GROUP_OTHER];

    /**
     * Kódy agend tak, jak je zapisují jednotlivé služby. Odkazuje se na
     * KONSTANTY, ne na opsané řetězce — jinak by se katalog rozešel s realitou
     * při prvním posunu ročníku.
     *
     * @var array<string,list<string>>
     */
    private const AGENDA_CODES = [
        self::GROUP_CSSZ => [
            JmhzSubmissionBridgeService::AGENDA_CODE,
            EldpStatementService::AGENDA_CODE,
            OzuspojSubmissionService::AGENDA_CODE,
            RegzelSubmissionBridgeService::AGENDA_CODE,
            PayrollRegistrationSubmissionService::AGENDA_PREZEC,
            PayrollRegistrationSubmissionService::AGENDA_REGZEC,
            PayrollRegistrationSubmissionService::AGENDA_EMPLOYER_REGISTRATION,
        ],
        self::GROUP_HEALTH => [
            HealthInsuranceSubmissionService::AGENDA_BULK_NOTIFICATION,
            HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
        ],
    ];

    /**
     * Základy kódů, které nemají konstantu ve službě: buď je zapsala starší
     * verze aplikace, nebo přijdou zvenčí (protokoly ČSSZ, ruční evidence
     * povinnosti). Zařazení jim musí zůstat, i když je dnes nic nezakládá.
     *
     * @var array<string,list<string>>
     */
    private const LEGACY_BASES = [
        self::GROUP_CSSZ => [
            'DZMH',
            'PREZAM',
            'OREZAM',
            'ZREZAM',
            'REGZEL',
        ],
        self::GROUP_HEALTH => [
            'HEALTH_HOZ',
            'HEALTH_PPZ',
        ],
    ];

    /**
     * Agendy, které do `other` patří ZÁMĚRNĚ.
     *
     * Daňová podání (EPO) nejsou mzdové povinnosti a v přehledu mzdových
     * podání nemají co dělat ani v jednom z panelů. Seznam je tu proto, aby
     * šlo poznat rozdíl mezi „rozhodli jsme se" a „zapomnělo se": kontrolní
     * test {@see \MyInvoice\Tests\Architecture\PayrollAgendaGroupCoverageTest}
     * projde všechny kódy agend v aplikaci a NOVÝ kód, který tu není a zároveň
     * nemá skupinu, shodí sadu. Bez toho by nová agenda tiše skončila v
     * `other` — přesně tak, jak to dopadlo s ročníkovými kódy ČSSZ.
     *
     * @var list<string>
     */
    private const DELIBERATE_OTHER = [
        'DPHDP3',
        'DPHKH1',
        'DPHSHV',
        'DPPDP9',
        'DPPO',
        'DPFDP5',
        'DPFDP7',
        'DPFO',
        'OSSEI1',
        'OSVC',
        // Žádosti o poukázání chybějící částky na daňovém bonusu
        // (§ 35d odst. 5 a 9). Mzdová data je živí, ale povinnost to není:
        // je to podání správci daně, které plátce podává, když CHCE své
        // peníze zpátky. Do panelů mzdových povinností nepatří.
        'DPZMB1',
        'DPZDB1',
    ];

    /**
     * Ročníkový suffix kódu: dvě nebo čtyři číslice, volitelně za oddělovačem
     * (`JMHZ25`, `HOZ_2026`). Dvouciferná varianta se zkouší první, ale kotva
     * na konec řetězce vynutí u `_2026` čtyřcifernou.
     */
    private const YEAR_SUFFIX = '_?(?:[0-9]{2}|[0-9]{4})';

    /** @var array<string,string>|null */
    private static ?array $baseGroups = null;

    /**
     * Skupina agendy podle jejího kódu.
     */
    public static function groupOf(string $agendaCode): string
    {
        return self::baseGroups()[self::baseOf($agendaCode)]
            ?? self::GROUP_OTHER;
    }

    /**
     * Patří kód do `other` vědomě? Viz {@see self::DELIBERATE_OTHER}.
     */
    public static function isDeliberatelyOther(string $agendaCode): bool
    {
        return in_array(
            self::baseOf($agendaCode),
            self::DELIBERATE_OTHER,
            true,
        );
    }

    /**
     * Tentýž předpis jako SQL výraz, aby se filtr a zobrazená hodnota nemohly
     * rozejít s PHP klasifikací.
     *
     * @param string $column SQL výraz se sloupcem `agenda_code`
     */
    public static function sqlExpression(string $column): string
    {
        $normalized = "REPLACE(UPPER(TRIM({$column})), '-', '_')";
        $sql = 'CASE';
        foreach ([self::GROUP_HEALTH, self::GROUP_CSSZ] as $group) {
            $sql .= "\n             WHEN {$normalized} REGEXP '"
                . self::pattern($group) . "' THEN '{$group}'";
        }

        return $sql . "\n             ELSE '" . self::GROUP_OTHER . "'\n         END";
    }

    /**
     * Základy kódů skupiny — pro testy a diagnostiku.
     *
     * @return list<string>
     */
    public static function basesOf(string $group): array
    {
        $bases = [];
        foreach (self::baseGroups() as $base => $assigned) {
            if ($assigned === $group) {
                $bases[] = $base;
            }
        }

        return $bases;
    }

    /** @return array<string,string> */
    private static function baseGroups(): array
    {
        if (self::$baseGroups !== null) {
            return self::$baseGroups;
        }
        $bases = [];
        foreach (self::AGENDA_CODES as $group => $codes) {
            foreach ($codes as $code) {
                $bases[self::baseOf($code)] = $group;
            }
        }
        foreach (self::LEGACY_BASES as $group => $codes) {
            foreach ($codes as $code) {
                $bases[self::baseOf($code)] = $group;
            }
        }
        // Delší základ musí stát v alternaci dřív: `REGZELDOPL` se nesmí
        // rozpadnout na `REGZEL` + zbytek.
        uksort($bases, static fn(string $a, string $b): int
            => strlen($b) <=> strlen($a) ?: strcmp($a, $b));

        return self::$baseGroups = $bases;
    }

    private static function baseOf(string $agendaCode): string
    {
        $normalized = str_replace('-', '_', strtoupper(trim($agendaCode)));

        return (string) preg_replace(
            '/' . self::YEAR_SUFFIX . '$/D',
            '',
            $normalized,
        );
    }

    private static function pattern(string $group): string
    {
        $bases = self::basesOf($group);
        foreach ($bases as $base) {
            if (preg_match('/^[A-Z][A-Z_]*$/D', $base) !== 1) {
                throw new \LogicException(
                    "Základ kódu agendy `{$base}` není bezpečný pro SQL výraz.",
                );
            }
        }

        return '^(?:' . implode('|', $bases) . ')(?:'
            . self::YEAR_SUFFIX . ')?$';
    }
}

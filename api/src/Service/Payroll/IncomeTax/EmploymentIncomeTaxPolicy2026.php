<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use UnexpectedValueException;

/**
 * Definice ALGORITMU daně ze závislé činnosti — nikoli jejích hodnot.
 *
 * Do MZ-02-W08 tahle třída držela vlastní kopii všech sazeb, slev a hranic
 * a metodou `assertCompatibleRuleset()` vyhazovala výjimku, kdykoli se ruleset
 * od kódu lišil. Administrátorská změna v obrazovce „Mzdy → Legislativní
 * pravidla" se tím nedala použít: override se uložil a výpočet daně na něj
 * spadl. Jediným zdrojem pravdy pro hodnoty je proto registry rulesetů
 * ({@see \MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026} + DB
 * override slučovaný {@see \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetRegistry}).
 *
 * Co zbylo, je tenká typovaná fasáda nad účinným rulesetem: seznam parametrů,
 * které algoritmus POTŘEBUJE, kontrola jejich úplnosti a účinnosti, a typované
 * čtení. Identita (`ID` + {@see contractHash()}) popisuje verzi algoritmu,
 * ne legislativní hodnoty — ty nese identita rulesetu, která se do výsledku
 * ukládá vedle ní. Stejný záměr jako u `PayrollNetPolicyV1`.
 */
final readonly class EmploymentIncomeTaxPolicy2026
{
    public const ID = 'cz-employment-income-tax-2026.domain.v1';

    /**
     * Peněžní parametry, bez kterých algoritmus nespočítá měsíční zálohu,
     * srážkovou daň, slevy, daňové zvýhodnění ani bonus.
     *
     * @var list<string>
     */
    private const MONEY_PARAMETERS = [
        'advance.high_threshold.monthly',
        'bonus.minimum_amount.monthly',
        'bonus.minimum_income.monthly',
        'bonus.minimum_income.yearly',
        'credit.child.first.monthly',
        'credit.child.second.monthly',
        'credit.child.third_and_next.monthly',
        'credit.disability.basic.monthly',
        'credit.disability.extended.monthly',
        'credit.taxpayer.monthly',
        'credit.ztp_p.monthly',
        'dpp.withholding.threshold',
        'other.withholding.threshold',
    ];

    /** @var list<string> */
    private const RATE_PARAMETERS = [
        'advance.high_rate',
        'advance.low_rate',
        'withholding.rate',
    ];

    /** @var list<string> */
    private const SOURCES = [
        'https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu/zamestnanci-zamestnavatele/obecne-informace',
    ];

    private function __construct(public PayrollRulesetVersion $ruleset) {}

    /**
     * Fasáda nad konkrétním účinným rulesetem. Fail-closed: chybějící nebo
     * neúčinný povinný parametr výpočet zastaví a hláška říká, co doplnit —
     * nikdy se nedopočítává z defaultu v kódu.
     */
    public static function forRuleset(PayrollRulesetVersion $ruleset): self
    {
        self::assertComplete($ruleset);

        return new self($ruleset);
    }

    /**
     * Otisk kontraktu algoritmu: co počítá a které parametry k tomu vyžaduje.
     * Hodnoty parametrů v něm záměrně NEJSOU — jinak by změna sazby v
     * administraci znamenala jinou „politiku", což je přesně ta záměna, kterou
     * MZ-02-W08 odstranilo.
     */
    public static function contractHash(): string
    {
        return hash('sha256', CanonicalJson::encode([
            'id' => self::ID,
            'required_money_parameters' => self::MONEY_PARAMETERS,
            'required_rate_parameters' => self::RATE_PARAMETERS,
            'sources' => self::SOURCES,
        ]));
    }

    public function money(string $key): int
    {
        $value = $this->ruleset->parameter($key);
        if ($value->type !== 'money_minor' || !is_int($value->value)) {
            throw new UnexpectedValueException(
                "Income tax ruleset parameter {$key} is not money.",
            );
        }

        return $value->value;
    }

    /**
     * Zná ruleset parametr, který mezi povinnými NENÍ, protože ho nemá každý
     * ročník (sleva na studenta od roku 2027)? Neznámý nebo neúčinný parametr
     * vrací `false` — volající z toho udělá překážku, ne nulu.
     */
    public function definesMoney(string $key): bool
    {
        $parameter = $this->ruleset->parameters[$key] ?? null;
        if ($parameter === null || $parameter->type !== 'money_minor') {
            return false;
        }
        try {
            $parameter->assertCalculationReady($key);
        } catch (PayrollRulesetException) {
            return false;
        }

        return true;
    }

    public function rate(string $key): string
    {
        $value = $this->ruleset->parameter($key);
        if ($value->type !== 'decimal_rate' || !is_string($value->value)) {
            throw new UnexpectedValueException(
                "Income tax ruleset parameter {$key} is not a rate.",
            );
        }

        return $value->value;
    }

    /**
     * Kontrola ÚPLNOSTI rulesetu, ne shody s kódem. Sbírá všechny vady najednou,
     * aby administrátor nedoplňoval parametry po jednom.
     */
    private static function assertComplete(PayrollRulesetVersion $ruleset): void
    {
        $problems = [];
        foreach ([
            'money_minor' => self::MONEY_PARAMETERS,
            'decimal_rate' => self::RATE_PARAMETERS,
        ] as $type => $keys) {
            foreach ($keys as $key) {
                $parameter = $ruleset->parameters[$key] ?? null;
                if ($parameter === null) {
                    $problems[] = "{$key} (chybí)";
                    continue;
                }
                try {
                    $parameter->assertCalculationReady($key);
                } catch (PayrollRulesetException) {
                    $problems[] = "{$key} (vyžaduje ruční kontrolu: {$parameter->note})";
                    continue;
                }
                if ($parameter->type !== $type) {
                    $problems[] = "{$key} (má typ {$parameter->type}, očekává se {$type})";
                }
            }
        }

        if ($problems !== []) {
            sort($problems, SORT_STRING);
            throw new PayrollRulesetException(
                "Ruleset {$ruleset->id} nemá parametry potřebné pro výpočet daně"
                . ' ze závislé činnosti; doplň je v administraci mzdových rulesetů: '
                . implode(', ', $problems) . '.',
            );
        }
    }
}

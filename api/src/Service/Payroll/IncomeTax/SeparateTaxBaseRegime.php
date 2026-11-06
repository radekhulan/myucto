<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

/**
 * Jak se daní samostatný základ podle § 6 odst. 4 ZDP — jediné místo, kde se
 * rozhoduje mezi srážkovou daní (do roku 2026) a zálohou ze samostatného
 * základu (od roku 2027).
 *
 * KTERÝ příjem do samostatného základu patří (DPP pod rozhodnou částkou pro
 * DPP, ostatní příjmy pod rozhodným příjmem, vše jen bez prohlášení u téhož
 * plátce), rozhoduje pro obě verze týž predikát
 * {@see MonthlyEmploymentIncomeTaxCalculator::withholdingGroupWithoutSignedDeclaration()}.
 * Tady se rozhoduje jen o tom, CO se s tím základem stane.
 *
 * ── Do 31. 12. 2026 ─────────────────────────────────────────────────────────
 * § 6 odst. 4 ve spojení s § 36 odst. 2 písm. m) ZDP: daň vybíraná srážkou
 * podle zvláštní sazby, základ i daň zaokrouhlené na celé koruny DOLŮ (§ 36
 * odst. 3), odvod do konce následujícího měsíce na samostatný účet (§ 38d
 * odst. 3), vyúčtování daně vybírané srážkou a potvrzení o sražené dani.
 *
 * ── Od 1. 1. 2027 ──────────────────────────────────────────────────────────
 * Zákon č. 360/2025 Sb., čl. VI body 10 a 36 (účinnost podle čl. XXXIV písm. f)
 * od 1. 1. 2027) přepsal § 6 odst. 4 a zrušil § 36 odst. 2 písm. m). Znění
 * § 6 odst. 4 ZDP k 1. 1. 2027:
 *   „Příjmy zúčtované nebo vyplacené plátcem daně jsou samostatným základem
 *    pro výpočet zálohy na daň z příjmů fyzických osob ze závislé činnosti
 *    sazbou zálohy podle § 38h, pokud zaměstnanec u tohoto plátce daně
 *    neučinil prohlášení k dani podle § 38k odst. 4, 5 nebo 7 anebo nevyužije-li
 *    postup podle § 38h odst. 10 …"
 * a § 6 odst. 18 (zák. č. 180/2026 Sb., § 30 bod 8):
 *   „Samostatný základ pro výpočet zálohy na daň z příjmů fyzických osob ze
 *    závislé činnosti se zaokrouhluje na celé koruny nahoru. Záloha na daň
 *    z příjmů fyzických osob podle odstavce 4 se zaokrouhluje na celé koruny
 *    nahoru."
 * Je to tedy ZÁLOHA (§ 38h): odvádí se s ostatními zálohami a je splatná
 * v poslední den lhůty pro hlášení k záloze (§ 38hb), vykazuje se v JMHZ
 * (§ 38ha), samostatné vyúčtování ani potvrzení o sražené dani neexistuje
 * a u téhož plátce vstupuje do ročního zúčtování (§ 38ch odst. 1 a 4 vyjímají
 * jen „příjmy podle § 6 odst. 4 plynoucí od jiného plátce daně").
 *
 * ── Rozhodný okamžik ───────────────────────────────────────────────────────
 * Čl. VII bod 6 zák. č. 360/2025 Sb.: „Pro daňové povinnosti u daně z příjmů
 * za zdaňovací období započaté přede dnem nabytí účinnosti čl. VI bodu 1 tohoto
 * zákona, jakož i pro práva a povinnosti s nimi související, se použije zákon
 * č. 586/1992 Sb., ve znění účinném přede dnem nabytí účinnosti čl. VI bodu 1."
 * Rozhoduje tedy ZDAŇOVACÍ OBDOBÍ, do kterého příjem patří, ne den výplaty —
 * prosincová mzda 2026 vyplacená v lednu 2027 je příjmem roku 2026 (§ 5 odst. 4,
 * § 38h odst. 7) a daní se srážkou. Výpočet běhu bere rok z data výpočtu daně,
 * kterým je poslední den mzdového období.
 *
 * Proto to není parametr rulesetu: přechod stanoví zákon pevným dnem a správce
 * ho nesmí „přepnout" overridem.
 */
enum SeparateTaxBaseRegime: string
{
    case FinalWithholding = 'final-withholding';
    case SeparateAdvance = 'separate-advance';

    /** První zdaňovací období se zálohou ze samostatného základu. */
    public const FIRST_SEPARATE_ADVANCE_TAX_YEAR = 2027;

    /**
     * Klíč ve zmrazeném výsledku daně osoby. Výsledek roku 2026 a starší ho
     * nenese (bajtová stabilita), výsledek od roku 2027 vždy.
     */
    public const RESULT_KEY = 'separate_base_regime';

    public static function forTaxYear(int $taxYear): self
    {
        return $taxYear >= self::FIRST_SEPARATE_ADVANCE_TAX_YEAR
            ? self::SeparateAdvance
            : self::FinalWithholding;
    }

    public static function forCalculationDate(string $calculationDate): self
    {
        if (preg_match('/^(\d{4})-\d{2}-\d{2}$/D', $calculationDate, $match) !== 1) {
            throw new \InvalidArgumentException(
                'Datum výpočtu daně musí být ve tvaru RRRR-MM-DD.',
            );
        }

        return self::forTaxYear((int) $match[1]);
    }

    /**
     * Režim ze zmrazeného výsledku daně osoby (JSON {@see MonthlyEmploymentIncomeTaxResult}).
     *
     * Klíč chybí jen u výsledků do roku 2026; výsledek roku 2027 bez něj je
     * vadný a fail-closed se odmítne — jinak by se záloha odvedla jako srážka.
     *
     * @param array<string,mixed> $result
     */
    public static function fromResultSnapshot(array $result): self
    {
        $value = $result[self::RESULT_KEY] ?? null;
        if ($value === null) {
            $date = $result['calculation_date'] ?? null;
            if (is_string($date)
                && self::forCalculationDate($date) === self::SeparateAdvance
            ) {
                throw new \DomainException(
                    'Výsledek daně od roku 2027 nenese režim samostatného základu'
                    . ' podle § 6 odst. 4 ZDP; mzdový běh je třeba přepočítat.',
                );
            }

            return self::FinalWithholding;
        }
        $regime = is_string($value) ? self::tryFrom($value) : null;
        if ($regime === null) {
            throw new \DomainException(
                'Výsledek daně nese neznámý režim samostatného základu.',
            );
        }

        return $regime;
    }

    public function isAdvance(): bool
    {
        return $this === self::SeparateAdvance;
    }

    /** Popisek částky daně na výplatní pásce. */
    public function payslipTaxLabel(): string
    {
        return match ($this) {
            self::FinalWithholding => 'Srážková daň',
            self::SeparateAdvance => 'Záloha na daň ze samostatného základu (§ 6 odst. 4 ZDP)',
        };
    }

    /** Popisek základu na mzdovém listu. */
    public function sheetBaseLabel(): string
    {
        return match ($this) {
            self::FinalWithholding => 'Základ daně zvláštní sazbou',
            self::SeparateAdvance => 'Samostatný základ zálohy (§ 6 odst. 4)',
        };
    }

    /** Popisek daně na mzdovém listu. */
    public function sheetTaxLabel(): string
    {
        return match ($this) {
            self::FinalWithholding => 'Daň zvláštní sazbou (srážková)',
            self::SeparateAdvance => 'Záloha ze samostatného základu (§ 6 odst. 4)',
        };
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;

/**
 * Co je u pracovního vztahu SJEDNÁNO nad rámec zákonného minima.
 *
 * Ruleset drží zákon, tenhle objekt smlouvu. Oddělení je záměrné: sazba
 * z kolektivní ani z pracovní smlouvy není legislativa a nesmí se dostat do
 * dodané sady, jinak by se otisk sady lišil firmu od firmy a přestala by být
 * poznána jako dodaná ({@see \MyInvoice\Service\Payroll\Ruleset\VendorRulesetManifest}).
 *
 * ── Co se smí sjednat ────────────────────────────────────────────────────────
 *
 * Vyšší sazbu vždycky. NIŽŠÍ jen u noční práce a u víkendu, protože jen § 116
 * a § 118 obsahují větu „Je možné sjednat jinou minimální výši a způsob určení
 * příplatku". § 114, § 115 a § 117 mají kogentní „nejméně" a podlézt se nedá —
 * hlídá to {@see assertAgreedRateIsLawful()} už při stavbě objektu, ne až ve
 * výpočtu, aby neplatná zásada nemohla v databázi vůbec vzniknout.
 */
final readonly class PayrollSurchargePolicy
{
    /**
     * @param array<string,int> $agreedRateBasisPoints klíč = hodnota {@see PayrollSurchargeKind}
     */
    private function __construct(
        public PayrollSurchargeCompensationMode $overtimeMode,
        public PayrollSurchargeCompensationMode $holidayMode,
        public ?int $difficultEnvironmentFactors,
        private array $agreedRateBasisPoints,
        public bool $isStatutoryDefault,
    ) {}

    /**
     * Zásada, kterou určuje sám zákon, když u vztahu není nic sjednáno.
     *
     * Přesčas: § 114 odst. 1 — příplatek. Zákon ho přiznává bez jakékoli dohody,
     * takže chybějící evidence tady nebrání výpočtu; naopak, počítat NEPŘÍPLATEK
     * by byl nedoplatek.
     *
     * Svátek: § 115 odst. 1 — náhradní volno. Tady je to obráceně a je to past:
     * vyplatit příplatek bez dohody podle odst. 2 by znamenalo zaplatit něco,
     * na co bez dohody nárok není, a zároveň nechat nevyčerpané náhradní volno.
     * Modul přitom evidenci „za tenhle svátek bylo poskytnuto volno" nemá
     * (viz {@see PayrollSurchargeEvidence::HOLIDAY_ARRANGEMENT_MISSING}), takže
     * výchozí režim svátku vede na FAIL-CLOSED, ne na tichou nulu.
     *
     * Ztížené prostředí: počet ztěžujících vlivů zákon neurčuje, ten plyne
     * z nařízení vlády a z konkrétního pracoviště — proto zůstává `null`
     * a bez doloženého počtu se § 117 nepočítá.
     */
    public static function statutoryDefault(): self
    {
        return new self(
            PayrollSurchargeCompensationMode::Surcharge,
            PayrollSurchargeCompensationMode::CompensatoryTimeOff,
            null,
            [],
            true,
        );
    }

    /**
     * @param array<string,int|null> $agreedRateBasisPoints
     */
    public static function agreed(
        PayrollSurchargeCompensationMode $overtimeMode,
        PayrollSurchargeCompensationMode $holidayMode,
        ?int $difficultEnvironmentFactors,
        array $agreedRateBasisPoints,
        PayrollSurchargeRuleset $ruleset,
    ): self {
        if ($holidayMode === PayrollSurchargeCompensationMode::IncludedInWage) {
            throw new InvalidArgumentException(
                'Mzda sjednaná s přihlédnutím k práci ve svátek neexistuje; '
                . '§ 114 odst. 3 se týká jen práce přesčas.',
            );
        }
        if ($difficultEnvironmentFactors !== null
            && ($difficultEnvironmentFactors < 0 || $difficultEnvironmentFactors > 255)
        ) {
            throw new InvalidArgumentException(
                'Počet ztěžujících vlivů podle § 117 musí být 0 až 255.',
            );
        }

        $rates = [];
        foreach ($agreedRateBasisPoints as $key => $basisPoints) {
            if ($basisPoints === null) {
                continue;
            }
            $kind = PayrollSurchargeKind::tryFrom((string) $key);
            if ($kind === null) {
                throw new InvalidArgumentException("Neznámý druh příplatku {$key}.");
            }
            self::assertAgreedRateIsLawful($kind, $basisPoints, $ruleset);
            $rates[$kind->value] = $basisPoints;
        }

        return new self(
            $overtimeMode,
            $holidayMode,
            $difficultEnvironmentFactors,
            $rates,
            false,
        );
    }

    public function mode(PayrollSurchargeKind $kind): PayrollSurchargeCompensationMode
    {
        return match ($kind) {
            PayrollSurchargeKind::Overtime => $this->overtimeMode,
            PayrollSurchargeKind::Holiday => $this->holidayMode,
            default => PayrollSurchargeCompensationMode::Surcharge,
        };
    }

    /**
     * Sazba, která se skutečně použije: sjednaná, jinak zákonné minimum.
     *
     * @return array{rate:DecimalRate, agreed:bool}
     */
    public function effectiveRate(
        PayrollSurchargeKind $kind,
        PayrollSurchargeRuleset $ruleset,
    ): array {
        $basisPoints = $this->agreedRateBasisPoints[$kind->value] ?? null;
        if ($basisPoints === null) {
            return ['rate' => $ruleset->statutoryRate($kind), 'agreed' => false];
        }

        return ['rate' => self::rateFromBasisPoints($basisPoints), 'agreed' => true];
    }

    public function agreedRateBasisPoints(PayrollSurchargeKind $kind): ?int
    {
        return $this->agreedRateBasisPoints[$kind->value] ?? null;
    }

    /**
     * Sjednaná sazba se drží v BÁZOVÝCH BODECH, ne jako desetinné číslo:
     * `0.25` v databázi jako DECIMAL by se sem vrátilo řetězcem, jehož tvar
     * závisí na ovladači, a `DecimalRate` je na kanonický tvar citlivá.
     * Celé číslo desetitisícin je jednoznačné a bezztrátové.
     */
    public static function rateFromBasisPoints(int $basisPoints): DecimalRate
    {
        if ($basisPoints < 0) {
            throw new InvalidArgumentException('Sazba příplatku nesmí být záporná.');
        }

        return DecimalRate::fromString(
            sprintf('%d.%04d', intdiv($basisPoints, 10_000), $basisPoints % 10_000),
        );
    }

    /**
     * Opačný převod: zlomek na bázové body, aby se zákonné minimum dalo ukázat
     * ve stejných jednotkách, ve kterých se sjednaná sazba zadává. Bez toho by
     * formulář srovnával 0,25 s 2500 a uživatel by nepoznal, co je víc.
     */
    public static function basisPointsOf(DecimalRate $rate): int
    {
        return \MyInvoice\Service\Payroll\Calculation\RoundingMode::HalfUp->roundFraction(
            $rate->numerator * 10_000,
            $rate->denominator,
        );
    }

    private static function assertAgreedRateIsLawful(
        PayrollSurchargeKind $kind,
        int $basisPoints,
        PayrollSurchargeRuleset $ruleset,
    ): void {
        if ($basisPoints < 0) {
            throw new InvalidArgumentException('Sazba příplatku nesmí být záporná.');
        }
        if ($kind->allowsLowerAgreedRate()) {
            return;
        }
        $statutory = $ruleset->statutoryRate($kind);
        // Porovnání zlomků křížovým součinem, ne převodem na desetinné číslo:
        // jmenovatel zákonné sazby je mocnina deseti, sjednané vždycky 10 000,
        // a převod přes float by na hranici rozhodl náhodně.
        if ($basisPoints * $statutory->denominator < $statutory->numerator * 10_000) {
            throw new InvalidArgumentException(sprintf(
                'Sjednaná sazba příplatku %s nesmí být nižší než zákonné minimum %s.',
                $kind->section(),
                $statutory->toCanonicalString(),
            ));
        }
    }
}

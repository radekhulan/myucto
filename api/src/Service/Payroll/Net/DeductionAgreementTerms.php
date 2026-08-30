<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

/**
 * Ověřené podmínky dohody o srážce. Peníze jsou výhradně celá čísla v haléřích.
 *
 * Procento je jen odvozovací pomůcka: uloží se `basis_points` i základ
 * (`basis_amount_minor`) a z nich se DETERMINISTICKY spočítá `requested_minor`.
 * Do zmrazeného vstupního snapshotu mzdového běhu tak jde vždy pevná částka —
 * výpočtová větev MZ-13 (PayrollRunStatutoryCalculationService::deductions,
 * PayrollRunDeductionLedgerApprover) porovnává výsledek proti snapshotu na
 * korunu a nesmí záviset na základu, který v okamžiku zmrazení není znám.
 */
final readonly class DeductionAgreementTerms
{
    public const KINDS = ['advance', 'meal', 'contribution', 'damage', 'other'];

    /**
     * Pásmo 1–9 je rezervované pro zákonné a exekuční pořadí, které se řeší
     * mimo tuto tabulku (modul exekucí). Pořadí uvnitř `DeductionPriorityResolver`
     * ale samo o sobě NIC nezaručuje — exekuce se počítá v jiném kroku pipeline.
     * Přednost zákonné a exekuční srážky drží až kapacita, kterou dohodám
     * přiděluje `GarnishmentCalculator::voluntaryDeductionCapacity()` (§ 148
     * odst. 2 zákoníku práce, § 276 a násl. OSŘ). Od doplnění `deliveredOn`
     * (nález E-03) to ale není nepodmíněná přednost: dohoda doručená plátci
     * mzdy DŘÍV než exekuční příkaz soutěží o obecnou nepřednostní část
     * společně s exekucemi podle § 280 odst. 5 o. s. ř., takže kapacita, kterou
     * dohody dostanou, může být větší než zbytek po exekucích. `priority_no`
     * rozhoduje až uvnitř téhož dne doručení. Viz MZ-13-W07.
     */
    public const PRIORITY_FLOOR = 10;
    public const PRIORITY_CEILING = 9999;

    public function __construct(
        public string $agreementReference,
        public string $title,
        public string $deductionKind,
        public int $priorityNo,
        public int $requestedMinor,
        public ?int $basisPoints,
        public ?int $basisAmountMinor,
        public ?int $totalLimitMinor,
        public string $validFrom,
        public ?string $validTo,
        public ?string $recipientReference,
        public ?string $note,
        /**
         * Den, kdy byla dohoda doručena plátci mzdy (§ 2045 odst. 2 OZ). Od něj
         * se podle § 148 odst. 2 zákoníku práce ve spojení s § 280 odst. 5
         * o. s. ř. odvozuje POŘADÍ dohody vůči exekučním pohledávkám.
         * `null` je legacy stav dohod zaevidovaných dřív, než se den doručení
         * ukládal — takové se řadí fail-closed až za všechny se známým datem.
         */
        public ?string $deliveredOn = null,
    ) {}

    /**
     * @param array<string,mixed> $body
     */
    public static function fromRequest(
        array $body,
        ?self $current = null,
    ): self {
        $reference = self::optionalText($body['agreement_reference'] ?? null, 'agreement_reference', 96)
            ?? $current?->agreementReference
            ?? self::generateReference();
        $title = self::requiredText($body['title'] ?? null, 'title', 190);
        $kind = self::requiredText($body['deduction_kind'] ?? null, 'deduction_kind', 32);
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Titul srážky není podporovaný.');
        }
        $priority = self::integer($body['priority_no'] ?? null, 'priority_no');
        if ($priority < self::PRIORITY_FLOOR || $priority > self::PRIORITY_CEILING) {
            throw new \InvalidArgumentException(
                'Pořadí dobrovolné srážky musí být mezi '
                . self::PRIORITY_FLOOR . ' a ' . self::PRIORITY_CEILING
                . ' — nižší pásmo patří zákonným a exekučním srážkám.',
            );
        }

        $basisPoints = self::optionalInteger($body['basis_points'] ?? null, 'basis_points');
        $basisAmount = self::optionalInteger($body['basis_amount_minor'] ?? null, 'basis_amount_minor');
        if ($basisPoints === null && $basisAmount !== null) {
            throw new \InvalidArgumentException('Základ pro procentní srážku nemá zadané procento.');
        }
        if ($basisPoints !== null) {
            if ($basisPoints < 0 || $basisPoints > 10_000) {
                throw new \InvalidArgumentException('Procento srážky musí být mezi 0 a 100 %.');
            }
            if ($basisAmount === null || $basisAmount < 0) {
                throw new \InvalidArgumentException('Procentní srážka vyžaduje nezáporný základ.');
            }
            $requested = self::percentageAmount($basisAmount, $basisPoints);
        } else {
            $requested = self::integer($body['requested_minor'] ?? null, 'requested_minor');
        }
        if ($requested < 0) {
            throw new \InvalidArgumentException('Požadovaná srážka nesmí být záporná.');
        }

        $limit = self::optionalInteger($body['total_limit_minor'] ?? null, 'total_limit_minor');
        if ($limit !== null && $limit < 0) {
            throw new \InvalidArgumentException('Limit dohody nesmí být záporný.');
        }
        $validFrom = self::date($body['valid_from'] ?? null, 'valid_from');
        $validTo = self::optionalDate($body['valid_to'] ?? null, 'valid_to');
        if ($validTo !== null && $validTo < $validFrom) {
            throw new \InvalidArgumentException('Konec účinnosti nesmí předcházet začátku.');
        }
        // Doručení dohody plátci mzdy nemá k účinnosti srážek pevný vztah:
        // dohoda může být doručena dřív (typicky týž den, kdy byla uzavřena)
        // i později. Vylučuje se jen to, co je nesporně chybné — den doručení
        // po skončení účinnosti, kdy by z něj už nemohlo plynout žádné pořadí.
        $deliveredOn = self::optionalDate($body['delivered_on'] ?? null, 'delivered_on');
        if ($deliveredOn !== null && $validTo !== null && $deliveredOn > $validTo) {
            throw new \InvalidArgumentException(
                'Den doručení dohody plátci mzdy nesmí následovat až po konci účinnosti.',
            );
        }

        return new self(
            $reference,
            $title,
            $kind,
            $priority,
            $requested,
            $basisPoints,
            $basisPoints === null ? null : $basisAmount,
            $limit,
            $validFrom,
            $validTo,
            self::optionalText($body['recipient_reference'] ?? null, 'recipient_reference', 190),
            self::optionalText($body['note'] ?? null, 'note', 500),
            $deliveredOn,
        );
    }

    public static function percentageAmount(int $baseMinorUnits, int $basisPoints): int
    {
        $whole = intdiv($baseMinorUnits, 10_000) * $basisPoints;
        $fraction = intdiv(($baseMinorUnits % 10_000) * $basisPoints, 10_000);

        return $whole + $fraction;
    }

    private static function generateReference(): string
    {
        return 'srazka-' . bin2hex(random_bytes(8));
    }

    private static function requiredText(mixed $value, string $field, int $max): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Pole {$field} musí být text.");
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new \InvalidArgumentException("Pole {$field} nesmí být prázdné.");
        }
        if (mb_strlen($trimmed, 'UTF-8') > $max) {
            throw new \InvalidArgumentException("Pole {$field} může mít nejvýše {$max} znaků.");
        }

        return $trimmed;
    }

    private static function optionalText(mixed $value, string $field, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::requiredText($value, $field, $max);
    }

    private static function integer(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]{0,17})$/D', $value) === 1) {
            return (int) $value;
        }

        throw new \InvalidArgumentException("Pole {$field} musí být celé číslo.");
    }

    private static function optionalInteger(mixed $value, string $field): ?int
    {
        return $value === null || $value === '' ? null : self::integer($value, $field);
    }

    private static function date(mixed $value, string $field): string
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException("Pole {$field} musí být datum ve tvaru RRRR-MM-DD.");
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        if (!checkdate($month, $day, $year)) {
            throw new \InvalidArgumentException("Pole {$field} není platné datum.");
        }

        return $value;
    }

    private static function optionalDate(mixed $value, string $field): ?string
    {
        return $value === null || $value === '' ? null : self::date($value, $field);
    }
}

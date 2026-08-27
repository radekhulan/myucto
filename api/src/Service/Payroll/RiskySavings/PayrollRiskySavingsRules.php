<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\RiskySavings;

use DateTimeImmutable;
use InvalidArgumentException;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Ruleset\PayrollRuleValue;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;

/**
 * Immutable calculation inputs selected from the effective social-insurance
 * ruleset. The complete value is retained in the payroll-run snapshot so a
 * later ruleset change cannot alter an already locked payroll period.
 */
final readonly class PayrollRiskySavingsRules
{
    private const SNAPSHOT_SCHEMA = 'payroll-risky-savings-rules.v1';
    private const PAYMENT_DUE_LAST_DAY_OF_MONTH = 'last_day_of_month';

    private function __construct(
        public string $rulesetId,
        public string $rulesetSha256,
        public string $effectiveFrom,
        public int $minimumShiftEighths,
        public DecimalRate $rate,
        public int $paymentDueMonthsAfterPeriod,
        public string $paymentDueRule,
    ) {}

    public static function fromProvider(PayrollRulesetProvider $provider, string $periodStart): self
    {
        return self::fromRuleset(
            $provider->forCalculation(PayrollRulesetDomain::SocialInsurance, $periodStart),
        );
    }

    public static function fromRuleset(PayrollRulesetVersion $ruleset): self
    {
        if ($ruleset->domain !== PayrollRulesetDomain::SocialInsurance) {
            throw new PayrollRulesetException('Risky-savings rules must come from the social-insurance ruleset.');
        }
        $ruleset->assertCalculationReady();

        return self::create(
            $ruleset->id,
            $ruleset->canonicalHash,
            self::text($ruleset->parameter('risky_savings.effective_from'), 'risky_savings.effective_from'),
            self::integer($ruleset->parameter('risky_savings.minimum_shift_eighths'), 'risky_savings.minimum_shift_eighths'),
            self::rate($ruleset->parameter('risky_savings.rate'), 'risky_savings.rate'),
            self::integer($ruleset->parameter('risky_savings.payment_due.months_after_period'), 'risky_savings.payment_due.months_after_period'),
            self::text($ruleset->parameter('risky_savings.payment_due.rule'), 'risky_savings.payment_due.rule'),
        );
    }

    /** @param array<string,mixed> $snapshot */
    public static function fromSnapshot(array $snapshot): self
    {
        if (($snapshot['schema'] ?? null) !== self::SNAPSHOT_SCHEMA) {
            throw new InvalidArgumentException('Snapshot povinného spoření má neznámé schéma.');
        }
        foreach (['ruleset_id', 'ruleset_sha256', 'effective_from', 'rate', 'payment_due_rule'] as $key) {
            if (!is_string($snapshot[$key] ?? null)) {
                throw new InvalidArgumentException("Snapshot povinného spoření nemá textové pole {$key}.");
            }
        }
        foreach (['minimum_shift_eighths', 'payment_due_months_after_period'] as $key) {
            if (!is_int($snapshot[$key] ?? null)) {
                throw new InvalidArgumentException("Snapshot povinného spoření nemá celočíselné pole {$key}.");
            }
        }

        return self::create(
            $snapshot['ruleset_id'],
            $snapshot['ruleset_sha256'],
            $snapshot['effective_from'],
            $snapshot['minimum_shift_eighths'],
            DecimalRate::fromString($snapshot['rate']),
            $snapshot['payment_due_months_after_period'],
            $snapshot['payment_due_rule'],
        );
    }

    /** @return array<string,int|string> */
    public function toSnapshot(): array
    {
        return [
            'effective_from' => $this->effectiveFrom,
            'minimum_shift_eighths' => $this->minimumShiftEighths,
            'payment_due_months_after_period' => $this->paymentDueMonthsAfterPeriod,
            'payment_due_rule' => $this->paymentDueRule,
            'rate' => $this->rate->toCanonicalString(),
            'ruleset_id' => $this->rulesetId,
            'ruleset_sha256' => $this->rulesetSha256,
            'schema' => self::SNAPSHOT_SCHEMA,
        ];
    }

    private static function create(
        string $rulesetId,
        string $rulesetSha256,
        string $effectiveFrom,
        int $minimumShiftEighths,
        DecimalRate $rate,
        int $paymentDueMonthsAfterPeriod,
        string $paymentDueRule,
    ): self {
        if ($rulesetId === '' || preg_match('/^[0-9a-f]{64}$/D', $rulesetSha256) !== 1) {
            throw new InvalidArgumentException('Snapshot povinného spoření nemá platnou identitu rulesetu.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveFrom);
        if ($date === false || $date->format('Y-m-d') !== $effectiveFrom) {
            throw new InvalidArgumentException('Účinnost povinného spoření musí mít formát YYYY-MM-DD.');
        }
        if ($minimumShiftEighths < 0 || $paymentDueMonthsAfterPeriod < 0) {
            throw new InvalidArgumentException('Číselné parametry povinného spoření nesmí být záporné.');
        }
        if ($rate->numerator <= 0 || $rate->numerator > $rate->denominator) {
            throw new InvalidArgumentException('Sazba povinného spoření musí být v intervalu (0, 1].');
        }
        if ($paymentDueRule !== self::PAYMENT_DUE_LAST_DAY_OF_MONTH) {
            throw new InvalidArgumentException('Pravidlo splatnosti povinného spoření není podporováno.');
        }

        return new self(
            $rulesetId,
            $rulesetSha256,
            $effectiveFrom,
            $minimumShiftEighths,
            $rate,
            $paymentDueMonthsAfterPeriod,
            $paymentDueRule,
        );
    }

    private static function text(PayrollRuleValue $value, string $key): string
    {
        if ($value->type !== 'text' || !is_string($value->value)) {
            throw new PayrollRulesetException("Parameter {$key} must be text.");
        }

        return $value->value;
    }

    private static function integer(PayrollRuleValue $value, string $key): int
    {
        if ($value->type !== 'integer' || !is_int($value->value)) {
            throw new PayrollRulesetException("Parameter {$key} must be an integer.");
        }

        return $value->value;
    }

    private static function rate(PayrollRuleValue $value, string $key): DecimalRate
    {
        if ($value->type !== 'decimal_rate' || !is_string($value->value)) {
            throw new PayrollRulesetException("Parameter {$key} must be a decimal rate.");
        }

        return DecimalRate::fromString($value->value);
    }
}

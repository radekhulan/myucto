<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Service\Payroll\IncomeTax\ChildCreditRateKey;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use Throwable;

/**
 * Náhled měsíčního daňového zvýhodnění pro evidenci vyživovaných osob.
 *
 * Slouží VÝHRADNĚ k zobrazení v kartě osoby — závazný výpočet zůstává
 * v MonthlyEmploymentIncomeTaxCalculator nad snímkem mzdové revize. Sdílený je
 * jen klíč sazby (ChildCreditRateKey) a zdroj sazby (ruleset), takže se náhled
 * nemůže rozejít s výpočtem vlastní kopií částek.
 *
 * Fail-closed: když ruleset pro dané datum neexistuje, není aktivní nebo sazbu
 * nedefinuje, náhled nevrátí žádnou částku a označí stav `manual_review`.
 */
final class PayrollDependantCreditPreview
{
    public const STATUS_CALCULATED = 'calculated';
    public const STATUS_MANUAL_REVIEW = 'manual_review';

    public function __construct(
        private readonly PayrollRulesetProvider $rulesets,
    ) {}

    /**
     * @return array{
     *   status:string,
     *   rate_key:?string,
     *   monthly_credit_minor_units:?int,
     *   manual_review_reason:?string
     * }
     */
    public function monthly(int $order, bool $ztpP, string $effectiveOn): array
    {
        try {
            $key = ChildCreditRateKey::forOrder($order);
            $parameter = $this->rulesets
                ->forCalculation(PayrollRulesetDomain::IncomeTax, $effectiveOn)
                ->parameter($key);
            $amount = $parameter->value;
            if ($parameter->type !== 'money_minor' || !is_int($amount) || $amount < 0) {
                return $this->manualReview(
                    $key,
                    'Sazba zvýhodnění není v rulesetu částkou v haléřích.',
                );
            }

            return [
                'status' => self::STATUS_CALCULATED,
                'rate_key' => $key,
                // § 35c odst. 7 ZDP — u dítěte ZTP/P je zvýhodnění dvojnásobné.
                'monthly_credit_minor_units' => $ztpP ? $amount * 2 : $amount,
                'manual_review_reason' => null,
            ];
        } catch (Throwable $exception) {
            return $this->manualReview(null, $exception->getMessage());
        }
    }

    /**
     * Dítě s pořadím „N": zvýhodnění uplatňuje jiná osoba v domácnosti, u
     * tohoto zaměstnance z něj nevzniká nic. Nula je tu výsledek, ne neznámo.
     *
     * @return array{
     *   status:string,
     *   rate_key:?string,
     *   monthly_credit_minor_units:?int,
     *   manual_review_reason:?string
     * }
     */
    public function claimedByOther(): array
    {
        return [
            'status' => self::STATUS_CALCULATED,
            'rate_key' => null,
            'monthly_credit_minor_units' => 0,
            'manual_review_reason' => null,
        ];
    }

    /**
     * @return array{
     *   status:string,
     *   rate_key:?string,
     *   monthly_credit_minor_units:?int,
     *   manual_review_reason:?string
     * }
     */
    private function manualReview(?string $key, string $reason): array
    {
        return [
            'status' => self::STATUS_MANUAL_REVIEW,
            'rate_key' => $key,
            'monthly_credit_minor_units' => null,
            'manual_review_reason' => $reason,
        ];
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

use JsonSerializable;

/**
 * Zákonné příplatky jednoho pracovního vztahu za jeden měsíc.
 *
 * `supportStatus` je vždycky `manual_review`, stejně jako u náhrady mzdy při
 * DPN: výpočet je deterministický, ale NÁROK (sjednaná dohoda o náhradním
 * volnu, zařazení pracoviště do ztíženého prostředí, úplnost docházky) je
 * skutkové posouzení mzdové účetní a aplikace ho nenahradí.
 */
final readonly class PayrollSurchargeResult implements JsonSerializable
{
    /**
     * @param list<PayrollSurchargeLine> $lines
     * @param list<array{reason:string,message:string,local_date:?string}> $findings
     */
    public function __construct(
        public string $periodStart,
        public int $totalMinor,
        public array $lines,
        public array $findings,
        public string $rulesetId,
        public string $rulesetContentHash,
        public string $supportStatus = 'manual_review',
    ) {}

    public function lineFor(PayrollSurchargeKind $kind): ?PayrollSurchargeLine
    {
        foreach ($this->lines as $line) {
            if ($line->kind === $kind) {
                return $line;
            }
        }

        return null;
    }

    public function amountFor(PayrollSurchargeKind $kind): int
    {
        return $this->lineFor($kind)?->amountMinor ?? 0;
    }

    /**
     * Rozpad na mzdové vstupy: kód složky => částka v haléřích.
     *
     * Nulové řádky se nevydávají. Mzdový vstup na nula korun by v přehledu
     * i na výplatní pásce tvrdil, že příplatek vznikl, jen byl nulový — což je
     * něco jiného než „nevznikl".
     *
     * @return array<string,int>
     */
    public function componentAmounts(): array
    {
        $amounts = [];
        foreach ($this->lines as $line) {
            if ($line->amountMinor === 0) {
                continue;
            }
            $code = $line->kind->componentCode();
            $amounts[$code] = ($amounts[$code] ?? 0) + $line->amountMinor;
        }

        return $amounts;
    }

    public function requiresManualReview(): bool
    {
        return $this->findings !== [];
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'kind' => 'payroll_surcharges.v1',
            'period_start' => $this->periodStart,
            'total_minor' => $this->totalMinor,
            'support_status' => $this->supportStatus,
            'ruleset_id' => $this->rulesetId,
            'ruleset_content_hash' => $this->rulesetContentHash,
            'lines' => array_map(
                static fn (PayrollSurchargeLine $line): array => $line->jsonSerialize(),
                $this->lines,
            ),
            'findings' => $this->findings,
        ];
    }
}

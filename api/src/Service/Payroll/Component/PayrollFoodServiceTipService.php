<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollFoodServiceTipRepository;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;

/**
 * Spropitné ze stravovacích služeb (§ 6 odst. 9 písm. g) ZDP, od 2027):
 * evidence měsíce a strop osvobození jednoho zaměstnance.
 *
 * Strop se zmrazí do mzdového vstupu při schválení stejně jako rozpad koše
 * benefitů ({@see PayrollBenefitBasketService::splitWithLimit()}). Proto se
 * evidence měsíce, ve kterém už je schválené spropitné, nedá změnit — jinak by
 * zmrazené rozpady tvrdily strop, který už neplatí.
 */
final class PayrollFoodServiceTipService
{
    public const MODE_PER_CAPITA = 'per_capita';
    public const MODE_EMPLOYER_DETERMINED = 'employer_determined';

    private const MAX_ALLOCATIONS = 2_000;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollFoodServiceTipRepository $tips,
    ) {}

    /**
     * Strop osvobozeného spropitného zaměstnance za měsíc.
     *
     * Fail-closed: bez evidence měsíce strop neexistuje a vstup se neschválí —
     * zdanit celé spropitné by tiše připravilo zaměstnance o osvobození,
     * osvobodit ho bez tržeb by porušilo strop. Před rokem 2027 je strop nula.
     *
     * @throws PayrollRulesetException evidence chybí nebo jí odporují schválené vstupy
     */
    public function limitMinor(int $supplierId, int $employeeId, string $periodStart): int
    {
        if (!PayrollFoodServiceTipStatute::isEffective((int) substr($periodStart, 0, 4))) {
            return 0;
        }
        $month = $this->tips->find($supplierId, $periodStart);
        if ($month === null) {
            throw new PayrollRulesetException(
                'Pro měsíc chybí evidence příjmů ze stravovacích služeb, ze které se počítá '
                . 'strop osvobozeného spropitného (7 % podle ' . PayrollFoodServiceTipStatute::STATUTE . ').',
            );
        }
        if ($month['allocation_mode'] === self::MODE_EMPLOYER_DETERMINED) {
            foreach ($month['allocations'] as $allocation) {
                if ($allocation['employee_id'] === $employeeId) {
                    return $allocation['exempt_cap_minor'];
                }
            }

            return 0;
        }

        $recipients = (int) $month['recipients_count'];
        $approved = $this->tips->approvedRecipients($supplierId, $periodStart);
        if (!in_array($employeeId, $approved, true)) {
            $approved[] = $employeeId;
        }
        // Podíl se počítá z počtu zaměstnanců, kteří spropitné obdrželi. Je-li
        // jich podle schválených vstupů víc, než kolik evidence tvrdí, byl by
        // dosavadní podíl vyšší, než zákon dovoluje.
        if (count($approved) > $recipients) {
            throw new PayrollRulesetException(
                'Spropitné by v měsíci dostalo víc zaměstnanců (' . count($approved)
                . '), než kolik uvádí evidence (' . $recipients . '). Opravte počet v evidenci '
                . 'spropitného; schválené vstupy měsíce je k tomu nutné nejdřív stornovat.',
            );
        }

        return PayrollFoodServiceTipStatute::perCapitaCapMinor($month['revenue_minor'], $recipients);
    }

    /**
     * @return array<string,mixed>
     */
    public function view(int $supplierId, string $periodStart): array
    {
        $month = $this->tips->find($supplierId, $periodStart);
        $approved = $this->tips->approvedRecipients($supplierId, $periodStart);

        return [
            'period_start' => $periodStart,
            'available' => PayrollFoodServiceTipStatute::isEffective((int) substr($periodStart, 0, 4)),
            'statute' => PayrollFoodServiceTipStatute::STATUTE,
            'month' => $month === null ? null : [
                ...$month,
                'employer_cap_minor' => PayrollFoodServiceTipStatute::employerCapMinor($month['revenue_minor']),
                'per_capita_cap_minor' => $month['allocation_mode'] === self::MODE_PER_CAPITA
                    ? PayrollFoodServiceTipStatute::perCapitaCapMinor(
                        $month['revenue_minor'],
                        (int) $month['recipients_count'],
                    )
                    : null,
            ],
            'approved_recipients' => $approved,
            'locked' => $approved !== [],
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     * @throws \InvalidArgumentException neplatný vstup (422)
     * @throws \DomainException měsíc je zamčený schválenými vstupy (409)
     */
    public function save(int $supplierId, string $periodStart, array $input, ?int $actorUserId): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])-01$/D', $periodStart) !== 1) {
            throw new \InvalidArgumentException('Měsíc evidence spropitného není platný.');
        }
        if (!PayrollFoodServiceTipStatute::isEffective((int) substr($periodStart, 0, 4))) {
            throw new \InvalidArgumentException(
                'Osvobození spropitného platí od roku ' . PayrollFoodServiceTipStatute::FIRST_YEAR . '.',
            );
        }
        $revenue = self::integer($input['revenue_minor'] ?? null, 'Příjmy ze stravovacích služeb');
        if ($revenue === null || $revenue < 0) {
            throw new \InvalidArgumentException('Příjmy ze stravovacích služeb musí být nezáporná částka.');
        }
        $mode = (string) ($input['allocation_mode'] ?? '');
        if (!in_array($mode, [self::MODE_PER_CAPITA, self::MODE_EMPLOYER_DETERMINED], true)) {
            throw new \InvalidArgumentException('Způsob rozdělení spropitného není platný.');
        }
        $reference = self::text($input['evidence_reference'] ?? null, 500, 'Doklad');
        $note = self::text($input['note'] ?? null, 500, 'Poznámka');

        $recipients = null;
        $allocations = [];
        if ($mode === self::MODE_PER_CAPITA) {
            $recipients = self::integer($input['recipients_count'] ?? null, 'Počet zaměstnanců');
            if ($recipients === null || $recipients < 1 || $recipients > 65_535) {
                throw new \InvalidArgumentException(
                    'Počet zaměstnanců, kteří spropitné obdrželi, musí být kladný.',
                );
            }
        } else {
            $rows = $input['allocations'] ?? [];
            if (!is_array($rows) || count($rows) > self::MAX_ALLOCATIONS) {
                throw new \InvalidArgumentException('Rozdělení spropitného není platné.');
            }
            $seen = [];
            $total = 0;
            foreach (array_values($rows) as $row) {
                if (!is_array($row)) {
                    throw new \InvalidArgumentException('Rozdělení spropitného není platné.');
                }
                $employeeId = self::integer($row['employee_id'] ?? null, 'Zaměstnanec');
                $cap = self::integer($row['exempt_cap_minor'] ?? null, 'Strop');
                if ($employeeId === null || $employeeId <= 0 || $cap === null || $cap < 0) {
                    throw new \InvalidArgumentException('Rozdělení spropitného není platné.');
                }
                if (isset($seen[$employeeId])) {
                    throw new \InvalidArgumentException('Zaměstnanec je v rozdělení spropitného dvakrát.');
                }
                $seen[$employeeId] = true;
                $total += $cap;
                $allocations[] = ['employee_id' => $employeeId, 'exempt_cap_minor' => $cap];
            }
            // „tak, aby úhrn osvobozeného spropitného … nepřekročil 7 %" — neostře.
            if ($total > PayrollFoodServiceTipStatute::employerCapMinor($revenue)) {
                throw new \InvalidArgumentException(
                    'Úhrn určených stropů přesahuje 7 % měsíčních příjmů ze stravovacích služeb.',
                );
            }
            $existing = $this->tips->existingEmployees($supplierId, array_keys($seen));
            if (count($existing) !== count($seen)) {
                throw new \InvalidArgumentException('Vybraný zaměstnanec v této firmě není.');
            }
        }

        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            if ($this->tips->approvedRecipients($supplierId, $periodStart) !== []) {
                throw new \DomainException(
                    'V měsíci je schválené spropitné se zmrazeným stropem. Evidenci lze změnit '
                    . 'až po stornu schválených vstupů spropitného.',
                );
            }
            $this->tips->save(
                $supplierId,
                $periodStart,
                $revenue,
                $mode,
                $recipients,
                $allocations,
                $reference,
                $note,
                $actorUserId,
            );
            if ($owns) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $this->view($supplierId, $periodStart);
    }

    private static function integer(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('~^-?\d{1,15}$~', $value) === 1) {
            return (int) $value;
        }

        throw new \InvalidArgumentException("{$label} není celé číslo.");
    }

    private static function text(mixed $value, int $max, string $label): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $text = trim($value);
        if (mb_strlen($text) > $max) {
            throw new \InvalidArgumentException("{$label} je delší než {$max} znaků.");
        }

        return $text;
    }
}

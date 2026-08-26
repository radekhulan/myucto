<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;

/**
 * Počáteční stavy mzdových kumulací.
 *
 * Zaměstnanec, který nastoupil dřív, než firma začala vést mzdy v MyÚčtu, nemá
 * za uzavřené měsíce žádnou revizi. Bez počátečního stavu vypadne z dávky
 * zákonného výpočtu, celý běh spadne do `manual_review` a přebít se to nedá —
 * override pracuje nad řádky validací, kdežto tohle je issue statutory bundlu.
 *
 * Uživatel zadává ÚHRNY PO MĚSÍCÍCH, protože tak je má v sestavě z předchozího
 * programu. Kumulace je ale roční součet, takže se tady sečtou; rozpis měsíců
 * jde do `evidence`, aby z čeho součet vznikl zůstalo dohledatelné.
 *
 * @phpstan-type OpeningMonth array{
 *   month:int,
 *   social_assessment_base_minor_units:int,
 *   advance_base_minor_units:int,
 *   advance_tax_minor_units:int,
 *   withholding_base_minor_units:int,
 *   withholding_tax_minor_units:int,
 *   applied_non_refundable_credits_minor_units:int,
 *   applied_child_credit_minor_units:int,
 *   tax_bonus_minor_units:int,
 *   bonus_qualifying_income_minor_units:int
 * }
 */
final readonly class PayrollOpeningBalanceService
{
    private const SAVEPOINT = 'payroll_opening_balance';

    /**
     * Zdravotní pojištění tu schválně není. `calculation_kind` ho od migrace 1401
     * zná, ale akumulační cesta pro něj neexistuje (chybí sada polí, větev ve
     * snapshot builderu i `approveHealthInsurance()`), takže zapsaný opening by
     * nikdo nepřečetl. Až cesta vznikne, přibude sem třetí druh.
     */
    private const KINDS = ['social_insurance', 'income_tax'];

    /** Daňová pole kumulace, v tom pořadí, v jakém je čte roční zúčtování. */
    private const TAX_FIELDS = [
        'advance_base_minor_units',
        'withholding_base_minor_units',
        'advance_tax_minor_units',
        'withholding_tax_minor_units',
        'applied_non_refundable_credits_minor_units',
        'applied_child_credit_minor_units',
        'tax_bonus_minor_units',
        'bonus_qualifying_income_minor_units',
    ];

    public function __construct(
        private PayrollStatutoryAccumulatorRepository $accumulators,
        private Connection $db,
    ) {}

    /**
     * Co je za daný rok uložené. Vrací i `id` aktuální verze — oprava se na něj
     * musí explicitně navázat, jinak ji repozitář odmítne jako duplicitu.
     *
     * @return array{year:int,months:list<OpeningMonth>,openings:array<string,?int>,source_reference:string,locked:bool}
     */
    public function current(int $supplierId, int $employeeId, int $year): array
    {
        $openings = [];
        $months = [];
        $sourceReference = '';
        foreach (self::KINDS as $kind) {
            $opening = $this->accumulators->openingBalance($supplierId, $employeeId, $year, $kind);
            $openings[$kind] = $opening === null ? null : (int) $opening['id'];
            // Rozpis měsíců je v evidence u obou druhů stejný; stačí ten první nalezený.
            if ($months === [] && $opening !== null && is_array($opening['evidence']['months'] ?? null)) {
                $months = $opening['evidence']['months'];
                $sourceReference = (string) $opening['source_reference'];
            }
        }

        return [
            'year' => $year,
            'months' => array_values($months),
            'openings' => $openings,
            'source_reference' => $sourceReference,
            'locked' => $this->accumulators->hasApprovedResult($supplierId, $employeeId, $year),
        ];
    }

    /**
     * Uloží počáteční stavy. Opakované uložení TÝCHŽ čísel je replay (repozitář
     * vrátí původní řádek), změna čísel je oprava navázaná na aktuální verzi.
     *
     * @param list<OpeningMonth> $months
     * @return array{year:int,months:list<OpeningMonth>,openings:array<string,?int>,source_reference:string,locked:bool}
     */
    public function save(
        int $supplierId,
        int $employeeId,
        int $year,
        array $months,
        string $sourceReference,
        ?int $actorUserId,
    ): array {
        $sourceReference = trim($sourceReference);
        $months = $this->continuousMonths($months);
        // Prázdný rozpis je záměrný a auditovatelný nulový počátek nového
        // zaměstnance. Nesmíme z ledna až měsíce nástupu vyrábět fiktivně
        // „dokončené" měsíce, protože by zkreslily roční daňovou kumulaci.
        /*
         * Kumulace nese `completed_months` a repozitář ho u openingu omezuje na 11 —
         * dvanáctý měsíc už není „před obdobím", ale celý rok.
         */
        if (count($months) > 11) {
            throw new \InvalidArgumentException(
                'Počáteční stavy pokrývají měsíce PŘED prvním zpracovaným obdobím, tedy nejvýš jedenáct.',
            );
        }
        if ($this->accumulators->hasApprovedResult($supplierId, $employeeId, $year)) {
            throw new \DomainException(
                'Za tenhle rok už je schválená mzda. Počáteční stavy by změnily základ, ze kterého se počítala.',
            );
        }

        $social = ['assessment_base_minor_units' => 0];
        $tax = ['completed_months' => count($months)];
        foreach (self::TAX_FIELDS as $field) {
            $tax[$field] = 0;
        }
        foreach ($months as $month) {
            $social['assessment_base_minor_units'] += $month['social_assessment_base_minor_units'];
            foreach (self::TAX_FIELDS as $field) {
                $tax[$field] += $month[$field];
            }
        }

        $evidence = ['months' => array_values($months)];
        $values = ['social_insurance' => $social, 'income_tax' => $tax];
        $this->transactional(function () use (
            $supplierId,
            $employeeId,
            $year,
            $values,
            $sourceReference,
            $evidence,
            $actorUserId,
        ): void {
            foreach (self::KINDS as $kind) {
                $previous = $this->accumulators->openingBalance(
                    $supplierId,
                    $employeeId,
                    $year,
                    $kind,
                );
                /*
                 * Beze změny se nic nezapisuje.
                 *
                 * Tabulka je append-only, takže druhé uložení týchž čísel by jinak
                 * založilo verzi, která nic neopravuje — a v historii by po pár
                 * kliknutích stál řetěz shodných záznamů. Idempotence repozitáře
                 * to nepokryje: klíč se počítá z dat, ale `record_hash` nese
                 * i předchůdce, který se mezitím změnil z `null` na id první verze.
                 */
                if ($previous !== null
                    && $previous['values'] == $values[$kind]
                    && $previous['evidence'] == $evidence
                    && (string) $previous['source_reference'] === $sourceReference
                ) {
                    continue;
                }
                $this->accumulators->appendOpeningBalance(
                    $supplierId,
                    $employeeId,
                    $year,
                    $kind,
                    $values[$kind],
                    $sourceReference,
                    $evidence,
                    $this->idempotencyKey(
                        $employeeId,
                        $year,
                        $kind,
                        $values[$kind],
                        $evidence,
                        $sourceReference,
                        $previous['id'] ?? null,
                    ),
                    $previous['id'] ?? null,
                    $actorUserId,
                );
            }
        });

        return $this->current($supplierId, $employeeId, $year);
    }

    /**
     * Počet dokončených měsíců smí vzniknout jen ze souvislého intervalu.
     * První měsíc je záměrně explicitní: převzatý zaměstnanec, který nastoupil
     * až v březnu, má před srpnovou aktivací pět měsíců (3–7), ne sedm.
     *
     * @param list<OpeningMonth> $months
     * @return list<OpeningMonth>
     */
    private function continuousMonths(array $months): array
    {
        $byMonth = [];
        foreach ($months as $row) {
            $month = $row['month'] ?? null;
            if (!is_int($month) || $month < 1 || $month > 12) {
                throw new \InvalidArgumentException(
                    'Měsíc počátečního stavu musí být číslo 1 až 12.',
                );
            }
            if (isset($byMonth[$month])) {
                throw new \InvalidArgumentException(
                    "Měsíc {$month} je v počátečních stavech dvakrát.",
                );
            }
            $byMonth[$month] = $row;
        }
        if ($byMonth === []) {
            return [];
        }

        ksort($byMonth, SORT_NUMERIC);
        $numbers = array_keys($byMonth);
        $first = $numbers[0];
        $last = $numbers[count($numbers) - 1];
        if (count($numbers) !== $last - $first + 1) {
            throw new \InvalidArgumentException(
                'Měsíce počátečního stavu musí tvořit souvislou řadu.',
            );
        }

        return array_values($byMonth);
    }

    /** @param callable():void $callback */
    private function transactional(callable $callback): void
    {
        $pdo = $this->db->pdo();
        $nested = $pdo->inTransaction();
        if ($nested) {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        } else {
            $pdo->beginTransaction();
        }
        try {
            $callback();
            if ($nested) {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } else {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($nested) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Klíč se odvozuje ze VŠEHO, co tvoří otisk záznamu — hodnot, rozpisu, zdroje
     * i předchůdce. Zopakovaný požadavek (uživatel klikl dvakrát, spadlo spojení)
     * tak dostane tentýž klíč a repozitář vrátí původní řádek místo nové verze.
     *
     * Předchůdce v klíči být MUSÍ: `record_hash` ho nese, takže bez něj by druhý
     * pokus o tutéž opravu narazil na „klíč už používá jiný opening balance".
     * Časové razítko by naopak z každého kliknutí udělalo novou verzi.
     *
     * @param array<string,int> $values
     * @param array<string,mixed> $evidence
     */
    private function idempotencyKey(
        int $employeeId,
        int $year,
        string $kind,
        array $values,
        array $evidence,
        string $sourceReference,
        ?int $replacesOpeningId,
    ): string {
        ksort($values);

        return sprintf(
            'payroll-opening:%d:%d:%s:%s',
            $employeeId,
            $year,
            $kind,
            hash('sha256', json_encode([
                'values' => $values,
                'evidence' => $evidence,
                'source_reference' => $sourceReference,
                'replaces_opening_id' => $replacesOpeningId,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        );
    }
}

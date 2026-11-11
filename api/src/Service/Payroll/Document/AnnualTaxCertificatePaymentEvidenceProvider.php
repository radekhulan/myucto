<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class AnnualTaxCertificatePaymentEvidenceProvider
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Doloží, že schválená čistá mzda měsíce byla do mezního data skutečně
     * vyplacena nebo vypořádána (§ 5 odst. 4 ZDP).
     *
     * Čistá mzda se vypořádává dvěma cestami a obě se tu sčítají do téže
     * částky `$expectedNetMinorUnits`:
     *
     *  1. výplata — závazek `net_wage` se spárovanou platební událostí
     *     (bankovní nebo pokladní důkaz s datem skutečné úhrady);
     *  2. zápočet na účet společníka
     *     ({@see \MyInvoice\Service\Payroll\Net\PayrollPartnerSettlement}) —
     *     závazek `net_wage` pro něj záměrně nevzniká, protože se nic
     *     neplatí. Částky předává volající ze zmrazené revize přes
     *     {@see \MyInvoice\Service\Payroll\Posting\PayrollPartnerSettlementResolver},
     *     tedy z téhož SSOT, ze kterého účtuje mzdový můstek.
     *
     * Důkazem zápočtu je ZAÚČTOVANÝ mzdový předpis revize: dávka
     * `payroll_posting_batches` ve stavu posted/no_change, jejíž cílové alokace
     * nesou přesně tutéž částku zápočtu na tentýž účet, a deníkový zápis, který
     * ji do účetnictví propsal. Datem vypořádání je `journal_entries.entry_date`
     * toho zápisu. Proč právě tohle:
     *
     *  - Zápočet je zánik závazku ze mzdy započtením (§ 1982 OZ) a v účetnictví
     *    se projeví jedině přeúčtováním 331/366 → 365. Jiná stopa než účetní
     *    zápis neexistuje — žádný výpis, žádný pokladní doklad.
     *  - Datum účetního případu (§ 11 ZoÚ) je okamžik, ke kterému účetní
     *    jednotka vypořádání vykázala; mzdový můstek ho bere z konce mzdového
     *    období, případně z nejbližšího otevřeného data.
     *  - Mzdový deníkový zápis je neměnný: přepis i storno účtovací služba
     *    odmítá (`payroll_rewrite_forbidden`, `payroll_reversal_forbidden`),
     *    takže důkaz se po vydání potvrzení nemůže tiše změnit.
     *  - Dávka `no_change` deníkový zápis nemá (cílový stav se nezměnil);
     *    datem je pak nejbližší dřívější zaúčtovaná dávka v řetězu. To je
     *    datum POZDĚJŠÍ nebo stejné jako skutečný zápočet, tedy směrem
     *    k meznímu datu bezpečné.
     *
     * Nezaúčtovaný běh (žádná dávka, rozpracovaná dávka, zápis bez data
     * zaúčtování nebo stornovaný zápis) zápočet NEDOKLÁDÁ a potvrzení se
     * nevydá. Zápočet zaúčtovaný až po mezním datu do období nepatří stejně
     * jako pozdní úhrada.
     *
     * Bez zápočtu (`$partnerSettlements === []`) je chování i výstup doslova
     * stejné jako dřív, včetně verze schématu.
     *
     * @param list<array{
     *   allocation_reference:string,
     *   account_code:string,
     *   amount_minor:int
     * }> $partnerSettlements
     * @return array{
     *   schema_version:string,
     *   run_id:int,
     *   revision_id:int,
     *   expected_net_minor_units:int,
     *   cutoff:string,
     *   last_payment_date:string,
     *   liabilities:list<array{
     *     liability_id:int,
     *     revision_id:int,
     *     revision_no:int,
     *     direction:string,
     *     amount_minor_units:int,
     *     settled_minor_units:int,
     *     events:list<array{
     *       match_id:int,
     *       event_kind:string,
     *       amount_minor_units:int,
     *       actual_payment_date:string,
     *       evidence_fact_hash:string
     *     }>
     *   }>,
     *   partner_settlement?:array{
     *     amount_minor_units:int,
     *     settlement_date:string,
     *     posting_batch_id:int,
     *     journal_entry_id:int,
     *     settlements:list<array{
     *       allocation_reference_hash:string,
     *       account_code:string,
     *       amount_minor_units:int
     *     }>
     *   }
     * }
     */
    public function prove(
        int $supplierId,
        int $employeeId,
        int $runId,
        int $revisionId,
        int $expectedNetMinorUnits,
        string $cutoff,
        array $partnerSettlements = [],
    ): array {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException(
                'Důkaz skutečné výplaty vyžaduje aktivní transakci.',
            );
        }
        if ($supplierId <= 0
            || $employeeId <= 0
            || $runId <= 0
            || $revisionId <= 0
            || $expectedNetMinorUnits <= 0
        ) {
            throw new \InvalidArgumentException(
                'Identita platebního důkazu daňového potvrzení není platná.',
            );
        }
        $cutoffDate = self::date($cutoff, 'mezní datum skutečné výplaty');
        $settlementTotal = self::settlementTotal($partnerSettlements);
        $statement = $pdo->prepare(
            'SELECT liability.id AS liability_id,
                    liability.revision_id,
                    revision.revision_no,
                    liability.direction,
                    liability.currency_code,
                    liability.amount_minor AS liability_amount_minor,
                    allocation.id AS allocation_id,
                    payment_match.id AS match_id,
                    payment_match.event_kind,
                    payment_match.amount_minor AS match_amount_minor,
                    payment_match.actual_payment_date,
                    payment_match.evidence_fact_hash
               FROM payroll_run_revisions selected_revision
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = selected_revision.supplier_id
                AND revision.run_id = selected_revision.run_id
                AND revision.revision_no <= selected_revision.revision_no
               JOIN payroll_payment_liabilities liability
                 ON liability.supplier_id = revision.supplier_id
                AND liability.revision_id = revision.id
                AND liability.employee_id = ?
                AND liability.liability_kind = "net_wage"
          LEFT JOIN payroll_payment_allocations allocation
                 ON allocation.supplier_id = liability.supplier_id
                AND allocation.liability_id = liability.id
          LEFT JOIN payroll_payment_matches payment_match
                 ON payment_match.supplier_id = allocation.supplier_id
                AND payment_match.allocation_id = allocation.id
              WHERE selected_revision.supplier_id = ?
                AND selected_revision.id = ?
                AND selected_revision.run_id = ?
                AND selected_revision.status IN ("approved", "superseded")
              ORDER BY liability.id, allocation.id, payment_match.id
              FOR UPDATE',
        );
        $statement->execute([
            $employeeId,
            $supplierId,
            $revisionId,
            $runId,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === [] && $settlementTotal === 0) {
            throw new \DomainException(
                'Skutečnou výplatu nelze doložit: pro schválenou revizi '
                . 'neexistuje neměnný závazek čisté mzdy.',
            );
        }

        $liabilities = [];
        foreach ($rows as $fetched) {
            $row = self::associativeRow($fetched);
            $liabilityId = self::positiveInt($row, 'liability_id');
            if (!isset($liabilities[$liabilityId])) {
                $direction = self::text($row, 'direction');
                if (!in_array($direction, ['outgoing', 'incoming'], true)) {
                    throw new \DomainException(
                        'Závazek čisté mzdy má neplatný směr.',
                    );
                }
                if (self::text($row, 'currency_code') !== 'CZK') {
                    throw new \DomainException(
                        'Daňové potvrzení podporuje pouze doložené výplaty v CZK.',
                    );
                }
                $liabilities[$liabilityId] = [
                    'liability_id' => $liabilityId,
                    'revision_id' => self::positiveInt($row, 'revision_id'),
                    'revision_no' => self::positiveInt($row, 'revision_no'),
                    'direction' => $direction,
                    'amount_minor_units' =>
                        self::positiveInt($row, 'liability_amount_minor'),
                    'settled_minor_units' => 0,
                    'events' => [],
                ];
            }
            $matchId = self::nullablePositiveInt($row, 'match_id');
            if ($matchId === null) {
                continue;
            }
            $actualPaymentDate = self::nullableText(
                $row,
                'actual_payment_date',
            );
            if ($actualPaymentDate === null) {
                throw new \DomainException(
                    'Skutečnou výplatu nelze doložit bez data účetního důkazu.',
                );
            }
            $paymentDate = self::date(
                $actualPaymentDate,
                'datum účetního důkazu',
            );
            $evidenceHash = self::nullableText(
                $row,
                'evidence_fact_hash',
            );
            if ($evidenceHash === null
                || preg_match('/^[a-f0-9]{64}$/D', $evidenceHash) !== 1
            ) {
                throw new \DomainException(
                    'Skutečnou výplatu nelze doložit bez otisku účetního důkazu.',
                );
            }
            $eventKind = self::text($row, 'event_kind');
            $eventAmount = self::int($row, 'match_amount_minor');
            if (($eventKind === 'matched' && $eventAmount <= 0)
                || ($eventKind === 'reversed' && $eventAmount >= 0)
                || !in_array($eventKind, ['matched', 'reversed'], true)
            ) {
                throw new \DomainException(
                    'Událost platebního důkazu má neplatný směr.',
                );
            }
            // Mezní datum se uplatňuje POUZE na příchozí úhradu. Příjem vyplacený
            // až po 31. lednu do zdaňovacího období nepatří (§ 5 odst. 4 zákona),
            // takže se `matched` událost za hranicí nezapočítá a závazek zůstane
            // nedoložený — potvrzení se nevydá.
            //
            // Reverzace je ale důkaz OPAČNÉHO směru: banka peníze vrátila, takže
            // ta dřívější `matched` událost mzdu nevyrovnala. Kdyby se ignorovala
            // stejným pravidlem, stačilo by, aby se vrácení platby zaúčtovalo
            // 1. února, a nevyplacená mzda by se na potvrzení objevila jako řádně
            // vyplacená. Ověřeno simulací: úhrada 15. 2. 2026 vrácená 5. 2. 2027
            // vydala potvrzení na 40 000 Kč, přestože zaměstnanec nedostal nic.
            // Reverzace se proto započítává vždy, bez ohledu na datum; směrem ven
            // je to fail-closed (uzavře cestu, neotevírá ji).
            if ($eventKind === 'matched' && $paymentDate > $cutoffDate) {
                continue;
            }
            if (isset($liabilities[$liabilityId]['events'][$matchId])) {
                continue;
            }
            $liabilities[$liabilityId]['settled_minor_units'] = self::add(
                $liabilities[$liabilityId]['settled_minor_units'],
                $eventAmount,
            );
            $liabilities[$liabilityId]['events'][$matchId] = [
                'match_id' => $matchId,
                'event_kind' => $eventKind,
                'amount_minor_units' => $eventAmount,
                'actual_payment_date' => $actualPaymentDate,
                'evidence_fact_hash' => $evidenceHash,
            ];
        }

        $netLiability = 0;
        $lastPaymentDate = null;
        $proof = [];
        foreach ($liabilities as $liability) {
            if ($liability['settled_minor_units']
                !== $liability['amount_minor_units']
            ) {
                throw new \DomainException(sprintf(
                    'Čistá mzda nebyla podle neměnné evidence plně vyplacena '
                    . 'do %s. V Mzdy > Platby > Spárování úhrad vyberte '
                    . 'platbu zaměstnance a spárujte skutečnou úhradu '
                    . 's bankovním pohybem nebo zaúčtovaným pokladním dokladem. '
                    . 'Poté vytvořte potvrzení znovu. Samotný export plateb úhradu nedokládá.',
                    self::displayDate($cutoff),
                ));
            }
            $netLiability = self::add(
                $netLiability,
                $liability['direction'] === 'outgoing'
                    ? $liability['amount_minor_units']
                    : -$liability['amount_minor_units'],
            );
            $events = array_values($liability['events']);
            foreach ($events as $event) {
                if ($event['event_kind'] === 'matched'
                    && ($lastPaymentDate === null
                        || $event['actual_payment_date'] > $lastPaymentDate)
                ) {
                    $lastPaymentDate = $event['actual_payment_date'];
                }
            }
            $liability['events'] = $events;
            $proof[] = $liability;
        }
        $settlementProof = null;
        if ($settlementTotal > 0) {
            $settlementProof = $this->provePartnerSettlement(
                $pdo,
                $supplierId,
                $employeeId,
                $runId,
                $revisionId,
                $partnerSettlements,
                $settlementTotal,
            );
            if (self::date(
                $settlementProof['settlement_date'],
                'datum zápočtu',
            ) > $cutoffDate) {
                throw new \DomainException(sprintf(
                    'Zápočet čisté mzdy na účet společníka je zaúčtován až '
                    . '%s, tedy po %s; do zdaňovacího období nepatří.',
                    self::displayDate($settlementProof['settlement_date']),
                    self::displayDate($cutoff),
                ));
            }
            $netLiability = self::add($netLiability, $settlementTotal);
            if ($lastPaymentDate === null
                || $settlementProof['settlement_date'] > $lastPaymentDate
            ) {
                $lastPaymentDate = $settlementProof['settlement_date'];
            }
        }
        if ($netLiability !== $expectedNetMinorUnits) {
            throw new \DomainException(
                'Vektor platebních závazků neodpovídá schválené čisté mzdě.',
            );
        }
        if ($lastPaymentDate === null) {
            throw new \DomainException(
                'Skutečnou výplatu nelze doložit bez kladné platební události.',
            );
        }

        if ($settlementProof === null) {
            return [
                'schema_version' =>
                    'annual-tax-certificate-payment-evidence.v1',
                'run_id' => $runId,
                'revision_id' => $revisionId,
                'expected_net_minor_units' => $expectedNetMinorUnits,
                'cutoff' => $cutoff,
                'last_payment_date' => $lastPaymentDate,
                'liabilities' => $proof,
            ];
        }

        return [
            'schema_version' =>
                'annual-tax-certificate-payment-evidence.v2',
            'run_id' => $runId,
            'revision_id' => $revisionId,
            'expected_net_minor_units' => $expectedNetMinorUnits,
            'cutoff' => $cutoff,
            'last_payment_date' => $lastPaymentDate,
            'liabilities' => $proof,
            'partner_settlement' => $settlementProof,
        ];
    }

    /**
     * @param list<array{
     *   allocation_reference:string,
     *   account_code:string,
     *   amount_minor:int
     * }> $partnerSettlements
     */
    private static function settlementTotal(array $partnerSettlements): int
    {
        $total = 0;
        $references = [];
        foreach ($partnerSettlements as $settlement) {
            if (!is_array($settlement)
                || !is_string($settlement['allocation_reference'] ?? null)
                || $settlement['allocation_reference'] === ''
                || !is_string($settlement['account_code'] ?? null)
                || $settlement['account_code'] === ''
                || !is_int($settlement['amount_minor'] ?? null)
                || $settlement['amount_minor'] <= 0
            ) {
                throw new \InvalidArgumentException(
                    'Zápočet čisté mzdy na účet společníka nemá platnou částku.',
                );
            }
            if (isset($references[$settlement['allocation_reference']])) {
                throw new \InvalidArgumentException(
                    'Zápočet čisté mzdy na účet společníka je uveden vícekrát.',
                );
            }
            $references[$settlement['allocation_reference']] = true;
            $total = self::add($total, $settlement['amount_minor']);
        }

        return $total;
    }

    /**
     * Zaúčtovaný mzdový předpis jako důkaz zápočtu — důvody výběru jsou
     * v docbloku {@see prove()}.
     *
     * @param list<array{
     *   allocation_reference:string,
     *   account_code:string,
     *   amount_minor:int
     * }> $partnerSettlements
     * @return array{
     *   amount_minor_units:int,
     *   settlement_date:string,
     *   posting_batch_id:int,
     *   journal_entry_id:int,
     *   settlements:list<array{
     *     allocation_reference_hash:string,
     *     account_code:string,
     *     amount_minor_units:int
     *   }>
     * }
     */
    private function provePartnerSettlement(
        PDO $pdo,
        int $supplierId,
        int $employeeId,
        int $runId,
        int $revisionId,
        array $partnerSettlements,
        int $settlementTotal,
    ): array {
        $batchStatement = $pdo->prepare(
            'SELECT batch.id, batch.status, batch.previous_batch_id,
                    batch.journal_entry_id, entry.entry_date,
                    entry.posted_at AS entry_posted_at, entry.reversed_by
               FROM payroll_posting_batches batch
          LEFT JOIN journal_entries entry
                 ON entry.supplier_id = batch.supplier_id
                AND entry.id = batch.journal_entry_id
              WHERE batch.supplier_id = ?
                AND batch.run_id = ?
                AND batch.revision_id = ?
              FOR UPDATE',
        );
        $batchStatement->execute([$supplierId, $runId, $revisionId]);
        $batch = $batchStatement->fetch(PDO::FETCH_ASSOC);
        $unposted = 'Zápočet čisté mzdy na účet společníka nelze doložit: '
            . 'mzdový předpis schválené revize není zaúčtovaný.';
        if (!is_array($batch)) {
            throw new \DomainException($unposted);
        }
        $batch = self::associativeRow($batch);
        if (!in_array($batch['status'] ?? null, ['posted', 'no_change'], true)) {
            throw new \DomainException($unposted);
        }
        $batchId = self::positiveInt($batch, 'id');

        $allocationStatement = $pdo->prepare(
            'SELECT allocation_key, account_code, signed_minor
               FROM payroll_posting_allocations
              WHERE supplier_id = ?
                AND batch_id = ?
                AND allocation_key LIKE ?
              ORDER BY allocation_key',
        );
        $prefix = "employee:{$employeeId}:partner-settlement:";
        $allocationStatement->execute([$supplierId, $batchId, $prefix . '%']);
        $posted = [];
        foreach ($allocationStatement->fetchAll(PDO::FETCH_ASSOC) as $fetched) {
            $row = self::associativeRow($fetched);
            $key = self::text($row, 'allocation_key');
            if (!str_starts_with($key, $prefix)
                || !str_ends_with($key, ':liability')
            ) {
                continue;
            }
            $posted[$key] = [
                'account_code' => self::text($row, 'account_code'),
                'signed_minor' => self::int($row, 'signed_minor'),
            ];
        }
        $settlements = [];
        foreach ($partnerSettlements as $settlement) {
            $hash = hash('sha256', $settlement['allocation_reference']);
            $key = "{$prefix}{$hash}:liability";
            $allocation = $posted[$key] ?? null;
            if ($allocation === null
                || $allocation['account_code'] !== $settlement['account_code']
                || $allocation['signed_minor'] !== -$settlement['amount_minor']
            ) {
                throw new \DomainException(
                    'Zápočet čisté mzdy na účet společníka nelze doložit: '
                    . 'zaúčtovaný mzdový předpis nese jinou částku nebo účet '
                    . 'zápočtu než schválená revize.',
                );
            }
            unset($posted[$key]);
            $settlements[] = [
                'allocation_reference_hash' => $hash,
                'account_code' => $settlement['account_code'],
                'amount_minor_units' => $settlement['amount_minor'],
            ];
        }
        if ($posted !== []) {
            throw new \DomainException(
                'Zápočet čisté mzdy na účet společníka nelze doložit: '
                . 'zaúčtovaný mzdový předpis nese zápočet, který schválená '
                . 'revize nezná.',
            );
        }

        // Dávka bez změny cílového stavu deníkový zápis nemá; zápočet propsal
        // do účetnictví nejbližší dřívější zaúčtovaný předpis téhož běhu.
        $visited = [];
        while (($batch['status'] ?? null) === 'no_change') {
            $previousId = self::nullablePositiveInt($batch, 'previous_batch_id');
            $currentId = self::positiveInt($batch, 'id');
            if ($previousId === null || isset($visited[$currentId])) {
                throw new \DomainException($unposted);
            }
            $visited[$currentId] = true;
            $previousStatement = $pdo->prepare(
                'SELECT batch.id, batch.status, batch.previous_batch_id,
                        batch.journal_entry_id, entry.entry_date,
                        entry.posted_at AS entry_posted_at, entry.reversed_by
                   FROM payroll_posting_batches batch
              LEFT JOIN journal_entries entry
                     ON entry.supplier_id = batch.supplier_id
                    AND entry.id = batch.journal_entry_id
                  WHERE batch.supplier_id = ?
                    AND batch.run_id = ?
                    AND batch.id = ?
                  FOR UPDATE',
            );
            $previousStatement->execute([$supplierId, $runId, $previousId]);
            $previous = $previousStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($previous)) {
                throw new \DomainException($unposted);
            }
            $batch = self::associativeRow($previous);
            if (!in_array($batch['status'] ?? null, ['posted', 'no_change'], true)) {
                throw new \DomainException($unposted);
            }
        }
        $journalEntryId = self::nullablePositiveInt($batch, 'journal_entry_id');
        $entryDate = self::nullableText($batch, 'entry_date');
        if ($journalEntryId === null
            || $entryDate === null
            || self::nullableText($batch, 'entry_posted_at') === null
            || ($batch['reversed_by'] ?? null) !== null
        ) {
            throw new \DomainException($unposted);
        }
        self::date($entryDate, 'datum zápočtu');

        return [
            'amount_minor_units' => $settlementTotal,
            'settlement_date' => $entryDate,
            'posting_batch_id' => $batchId,
            'journal_entry_id' => $journalEntryId,
            'settlements' => $settlements,
        ];
    }

    /** @param array<string,mixed> $row */
    private static function positiveInt(array $row, string $field): int
    {
        $value = self::int($row, $field);
        if ($value <= 0) {
            throw new \UnexpectedValueException(
                "Pole {$field} platebního důkazu není kladné celé číslo.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullablePositiveInt(
        array $row,
        string $field,
    ): ?int {
        return ($row[$field] ?? null) === null
            ? null
            : self::positiveInt($row, $field);
    }

    /** @param array<string,mixed> $row */
    private static function int(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if ((!is_int($value) && !is_string($value))
            || filter_var($value, FILTER_VALIDATE_INT) === false
        ) {
            throw new \UnexpectedValueException(
                "Pole {$field} platebního důkazu není celé číslo.",
            );
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $row */
    private static function text(array $row, string $field): string
    {
        return self::nullableText($row, $field)
            ?? throw new \UnexpectedValueException(
                "Pole {$field} platebního důkazu není text.",
            );
    }

    /** @param array<string,mixed> $row */
    private static function nullableText(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new \UnexpectedValueException(
                "Pole {$field} platebního důkazu není text.",
            );
        }

        return $value;
    }

    private static function date(
        string $value,
        string $label,
    ): \DateTimeImmutable {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false
                && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new \InvalidArgumentException(
                "Pole {$label} platebního důkazu není platné datum.",
            );
        }

        return $date;
    }

    private static function displayDate(string $value): string
    {
        $date = self::date($value, 'datum');

        return implode('. ', [
            (string) (int) $date->format('d'),
            (string) (int) $date->format('m'),
            $date->format('Y'),
        ]);
    }

    private static function add(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new \OverflowException(
                'Součet platebního důkazu přetekl.',
            );
        }

        return $left + $right;
    }

    /** @return array<string,mixed> */
    private static function associativeRow(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException(
                'Platební evidence vrátila neplatný řádek.',
            );
        }
        $row = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Platební evidence vrátila neplatný název sloupce.',
                );
            }
            $row[$key] = $item;
        }

        return $row;
    }
}

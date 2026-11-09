<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\DocumentRepostService;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kritérium c a f: přeúčtování dokladu ve formě single s řádky, jak je vrátí náhled
 * (plan), dá tytéž dvojice a zachová dimenze dílů. Přeúčtování jednoho výnosového
 * řádku změní jen účet své dvojice, ostatní dvojice i jejich dimenze zůstanou.
 */
#[Group('integration')]
#[Group('pairs-e2e')]
final class RepostDeterminismTest extends PairsTestCase
{
    private int $typeId = 0;

    public function testRepostWithPlanLinesKeepsPairsAndDimensions(): void
    {
        [$invoiceId, $valueA, $valueB] = $this->invoiceWithDimensions();
        $entryId = $this->posting->postDocument(
            $this->supplierId, 'invoice', $invoiceId,
            $this->posting->buildFromInvoice($this->supplierId, $invoiceId),
            ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId],
        );
        $before = $this->pairsWithDimensions($entryId);
        $revenue = array_values(array_filter($before, static fn (array $p): bool => $p[1] === '602'));
        self::assertSame(
            [['10000.00', [$this->typeId => $valueA]], ['1000.00', [$this->typeId => $valueB]]],
            array_map(static fn (array $p): array => [$p[2], $p[4]], $revenue),
            'Výnos položek s různou zakázkou = dvě dvojice 311 / 602: ' . json_encode($before),
        );

        $service = $this->container->get(DocumentRepostService::class);
        $plan = $service->plan($this->supplierId, 'invoice', $invoiceId);
        self::assertSame(DocumentRepostService::STRATEGY_REPLACE, $plan['strategy']);
        $lines = array_map(static fn (array $l): array => [
            'account_code' => (string) $l['account_code'], 'side' => $l['side'], 'amount' => $l['amount'],
        ], $plan['lines']);
        $result = $service->repost($this->supplierId, 'invoice', $invoiceId, $lines, ['user_id' => $this->userId]);
        self::assertSame($entryId, $result['entry_id']);
        self::assertSame($before, $this->pairsWithDimensions($entryId), 'Opakované zaúčtování dá tytéž dvojice a dimenze.');

        // Přeúčtování výnosu 1 000 na 604: jen jeho dvojice změní účet, dimenze zůstane.
        $changed = $lines;
        foreach ($changed as $i => $line) {
            if ($line['account_code'] === '602' && (float) $line['amount'] === 1000.0) {
                $changed[$i]['account_code'] = '604';
            }
        }
        $service->repost($this->supplierId, 'invoice', $invoiceId, $changed, ['user_id' => $this->userId]);
        $after = $this->pairsWithDimensions($entryId);
        $expected = array_map(static fn (array $p): array => $p[1] === '602' && $p[2] === '1000.00' ? ['311', '604', '1000.00', $p[3], $p[4]] : $p, $before);
        self::assertSame($expected, $after);
    }

    /** @return array{int,int,int} faktura, hodnota hlavičky, hodnota druhé položky */
    private function invoiceWithDimensions(): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET dimensions_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind) VALUES (?, 'ZAK', 'Zakázka', 'project')")
            ->execute([$this->supplierId]);
        $this->typeId = (int) $pdo->lastInsertId();
        $value = function (string $code) use ($pdo): int {
            $pdo->prepare('INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, ?, ?)')
                ->execute([$this->typeId, $this->supplierId, $code, 'Zakázka ' . $code]);
            return (int) $pdo->lastInsertId();
        };
        $valueA = $value('A');
        $valueB = $value('B');

        $date = self::YEAR . '-06-15';
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date, currency_id,
                 reverse_charge, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, 11000, 2220, 13220, "issued", "1", ?)'
        )->execute([$this->supplierId, 'RD' . random_int(100000, 999999), $this->client('Odběratel přeúčtování'), $date, $date, $date,
            $this->currencyId, $this->userId]);
        $invoiceId = (int) $pdo->lastInsertId();
        foreach ([[10000.00, 2100.00], [1000.00, 120.00]] as $i => [$net, $vat]) {
            $rate = $i === 0 ? 21 : 12;
            $pdo->prepare(
                'INSERT INTO invoice_items
                    (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                     total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
                 VALUES (?, "Položka", 1, "ks", ?, ?, ?, ?, ?, ?, ?, "1")'
            )->execute([$invoiceId, $net, $this->vatRateId($rate), $rate, $net, $vat, $net + $vat, $i]);
        }
        $dimension = $pdo->prepare(
            'INSERT INTO document_dimensions (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id)
             VALUES (?, "invoice", ?, ?, ?, ?)'
        );
        $dimension->execute([$this->supplierId, $invoiceId, 0, $this->typeId, $valueA]);
        $dimension->execute([$this->supplierId, $invoiceId, 2, $this->typeId, $valueB]);
        return [$invoiceId, $valueA, $valueB];
    }

    /**
     * Dvojice zápisu [MD, Dal, částka, dimenze řádku MD, dimenze řádku Dal] v pořadí dvojic.
     *
     * @return list<array{string,string,string,array<int,int>,array<int,int>}>
     */
    private function pairsWithDimensions(int $entryId): array
    {
        $this->assertEntryPaired($this->db->pdo(), $this->supplierId, $entryId, '13220.00');
        $dims = $this->container->get(DimensionService::class)->entryLineDimensions($this->supplierId, $entryId);
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.pair_no, l.side, l.amount, a.account_code
               FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND l.entry_id = ? ORDER BY l.pair_no, l.side DESC'
        );
        $stmt->execute([$this->supplierId, $entryId]);
        $pairs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $no = (int) $row['pair_no'];
            $offset = $row['side'] === 'debit' ? 0 : 1;
            $pairs[$no][$offset] = (string) $row['account_code'];
            $pairs[$no][2] = number_format((float) $row['amount'], 2, '.', '');
            $lineDims = array_map('intval', $dims[(int) $row['id']] ?? []);
            ksort($lineDims);
            $pairs[$no][3 + $offset] = $lineDims;
        }
        ksort($pairs);
        return array_values(array_map(static function (array $p): array {
            ksort($p);
            return array_values($p);
        }, $pairs));
    }
}

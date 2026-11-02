<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;

/**
 * Čerpání přijatých záloh (proformy na 324), které Money vede bez vazby na všechny zálohy
 * ({@see SyntheticAgenda::filesWithIssuedAdvanceDeductions()}): konečná faktura odečítá dvě
 * proformy pod symbolem první z nich a partner bez IČO odkazuje na zálohu jen párovacím
 * symbolem. Řádný i opravný převod je musí spárovat tak, aby saldokonto 324 odpovídalo
 * hlavní knize, opakovaný běh nic nezmění a vazby zadané v MyÚčtu zůstanou.
 */
final class MoneyS3IssuedAdvanceDeductionsTest extends MoneyS3ImportTestCase
{
    private int $supplierId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithIssuedAdvanceDeductions());
        $this->supplierId = $this->supplier();
        $protocol = $this->convert(false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
    }

    public function testFinalInvoiceDeductingTwoAdvancesUnderOneSymbolClosesBoth(): void
    {
        // FV25201 má vazbu na ZV25201 (položka odpočtu); zbytek strany čerpání nad ní čerpá ZV25202.
        self::assertSame($this->doc('ZV25201'), $this->parentOf('FV25201'));
        self::assertSame([['FV25201', 'ZV25202', '2000.00']], $this->deductionsOf('FV25201'));
        self::assertSame([], $this->openOn324(SyntheticAgenda::ADVANCE_CUSTOMER_ICO));
    }

    public function testPairingSymbolPicksAdvanceOfPartnerWithoutIco(): void
    {
        // FV25211 odkazuje symbolem 25212 na ZV25212, ne na starší ZV25211.
        self::assertSame([['FV25211', 'ZV25212', '800.00']], $this->deductionsOf('FV25211'));
        self::assertSame(['ZV25211 500'], $this->openOn324(''));
        $saldo = $this->saldo324();
        self::assertSame(0.0, $saldo['difference'], json_encode($saldo));
    }

    public function testAdvancePaymentGoesToProformaNotToFullyCoveredFinalInvoice(): void
    {
        // BP25221 nese symbol proformy ZV25221; konečná FV25221 (k úhradě nula) ji jen čerpá.
        self::assertSame([['ZV25221', '1210.00']], $this->matchesOf('BP25221'));
        self::assertSame([['FV25221', 'ZV25221', '1210.00']], $this->deductionsOf('FV25221'));
        self::assertSame([], array_values(array_filter($this->openOn324(''), static fn (string $i): bool => str_contains($i, '25221'))));
    }

    public function testRelinkMovesAdvancePaymentFromCoveredFinalInvoiceToProforma(): void
    {
        $sid = $this->supplierId;
        $pdo = $this->db->pdo();
        // Firma převedená dřívější verzí: úhradu zálohy dostala (párováním převodu) konečná faktura.
        $final = $this->doc('FV25221');
        $advance = $this->doc('ZV25221');
        $tx = (int) $pdo->query("SELECT t.id FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                                  WHERE s.supplier_id = {$sid} AND t.source_ref = 'BP25221'")->fetchColumn();
        $pdo->prepare('UPDATE payment_matches SET invoice_id = ? WHERE supplier_id = ? AND bank_transaction_id = ?')->execute([$final, $sid, $tx]);
        $pdo->prepare('UPDATE invoice_payments SET invoice_id = ? WHERE supplier_id = ? AND bank_transaction_id = ?')->execute([$final, $sid, $tx]);
        $pdo->prepare("DELETE FROM money_s3_import_map WHERE supplier_id = ? AND kind = 'payment' AND money_key = ?")->execute([$sid, "ps|{$tx}|invoice|{$final}"]);
        $pdo->prepare("UPDATE money_s3_import_map SET money_key = ? WHERE supplier_id = ? AND kind = 'payment' AND money_key = ?")
            ->execute(["ps|{$tx}|invoice|{$final}", $sid, "ps|{$tx}|invoice|{$advance}"]);
        $pdo->prepare('DELETE FROM invoice_advance_deductions WHERE supplier_id = ? AND final_invoice_id = ?')->execute([$sid, $final]);
        $pdo->prepare("DELETE FROM money_s3_import_map WHERE supplier_id = ? AND kind = 'advance_deduction'")->execute([$sid]);
        self::assertSame([['FV25221', '1210.00']], $this->matchesOf('BP25221'));

        $repair = $this->convert(true);
        self::assertFalse($repair->hasErrors(), $this->explain($repair));
        self::assertSame([['ZV25221', '1210.00']], $this->matchesOf('BP25221'));
        self::assertSame([['FV25221', 'ZV25221', '1210.00']], $this->deductionsOf('FV25221'));
    }

    public function testRelinkChangesNothingAndKeepsUserDecisions(): void
    {
        $before = $this->state();
        $again = $this->convert(true);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($before, $this->state(), 'Opakovaný převod nesmí nic změnit.');

        $sid = $this->supplierId;
        $pdo = $this->db->pdo();
        // Uživatel smazal čerpání FV25211 a FV25201 vyrovnal ze ZV25202 jen 500 Kč po svém.
        $pdo->prepare('DELETE FROM invoice_advance_deductions WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare("INSERT INTO invoice_advance_deductions (supplier_id, final_invoice_id, advance_invoice_id, amount, source) VALUES (?, ?, ?, 500.00, 'saldo')")
            ->execute([$sid, $this->doc('FV25201'), $this->doc('ZV25202')]);
        $repair = $this->convert(true);
        self::assertFalse($repair->hasErrors(), $this->explain($repair));
        self::assertSame([['FV25201', 'ZV25202', '500.00']], $this->deductions(), 'Smazané čerpání převod znovu nezaloží a ruční nepřepíše.');
    }

    public function testRelinkAddsMissingDeductionsToCompanyConvertedEarlier(): void
    {
        $sid = $this->supplierId;
        $pdo = $this->db->pdo();
        // Firma převedená verzí, která čerpání proforem nepárovala: čerpání ani mapa nejsou.
        $pdo->prepare('DELETE FROM invoice_advance_deductions WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare("DELETE FROM money_s3_import_map WHERE supplier_id = ? AND kind = 'advance_deduction'")->execute([$sid]);
        $repair = $this->convert(true);
        self::assertFalse($repair->hasErrors(), $this->explain($repair));
        self::assertSame([['FV25201', 'ZV25202', '2000.00'], ['FV25211', 'ZV25212', '800.00'], ['FV25221', 'ZV25221', '1210.00']], $this->deductions());
    }

    private function convert(bool $relink): ImportProtocol
    {
        return $this->importer->run($this->supplierId, $this->userId, $this->backup(),
            new ImportOptions(ImportOptions::MODE_IMPORT, true, null, [], [], false, 2025, ImportOptions::DISPOSAL_YEAR_TAX_HALF, false, $relink));
    }

    /** @return list<array{0:string,1:string,2:string}> konečná faktura, proforma, částka */
    private function deductions(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT f.varsymbol, a.varsymbol, iad.amount FROM invoice_advance_deductions iad
               JOIN invoices f ON f.id = iad.final_invoice_id
               JOIN invoices a ON a.id = iad.advance_invoice_id
              WHERE iad.supplier_id = ? ORDER BY f.varsymbol, a.varsymbol'
        );
        $stmt->execute([$this->supplierId]);
        return $stmt->fetchAll(PDO::FETCH_NUM);
    }

    /** @return list<array{0:string,1:string}> doklad, částka párování pohybu */
    private function matchesOf(string $bankDoc): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.varsymbol, pm.amount FROM payment_matches pm
               JOIN bank_transactions t ON t.id = pm.bank_transaction_id
               JOIN invoices i ON i.id = pm.invoice_id
              WHERE pm.supplier_id = ? AND t.source_ref = ? ORDER BY i.varsymbol'
        );
        $stmt->execute([$this->supplierId, $bankDoc]);
        return $stmt->fetchAll(PDO::FETCH_NUM);
    }

    /** @return list<array{0:string,1:string,2:string}> */
    private function deductionsOf(string $final): array
    {
        return array_values(array_filter($this->deductions(), static fn (array $r): bool => $r[0] === $final));
    }

    /** @return array<string,mixed> */
    private function saldo324(): array
    {
        $saldo = $this->container->get(SaldoService::class);
        $account = $saldo->build($this->supplierId, $this->periodId($this->supplierId, 2025), '2025-12-31', '324')['accounts'][0];
        return ['difference' => round((float) $account['difference'], 2), 'gl' => $account['gl_balance'], 'partners' => $account['partners']];
    }

    /** @return list<string> otevřené položky partnera s IČO `$ico` (prázdné = bez IČO) */
    private function openOn324(string $ico): array
    {
        $open = [];
        foreach ($this->saldo324()['partners'] as $partner) {
            foreach ($partner['items'] as $item) {
                $clientIco = (string) $this->db->pdo()->query('SELECT COALESCE(c.ic, \'\') FROM invoices i JOIN clients c ON c.id = i.client_id WHERE i.id = ' . (int) $item['doc_id'])->fetchColumn();
                if ($clientIco === $ico) {
                    $open[] = $item['doc_no'] . ' ' . $item['remaining_czk'];
                }
            }
        }
        return $open;
    }

    private function parentOf(string $no): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT parent_invoice_id FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$this->supplierId, $no]);
        $parent = $stmt->fetchColumn();
        return $parent === false || $parent === null ? null : (int) $parent;
    }

    private function doc(string $no): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$this->supplierId, $no]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id, "{$no} nenalezen");
        return $id;
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $out = [];
        foreach ([
            'iad' => 'SELECT id, final_invoice_id, advance_invoice_id, amount FROM invoice_advance_deductions WHERE supplier_id = ? ORDER BY id',
            'links' => 'SELECT id, parent_invoice_id FROM invoices WHERE supplier_id = ? ORDER BY id',
            'payment_matches' => 'SELECT id, bank_transaction_id, invoice_id, amount FROM payment_matches WHERE supplier_id = ? ORDER BY id',
        ] as $key => $sql) {
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute([$this->supplierId]);
            $out[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $out;
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Service\Invoice\PaidWithoutPaymentRepair;
use MyInvoice\Service\Invoice\TaxDocumentSettlementRepair;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Oprava dokladů uhrazených bez evidované úhrady (`api/bin/repair-paid-without-payment.php`):
 * vrací jen doklady, u kterých úhradu nenese evidence ani deník, a nesahá na skutečné platby,
 * zálohou kryté doklady ani na uhrazenou částku převzatou z jiného programu.
 */
#[Group('integration')]
final class PaidWithoutPaymentRepairTest extends StockTestCase
{
    use IsolatedSupplierTrait;

    private const SUPPLIER_IC = '00000001';
    private const CUSTOMER_IC = '00000002';

    private int $sid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sid = $this->company();
    }

    protected function tearDown(): void
    {
        foreach ($this->supplierIds as $supplierId) {
            $this->db->pdo()->prepare('DELETE FROM invoice_counters WHERE supplier_id = ?')->execute([$supplierId]);
        }
        parent::tearDown();
    }

    public function testRepairResetsOnlyDocumentsWithoutPaymentEvidence(): void
    {
        $ids = $this->import([
            '250001' => '2024-04-10', // uhrazeno bez úhrady, 311 otevřená → vrátit
            '250002' => '2024-04-11', // zaplaceno mimo MyÚčto, 311 vyrovnaná ručním zápisem → ponechat
            '250003' => '2024-04-12', // krytá zálohou (k úhradě 0) → mimo výběr
            '250004' => '2024-04-13', // skutečná platba v plné výši → mimo výběr
            '250005' => '2024-04-14', // částečná platba → vrátit, platba zůstává
            '250006' => '2024-04-15', // uhrazená část převzatá jen v paid_total → nikdy nevracet
            '250007' => '2024-02-10', // za zámkem účtování → ohlásit
            '250008' => '2024-04-16', // proforma, kterou čerpá konečná faktura → jen ohlásit
            '250009' => '2024-04-17', // konečná faktura k proformě (neuhrazená)
        ]);
        [$open, $closed, $advance, $payment, $partial, $unbacked, $locked, $drawn, $final] = $ids;
        foreach (array_slice($ids, 0, 8) as $id) {
            $this->markLegacyPaid($id);
        }
        $this->db->pdo()->prepare("UPDATE invoices SET invoice_type = 'proforma' WHERE id = ?")->execute([$drawn]);
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id = ? WHERE id = ?')->execute([$drawn, $final]);
        // Integrace odebírá vrácení úhrady: hromadná oprava jí webhooky posílat nesmí.
        $this->db->pdo()->prepare("INSERT INTO webhook_endpoints (supplier_id, name, url, secret_enc, event_types)
            VALUES (?, 'Syntetická integrace', 'https://example.test/hook', 'x', ?)")->execute([$this->sid, json_encode(['invoice.payment_reversed'])]);
        $this->manualSettlement($closed, 1210.0);
        $this->db->pdo()->prepare('UPDATE invoices SET advance_paid_amount = total_with_vat WHERE id = ?')->execute([$advance]);
        $this->payment($payment, 1210.0);
        $this->payment($partial, 500.0);
        $this->db->pdo()->prepare('UPDATE invoices SET paid_total = 1210 WHERE id = ?')->execute([$unbacked]);
        $this->container->get(AccountingSupplierSettingsRepository::class)->setLockedUntil($this->sid, '2024-02-29');

        $dry = $this->rows('invoice', false);
        self::assertSame([
            $open => PaidWithoutPaymentRepair::RESET,
            $closed => PaidWithoutPaymentRepair::LEDGER_CLOSED,
            $partial => PaidWithoutPaymentRepair::RESET,
            $unbacked => PaidWithoutPaymentRepair::UNBACKED_PAID_TOTAL,
            $locked => PaidWithoutPaymentRepair::LOCKED,
            $drawn => PaidWithoutPaymentRepair::ADVANCE_DRAWN,
        ], array_map(static fn (array $r): string => $r['category'], $dry));
        self::assertTrue($dry[$open]['reminders_disabled']);
        self::assertTrue($dry[$open]['webhook_suppressed']);
        self::assertSame('open', $dry[$open]['ledger']);
        self::assertSame('closed', $dry[$closed]['ledger']);
        self::assertSame('import', $dry[$open]['origin']);
        self::assertSame(710.0, $dry[$partial]['missing']);
        foreach (array_slice($ids, 0, 8) as $id) {
            self::assertSame('paid', $this->invoice($id)['status'], 'Nanečisto nic nemění.');
        }

        $done = $this->rows('invoice', true, ['skip_locked' => true]);
        self::assertSame('reset', $done[$open]['outcome']);
        self::assertSame('reset', $done[$partial]['outcome']);
        foreach ([$closed, $unbacked, $locked] as $id) {
            self::assertSame('kept', $done[$id]['outcome']);
        }

        $reset = $this->invoice($open);
        self::assertSame('sent', $reset['status']);
        $reminders = $this->db->pdo()->prepare('SELECT auto_send_reminders FROM invoices WHERE id = ?');
        foreach ([[$open, 0], [$partial, 0], [$closed, 1]] as [$id, $expected]) {
            $reminders->execute([$id]);
            self::assertSame($expected, (int) $reminders->fetchColumn(), 'Vrácený doklad nesmí cron upomínek obeslat.');
        }
        self::assertSame(0, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM webhook_deliveries WHERE supplier_id = {$this->sid} AND event_type = 'invoice.payment_reversed'")->fetchColumn());
        self::assertSame('paid', $this->invoice($drawn)['status']);
        self::assertNull($reset['paid_at']);
        self::assertSame('0.00', $reset['paid_total']);
        $partialRow = $this->invoice($partial);
        self::assertSame('sent', $partialRow['status']);
        self::assertSame('500.00', $partialRow['paid_total']);
        self::assertSame(1, $this->paymentCount($partial));
        foreach ([$closed, $advance, $payment, $unbacked, $locked] as $id) {
            self::assertSame('paid', $this->invoice($id)['status']);
        }
        self::assertSame('1210.00', $this->invoice($unbacked)['paid_total']);
        $audit = $this->db->pdo()->prepare("SELECT payload FROM activity_log WHERE action = 'invoice.unmark_paid' AND entity_type = 'invoice' AND entity_id = ?");
        $audit->execute([$open]);
        $payload = json_decode((string) $audit->fetchColumn(), true);
        self::assertSame(PaidWithoutPaymentRepair::AUDIT_SOURCE, $payload['source'] ?? null);
        self::assertTrue($payload['auto_send_reminders_disabled'] ?? false);

        // Idempotence a volby: zamčený doklad se bez --skip-locked opraví, vyrovnaný v deníku
        // jen s --include-ledger-closed, převzatá uhrazená částka nikdy.
        $again = $this->rows('invoice', true);
        self::assertSame([$closed, $unbacked, $locked, $drawn], array_keys($again));
        self::assertSame('reset', $again[$locked]['outcome']);
        self::assertSame('kept', $again[$closed]['outcome']);
        $last = $this->rows('invoice', true, ['include_ledger_closed' => true]);
        self::assertSame([$closed, $unbacked, $drawn], array_keys($last));
        self::assertSame('reset', $last[$closed]['outcome']);
        self::assertSame('kept', $last[$unbacked]['outcome']);
        self::assertSame('paid', $this->invoice($unbacked)['status']);
        self::assertSame([$unbacked, $drawn], array_keys($this->rows('invoice', false)));
    }

    /**
     * Přijaté doklady se vracejí jen s výslovnou volbou: označené uživatelem (i skutečným
     * přechodem stavu, jehož audit nenese firmu), v daňové evidenci až s druhou volbou.
     * Doklad z Fakturoidu dostane krytí, převedený doklad a čerpaná záloha se jen ohlásí.
     */
    public function testPurchasesAreCoveredOrReportedAndResetOnlyOnRequest(): void
    {
        $pdo = $this->db->pdo();
        $vendor = $this->client($this->sid, 'Syntetický dodavatel');
        // Doklad převzatý z Fakturoidu jako přijatý, uhrazený až uživatelem v MyÚčtu: audit
        // přechodu stavu nenese firmu, rozhoduje jen doklad.
        $manual = $this->purchaseInvoice($this->sid, $vendor, ['issue_date' => '2024-05-03', 'due_date' => '2024-05-03', 'status' => 'received']);
        $pdo->prepare('UPDATE purchase_invoices SET total_without_vat = 1000, total_vat = 210, total_with_vat = 1210, tax_date = issue_date, fakturoid_id = ? WHERE id = ?')
            ->execute([990000 + $manual, $manual]);
        $response = $this->transition($manual, ['target' => 'paid', 'paid_date' => '2024-05-20']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $app = $this->paidPurchase($vendor, '2024-05-05');
        $fakturoid = $this->paidPurchase($vendor, '2024-05-06');
        // Platba kartou převzatá z Fakturoidu není ruční označení v MyÚčtu.
        $pdo->prepare("UPDATE purchase_invoices SET fakturoid_id = ?, payment_method = 'card' WHERE id = ?")->execute([990000 + $fakturoid, $fakturoid]);
        $migrated = $this->paidPurchase($vendor, '2024-05-07');
        $pdo->prepare("INSERT INTO helios_import_map (supplier_id, kind, source_key, target_id) VALUES (?, 'purchase_invoice', ?, ?)")
            ->execute([$this->sid, 'PF-' . $migrated, $migrated]);
        $advance = $this->paidPurchase($vendor, '2024-05-08');
        $final = $this->purchaseInvoice($this->sid, $vendor, ['issue_date' => '2024-05-09', 'status' => 'received']);
        $pdo->prepare("UPDATE purchase_invoices SET document_kind = 'advance' WHERE id = ?")->execute([$advance]);
        $pdo->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id = ? WHERE id = ?')->execute([$advance, $final]);
        // Daňová evidence od září: doklad uhrazený v ní je výdajem peněžního deníku.
        $this->container->get(AccountingModeRepository::class)->record($this->sid, '2024-09-01', 'tax_evidence');
        $taxEvidence = $this->paidPurchase($vendor, '2024-09-10');

        $dry = $this->rows('purchase_invoice', false);
        self::assertSame([
            $manual => PaidWithoutPaymentRepair::MANUAL,
            $app => PaidWithoutPaymentRepair::MANUAL,
            $fakturoid => PaidWithoutPaymentRepair::SOURCE_PAID,
            $migrated => PaidWithoutPaymentRepair::MIGRATION_SOURCE,
            $advance => PaidWithoutPaymentRepair::ADVANCE_DRAWN,
            $taxEvidence => PaidWithoutPaymentRepair::MANUAL,
        ], array_map(static fn (array $r): string => $r['category'], $dry));
        self::assertContains(PaidWithoutPaymentRepair::TAX_EVIDENCE, $dry[$taxEvidence]['flags']);
        self::assertSame('would_cover', $dry[$fakturoid]['outcome']);

        $done = $this->rows('purchase_invoice', true);
        self::assertSame('covered', $done[$fakturoid]['outcome']);
        foreach ([$manual, $app, $migrated, $advance, $taxEvidence] as $id) {
            self::assertSame('kept', $done[$id]['outcome']);
        }
        $row = $pdo->prepare('SELECT status, paid_at, settled_outside_amount FROM purchase_invoices WHERE id = ?');
        $row->execute([$fakturoid]);
        self::assertSame(['status' => 'paid', 'paid_at' => '2024-05-06', 'settled_outside_amount' => '1210.00'], $row->fetch(PDO::FETCH_ASSOC));

        $withManual = $this->rows('purchase_invoice', true, ['include_manual' => true]);
        self::assertSame(['reset', 'reset', 'kept'], [$withManual[$manual]['outcome'], $withManual[$app]['outcome'], $withManual[$taxEvidence]['outcome']]);
        $row->execute([$manual]);
        self::assertSame(['status' => 'received', 'paid_at' => null], array_slice($row->fetch(PDO::FETCH_ASSOC), 0, 2));
        self::assertSame('reset', $this->rows('purchase_invoice', true, ['include_manual' => true, 'include_tax_evidence' => true])[$taxEvidence]['outcome']);
        foreach ([$migrated, $advance] as $id) {
            $row->execute([$id]);
            self::assertSame('paid', $row->fetch(PDO::FETCH_ASSOC)['status']);
        }
    }

    /**
     * Nedoplatek, který aplikace bere jako uhrazeno, oprava nevrací: vydaný doklad v toleranci
     * plateb v měně dokladu (jako přepočet plateb), přijatý v toleranci 1 Kč včetně
     * zaokrouhlení dokladu (jako saldo a „uhrazeno s rozdílem").
     */
    public function testToleranceFollowsTheApplicationPaymentRules(): void
    {
        $pdo = $this->db->pdo();
        [$eurPaid, $eurShort] = $this->import(['250201' => '2024-06-10', '250202' => '2024-06-11']);
        $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
            VALUES (?, 'EUR', 'EUR', '€', 'Euro', 'Euro', 2, 1, 0)")->execute([$this->sid]);
        $eur = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE invoices SET currency_id = ?, exchange_rate = 25 WHERE id IN (?, ?)')->execute([$eur, $eurPaid, $eurShort]);
        foreach ([$eurPaid, $eurShort] as $id) {
            $this->markLegacyPaid($id);
        }
        $this->payment($eurPaid, 1209.98);
        $this->payment($eurShort, 1209.90);

        $vendor = $this->client($this->sid, 'Syntetický dodavatel zaokrouhlení');
        $rounded = $this->paidPurchase($vendor, '2024-06-12');
        $pdo->prepare('UPDATE purchase_invoices SET total_with_vat = 12468.40, rounding = -0.40, settled_outside_amount = 12468 WHERE id = ?')->execute([$rounded]);
        $withinCzk = $this->paidPurchase($vendor, '2024-06-13');
        $pdo->prepare('UPDATE purchase_invoices SET settled_outside_amount = 1209.20 WHERE id = ?')->execute([$withinCzk]);
        $short = $this->paidPurchase($vendor, '2024-06-14');
        $pdo->prepare('UPDATE purchase_invoices SET settled_outside_amount = 1200 WHERE id = ?')->execute([$short]);

        self::assertSame([$eurShort], array_keys($this->rows('invoice', false)), 'Nedoplatek 0,02 EUR je v toleranci plateb, 0,10 EUR ne.');
        self::assertSame([$short], array_keys($this->rows('purchase_invoice', false)), 'Zaokrouhlení a nedoplatek do 1 Kč jsou uhrazeno.');
    }

    /** @param array<string,mixed> $body */
    private function transition(int $purchaseId, array $body): \Psr\Http\Message\ResponseInterface
    {
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('POST', '/api/purchase-invoices/' . $purchaseId . '/transition')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->sid)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody($body);
        $response = $this->container->get(\MyInvoice\Action\PurchaseInvoice\TransitionPurchaseInvoiceStatusAction::class)
            ->__invoke($request, new \Slim\Psr7\Response(), ['id' => (string) $purchaseId]);
        $response->getBody()->rewind();
        return $response;
    }

    public function testTaxDocumentPaidByPaidTotalIsSettledByAdvanceLikeNative(): void
    {
        [$ddkp, $foreign, $locked] = $this->import(['250101' => '2024-05-10', '250102' => '2024-05-11', '250103' => '2024-02-12']);
        $pdo = $this->db->pdo();
        // Tvar, který po sobě nechaly převody: DDKP uhrazený jen uhrazenou částkou, záloha 0.
        $pdo->prepare("UPDATE invoices SET invoice_type = 'tax_document', status = 'paid', paid_at = tax_date, advance_paid_amount = 0, paid_total = total_with_vat
                        WHERE id IN (?, ?, ?)")->execute([$ddkp, $foreign, $locked]);
        $this->container->get(AccountingSupplierSettingsRepository::class)->setLockedUntil($this->sid, '2024-02-29');
        $pdo->prepare("UPDATE invoices SET exchange_rate = NULL, note_below_items = 'doklad v EUR, převzat v Kč (kurz 25)' WHERE id = ?")->execute([$foreign]);
        $repair = $this->container->get(TaxDocumentSettlementRepair::class);
        $run = function (bool $commit) use ($repair): array {
            $out = [];
            foreach ($repair->run($this->sid, $commit) as $row) {
                $out[$row['id']] = $row;
            }
            return $out;
        };

        $dry = $run(false);
        self::assertSame([$ddkp => TaxDocumentSettlementRepair::SETTLE, $foreign => TaxDocumentSettlementRepair::FOREIGN_PENDING,
            $locked => TaxDocumentSettlementRepair::LOCKED], array_map(static fn (array $r): string => $r['category'], $dry));
        $done = $run(true);
        self::assertSame(['reset', 'kept', 'kept'], [$done[$ddkp]['outcome'], $done[$foreign]['outcome'], $done[$locked]['outcome']],
            'DDKP v zamčeném období se jen ohlásí.');
        $state = $pdo->prepare('SELECT status, paid_at, amount_to_pay, paid_total, advance_paid_amount, total_with_vat FROM invoices WHERE id = ?');
        $state->execute([$ddkp]);
        $row = $state->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['paid', '0.00', '0.00'], [$row['status'], $row['amount_to_pay'], $row['paid_total']]);
        self::assertSame($row['total_with_vat'], $row['advance_paid_amount']);
        self::assertSame('2024-05-10', substr((string) $row['paid_at'], 0, 10));
        self::assertSame([$foreign, $locked], array_keys($run(false)), 'Opakovaná oprava už nic nemění.');

        $out = $this->cli(["--supplier={$this->sid}", '--report=' . ($report = tempnam(sys_get_temp_dir(), 'paid'))]);
        self::assertStringContainsString('[NANEČISTO]', $out);
        self::assertStringContainsString('DDKP, DDKP čeká na převod měny, ponechat: 1 dokladů, k úhradě převedeno na zálohu', $out);
        self::assertStringNotContainsString('DDKP, DDKP čeká na převod měny, ponechat: 1 dokladů, chybí', $out);
        $json = json_decode((string) file_get_contents($report), true);
        @unlink($report);
        self::assertFalse($json['commit']);
        self::assertSame($this->sid, $json['suppliers'][0]['id']);
    }

    private function cli(array $args): string
    {
        $script = dirname(__DIR__, 3) . '/bin/repair-paid-without-payment.php';
        $env = getenv();
        $env['MYINVOICE_DB_NAME'] = (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $env['MYINVOICE_DB_NAME']);
        $process = proc_open([PHP_BINARY, $script, ...$args], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $env, ['bypass_shell' => true]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $out);
        return $out;
    }

    /**
     * @param array<string,bool> $options
     * @return array<int,array<string,mixed>>
     */
    private function rows(string $docType, bool $commit, array $options = []): array
    {
        $out = [];
        foreach ($this->container->get(PaidWithoutPaymentRepair::class)->run($this->sid, $docType, $commit, $options) as $row) {
            $out[$row['id']] = $row;
        }
        return $out;
    }

    private function company(): int
    {
        $id = $this->createSupplier('double_entry', false, false);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET ic = ?, accounting_enabled = 1, auto_post_invoices = 1, accounting_starts_on = '2024-01-01' WHERE id = ?")
            ->execute([self::SUPPLIER_IC, $id]);
        $this->container->get(AccountingModeRepository::class)->record($id, '2024-01-01', 'double_entry');
        $this->setVatPayerAt($pdo, $id, '2023-01-01', true);
        $clientId = $this->client($id, 'Syntetický odběratel opravy');
        $pdo->prepare('UPDATE clients SET ic = ? WHERE id = ?')->execute([self::CUSTOMER_IC, $clientId]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($id, '2024-01-01');
        $this->container->get(AccountingPeriodRepository::class)->create($id, 2024, '2024-01-01', '2024-12-31');
        return $id;
    }

    /**
     * @param array<string,string> $dates číslo dokladu => datum vystavení i DUZP
     * @return list<int>
     */
    private function import(array $dates): array
    {
        $files = [];
        foreach ($dates as $number => $date) {
            $files[] = ['name' => $number . '.xml', 'content' => $this->pohodaInvoice((string) $number, $date)];
        }
        $report = $this->container->get(InvoiceImportService::class)->importBundle($files, $this->sid, $this->userId, 'issued');
        self::assertSame(count($files), $report['summary']['created'], json_encode($report['results'], JSON_UNESCAPED_UNICODE));
        return array_map('intval', array_column($report['results'], 'invoice_id'));
    }

    /** Stav, který po sobě nechal starší import souborů: uhrazeno bez platby. */
    private function markLegacyPaid(int $id): void
    {
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_at = tax_date WHERE id = ?")->execute([$id]);
    }

    private function payment(int $id, float $amount): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source) VALUES (?, ?, '2024-04-20', ?, 'CZK', 'manual')")
            ->execute([$this->sid, $id, $amount]);
        $pdo->prepare('UPDATE invoices SET paid_total = ? WHERE id = ?')->execute([$amount, $id]);
    }

    private function paymentCount(int $id): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }

    /** Úhrada mimo MyÚčto: ruční zápis 221/311 navázaný na doklad. */
    private function manualSettlement(int $invoiceId, float $amount): void
    {
        $pdo = $this->db->pdo();
        $receivable = $pdo->prepare(
            "SELECT l.account_id, e.period_id FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = ? AND e.source_type = 'invoice' AND e.source_id = ? AND a.account_code LIKE '311%' LIMIT 1"
        );
        $receivable->execute([$this->sid, $invoiceId]);
        $line = $receivable->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($line, 'Doklad musí být zaúčtovaný.');
        $bank = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code LIKE '221%' ORDER BY account_code LIMIT 1");
        $bank->execute([$this->sid]);
        $bankAccount = (int) $bank->fetchColumn();
        self::assertGreaterThan(0, $bankAccount);
        $pdo->prepare("INSERT INTO journal_entries (supplier_id, period_id, entry_date, description, source_type, posted_at)
                       VALUES (?, ?, '2024-04-30', 'Úhrada mimo MyÚčto', 'manual', NOW())")->execute([$this->sid, $line['period_id']]);
        $entryId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([$entryId, $this->sid, $bankAccount, 'debit', $amount, 1]);
        $insert->execute([$entryId, $this->sid, (int) $line['account_id'], 'credit', $amount, 2]);
        $pdo->prepare("INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id) VALUES (?, ?, 'invoice', ?)")
            ->execute([$this->sid, $entryId, $invoiceId]);
    }

    private function paidPurchase(int $vendor, string $date): int
    {
        $id = $this->purchaseInvoice($this->sid, $vendor, ['issue_date' => $date, 'due_date' => $date, 'status' => 'received']);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET total_without_vat = 1000, total_vat = 210, total_with_vat = 1210,
                status = 'paid', paid_at = ?, tax_date = ? WHERE id = ?")->execute([$date, $date, $id]);
        return $id;
    }

    /** @return array<string,mixed> */
    private function invoice(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, paid_at, paid_total FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    private function pohodaInvoice(string $number, string $date): string
    {
        $supplier = self::SUPPLIER_IC;
        $customerIc = self::CUSTOMER_IC;
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
                          xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
                          xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd" version="2.0" ico="{$supplier}">
              <dat:dataPackItem version="2.0">
                <inv:invoice version="2.0">
                  <inv:invoiceHeader>
                    <inv:invoiceType>issuedInvoice</inv:invoiceType>
                    <inv:number><typ:numberRequested>{$number}</typ:numberRequested></inv:number>
                    <inv:symVar>{$number}</inv:symVar>
                    <inv:date>{$date}</inv:date><inv:dateTax>{$date}</inv:dateTax><inv:dateDue>{$date}</inv:dateDue>
                    <inv:partnerIdentity><typ:address><typ:company>Syntetický odběratel opravy</typ:company><typ:ico>{$customerIc}</typ:ico>
                      <typ:street>Testovací 1</typ:street><typ:city>Praha</typ:city><typ:zip>11000</typ:zip>
                      <typ:country><typ:ids>CZ</typ:ids></typ:country>
                    </typ:address></inv:partnerIdentity>
                  </inv:invoiceHeader>
                  <inv:invoiceDetail><inv:invoiceItem>
                    <inv:text>Konzultace</inv:text><inv:quantity>1</inv:quantity><inv:unit>ks</inv:unit><inv:rateVAT>high</inv:rateVAT>
                    <inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice></inv:homeCurrency>
                  </inv:invoiceItem></inv:invoiceDetail>
                  <inv:invoiceSummary><inv:homeCurrency>
                    <typ:priceHigh>1000</typ:priceHigh><typ:priceHighVAT rate="21">210</typ:priceHighVAT>
                  </inv:homeCurrency></inv:invoiceSummary>
                </inv:invoice>
              </dat:dataPackItem>
            </dat:dataPack>
            XML;
    }
}

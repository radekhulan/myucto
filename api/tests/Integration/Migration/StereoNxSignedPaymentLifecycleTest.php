<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Bank\BankTransactionReleaseService;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingPayments;
use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Service\Migration\StereoNx\StereoNxImporter;
use MyInvoice\Service\Migration\StereoNx\StereoNxSourcePlan;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxTables;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticNx1Archive.php';
require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticStereoNxTables.php';

#[Group('integration')]
final class StereoNxSignedPaymentLifecycleTest extends TestCase
{
    use \MyInvoice\Tests\Support\IsolatedSupplierTrait;

    private ContainerInterface $container;
    private Connection $db;
    private int $supplierId;
    private int $userId;
    private string $archive;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildApp()->getContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn());
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $identity = SyntheticStereoNxTables::identity();
        $pdo->prepare("UPDATE supplier SET company_name=?, ic=?, dic=?, accounting_mode='double_entry' WHERE id=?")
            ->execute([$identity['name'], $identity['ico'], $identity['dic'], $this->supplierId]);
        $pdo->prepare('INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default)
            VALUES (?,"CZK","CZK","Kč","CZK","CZK",2,1,1)')->execute([$this->supplierId]);
        $pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')
            ->execute([(int) $pdo->lastInsertId(), $this->supplierId]);
        $this->archive = sys_get_temp_dir() . '/stereo-refund-lifecycle-' . bin2hex(random_bytes(6)) . '.zip';
    }

    protected function tearDown(): void
    {
        @unlink($this->archive ?? '');
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testImportedRefundAndManualUnmatchKeepPayableOverviewsConsistent(): void
    {
        $pdo = $this->db->pdo();
        $importer = $this->container->get(StereoNxImporter::class);
        $payments = $this->container->get(StereoNxAccountingPayments::class);
        $documents = StereoNxSourcePlan::fromTables(self::tables(false), SyntheticStereoNxTables::identity(), true, true);
        $documents['source_company_index'] = 0;
        $importer->writeAccountingPartners($documents['clients'], $documents['identity'], 0, $this->supplierId);
        $importer->writeAccountingDocuments($documents, $this->supplierId, $this->userId);
        $stmt = $pdo->prepare('SELECT id FROM purchase_invoices WHERE supplier_id=?');
        $stmt->execute([$this->supplierId]);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(1, $ids);
        $invoiceId = (int) $ids[0];

        // Dva úplné zdrojové snímky nad stejným předpisem. Rollback oddělí jejich
        // importní mapy i stav úhrady; test neupravuje částky ani vazby hotového plánu.
        $pdo->exec('SAVEPOINT before_signed_payments');
        $first = $payments->write($this->paymentPlan(false, $documents), $this->supplierId, $this->userId);
        self::assertSame(1, $first['counts']['payments']);
        $observed['before_refund'] = $this->overview($invoiceId);
        $pdo->exec('ROLLBACK TO SAVEPOINT before_signed_payments');

        $refunded = $payments->write($this->paymentPlan(true, $documents), $this->supplierId, $this->userId);
        self::assertSame(2, $refunded['counts']['payments']);
        $observed['with_refund'] = $this->overview($invoiceId);
        $stmt = $pdo->prepare('SELECT bt.id, bt.amount FROM bank_transactions bt
            JOIN payment_matches pm ON pm.bank_transaction_id=bt.id
            WHERE pm.supplier_id=? AND pm.purchase_invoice_id=? AND bt.amount > 0');
        $stmt->execute([$this->supplierId, $invoiceId]);
        $refunds = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $refunds);
        self::assertSame(21.0, (float) $refunds[0]['amount']);
        $refundId = (int) $refunds[0]['id'];

        $this->container->get(BankTransactionReleaseService::class)
            ->release($this->supplierId, $refundId, BankTransactionReleaseService::MODE_UNMATCH, $this->userId);
        // Stavový filtr po odpárování pokrývá PurchaseRefundReleaseStatusTest v opravě
        // nativního jádra; zde ověřujeme finanční zůstatek a skutečné odstranění vazby.
        $observed['after_refund_unmatch'] = $this->overview($invoiceId, false);
        $stmt = $pdo->prepare('SELECT match_status, match_reason FROM bank_transactions WHERE id=?');
        $stmt->execute([$refundId]);
        self::assertSame(['match_status' => 'unmatched', 'match_reason' => 'migration_review'], $stmt->fetch(\PDO::FETCH_ASSOC));
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM payment_matches WHERE supplier_id=? AND purchase_invoice_id=?');
        $stmt->execute([$this->supplierId, $invoiceId]);
        self::assertSame(1, (int) $stmt->fetchColumn());
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=?');
        $stmt->execute([$this->supplierId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Odpárování nesmí vytvářet nové zaúčtování importu.');

        $paid = ['paid' => 121.0, 'remaining' => 0.0, 'unpaid_count' => 0, 'paid_count' => 1, 'aging_count' => 0, 'aging_total' => 0.0];
        $afterUnmatch = $paid;
        unset($afterUnmatch['paid_count']);
        self::assertSame([
            'before_refund' => $paid,
            'with_refund' => ['paid' => 100.0, 'remaining' => 21.0, 'unpaid_count' => 1, 'paid_count' => 0, 'aging_count' => 1, 'aging_total' => 21.0],
            'after_refund_unmatch' => $afterUnmatch,
        ], $observed, 'Vratka snižuje úhradu; její odpárování obnoví finanční zůstatek a vyřadí závazek z neuhrazených.');
    }

    #[DataProvider('paymentCases')]
    public function testNativeAllocationCacheAndRepeatRemainConsistent(bool $refund, bool $credit, float $expectedPaid, bool $unverifiedCredit = false): void
    {
        $tables = self::tables($refund, $credit, $unverifiedCredit);
        $documents = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);
        $documents['source_company_index'] = 0;
        $importer = $this->container->get(StereoNxImporter::class);
        $importer->writeAccountingPartners($documents['clients'], $documents['identity'], 0, $this->supplierId);
        $importer->writeAccountingDocuments($documents, $this->supplierId, $this->userId);
        $payments = $this->container->get(StereoNxAccountingPayments::class);
        $plan = $this->paymentPlan($refund, $documents, $credit, $unverifiedCredit);
        $first = $payments->write($plan, $this->supplierId, $this->userId);
        self::assertSame($refund ? 2 : 1, $first['counts']['payments']);
        $pdo = $this->db->pdo();
        $query = $pdo->prepare('SELECT id, paid_amount_invoice_ccy, status FROM purchase_invoices WHERE supplier_id=?');
        $query->execute([$this->supplierId]);
        $invoice = $query->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($invoice);
        self::assertSame($expectedPaid, (float) $invoice['paid_amount_invoice_ccy']);
        self::assertSame($unverifiedCredit ? 'draft' : ($refund ? 'received' : 'paid'), $invoice['status']);
        if ($unverifiedCredit) self::assertContains('document_tax_mapping_unverified', $documents['purchases'][0]['review_codes']);
        $rows = $this->purchaseRows([]);
        self::assertSame($credit ? -$expectedPaid : $expectedPaid, (float) $rows[(int) $invoice['id']]['paid_amount']);
        self::assertSame($refund ? 21.0 : 0.0, (float) $rows[(int) $invoice['id']]['remaining_amount']);
        $matches = $pdo->prepare('SELECT id, amount FROM payment_matches WHERE supplier_id=? ORDER BY id');
        $matches->execute([$this->supplierId]);
        $before = $matches->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame($refund ? [121.0, 21.0] : [121.0], array_map('floatval', array_column($before, 'amount')));
        $repeat = $payments->write($plan, $this->supplierId, $this->userId);
        self::assertSame(0, $repeat['counts']['payments']);
        $matches->execute([$this->supplierId]);
        self::assertSame($before, $matches->fetchAll(\PDO::FETCH_ASSOC));
        $query->execute([$this->supplierId]);
        self::assertSame($invoice, $query->fetch(\PDO::FETCH_ASSOC));
    }

    public static function paymentCases(): iterable
    {
        yield 'plná úhrada' => [false, false, 121.0];
        yield 'úhrada s vratkou' => [true, false, 100.0];
        yield 'ověřená záporná přijatá faktura' => [false, true, 121.0];
        yield 'neověřený dobropis zůstane konceptem' => [false, true, 121.0, true];
    }

    public function testFullRefundReopensPreviouslyPaidImportedPurchase(): void
    {
        $tables = self::tables(false);
        $documents = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);
        $documents['source_company_index'] = 0;
        $importer = $this->container->get(StereoNxImporter::class);
        $importer->writeAccountingPartners($documents['clients'], $documents['identity'], 0, $this->supplierId);
        $importer->writeAccountingDocuments($documents, $this->supplierId, $this->userId);
        $payments = $this->container->get(StereoNxAccountingPayments::class);
        $payments->write($this->paymentPlan(false, $documents), $this->supplierId, $this->userId);
        $before = array_values($this->purchaseRows([]));
        self::assertSame('paid', $before[0]['status']);

        $tables = self::tables(true);
        $tables['Cpz'][0]['Uhrazeno'] = 0.0;
        $tables['CBankap'][1]['Castka'] = $tables['CBankap'][1]['CastkaVlastni'] = -121.0;
        SyntheticNx1Archive::write($this->archive, $tables, SyntheticStereoNxTables::identity());
        $plan = $payments->prepare(StereoNxBackup::open($this->archive, 0));
        self::assertSame(2, $plan['counts']['payments']);
        $plan['documents'] = $documents;
        $result = $payments->write($plan, $this->supplierId, $this->userId);
        self::assertSame(1, $result['counts']['payments']);
        self::assertSame([
            'paid' => 0.0, 'remaining' => 121.0, 'unpaid_count' => 1,
            'paid_count' => 0, 'aging_count' => 1, 'aging_total' => 121.0,
        ], $this->overview((int) $before[0]['id']), 'Nulový čistý součet po vratce není ruční úhrada bez evidence.');
    }

    #[DataProvider('roundingCases')]
    public function testImportedPaymentUsesNativeRoundingAndSettlementTolerance(float $rounding, float $payment, float $remaining, string $status): void
    {
        $tables = self::tables(false);
        $tables['SPFH'][0]['Zaokrouhleni'] = $rounding;
        $tables['SPFH'][0]['Celkem'] = round(121.0 + $rounding, 2);
        $tables['Cpz'][0]['Celkem'] = $tables['SPFH'][0]['Celkem'];
        $tables['Cpz'][0]['Uhrazeno'] = $payment;
        $tables['Cpz'][0]['UhrazenoVse'] = $payment === $tables['SPFH'][0]['Celkem'];
        $tables['CBankap'][0]['Castka'] = $tables['CBankap'][0]['CastkaVlastni'] = $payment;
        $documents = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);
        $documents['source_company_index'] = 0;
        $importer = $this->container->get(StereoNxImporter::class);
        $importer->writeAccountingPartners($documents['clients'], $documents['identity'], 0, $this->supplierId);
        $importer->writeAccountingDocuments($documents, $this->supplierId, $this->userId);
        SyntheticNx1Archive::write($this->archive, $tables, SyntheticStereoNxTables::identity());
        $payments = $this->container->get(StereoNxAccountingPayments::class);
        $plan = $payments->prepare(StereoNxBackup::open($this->archive, 0));
        self::assertSame(1, $plan['counts']['payments']);
        $plan['documents'] = $documents;
        $payments->write($plan, $this->supplierId, $this->userId);
        $rows = array_values($this->purchaseRows([]));
        self::assertCount(1, $rows);
        self::assertSame($payment, (float) $rows[0]['paid_amount']);
        self::assertSame($remaining, round((float) $rows[0]['remaining_amount'], 2));
        self::assertSame($status, $rows[0]['status']);
    }

    public static function roundingCases(): iterable
    {
        yield 'zaokrouhlení se nepřičítá podruhé k vyrovnanému nominálu' => [0.4, 121.4, 0.0, 'paid'];
        yield 'rozdíl v nativní korunové toleranci' => [0.0, 120.25, 0.75, 'paid'];
        yield 'nedoplatek nad tolerancí zůstane otevřený' => [0.0, 119.99, 1.01, 'received'];
    }

    /** @return array<string,int|float> */
    private function overview(int $invoiceId, bool $includePaidStatus = true): array
    {
        $rows = $this->purchaseRows([]);
        self::assertArrayHasKey($invoiceId, $rows);
        $aging = $this->container->get(CrmAggregationService::class)->agingPayables($this->supplierId);
        return [
            'paid' => round((float) $rows[$invoiceId]['paid_amount'], 2),
            'remaining' => round((float) $rows[$invoiceId]['remaining_amount'], 2),
            'unpaid_count' => count($this->purchaseRows(['unpaid_only' => true])),
            ...($includePaidStatus ? ['paid_count' => count($this->purchaseRows(['status' => 'paid']))] : []),
            'aging_count' => (int) array_sum(array_column($aging, 'count')),
            'aging_total' => round((float) array_sum(array_column($aging, 'total')), 2),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function purchaseRows(array $filters): array
    {
        $result = $this->container->get(PurchaseInvoiceRepository::class)
            ->listGroupedByMonth($filters + ['supplier_id' => $this->supplierId], 1, 50);
        $rows = [];
        foreach ($result['data'] as $group) {
            foreach ($group['invoices'] as $row) $rows[(int) $row['id']] = $row;
        }
        return $rows;
    }

    /** @param array<string,mixed> $documents @return array<string,mixed> */
    private function paymentPlan(bool $refund, array $documents, bool $credit = false, bool $unverifiedCredit = false): array
    {
        SyntheticNx1Archive::write($this->archive, self::tables($refund, $credit, $unverifiedCredit), SyntheticStereoNxTables::identity());
        $plan = $this->container->get(StereoNxAccountingPayments::class)->prepare(StereoNxBackup::open($this->archive, 0));
        self::assertSame(0, $plan['counts']['requires_review']);
        $plan['documents'] = $documents;
        return $plan;
    }

    /** @return array<string,list<array<string,mixed>>> */
    private static function tables(bool $refund, bool $credit = false, bool $unverifiedCredit = false): array
    {
        $tables = SyntheticStereoNxTables::tables();
        // Oddělený platný prefix nekoliduje s účtem základního testovacího tenanta.
        $tables['LFirmaUc'][0]['BaUcet'] = '19-1000000005';
        $tables['LFirmaUc'][0]['KodBanky'] = '0100';
        $tables['Svfh'] = $tables['Svfp'] = $tables['CPokl'] = $tables['Cdenik'] = [];
        $tables['SPFH'] = [$tables['SPFH'][0]];
        $tables['Cpz'] = [array_replace($tables['Cpz'][1], [
            'Agenda' => 'PF', 'Uhrazeno' => $refund ? 100.0 : 121.0, 'UhrazenoVse' => !$refund,
        ])];
        $tables['CPZZ'] = [array_replace($tables['CPZZ'][1], ['Mena' => 'Kč'])];
        $tables['CBanka'][0]['Kurz'] = $tables['CBanka'][0]['KurzMn'] = 1.0;
        $payment = array_replace($tables['CBankap'][1], [
            'Kurz' => 1.0, 'KurzMn' => 1.0, 'CastkaVlastni' => 121.0,
            'DPHz' => 0.0, 'DPHs' => 0.0, 'DPHt' => 0.0,
        ]);
        $tables['CBankap'] = [$payment];
        if ($refund) {
            $tables['CBankap'][] = array_replace($payment, [
                'Klic' => 6, 'Castka' => -21.0, 'CastkaVlastni' => -21.0,
                'Text' => 'Syntetická vratka úhrady',
            ]);
        }
        if ($credit) {
            foreach (['ZaklDPHz', 'DPHz', 'Celkem'] as $field) $tables['SPFH'][0][$field] *= -1;
            $tables['SPFH'][0]['TypDokladu'] = $unverifiedCredit ? 'D' : 'F';
            $tables['Cpz'][0]['Celkem'] = $tables['Cpz'][0]['Uhrazeno'] = -121.0;
            $tables['CBankap'][0]['Castka'] = $tables['CBankap'][0]['CastkaVlastni'] = -121.0;
        }
        return $tables;
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\EmailNoticeReconciler;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

final class EmailNoticeCardFxSplitRegressionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private \Psr\Container\ContainerInterface $container;
    private int $supplier;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        $source = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $source, 'Vyžadován syntetický seed testovací databáze.');
        $this->supplier = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET accounting_mode='tax_evidence',accounting_enabled=0 WHERE id=?")->execute([$this->supplier]);
        $pdo->prepare("INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,account_number,bank_code) VALUES (?,'CZK','Synthetic batch','Kč','Koruna','Koruna','1000000005','0100')")->execute([$this->supplier]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    private function movement(string $source, float $amount, string $date): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO bank_statements (supplier_id,source,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,?,'Synthetic FX split',?,'1000000005','0100','CZK',?)")
            ->execute([$this->supplier, $source, hash('sha256', random_bytes(20)), $date]);
        $statement = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id,source,source_ref,posted_at,amount,currency,variable_symbol) VALUES (?,?,?,?,?,'CZK','')")
            ->execute([$statement, $source === 'email_notice' ? 'email_notice' : 'statement', 'synthetic-fx-split:' . bin2hex(random_bytes(10)), $date, $amount]);
        return (int) $pdo->lastInsertId();
    }

    public function testCardFxTwinWithSplitPurchaseAllocationsIsNotAutomaticallyTakenOver(): void
    {
        $pdo = $this->db->pdo();
        $currency = (int) $pdo->query('SELECT id FROM currencies WHERE supplier_id=' . $this->supplier . ' LIMIT 1')->fetchColumn();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn();
        $user = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $user);
        $pdo->prepare("INSERT INTO clients (supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) VALUES (?,'Synthetic FX vendor','Test 1','Test','11000',?,'',?)")
            ->execute([$this->supplier, $country, $currency]);
        $vendor = (int) $pdo->lastInsertId();
        $notice = $this->movement('email_notice', -100, '2099-06-14');
        $target = $this->movement('gpc', -102, '2099-06-15');
        $allocations = [];
        foreach ([40, 60] as $amount) {
            $pdo->prepare("INSERT INTO purchase_invoices (supplier_id,vendor_id,vendor_invoice_number,issue_date,tax_date,due_date,received_at,currency_id,vendor_snapshot,total_without_vat,total_vat,total_with_vat,status,created_by) VALUES (?,?,?,'2099-06-14','2099-06-14','2099-06-14','2099-06-14',?,'{}',?,0,?,'received',?)")
                ->execute([$this->supplier, $vendor, 'SYNTHETIC-FX-SPLIT-' . $amount, $currency, $amount, $amount, $user]);
            $purchase = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO payment_matches (supplier_id,bank_transaction_id,purchase_invoice_id,amount,match_type) VALUES (?,?,?,?,'manual')")
                ->execute([$this->supplier, $notice, $purchase, $amount]);
            $allocations[] = (int) $pdo->lastInsertId();
        }
        $pdo->prepare("UPDATE bank_transactions SET match_status='manual' WHERE id=?")->execute([$notice]);
        $before = $pdo->query('SELECT id,bank_transaction_id,purchase_invoice_id,amount FROM payment_matches WHERE bank_transaction_id=' . $notice . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $reconciler = new EmailNoticeReconciler($this->db, null);
        self::assertNull($reconciler->findTakeoverCandidate($target), 'Rozdíl kurzu nesmí být automaticky přiřazen více přijatým dokladům.');
        self::assertSame($before, $pdo->query('SELECT id,bank_transaction_id,purchase_invoice_id,amount FROM payment_matches WHERE bank_transaction_id=' . $notice . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(['40.00', '60.00'], array_column($before, 'amount'));
        // Pozitivní kontrola: stejný pohyb s jedinou alokací projde ostatními guardy.
        $pdo->prepare('DELETE FROM payment_matches WHERE id=?')->execute([$allocations[1]]);
        $pdo->prepare('UPDATE payment_matches SET amount=100 WHERE id=?')->execute([$allocations[0]]);
        self::assertSame($notice, (int) ($reconciler->findTakeoverCandidate($target)['id'] ?? 0));
    }
}

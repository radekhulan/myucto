<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Crm;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\Invoice\ProformaPaymentDocuments;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Uhrazená záloha bez dokladu v denním přehledu úkolů (issue #39, migrace 1566).
 *
 * Ruční režim `proforma_payment_document = 'manual'` nezakládá po úhradě zálohy nic.
 * Funkčně díru nedělá — obě ruční akce existují — ale dělá díru v POZORNOSTI: bez
 * konceptu v seznamu dokladů není nic, co by připomnělo, že § 28 ZDPH dává na
 * vystavení daňového dokladu k přijaté platbě 15 dnů. Položka v úkolech je tedy
 * podmínka, za které ten režim vůbec smí existovat; kdyby zmizela, stane se z volby
 * tichá past a na uplynulou lhůtu se přijde až od finančního úřadu.
 *
 * Metoda: transakce + rollback, delta proti baseline (cizí data v okně nevadí).
 */
#[Group('integration')]
final class CrmProformaAwaitingDocumentTest extends TestCase
{
    private const ITEM_TYPE = 'proforma_awaiting_document';

    private Connection $db;
    private CrmAggregationService $crm;
    private \PDO $pdo;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $czkId = 0;
    private int $czId = 0;
    private int $clientId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db  = $c->get(Connection::class);
            $this->crm = $c->get(CrmAggregationService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->pdo = $this->db->pdo();

        $this->supplierId = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->czkId      = (int) ($this->pdo->query(
            "SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND is_active = 1
              ORDER BY (code = 'CZK') DESC, is_default DESC, id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->czId === 0 || $this->czkId === 0) {
            $this->markTestSkipped('Chybí supplier/user/country/měna.');
        }
        try {
            $this->pdo->query('SELECT proforma_payment_document FROM supplier LIMIT 1');
        } catch (\PDOException) {
            $this->markTestSkipped('Migrace 1565/1566 zatím neproběhly.');
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;
        $this->pdo->prepare('DELETE FROM crm_action_item_dismissals WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $this->clientId = $this->client();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->db->close();
        }
    }

    /** BEZ POLOŽKY PADÁ: uhrazená záloha by v ručním režimu zmizela beze stopy. */
    public function testManualModeSurfacesPaidProformaWithoutDocument(): void
    {
        $this->setMode(ProformaPaymentDocuments::MODE_MANUAL);
        self::assertNull($this->item(), 'Baseline musí být čistá, jinak test neměří nic.');

        $this->paidProforma(12);

        $item = $this->item();
        self::assertNotNull($item, 'Uhrazená záloha bez dokladu musí být v úkolech.');
        self::assertSame(1, $item['count']);
        self::assertStringContainsString('15', (string) $item['hint'], 'Nápověda má připomenout lhůtu.');
    }

    /** V automatických režimech koncept vzniká sám a je vidět — položka by duplikovala. */
    public function testAutomaticModesDoNotSurfaceTheItem(): void
    {
        $this->setMode(ProformaPaymentDocuments::MODE_FINAL_ON_FULL_PAYMENT);
        $this->paidProforma(12);

        self::assertNull($this->item());
    }

    /** Vystavený doklad k záloze úkol uzavírá. */
    public function testIssuedDocumentClearsTheItem(): void
    {
        $this->setMode(ProformaPaymentDocuments::MODE_MANUAL);
        $proformaId = $this->paidProforma(3);
        self::assertNotNull($this->item());

        $this->pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, parent_invoice_id, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, paid_total, created_by)
             VALUES ('tax_document', ?, ?, ?, CURDATE(), CURDATE(), CURDATE(), ?, 'issued', 100, 121, 0, ?)"
        )->execute([$proformaId, $this->clientId, $this->supplierId, $this->czkId, $this->userId]);

        self::assertNull($this->item(), 'Po vystavení dokladu už není co připomínat.');
    }

    /** Naléhavost roste se stářím platby, ne s počtem — jedna zapomenutá je horší než pět čerstvých. */
    public function testSeverityFollowsAgeOfOldestPayment(): void
    {
        $this->setMode(ProformaPaymentDocuments::MODE_MANUAL);

        $this->paidProforma(2);
        self::assertSame('low', $this->item()['severity'] ?? null);

        $this->paidProforma(20);
        self::assertSame('high', $this->item()['severity'] ?? null, 'Po zákonné lhůtě musí úkol zčervenat.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function item(): ?array
    {
        $result = $this->crm->actionItems($this->supplierId, $this->userId);
        foreach ((array) ($result['items'] ?? $result) as $item) {
            if (is_array($item) && ($item['type'] ?? null) === self::ITEM_TYPE) {
                return $item;
            }
        }

        return null;
    }

    private function setMode(string $mode): void
    {
        $this->pdo->prepare('UPDATE supplier SET proforma_payment_document = ? WHERE id = ?')
            ->execute([$mode, $this->supplierId]);
    }

    /** Zálohová faktura s přijatou platbou starou `$daysAgo` dnů. */
    private function paidProforma(int $daysAgo): int
    {
        $paidOn = (new \DateTimeImmutable('today'))->modify("-{$daysAgo} days")->format('Y-m-d');
        $this->pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, paid_total, created_by)
             VALUES ('proforma', ?, ?, ?, ?, ?, ?, ?, 'issued', 70000, 84700, 84700, ?)"
        )->execute([
            '9' . random_int(1000000, 9999999),
            $this->clientId, $this->supplierId, $paidOn, $paidOn, $paidOn, $this->czkId, $this->userId,
        ]);
        $id = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO invoice_payments
                (supplier_id, invoice_id, amount, currency, paid_on, source, created_by)
             VALUES (?, ?, 84700, "CZK", ?, "manual", ?)'
        )->execute([$this->supplierId, $id, $paidOn, $this->userId]);

        return $id;
    }

    private function client(): int
    {
        $this->pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "__proforma_awaiting_test__", "Testovaci 1", "Praha", "11000", ?,
                     "awaiting@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $this->czId, $this->czkId]);

        return (int) $this->pdo->lastInsertId();
    }
}

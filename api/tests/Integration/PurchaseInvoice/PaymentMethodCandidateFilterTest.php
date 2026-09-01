<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Forma úhrady na přijaté faktuře (migrace 1128) a její dopad na kandidáty
 * platebního příkazu.
 *
 * Proč to vzniklo: stránka platebních příkazů nabízela VŠECHNY nezaplacené přijaté
 * faktury včetně těch inkasních. U inkasa (SIPO / direct debit) si peníze strhne
 * dodavatel sám — vystavit na ně příkaz znamená zaplatit DVAKRÁT. Z platebních údajů
 * se to poznat nedá: inkasní doklad nese číslo účtu, VS i KS stejně jako převodní.
 *
 * Ověřujeme:
 *   - default filtr pustí do kandidátů JEN `payment_method = 'bank_transfer'`
 *   - `includeNonTransfer = true` (opt-out) inkasní fakturu zase ukáže — chybně
 *     označená faktura nesmí zmizet beze stopy
 *   - COUNT (paginace) používá STEJNÝ filtr jako list, jinak by se rozešlo stránkování
 *   - předvolba dodavatele (`clients.default_payment_method`) se otiskne se
 *     source='vendor', explicitní hodnota v payloadu vyhraje
 *   - priorita zdrojů: 'ai' NEPŘEPÍŠE 'manual', ale 'manual' přepíše 'ai'
 *
 * Izolováno pod existujícím supplierem, vše uklizeno v tearDown.
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class PaymentMethodCandidateFilterTest extends TestCase
{
    private Connection $db;
    private PurchaseInvoiceRepository $repo;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;

    /** @var int[] */
    private array $vendorIds = [];
    /** @var int[] */
    private array $piIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container  = Bootstrap::buildApp()->getContainer();
            $this->db   = $container->get(Connection::class);
            $this->repo = $container->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $has = $pdo->query("SHOW COLUMNS FROM purchase_invoices LIKE 'payment_method'")->fetch();
        if ($has === false) {
            $this->markTestSkipped('Migrace 1128 (payment_method) není aplikovaná.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->piIds as $id) {
            $pdo->prepare("DELETE FROM invoice_settlements WHERE doc_type = 'purchase_invoice' AND doc_id = ?")
                ->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->vendorIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testDirectDebitInvoiceIsNotOfferedAsPaymentCandidate(): void
    {
        $vendor = $this->vendor('Inkasni dodavatel', 'CZ21000001', null);

        $transferId = $this->candidateInvoice($vendor, 'PMCF-T1', 'bank_transfer');
        $debitId    = $this->candidateInvoice($vendor, 'PMCF-D1', 'direct_debit');

        $ids = $this->candidateIds(false);

        self::assertContains($transferId, $ids,
            'faktura hrazená převodem musí zůstat mezi kandidáty platebního příkazu');
        self::assertNotContains($debitId, $ids,
            'inkasní fakturu nesmí příkaz nabídnout — dodavatel si částku strhne sám (dvojí platba)');
    }

    /**
     * DDKP (daňový doklad k poskytnuté záloze, § 28 ZDPH) NENÍ platební cíl —
     * peníze odešly už na zálohové faktuře, doklad nese jen odpočet DPH (343/314)
     * a závazek na 321 nikdy nezaložil.
     *
     * Jeho `amount_to_pay` je ale generated sloupec `total_with_vat − advance_paid_amount`,
     * takže nese PLNÉ BRUTTO už zaplacené zálohy. Bez filtru by ve stavu received/booked
     * spadl do příkazu k úhradě a dodavateli by odešla DRUHÁ platba.
     *
     * Filtr je NEPODMÍNĚNÝ — na rozdíl od payment_method ho `includeNonTransfer`
     * nesmí vypnout, protože u DDKP neexistuje legitimní důvod platit.
     */
    public function testAdvanceVatDocumentIsNeverAPaymentCandidate(): void
    {
        $vendor = $this->vendor('Dodavatel DDKP', 'CZ21000009', null);

        $plainId = $this->candidateInvoice($vendor, 'PMCF-OK', 'bank_transfer');
        $ddkpId  = $this->candidateInvoice($vendor, 'PMCF-DDKP', 'bank_transfer');
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind = 'tax_document' WHERE id = ?")
            ->execute([$ddkpId]);

        self::assertContains($plainId, $this->candidateIds(false), 'Běžná PF kandidátem zůstává.');
        self::assertNotContains($ddkpId, $this->candidateIds(false),
            'DDKP nesmí příkaz nabídnout — peníze odešly už na zálohové faktuře (dvojí platba).');
        self::assertNotContains($ddkpId, $this->candidateIds(true),
            'Ani opt-out includeNonTransfer nesmí DDKP odemknout — není to platební cíl.');
    }

    public function testIncludeNonTransferOptOutShowsDirectDebitAgain(): void
    {
        $vendor  = $this->vendor('Inkasni dodavatel 2', 'CZ21000002', null);
        $debitId = $this->candidateInvoice($vendor, 'PMCF-D2', 'direct_debit');

        self::assertNotContains($debitId, $this->candidateIds(false),
            'default filtr inkasní fakturu skrývá');
        self::assertContains($debitId, $this->candidateIds(true),
            'opt-out musí inkasní fakturu ukázat — chybně označená faktura nesmí zmizet beze stopy');
    }

    public function testCountUsesSameFilterAsList(): void
    {
        $vendor = $this->vendor('Dodavatel pro count', 'CZ21000003', null);
        $this->candidateInvoice($vendor, 'PMCF-T3', 'bank_transfer');
        $this->candidateInvoice($vendor, 'PMCF-D3', 'direct_debit');
        $this->candidateInvoice($vendor, 'PMCF-C3', 'cash_on_delivery');

        // COUNT musí sedět na velikost neomezeného listu — jinak by se rozešlo stránkování
        // (uživatel by viděl „3 kandidáti" a v tabulce jen jednoho).
        self::assertSame(
            count($this->repo->listPaymentCandidates($this->supplierId, null, null, 0, false)),
            $this->repo->countPaymentCandidates($this->supplierId, null, false),
            'countPaymentCandidates musí použít stejný WHERE jako listPaymentCandidates (default)',
        );
        self::assertSame(
            count($this->repo->listPaymentCandidates($this->supplierId, null, null, 0, true)),
            $this->repo->countPaymentCandidates($this->supplierId, null, true),
            'countPaymentCandidates musí použít stejný WHERE jako listPaymentCandidates (opt-out)',
        );
    }

    public function testPaymentCandidatesUseRemainingAmountAfterAccountSettlement(): void
    {
        $vendor = $this->vendor('Dodavatel se zápočtem', 'CZ21000010', null);
        $partialId = $this->candidateInvoice($vendor, 'PMCF-S1', 'bank_transfer');
        $settledId = $this->candidateInvoice($vendor, 'PMCF-S2', 'bank_transfer');

        $this->settle($partialId, 210.0);
        $this->settle($settledId, 1210.0);

        $rows = $this->repo->listPaymentCandidates($this->supplierId);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        self::assertArrayHasKey($partialId, $byId);
        self::assertSame(1000.0, $byId[$partialId]['amount_to_pay']);
        self::assertArrayNotHasKey($settledId, $byId,
            'Plně vyrovnaný doklad nesmí platební příkaz nabídnout podruhé.');
    }

    public function testVendorDefaultPaymentMethodIsAppliedWithVendorSource(): void
    {
        $vendor = $this->vendor('Dodavatel s inkasem', 'CZ21000004', 'direct_debit');

        $id = $this->repo->createDraft($this->payload($vendor, 'PMCF-V1'), $this->userId, $this->supplierId);
        $this->piIds[] = $id;

        self::assertSame(['direct_debit', 'vendor'], $this->storedMethod($id),
            'předvolba dodavatele se má otisknout jako payment_method + source=vendor');
    }

    public function testExplicitPaymentMethodWinsOverVendorDefault(): void
    {
        $vendor = $this->vendor('Dodavatel s inkasem 2', 'CZ21000005', 'direct_debit');

        $payload = $this->payload($vendor, 'PMCF-V2');
        $payload['payment_method'] = 'bank_transfer';
        $payload['payment_method_source'] = 'manual';

        $id = $this->repo->createDraft($payload, $this->userId, $this->supplierId);
        $this->piIds[] = $id;

        self::assertSame(['bank_transfer', 'manual'], $this->storedMethod($id),
            'explicitní volba v payloadu má přebít předvolbu dodavatele');
    }

    public function testVendorWithoutDefaultStaysBankTransfer(): void
    {
        $vendor = $this->vendor('Dodavatel bez predvolby', 'CZ21000006', null);

        $id = $this->repo->createDraft($this->payload($vendor, 'PMCF-V3'), $this->userId, $this->supplierId);
        $this->piIds[] = $id;

        self::assertSame(['bank_transfer', 'default'], $this->storedMethod($id),
            'dodavatel bez předvolby → doklad si drží bankovní převod se source=default');
    }

    public function testAiCannotOverrideManualButManualCanOverrideAi(): void
    {
        $vendor = $this->vendor('Dodavatel priorita', 'CZ21000007', null);

        $payload = $this->payload($vendor, 'PMCF-P1');
        $payload['payment_method'] = 'bank_transfer';
        $payload['payment_method_source'] = 'manual';
        $id = $this->repo->createDraft($payload, $this->userId, $this->supplierId);
        $this->piIds[] = $id;

        // AI si myslí, že je to inkaso — ale účetní už rozhodla ručně, takže smůla.
        $written = $this->repo->setPaymentMethod($id, $this->supplierId, 'direct_debit', 'ai');
        self::assertFalse($written, 'setPaymentMethod se zdrojem ai nesmí přepsat ruční volbu');
        self::assertSame(['bank_transfer', 'manual'], $this->storedMethod($id),
            'AI nesmí přebít manual — účetní má poslední slovo');

        // Opačným směrem to jít musí: účetní opraví to, co nastavila AI.
        $written = $this->repo->setPaymentMethod($id, $this->supplierId, 'direct_debit', 'manual');
        self::assertTrue($written, 'manual musí smět přepsat i existující manual (oprava)');
        self::assertSame(['direct_debit', 'manual'], $this->storedMethod($id));
    }

    public function testUnknownPaymentMethodFallsBackToBankTransfer(): void
    {
        $vendor = $this->vendor('Dodavatel nesmysl', 'CZ21000008', null);

        $payload = $this->payload($vendor, 'PMCF-X1');
        $payload['payment_method'] = 'bitcoin';

        $id = $this->repo->createDraft($payload, $this->userId, $this->supplierId);
        $this->piIds[] = $id;

        self::assertSame(['bank_transfer', 'default'], $this->storedMethod($id),
            'neznámá hodnota musí spadnout na bank_transfer, ne rozbít INSERT na ENUMu');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return list<int> */
    private function candidateIds(bool $includeNonTransfer): array
    {
        $rows = $this->repo->listPaymentCandidates($this->supplierId, null, null, 0, $includeNonTransfer);
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /** @return array{0:string,1:string} [payment_method, payment_method_source] */
    private function storedMethod(int $piId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT payment_method, payment_method_source FROM purchase_invoices WHERE id = ?'
        );
        $stmt->execute([$piId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [(string) ($row['payment_method'] ?? ''), (string) ($row['payment_method_source'] ?? '')];
    }

    /**
     * Faktura ve stavu, který ji dělá kandidátem příkazu: status 'received',
     * amount_to_pay > 0 a vyplněný účet příjemce.
     */
    private function candidateInvoice(int $vendorId, string $number, string $method): int
    {
        $payload = $this->payload($vendorId, $number);
        $payload['payment_method'] = $method;
        $payload['payment_method_source'] = 'manual';
        $payload['payment'] = [
            'account_number' => '19-2000145399',
            'bank_code'      => '0800',
            'source'         => 'manual',
        ];

        $id = $this->repo->createDraft($payload, $this->userId, $this->supplierId);
        $this->piIds[] = $id;

        // Kandidát musí být 'received'/'booked' a mít co platit — obojí nastavíme přímo,
        // ať test nezávisí na kalkulátoru ani na status transition pipeline.
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices
                SET status = 'received', total_with_vat = 1210
              WHERE id = ?"
        )->execute([$id]);

        return $id;
    }

    private function settle(int $purchaseId, float $amount): void
    {
        $accountId = (int) ($this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts
              WHERE supplier_id = {$this->supplierId} AND account_code LIKE '365%'
              ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        self::assertGreaterThan(0, $accountId, 'Test vyžaduje účet 365.');
        $this->db->pdo()->prepare(
            "INSERT INTO invoice_settlements
                (supplier_id, doc_type, doc_id, settled_on, amount, account_id, status, created_by)
             VALUES (?, 'purchase_invoice', ?, CURDATE(), ?, ?, 'confirmed', ?)"
        )->execute([$this->supplierId, $purchaseId, $amount, $accountId, $this->userId]);
    }

    private function vendor(string $name, string $dic, ?string $defaultPaymentMethod): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor,
                                  default_payment_method)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "v@example.com", "cs", ?, 0, 1, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $dic, $this->currencyId, $defaultPaymentMethod]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->vendorIds[] = $id;
        return $id;
    }

    /** Minimální validní payload pro createDraft (povinné: vendor_invoice_number, datumy, currency). */
    private function payload(int $vendorId, string $number): array
    {
        return [
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $number,
            'document_kind'         => 'invoice',
            'issue_date'            => '2099-06-10',
            'tax_date'              => '2099-06-10',
            'due_date'              => '2099-06-24',
            'currency_id'           => $this->currencyId,
        ];
    }
}

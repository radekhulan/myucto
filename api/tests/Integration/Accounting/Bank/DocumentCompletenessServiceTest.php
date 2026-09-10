<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Repository\SaldoRepository;
use MyInvoice\Service\Accounting\Reports\DocumentCompletenessService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Featura E (REAL_data_followup_UX.md) — kontrola úplnosti dokladů proti bance.
 * Ověřuje predikát „bankovní pohyb bez dokladu po prahu X dní": aging, práh, směr
 * a vyloučení pohybů, které doklad mají / nemají chybět (matched, uhrazené,
 * zaúčtované, ignorované, mladší než práh).
 */
#[Group('integration')]
final class DocumentCompletenessServiceTest extends BankPostingTestCase
{
    private DocumentCompletenessService $completeness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->completeness = $this->container->get(DocumentCompletenessService::class);
    }

    public function testBankWithoutDocumentRespectsThresholdDirectionAndLinkage(): void
    {
        $old = (new \DateTimeImmutable('-100 days'))->format('Y-m-d');
        $recent = (new \DateTimeImmutable('-5 days'))->format('Y-m-d');
        $stId = $this->statement();

        // 1) Starý výdaj bez dokladu → patří do reportu.
        $txOldOut = $this->transaction($stId, -1234.0, ['posted_at' => $old, 'counterparty_name' => 'Bez dokladu s.r.o.']);
        // 2) Starý příjem bez dokladu → patří (jen ve směru all/incoming).
        $txOldIn = $this->transaction($stId, 555.0, ['posted_at' => $old, 'counterparty_name' => 'Prijem bez dokladu']);
        // 3) Čerstvý pohyb pod prahem → NEPATŘÍ.
        $txRecent = $this->transaction($stId, -999.0, ['posted_at' => $recent, 'counterparty_name' => 'Cerstvy']);
        // 4) Spárovaný pohyb (má doklad) → NEPATŘÍ.
        $txMatched = $this->transaction($stId, -50.0, ['posted_at' => $old, 'match_status' => 'manual', 'counterparty_name' => 'Sparovany']);
        // 5) Pohyb s evidovanou úhradou faktury → NEPATŘÍ.
        $customer = $this->client('Klient E');
        $invId = $this->saleInvoice('2099901', $customer, 70.0);
        $txPaid = $this->transaction($stId, -70.0, ['posted_at' => $old, 'counterparty_name' => 'Uhrazeny']);
        $this->invoicePayment($invId, $txPaid, 70.0);
        // 6) Zaúčtovaný pohyb (živý bankovní zápis) → NEPATŘÍ.
        $txPosted = $this->transaction($stId, -80.0, ['posted_at' => $old, 'counterparty_name' => 'Zauctovany']);
        $this->postPredpis('bank', $txPosted, '518', '221', 80.0);
        // 7) Ignorovaný pohyb → NEPATŘÍ.
        $txIgnored = $this->transaction($stId, -20.0, ['posted_at' => $old, 'match_status' => 'ignored', 'counterparty_name' => 'Ignorovany']);

        $result = $this->completeness->build($this->supplierId, 30, 'all');
        $ids = array_column($result['bank_without_document']['items'], 'bank_transaction_id');

        self::assertContains($txOldOut, $ids);
        self::assertContains($txOldIn, $ids);
        self::assertNotContains($txRecent, $ids);
        self::assertNotContains($txMatched, $ids);
        self::assertNotContains($txPaid, $ids);
        self::assertNotContains($txPosted, $ids);
        self::assertNotContains($txIgnored, $ids);

        $byId = [];
        foreach ($result['bank_without_document']['items'] as $it) {
            $byId[$it['bank_transaction_id']] = $it;
        }
        self::assertSame('outgoing', $byId[$txOldOut]['direction']);
        self::assertSame('incoming', $byId[$txOldIn]['direction']);
        self::assertSame('d91_180', $byId[$txOldOut]['bucket']); // 100 dní
        self::assertSame(100, $byId[$txOldOut]['days']);

        // Směr „jen výdaje" vynechá příjem.
        $outgoing = $this->completeness->build($this->supplierId, 30, 'outgoing');
        $outIds = array_column($outgoing['bank_without_document']['items'], 'bank_transaction_id');
        self::assertContains($txOldOut, $outIds);
        self::assertNotContains($txOldIn, $outIds);

        // Vyšší práh vyřadí i staré pohyby (200 dní).
        $highThreshold = $this->completeness->build($this->supplierId, 200, 'all');
        $highIds = array_column($highThreshold['bank_without_document']['items'], 'bank_transaction_id');
        self::assertNotContains($txOldOut, $highIds);

        // Obrácený směr (doklady po splatnosti) je vždy přítomný jako sekce.
        self::assertArrayHasKey('items', $result['documents_overdue_unpaid']);
        self::assertArrayHasKey('summary', $result['documents_overdue_unpaid']);
    }

    public function testFutureDocumentsCannotHideAnOverdueDocumentBehindTheGlobalCap(): void
    {
        $futureClient = $this->client('Alfa budoucí s.r.o.');
        $overdueClient = $this->client('Zeta po splatnosti s.r.o.');
        $oldestVendor = $this->client('Dodavatel s nejstarším závazkem s.r.o.');
        $future = $this->saleInvoice('2099902', $futureClient, 100.00);
        $overdue = $this->saleInvoice('2099903', $overdueClient, 200.00);
        $oldest = $this->purchaseInvoice('PF-2099-901', $oldestVendor, 300.00);
        $this->db->pdo()->prepare('UPDATE invoices SET due_date = ? WHERE id = ? AND supplier_id = ?')
            ->execute([self::YEAR . '-08-01', $future, $this->supplierId]);
        $this->db->pdo()->prepare('UPDATE invoices SET due_date = ? WHERE id = ? AND supplier_id = ?')
            ->execute([self::YEAR . '-06-20', $overdue, $this->supplierId]);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET due_date = ? WHERE id = ? AND supplier_id = ?')
            ->execute([self::YEAR . '-06-10', $oldest, $this->supplierId]);
        $this->postPredpis('invoice', $future, '311', '602', 100.00);
        $this->postPredpis('invoice', $overdue, '311', '602', 200.00);
        $this->postPredpis('purchase_invoice', $oldest, '518', '321', 300.00);

        $limited = new DocumentCompletenessService(
            $this->db,
            $this->container->get(SaldoRepository::class),
            1,
        );
        $result = $limited->build(
            $this->supplierId,
            30,
            'all',
            new \DateTimeImmutable(self::YEAR . '-07-01'),
        );

        self::assertSame(
            [$oldest],
            array_column($result['documents_overdue_unpaid']['items'], 'doc_id'),
            'Limit se musí použít až na SQL filtrovaný a globálně chronologický seznam po splatnosti.',
        );
        self::assertSame('321', $result['documents_overdue_unpaid']['items'][0]['account_code']);
        self::assertTrue($result['documents_overdue_unpaid']['summary']['truncated']);
    }
}

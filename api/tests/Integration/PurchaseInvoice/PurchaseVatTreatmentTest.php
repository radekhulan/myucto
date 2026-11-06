<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Tests\Support\PurchaseVatFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Skutečný nárok na odpočet přijaté faktury (rozpis DPH má přednost před hlavičkou)
 * a změna nároku v hlavičce u dokladu s rozpisem. Data jsou syntetická.
 */
#[Group('integration')]
final class PurchaseVatTreatmentTest extends TestCase
{
    use PurchaseVatFixtureTrait;

    protected function setUp(): void
    {
        $this->setUpPurchaseVatFixture();
    }

    protected function tearDown(): void
    {
        $this->tearDownPurchaseVatFixture();
    }

    /**
     * Issue #246: neplátce nemá nárok na odpočet. Doklad založený s výchozím plným nárokem
     * (podle dodavatele-plátce) se uloží bez nároku podle plátcovství firmy k datu plnění.
     */
    public function testNonPayerPurchaseIsStoredWithoutDeduction(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, is_identified) VALUES (?, ?, 0, 0)'
        )->execute([$this->supplierId, '2000-01-01']);

        $id = $this->createPurchase(['vat_deduction' => 'full']);

        self::assertSame('none', $this->repo->find($id, $this->supplierId)['vat_deduction']);
        self::assertSame([], $this->ledgerList($id), 'Neplátce nemá doklad v evidenci odpočtu.');
    }

    /** Hlavička „bez nároku", rozpis s plným nárokem: odpočet se uplatní a detail to musí říct. */
    public function testEffectiveDeductionFollowsAllocations(): void
    {
        $id = $this->createPurchase([
            'vat_deduction' => 'none',
            'vat_allocations' => [$this->allocation('Celý doklad', 1000.0, 210.0, 'full')],
        ]);

        $treatment = $this->repo->find($id, $this->supplierId)['vat_treatment'] ?? null;
        self::assertIsArray($treatment, 'Detail musí dostat skutečný nárok, ne jen hlavičku.');
        self::assertSame('allocations', $treatment['source']);
        self::assertSame('full', $treatment['effective']);
        self::assertContains('header_differs_from_allocations', $treatment['warnings']);
        self::assertCount(1, $this->ledgerList($id), 'Evidence uplatní odpočet podle rozpisu.');
    }

    /** Hlavička „bez nároku" a položka s kódem 41: do DPH nejde, detail to pojmenuje. */
    public function testClaimCodeUnderNoDeductionHeaderIsFlagged(): void
    {
        $id = $this->createPurchase(['vat_deduction' => 'none'], [$this->item('Kniha', 100.0, $this->rate12, '41')]);

        $treatment = $this->repo->find($id, $this->supplierId)['vat_treatment'] ?? null;
        self::assertIsArray($treatment);
        self::assertSame('header', $treatment['source']);
        self::assertSame('none', $treatment['effective']);
        self::assertSame('no_claim_header', $treatment['items'][0]['state']);
        self::assertSame('41', $treatment['items'][0]['effective_code']);
        self::assertContains('items_claim_code_without_header_claim', $treatment['warnings']);
        self::assertSame([], $this->ledgerList($id));
    }

    /** Celý rozpis měl nárok hlavičky → změna hlavičky se do něj promítne. */
    public function testHeaderChangePropagatesToUniformAllocations(): void
    {
        $id = $this->createPurchase([
            'vat_deduction' => 'full',
            'vat_allocations' => [
                $this->allocation('Část A', 600.0, 126.0, 'full'),
                $this->allocation('Část B', 400.0, 84.0, 'full'),
            ],
        ]);
        self::assertCount(2, $this->ledgerList($id));

        $res = $this->update($id, $this->editorBody($id, ['vat_deduction' => 'none', 'vat_deduction_percent' => 0]));

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(['none', 'none'], array_column($this->repo->find($id, $this->supplierId)['vat_allocations'], 'vat_deduction'));
        self::assertSame([], $this->ledgerList($id), 'Bez nároku podle hlavičky i rozpisu → mimo evidenci DPH.');
    }

    /** Rozpis se smíšeným nárokem: změnu hlavičky nejde jednoznačně promítnout → 422, nic se nemění. */
    public function testHeaderChangeWithMixedAllocationsIsRejected(): void
    {
        $id = $this->createPurchase([
            'vat_deduction' => 'full',
            'vat_allocations' => [
                $this->allocation('Firemní', 500.0, 105.0, 'full'),
                $this->allocation('Reprezentace', 500.0, 105.0, 'none', 0.0),
            ],
        ]);
        $before = $this->ledgerRows($id);

        $res = $this->update($id, $this->editorBody($id, ['vat_deduction' => 'none', 'vat_deduction_percent' => 0]));

        self::assertSame(422, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('vat_allocations_deduction_conflict', $res['body']['error']['code'] ?? null);
        self::assertStringContainsString('rozpisu DPH', (string) ($res['body']['error']['message'] ?? ''));
        self::assertSame('full', $this->repo->find($id, $this->supplierId)['vat_deduction']);
        self::assertSame($before, $this->ledgerRows($id));
    }

    /** Hlavička srovnaná s rozpisem (oprava případu „bez nároku" u plného rozpisu) projde bez zásahu do rozpisu. */
    public function testHeaderAlignedWithAllocationsIsAccepted(): void
    {
        $id = $this->createPurchase([
            'vat_deduction' => 'none',
            'vat_allocations' => [$this->allocation('Celý doklad', 1000.0, 210.0, 'full')],
        ]);
        $before = $this->ledgerRows($id);

        $res = $this->update($id, $this->editorBody($id, ['vat_deduction' => 'full', 'vat_deduction_percent' => 100]));

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame([], $res['body']['vat_treatment']['warnings']);
        self::assertSame($before, $this->ledgerRows($id), 'Evidence se nemění, rozpis rozhodoval už předtím.');
    }

    /**
     * Existující doklady se samy nemění: uložení, které na DPH nesahá, nechá evidenci
     * i přiznání za období bajtově stejné (rozpis vs. hlavička, kód 41 pod „bez nároku",
     * hlavička 42 s položkami 40).
     */
    public function testUnrelatedResaveKeepsVatReportsByteIdentical(): void
    {
        $a = $this->createPurchase([
            'vat_deduction' => 'none',
            'vat_allocations' => [$this->allocation('Celý doklad', 1000.0, 210.0, 'full')],
        ]);
        $b = $this->createPurchase(['vat_deduction' => 'none'], [$this->item('Kniha', 100.0, $this->rate12, '41')]);
        $c = $this->createPurchase([], [$this->item('Oběd', 300.0), $this->item('Káva', 50.0), $this->item('Bez kódu', 20.0)]);
        // Stav z doby před opravou: hlavička 42, položky dostaly 40 nebo zůstaly bez kódu.
        // Položka bez kódu jde do evidence s kódem hlavičky (COALESCE); uložení jí dřív
        // dosadilo 40 a tiše uplatnilo odpočet.
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE purchase_invoices SET vat_classification_code = '42' WHERE id = ?")->execute([$c]);
        $pdo->prepare("UPDATE purchase_invoice_items SET vat_classification_code = NULL WHERE purchase_invoice_id = ? AND description = 'Bez kódu'")
            ->execute([$c]);
        // Přijaté doklady (koncept do přiznání nevstupuje).
        $pdo->prepare("UPDATE purchase_invoices SET status = 'received' WHERE id IN (?, ?, ?)")->execute([$a, $b, $c]);

        $snapshot = function () use ($a, $b, $c): string {
            $summary = null;
            try {
                $summary = $this->container->get(DphPriznaniBuilder::class)->build($this->supplierId, 2096, 4, 'monthly')['summary'] ?? null;
                // Čas poslední úpravy dokladu se uložením mění vždy; obsah výkazu ne.
                foreach ((array) ($summary['document_refs'] ?? []) as $i => $ref) {
                    unset($summary['document_refs'][$i]['updated_at']);
                }
            } catch (\Throwable $e) {
                $summary = 'build_failed:' . $e->getMessage();
            }
            return $this->ledgerRows($a) . $this->ledgerRows($b) . $this->ledgerRows($c)
                . json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        };
        $before = $snapshot();
        self::assertStringNotContainsString('"lines":[]', $before, 'Přiznání za období musí doklady obsahovat, jinak test nic nehlídá.');
        self::assertStringNotContainsString('build_failed', $before);

        foreach ([$a, $b, $c] as $id) {
            $res = $this->update($id, $this->editorBody($id, ['note_below_items' => 'Jen poznámka']), ['force' => '1']);
            self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        }

        self::assertSame($before, $snapshot());
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function update(int $id, array $body, array $query = []): array
    {
        return $this->call($this->container->get(UpdatePurchaseInvoiceAction::class), 'PUT', $body, ['id' => (string) $id], 'admin', $query);
    }
}

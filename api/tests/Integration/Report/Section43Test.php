<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Vat\Section43Service;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * § 43 ZDPH — oprava výše daně v jiných případech (per doklad).
 *
 * Matice DPH to vedla jako CHYBÍ: systém uměl dodatečné přiznání jako CELEK, ale neměl
 * institut opravy per doklad ani vazbu na období původního plnění. Účetní musela rozdíl
 * dopočítat ručně mimo systém a nikde nezůstala stopa, ČEHO se oprava týkala — přesně to,
 * co správce daně při kontrole chce vidět.
 *
 * ── Co testy zamykají ───────────────────────────────────────────────────────
 * Jádro § 43 je SMĚR V ČASE, a ten se plete s § 42: § 42 opravuje ZÁKLAD daně a jde do
 * období DORUČENÍ opravného dokladu (dopředu), § 43 opravuje VÝŠI daně a jde ZPĚTNĚ do
 * období PŮVODNÍHO plnění. Kdyby se to prohodilo, oprava spadne do jiného přiznání
 * a rozdíl se objeví ve špatném období — {@see testCorrectionLandsInOriginalPeriodNotDeliveryPeriod()}.
 *
 * Dál prekluze (§ 43 odst. 3 → § 148 DŘ), která běží od KONCE zdaňovacího období, ne od
 * data dokladu, a sazbová skupina podle § 43 odst. 2 (sazba PŮVODNÍHO plnění, ne dnešní).
 */
#[Group('integration')]
final class Section43Test extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private Section43Service $service;
    private \MyInvoice\Service\Report\DphPriznaniBuilder $builder;
    private \MyInvoice\Service\Report\TaxSubmissionArchiver $archiver;
    private int $supplierId = 0;
    private array $invoiceIds = [];
    private int $purchaseInvoiceId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->service = $c->get(Section43Service::class);
            // Builder MUSÍ pocházet z TÉHOŽ kontejneru — jinak dostane vlastní připojení
            // mimo transakci testu a izolovaného dodavatele vůbec neuvidí.
            $this->builder = $c->get(\MyInvoice\Service\Report\DphPriznaniBuilder::class);
            $this->archiver = $c->get(\MyInvoice\Service\Report\TaxSubmissionArchiver::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'vat_s43_corrections'")->fetch() === false) {
            $this->markTestSkipped('Migrace 1164 neproběhla.');
        }
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 1 WHERE id = ?')->execute([$this->supplierId]);
        [$this->invoiceIds, $this->purchaseInvoiceId] = $this->sourceDocuments($this->supplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    private function sourceDocuments(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en)
             VALUES (?, 'CZK', 'Syntetická měna', 'Kč', 'Koruna', 'Czech crown')"
        )->execute([$supplierId]);
        $currencyId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id, is_customer, is_vendor)
             VALUES (?, 'Syntetická protistrana §43', 'Testovací 1', 'Praha', '11000', ?, ?, 1, 1)"
        )->execute([$supplierId, $countryId, $currencyId]);
        $clientId = (int) $pdo->lastInsertId();
        $invoiceIds = [];
        for ($i = 0; $i < 3; $i++) {
            $pdo->prepare(
                "INSERT INTO invoices (supplier_id, client_id, invoice_type, issue_date, tax_date, due_date, currency_id, status, created_by)
                 VALUES (?, ?, 'invoice', '2025-03-01', '2025-03-01', '2025-03-15', ?, 'issued', ?)"
            )->execute([$supplierId, $clientId, $currencyId, $userId]);
            $invoiceIds[] = (int) $pdo->lastInsertId();
        }
        $pdo->prepare(
            "INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date, received_at, currency_id, vendor_snapshot, status, created_by)
             VALUES (?, ?, 'SYNTHETIC-S43-1', 'invoice', '2025-03-01', '2025-03-01', '2025-03-15', '2025-03-01', ?, '{}', 'received', ?)"
        )->execute([$supplierId, $clientId, $currencyId, $userId]);
        return [$invoiceIds, (int) $pdo->lastInsertId()];
    }

    public function testForeignSourcesAreRejectedForBothDocumentTypes(): void
    {
        $otherSupplier = $this->createIsolatedSupplier($this->db->pdo(), $this->supplierId);
        [$invoices, $purchaseInvoice] = $this->sourceDocuments($otherSupplier);
        foreach (['invoice' => $invoices[0], 'purchase_invoice' => $purchaseInvoice] as $type => $sourceId) {
            try {
                $this->service->register($this->supplierId, $type, $sourceId, 2025, 3, 'basic', 0, 21, '2025-04-10', 'Syntetická oprava');
                self::fail('Cizí zdroj opravy musí být odmítnut.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Zdroj opravy nenalezen.', $e->getMessage());
            }
        }
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM vat_s43_corrections WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testOwnPurchaseInvoiceSourceIsAccepted(): void
    {
        $id = $this->service->register($this->supplierId, 'purchase_invoice', $this->purchaseInvoiceId, 2025, 3, 'basic', 0, 21, '2025-04-10', 'Syntetická oprava');
        $stmt = $this->db->pdo()->prepare('SELECT source_id FROM vat_s43_corrections WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $this->supplierId]);
        self::assertSame($this->purchaseInvoiceId, (int) $stmt->fetchColumn());
    }

    /**
     * Oprava se objeví v období PŮVODNÍHO plnění, ne v období doručení opravného dokladu.
     * Tohle je rozdíl proti § 42 a jediná věc, kterou tu jde splést se skutečným dopadem.
     */
    public function testCorrectionLandsInOriginalPeriodNotDeliveryPeriod(): void
    {
        // Původní plnění 03/2025, opravný doklad doručen až 09/2025.
        $this->service->register(
            $this->supplierId, 'invoice', $this->invoiceIds[0], 2025, 3, 'basic',
            0.0, -2100.0, '2025-09-10', 'Použita 21 % místo 12 %',
        );

        $march = $this->service->periodCorrectionLines($this->supplierId, 2025, 3);
        $september = $this->service->periodCorrectionLines($this->supplierId, 2025, 9);

        self::assertEqualsWithDelta(-2100.0, $march['basic']['vat'], 0.01, 'Patří do období původního plnění.');
        self::assertEqualsWithDelta(0.0, $september['basic']['vat'], 0.01, 'Do období doručení NEpatří — to je § 42.');
    }

    /** Sazbová skupina rozhoduje o řádku: základní → ř. 1, snížená → ř. 2 (§ 43 odst. 2). */
    public function testRateKindRoutesToTheCorrectLine(): void
    {
        $this->service->register($this->supplierId, 'invoice', $this->invoiceIds[0], 2025, 3, 'basic', 1000.0, 210.0, '2025-04-10', 'Doúčtování');
        $this->service->register($this->supplierId, 'invoice', $this->invoiceIds[1], 2025, 3, 'reduced', 500.0, 60.0, '2025-04-10', 'Doúčtování');

        $lines = $this->service->periodCorrectionLines($this->supplierId, 2025, 3);

        self::assertEqualsWithDelta(210.0, $lines['basic']['vat'], 0.01);
        self::assertEqualsWithDelta(60.0, $lines['reduced']['vat'], 0.01);
    }

    /** Opravy téhož období se SČÍTAJÍ — za měsíc jich může být víc. */
    public function testMultipleCorrectionsInPeriodAreSummed(): void
    {
        $this->service->register($this->supplierId, 'invoice', $this->invoiceIds[0], 2025, 3, 'basic', 0.0, -500.0, '2025-04-10', 'A');
        $this->service->register($this->supplierId, 'invoice', $this->invoiceIds[1], 2025, 3, 'basic', 0.0, 300.0, '2025-04-10', 'B');

        self::assertEqualsWithDelta(
            -200.0,
            $this->service->periodCorrectionLines($this->supplierId, 2025, 3)['basic']['vat'],
            0.01,
        );
    }

    /** U čtvrtletního plátce se sečte celý kvartál. */
    public function testQuarterlyPeriodSumsWholeQuarter(): void
    {
        $this->service->register($this->supplierId, 'invoice', $this->invoiceIds[0], 2025, 1, 'basic', 0.0, -100.0, '2025-05-10', 'leden');
        $this->service->register($this->supplierId, 'invoice', $this->invoiceIds[1], 2025, 3, 'basic', 0.0, -200.0, '2025-05-10', 'březen');
        $this->service->register($this->supplierId, 'invoice', $this->invoiceIds[2], 2025, 4, 'basic', 0.0, -400.0, '2025-08-10', 'duben — jiný kvartál');

        $q1 = $this->service->periodCorrectionLines($this->supplierId, 2025, 2, 'quarterly');

        self::assertEqualsWithDelta(-300.0, $q1['basic']['vat'], 0.01, 'Jen Q1, duben tam nepatří.');
    }

    // ── prekluze § 43 odst. 3 ────────────────────────────────────────────────

    /**
     * Po uplynutí lhůty pro stanovení daně opravu zaevidovat NELZE. Bez téhle zábrany by
     * systém vyrobil dodatečné přiznání, které správce daně odmítne.
     */
    public function testCorrectionAfterAssessmentPeriodIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/lhůta pro stanovení daně/');

        // Plnění 2021, opravný doklad doručen 2025 → přes 3 roky od konce roku 2021.
        $this->service->register(
            $this->supplierId, 'invoice', $this->invoiceIds[0], 2021, 3, 'basic',
            0.0, -1000.0, '2025-06-01', 'Pozdě',
        );
    }

    /**
     * Lhůta běží ode dne, kdy uplynula lhůta pro podání ŘÁDNÉHO tvrzení za období
     * původního plnění — u DPH 25 dnů po jeho konci (§ 101 odst. 1 ZDPH), § 148 DŘ.
     *
     * Dřív se počítalo `rok + 3` k 31. 12., což je u lednového plnění o 310 dnů POZDĚ:
     * leden 2021 se podává 25. 2. 2021, prekluze tedy nastává 25. 2. 2024, ale systém
     * pouštěl opravu ještě 31. 12. 2024.
     */
    public function testDeadlineRunsFromFilingDeadlineOfTheOriginalPeriod(): void
    {
        self::assertFalse($this->service->isTimeBarred(2021, 1, '2024-02-25', 'monthly'), 'Poslední den lhůty je ještě včas.');
        self::assertTrue($this->service->isTimeBarred(2021, 1, '2024-02-26', 'monthly'), 'Den po lhůtě už je prekluze.');
        self::assertTrue($this->service->isTimeBarred(2021, 1, '2024-12-31', 'monthly'), 'Konec roku je o 310 dnů pozdě.');
    }

    /**
     * U ČTVRTLETNÍHO plátce běží lhůta později — jeho zdaňovací období končí až
     * čtvrtletím. Počítat u něj měsíčně by opravu zablokovalo dřív, než zákon velí.
     */
    public function testQuarterlyFilerHasLaterDeadlineThanMonthly(): void
    {
        // Plnění leden 2021: Q1 končí 31. 3., podání 25. 4. 2021 → prekluze 25. 4. 2024.
        self::assertFalse($this->service->isTimeBarred(2021, 1, '2024-04-25', 'quarterly'));
        self::assertTrue($this->service->isTimeBarred(2021, 1, '2024-04-26', 'quarterly'));
        // Měsíčnímu plátci lhůta k témuž dni už uplynula.
        self::assertTrue($this->service->isTimeBarred(2021, 1, '2024-04-25', 'monthly'));
    }

    // ── validace ─────────────────────────────────────────────────────────────

    /** Nulová oprava není oprava — vznikla by prázdná položka budící dojem zásahu. */
    public function testZeroCorrectionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nesmí být nulová/');

        $this->service->register($this->supplierId, 'invoice', $this->invoiceIds[0], 2025, 3, 'basic', 0.0, 0.0, '2025-04-10', 'Nic');
    }

    /** Bez důvodu se oprava neuloží — při kontrole se neobhájí. */
    public function testReasonIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/[Dd]ůvod/');

        $this->service->register($this->supplierId, 'invoice', $this->invoiceIds[0], 2025, 3, 'basic', 0.0, -100.0, '2025-04-10', '  ');
    }

    // ── promítnutí do přiznání ───────────────────────────────────────────────

    /**
     * Oprava se promítne do ř. 1 přiznání za období původního plnění a XML projde XSD.
     *
     * Validace proti schématu není formalita — u `uprav_odp` se v tomhle projektu ukázalo,
     * že atribut patřil na jinou Vetu, než se čekalo, a bez XSD by to prošlo.
     */
    public function testCorrectionAppearsInReturnForOriginalPeriod(): void
    {
        $this->service->register(
            $this->supplierId, 'invoice', $this->invoiceIds[0], 2026, 3, 'basic',
            10000.0, 2100.0, '2026-05-10', 'Doúčtování nesprávně nízké daně',
        );

        $out = $this->builder->build($this->supplierId, 2026, 3, 'monthly');
        $xml = (string) ($out['xml'] ?? '');
        self::assertNotSame('', $xml);
        self::assertStringContainsString('dan23="2100"', $xml, 'Daň z opravy musí být na ř. 1.');

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        libxml_use_internal_errors(true);
        $valid = $dom->schemaValidate(dirname(__DIR__, 3) . '/xsd/dphdp3.xsd');
        $errors = array_map(static fn ($e): string => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        self::assertTrue($valid, 'XML neprošlo XSD: ' . implode(' | ', $errors));
    }

    /**
     * Staví-li se za období s evidovanou opravou ŘÁDNÉ přiznání, systém upozorní — § 43
     * odst. 1 opravu směruje do dodatečného a špatný typ podání by jinak nikdo nezachytil.
     */
    public function testRegularReturnWarnsThatAmendmentIsRequired(): void
    {
        $this->service->register(
            $this->supplierId, 'invoice', $this->invoiceIds[0], 2026, 4, 'basic',
            0.0, -1000.0, '2026-06-10', 'Chybná sazba',
        );

        $out = $this->builder->build($this->supplierId, 2026, 4, 'monthly');

        self::assertNotEmpty(array_filter(
            $out['warnings'] ?? [],
            static fn (string $w): bool => str_contains($w, '§ 43'),
        ), 'Řádné přiznání s opravou § 43 musí upozornit na dodatečné.');
    }

    /**
     * Skutečný sled: řádné se podá BEZ opravy, oprava se zaeviduje až potom a projeví se
     * v dodatečném jako ROZDÍL. Kdyby se korekce dostala už do základny, dodatečné by
     * vyšlo nulové a oprava by se nikam nedostala.
     *
     * Dodatečné přiznání vyžaduje dřív podanou základnu, takže se musí archivovat
     * a submitnout — samotná archivace nestačí (vzor VatAmendedReturnTest).
     */
    public function testAmendmentShowsCorrectionAsDifference(): void
    {
        // 1) Řádné bez opravy → podáno. Archiver musí být z TÉHOŽ kontejneru jako builder,
        // jinak si otevře vlastní připojení mimo transakci testu a podání nikdo neuvidí.
        $baseline = $this->builder->build($this->supplierId, 2026, 4, 'monthly');
        $res = $this->archiver->archive(
            $this->supplierId, 'dphdp3', 2026, 4, null,
            $baseline['xml'], $baseline['summary'], null, true, 'B',
        );
        $this->archiver->markSubmitted((int) $res['submission_id'], $this->supplierId, date('Y-m-d H:i:s'), 'TEST-43', null);

        // 2) Teprve teď vyjde najevo chybná sazba.
        $this->service->register(
            $this->supplierId, 'invoice', $this->invoiceIds[0], 2026, 4, 'basic',
            0.0, -1000.0, '2026-06-10', 'Chybná sazba',
        );

        // 3) Dodatečné → rozdíl, a už žádné upozornění na špatný typ podání.
        $out = $this->builder->build($this->supplierId, 2026, 4, 'monthly', 'dodatecne', '2026-06-10');

        self::assertSame([], array_values(array_filter(
            $out['warnings'] ?? [],
            static fn (string $w): bool => str_contains($w, '§ 43'),
        )), 'U dodatečného je typ podání správný.');
        self::assertStringContainsString('dapdph_forma="D"', (string) $out['xml']);
    }

    /** Bez evidované opravy se chování nemění. */
    public function testWithoutCorrectionsNothingChanges(): void
    {
        $lines = $this->service->periodCorrectionLines($this->supplierId, 2026, 7);

        self::assertSame(0.0, $lines['basic']['vat']);
        self::assertSame(0.0, $lines['reduced']['vat']);
    }
}

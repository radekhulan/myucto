<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Oss;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Oss\OssEvidenceService;
use MyInvoice\Service\Oss\OssFilingSnapshot;
use MyInvoice\Service\Oss\OssLedgerService;
use MyInvoice\Service\Oss\OssReconciliationService;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Archiv OSS podání, rekonciliace a evidence § 110f nad skutečnou databází.
 *
 * Tři věci, které se v unit testech ověřit nedají a přitom na nich celá epika stojí:
 *  1. evidence se opravdu zapíše a je WRITE-ONCE (trigger migrace 1300),
 *  2. rekonciliace najde doklad opravený PO podání,
 *  3. archiv bez snapshotu (podání z doby před touhle epikou) se nevydává za shodu.
 */
#[Group('integration')]
final class OssFilingArchiveTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private OssLedgerService $ledger;
    private OssFilingSnapshot $snapshot;
    private OssEvidenceService $evidence;
    private OssReconciliationService $reconciliation;
    private TaxSubmissionArchiver $archiver;
    private TaxSubmissionRepository $submissions;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $eurId = 0;
    private int $czkId = 0;
    private int $rate21Id = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db             = $c->get(Connection::class);
            $this->ledger         = $c->get(OssLedgerService::class);
            $this->snapshot       = $c->get(OssFilingSnapshot::class);
            $this->evidence       = $c->get(OssEvidenceService::class);
            $this->reconciliation = $c->get(OssReconciliationService::class);
            $this->archiver       = $c->get(TaxSubmissionArchiver::class);
            $this->submissions    = $c->get(TaxSubmissionRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->evidence->isAvailable()) {
            $this->markTestSkipped('Chybí migrace 1300 (oss_filing_evidence).');
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->eurId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'EUR' LIMIT 1")->fetchColumn() ?: 0);
        $this->czkId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1")->fetchColumn() ?: 0);
        $this->rate21Id = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0 || $this->eurId === 0 || $this->rate21Id === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users / měna EUR / sazba DPH).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare(
            "UPDATE supplier SET oss_enabled = 1, oss_valid_from = '2020-01-01', oss_valid_to = NULL,
                                 oss_return_currency = 'EUR'
              WHERE id = ?"
        )->execute([$this->supplierId]);
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

    /**
     * Evidence vznikne k archivovanému podání a nese body čl. 63c, které doložit umíme:
     * stát spotřeby, datum plnění, obě měny, sazbu i lhůtu uchování podle roku PLNĚNÍ.
     */
    public function testEvidenceIsCapturedWithArticle63cFields(): void
    {
        $this->ossSale($this->euConsumer('PL'), '2099-02-10', 1000.0, 23.0);
        [$submissionId, $preview] = $this->archive(2099, 1);

        $written = $this->evidence->capture($this->supplierId, $submissionId, 2099, 1, $preview, $this->userId);
        self::assertSame(1, $written);

        $data = $this->evidence->records($this->supplierId, 2099, 1);
        self::assertSame(OssEvidenceService::LEGAL_BASIS, $data['legal_basis']);
        self::assertCount(1, $data['records']);

        $r = $data['records'][0];
        self::assertSame('PL', $r['consumption_country']);           // 63c(1)(a)
        self::assertSame('2099-02-10', $r['supply_date']);           // 63c(1)(c)
        self::assertSame('EUR', $r['taxable_currency']);             // 63c(1)(d) měna dokladu
        self::assertSame('EUR', $r['return_currency']);              // 63c(1)(d) měna podání
        self::assertEqualsWithDelta(1000.0, (float) $r['taxable_amount_return'], 0.01);
        self::assertEqualsWithDelta(23.0, (float) $r['vat_rate'], 0.01); // 63c(1)(f)
        self::assertSame('2109-12-31', $r['retain_until']);          // § 110f/2/a: 2099 + 10
        self::assertNotSame([], $r['completeness'], 'Nenaplněné body čl. 63c se musí přiznat.');
        // Doklad je rovnou v měně podání — kurz 1, nic se nepřepočítávalo, takže bod d)
        // je naplněný a nesmí být mezi nenaplněnými.
        self::assertEqualsWithDelta(1.0, (float) $r['exchange_rate'], 0.000001);
        self::assertArrayNotHasKey('d', $r['completeness']);
    }

    /**
     * Kurz ECB (bod 63c(1)(d)) se do evidence zapíše i u AUTOMATICKÉHO přepočtu.
     *
     * Do konce roku 2026 se `exchange_rate` plnil jen z ručního pole na položce, takže
     * u drtivé většiny řádků zůstal NULL — a `completeness_json` to nepřiznával. Evidence
     * tvrdila, že je úplná, a přitom neuměla doložit, jak částka v eurech vznikla.
     */
    public function testEvidenceRecordsTheEcbRateOfAutomaticConversion(): void
    {
        $this->requireEcbSchema();
        $this->seedEcbDay('2099-03-31', 25.0);
        $this->seedCnbDay('2099-02-10', 28.0);
        $this->ossSale($this->euConsumer('PL'), '2099-02-10', 25_000.0, 23.0, currencyId: $this->czkId);
        [$submissionId, $preview] = $this->archive(2099, 1);
        $this->evidence->capture($this->supplierId, $submissionId, 2099, 1, $preview, $this->userId);

        $r = $this->evidence->records($this->supplierId, 2099, 1)['records'][0];

        self::assertSame('CZK', $r['taxable_currency']);
        self::assertEqualsWithDelta(1000.0, (float) $r['taxable_amount_return'], 0.01);
        self::assertEqualsWithDelta(0.04, (float) $r['exchange_rate'], 0.000001, '1 / 25 Kč za 1 €.');
        self::assertSame('2099-03-31', $r['exchange_rate_date']);
        self::assertArrayNotHasKey('d', $r['completeness'], 'Doložený kurz se za nenaplněný bod hlásit nesmí.');
    }

    /**
     * Ručně zadané částky v měně podání kurz nedokládají — systém neví, jakým se počítalo.
     * Evidence to musí PŘIZNAT, ne si kurz dopočítat z podílu částek.
     */
    public function testManualReturnAmountsAdmitTheMissingRateInsteadOfInventingIt(): void
    {
        $invoiceId = $this->ossSale($this->euConsumer('PL'), '2099-02-10', 25_000.0, 23.0, currencyId: $this->czkId);
        $this->db->pdo()->prepare(
            'UPDATE invoice_items SET oss_taxable_amount_return = 1000, oss_vat_amount_return = 230
              WHERE invoice_id = ?'
        )->execute([$invoiceId]);

        [$submissionId, $preview] = $this->archive(2099, 1);
        $this->evidence->capture($this->supplierId, $submissionId, 2099, 1, $preview, $this->userId);

        $r = $this->evidence->records($this->supplierId, 2099, 1)['records'][0];

        self::assertNull($r['exchange_rate']);
        self::assertArrayHasKey('d', $r['completeness']);
    }

    /**
     * Evidence je archivním zdrojem kurzu pro opravu minulého období — tenhle test hlídá,
     * že to, co `capture()` zapíše, umí {@see OssEvidenceService::ratesForPeriod()} přečíst.
     * Bez toho by se oprava přepočetla kurzem běžného kvartálu a nikdy by se nevyrovnala.
     */
    public function testCapturedRateIsReadableAsTheArchivedRateOfThePeriod(): void
    {
        $this->requireEcbSchema();
        $this->seedEcbDay('2099-03-31', 25.0);
        $this->seedCnbDay('2099-02-10', 28.0);
        $this->ossSale($this->euConsumer('PL'), '2099-02-10', 25_000.0, 23.0, currencyId: $this->czkId);
        [$submissionId, $preview] = $this->archive(2099, 1);
        $this->evidence->capture($this->supplierId, $submissionId, 2099, 1, $preview, $this->userId);

        $rates = $this->evidence->ratesForPeriod($this->supplierId, 2099, 1, 'EUR');

        self::assertArrayHasKey('CZK', $rates);
        self::assertEqualsWithDelta(0.04, $rates['CZK']['rate'], 0.000001);
        self::assertSame('2099-03-31', $rates['CZK']['rate_date']);
        self::assertArrayNotHasKey('EUR', $rates, 'Měna podání sama sebe nepřepočítává.');
    }

    /**
     * Bod 63c(1)(h) jsou přijaté platby. Zápočet dobropisu platbou není: jen snižuje
     * tutéž pohledávku a OSS ho vykazuje opravou základu dobropisem, ne úhradou.
     */
    public function testCreditNoteOffsetIsNotRecordedAsReceivedPayment(): void
    {
        $invoiceId = $this->ossSale($this->euConsumer('PL'), '2099-02-10', 1000.0, 23.0);
        $insert = $this->db->pdo()->prepare(
            "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source)
             VALUES (?, ?, ?, ?, 'EUR', ?)"
        );
        $insert->execute([$this->supplierId, $invoiceId, '2099-02-20', 230.0, 'credit_note']);
        $insert->execute([$this->supplierId, $invoiceId, '2099-03-01', 1000.0, 'bank']);

        [$submissionId, $preview] = $this->archive(2099, 1);
        $this->evidence->capture($this->supplierId, $submissionId, 2099, 1, $preview, $this->userId);

        $r = $this->evidence->records($this->supplierId, 2099, 1)['records'][0];
        self::assertEquals(
            [['paid_on' => '2099-03-01', 'amount' => 1000.0, 'currency' => 'EUR']],
            $r['payments'],
            'zápočet dobropisu není přijatá platba',
        );
    }

    /** Evidence je write-once — trigger migrace 1300 odmítne UPDATE i DELETE. */
    public function testEvidenceIsWriteOnce(): void
    {
        $this->ossSale($this->euConsumer('PL'), '2099-02-10', 1000.0, 23.0);
        [$submissionId, $preview] = $this->archive(2099, 1);
        $this->evidence->capture($this->supplierId, $submissionId, 2099, 1, $preview, $this->userId);

        $id = (int) $this->db->pdo()->query(
            'SELECT id FROM oss_filing_evidence WHERE supplier_id = ' . $this->supplierId . ' LIMIT 1'
        )->fetchColumn();
        self::assertGreaterThan(0, $id);

        try {
            $this->db->pdo()->exec("UPDATE oss_filing_evidence SET vat_amount = 0 WHERE id = {$id}");
            self::fail('UPDATE nad evidencí § 110f musí selhat.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('write-once', $e->getMessage());
        }

        try {
            $this->db->pdo()->exec("DELETE FROM oss_filing_evidence WHERE id = {$id}");
            self::fail('DELETE nad evidencí § 110f musí selhat.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('write-once', $e->getMessage());
        }
    }

    /** Opakovaný capture nad TÝMŽ podáním nic nezdvojí (a nic nepřepíše). */
    public function testCaptureIsIdempotentPerSubmission(): void
    {
        $this->ossSale($this->euConsumer('PL'), '2099-02-10', 1000.0, 23.0);
        [$submissionId, $preview] = $this->archive(2099, 1);

        self::assertSame(1, $this->evidence->capture($this->supplierId, $submissionId, 2099, 1, $preview, $this->userId));
        self::assertSame(0, $this->evidence->capture($this->supplierId, $submissionId, 2099, 1, $preview, $this->userId));
        self::assertCount(1, $this->evidence->records($this->supplierId, 2099, 1)['records']);
    }

    /** Beze změny dokladů musí rekonciliace hlásit shodu — jinak je k ničemu. */
    public function testUntouchedPeriodReconciles(): void
    {
        $this->ossSale($this->euConsumer('PL'), '2099-02-10', 1000.0, 23.0);
        $this->archive(2099, 1);

        $result = $this->reconciliation->reconcile($this->supplierId, 2099, 1);

        self::assertTrue($result['has_filing']);
        self::assertTrue($result['snapshot_available']);
        self::assertTrue($result['in_sync']);
        self::assertFalse($result['basis']['is_proven_filing'], 'Stažené XML není doložené podání.');
    }

    /**
     * Jádro epiky: doklad opravený ZPĚTNĚ po podání. Bez rekonciliace by tahle změna
     * neměla v aplikaci jediné místo, kde by se projevila.
     */
    public function testDocumentEditedAfterFilingBreaksReconciliation(): void
    {
        $invoiceId = $this->ossSale($this->euConsumer('PL'), '2099-02-10', 1000.0, 23.0);
        $this->archive(2099, 1);

        $this->db->pdo()->prepare(
            'UPDATE invoice_items SET total_without_vat = 1200, total_vat = 276, total_with_vat = 1476
              WHERE invoice_id = ?'
        )->execute([$invoiceId]);

        $result = $this->reconciliation->reconcile($this->supplierId, 2099, 1);

        self::assertFalse($result['in_sync']);
        self::assertNotSame([], $result['differences']['totals']);
        self::assertSame(['changed'], array_column($result['differences']['documents'], 'change'));
        self::assertSame($invoiceId, $result['differences']['documents'][0]['invoice_id']);
    }

    /** Doklad odstraněný z období (storno) — v dnešním náhledu neexistuje. */
    public function testCancelledDocumentIsReportedAsRemoved(): void
    {
        $invoiceId = $this->ossSale($this->euConsumer('PL'), '2099-02-10', 1000.0, 23.0);
        $this->archive(2099, 1);

        $this->db->pdo()->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?")->execute([$invoiceId]);

        $result = $this->reconciliation->reconcile($this->supplierId, 2099, 1);

        self::assertFalse($result['in_sync']);
        self::assertSame(['removed'], array_column($result['differences']['documents'], 'change'));
    }

    /**
     * Archiv bez snapshotu (podání vzniklé před touhle epikou) NESMÍ projít jako shoda.
     * Přiznaná neznalost je jediná správná odpověď — `in_sync` zůstává `null`.
     */
    public function testLegacyArchiveWithoutSnapshotIsNotReportedInSync(): void
    {
        $this->ossSale($this->euConsumer('PL'), '2099-02-10', 1000.0, 23.0);
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            OssReconciliationService::FORM_CODE,
            2099,
            null,
            1,
            '<OSS/>',
            ['total_vat' => 230.0],   // starý tvar summary — bez klíče `snapshot`
            'skipped',
            [],
            $this->userId,
            'B',
            'downloaded',
        );
        self::assertGreaterThan(0, $submissionId);

        $result = $this->reconciliation->reconcile($this->supplierId, 2099, 1);

        self::assertTrue($result['has_filing']);
        self::assertFalse($result['snapshot_available']);
        self::assertNull($result['in_sync']);
    }

    /**
     * Jádro write-once archivu: rekonciliaci NELZE umlčet dalším stažením.
     *
     * Dřív se za referenci brala poslední archivovaná podoba období, takže „oprav doklad
     * → stáhni XML znovu" rozdíl smazalo: nový snapshot vznikl ze stavu PO opravě a stal
     * se referencí. Kontrola, kterou jde vypnout tím, že se spustí znovu, není kontrola.
     */
    public function testRedownloadCannotOverwriteTheReconciliationReference(): void
    {
        $invoiceId = $this->ossSale($this->euConsumer('PL'), '2099-02-10', 1000.0, 23.0);
        [$firstSubmissionId] = $this->archive(2099, 1);

        $this->db->pdo()->prepare(
            'UPDATE invoice_items SET total_without_vat = 1200, total_vat = 276, total_with_vat = 1476
              WHERE invoice_id = ?'
        )->execute([$invoiceId]);

        // Druhé stažení téhož období — už ze stavu PO opravě.
        [$secondSubmissionId] = $this->archive(2099, 1);
        self::assertNotSame($firstSubmissionId, $secondSubmissionId);

        $result = $this->reconciliation->reconcile($this->supplierId, 2099, 1);

        self::assertSame($firstSubmissionId, $result['basis']['submission_id'], 'Reference je PRVNÍ stažení.');
        self::assertFalse($result['in_sync'], 'Rozdíl proti odeslanému nesmí opakovaným stažením zmizet.');
        self::assertNotSame([], $result['differences']['totals']);
    }

    /**
     * Doložené podání referenci posouvá právem — na rozdíl od pouhého stažení. To je ten
     * rozdíl mezi „vygenerováno k náhledu / staženo" a „tohle bylo podáno".
     */
    public function testProvenFilingBecomesTheReferenceEvenWhenOlderDownloadsExist(): void
    {
        $invoiceId = $this->ossSale($this->euConsumer('PL'), '2099-02-10', 1000.0, 23.0);
        $this->archive(2099, 1);

        $this->db->pdo()->prepare(
            'UPDATE invoice_items SET total_without_vat = 1200, total_vat = 276, total_with_vat = 1476
              WHERE invoice_id = ?'
        )->execute([$invoiceId]);

        [$secondSubmissionId] = $this->archive(2099, 1);
        $this->archiver->markSubmitted($secondSubmissionId, $this->supplierId, '2099-04-20 10:00:00', 'EPO-1', $this->userId);

        $result = $this->reconciliation->reconcile($this->supplierId, 2099, 1);

        self::assertSame($secondSubmissionId, $result['basis']['submission_id']);
        self::assertTrue($result['basis']['is_proven_filing']);
        self::assertTrue($result['in_sync'], 'Podaný snapshot odpovídá dnešnímu stavu dokladů.');
    }

    /**
     * `generated` = vygenerováno k náhledu. Nic neopustilo systém, takže není proti čemu
     * se poměřovat — a rekonciliace to musí říct nahlas místo aby náhled porovnala se
     * sebou samým a vyhlásila shodu.
     */
    public function testGeneratedSnapshotIsNeverUsedAsReference(): void
    {
        $this->ossSale($this->euConsumer('PL'), '2099-02-10', 1000.0, 23.0);
        $preview = $this->ledger->preview($this->supplierId, 2099, 1);
        $this->submissions->archive(
            $this->supplierId, OssReconciliationService::FORM_CODE, 2099, null, 1, '<OSS/>',
            ['snapshot' => $this->snapshot->fromPreview($this->supplierId, $preview)],
            'skipped', [], $this->userId, 'B', 'generated',
        );

        $result = $this->reconciliation->reconcile($this->supplierId, 2099, 1);

        self::assertFalse($result['has_filing']);
        self::assertNull($result['in_sync']);
    }

    /** Co opustilo systém, se z archivu nemaže — jinak by šla reference zahodit ručně. */
    public function testDownloadedAndSubmittedSnapshotsCannotBeDeleted(): void
    {
        $this->ossSale($this->euConsumer('PL'), '2099-02-10', 1000.0, 23.0);
        [$downloadedId] = $this->archive(2099, 1);
        self::assertFalse($this->submissions->delete($downloadedId, $this->supplierId));

        $this->archiver->markSubmitted($downloadedId, $this->supplierId, '2099-04-20 10:00:00', 'EPO-1', $this->userId);
        self::assertFalse($this->submissions->delete($downloadedId, $this->supplierId));
        self::assertNotNull($this->submissions->find($downloadedId, $this->supplierId));

        $draftId = $this->submissions->archive(
            $this->supplierId, OssReconciliationService::FORM_CODE, 2098, null, 4, '<OSS/>',
            [], 'skipped', [], $this->userId, 'B', 'generated',
        );
        self::assertTrue($this->submissions->delete($draftId, $this->supplierId), 'Pouhý náhled smazat lze.');
    }

    /** Bez archivovaného podání není proti čemu rekonciliovat — a říká se to nahlas. */
    public function testNoFilingIsReportedExplicitly(): void
    {
        $this->ossSale($this->euConsumer('PL'), '2099-02-10', 1000.0, 23.0);

        $result = $this->reconciliation->reconcile($this->supplierId, 2099, 1);

        self::assertFalse($result['has_filing']);
        self::assertNull($result['in_sync']);
        self::assertNull($result['basis']);
    }

    /** Archiv se filtruje na OSS — DPH snapshoty do OSS obrazovky nepatří. */
    public function testArchiveListIsFilteredToOssForm(): void
    {
        $this->ossSale($this->euConsumer('PL'), '2099-02-10', 1000.0, 23.0);
        $this->archive(2099, 1);
        $this->submissions->archive(
            $this->supplierId, 'dphdp3', 2099, 2, null, '<DPH/>', [], 'skipped', [], $this->userId, 'B', 'downloaded',
        );

        $rows = $this->submissions->listForForm($this->supplierId, OssReconciliationService::FORM_CODE, 50);

        self::assertCount(1, $rows);
        self::assertSame('ossei1', $rows[0]['form_code']);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * Archivuje aktuální náhled období tak, jak to dělá `OssReportAction::download()`
     * (bez XML exportu, který by vyžadoval IBAN a číselník sazeb členských států).
     *
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function archive(int $year, int $quarter): array
    {
        $preview = $this->ledger->preview($this->supplierId, $year, $quarter);
        $summary = [
            'period'    => sprintf('%04d-Q%d', $year, $quarter),
            'form_code' => OssReconciliationService::FORM_CODE,
            'snapshot'  => $this->snapshot->fromPreview($this->supplierId, $preview),
        ];
        $archived = $this->archiver->archive(
            $this->supplierId,
            OssReconciliationService::FORM_CODE,
            $year,
            null,
            $quarter,
            '<OSS/>',
            $summary,
            $this->userId,
        );

        return [(int) $archived['submission_id'], $preview];
    }

    private function euConsumer(string $iso2): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query(
            "SELECT id FROM countries WHERE UPPER(iso2) = '" . strtoupper($iso2) . "' LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát ' . $iso2 . ' není v číselníku zemí.');
        }
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Mesto", "11000", ?, "c@example.com", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, 'Spotřebitel ' . $iso2, $countryId, $this->eurId]);

        return (int) $pdo->lastInsertId();
    }

    private function requireEcbSchema(): void
    {
        if (!$this->db->hasTable('ecb_exchange_rates') || !$this->db->hasTable('ecb_exchange_rate_days')) {
            self::markTestSkipped('Chybí migrace 1299 (kurzy ECB).');
        }
    }

    /** Syntetický kurz ECB — fiktivní rok, takže test nikdy nesáhne na síť. */
    private function seedEcbDay(string $date, float $czkPerEur): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO ecb_exchange_rates (rate_date, currency_code, units_per_eur) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE units_per_eur = VALUES(units_per_eur)'
        )->execute([$date, 'CZK', $czkPerEur]);
        $this->db->pdo()->prepare(
            'INSERT INTO ecb_exchange_rate_days (rate_date, published) VALUES (?, 1)
             ON DUPLICATE KEY UPDATE published = VALUES(published)'
        )->execute([$date]);
    }

    private function seedCnbDay(string $date, float $czkPerEur): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([$date, 'EUR', $czkPerEur]);
    }

    /** OSS prodej — bez `currencyId` v EUR, tedy v měně podání a bez přepočtu. */
    private function ossSale(int $clientId, string $taxDate, float $baseEur, float $rate, ?int $currencyId = null): int
    {
        $pdo = $this->db->pdo();
        $vat = round($baseEur * $rate / 100.0, 2);
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, varsymbol, invoice_type, issue_date, tax_date,
                 due_date, currency_id, reverse_charge, client_snapshot, supplier_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, 0, "{}", "{}", ?, ?, ?, "issued", ?)'
        )->execute([
            $this->supplierId, $clientId, substr(md5($taxDate . $baseEur . $clientId), 0, 10),
            $taxDate, $taxDate, $taxDate, $currencyId ?? $this->eurId,
            $baseEur, $vat, $baseEur + $vat, $this->userId,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 oss_applicable, oss_consumer_country, oss_rate_type, oss_supply_type)
             VALUES (?, "Plnění", 1, "ks", ?, ?, ?, ?, ?, ?, 1, 1, ?, "standard", "services")'
        )->execute([
            $invoiceId, $baseEur, $this->rate21Id, $rate, $baseEur, $vat, $baseEur + $vat,
            $this->consumerCountry($clientId),
        ]);

        return $invoiceId;
    }

    private function consumerCountry(int $clientId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT UPPER(co.iso2) FROM clients c JOIN countries co ON co.id = c.country_id WHERE c.id = ?'
        );
        $stmt->execute([$clientId]);

        return (string) $stmt->fetchColumn();
    }
}

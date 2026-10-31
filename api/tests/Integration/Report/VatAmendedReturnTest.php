<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use MyInvoice\Service\Report\VatPostFilingChangesService;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * C7' (audit 2026-07, vat) — opravné/dodatečné DPH přiznání + následné KH + fronta
 * „doklady změněné po podání".
 *
 * Pokrývá:
 *   (a) opravné (O) DPHDP3 = plný přepočet, dapdph_forma='O',
 *   (b) dodatečné (D) bez předchozího archivovaného přiznání → tvrdá chyba,
 *   (c) dodatečné (D) S předchozím = řádky nesou DELTA, ř.66 (dano) = rozdíl,
 *   (d) dodatečné (D) bez d_zjist → validační chyba,
 *   (e) následné (N) KH = FULL (ne diff), khdph_forma='N',
 *   (f) fronta post-filing-changes najde doklad upravený po archivaci,
 *   (g) XSD validace O/D DPHDP3 + N KH.
 *
 * Izolovaný rok 2092 pod existujícím supplierem (vynucen plátce), úklid v tearDown.
 */
#[Group('integration')]
class VatAmendedReturnTest extends TestCase
{
    protected const YEAR = 2092;

    protected Connection $db;
    protected DphPriznaniBuilder $dph;
    protected KontrolniHlaseniBuilder $kh;
    protected TaxSubmissionArchiver $archiver;
    private TaxSubmissionRepository $submissions;
    private VatPostFilingChangesService $postFiling;
    private ?XmlSchemaValidator $validator = null;

    protected int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    protected int $userId = 0;
    private int $czId = 0;

    /** @var array{customers:int[], vendors:int[]} */
    private array $clientIds = ['customers' => [], 'vendors' => []];
    /** @var int[] */
    private array $invoiceIds = [];
    /** @var int[] */
    private array $purchaseIds = [];
    /** @var int[] */
    private array $cashDocIds = [];
    private int $cashRegisterId = 0;
    private ?array $origVatFlags = null;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db          = $c->get(Connection::class);
            $this->dph         = $c->get(DphPriznaniBuilder::class);
            $this->kh          = $c->get(KontrolniHlaseniBuilder::class);
            $this->archiver    = $c->get(TaxSubmissionArchiver::class);
            $this->submissions = $c->get(TaxSubmissionRepository::class);
            $this->postFiling  = $c->get(VatPostFilingChangesService::class);
            $this->validator   = $c->get(XmlSchemaValidator::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = $this->countryId('CZ');

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0
            || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $flags = $pdo->query("SELECT is_vat_payer, is_identified FROM supplier WHERE id = {$this->supplierId}")
            ->fetch(\PDO::FETCH_ASSOC) ?: [];
        $this->origVatFlags = $flags;
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 1, is_identified = 0 WHERE id = ?')
            ->execute([$this->supplierId]);

        $this->cleanupSubmissions();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->origVatFlags !== null && $this->supplierId > 0) {
            $pdo->prepare('UPDATE supplier SET is_vat_payer = ?, is_identified = ? WHERE id = ?')
                ->execute([
                    (int) ($this->origVatFlags['is_vat_payer'] ?? 1),
                    (int) ($this->origVatFlags['is_identified'] ?? 0),
                    $this->supplierId,
                ]);
        }
        foreach ($this->invoiceIds as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->purchaseIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->cashDocIds as $id) {
            $pdo->prepare('DELETE FROM cash_document_vat_lines WHERE cash_document_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM cash_documents WHERE id = ?')->execute([$id]);
        }
        if ($this->cashRegisterId > 0) {
            $pdo->prepare('DELETE FROM cash_registers WHERE id = ?')->execute([$this->cashRegisterId]);
        }
        foreach (array_merge($this->clientIds['customers'], $this->clientIds['vendors']) as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->cleanupSubmissions();
        $this->db->close();
    }

    private function cleanupSubmissions(): void
    {
        $this->db->pdo()
            ->prepare('DELETE FROM tax_submissions WHERE supplier_id = ? AND period_year = ?')
            ->execute([$this->supplierId, self::YEAR]);
    }

    /**
     * Archivuje snapshot a HNED jej označí jako prokazatelně PODANÝ (§2.4). Základnou
     * opravného/následného tvrzení a rekonciliace "s podaným" je od auditu §2.4 jen
     * `status='submitted'`, nikoli pouhá archivace stažení — proto tyto testy, které
     * simulují "již podané přiznání", musí snapshot i submitnout. (YEAR=2092 je budoucí
     * období, takže markSubmitted zámek neposouvá — žádná pollution zámku mezi testy.)
     *
     * @param array<string,mixed> $summary
     */
    protected function archiveAndSubmit(
        int $supplierId,
        string $formCode,
        int $year,
        ?int $month,
        ?int $quarter,
        string $xml,
        array $summary,
        ?int $generatedBy,
        bool $allowLock = true,
        string $variant = 'B',
    ): int {
        $res = $this->archiver->archive(
            $supplierId, $formCode, $year, $month, $quarter, $xml, $summary, $generatedBy, $allowLock, $variant,
        );
        $id = (int) $res['submission_id'];
        $this->archiver->markSubmitted($id, $supplierId, date('Y-m-d H:i:s'), 'TEST-EPO-' . $id, $generatedBy);
        return $id;
    }

    /** (a) Opravné (O) přiznání = plný přepočet (jako řádné), jen dapdph_forma='O'. */
    public function testOpravneIsFullRestatement(): void
    {
        $cust = $this->client('Odběratel', 'CZ90010011');
        $this->sale('OA-1', $cust, '1', $this->d(5, 10), [[100000, 21000, 21]]);
        // Opravné nahrazuje dříve podané řádné — bez základny ho builder odmítne.
        $baseline = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        $this->archiveAndSubmit(
            $this->supplierId, 'dphdp3', self::YEAR, 5, null,
            $baseline['xml'], $baseline['summary'], $this->userId, true, 'B',
        );

        $result = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'opravne');
        $xml = new \SimpleXMLElement($result['xml']);

        $this->assertSame('O', (string) $xml->DPHDP3->VetaD['dapdph_forma']);
        // Plné (absolutní) hodnoty — ne diff.
        $this->assertSame('100000', (string) $xml->DPHDP3->Veta1['obrat23']);
        $this->assertSame('21000', (string) $xml->DPHDP3->Veta1['dan23']);
        $this->assertSame('21000', (string) $xml->DPHDP3->Veta6['dan_zocelk']);
        $this->assertSame('21000', (string) $xml->DPHDP3->Veta6['dano_da']);
        // ř.66 (dano) se u řádného/opravného NEvyplňuje.
        $this->assertSame('', (string) $xml->DPHDP3->Veta6['dano']);
        $this->assertFalse($result['summary']['is_amendment']);
    }

    /** (b) Dodatečné (D) bez předchozího archivovaného přiznání → tvrdá chyba. */
    public function testDodatecneWithoutBaselineThrows(): void
    {
        $cust = $this->client('Odběratel', 'CZ90010029');
        $this->sale('DB-1', $cust, '1', $this->d(5, 10), [[100000, 21000, 21]]);

        try {
            $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'dodatecne', '2092-07-01');
            $this->fail('Očekávána chyba: dodatečné bez základny.');
        } catch (PostingException $e) {
            $this->assertSame('no_prior_submission_to_amend', $e->errorCode, $e->getMessage());
        }
    }

    /** (c) Dodatečné (D) s předchozím = řádky nesou DELTA, ř.66 = rozdíl vlastní daně. */
    public function testDodatecneCarriesDeltaAgainstBaseline(): void
    {
        $cust = $this->client('Odběratel', 'CZ90010037');
        // Řádné: jedna faktura 100 000 / 21 000.
        $this->sale('DC-1', $cust, '1', $this->d(5, 10), [[100000, 21000, 21]]);
        $baseline = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        $this->archiveAndSubmit(
            $this->supplierId, 'dphdp3', self::YEAR, 5, null,
            $baseline['xml'], $baseline['summary'], $this->userId, true, 'B',
        );

        // Po podání přibyla druhá faktura 50 000 / 10 500.
        $this->sale('DC-2', $cust, '1', $this->d(5, 20), [[50000, 10500, 21]]);

        $result = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'dodatecne', '2092-07-15');
        $xml = new \SimpleXMLElement($result['xml']);

        $this->assertSame('D', (string) $xml->DPHDP3->VetaD['dapdph_forma']);
        $this->assertSame('15.07.2092', (string) $xml->DPHDP3->VetaD['d_zjist']);
        // Řádky nesou ROZDÍL (ne absolutní 150 000 / 31 500).
        $this->assertSame('50000', (string) $xml->DPHDP3->Veta1['obrat23'], 'obrat23 musí být DELTA');
        $this->assertSame('10500', (string) $xml->DPHDP3->Veta1['dan23'], 'dan23 musí být DELTA');
        // Veta6: dan_zocelk delta = 10 500, odp_zocelk delta = 0, ř.66 dano = 10 500.
        $this->assertSame('10500', (string) $xml->DPHDP3->Veta6['dan_zocelk']);
        $this->assertSame('10500', (string) $xml->DPHDP3->Veta6['dano']);
        // dano_da/dano_no se u dodatečného NEvyplňují.
        $this->assertSame('', (string) $xml->DPHDP3->Veta6['dano_da']);
        $this->assertSame('', (string) $xml->DPHDP3->Veta6['dano_no']);
        // Summary — poslední známá daň 21 000, rozdíl 10 500.
        $this->assertTrue($result['summary']['is_amendment']);
        $this->assertSame(21000.0, (float) $result['summary']['last_known_tax']);
        $this->assertSame(10500.0, (float) $result['summary']['tax_difference']);
    }

    /** (d) Dodatečné (D) bez data zjištění (d_zjist) → validační chyba. */
    public function testDodatecneWithoutDZjistThrows(): void
    {
        try {
            $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'dodatecne', null);
            $this->fail('Očekávána chyba: dodatečné bez d_zjist.');
        } catch (PostingException $e) {
            $this->assertSame('vat_d_zjist_required', $e->errorCode, $e->getMessage());
        }
    }

    /** (e) Následné (N) KH = FULL (všechny údaje znovu), khdph_forma='N'. */
    public function testNasledneKhIsFullNotDiff(): void
    {
        $cust = $this->client('Odběratel KH', 'CZ90010045');
        // Tuzemská plnění nad 10 000 Kč s DIČ → A.4.
        $this->sale('NK-1', $cust, '1', $this->d(5, 10), [[100000, 21000, 21]]);

        $radne   = $this->kh->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        // Následné KH navazuje na PODANÉ řádné — bez něj ho builder odmítne (první podání
        // za období je vždy řádné, i po termínu).
        $this->archiveAndSubmit(
            $this->supplierId, 'dphkh1', self::YEAR, 5, null,
            $radne['xml'], $radne['summary'], $this->userId, true, 'B',
        );
        $nasledne = $this->kh->build($this->supplierId, self::YEAR, 5, 'monthly', 'nasledne', '2092-07-10');

        $xmlR = new \SimpleXMLElement($radne['xml']);
        $xmlN = new \SimpleXMLElement($nasledne['xml']);

        $this->assertSame('B', (string) $xmlR->DPHKH1->VetaD['khdph_forma']);
        $this->assertSame('N', (string) $xmlN->DPHKH1->VetaD['khdph_forma']);
        $this->assertSame('10.07.2092', (string) $xmlN->DPHKH1->VetaD['d_zjist']);
        // FULL: následné má stejný počet A.4 řádků jako řádné (ne diff).
        $this->assertSame(
            $xmlR->DPHKH1->VetaA4->count(),
            $xmlN->DPHKH1->VetaA4->count(),
            'následné KH musí obsahovat VŠECHNY údaje (ne rozdíl)',
        );
        $this->assertSame(1, $xmlN->DPHKH1->VetaA4->count());
        $this->assertSame('nasledne', (string) $nasledne['summary']['variant']);
    }

    /** (f) Fronta post-filing-changes najde doklad upravený po archivaci přiznání. */
    public function testPostFilingChangesDetectsModifiedDocument(): void
    {
        $cust = $this->client('Odběratel', 'CZ90010053');
        $this->sale('PF-1', $cust, '1', $this->d(6, 10), [[100000, 21000, 21]]);

        // Podání (archivace) — generated_at = teď.
        $built = $this->dph->build($this->supplierId, self::YEAR, 6, 'monthly', 'radne');
        $this->archiveAndSubmit(
            $this->supplierId, 'dphdp3', self::YEAR, 6, null,
            $built['xml'], $built['summary'], $this->userId, true, 'B',
        );
        $unchanged = $this->postFiling->changes($this->supplierId, self::YEAR, 6, 'monthly');
        $this->assertSame([], $unchanged['documents'], 'snapshot nesmí označit nezměněný doklad ani ve stejné sekundě');

        // Úhrada ani změna updated_at DPH nemění, doklad do fronty nepatří.
        $invoiceId = $this->invoiceIds[array_key_last($this->invoiceIds)];
        $this->db->pdo()
            ->prepare("UPDATE invoices SET status = 'paid', updated_at = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE id = ?")
            ->execute([$invoiceId]);
        $paid = $this->postFiling->changes($this->supplierId, self::YEAR, 6, 'monthly');
        $this->assertSame([], $paid['documents'], 'úhrada ani updated_at nejsou důvodem k dodatečnému přiznání');

        // Změna částky po podání do fronty patří.
        $this->db->pdo()
            ->prepare('UPDATE invoices SET total_with_vat = total_with_vat + 100 WHERE id = ?')
            ->execute([$invoiceId]);

        $changes = $this->postFiling->changes($this->supplierId, self::YEAR, 6, 'monthly');

        $this->assertTrue($changes['has_filing']);
        $ids = array_map(static fn ($d) => $d['invoice_id'], $changes['documents']);
        $this->assertContains($invoiceId, $ids, 'změněný doklad musí být ve frontě');
    }

    /** Čtení času jinak o hodinu (přechod letního času bez tabulek zón) není změna dokladu. */
    public function testPostFilingIgnoresShiftedUpdatedAtAndComparesVatLines(): void
    {
        $line = ['21|1|21' => ['base' => 1000.0, 'vat' => 210.0]];
        $row = ['status' => 'paid', 'tax_date' => '2026-09-30', 'total' => '1210.00', 'updated_at' => '2026-10-13 10:00:04'];
        $filed = ['status' => 'sent', 'tax_date' => '2026-09-30', 'total' => 1210.0, 'updated_at' => '2026-10-13 11:00:04', 'vat_lines' => $line];
        $legacy = array_diff_key($filed, ['vat_lines' => true]);
        $check = [VatPostFilingChangesService::class, 'changedAfterFiling'];

        $this->assertFalse($check($row, '2026-10-13 10:21:49', $filed, $line));
        $this->assertFalse($check($row, '2026-10-13 10:21:49', $legacy, []), 'starší snapshot bez řádků přiznání');
        $this->assertTrue($check($row, '2026-10-13 10:21:49', $filed, ['21|1|12' => ['base' => 1000.0, 'vat' => 210.0]]), 'jiná sazba při stejné částce');
        $this->assertTrue($check($row, '2026-10-13 10:21:49', $filed, ['21|1|21' => ['base' => 1000.0, 'vat' => 200.0]]), 'jiná daň');
        $this->assertTrue($check($row, '2026-10-13 10:21:49', $filed, []), 'doklad z období zmizel');
        $this->assertTrue($check(['status' => 'cancelled'] + $row, '2026-10-13 10:21:49', $filed, $line), 'storno');
        $this->assertTrue($check($row, '2026-10-13 09:00:00', null), 'doklad v podání nebyl a přibyl po něm');
        $this->assertFalse($check($row, '2026-10-13 11:00:00', null), 'doklad v podání nebyl, ale je starší');
    }

    /** M42: storno po podání nesmí doklad odstranit z fronty změn. */
    public function testPostFilingChangesDetectsCancelledDocumentRemovedFromLedger(): void
    {
        $cust = $this->client('Odběratel storno', 'CZ90010142');
        $this->sale('PF-CANCEL', $cust, '1', $this->d(8, 10), [[100000, 21000, 21]]);
        $invoiceId = $this->invoiceIds[array_key_last($this->invoiceIds)];

        $built = $this->dph->build($this->supplierId, self::YEAR, 8, 'monthly', 'radne');
        $this->archiveAndSubmit(
            $this->supplierId, 'dphdp3', self::YEAR, 8, null,
            $built['xml'], $built['summary'], $this->userId, true, 'B',
        );

        $this->db->pdo()->prepare('UPDATE invoices SET status = "cancelled" WHERE id = ?')->execute([$invoiceId]);

        $changes = $this->postFiling->changes($this->supplierId, self::YEAR, 8, 'monthly');
        $this->assertTrue($changes['snapshot_available']);
        $ids = array_column($changes['documents'], 'invoice_id');
        $this->assertContains($invoiceId, $ids, 'stornovaný doklad z archivovaného podání musí zůstat ve frontě');
    }

    /** M42: přesun DUZP mimo podané období musí být dohledatelný přes snapshot podání. */
    public function testPostFilingChangesDetectsTaxDateMovedOutsideFiledPeriod(): void
    {
        $cust = $this->client('Odběratel přesun DUZP', 'CZ90010150');
        $this->sale('PF-MOVED', $cust, '1', $this->d(10, 10), [[50000, 10500, 21]]);
        $invoiceId = $this->invoiceIds[array_key_last($this->invoiceIds)];

        $built = $this->dph->build($this->supplierId, self::YEAR, 10, 'monthly', 'radne');
        $this->assertContains(
            $invoiceId,
            array_column($built['summary']['document_refs'], 'invoice_id'),
            'archivní summary musí nést snapshot dokladů zahrnutých do podání',
        );
        $this->archiveAndSubmit(
            $this->supplierId, 'dphdp3', self::YEAR, 10, null,
            $built['xml'], $built['summary'], $this->userId, true, 'B',
        );

        $this->db->pdo()->prepare(
            'UPDATE invoices SET tax_date = ?, updated_at = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE id = ?'
        )->execute([$this->d(11, 5), $invoiceId]);

        $changes = $this->postFiling->changes($this->supplierId, self::YEAR, 10, 'monthly');
        $ids = array_column($changes['documents'], 'invoice_id');
        $this->assertContains($invoiceId, $ids, 'doklad s DUZP přesunutým mimo podané období musí být ve frontě');
    }

    /** Historická podání bez snapshotu musí omezení přiznat volajícímu. */
    public function testPostFilingReportsMissingSnapshotForLegacySubmission(): void
    {
        $cust = $this->client('Odběratel legacy snapshot', 'CZ90010169');
        $this->sale('PF-LEGACY', $cust, '1', $this->d(11, 10), [[10000, 2100, 21]]);
        $built = $this->dph->build($this->supplierId, self::YEAR, 11, 'monthly', 'radne');
        unset($built['summary']['document_refs']);
        $this->archiveAndSubmit(
            $this->supplierId, 'dphdp3', self::YEAR, 11, null,
            $built['xml'], $built['summary'], $this->userId, true, 'B',
        );

        $changes = $this->postFiling->changes($this->supplierId, self::YEAR, 11, 'monthly');
        $this->assertFalse($changes['snapshot_available']);
    }

    /** (f2) Bez podání = fronta prázdná (has_filing=false). */
    public function testPostFilingChangesEmptyWithoutFiling(): void
    {
        $cust = $this->client('Odběratel', 'CZ90010061');
        $this->sale('PF2-1', $cust, '1', $this->d(9, 10), [[100000, 21000, 21]]);

        $changes = $this->postFiling->changes($this->supplierId, self::YEAR, 9, 'monthly');
        $this->assertFalse($changes['has_filing']);
        $this->assertSame([], $changes['documents']);
    }

    /** (g) XSD validace: O a D DPHDP3 + N KH projdou. */
    public function testAmendedFormsPassXsd(): void
    {
        if ($this->validator === null || !$this->validator->hasSchema('dphdp3') || !$this->validator->hasSchema('dphkh1')) {
            $this->markTestSkipped('XSD schema není k dispozici.');
        }
        $cust = $this->client('Odběratel', 'CZ90010079');
        $this->sale('XV-1', $cust, '1', $this->d(5, 10), [[100000, 21000, 21]]);

        // Archivuj řádné — základna pro opravné i dodatečné.
        $b = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        $this->archiveAndSubmit(
            $this->supplierId, 'dphdp3', self::YEAR, 5, null,
            $b['xml'], $b['summary'], $this->userId, true, 'B',
        );

        // O — plné přiznání.
        $o = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'opravne');
        $this->assertValid('dphdp3', $o['xml']);

        $this->sale('XV-2', $cust, '1', $this->d(5, 20), [[50000, 10500, 21]]);
        $d = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'dodatecne', '2092-07-01');
        $this->assertValid('dphdp3', $d['xml']);

        // N KH — opět proti podanému řádnému KH.
        $khB = $this->kh->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        $this->archiveAndSubmit(
            $this->supplierId, 'dphkh1', self::YEAR, 5, null,
            $khB['xml'], $khB['summary'], $this->userId, true, 'B',
        );
        $n = $this->kh->build($this->supplierId, self::YEAR, 5, 'monthly', 'nasledne', '2092-07-01');
        $this->assertValid('dphkh1', $n['xml']);
    }

    /**
     * (c2) HIGH regrese: 2. dodatečné (D2) musí počítat DELTU proti POSLEDNÍ ZNÁMÉ DANI
     * (řádné + Σ předchozích dodatečných), ne proti původnímu řádnému — jinak by dvakrát
     * vykázalo rozdíl už podaný v D1.
     */
    public function testSecondDodatecneUsesCumulativeBaseline(): void
    {
        $cust = $this->client('Odběratel', 'CZ90010088');
        // Řádné B: 100 000 / 21 000.
        $this->sale('C2-1', $cust, '1', $this->d(5, 10), [[100000, 21000, 21]]);
        $b = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        $this->archiveAndSubmit(
            $this->supplierId, 'dphdp3', self::YEAR, 5, null,
            $b['xml'], $b['summary'], $this->userId, true, 'B',
        );

        // 1. dodatečné D1: přibyla faktura 50 000 / 10 500 → správně 31 500.
        $this->sale('C2-2', $cust, '1', $this->d(5, 20), [[50000, 10500, 21]]);
        $d1 = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'dodatecne', '2092-07-10');
        $this->assertSame(21000.0, (float) $d1['summary']['last_known_tax']);
        $this->assertSame(10500.0, (float) $d1['summary']['tax_difference']);
        $this->archiveAndSubmit(
            $this->supplierId, 'dphdp3', self::YEAR, 5, null,
            $d1['xml'], $d1['summary'], $this->userId, true, 'D',
        );

        // 2. dodatečné D2: přibyla další faktura 21 500 / 4 500 → správně 36 000.
        $this->sale('C2-3', $cust, '1', $this->d(5, 25), [[21500, 4500, 21]]);
        $d2 = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'dodatecne', '2092-07-20');
        $xml = new \SimpleXMLElement($d2['xml']);

        // Poslední známá daň = 31 500 (řádné 21 000 + D1 10 500), NE 21 000.
        $this->assertSame(31500.0, (float) $d2['summary']['last_known_tax'],
            'baseline 2. dodatečného musí být kumulativní (řádné + D1), ne původní řádné');
        // Rozdíl D2 = 36 000 − 31 500 = 4 500 (NE 15 000).
        $this->assertSame(4500.0, (float) $d2['summary']['tax_difference']);
        $this->assertSame('4500', (string) $xml->DPHDP3->Veta6['dan_zocelk']);
        $this->assertSame('4500', (string) $xml->DPHDP3->Veta6['dano']);
        // Řádek nese jen inkrement 4 500 (dan23), ne 15 000.
        $this->assertSame('4500', (string) $xml->DPHDP3->Veta1['dan23']);
        $this->assertSame('21500', (string) $xml->DPHDP3->Veta1['obrat23']);
    }

    /**
     * (c3) Opravné dodatečné (E) v řetězci → tvrdá, srozumitelná chyba (konzervativní guard):
     * náhradová sémantika nejde bezpečně kumulovat.
     */
    public function testDodatecneOpravneThrowsUnsupported(): void
    {
        $cust = $this->client('Odběratel', 'CZ90010096');
        $this->sale('C3-1', $cust, '1', $this->d(5, 10), [[100000, 21000, 21]]);
        $b = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        $this->archiveAndSubmit(
            $this->supplierId, 'dphdp3', self::YEAR, 5, null,
            $b['xml'], $b['summary'], $this->userId, true, 'B',
        );

        try {
            $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'dodatecne_opravne', '2092-07-10');
            $this->fail('Očekávána chyba: opravné dodatečné (E) není podporováno.');
        } catch (PostingException $e) {
            $this->assertSame('amendment_correction_unsupported', $e->errorCode, $e->getMessage());
        }
    }

    /**
     * (f3) MEDIUM: fronta post-filing musí najít změněný DAŇOVÝ POKLADNÍ doklad se `source='cash'`
     * a jeho ID (cash_documents.id) NESMÍ téct do dotazu na `invoices` (kolize PK).
     */
    public function testPostFilingDetectsChangedCashDocument(): void
    {
        $cashId = $this->cashDoc('in', $this->d(7, 15), 12100.0, 10000.0, 2100.0, 21.0);

        // Podání za červenec (řádné) — pokladní doklad do něj patří.
        $built = $this->dph->build($this->supplierId, self::YEAR, 7, 'monthly', 'radne');
        $this->archiveAndSubmit(
            $this->supplierId, 'dphdp3', self::YEAR, 7, null,
            $built['xml'], $built['summary'], $this->userId, true, 'B',
        );

        // Pokladní doklad změněn PO podání.
        $this->db->pdo()
            ->prepare('UPDATE cash_documents SET total_amount = total_amount + 100 WHERE id = ?')
            ->execute([$cashId]);

        $changes = $this->postFiling->changes($this->supplierId, self::YEAR, 7, 'monthly');
        $this->assertTrue($changes['has_filing']);

        $cash = array_values(array_filter(
            $changes['documents'],
            static fn ($d) => $d['source'] === 'cash' && (int) $d['invoice_id'] === $cashId,
        ));
        $this->assertCount(1, $cash, 'změněný pokladní doklad musí být ve frontě jako source=cash');
        // Pokladní ID se NESMÍ objevit jako fakturační zdroj.
        foreach ($changes['documents'] as $d) {
            if ((int) $d['invoice_id'] === $cashId) {
                $this->assertSame('cash', $d['source'], 'ID pokladního dokladu nesmí být hlášeno jako faktura');
            }
        }
    }

    /**
     * (g) § 141 DŘ: dodatečné přiznání se podává na ZMĚNU údajů. Když se proti poslední
     * známé dani nic nezměnilo, nesmí vzniknout prázdné podání — XSD ho pustí (Veta1-6
     * mají minOccurs=0), správce daně s ním nemá co dělat.
     */
    public function testDodatecneWithoutAnyChangeIsRefused(): void
    {
        $cust = $this->client('Odběratel beze změny', 'CZ90010053');
        $this->sale('NC-1', $cust, '1', $this->d(5, 10), [[100000, 21000, 21]]);

        $b = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        $this->archiveAndSubmit(
            $this->supplierId, 'dphdp3', self::YEAR, 5, null,
            $b['xml'], $b['summary'], $this->userId, true, 'B',
        );

        try {
            $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'dodatecne', '2092-07-01');
            $this->fail('Očekávána chyba: dodatečné bez jediné změny.');
        } catch (PostingException $e) {
            $this->assertSame('vat_amendment_no_change', $e->errorCode, $e->getMessage());
        }
    }

    /**
     * (h) EPO: „Je zadána pouze jedna z hodnot základ daně/daň na ř. 01. Musí být zadány
     * obě." Změní-li se jen DAŇ (oprava sazby při stejném základu), musí v dodatečném
     * zůstat i základ — byť s nulovým rozdílem.
     */
    public function testDodatecneKeepsBaseTaxPairEvenWhenOnlyTaxChanged(): void
    {
        $cust = $this->client('Odběratel sazba', 'CZ90010061');
        $this->sale('PR-1', $cust, '1', $this->d(5, 10), [[100000, 21000, 21]]);

        $b = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        // Podané XML posuneme jen v DANI — základ zůstává stejný.
        $xml = str_replace('dan23="21000"', 'dan23="20000"', $b['xml']);
        $this->assertNotSame($b['xml'], $xml, 'fixture: dan23 se nepodařilo posunout');
        $this->archiveAndSubmit(
            $this->supplierId, 'dphdp3', self::YEAR, 5, null,
            $xml, $b['summary'], $this->userId, true, 'B',
        );

        $d = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'dodatecne', '2092-07-01');
        $veta1 = (new \SimpleXMLElement($d['xml']))->DPHDP3->Veta1;
        $this->assertSame('1000', (string) $veta1['dan23']);
        $this->assertSame('0', (string) $veta1['obrat23'], 'základ musí zůstat ve dvojici s daní');
    }

    /** (i) § 141/5: dodatečné přiznání nese důvody podání jako textovou přílohu (kod_sekce='D'). */
    public function testDodatecneCarriesReasonAttachment(): void
    {
        $cust = $this->client('Odběratel důvody', 'CZ90010088');
        $this->sale('RS-1', $cust, '1', $this->d(5, 10), [[100000, 21000, 21]]);

        $b = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        $this->archiveAndSubmit(
            $this->supplierId, 'dphdp3', self::YEAR, 5, null,
            $b['xml'], $b['summary'], $this->userId, true, 'B',
        );
        $this->sale('RS-2', $cust, '1', $this->d(5, 20), [[50000, 10500, 21]]);

        $d = $this->dph->build(
            $this->supplierId, self::YEAR, 5, 'monthly', 'dodatecne', '2092-07-01',
            'Dodatečně zaúčtována přijatá faktura.',
        );
        $xml = new \SimpleXMLElement($d['xml']);
        $this->assertSame('D', (string) $xml->DPHDP3->VetaR[0]['kod_sekce']);
        $this->assertSame('Dodatečně zaúčtována přijatá faktura.', (string) $xml->DPHDP3->VetaR[0]['t_prilohy']);
        $this->assertValid('dphdp3', $d['xml']);

        // Bez zadaných důvodů se doplní obecný text — a builder na to upozorní.
        $auto = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly', 'dodatecne', '2092-07-01');
        $autoXml = new \SimpleXMLElement($auto['xml']);
        $this->assertNotSame('', (string) $autoXml->DPHDP3->VetaR[0]['t_prilohy']);
        $this->assertNotEmpty(array_filter(
            $auto['warnings'],
            static fn (string $w): bool => str_contains($w, 'důvody podání'),
        ));
    }

    /**
     * (j) § 101g: rychlá odpověď na výzvu je KH BEZ oddílů A/B/C, s `vyzva_odp` a č.j. výzvy.
     * Bez č.j. ji nelze sestavit — správce daně by ji nespároval s výzvou.
     */
    public function testKhVyzvaOdpovedHasNoSectionsAndNeedsReference(): void
    {
        $cust = $this->client('Odběratel výzva', 'CZ90010096');
        $this->sale('VY-1', $cust, '1', $this->d(5, 10), [[100000, 21000, 21]]);

        $res = $this->kh->build(
            $this->supplierId, self::YEAR, 5, 'monthly', 'vyzva_nulove', null,
            '12345678/12/3001-12345-123456',
        );
        $xml = new \SimpleXMLElement($res['xml']);
        $this->assertSame('B', (string) $xml->DPHKH1->VetaD['khdph_forma']);
        $this->assertSame('B', (string) $xml->DPHKH1->VetaD['vyzva_odp']);
        $this->assertSame('12345678/12/3001-12345-123456', (string) $xml->DPHKH1->VetaD['c_jed_vyzvy']);
        $this->assertCount(0, $xml->DPHKH1->VetaA4, 'odpověď na výzvu nesmí mít řádky oddílu A');
        $this->assertCount(0, $xml->DPHKH1->VetaC, 'odpověď na výzvu nesmí mít rekapitulaci');
        $this->assertValid('dphkh1', $res['xml']);

        try {
            $this->kh->build($this->supplierId, self::YEAR, 5, 'monthly', 'vyzva_nulove');
            $this->fail('Očekávána chyba: odpověď na výzvu bez č.j.');
        } catch (PostingException $e) {
            $this->assertSame('kh_vyzva_ref_required', $e->errorCode, $e->getMessage());
        }
    }

    /**
     * (k) Následné KH bez data zjištění i bez č.j. výzvy je vadné podání (XSD anotace
     * d_zjist). Dřív to bylo jen varování, které stažení XML vůbec nevrací.
     */
    public function testNasledneKhWithoutDateOrNoticeIsRefused(): void
    {
        $cust = $this->client('Odběratel KH bez data', 'CZ90010100');
        $this->sale('ND-1', $cust, '1', $this->d(5, 10), [[100000, 21000, 21]]);
        $radne = $this->kh->build($this->supplierId, self::YEAR, 5, 'monthly', 'radne');
        $this->archiveAndSubmit(
            $this->supplierId, 'dphkh1', self::YEAR, 5, null,
            $radne['xml'], $radne['summary'], $this->userId, true, 'B',
        );

        try {
            $this->kh->build($this->supplierId, self::YEAR, 5, 'monthly', 'nasledne');
            $this->fail('Očekávána chyba: následné KH bez data zjištění i bez č.j. výzvy.');
        } catch (PostingException $e) {
            $this->assertSame('kh_d_zjist_required', $e->errorCode, $e->getMessage());
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function assertValid(string $form, string $xml): void
    {
        $v = $this->validator->validate($xml, $form);
        $this->assertSame('passed', $v['status'], "XSD ({$form}) selhala:\n  - " . implode("\n  - ", $v['errors']));
    }

    protected function d(int $month, int $day): string
    {
        return sprintf('%04d-%02d-%02d', self::YEAR, $month, $day);
    }

    protected function client(string $name, ?string $dic): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "test@example.com", "cs", ?, 1, 0)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $dic, $this->currencyId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->clientIds['customers'][] = $id;
        return $id;
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $items [base, vat, vat_rate_snapshot]
     */
    protected function sale(string $varsymbol, int $clientId, ?string $code, string $tax, array $items): void
    {
        $base = 0.0; $vat = 0.0;
        foreach ($items as $it) { $base += $it[0]; $vat += $it[1]; }
        $with = $base + $vat;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", ?, ?)'
        );
        $stmt->execute([
            $this->supplierId, $varsymbol, $clientId, $tax, $tax, $tax,
            $this->currencyId, $base, $vat, $with, $code, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->invoiceIds[] = $id;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Test položka", 1, "ks", ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $i => $it) {
            [$b, $v, $snap] = $it;
            $stmt->execute([$id, $b, $this->vatRateId, $snap, $b, $v, $b + $v, $i]);
        }
    }

    /** Daňový pokladní doklad (+ DPH řádek). Vrací cash_documents.id. */
    private function cashDoc(string $docType, string $date, float $total, float $base, float $vat, float $rate): int
    {
        $pdo = $this->db->pdo();
        if ($this->cashRegisterId === 0) {
            $stmt = $pdo->prepare(
                'INSERT INTO cash_registers (supplier_id, name, currency_code, account_code, is_active)
                 VALUES (?, ?, "CZK", ?, 1)'
            );
            $stmt->execute([$this->supplierId, 'TEST-AMEND-CASH-' . self::YEAR, '211900']);
            $this->cashRegisterId = (int) $pdo->lastInsertId();
        }
        $purpose = $docType === 'in' ? 'sale' : 'purchase';
        $stmt = $pdo->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, tax_date,
                 description, vat_mode, total_amount, currency_code, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, "Testovací pokladní doklad", "vat", ?, "CZK", "posted", ?)'
        );
        $docNumber = sprintf('TC-%s-%d', self::YEAR, count($this->cashDocIds) + 1);
        $stmt->execute([
            $this->supplierId, $this->cashRegisterId, $docType, $purpose, $docNumber,
            $date, $date, $total, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->cashDocIds[] = $id;
        $pdo->prepare(
            'INSERT INTO cash_document_vat_lines (cash_document_id, vat_rate, base_amount, vat_amount)
             VALUES (?, ?, ?, ?)'
        )->execute([$id, $rate, $base, $vat]);
        return $id;
    }

    private function countryId(string $iso2): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM countries WHERE iso2 = ? LIMIT 1');
        $stmt->execute([$iso2]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }
}

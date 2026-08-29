<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementOutcome;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementResult;
use MyInvoice\Service\Payroll\ControlTotals\PayrollControlTotalsService;
use MyInvoice\Service\Payroll\Document\AnnualPayrollSheetService;
use MyInvoice\Service\Payroll\Document\AnnualSettlementSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\ApprovedRevisionPayslipBatchService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use MyInvoice\Service\Payroll\Document\PayrollDocumentService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentStorage;
use MyInvoice\Service\Payroll\Document\PayrollSheetDocumentData;
use MyInvoice\Service\Payroll\Document\PayrollSheetMonth;
use MyInvoice\Service\Payroll\Document\PayrollSheetPdfRenderer;
use MyInvoice\Service\Payroll\Document\PayrollSheetSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\PayslipDocumentSnapshotHydrator;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Service\Payroll\Posting\PayrollApprovedRevisionPostingService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCalculationPipeline;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunWorkflow;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Mzdový list § 38j odst. 2 od schválené revize po stažené PDF.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Proč tenhle test existuje
 * ─────────────────────────────────────────────────────────────────────────────
 * Doklad prošel za dva dny třemi verzemi schématu a nikdo ho nikdy nepostavil
 * nad skutečnou databází — unit testy krmí sestavovač ručně napsanými poli,
 * takže by rozejití zmrazeného vstupu se čtecím dotazem nezachytily. Tenhle
 * test jede celou cestu: mzdový běh (uzamčení vstupů → výpočet → schválení)
 * → `PayrollSheetSnapshotBuilder::build()` → PDF → archiv → stažení.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Proč se nevolá `AnnualPayrollSheetService::generate()` na hlavní cestě
 * ─────────────────────────────────────────────────────────────────────────────
 * `generate()` si transakci OTEVÍRÁ SÁM a odmítá běžet uvnitř cizí. Test by
 * proto musel commitovat; jenže roční revize i mzdové kumulace mají
 * `BEFORE DELETE` triggery, takže by po každém běhu zůstávaly v testovací
 * databázi neodstranitelné řádky a druhý běh by měřil zbytky prvního. Test
 * proto provádí PŘESNĚ tu sekvenci, kterou `generate()` obaluje (build →
 * render → `archiveAnnualPdf` ve společném úložném scope), a smlouvu
 * `generate()` samotného — že cizí transakci odmítne — ověřuje zvlášť.
 */
#[Group('integration')]
final class PayrollSheetDocumentLifecycleTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2026;

    /** Základní mzda 20 000 Kč — nízká schválně, aby se nárok na zvýhodnění nevešel do daně. */
    private const WAGE_MINOR = 2_000_000;

    /** Cestovní náhrada do limitu § 6 odst. 7 písm. a) — NENÍ předmětem daně. */
    private const TRAVEL_MINOR = 120_000;

    /** Nepeněžní rekreace v koši § 6 odst. 9 písm. d) — JE osvobozený příjem. */
    private const BENEFIT_MINOR = 500_000;

    /** § 35ba odst. 1 písm. a) — měsíční sleva na poplatníka 2 570 Kč. */
    private const TAXPAYER_CREDIT_MINOR = 257_000;

    private ContainerInterface $container;
    private Connection $db;
    private PayrollRunCommandService $runs;
    private PayrollRunRepository $runRepository;
    private PayrollSheetSnapshotBuilder $sheets;
    private PayrollSheetPdfRenderer $renderer;
    private PayrollDocumentService $documents;
    private PayrollDocumentStorage $storage;
    private PayrollStatutoryAccumulatorRepository $accumulators;

    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
    private int $actorId;
    private int $reviewerId;
    /** @var array<int,array{run_id:int,revision_id:int}> */
    private array $approvedMonths = [];

    private string $dataDir;
    private string|false $previousDataDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        $this->dataDir = sys_get_temp_dir()
            . '/myucto-payroll-sheet-lifecycle-'
            . bin2hex(random_bytes(6));
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);

        $container = Bootstrap::buildContainer();
        $this->container = $container;
        $db = $container->get(Connection::class);
        $sheets = $container->get(PayrollSheetSnapshotBuilder::class);
        $documents = $container->get(PayrollDocumentService::class);
        $storage = $container->get(PayrollDocumentStorage::class);
        $runRepository = $container->get(PayrollRunRepository::class);
        $accumulators = $container->get(PayrollStatutoryAccumulatorRepository::class);
        $policies = $container->get(PayrollEmployerPolicyRepository::class);
        self::assertInstanceOf(Connection::class, $db);
        self::assertInstanceOf(PayrollSheetSnapshotBuilder::class, $sheets);
        self::assertInstanceOf(PayrollDocumentService::class, $documents);
        self::assertInstanceOf(PayrollDocumentStorage::class, $storage);
        self::assertInstanceOf(PayrollRunRepository::class, $runRepository);
        self::assertInstanceOf(
            PayrollStatutoryAccumulatorRepository::class,
            $accumulators,
        );
        self::assertInstanceOf(PayrollEmployerPolicyRepository::class, $policies);
        $this->db = $db;
        $this->sheets = $sheets;
        $this->renderer = new PayrollSheetPdfRenderer();
        $this->documents = $documents;
        $this->storage = $storage;
        $this->runRepository = $runRepository;
        $this->accumulators = $accumulators;

        foreach ([
            'payroll_runs',
            'payroll_annual_document_revisions',
            'payroll_annual_settlement_requests',
            'payroll_person_tax_child_claims',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Mzdové migrace neproběhly.');
            }
        }

        // Schvalování s dávkou pásek odmítá běžet ve vnořené transakci, takže
        // běh řídí služba bez ní; pásky se v testu vydávají samostatně přes
        // `ApprovedRevisionPayslipBatchService`, který SAVEPOINT umí.
        $posting = $this->createStub(PayrollApprovedRevisionPostingService::class);
        $posting->method('post')->willReturn([]);
        $this->runs = new PayrollRunCommandService(
            $db,
            $runRepository,
            $container->get(PayrollRunSnapshotBuilder::class),
            $container->get(PayrollRunCalculationPipeline::class),
            $container->get(PayrollRunWorkflow::class),
            $container->get(PayrollPeriodOwnershipService::class),
            $posting,
            null,
            $container->get(PayrollControlTotalsService::class),
        );

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma.');
        }
        $pdo->beginTransaction();
        $this->seedEmployer($pdo, $sourceSupplierId, $policies);
        $this->seedPerson($pdo);
        $this->seedZeroOpenings();
        foreach ([5, 6] as $month) {
            $this->seedMonthlyInputs($month);
            $this->approveMonth($month);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
        $this->removeDirectory($this->dataDir);
        $this->previousDataDir === false
            ? putenv('MYINVOICE_DATA_DIR')
            : putenv('MYINVOICE_DATA_DIR=' . $this->previousDataDir);
        parent::tearDown();
    }

    /**
     * Celá cesta dokladu a údaje, které se v posledních dvou verzích mapování
     * měnily: osvobozené částky, základ srážkové daně, den nástupu a rozdíl
     * mezi nárokem na daňové zvýhodnění a jeho uplatněním.
     */
    public function testBuildRendersArchivesAndDownloadsTheSheet(): void
    {
        $sheet = $this->buildSheet();
        $document = $sheet['document'];
        self::assertInstanceOf(PayrollSheetDocumentData::class, $document);

        self::assertSame(1, (int) $sheet['revision']['revision_no']);
        self::assertNull($sheet['revision']['previous_revision_id']);
        self::assertSame(
            PayrollSheetSnapshotBuilder::PURPOSE,
            $sheet['revision']['purpose'],
        );

        self::assertCount(2, $document->months);
        self::assertSame([5, 6], array_map(
            static fn (PayrollSheetMonth $month): int => $month->month,
            $document->months,
        ));

        $may = $document->months[0];
        self::assertSame(
            PayrollSheetMonth::TAX_DETAIL_RECORDED,
            $may->taxDetailStatus,
        );
        self::assertSame(
            PayrollSheetMonth::CHILD_DETAIL_RECORDED,
            $may->childDetailStatus,
        );

        // § 38j odst. 2 písm. f) bod 2 — osvobozená je JEN složka z koše.
        // Cestovní náhrada předmětem daně vůbec není (§ 6 odst. 7), takže mezi
        // osvobozené částky nepatří; kdyby se sem přičetla, doklad by tvrdil,
        // že poplatník měl osvobozený příjem, který nikdy nevznikl.
        self::assertSame(self::BENEFIT_MINOR, $may->taxExemptIncomeMinorUnits);
        self::assertNotSame(
            self::BENEFIT_MINOR + self::TRAVEL_MINOR,
            $may->taxExemptIncomeMinorUnits,
        );
        self::assertSame(
            self::WAGE_MINOR + self::TRAVEL_MINOR + self::BENEFIT_MINOR,
            $may->grossMinorUnits,
        );

        // § 38j odst. 2 písm. f) bod 3 — zálohový základ je z čeho daň vznikla.
        self::assertSame(self::WAGE_MINOR, $may->advanceTaxBaseMinorUnits);
        self::assertSame(0, $may->withholdingTaxBaseMinorUnits);
        self::assertSame(0, $may->withholdingTaxMinorUnits);

        // § 38j odst. 2 písm. f) bod 6 — nárok NENÍ uplatnění. Mzda je nízká
        // schválně: nárok se do daně nevejde a rozpadne se na slevu a bonus.
        self::assertGreaterThan(
            $may->childCreditMinorUnits,
            $may->childEntitlementMinorUnits,
        );
        self::assertSame(
            $may->childCreditMinorUnits + $may->taxBonusMinorUnits,
            $may->childEntitlementMinorUnits,
        );
        self::assertGreaterThan(0, $may->taxBonusMinorUnits);
        self::assertSame(
            max(
                0,
                (int) (ceil(self::WAGE_MINOR * 15 / 100 / 100) * 100)
                    - self::TAXPAYER_CREDIT_MINOR,
            ),
            $may->childCreditMinorUnits,
        );

        // § 38j odst. 2 písm. e) — den nástupu ze ZMRAZENÉHO vztahu.
        self::assertCount(1, $document->employments);
        self::assertSame('2026-02-01', $document->employments[0]['start_date']);
        self::assertSame('2026-02-05', $document->employments[0]['actual_start_date']);
        self::assertNull($document->employments[0]['end_date']);

        self::assertTrue($document->taxDetailComplete());
        self::assertTrue($document->childDetailComplete());
        self::assertSame(
            2 * self::BENEFIT_MINOR,
            $document->totals()['tax_exempt_income_minor_units'],
        );

        // Zúčtování se ještě neprovedlo — doklad to musí POJMENOVAT i s doložením
        // podmínek § 38ch odst. 1 a 3, ne nechat prázdné místo.
        self::assertSame(
            PayrollSheetDocumentData::ANNUAL_SETTLEMENT_NOT_PERFORMED,
            $document->annualSettlementStatus,
        );
        self::assertNull($document->annualSettlement);
        self::assertSame([
            'request_status' => 'requested',
            'prior_employers' => 'none',
            'filing_obligation' => 'none',
            'annual_claims' => 'none',
        ], $document->annualSettlementEvidence);

        // PDF a archiv.
        $archived = $this->archiveSheet($sheet);
        self::assertSame(
            PayrollDocumentKind::PayrollSheet->value,
            $archived['document_kind'],
        );
        self::assertSame(1, (int) $archived['document_revision_no']);
        self::assertSame(
            $document->sourceSnapshotSha256,
            $archived['source_snapshot_hash'],
        );
        self::assertSame(
            PayrollSheetPdfRenderer::VERSION,
            $archived['renderer_version'],
        );
        self::assertSame(
            PayrollSheetSnapshotBuilder::SCHEMA_VERSION,
            $archived['template_version'],
        );

        // Stažení přes jednorázový grant vrací tytéž bajty, které se uložily.
        $grant = $this->documents->issueDownloadGrant(
            $this->supplierId,
            (int) $archived['id'],
            $this->actorId,
        );
        $downloaded = $this->documents->consumeDownload(
            $this->supplierId,
            (int) $archived['id'],
            $this->actorId,
            $grant['token'],
        );
        self::assertStringStartsWith('%PDF-', $downloaded['bytes']);
        self::assertSame(
            $archived['file_sha256'],
            hash('sha256', $downloaded['bytes']),
        );
        self::assertSame(
            (int) $archived['size_bytes'],
            strlen($downloaded['bytes']),
        );
    }

    /**
     * Druhé sestavení nad týmiž zdroji nezaloží druhou revizi ani druhý soubor.
     */
    public function testRebuildOverTheSameSourcesReturnsTheSameRevision(): void
    {
        $first = $this->buildSheet();
        $firstArchived = $this->archiveSheet($first);
        $second = $this->buildSheet();
        $secondArchived = $this->archiveSheet($second);

        self::assertSame(
            (int) $first['revision']['id'],
            (int) $second['revision']['id'],
        );
        self::assertSame(
            $first['document']->sourceSnapshotSha256,
            $second['document']->sourceSnapshotSha256,
        );
        self::assertSame((int) $firstArchived['id'], (int) $secondArchived['id']);
        self::assertSame(1, $this->sheetDocumentCount());
    }

    /**
     * Neměnnost dokladu v praxi.
     *
     * Roční zúčtování se provádí až po posledním měsíci, takže mzdový list
     * vydaný dřív ho nést nemůže. Jakmile se zúčtování zmrazí, změní se
     * ZDROJOVÝ MANIFEST (nese jeho otisk stejně jako verzi mapování), takže
     * se nesmí vrátit původní revize — musí vzniknout DALŠÍ v řetězu. A původní
     * archivované PDF musí zůstat bajt na bajt stejné, protože na tom stojí
     * celá neměnnost dokladů.
     */
    public function testNewSourceManifestChainsAnotherRevisionAndKeepsTheOldPdf(): void
    {
        $first = $this->buildSheet();
        $firstArchived = $this->archiveSheet($first);
        $firstKey = (string) $firstArchived['storage_key'];
        $firstBytes = $this->storage->readVerified($this->supplierId, $firstKey, $this->employeeId);

        $manifest = json_decode(
            (string) $first['revision']['source_manifest_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        // Verze mapování JE součástí manifestu — proto její změna nemůže vrátit
        // dřívější revizi, nýbrž si vynutí další článek řetězu.
        self::assertSame(
            PayrollSheetSnapshotBuilder::MAPPING_VERSION,
            $manifest['mapping_version'],
        );
        self::assertSame(
            PayrollSheetSnapshotBuilder::SCHEMA_VERSION,
            $manifest['document_schema_version'],
        );
        self::assertArrayHasKey('annual_settlement_hash', $manifest);

        $settlement = $this->freezeAnnualSettlement();

        $second = $this->buildSheet();
        self::assertNotSame(
            (int) $first['revision']['id'],
            (int) $second['revision']['id'],
        );
        self::assertSame(2, (int) $second['revision']['revision_no']);
        self::assertSame(
            (int) $first['revision']['id'],
            (int) $second['revision']['previous_revision_id'],
        );

        // § 38j odst. 2 písm. h) — údaje se ČTOU ze zmrazené revize zúčtování
        // a doklad na ni odkazuje jejím otiskem.
        $document = $second['document'];
        self::assertSame(
            PayrollSheetDocumentData::ANNUAL_SETTLEMENT_APPROVED,
            $document->annualSettlementStatus,
        );
        self::assertIsArray($document->annualSettlement);
        self::assertSame(
            (int) $settlement['revision']['id'],
            $document->annualSettlement['revision_id'],
        );
        self::assertSame(
            $settlement['revision']['snapshot_hash'],
            $document->annualSettlement['snapshot_hash'],
        );
        self::assertSame(
            AnnualSettlementOutcome::Overpayment->value,
            $document->annualSettlement['outcome'],
        );
        self::assertSame(12, $document->annualSettlement['completed_months']);

        $secondArchived = $this->archiveSheet($second);
        self::assertNotSame((int) $firstArchived['id'], (int) $secondArchived['id']);
        self::assertSame(2, (int) $secondArchived['document_revision_no']);
        self::assertSame(
            (int) $firstArchived['id'],
            (int) $secondArchived['supersedes_document_id'],
        );

        // Původní PDF se nesmí hnout ani o bajt.
        self::assertSame(
            $firstBytes,
            $this->storage->readVerified($this->supplierId, $firstKey, $this->employeeId),
        );
        self::assertSame(
            $firstArchived['file_sha256'],
            hash('sha256', $firstBytes),
        );
        self::assertNotSame(
            (string) $firstArchived['file_sha256'],
            (string) $secondArchived['file_sha256'],
        );
    }

    /**
     * Snapshot staršího mapování se NEDOPOČÍTÁVÁ. Zdrojové vstupní revize v něm
     * nejsou, takže osvobozené částky, základ srážkové daně ani nárok na
     * zvýhodnění nelze zpětně zjistit — doklad je musí přiznat jako neevidované,
     * ne vytisknout nulu.
     */
    public function testOlderSnapshotVersionsHydrateAsNotRecorded(): void
    {
        $sheet = $this->buildSheet();
        $snapshot = $this->decryptedSnapshot((int) $sheet['revision']['id']);
        $hash = (string) $sheet['revision']['snapshot_hash'];
        $hydrate = new \ReflectionMethod(PayrollSheetSnapshotBuilder::class, 'hydrate');

        $v2 = $hydrate->invoke(
            $this->sheets,
            ['schema_version' => 'payroll-sheet-document.v2'] + $snapshot,
            $hash,
        );
        self::assertInstanceOf(PayrollSheetDocumentData::class, $v2);
        // v2 osvobozené částky a základ srážkové daně UŽ evidovalo…
        self::assertTrue($v2->taxDetailComplete());
        self::assertSame(
            2 * self::BENEFIT_MINOR,
            $v2->totals()['tax_exempt_income_minor_units'],
        );
        // …nárok na zvýhodnění a stav ročního zúčtování ale ne.
        self::assertFalse($v2->childDetailComplete());
        self::assertSame(0, $v2->totals()['child_entitlement_minor_units']);
        self::assertSame(
            PayrollSheetDocumentData::ANNUAL_SETTLEMENT_NOT_RECORDED,
            $v2->annualSettlementStatus,
        );
        self::assertNull($v2->annualSettlement);
        self::assertNull($v2->annualSettlementEvidence);

        $v1 = $hydrate->invoke(
            $this->sheets,
            ['schema_version' => 'payroll-sheet-document.v1'] + $snapshot,
            $hash,
        );
        self::assertInstanceOf(PayrollSheetDocumentData::class, $v1);
        self::assertFalse($v1->taxDetailComplete());
        self::assertFalse($v1->childDetailComplete());
        self::assertSame(0, $v1->totals()['tax_exempt_income_minor_units']);
        self::assertSame(0, $v1->totals()['withholding_tax_base_minor_units']);
        foreach ($v1->months as $month) {
            self::assertSame(
                PayrollSheetMonth::TAX_DETAIL_NOT_RECORDED,
                $month->taxDetailStatus,
            );
        }

        // Neznámé schéma se nedomýšlí vůbec.
        $this->expectException(\DomainException::class);
        $hydrate->invoke(
            $this->sheets,
            ['schema_version' => 'payroll-sheet-document.v9'] + $snapshot,
            $hash,
        );
    }

    /**
     * Výplatní páska a mzdový list jsou dva doklady o téže mzdě. Musí se číst
     * stejně — sdílený `PayrollExemptIncomeSplit` je jediné místo, kde se
     * osvobozená část určuje — a archivovaná páska se nesmí zpětně změnit.
     */
    public function testPayslipAndSheetReadTheSameExemptSplitAndPayslipStaysFrozen(): void
    {
        $payslips = $this->container->get(ApprovedRevisionPayslipBatchService::class);
        $hydrator = $this->container->get(PayslipDocumentSnapshotHydrator::class);
        self::assertInstanceOf(ApprovedRevisionPayslipBatchService::class, $payslips);
        self::assertInstanceOf(PayslipDocumentSnapshotHydrator::class, $hydrator);

        $may = $this->approvedMonths[5];
        $first = $payslips->generate(
            $this->supplierId,
            $may['run_id'],
            $may['revision_id'],
            $this->actorId,
        );
        self::assertCount(1, $first);
        $key = (string) $first[0]['storage_key'];
        $bytes = $this->storage->readVerified($this->supplierId, $key, $this->employeeId);
        self::assertStringStartsWith('%PDF-', $bytes);

        // Zmrazená páska je v revizi ve schématu v2, hydrátor čte v1 i v2.
        $person = $this->personResult($may['revision_id']);
        self::assertSame(
            'payroll-payslip-document.v2',
            $person['payslip_document']['schema_version'],
        );
        $payslip = $hydrator->hydrate(
            $person['payslip_document'],
            (string) $may['revision_id'],
            str_repeat('a', 64),
            '2026-05',
        );

        $reported = 0;
        $notSubject = 0;
        foreach ($payslip->incomeLines as $line) {
            $reported += $line->reportedExemptMinorUnits();
            $notSubject += $line->notSubjectToTaxMinorUnits();
        }
        // Páska ukazuje OBOJÍ — jinak by z ní nešlo přečíst, proč se z náhrady
        // nic nesrazilo. Mzdový list mezi osvobozené počítá jen to první.
        self::assertSame(self::BENEFIT_MINOR, $reported);
        self::assertSame(self::TRAVEL_MINOR, $notSubject);

        $sheet = $this->buildSheet();
        self::assertSame(
            $reported,
            $sheet['document']->months[0]->taxExemptIncomeMinorUnits,
        );
        self::assertSame(
            $payslip->grossMinorUnits,
            $sheet['document']->months[0]->grossMinorUnits,
        );

        // Druhá dávka nad toutéž revizí vrátí týž dokument a soubor nechá být.
        $second = $payslips->generate(
            $this->supplierId,
            $may['run_id'],
            $may['revision_id'],
            $this->actorId,
        );
        self::assertSame((int) $first[0]['id'], (int) $second[0]['id']);
        self::assertSame(
            $bytes,
            $this->storage->readVerified($this->supplierId, $key, $this->employeeId),
        );
    }

    /**
     * Zvednutá verze rendereru pásky musí vydat DALŠÍ ČLÁNEK ŘETĚZU nad toutéž
     * revizí — stejně jako to umí roční archiv. Archivovaná páska přitom zůstává
     * bajt na bajt stejná a opakovaná dávka vrací hotový doklad.
     */
    public function testRendererVersionBumpChainsPayslipAndLeavesArchivedPdfIntact(): void
    {
        $payslips = $this->container->get(ApprovedRevisionPayslipBatchService::class);
        self::assertInstanceOf(ApprovedRevisionPayslipBatchService::class, $payslips);

        $may = $this->approvedMonths[5];
        // Archiv, jak ho zanechala PŘEDCHOZÍ verze rendereru: tentýž zdrojový
        // otisk, jiná verze šablony a jiný idempotentní klíč. Doklady jsou
        // neměnné, takže se stav nedá dodělat úpravou — musí se založit.
        $firstBytes = "%PDF-1.4\nstara paska\n%%EOF\n";
        $firstId = $this->seedLegacyPayslipDocument(
            $may['run_id'],
            $may['revision_id'],
            $firstBytes,
        );
        $firstKey = (string) $this->documentColumn($firstId, 'storage_key');

        $second = $payslips->generate(
            $this->supplierId,
            $may['run_id'],
            $may['revision_id'],
            $this->actorId,
        );
        self::assertCount(1, $second);
        self::assertNotSame($firstId, (int) $second[0]['id']);
        self::assertSame(2, (int) $second[0]['document_revision_no']);
        self::assertSame($firstId, (int) $second[0]['supersedes_document_id']);
        self::assertSame(
            $firstBytes,
            $this->storage->readVerified($this->supplierId, $firstKey, $this->employeeId),
        );

        // Další dávka nad touž revizí a touž verzí vrací hotový doklad.
        $third = $payslips->generate(
            $this->supplierId,
            $may['run_id'],
            $may['revision_id'],
            $this->actorId,
        );
        self::assertSame((int) $second[0]['id'], (int) $third[0]['id']);
    }

    /**
     * Pracovní vztah bez mzdové účtárny musí běh zastavit POJMENOVANOU
     * překážkou u vztahu, ne až hláškou o haléřích při schvalování.
     */
    public function testRunWithoutPayrollOfficeIsBlockedByName(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_employments SET office_id = NULL
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);
        $pdo->prepare(
            'UPDATE payroll_employment_terms SET office_id = NULL
              WHERE supplier_id = ? AND employment_id = ?'
        )->execute([$this->supplierId, $this->employmentId]);

        $this->seedMonthlyInputs(7);
        $run = $this->runs->createRun(
            $this->supplierId,
            sprintf('%04d-07-01', self::YEAR),
            sprintf('%04d-08-15', self::YEAR),
            null,
            $this->actorId,
        );
        $locked = $this->runs->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-office-7',
            $this->actorId,
        );

        $validations = $this->runRepository->validations(
            $this->supplierId,
            (int) $locked->revision['id'],
        );
        $blockers = array_values(array_filter(
            $validations,
            static fn (array $row): bool
                => $row['code'] === 'employment_without_office',
        ));
        self::assertCount(1, $blockers, CanonicalJson::encode($validations));
        self::assertSame('blocker', $blockers[0]['severity']);
        self::assertSame('employment', $blockers[0]['entity_type']);
        self::assertSame($this->employmentId, (int) $blockers[0]['entity_id']);
        self::assertStringContainsString(
            'mzdovou účtárnu',
            (string) $blockers[0]['message'],
        );
        self::assertStringNotContainsString(
            'haléř',
            (string) $blockers[0]['message'],
        );
    }

    /**
     * `AnnualPayrollSheetService::generate()` vlastní transakci sám. Kdyby se
     * dal zavolat uvnitř cizí, vydal by se doklad, který by cizí rollback tiše
     * zrušil — a soubor by na disku zůstal.
     */
    public function testGenerateRefusesToRunInsideAForeignTransaction(): void
    {
        $service = $this->container->get(AnnualPayrollSheetService::class);
        self::assertInstanceOf(AnnualPayrollSheetService::class, $service);
        self::assertTrue($this->db->pdo()->inTransaction());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('musí vlastnit databázovou transakci');
        $service->generate(
            $this->supplierId,
            $this->employeeId,
            self::YEAR,
            $this->actorId,
        );
    }

    /**
     * Údaje § 38j odst. 2, které doklad nenese, musí být na dokladu POJMENOVANÉ.
     *
     * Prázdné místo se u kontroly čte jako nula. Doklad proto musí sám říct,
     * že písm. d) (osoby a důvody uznání slev), písm. f) bod 8 (podíl nebo opce
     * podle § 6 odst. 3) ani věta za bodem 8 (odměna člena orgánu u nerezidenta)
     * v něm nejsou — a proč.
     */
    public function testTheSheetNamesTheDataItDoesNotCarry(): void
    {
        $sheet = $this->buildSheet();
        $html = $this->renderer->renderHtml($sheet['document']);

        foreach ([
            'písm. d)',
            '§ 35ba',
            '§ 15',
            'f) bod 8',
            '§ 6 odst. 3',
            'člena orgánu',
            'nerezident',
            'neznamená nulu',
        ] as $needle) {
            self::assertStringContainsString($needle, mb_strtolower($html), sprintf(
                'Doklad musí pojmenovat údaj, který nenese: chybí „%s".',
                $needle,
            ));
        }

        // Neprovedené zúčtování se pojmenuje i s doložením podmínek § 38ch.
        self::assertStringContainsString('neprovedlo', $html);
        self::assertStringContainsString('žádost „requested"', $html);

        // Slovo „neevidováno" stojí i ve vysvětlivce; v ŘÁDCÍCH téhle revize
        // ale být nesmí — všechny údaje eviduje.
        self::assertSame(1, substr_count($html, 'neevidováno'));
        self::assertStringNotContainsString('neúplné', $html);

        $hydrate = new \ReflectionMethod(PayrollSheetSnapshotBuilder::class, 'hydrate');
        $legacy = $hydrate->invoke(
            $this->sheets,
            ['schema_version' => 'payroll-sheet-document.v1']
                + $this->decryptedSnapshot((int) $sheet['revision']['id']),
            (string) $sheet['revision']['snapshot_hash'],
        );
        self::assertInstanceOf(PayrollSheetDocumentData::class, $legacy);
        $legacyHtml = $this->renderer->renderHtml($legacy);
        // Starší revize tiskne slovo, ne nulu — v každém měsíci a v součtu.
        // Vysvětlivka, hlavička se stavem ročního zúčtování a tři sloupce
        // v každém měsíci: osvobozené částky, nárok na zvýhodnění a základ
        // srážkové daně.
        self::assertSame(
            2 + 3 * count($legacy->months),
            substr_count($legacyHtml, 'neevidováno'),
        );
        self::assertStringContainsString('neúplné', $legacyHtml);
        self::assertStringContainsString(
            'Stav ročního zúčtování není evidován',
            $legacyHtml,
        );
    }

    /** @return array{revision:array<string,mixed>,document:PayrollSheetDocumentData} */
    private function buildSheet(): array
    {
        /** @var array{revision:array<string,mixed>,document:PayrollSheetDocumentData} $built */
        $built = $this->sheets->build(
            $this->supplierId,
            $this->employeeId,
            self::YEAR,
            $this->actorId,
        );

        return $built;
    }

    /**
     * @param array{revision:array<string,mixed>,document:PayrollSheetDocumentData} $sheet
     * @return array<string,mixed>
     */
    private function archiveSheet(array $sheet): array
    {
        $artifact = $this->renderer->render($sheet['document']);
        self::assertStringStartsWith('%PDF-', $artifact->bytes);

        return $this->documents->archiveAnnualPdf(
            $this->supplierId,
            (int) $sheet['revision']['id'],
            $this->employeeId,
            $artifact,
            'annual-payroll-sheet:' . hash('sha256', implode("\0", [
                (string) $this->supplierId,
                (string) $this->employeeId,
                (string) self::YEAR,
                $artifact->sourceSnapshotHash,
                $artifact->rendererVersion,
            ])),
            $this->actorId,
        );
    }

    /**
     * Zmrazí syntetický výsledek ročního zúčtování, aby mzdový list měl co číst
     * do § 38j odst. 2 písm. h). Čísla jsou vnitřně konzistentní — doklad si to
     * sám hlídá (§ 35d odst. 7).
     *
     * @return array{revision:array<string,mixed>,created:bool}
     */
    private function freezeAnnualSettlement(): array
    {
        $builder = $this->container->get(AnnualSettlementSnapshotBuilder::class);
        self::assertInstanceOf(AnnualSettlementSnapshotBuilder::class, $builder);

        $advanceTax = 12_000_00;
        $taxAfterCredits = 9_000_00;
        $result = AnnualSettlementResult::performed(
            self::YEAR,
            AnnualSettlementOutcome::Overpayment,
            roundedTaxBaseMinorUnits: 240_000_00,
            taxBeforeCreditsMinorUnits: 36_000_00,
            annualCreditsMinorUnits: 30_840_00,
            appliedCreditsMinorUnits: 30_840_00,
            childEntitlementMinorUnits: 0,
            childCreditMinorUnits: 0,
            annualTaxBonusMinorUnits: 0,
            taxAfterAllCreditsMinorUnits: $taxAfterCredits,
            taxDifferenceMinorUnits: $advanceTax - $taxAfterCredits,
            bonusDifferenceMinorUnits: 0,
            settlementDifferenceMinorUnits: $advanceTax - $taxAfterCredits,
            payableMinorUnits: $advanceTax - $taxAfterCredits,
            annualBonusThresholdMet: false,
            trace: [
                'completed_months' => 12,
                'advance_base_minor_units' => 240_000_00,
                'advance_tax_minor_units' => $advanceTax,
                'monthly_tax_bonus_minor_units' => 0,
                'external_certificates' => ['count' => 0],
            ],
        );

        /** @var array{revision:array<string,mixed>,created:bool} $built */
        $built = $builder->build(
            $this->supplierId,
            $this->employeeId,
            self::YEAR,
            $result,
            sprintf('%04d-03-10', self::YEAR + 1),
            [['label' => 'Sleva na poplatníka', 'amount_minor_units' => 30_840_00]],
            [],
            $this->actorId,
        );
        self::assertTrue($built['created']);

        return $built;
    }

    /** @return array<string,mixed> */
    private function decryptedSnapshot(int $revisionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_annual_document_revisions
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$this->supplierId, $revisionId]);
        $revision = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($revision);

        $method = new \ReflectionMethod(
            PayrollSheetSnapshotBuilder::class,
            'decryptSnapshot',
        );
        $snapshot = $method->invoke($this->sheets, $revision);
        self::assertIsArray($snapshot);

        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function personResult(int $revisionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT result_json FROM payroll_run_persons
              WHERE supplier_id = ? AND revision_id = ? AND employee_id = ?'
        );
        $statement->execute([$this->supplierId, $revisionId, $this->employeeId]);
        $json = $statement->fetchColumn();
        self::assertIsString($json);
        $person = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($person);

        return $person;
    }

    private function sheetDocumentCount(): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_generated_documents
              WHERE supplier_id = ? AND document_kind = ?'
        );
        $statement->execute([
            $this->supplierId,
            PayrollDocumentKind::PayrollSheet->value,
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Výplatní páska téže revize archivovaná starší verzí rendereru.
     */
    private function seedLegacyPayslipDocument(
        int $runId,
        int $revisionId,
        string $bytes,
    ): int {
        $pdo = $this->db->pdo();
        $revision = $pdo->prepare(
            'SELECT result_snapshot_hash FROM payroll_run_revisions
              WHERE supplier_id = ? AND id = ?'
        );
        $revision->execute([$this->supplierId, $revisionId]);
        $revisionHash = (string) $revision->fetchColumn();
        $person = $pdo->prepare(
            'SELECT result_hash FROM payroll_run_persons
              WHERE supplier_id = ? AND revision_id = ? AND employee_id = ?'
        );
        $person->execute([$this->supplierId, $revisionId, $this->employeeId]);
        $sourceHash = (string) $person->fetchColumn();
        self::assertNotSame('', $revisionHash);
        self::assertNotSame('', $sourceHash);

        $stored = $this->storage->store(
            $this->supplierId,
            $bytes,
            null,
            $this->employeeId,
        );
        $pdo->prepare(
            'INSERT INTO payroll_generated_documents
                (supplier_id, run_id, revision_id, employee_id, document_kind,
                 document_revision_no, source_snapshot_hash, revision_snapshot_hash,
                 template_version, renderer_version, file_sha256, size_bytes,
                 mime_type, storage_key, suggested_filename, idempotency_key_hash,
                 created_by)
             VALUES (?, ?, ?, ?, "payslip", 1, ?, ?,
                     "mz-16-payslip-v0", "mz-16-payslip-v0", ?, ?,
                     "application/pdf", ?, "payslip-legacy.pdf", UNHEX(?), ?)'
        )->execute([
            $this->supplierId,
            $runId,
            $revisionId,
            $this->employeeId,
            $sourceHash,
            $revisionHash,
            $stored['file_sha256'],
            $stored['size_bytes'],
            $stored['storage_key'],
            hash('sha256', 'approved-payslip:predchozi-verze'),
            $this->actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function documentColumn(int $documentId, string $column): mixed
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $column . ' FROM payroll_generated_documents
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$this->supplierId, $documentId]);

        return $stmt->fetchColumn();
    }

    private function approveMonth(int $month): void
    {
        $periodStart = sprintf('%04d-%02d-01', self::YEAR, $month);
        $run = $this->runs->createRun(
            $this->supplierId,
            $periodStart,
            sprintf('%04d-%02d-15', self::YEAR, $month + 1),
            null,
            $this->actorId,
        );
        $locked = $this->runs->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-sheet-' . $month,
            $this->actorId,
        );
        $calculated = $this->runs->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-sheet-' . $month,
            $this->actorId,
        );
        self::assertSame(
            'calculated',
            $calculated->revision['result_snapshot']['statutory']['status'] ?? null,
            sprintf(
                "Zákonný výpočet měsíce %d nedoběhl:\n%s",
                $month,
                CanonicalJson::encode($calculated->revision['result_snapshot']),
            ),
        );
        $reviewed = $this->runs->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'review-sheet-' . $month,
            $this->reviewerId,
        );
        $approved = $this->runs->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'approve-sheet-' . $month,
            $this->reviewerId,
        );
        self::assertSame('approved', $approved->run['status']);

        $this->approvedMonths[$month] = [
            'run_id' => (int) $run['id'],
            'revision_id' => (int) $approved->revision['id'],
        ];
    }

    private function seedEmployer(
        PDO $pdo,
        int $sourceSupplierId,
        PayrollEmployerPolicyRepository $policies,
    ): void {
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier
                SET payroll_enabled = 1,
                    company_name = "Syntetická mzdová s.r.o.",
                    display_name = "Syntetický zaměstnavatel",
                    ic = "12345678",
                    street = "Testovací 12",
                    city = "Testov",
                    zip = "10000"
              WHERE id = ?'
        )->execute([$this->supplierId]);
        $this->actorId = $this->createActor();
        $this->reviewerId = $this->createActor();
        $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())'
        )->execute([$this->supplierId, $this->actorId]);
        $policies->create($this->supplierId, [
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'payday_day' => 15,
            'payday_month_offset' => 1,
            'payday_business_day_rule' => 'previous_business_day',
            'balance_rounding_mode' => 'exact_minor_units',
            'home_office_policy' => 'not_used',
            'travel_expense_policy' => 'not_used',
            'four_eyes_required' => true,
            'automatic_calculation_enabled' => true,
            'automatic_posting_enabled' => false,
            'automatic_payments_enabled' => false,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:payroll-sheet-policy',
        ], $this->actorId);
    }

    private function seedPerson(PDO $pdo): void
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická mzdová osoba", "employee", 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "ready")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 effective_from)
             VALUES (?, ?, "Syntetická mzdová osoba", "Syntetická", "Osoba",
                     "2026-01-01")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city,
                 postal_code, country_code, effective_from)
             VALUES (?, ?, "residence", "Zkušební 5", "Testov", "10000", "CZ",
                     "2026-01-01")'
        )->execute([$this->supplierId, $this->employeeId]);

        $sensitive = $this->container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        $pdo->prepare(
            'INSERT INTO payroll_person_identifiers
                (supplier_id, employee_id, identifier_type, value_ciphertext,
                 value_hash, value_masked)
             VALUES (?, ?, "birth_number", "enc:v2:synthetic", ?, "••••0009")'
        )->execute([$this->supplierId, $this->employeeId, random_bytes(32)]);
        $identifierId = (int) $pdo->lastInsertId();
        $sealed = $sensitive->seal(
            '0001010009',
            PayrollSensitiveField::PERSONAL_IDENTIFIER,
            $this->supplierId,
            $identifierId,
        );
        $pdo->prepare(
            'UPDATE payroll_person_identifiers
                SET value_ciphertext = ?, value_hash = ?, value_masked = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([
            $sealed->ciphertext,
            $sealed->lookupHash,
            $sealed->masked,
            $this->supplierId,
            $identifierId,
        ]);

        $pdo->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, is_active)
             VALUES (?, "LIST", "Syntetická účtárna", 1)'
        )->execute([$this->supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from,
                 social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2026-01-01", "0012345678", "synthetic:payroll-sheet")'
        )->execute([$this->supplierId, $officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_primary)
             VALUES (?, ?, ?, "SYN-LIST", "employment", "active",
                     "2026-02-01", "2026-02-05", ?, 1)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $officeId,
            self::WAGE_MINOR,
        ]);
        $this->employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from,
                 planned_start_on,
                 actual_start_on, weekly_hours, workload_basis_points,
                 social_insurance_participation,
                 health_insurance_participation, tax_regime,
                 other_withholding_eligibility,
                 tax_declaration_signed, is_primary)
             VALUES (?, ?, ?, "2026-02-01", "2026-02-01", "2026-02-05",
                     40, 10000, "automatic", "automatic", "advance",
                     "ineligible", 1, 1)'
        )->execute([$this->supplierId, $this->employmentId, $officeId]);

        $pdo->prepare(
            'INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, effective_from, evidence_reference)
             VALUES (?, ?, "signed", "2026-01-01", "document:tax-declaration")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_residences
                (supplier_id, employee_id, residence, country_code,
                 effective_from, evidence_reference)
             VALUES (?, ?, "czech-resident", "CZ", "2026-01-01",
                     "document:tax-residence")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_credit_claims
                (supplier_id, employee_id, credit_kind, evidence_status,
                 effective_from, evidence_reference)
             VALUES (?, ?, "taxpayer", "verified", "2026-01-01",
                     "document:taxpayer-credit")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_child_claims
                (supplier_id, employee_id, child_reference, child_order, ztp_p,
                 evidence_status, shared_household_confirmed,
                 other_claimant_excluded, effective_from, evidence_reference)
             VALUES (?, ?, "DITE-1", 1, 0, "verified", 1, 1, "2026-01-01",
                     "document:child-claim")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction, insurer_status,
                 insurer_code, insurer_evidence_reference, effective_from)
             VALUES (?, ?, "czech_regime_verified", "verified", "111",
                     "document:health-insurer", "2026-01-01")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_social_jurisdictions
                (supplier_id, employee_id, jurisdiction, a1_status, effective_from)
             VALUES (?, ?, "czech_regime_verified", "not_applicable", "2026-01-01")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_social_discount_claims
                (supplier_id, employee_id, status, effective_from, evidence_reference)
             VALUES (?, ?, "not_claimed", "2026-01-01", NULL)'
        )->execute([$this->supplierId, $this->employeeId]);
        foreach ([5, 6] as $month) {
            $pdo->prepare(
                'INSERT INTO payroll_person_health_month_evidence
                    (supplier_id, employee_id, period_start, top_up_responsibility)
                 VALUES (?, ?, ?, "employee")'
            )->execute([
                $this->supplierId,
                $this->employeeId,
                sprintf('%04d-%02d-01', self::YEAR, $month),
            ]);
        }

        // § 38ch odst. 1 a 3 — doklad o posouzení podmínek. Bez něj by mzdový
        // list u neprovedeného zúčtování nemohl uvést ani důvod.
        $pdo->prepare(
            'INSERT INTO payroll_annual_settlement_requests
                (supplier_id, employee_id, tax_year, request_status,
                 requested_on, request_evidence_reference, prior_employers,
                 filing_obligation, annual_claims)
             VALUES (?, ?, ?, "requested", "2027-02-05", "synthetic-request",
                     "none", "none", "none")'
        )->execute([$this->supplierId, $this->employeeId, self::YEAR]);
    }

    private function seedZeroOpenings(): void
    {
        $this->accumulators->appendOpeningBalance(
            $this->supplierId,
            $this->employeeId,
            self::YEAR,
            'social_insurance',
            ['assessment_base_minor_units' => 0],
            'synthetic:social-opening',
            ['verified_zero' => true],
            'payroll-sheet-social-opening',
            actorUserId: $this->actorId,
        );
        $this->accumulators->appendOpeningBalance(
            $this->supplierId,
            $this->employeeId,
            self::YEAR,
            'income_tax',
            [
                'completed_months' => 0,
                'advance_base_minor_units' => 0,
                'withholding_base_minor_units' => 0,
                'advance_tax_minor_units' => 0,
                'withholding_tax_minor_units' => 0,
                'applied_non_refundable_credits_minor_units' => 0,
                'applied_child_credit_minor_units' => 0,
                'tax_bonus_minor_units' => 0,
                'bonus_qualifying_income_minor_units' => 0,
            ],
            'synthetic:tax-opening',
            ['verified_zero' => true],
            'payroll-sheet-tax-opening',
            actorUserId: $this->actorId,
        );
    }

    private function seedMonthlyInputs(int $month): void
    {
        $period = sprintf('%04d-%02d-01', self::YEAR, $month);
        $this->seedInput(
            'MZDA_' . $month,
            'Základní mzda',
            'base_wage',
            [
                'tax_treatment' => 'included',
                'social_treatment' => 'included',
                'health_treatment' => 'included',
            ],
            self::WAGE_MINOR,
            $period,
        );
        $this->seedInput(
            'CESTOVNE_' . $month,
            'Cestovní náhrada do zákonného limitu',
            'travel_reimbursement',
            [
                'tax_treatment' => 'exempt',
                'exemption_basis' => 'not_subject_to_tax',
                'social_treatment' => 'excluded',
                'social_participation_treatment' => 'excluded',
                'health_treatment' => 'excluded',
                'health_participation_treatment' => 'excluded',
                'average_earning_treatment' => 'excluded',
                'enforcement_treatment' => 'excluded',
            ],
            self::TRAVEL_MINOR,
            $period,
        );
        $this->seedInput(
            'REKREACE_' . $month,
            'Nepeněžní rekreace v koši',
            'benefit_recreation',
            [
                'tax_treatment' => 'exempt',
                'exemption_basis' => 'benefit_basket',
                'exemption_basket' => 'non_cash_leisure',
                'value_kind' => 'non_monetary',
                'social_treatment' => 'included',
                'health_treatment' => 'included',
                'average_earning_treatment' => 'excluded',
                // Nepeněžní plnění není z čeho srazit — do základu srážek
                // nepatří, jinak by výsledek srážek hlásil nesoulad s peněžní
                // částkou k výplatě.
                'enforcement_treatment' => 'excluded',
            ],
            self::BENEFIT_MINOR,
            $period,
            [
                'benefit_basket' => 'non_cash_leisure',
                'benefit_exempt_minor' => self::BENEFIT_MINOR,
                'benefit_taxable_minor' => 0,
            ],
        );
    }

    /**
     * @param array<string,string|null> $overrides
     * @param array<string,int|string> $split
     */
    private function seedInput(
        string $code,
        string $name,
        string $kind,
        array $overrides,
        int $amountMinor,
        string $period,
        array $split = [],
    ): void {
        $pdo = $this->db->pdo();
        $classification = [
            'component_kind' => $kind,
            'value_kind' => 'monetary',
            'frequency_kind' => 'one_off',
            'tax_treatment' => 'included',
            'social_participation_treatment' => 'included',
            'social_treatment' => 'included',
            'health_participation_treatment' => 'included',
            'health_treatment' => 'included',
            'average_earning_treatment' => 'included',
            'enforcement_treatment' => 'included',
            'jmhz_treatment' => 'included',
            'statistics_treatment' => 'included',
            'exemption_basket' => null,
            'exemption_basis' => null,
            ...$overrides,
        ];
        $columns = array_keys($classification);
        $pdo->prepare(sprintf(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, %s,
                 accounting_debit_code, accounting_credit_code, valid_from)
             VALUES (?, ?, ?, %s, "521", "331", "2026-01-01")',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ))->execute([
            $this->supplierId,
            $code,
            $name,
            ...array_values($classification),
        ]);
        $componentId = (int) $pdo->lastInsertId();
        $snapshot = [
            'code' => $code,
            'name' => $name,
            ...$classification,
            'accounting_debit_code' => '521',
            'accounting_credit_code' => '331',
            'annual_limit_minor' => null,
            'component_id' => $componentId,
            'component_row_version' => 1,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ];
        $json = CanonicalJson::encode($snapshot);
        $pdo->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id,
                 period_start, amount_minor, source_kind, status,
                 component_snapshot_json, component_snapshot_hash,
                 benefit_basket, benefit_exempt_minor, benefit_taxable_minor,
                 approved_by, approved_at)
             VALUES (?, ?, ?, ?, ?, ?, "manual", "approved", ?, ?,
                     ?, ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            $componentId,
            $period,
            $amountMinor,
            $json,
            hash('sha256', $json, true),
            $split['benefit_basket'] ?? null,
            $split['benefit_exempt_minor'] ?? null,
            $split['benefit_taxable_minor'] ?? null,
            $this->actorId,
        ]);
    }

    private function createActor(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Synthetic payroll sheet actor", "readonly", "cs", 1)'
        );
        $stmt->execute([
            'payroll-sheet-' . bin2hex(random_bytes(6)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            if (!$item instanceof \SplFileInfo) {
                throw new \RuntimeException('Dočasný adresář obsahuje neplatnou položku.');
            }
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

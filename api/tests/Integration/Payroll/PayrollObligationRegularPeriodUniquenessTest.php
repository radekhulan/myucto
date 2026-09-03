<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * ČSSZ přijme za jedno rozhodné období jediné ŘÁDNÉ podání JMHZ; druhé zamítne
 * kódem 40326 a vzít zpět se to nedá. Idempotenční klíč povinnosti to sám
 * neuhlídá — nese přípravu a otisk snapshotu, takže druhá příprava za totéž
 * období je pro něj nový vstup.
 *
 * Test drží OBĚ patra pojistky: aplikační guard (srozumitelná věta) i klíč
 * `uq_payroll_obligations_regular_period` z migrace 1731 (pojistka pro cesty,
 * které guard obejdou), a k tomu obě legitimní výjimky — opravu a řádné
 * hlášení po zamítnutí.
 */
#[Group('integration')]
final class PayrollObligationRegularPeriodUniquenessTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD_START = '2026-07-01';
    private const PERIOD_END = '2026-07-31';

    private Connection $db;
    private PayrollSubmissionRepository $repository;
    private PayrollObligationService $obligations;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->db = $connection;
        $pdo = $connection->pdo();
        $sourceSupplier = $pdo->query('SELECT MIN(id) FROM supplier');
        self::assertInstanceOf(\PDOStatement::class, $sourceSupplier);
        $sourceSupplierId = (int) $sourceSupplier->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->repository = new PayrollSubmissionRepository($connection);
        $this->obligations = new PayrollObligationService(
            $this->repository,
            new MockClock('2026-08-04 10:11:12 Europe/Prague'),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testSecondRegularObligationForSamePeriodIsRefused(): void
    {
        $first = $this->registerRegular('jmhz25-regular:priprava-1');
        self::assertTrue($first['created']);

        try {
            $this->registerRegular('jmhz25-regular:priprava-2');
            self::fail(
                'Druhé řádné hlášení za totéž období nesmí založit povinnost.',
            );
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'opravným hlášením',
                $exception->getMessage(),
            );
            self::assertStringContainsString(
                self::PERIOD_START,
                $exception->getMessage(),
            );
        }

        self::assertSame(1, $this->countRegularObligations());
    }

    public function testReplayOfTheSamePreparationStillReturnsTheOriginal(): void
    {
        $first = $this->registerRegular('jmhz25-regular:priprava-1');
        $replay = $this->registerRegular('jmhz25-regular:priprava-1');

        self::assertTrue($first['created']);
        self::assertFalse($replay['created']);
        self::assertSame($first['id'], $replay['id']);
    }

    public function testCorrectionForTheSamePeriodIsAllowed(): void
    {
        $regular = $this->registerRegular('jmhz25-regular:priprava-1');
        $correction = $this->register(
            'jmhz25-correction:priprava-2',
            JmhzSubmissionBridgeService::AGENDA_CODE,
            'correction',
        );

        self::assertTrue($correction['created']);
        self::assertNotSame($regular['id'], $correction['id']);
    }

    /**
     * Zamítnuté řádné podání nemá platný kořen a podle
     * `PayrollAgendaCorrectionPolicy` po něm následuje NOVÉ řádné podání.
     * Povinnost je v ten okamžik v `manual_review`, tedy mimo klíč.
     */
    public function testRejectedRegularObligationReleasesThePeriod(): void
    {
        $first = $this->registerRegular('jmhz25-regular:priprava-1');
        $this->repository->updateObligationStatus(
            $this->supplierId,
            'production',
            $first['id'],
            $first['row_version'],
            'manual_review',
        );

        $second = $this->registerRegular('jmhz25-regular:priprava-2');

        self::assertTrue($second['created']);
        self::assertNotSame($first['id'], $second['id']);
    }

    /**
     * Oznamovací povinnost vůči zdravotní pojišťovně je vázaná na UDÁLOST, ne
     * na období: jeden pracovní poměr může mít v týž den víc řádných
     * povinností. Klíč se jich nesmí ani dotknout.
     */
    public function testEventBasedAgendaKeepsBothRegularObligations(): void
    {
        $first = $this->register(
            'health-notification:udalost-1',
            HealthInsuranceSchemaCatalog::HOZ,
            'regular',
            'employment:31337',
            'employment',
        );
        $second = $this->register(
            'health-notification:udalost-2',
            HealthInsuranceSchemaCatalog::HOZ,
            'regular',
            'employment:31337',
            'employment',
        );

        self::assertTrue($first['created']);
        self::assertTrue($second['created']);
        self::assertNotSame($first['id'], $second['id']);
    }

    /**
     * Pojistka pod aplikačním guardem: i zápis, který jde do repozitáře přímo
     * (dávkové zakládání povinností), musí na druhém řádném hlášení narazit.
     */
    public function testDatabaseKeyRefusesSecondRegularObligation(): void
    {
        $this->registerRegular('jmhz25-regular:priprava-1');

        $this->expectException(\PDOException::class);
        $this->repository->insertObligation(
            $this->supplierId,
            'production',
            JmhzSubmissionBridgeService::AGENDA_CODE,
            'payroll_run',
            'payroll_run:31337',
            self::PERIOD_START,
            self::PERIOD_END,
            'regular',
            'vrep_apep',
            JmhzSubmissionBridgeService::SOURCE_EVENT_TYPE,
            'jmhz_preparation:2',
            str_repeat('e', 64),
            str_repeat('f', 64),
            hash('sha256', 'jmhz25-regular:mimo-guard', true),
            null,
            null,
        );
    }

    /**
     * Katalog agend žije na dvou místech, protože jedno z nich je generovaný
     * sloupec v migraci. Rozejít se nesmějí: guard by pak pouštěl, co klíč
     * zamítne, nebo naopak.
     */
    public function testSchemaAgendaListMatchesTheServiceCatalog(): void
    {
        $statement = $this->db->pdo()->prepare(
            "SELECT GENERATION_EXPRESSION
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'payroll_obligations'
                AND COLUMN_NAME = 'regular_period_scope_on'",
        );
        $statement->execute();
        $expression = $statement->fetchColumn();
        self::assertIsString(
            $expression,
            'Generovaný sloupec regular_period_scope_on ve schématu chybí.',
        );

        $matches = [];
        self::assertGreaterThan(
            0,
            preg_match_all("/'([^']*)'/", $expression, $matches),
            'Výraz sloupce nenese očekávané literály.',
        );
        $agendas = array_values(array_diff(
            $matches[1],
            ['regular', 'manual_review', 'cancelled'],
        ));
        sort($agendas);
        $expected = PayrollObligationService::UNIQUE_REGULAR_PERIOD_AGENDAS;
        sort($expected);

        self::assertSame($expected, $agendas);
    }

    /** @return array{id:int,due_on:string,status:string,row_version:int,created:bool} */
    private function registerRegular(string $idempotencyKey): array
    {
        return $this->register(
            $idempotencyKey,
            JmhzSubmissionBridgeService::AGENDA_CODE,
            'regular',
        );
    }

    /** @return array{id:int,due_on:string,status:string,row_version:int,created:bool} */
    private function register(
        string $idempotencyKey,
        string $agendaCode,
        string $obligationKind,
        string $subjectReference = 'payroll_run:31337',
        string $subjectType = 'payroll_run',
    ): array {
        return $this->obligations->register(
            $this->supplierId,
            $agendaCode,
            $subjectType,
            $subjectReference,
            self::PERIOD_START,
            self::PERIOD_END,
            $obligationKind,
            'vrep_apep',
            JmhzSubmissionBridgeService::SOURCE_EVENT_TYPE,
            'jmhz_preparation:1',
            hash('sha256', $idempotencyKey),
            '2026-08-01',
            '2026-08-20',
            'calendar_days',
            'jmhz-deadline-test',
            str_repeat('d', 64),
            $idempotencyKey,
        );
    }

    private function countRegularObligations(): int
    {
        $statement = $this->db->pdo()->prepare(
            "SELECT COUNT(*)
               FROM payroll_obligations
              WHERE supplier_id = ?
                AND agenda_code = ?
                AND obligation_kind = 'regular'
                AND period_start = ?",
        );
        $statement->execute([
            $this->supplierId,
            JmhzSubmissionBridgeService::AGENDA_CODE,
            self::PERIOD_START,
        ]);

        return (int) $statement->fetchColumn();
    }
}

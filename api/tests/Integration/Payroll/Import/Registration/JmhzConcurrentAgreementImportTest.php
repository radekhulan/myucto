<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Registration;

use MyInvoice\Action\Payroll\PayrollRegistrationImportAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportLookup;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportService;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverReader;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\JmhzReportFixtures;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\RegistrationXmlFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Souběh pracovního poměru a dohody téže osoby v importu měsíčních hlášení.
 *
 * Osoba má v evidenci pracovní poměr s OIČ a ID PPV. Hlášení za leden až březen
 * nesou vedle něj formulář DPP s JINÝM ID PPV — bez druhu činnosti, bez kódu
 * ELDP, s příjmem z nepojištěné činnosti (tak ji hlásí cizí programy). Dřív
 * formulář DPP skončil jako „přiřadit vztah" s pracovním poměrem jako jediným
 * kandidátem a odvozená věta vztahu se zablokovala; měsíce DPP se tiše
 * nepřevzaly, nebo — po ručním přiřazení — přepsaly pracovní poměr.
 */
#[Group('integration')]
final class JmhzConcurrentAgreementImportTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PPV_HPP = '200000000000000000101';
    private const PPV_DPP = '200000000000000000102';
    private const DPP_INCOME = 3_960;

    private Connection $db;
    private ContainerInterface $container;
    private RegistrationImportService $imports;
    private int $supplierId;
    private int $userId;
    private string $oic;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 6) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $this->container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $this->container->get(Connection::class);
        $this->imports = $this->container->get(RegistrationImportService::class);
        if (!$this->db->hasTable('payroll_person_external_ids')) {
            self::markTestSkipped('Chybí tabulka payroll_person_external_ids.');
        }
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare("UPDATE supplier SET payroll_enabled = 1, accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetická účetní", "readonly", "cs", 1)'
        )->execute([
            'jmhz-soubeh-' . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);
        $this->userId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, 'IMPORT', 'Syntetická účtárna', '1234567890', 1)"
        )->execute([$this->supplierId]);
        $pdo->prepare('INSERT INTO payroll_employer_settings (supplier_id, default_office_id) VALUES (?, ?)')
            ->execute([$this->supplierId, (int) $pdo->lastInsertId()]);
        $pdo->prepare("INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, 'active', '2026-04-01')")
            ->execute([$this->supplierId]);
        $this->oic = RegistrationXmlFixtures::oic(7);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testConcurrentAgreementIsANewEmploymentOfTheSamePerson(): void
    {
        [$employeeId, $hppId] = $this->registerEmployee();
        $files = $this->reports();

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        $dppForms = $this->forms($preview['records'], self::PPV_DPP);
        self::assertCount(3, $dppForms);
        foreach ($dppForms as $form) {
            self::assertSame('create_employment', $form['operation'], $this->dump($form));
            self::assertSame('new', $form['match']['status']);
            self::assertSame($employeeId, $form['match']['employee_id']);
            self::assertSame([], $form['match']['candidates'], 'Pracovní poměr s jiným ID PPV kandidátem není.');
            self::assertTrue($form['selectable']);
        }
        $derived = $this->derived($preview['records'], self::PPV_DPP);
        self::assertSame('create_employment', $derived['operation'], $this->dump($derived));
        self::assertNull($derived['blocker']);
        self::assertSame('dpp', $derived['employment']['relation_type']);
        self::assertSame(['dpp', 'dpc'], $derived['employment']['relation_type_options']);

        $months = array_column($preview['takeover']['months'], null, 'period');
        self::assertSame('partial', $months['2026-01']['status'], $this->dump($months['2026-01']));
        self::assertSame(1, $months['2026-01']['ready_count']);
        self::assertCount(1, $months['2026-01']['blocked']);

        $keys = array_column(array_filter($preview['records'], static fn (array $r): bool => $r['selectable']), 'key');
        $applied = $this->apply($files, $keys, takeover: true);
        self::assertSame('complete', $applied['outcome'], $this->dump($applied['unresolved']));
        self::assertSame([], $applied['unresolved']);

        $lookup = $this->container->get(RegistrationImportLookup::class);
        $employments = array_column($lookup->employments($this->supplierId, $employeeId), null, 'id');
        self::assertCount(2, $employments);
        $dppId = (int) array_key_first(array_diff_key($employments, [$hppId => true]));
        self::assertSame('dpp', $employments[$dppId]['relation_type']);
        self::assertFalse((bool) $employments[$dppId]['is_primary'], 'Souběžná dohoda není hlavní vztah.');
        self::assertSame('employment', $employments[$hppId]['relation_type']);

        self::assertSame(
            [[(string) $hppId, '3', '12000000', '1'], [(string) $dppId, '3', (string) (3 * self::DPP_INCOME * 100), '0']],
            $this->fetch(
                'SELECT employment_id, COUNT(*), SUM(gross_minor), MAX(pension_participation)
                   FROM payroll_migration_reference_totals WHERE supplier_id = ? GROUP BY employment_id ORDER BY employment_id',
            ),
            'Měsíce DPP se převzaly k DPP a pracovní poměr zůstal nedotčený.',
        );
    }

    public function testManualPairToEmploymentWithAnotherIdPpvIsRefused(): void
    {
        [, $hppId] = $this->registerEmployee();
        $files = $this->reports();
        $key = $this->forms($this->imports->preview($this->supplierId, 'test', $files)['records'], self::PPV_DPP)[0]['key'];

        $paired = $this->imports->preview($this->supplierId, 'test', $files, [['key' => $key, 'employment_id' => $hppId]]);
        $form = array_column($paired['records'], null, 'key')[$key];

        self::assertFalse($form['selectable']);
        self::assertStringContainsString('jiné ID PPV', (string) $form['blocker']);
        $result = $this->apply($files, [$key], pairs: [['key' => $key, 'employment_id' => $hppId]]);
        self::assertSame('skipped', $result['results'][0]['status']);
        self::assertSame(0, (int) $this->fetch('SELECT COUNT(*) FROM payroll_migration_reference_totals WHERE supplier_id = ?')[0][0]);
    }

    /** Bez věty, která dohodu založí, zůstanou formuláře nepřevzaté — a výsledek to musí říct. */
    public function testFormsLeftWithoutEmploymentMakeTheImportIncomplete(): void
    {
        $this->registerEmployee();
        $files = $this->reports();
        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        $formKeys = array_column(array_filter(
            $preview['records'],
            static fn (array $r): bool => $r['document_type'] === 'JMHZ' && $r['selectable'],
        ), 'key');

        $applied = $this->apply($files, $formKeys, takeover: true);

        self::assertSame('incomplete', $applied['outcome']);
        self::assertCount(3, $applied['unresolved'], $this->dump($applied['unresolved']));
        self::assertSame(['2026-01', '2026-02', '2026-03'], array_column($applied['unresolved'], 'period'));
        self::assertSame(3, (int) $this->fetch('SELECT COUNT(*) FROM payroll_migration_reference_totals WHERE supplier_id = ?')[0][0]);
    }

    /** DPP i DPČ malého rozsahu vypadají v hlášení stejně: účetní zvolí druh v náhledu. */
    public function testRelationTypeChoiceCreatesAgreementToPerformWork(): void
    {
        [$employeeId] = $this->registerEmployee();
        $files = $this->reports();
        $derivedKey = $this->derived($this->imports->preview($this->supplierId, 'test', $files)['records'], self::PPV_DPP)['key'];
        $choice = [['key' => $derivedKey, 'relation_type' => 'dpc']];

        $derived = $this->derived($this->imports->preview($this->supplierId, 'test', $files, null, $choice)['records'], self::PPV_DPP);
        self::assertSame('dpc', $derived['employment']['relation_type']);
        $ignored = $this->imports->preview($this->supplierId, 'test', $files, null, [['key' => $derivedKey, 'relation_type' => 'employment']]);
        self::assertSame('dpp', $this->derived($ignored['records'], self::PPV_DPP)['employment']['relation_type'],
            'Volba mimo nabídnuté druhy se nepoužije.');

        $applied = $this->apply($files, [$derivedKey], relationTypes: $choice);
        self::assertSame('applied', $applied['results'][0]['status'], (string) $applied['results'][0]['message']);
        $types = array_column($this->container->get(RegistrationImportLookup::class)->employments($this->supplierId, $employeeId), 'relation_type');
        sort($types);
        self::assertSame(['dpc', 'employment'], $types);
    }

    /**
     * Scénář celého toku přes HTTP akci: náhled → volba druhu vztahu → použití
     * s převzetím historie → převzatý rok osoby, ze kterého čte evidenční list
     * i převzatý běh ({@see PayrollTakeoverReader}).
     */
    public function testScenarioThroughActionIntoTakeoverYear(): void
    {
        [$employeeId, $hppId] = $this->registerEmployee();
        $files = $this->reports();
        $action = $this->container->get(PayrollRegistrationImportAction::class);

        $preview = $action->preview($this->request(['environment' => 'test', 'files' => $files]), new Response());
        if ($preview->getStatusCode() === 403) {
            self::markTestSkipped('Mzdový modul není v téhle instalaci licencovaný.');
        }
        self::assertSame(200, $preview->getStatusCode(), (string) $preview->getBody());
        $records = $this->json($preview)['records'];
        $derivedKey = $this->derived($records, self::PPV_DPP)['key'];
        $keys = array_column(array_filter($records, static fn (array $r): bool => $r['selectable']), 'key');

        $applied = $action->apply($this->request([
            'environment' => 'test',
            'files' => $files,
            'keys' => $keys,
            'evidence_confirmed' => true,
            'apply_takeover' => true,
            'relation_types' => [['key' => $derivedKey, 'relation_type' => 'dpp']],
        ]), new Response());
        self::assertSame(200, $applied->getStatusCode(), (string) $applied->getBody());
        $result = $this->json($applied);
        self::assertSame('complete', $result['outcome'], $this->dump($result['unresolved']));

        $year = $this->container->get(PayrollTakeoverReader::class)->forEmployee($this->supplierId, $employeeId, 2026);
        $dppMonths = array_values(array_filter($year->months, static fn ($month): bool => $month->employmentId !== $hppId));
        $hppMonths = $year->forEmployment($hppId);
        self::assertCount(3, $hppMonths);
        self::assertCount(3, $dppMonths);
        foreach ($hppMonths as $month) {
            self::assertSame('employment', $month->relationType);
            self::assertTrue($month->pensionParticipation);
            self::assertSame(4_000_000, $month->grossMinor);
        }
        foreach ($dppMonths as $month) {
            self::assertSame('dpp', $month->relationType);
            self::assertFalse($month->pensionParticipation, 'DPP pod limitem není účastna na důchodovém pojištění.');
            self::assertSame(0, $month->insuranceDays);
            self::assertSame(self::DPP_INCOME * 100, $month->grossMinor);
        }
        self::assertSame(
            4_000_000 + self::DPP_INCOME * 100,
            $year->personMonthTotals('2026-01', $employeeId)['gross_minor'],
            'Hrubé příjmy osoby za měsíc sečtou pracovní poměr i dohodu.',
        );
    }

    /** @param array<string,mixed> $body */
    private function request(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payroll/imports/registrations/apply')
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array{0:int,1:int} [employee_id, employment_id] */
    private function registerEmployee(): array
    {
        $files = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1([
            'bno' => RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1),
            'start' => '2026-01-01',
            'ikmpsv' => $this->oic,
            'oid' => self::PPV_HPP,
        ]))];
        $key = $this->imports->preview($this->supplierId, 'test', $files)['records'][0]['key'];
        $result = $this->apply($files, [$key])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);

        return [(int) $result['employee_id'], (int) $result['employment_id']];
    }

    /** @return list<array{name:string,content_base64:string}> */
    private function reports(): array
    {
        $files = [];
        foreach ([1, 2, 3] as $month) {
            $xml = JmhzReportFixtures::report([
                JmhzReportFixtures::person(['oic' => $this->oic, 'id_ppv' => self::PPV_HPP, 'children' => []]),
                JmhzReportFixtures::person([
                    'employment_id' => 102,
                    'oic' => $this->oic,
                    'id_ppv' => self::PPV_DPP,
                    'primary' => false,
                    'wage' => self::DPP_INCOME,
                    'taxable' => self::DPP_INCOME,
                    'social_base' => 0,
                    'children' => [],
                ]),
            ], 2026, $month, ['guid_seed' => $month]);
            $files[] = $this->file("jmhz-{$month}.xml", JmhzReportFixtures::uninsuredAgreement($xml, self::PPV_DPP, self::DPP_INCOME));
        }

        return $files;
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return list<array<string,mixed>>
     */
    private function forms(array $records, string $idPpv): array
    {
        $employmentId = $idPpv === self::PPV_DPP ? 102 : 101;
        $guids = array_map(static fn (int $month): string => JmhzReportFixtures::guid($month, $employmentId), [1, 2, 3]);

        return array_values(array_filter(
            $records,
            static fn (array $record): bool => $record['document_type'] === 'JMHZ'
                && in_array($record['form_id'] ?? null, $guids, true),
        ));
    }

    /**
     * Odvozená věta dohody — jediná, která nabízí volbu druhu vztahu.
     *
     * @param list<array<string,mixed>> $records
     * @return array<string,mixed>
     */
    private function derived(array $records, string $idPpv): array
    {
        foreach ($records as $record) {
            if ($record['document_type'] === 'JMHZ_DERIVED' && $record['employment']['relation_type_options'] !== []) {
                return $record;
            }
        }
        self::fail('Odvozená věta vztahu ' . $idPpv . ' v náhledu není: ' . $this->dump($records));
    }

    /**
     * @param list<array{name:string,content_base64:string}> $files
     * @param list<string> $keys
     * @param list<array{key:string,employment_id:int}>|null $pairs
     * @param list<array{key:string,relation_type:string}>|null $relationTypes
     * @return array<string,mixed>
     */
    private function apply(array $files, array $keys, ?array $pairs = null, bool $takeover = false, ?array $relationTypes = null): array
    {
        return $this->imports->apply(
            $this->supplierId,
            'test',
            $files,
            $keys,
            true,
            null,
            $this->userId,
            null,
            'jmhz-soubeh-test',
            $pairs,
            false,
            false,
            false,
            false,
            $takeover,
            $relationTypes,
        );
    }

    /** @return list<list<mixed>> */
    private function fetch(string $sql): array
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute([$this->supplierId]);

        return array_map(
            static fn (array $row): array => array_map(static fn (mixed $value): mixed => $value === null ? null : (string) $value, $row),
            $statement->fetchAll(\PDO::FETCH_NUM),
        );
    }

    /** @return array{name:string,content_base64:string} */
    private function file(string $name, string $content): array
    {
        return ['name' => $name, 'content_base64' => base64_encode($content)];
    }

    private function dump(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

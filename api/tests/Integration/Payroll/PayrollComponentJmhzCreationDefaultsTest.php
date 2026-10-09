<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollComponentJmhzMappingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceProfileComponents;
use MyInvoice\Service\Payroll\Run\PayrollRunJmhzReadinessProbe;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Nález z provozní zkoušky: běh 6/2026 z importu docházky GIRITON hlásil
 * 9× `component_jmhz_mapping_missing` — u složek výchozího číselníku, které
 * aplikace založila sama, i u složek, které založil import podle profilu.
 * Zařazení teď vzniká tam, kde vzniká složka; nezařazená zůstane jen složka,
 * jejíž zařazení z druhu neplyne, a nález na ni vede přímo.
 */
#[Group('integration')]
final class PayrollComponentJmhzCreationDefaultsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06-01';
    private const MIGRATION = '1903_payroll_component_jmhz_catalog_1_4_2_10_package.sql';

    private ContainerInterface $container;
    private Connection $db;
    private PayrollComponentRepository $components;
    private PayrollComponentJmhzMappingRepository $mappings;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $db = $this->container->get(Connection::class);
        $components = $this->container->get(PayrollComponentRepository::class);
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        if (!$db instanceof Connection || !$components instanceof PayrollComponentRepository
            || !$mappings instanceof PayrollComponentJmhzMappingRepository
        ) {
            throw new \RuntimeException('Služby mzdových složek nejsou dostupné.');
        }
        $this->db = $db;
        $this->components = $components;
        $this->mappings = $mappings;
        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
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

    /**
     * Výchozí číselník vzniká i ze vstupní brány a z importu (ensureDefaults()).
     * Zařazení musí vzniknout s ním — ne až tehdy, když někdo otevře obrazovku
     * zařazení nebo přípravu hlášení.
     */
    public function testCatalogComponentsGetMappingTheMomentTheCatalogIsSeeded(): void
    {
        $this->components->ensureDefaults($this->supplierId);

        foreach (['MZDA_MESICNI' => '10329', 'MZDA_UKOLOVA' => '10329', 'ODMENA' => '10331'] as $code => $target) {
            $mapping = $this->mappings->find($this->supplierId, $this->componentId($code));
            self::assertIsArray($mapping, "Složka {$code} nemá zařazení hned po založení číselníku.");
            self::assertSame($target, $mapping['target_attribute_id']);
            self::assertTrue($mapping['is_active']);
        }
        self::assertSame(
            0,
            $this->scalar(
                'SELECT COUNT(*) FROM payroll_component_jmhz_mappings
                  WHERE supplier_id = ? AND created_by IS NOT NULL',
                [$this->supplierId],
            ),
            'Předvyplnění aplikací se pozná podle prázdného autora.',
        );
    }

    /**
     * Čtení číselníku doplní zařazení i složce, která vznikla mimo repozitář
     * (import před opravou, starší data) — podle druhu, stejně jako obrazovka
     * zařazení. Složku „podle hlavičky" nechá být.
     */
    public function testReadingTheCatalogMapsKindComponentsButLeavesHeaderOnes(): void
    {
        $this->components->ensureDefaults($this->supplierId);
        $bozp = $this->insertComponent('PRIPLATEK_BOZP', 'premium');
        $bonus = $this->insertComponent('ODMENA_HOTOVOSTNI', 'bonus');
        $auto = $this->insertComponent('DOCH_KONTEJNERY', 'other');

        $this->components->ensureDefaults($this->supplierId);

        self::assertSame('10332', $this->mappings->find($this->supplierId, $bozp)['target_attribute_id'] ?? null);
        // Odměna zadávaná za měsíc může být pravidelná (10330) i nepravidelná
        // (10331); z dat to neplyne, rozhodne účetní (Pokyny MH 1.4.14 kap. 3.5.1).
        self::assertNull($this->mappings->find($this->supplierId, $bonus));
        self::assertNull($this->mappings->find($this->supplierId, $auto));
        // Zařazení je v balíku, který snímek hlášení čte.
        self::assertSame('10332', $this->mappings->snapshot($this->supplierId, $bozp)['target_attribute_id']);
    }

    public function testImportProfileComponentsAreMappedByKindAndOnlyTheUnknownOneBlocks(): void
    {
        $ids = [];
        foreach ([
            ['code' => 'MZDA_HODINOVA_DOCH', 'name' => 'Hodinová mzda podle docházky', 'kind' => 'hourly_wage'],
            ['code' => 'PRIPLATEK_BOZP', 'name' => 'Příplatek BOZP', 'kind' => 'premium'],
            ['code' => 'ODMENA_MIMORADNA', 'name' => 'Mimořádná odměna', 'kind' => 'bonus'],
            ['code' => 'NAHRADA_TEST', 'name' => 'Syntetická náhrada', 'kind' => 'compensation'],
            ['code' => 'DOCH_KONTEJNERY', 'name' => 'Kontejnery', 'kind' => 'other'],
        ] as $component) {
            // Doslova volání, kterým import docházky složku profilu zakládá.
            $created = $this->components->create(
                $this->supplierId,
                AttendanceProfileComponents::definition($component, self::PERIOD),
            );
            $ids[$component['code']] = PayrollTimeValue::int($created['id'] ?? null, 'id');
        }

        $expected = [
            'MZDA_HODINOVA_DOCH' => '10329',
            'PRIPLATEK_BOZP' => '10332',
            // Pravidelnost odměny z importu neplyne; varování a rozhodnutí účetní.
            'ODMENA_MIMORADNA' => null,
            'NAHRADA_TEST' => '10337',
            'DOCH_KONTEJNERY' => null,
        ];
        foreach ($expected as $code => $target) {
            $mapping = $this->mappings->find($this->supplierId, $ids[$code]);
            self::assertSame($target, $mapping['target_attribute_id'] ?? null, "Špatné zařazení u {$code}.");
        }

        $probe = $this->container->get(PayrollRunJmhzReadinessProbe::class);
        self::assertInstanceOf(PayrollRunJmhzReadinessProbe::class, $probe);
        $findings = $probe->inspect($this->supplierId, self::PERIOD, $this->snapshotWith(array_values($ids)));

        self::assertSame(['component_jmhz_mapping_missing'], array_column($findings, 'code'));
        $finding = $findings[0];
        self::assertSame(2, $finding['count']);
        self::assertSame(2, $finding['entity_total']);
        self::assertCount(2, $finding['entities']);
        $entities = array_column($finding['entities'], null, 'entity_id');
        ksort($entities);
        $missing = [$ids['ODMENA_MIMORADNA'], $ids['DOCH_KONTEJNERY']];
        sort($missing);
        self::assertSame($missing, array_keys($entities));
        self::assertStringContainsString('DOCH_KONTEJNERY', $finding['message']);
        self::assertSame(
            '/payroll/components?tab=catalog&component=' . $ids['DOCH_KONTEJNERY'] . '&panel=jmhz',
            $entities[$ids['DOCH_KONTEJNERY']]['remediation_path'],
        );
        self::assertSame('/payroll/components?tab=catalog&jmhz=missing', $finding['remediation_path']);

        $suggestions = $this->suggestions();
        self::assertSame('10329', $suggestions[$ids['MZDA_HODINOVA_DOCH']]);
        self::assertNull($suggestions[$ids['DOCH_KONTEJNERY']]);
    }

    public function testBackfillMigrationIsIdempotentAndKeepsTheAccountantsChoice(): void
    {
        $this->components->ensureDefaults($this->supplierId);
        // Firma z doby před opravou: zařazení vyplněná aplikací neexistují
        // a složky profilu importu vznikly bez zařazení.
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_component_jmhz_mappings WHERE supplier_id = ? AND created_by IS NULL',
        )->execute([$this->supplierId]);
        $bozp = $this->insertComponent('PRIPLATEK_BOZP', 'premium');
        $auto = $this->insertComponent('DOCH_KONTEJNERY', 'other');
        $manual = $this->mappings->put($this->supplierId, $this->componentId('MZDA_MESICNI'), '10330', null, $this->userId);
        $odmena = $this->componentId('ODMENA');
        $this->mappings->put($this->supplierId, $odmena, '10331', null, $this->userId);
        $this->mappings->remove($this->supplierId, $odmena, 1, $this->userId);

        $this->runMigration();
        $after = $this->mappingRows();
        $this->runMigration();
        self::assertSame($after, $this->mappingRows(), 'Opakovaný běh backfillu nesmí nic změnit.');

        $ukol = $this->mappings->find($this->supplierId, $this->componentId('MZDA_UKOLOVA'));
        self::assertSame('10329', $ukol['target_attribute_id'] ?? null);
        self::assertSame('10332', $this->mappings->find($this->supplierId, $bozp)['target_attribute_id'] ?? null);
        self::assertNull($this->mappings->find($this->supplierId, $auto));
        // Zařazení vzniklo v balíku, který snímek hlášení opravdu čte.
        self::assertSame('10332', $this->mappings->snapshot($this->supplierId, $bozp)['target_attribute_id']);

        $kept = $this->mappings->find($this->supplierId, $this->componentId('MZDA_MESICNI'));
        self::assertSame('10330', $kept['target_attribute_id'] ?? null);
        self::assertSame($manual['row_version'], $kept['row_version'] ?? null);
        $disabled = $this->mappings->find($this->supplierId, $odmena);
        self::assertFalse($disabled['is_active'] ?? true, 'Vědomě zrušené zařazení se nesmí obnovit.');
    }

    /**
     * Zařazení odměny zadávané za měsíc do 10331, které vytvořilo dřívější
     * výchozí pravidlo, ZŮSTÁVÁ: firmě s podanými měsíci se příprava hlášení
     * nesmí bez zásahu zastavit. Obrazovka zařazení i kontrola před během ho
     * jen neblokujícím upozorněním předloží k ověření pravidelnosti. Rozhodnutí
     * účetní (i stejné 10331) a výchozí ODMENA podle kódu se nehlásí. Uložením
     * stejného cíle účetní zařazení potvrdí a upozornění zmizí.
     */
    public function testExistingBonusDefaultStaysAndIsOnlyFlaggedForReview(): void
    {
        $this->components->ensureDefaults($this->supplierId);
        $byDefault = $this->insertComponent('PAM_O01_PREMIE_PEVNOU_CASTKO', 'bonus');
        $byAccountant = $this->insertComponent('ODMENA_ROCNI', 'bonus');
        // Stav z dřívějška: výchozí pravidlo zařadilo odměnu za měsíc do 10331.
        $this->mappings->put($this->supplierId, $byDefault, '10331', null, null);
        $this->mappings->put($this->supplierId, $byAccountant, '10331', null, $this->userId);
        $odmena = $this->componentId('ODMENA');

        $this->components->ensureDefaults($this->supplierId);
        self::assertSame('10331', $this->mappings->find($this->supplierId, $byDefault)['target_attribute_id'] ?? null);
        self::assertSame('10331', $this->mappings->find($this->supplierId, $odmena)['target_attribute_id'] ?? null);

        $defaults = $this->container->get(PayrollComponentJmhzMappingDefaults::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingDefaults::class, $defaults);
        self::assertSame([$byDefault => true], $defaults->unverifiedBonusDefaults($this->supplierId));

        $hints = $this->suggestions('review_hint');
        self::assertSame('bonus_regularity_unverified', $hints[$byDefault]);
        self::assertNull($hints[$byAccountant]);
        self::assertNull($hints[$odmena]);

        $probe = $this->container->get(PayrollRunJmhzReadinessProbe::class);
        self::assertInstanceOf(PayrollRunJmhzReadinessProbe::class, $probe);
        $findings = $probe->inspect($this->supplierId, self::PERIOD, $this->snapshotWith([$byDefault, $byAccountant, $odmena]));
        self::assertSame(['component_jmhz_bonus_regularity_unverified'], array_column($findings, 'code'));
        self::assertSame('info', $findings[0]['severity']);
        self::assertSame('anytime', $findings[0]['impact']);
        self::assertSame([$byDefault], array_column($findings[0]['entities'], 'entity_id'));
        self::assertStringContainsString('PAM_O01_PREMIE_PEVNOU_CASTKO', $findings[0]['message']);

        // Potvrzení stejným cílem: obsah ani verze se nemění, upozornění zmizí.
        $before = $this->mappings->find($this->supplierId, $byDefault);
        $confirmed = $this->mappings->put($this->supplierId, $byDefault, '10331', (int) ($before['row_version'] ?? 0), $this->userId);
        self::assertSame($before['row_version'] ?? null, $confirmed['row_version']);
        self::assertSame([], $defaults->unverifiedBonusDefaults($this->supplierId));
        self::assertSame([], $probe->inspect($this->supplierId, self::PERIOD, $this->snapshotWith([$byDefault])));
    }

    public function testReadinessReportsTheRealNumberOfPeopleNotTheListedOnes(): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)',
        );
        for ($i = 1; $i <= 30; ++$i) {
            $insert->execute([$this->supplierId, sprintf('Syntetický Zaměstnanec %02d', $i)]);
        }

        $service = $this->container->get(PayrollRunReadinessService::class);
        self::assertInstanceOf(PayrollRunReadinessService::class, $service);
        $method = new \ReflectionMethod($service, 'personDataGapFinding');
        $finding = $method->invoke($service, $this->supplierId);

        self::assertIsArray($finding);
        self::assertSame('person_data_gap', $finding['code']);
        self::assertSame(30, $finding['count']);
        self::assertSame(30, $finding['entity_total']);
        self::assertCount(25, $finding['entities']);
        self::assertStringContainsString('a dalších 5', $finding['message']);
    }

    /**
     * @param list<int> $componentIds
     * @return array<string,mixed>
     */
    private function snapshotWith(array $componentIds): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, jmhz_treatment, tax_treatment, component_kind
               FROM payroll_component_definitions
              WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($componentIds), '?')) . ')',
        );
        $stmt->execute([$this->supplierId, ...$componentIds]);
        $inputs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $inputs[] = ['component' => [
                'component_id' => (int) $row['id'],
                'jmhz_treatment' => $row['jmhz_treatment'],
                'tax_treatment' => $row['tax_treatment'],
                'component_kind' => $row['component_kind'],
            ]];
        }

        // Identifikátory 0 = bez kontroly identity pro ČSSZ; zkoumá se jen zařazení složek.
        return ['people' => [[
            'employee' => ['id' => 0],
            'employments' => [['employment' => ['id' => 0], 'inputs' => $inputs]],
        ]]];
    }

    /** @return array<int,?string> */
    private function suggestions(string $field = 'suggested_target_attribute_id'): array
    {
        $action = $this->container->get(PayrollComponentJmhzMappingsAction::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingsAction::class, $action);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/components/jmhz-mappings')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        $response = $action->list($request, new Response());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        $result = [];
        foreach (PayrollTimeValue::rows($body['items'] ?? null, 'items') as $item) {
            $result[PayrollTimeValue::int($item['component_id'] ?? null, 'component_id')]
                = $item[$field] ?? null;
        }

        return $result;
    }

    private function runMigration(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 4) . '/db/migrations/' . self::MIGRATION);
        self::assertIsString($sql);
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') {
                $this->db->pdo()->exec($statement);
            }
        }
    }

    /** @return list<array{int,string,int,int,?int}> */
    private function mappingRows(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT component_definition_id, target_attribute_id, row_version, is_active, created_by
               FROM payroll_component_jmhz_mappings
              WHERE supplier_id = ?
              ORDER BY component_definition_id, spec_package_id',
        );
        $stmt->execute([$this->supplierId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            $rows[] = [(int) $row[0], (string) $row[1], (int) $row[2], (int) $row[3], $row[4] === null ? null : (int) $row[4]];
        }

        return $rows;
    }

    /** Složka založená mimo repozitář, jak ji zakládal import před opravou. */
    private function insertComponent(string $code, string $kind): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment,
                 jmhz_treatment, statistics_treatment,
                 valid_from, is_active)
             VALUES (?, ?, ?, ?, "monetary", "one_off", "included",
                     "included", "included", "included", "included",
                     "included", "included", "included", "included",
                     ?, 1)',
        )->execute([$this->supplierId, $code, "Syntetická {$code}", $kind, self::PERIOD]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function componentId(string $code): int
    {
        $id = $this->scalar(
            'SELECT id FROM payroll_component_definitions WHERE supplier_id = ? AND code = ? ORDER BY valid_from LIMIT 1',
            [$this->supplierId, $code],
        );
        if ($id === 0) {
            self::fail("Složka {$code} ve firmě není.");
        }

        return $id;
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}

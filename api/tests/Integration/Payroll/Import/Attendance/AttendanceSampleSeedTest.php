<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Attendance;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollImportProfileRepository;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMeaning;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMyUctoSampleProfile;
use MyInvoice\Service\Payroll\Import\Attendance\AttendancePamicaSampleProfile;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSampleProfile;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Založení a aktualizace vestavěných vzorů po klíčích. Vzor se zakládá jen
 * mimo cizí transakci, proto test pracuje s potvrzenými daty vlastní firmy
 * a v tearDown ji smaže (profily jdou kaskádou).
 */
#[Group('integration')]
final class AttendanceSampleSeedTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const MIGRATION = '2206_payroll_import_profile_sample_key.sql';

    private Connection $db;
    private AttendanceImportService $service;
    private PayrollImportProfileRepository $profiles;
    private ?int $supplierId = null;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $service = $container->get(AttendanceImportService::class);
        $profiles = $container->get(PayrollImportProfileRepository::class);
        if (!$db instanceof Connection || !$service instanceof AttendanceImportService
            || !$profiles instanceof PayrollImportProfileRepository) {
            throw new \RuntimeException('Služba importu docházky není dostupná.');
        }
        $this->db = $db;
        $this->service = $service;
        $this->profiles = $profiles;
        if (!$db->hasTable('payroll_import_profiles') || !$db->hasColumn('payroll_import_profiles', 'sample_key')) {
            self::markTestSkipped('Chybí sloupec payroll_import_profiles.sample_key.');
        }
        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier SET payroll_attendance_sample_seeded_at = NULL, payroll_attendance_myucto_sample_seeded_at = NULL,
                payroll_attendance_pamica_sample_seeded_at = NULL WHERE id = ?',
        )->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($this->supplierId !== null) {
            $pdo->prepare('DELETE FROM payroll_import_profiles WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM supplier_vat_status_history WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        }
        $this->db->close();
    }

    /** Nová firma dostane všechny vzory, každý jednou a s vlastní značkou. */
    public function testNewCompanyGetsBothSamplesOnce(): void
    {
        $this->service->profiles($this->supplierId());
        $this->service->profiles($this->supplierId());

        self::assertSame(['giriton' => 1, 'myucto' => 1, 'pamica' => 1], $this->sampleCounts());
        $samples = $this->samplesByKey();
        self::assertSame(AttendanceSampleProfile::NAME, $samples['giriton']['name']);
        self::assertSame(AttendanceMyUctoSampleProfile::NAME, $samples['myucto']['name']);
        self::assertSame(AttendanceMyUctoSampleProfile::rules(), $samples['myucto']['rules']);
        self::assertSame(AttendanceMyUctoSampleProfile::VERSION, $samples['myucto']['sample_version']);
        self::assertNotNull($this->marker('payroll_attendance_sample_seeded_at'));
        self::assertNotNull($this->marker('payroll_attendance_myucto_sample_seeded_at'));
        self::assertSame(AttendancePamicaSampleProfile::NAME, $samples['pamica']['name']);
        self::assertSame(AttendancePamicaSampleProfile::rules(), $samples['pamica']['rules']);
        self::assertNotNull($this->marker('payroll_attendance_pamica_sample_seeded_at'));
    }

    /**
     * Firma, která Vzor GIRITON už má (značka GIRITON nastavená), dostane
     * Vzor MyÚčto jednou. Smazaný vzor MyÚčto se znovu nezaloží, jde jen
     * obnovit tlačítkem; obnova GIRITON vzor MyÚčto nezaloží a naopak.
     */
    public function testExistingGiritonCompanyGetsMyUctoOnceAndDeletedStaysDeleted(): void
    {
        $giriton = $this->insertSample(AttendanceSampleProfile::NAME, AttendanceSampleProfile::rules(), AttendanceSampleProfile::components(), AttendanceSampleProfile::VERSION, null);
        $this->db->pdo()->prepare('UPDATE supplier SET payroll_attendance_sample_seeded_at = NOW() WHERE id = ?')
            ->execute([$this->supplierId()]);

        $this->service->profiles($this->supplierId());
        self::assertSame(['giriton' => 1, 'myucto' => 1, 'pamica' => 1], $this->sampleCounts());
        self::assertSame($giriton, $this->samplesByKey()['giriton']['id'], 'Stávající GIRITON zůstal, nový nevznikl.');

        $myucto = $this->samplesByKey()['myucto']['id'];
        self::assertTrue($this->service->deleteProfile($this->supplierId(), $myucto));
        $this->service->profiles($this->supplierId());
        self::assertSame(['giriton' => 1, 'pamica' => 1], $this->sampleCounts(), 'Smazaný Vzor MyÚčto se nevrací.');

        self::assertFalse($this->service->restoreSampleProfile($this->supplierId(), $this->userId, 'giriton')['restored']);
        self::assertSame(['giriton' => 1, 'pamica' => 1], $this->sampleCounts());

        $restored = $this->service->restoreSampleProfile($this->supplierId(), $this->userId, 'myucto');
        self::assertTrue($restored['restored']);
        self::assertSame('myucto', $restored['profile']['sample_key']);
        self::assertSame(AttendanceMyUctoSampleProfile::NAME, $restored['profile']['name']);
        self::assertFalse($this->service->restoreSampleProfile($this->supplierId(), $this->userId, 'myucto')['restored']);
        self::assertSame(['giriton' => 1, 'myucto' => 1, 'pamica' => 1], $this->sampleCounts());

        $this->expectException(\InvalidArgumentException::class);
        $this->service->restoreSampleProfile($this->supplierId(), $this->userId, 'neznamy');
    }

    /**
     * Aktualizace jde po klíčích: nedotčený starší Vzor MyÚčto dostane pravidla
     * MyÚčta (ne GIRITON), upravený GIRITON se jen nabídne a aktualizace
     * GIRITON na vzor MyÚčto nesáhne.
     */
    public function testUpgradeIsPerKey(): void
    {
        $this->markBothSeeded();
        $staleMyUcto = array_values(array_filter(
            AttendanceMyUctoSampleProfile::rules(),
            static fn (array $rule): bool => $rule['meaning'] !== 'net_other_deduction',
        ));
        $myucto = $this->insertSample(AttendanceMyUctoSampleProfile::NAME, $staleMyUcto, AttendanceMyUctoSampleProfile::components(), 0, 'myucto');
        $giriton = $this->insertSample(AttendanceSampleProfile::NAME, AttendanceSampleProfile::rules(), AttendanceSampleProfile::components(), 1, 'giriton');
        $custom = [...AttendanceSampleProfile::rules(), ['sheet' => 'mzdy*', 'header' => 'plat', 'meaning' => 'monthly_wage', 'unit' => null, 'component_code' => null]];
        $this->service->saveProfile($this->supplierId(), $giriton, AttendanceSampleProfile::NAME, $custom, $this->userId, AttendanceSampleProfile::components());

        // Aktualizace GIRITON vzorem MyÚčto nesáhne.
        $this->profiles->upgradeSample(
            $this->supplierId(),
            AttendanceMeaning::SOURCE_SYSTEM,
            'giriton',
            AttendanceSampleProfile::VERSION,
            AttendanceSampleProfile::rules(),
            AttendanceSampleProfile::components(),
        );
        $samples = $this->samplesByKey();
        self::assertSame($staleMyUcto, $samples['myucto']['rules']);
        self::assertSame(0, $samples['myucto']['sample_version']);

        $profiles = array_column($this->service->profiles($this->supplierId()), null, 'id');
        self::assertSame(AttendanceMyUctoSampleProfile::rules(), $profiles[$myucto]['rules']);
        self::assertSame(AttendanceMyUctoSampleProfile::VERSION, $profiles[$myucto]['sample_version']);
        self::assertFalse($profiles[$myucto]['upgrade_available']);
        self::assertSame($custom, $profiles[$giriton]['rules']);
        self::assertTrue($profiles[$giriton]['upgrade_available']);
    }

    /**
     * Migrace 2206 je opakovatelná a vzorům bez klíče doplní GIRITON; vlastní
     * profily klíč nedostanou.
     */
    public function testMigrationBackfillsGiritonKey(): void
    {
        $this->markBothSeeded();
        $sample = $this->insertSample(AttendanceSampleProfile::NAME, AttendanceSampleProfile::rules(), AttendanceSampleProfile::components(), 1, 'giriton');
        $own = $this->profiles->save($this->supplierId(), AttendanceMeaning::SOURCE_SYSTEM, null, 'Vlastní', AttendanceMyUctoSampleProfile::rules(), $this->userId);
        self::assertNotNull($own);
        $this->db->pdo()->prepare('UPDATE payroll_import_profiles SET sample_key = NULL WHERE supplier_id = ?')
            ->execute([$this->supplierId()]);

        $sql = (string) file_get_contents(dirname(__DIR__, 6) . '/db/migrations/' . self::MIGRATION);
        foreach (array_filter(array_map('trim', explode(';', (string) preg_replace('/^--.*$/m', '', $sql)))) as $statement) {
            $this->db->pdo()->exec($statement);
        }

        $keys = $this->db->pdo()->prepare('SELECT id, sample_key FROM payroll_import_profiles WHERE supplier_id = ?');
        $keys->execute([$this->supplierId()]);
        self::assertSame([$sample => 'giriton', $own['id'] => null], array_map(
            static fn (mixed $value): ?string => is_string($value) ? $value : null,
            $keys->fetchAll(PDO::FETCH_KEY_PAIR),
        ));
    }

    private function supplierId(): int
    {
        return $this->supplierId ?? throw new \LogicException('Firma testu nevznikla.');
    }

    private function markBothSeeded(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_attendance_sample_seeded_at = NOW(), payroll_attendance_myucto_sample_seeded_at = NOW(),
                payroll_attendance_pamica_sample_seeded_at = NOW() WHERE id = ?',
        )->execute([$this->supplierId()]);
    }

    /**
     * @param list<array<string,mixed>> $rules
     * @param list<array<string,mixed>> $components
     */
    private function insertSample(string $name, array $rules, array $components, int $version, ?string $key): int
    {
        $profile = $this->profiles->save(
            $this->supplierId(),
            AttendanceMeaning::SOURCE_SYSTEM,
            null,
            $name,
            $rules,
            null,
            $components,
            true,
            $version,
            $key,
        );
        self::assertNotNull($profile);

        return $profile['id'];
    }

    /** @return array<string,int> */
    private function sampleCounts(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT sample_key, COUNT(*) FROM payroll_import_profiles
              WHERE supplier_id = ? AND is_sample = 1 GROUP BY sample_key ORDER BY sample_key',
        );
        $stmt->execute([$this->supplierId()]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** @return array<string,array<string,mixed>> */
    private function samplesByKey(): array
    {
        $samples = [];
        foreach ($this->profiles->list($this->supplierId(), AttendanceMeaning::SOURCE_SYSTEM) as $profile) {
            if ($profile['is_sample'] && $profile['sample_key'] !== null) {
                $samples[$profile['sample_key']] = $profile;
            }
        }

        return $samples;
    }

    private function marker(string $column): ?string
    {
        $stmt = $this->db->pdo()->prepare("SELECT {$column} FROM supplier WHERE id = ?");
        $stmt->execute([$this->supplierId()]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }
}

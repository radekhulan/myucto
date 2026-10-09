<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollImporter;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaPayroll;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převod PAMICA s obsahem podaných hlášení JMHZ a registrací: z podání se doplní jen to,
 * co karty nenesou, nic se nepřepíše, hlášení jdou do historie podání (opakovaný převod
 * je nezdvojí) a neodeslaný měsíc je v protokolu. Transakce se v tearDown vrací.
 */
#[Group('integration')]
final class PohodaPayrollJmhzImportTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PohodaPayrollImporter $importer;
    private int $userId = 0;
    private int $sourceSupplierId = 0;
    private string $tmp = '';

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(PohodaPayrollImporter::class);
        foreach (['payroll_attendance_imports', 'payroll_employees', 'payroll_offices', 'pohoda_import_map', 'payroll_external_jmhz_submissions'] as $table) {
            if (!$this->db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $pdo = $this->db->pdo();
        $this->sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($this->sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_jmhz_int_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testReportsFillGapsOverwriteNothingAndLandInHistory(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = SyntheticPohodaPayroll::writeWithReports($this->tmp);

        $protocol = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, null, null, null, true, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $counts = self::stepCounts($protocol, PohodaPayrollImporter::STEP_JMHZ);
        self::assertSame(4, $counts['jmhz_submissions'] ?? 0, $this->explain($protocol));
        self::assertSame(4, $counts['jmhz_submissions_created'] ?? 0);
        self::assertSame(1, $counts['jmhz_months_not_sent'] ?? 0);
        self::assertSame(2, $counts['jmhz_registrations'] ?? 0);
        self::assertArrayNotHasKey('jmhz_failed', $counts, $this->explain($protocol));
        $messages = self::messages($protocol, PohodaPayrollImporter::STEP_JMHZ);
        self::assertStringContainsString('Hlášení za 03/2026 nebylo odesláno', $messages['jmhz_month_not_sent'] ?? '', $this->explain($protocol));

        $jana = $this->employment($supplierId, '1001');
        $petr = $this->employment($supplierId, '1002');

        // Historie podání: tři odeslaná hlášení, březen neodeslaný, formuláře spárované se vztahy.
        self::assertSame([
            ['period' => '2026-01', 'submission_type' => 'R', 'status' => 'sent', 'form_count' => 2],
            ['period' => '2026-02', 'submission_type' => 'R', 'status' => 'sent', 'form_count' => 2],
            ['period' => '2026-02', 'submission_type' => 'O', 'status' => 'sent', 'form_count' => 1],
            ['period' => '2026-03', 'submission_type' => 'R', 'status' => 'not_sent', 'form_count' => 1],
        ], $this->all(
            "SELECT period, submission_type, status, form_count FROM payroll_external_jmhz_submissions
              WHERE supplier_id = ? AND document_kind = 'monthly' ORDER BY period, submitted_at IS NULL, submitted_at",
            [$supplierId],
            static fn (array $r): array => array_merge($r, ['form_count' => (int) $r['form_count']]),
        ));
        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM payroll_external_jmhz_submissions WHERE supplier_id = ? AND document_kind = 'registration'", [$supplierId]));
        self::assertSame(5, $this->scalar('SELECT COUNT(*) FROM payroll_external_jmhz_submission_forms WHERE supplier_id = ? AND employment_id = ?', [$supplierId, $jana['id']]),
            'Janiny formuláře (leden, únor, oprava, neodeslaný březen) a věta registrace nesou vazbu na její vztah.');

        // Doplněno z hlášení: Petrovo ID PPV (karta ho nemá) a průměr za 1. čtvrtletí (mzda ho nenese).
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_employment_external_ids WHERE supplier_id = ? AND employment_id = ? AND identifier_type = 'id_ppv'", [$supplierId, $petr['id']]), $this->explain($protocol));
        self::assertSame(18050, $this->scalar("SELECT average_hourly_minor FROM payroll_average_earning_snapshots WHERE supplier_id = ? AND employment_id = ? AND status = 'approved'", [$supplierId, $petr['id']]), $this->explain($protocol));
        // Janin průměr z mzdy (250 Kč) zůstává; opravné hlášení s 255 Kč ho nepřepíše.
        self::assertSame(25000, $this->scalar("SELECT average_hourly_minor FROM payroll_average_earning_snapshots WHERE supplier_id = ? AND employment_id = ? AND status = 'approved'", [$supplierId, $jana['id']]));

        // Podmínky: příznaky JMHZ a upřesnění vztahu doplněné, pracoviště a CZ-ISCO z karty nepřepsané.
        $terms = $this->all(
            'SELECT effective_from, work_place, jmhz_workplace_municipality_code AS obec, cz_isco_code, regular_workplace, weekly_hours,
                    jmhz_apz_contribution_status AS apz, jmhz_functional_benefits_status AS benefits,
                    jmhz_temporary_assignment_status AS assignment, jmhz_relationship_detail_code AS detail, activity_code
               FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ? ORDER BY effective_from',
            [$supplierId, $jana['id']],
        );
        self::assertCount(2, $terms, $this->explain($protocol));
        foreach ($terms as $version) {
            self::assertSame(['Brno', '582786', '43111', 'Brno sídlo'], [$version['work_place'], $version['obec'], $version['cz_isco_code'], $version['regular_workplace']],
                'Údaje z karty PAMICA hlášení ani registrace nepřepíšou.');
            self::assertSame(['no', 'no', 'no', '1', '1'], [$version['apz'], $version['benefits'], $version['assignment'], $version['detail'], $version['activity_code']],
                'Příznaky JMHZ, upřesnění vztahu a druh činnosti se doplní do každé verze.');
            self::assertNotSame('37.50', $version['weekly_hours'], 'Týdenní doba z opravného hlášení nepřepíše kartu.');
        }

        // Děti: karta má jedno dítě, hlášení dvě - z hlášení se nic nepřidá.
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM payroll_dependants WHERE supplier_id = ? AND employee_id = ?', [$supplierId, $jana['employee_id']]));

        // Opakovaný převod: historie beze změny, nic se nezdvojí.
        $again = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, null, null, null, true, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        $counts = self::stepCounts($again, PohodaPayrollImporter::STEP_JMHZ);
        self::assertSame(4, $counts['jmhz_submissions_unchanged'] ?? 0, $this->explain($again));
        self::assertSame(2, $counts['jmhz_registrations_unchanged'] ?? 0);
        self::assertSame(6, $this->scalar('SELECT COUNT(*) FROM payroll_external_jmhz_submissions WHERE supplier_id = ?', [$supplierId]));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_employment_external_ids WHERE supplier_id = ? AND employment_id = ? AND identifier_type = 'id_ppv'", [$supplierId, $petr['id']]));
    }

    public function testIdentifiersFromReportsNeedConfirmation(): void
    {
        $supplierId = $this->payrollSupplier();
        $protocol = $this->importer->run($supplierId, $this->userId, SyntheticPohodaPayroll::writeWithReports($this->tmp), SyntheticPohodaPayroll::YEAR, false, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(2, self::stepCounts($protocol, PohodaPayrollImporter::STEP_JMHZ)['jmhz_identifiers_unconfirmed'] ?? 0, $this->explain($protocol));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_employment_external_ids WHERE supplier_id = ?', [$supplierId]));
    }

    public function testAcceptedRegistrationsGoThroughProductImportWithoutOverwritingCards(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = SyntheticPohodaPayroll::writeWithRegistrations($this->tmp);

        $protocol = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, null, null, null, true, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $counts = self::stepCounts($protocol, PohodaPayrollImporter::STEP_JMHZ);
        self::assertSame(2, $counts['registrations_sentences'] ?? 0, $this->explain($protocol));
        self::assertSame(1, $counts['registrations_files_unaccepted'] ?? 0, 'Petrova neodeslaná přihláška se nepřebírá.');
        self::assertSame(1, $counts['registrations_differs'] ?? 0, $this->explain($protocol));
        self::assertSame(1, $counts['registrations_applied'] ?? 0, $this->explain($protocol));
        self::assertStringContainsString('Kód CZ-ISCO', self::messages($protocol, PohodaPayrollImporter::STEP_JMHZ)['registration_differs'] ?? '');

        $jana = $this->employment($supplierId, '1001');
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM payroll_registration_a1_profiles WHERE supplier_id = ? AND employment_id = ?', [$supplierId, $jana['id']]),
            'Profil přihlášky A1 vznikne z přijaté věty.');
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ? AND cz_isco_code <> '43111'", [$supplierId, $jana['id']]),
            'CZ-ISCO z karty PAMICA věta registrace nepřepíše.');
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM payroll_employments WHERE supplier_id = ? AND employee_id = ?', [$supplierId, $jana['employee_id']]),
            'Věty nezaložily druhý vztah.');

        $again = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, null, null, null, true, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        $counts = self::stepCounts($again, PohodaPayrollImporter::STEP_JMHZ);
        self::assertSame(1, $counts['registrations_done'] ?? 0, $this->explain($again));
        self::assertArrayNotHasKey('registrations_applied', $counts);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM payroll_registration_a1_profiles WHERE supplier_id = ? AND employment_id = ?', [$supplierId, $jana['id']]));
    }

    /**
     * Firma bez VS a kódu OSSZ: převod je doplní z přijatých registrací, založí registraci
     * účtárny a registrace pak projdou importem (dřív je VS „jiného zaměstnavatele" blokoval).
     */
    public function testEmployerIdentifiersAreFilledFromAcceptedRegistrations(): void
    {
        $supplierId = $this->payrollSupplier(false);
        $file = SyntheticPohodaPayroll::writeWithRegistrations($this->tmp);

        $protocol = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, null, null, null, true, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $stmt = $this->db->pdo()->prepare(
            'SELECT o.social_security_variable_symbol, s.employer_registration_number, s.social_security_office_code
               FROM payroll_employer_settings s JOIN payroll_offices o ON o.supplier_id = s.supplier_id AND o.id = s.default_office_id
              WHERE s.supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        self::assertSame(['1234567890', '1234567890', '110'], array_values(array_map('strval', $stmt->fetch(\PDO::FETCH_ASSOC))), $this->explain($protocol));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_office_registration_versions WHERE supplier_id = ? AND social_security_variable_symbol = '1234567890'", [$supplierId]));
        self::assertSame(1, self::stepCounts($protocol, PohodaPayrollImporter::STEP_JMHZ)['registrations_applied'] ?? 0, $this->explain($protocol));
    }

    private function payrollSupplier(bool $identifiers = true): int
    {
        $pdo = $this->db->pdo();
        $supplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())',
        )->execute([$supplierId, $this->userId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "IMP", "Syntetická účtárna", ?, 1)',
        )->execute([$supplierId, $identifiers ? '1234567890' : null]);
        $officeId = (int) $pdo->lastInsertId();
        if (!$identifiers) {
            $pdo->prepare('INSERT INTO payroll_employer_settings (supplier_id, default_office_id) VALUES (?, ?)')->execute([$supplierId, $officeId]);
            return $supplierId;
        }
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2026-01-01", "1234567890", "synthetic:pohoda-payroll")',
        )->execute([$supplierId, $officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "P")',
        )->execute([$supplierId, $officeId]);
        return $supplierId;
    }

    /** @return array<string,mixed> */
    private function employment(int $supplierId, string $code): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, employee_id FROM payroll_employments WHERE supplier_id = ? AND code = ?');
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Vztah {$code} nevznikl.");
        return $row;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function all(string $sql, array $params, ?callable $map = null): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $map === null ? $rows : array_map($map, $rows);
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,int|float> */
    private static function stepCounts(ImportProtocol $protocol, string $key): array
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            if ($step['key'] === $key) {
                return $step['counts'];
            }
        }
        return [];
    }

    /** @return array<string,string> kód => text první zprávy */
    private static function messages(ImportProtocol $protocol, string $key): array
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            if ($step['key'] === $key) {
                $out = [];
                foreach ($step['messages'] as $message) {
                    $out[$message['code']] ??= $message['text'] ?? $message['message'] ?? '';
                }
                return $out;
            }
        }
        return [];
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

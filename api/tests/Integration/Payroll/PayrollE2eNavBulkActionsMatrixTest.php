<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollAbsenceAction;
use MyInvoice\Action\Payroll\PayrollEmploymentTermsBulkEditAction;
use MyInvoice\Action\Payroll\PayrollEmploymentWorkplaceBulkFillAction;
use MyInvoice\Action\Payroll\PayrollHealthInsurerBulkAction;
use MyInvoice\Action\Payroll\PayrollStatutoryEvidenceBulkDefaultsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\PayrollTestContainer;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Hromadné akce agentury z pohledu průchodu: oprávnění, izolace firem,
 * prázdný a velký výběr (150 dohod), opakované odeslání.
 *
 * Každá akce se volá přes svou HTTP akci (stejná cesta jako dialog), takže
 * se ověřuje i odpověď, kterou uživatel uvidí. Pravidla:
 *  - role bez práva dostane 403 a nic se nezapíše (žádný tichý úspěch),
 *  - prázdný výběr je chyba vstupu (422) s hláškou, ne 500 ani 200,
 *  - cizí ID ve výběru nic nezapíše do cizí firmy a vrátí se jako neúspěch
 *    řádku nebo chyba vstupu, nikdy jako provedený zápis,
 *  - 150 vybraných projde jedním voláním,
 *  - opakované odeslání téhož výběru nic nezmění.
 */
#[Group('integration')]
final class PayrollE2eNavBulkActionsMatrixTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const LARGE = 150;
    private const PERIOD_START = '2026-02-01';

    private Connection $db;
    private ContainerInterface $container;
    private int $userId;
    private int $supplierId;
    private int $foreignSupplierId;
    private int $officeId;
    /** @var list<array{0:int,1:int}> employee_id, employment_id */
    private array $own = [];
    /** @var array{0:int,1:int} */
    private array $foreign;

    protected function setUp(): void
    {
        $this->container = PayrollTestContainer::build();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $this->foreignSupplierId = $this->createIsolatedSupplier($pdo, $source);
        foreach ([$this->supplierId, $this->foreignSupplierId] as $supplierId) {
            $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$supplierId]);
            $pdo->prepare(
                'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
                 VALUES (?, "setup", "2026-01-01", ?, NOW())'
            )->execute([$supplierId, $this->userId]);
        }
        $this->officeId = $this->office($this->supplierId);
        $foreignOffice = $this->office($this->foreignSupplierId);
        for ($i = 1; $i <= self::LARGE; $i++) {
            $this->own[] = $this->dpp($this->supplierId, $this->officeId, sprintf('Syntetický Brigádník %03d', $i));
        }
        $this->foreign = $this->dpp($this->foreignSupplierId, $foreignOffice, 'Syntetická Cizí');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    /** @return iterable<string,array{0:string}> */
    public static function actions(): iterable
    {
        foreach (['terms', 'workplace', 'probable', 'statutory', 'insurer'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('actions')]
    public function testRoleWithoutWriteGetsForbiddenAndNothingIsWritten(string $kind): void
    {
        $before = $this->fingerprint($this->supplierId);
        $response = $this->call($kind, $this->ids($kind, array_slice($this->own, 0, 3)), 'readonly');

        self::assertSame(403, $response->getStatusCode(), $this->body($response));
        self::assertNotSame('', $this->errorMessage($response), 'Odmítnutí bez hlášky.');
        self::assertSame($before, $this->fingerprint($this->supplierId), 'Odmítnutý požadavek přesto zapsal.');
    }

    #[DataProvider('actions')]
    public function testEmptySelectionIsValidationErrorWithMessage(string $kind): void
    {
        $response = $this->call($kind, [], 'accountant');

        self::assertSame(422, $response->getStatusCode(), $this->body($response));
        self::assertNotSame('', $this->errorMessage($response), 'Prázdný výběr bez hlášky.');
    }

    #[DataProvider('actions')]
    public function testForeignIdInSelectionNeverWritesIntoTheOtherCompany(string $kind): void
    {
        $foreignBefore = $this->fingerprint($this->foreignSupplierId);
        $mine = $this->own[0];
        $response = $this->call($kind, $this->ids($kind, [$mine, $this->foreign]), 'accountant');

        self::assertNotSame(500, $response->getStatusCode(), $this->body($response));
        self::assertSame($foreignBefore, $this->fingerprint($this->foreignSupplierId), 'Hromadná akce zapsala do cizí firmy.');
        if ($response->getStatusCode() === 200) {
            $result = $this->rowsOf($kind, $response);
            self::assertNotContains($this->foreign[$this->idIndex($kind)], $result['applied'], 'Cizí ID vykázané jako provedené.');
            self::assertContains($this->foreign[$this->idIndex($kind)], $result['failed'], 'Cizí ID tiše zmizelo z výsledku: ' . $this->body($response));
            self::assertContains($mine[$this->idIndex($kind)], $result['applied'], 'Vlastní řádek neprošel kvůli cizímu.');
        } else {
            self::assertSame(422, $response->getStatusCode(), $this->body($response));
        }
    }

    #[DataProvider('actions')]
    public function testLargeSelectionAppliesInOneCallAndRepeatChangesNothing(string $kind): void
    {
        $ids = $this->ids($kind, $this->own);
        $started = microtime(true);
        $first = $this->call($kind, $ids, 'accountant');
        $elapsed = microtime(true) - $started;

        self::assertSame(200, $first->getStatusCode(), $this->body($first));
        $result = $this->rowsOf($kind, $first);
        self::assertCount(self::LARGE, $result['applied'], $this->body($first));
        self::assertSame([], $result['failed']);
        self::assertLessThan(60.0, $elapsed, 'Hromadná akce nad 150 vztahy trvá příliš dlouho.');
        if (getenv('MYINVOICE_NAV_TIMING') === '1') {
            fwrite(STDERR, sprintf("%s: %d vztahů za %.2f s\n", $kind, self::LARGE, $elapsed));
        }

        $after = $this->fingerprint($this->supplierId);
        $again = $this->call($kind, $ids, 'accountant');
        self::assertSame(200, $again->getStatusCode(), $this->body($again));
        $repeat = $this->rowsOf($kind, $again);
        self::assertSame([], $repeat['failed'], substr($this->body($again), 0, 1500));
        self::assertSame($after, $this->fingerprint($this->supplierId), 'Opakované odeslání téhož výběru znovu zapsalo.');
    }

    /**
     * @param list<array{0:int,1:int}> $people
     * @return list<int>
     */
    private function ids(string $kind, array $people): array
    {
        return array_map(fn (array $person): int => $person[$this->idIndex($kind)], $people);
    }

    private function idIndex(string $kind): int
    {
        return in_array($kind, ['statutory', 'insurer'], true) ? 0 : 1;
    }

    /** @param list<int> $ids */
    private function call(string $kind, array $ids, string $role): ResponseInterface
    {
        [$class, $method, $uri, $body] = match ($kind) {
            'terms' => [PayrollEmploymentTermsBulkEditAction::class, 'apply', '/api/payroll/employments/terms-bulk/apply', [
                'period_start' => self::PERIOD_START,
                'mode' => 'fill',
                'changes' => ['cz_isco_code' => '43111'],
                'employment_ids' => $ids,
            ]],
            'workplace' => [PayrollEmploymentWorkplaceBulkFillAction::class, 'apply', '/api/payroll/employments/workplace-bulk/apply', [
                'period_start' => self::PERIOD_START,
                'municipality_code' => '554782',
                'country_code' => 'CZ',
                'employment_ids' => $ids,
            ]],
            'probable' => [PayrollAbsenceAction::class, 'setProbableEarningBulk', '/api/payroll/time/averages/probable-bulk', [
                'year' => 2026,
                'quarter' => 1,
                'probable_hourly_minor' => 15_000,
                'rationale' => 'Sjednaná sazba brigádníků 150 Kč za hodinu.',
                'employment_ids' => $ids,
            ]],
            'statutory' => [PayrollStatutoryEvidenceBulkDefaultsAction::class, 'apply', '/api/payroll/statutory-evidence/bulk-defaults/apply', [
                'effective_on' => self::PERIOD_START,
                'employee_ids' => $ids,
                'sections' => ['tax_residences', 'social_jurisdictions', 'social_discount_claims'],
                'record_unsigned_declaration' => false,
            ]],
            'insurer' => [PayrollHealthInsurerBulkAction::class, 'apply', '/api/payroll/statutory-evidence/health-insurer-bulk/apply', [
                'assignments' => array_map(
                    static fn (int $id): array => ['employee_id' => $id, 'insurer_code' => '111', 'effective_from' => '2026-01-01'],
                    $ids,
                ),
            ]],
        };
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody($body);

        return $this->container->get($class)->{$method}($request, new Response());
    }

    /**
     * Provedené a neúspěšné řádky v jednotném tvaru (ID vztahu nebo osoby).
     *
     * @return array{applied:list<int>,failed:list<int>}
     */
    private function rowsOf(string $kind, ResponseInterface $response): array
    {
        $body = $this->json($response);
        $result = $body['result'] ?? $body;
        $ids = static fn (array $rows, string $key): array => array_values(array_map(
            static fn (mixed $row): int => is_array($row) ? (int) ($row[$key] ?? 0) : (int) $row,
            $rows,
        ));

        return match ($kind) {
            // Opakované odeslání vrací řádek jako přeskočený „není co měnit".
            'terms', 'workplace' => [
                'applied' => [
                    ...$ids($result['applied'] ?? [], 'employment_id'),
                    ...$ids(array_filter($result['skipped'] ?? [], self::alreadyDone(...)), 'employment_id'),
                ],
                'failed' => [
                    ...$ids($result['failed'] ?? [], 'employment_id'),
                    ...$ids(array_filter($result['skipped'] ?? [], static fn (array $row): bool => !self::alreadyDone($row)), 'employment_id'),
                ],
            ],
            'probable' => [
                'applied' => [...$ids($result['applied'] ?? [], 'employment_id'), ...$ids($result['unchanged'] ?? [], 'employment_id')],
                'failed' => $ids($result['failed'] ?? [], 'employment_id'),
            ],
            'statutory' => [
                'applied' => [
                    ...$ids($result['applied'] ?? [], 'employee_id'),
                    ...$ids(array_filter($result['skipped'] ?? [], self::alreadyDone(...)), 'employee_id'),
                ],
                'failed' => [
                    ...$ids($result['failed'] ?? [], 'employee_id'),
                    ...$ids(array_filter($result['skipped'] ?? [], static fn (array $row): bool => !self::alreadyDone($row)), 'employee_id'),
                ],
            ],
            'insurer' => [
                'applied' => $ids($result['applied'] ?? [], 'employee_id'),
                'failed' => $ids($result['failed'] ?? [], 'employee_id'),
            ],
        };
    }

    /** @param array<string,mixed> $row */
    private static function alreadyDone(array $row): bool
    {
        return in_array($row['reason'] ?? null, ['nothing_to_change', 'verified'], true)
            || ($row['reasons'] ?? null) === ['nothing_to_add'];
    }

    /** Otisk mzdových dat firmy, který se změní při jakémkoli zápisu hromadné akce. */
    private function fingerprint(int $supplierId): string
    {
        $parts = [];
        foreach ([
            'SELECT id, row_version, cz_isco_code, jmhz_workplace_municipality_code, probable_hourly_earning_minor, effective_from FROM payroll_employment_terms WHERE supplier_id = ? ORDER BY id',
            'SELECT id, status FROM payroll_average_earning_snapshots WHERE supplier_id = ? ORDER BY id',
            'SELECT id, effective_from, effective_to FROM payroll_person_tax_residences WHERE supplier_id = ? ORDER BY id',
            'SELECT id, effective_from, effective_to FROM payroll_person_social_jurisdictions WHERE supplier_id = ? ORDER BY id',
            'SELECT id, insurer_code, effective_from, effective_to FROM payroll_person_health_coverage_history WHERE supplier_id = ? ORDER BY id',
        ] as $sql) {
            $statement = $this->db->pdo()->prepare($sql);
            $statement->execute([$supplierId]);
            $parts[] = $statement->fetchAll(PDO::FETCH_NUM);
        }

        return hash('sha256', (string) json_encode($parts));
    }

    private function office(int $supplierId): int
    {
        $this->db->pdo()->prepare('INSERT INTO payroll_offices (supplier_id, code, name, is_active) VALUES (?, "NAV", "Syntetická účtárna", 1)')
            ->execute([$supplierId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{0:int,1:int} */
    private function dpp(int $supplierId, int $officeId, string $name): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, ?, 'employee', 1)")
            ->execute([$supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_employee_profiles (supplier_id, employee_id, profile_status) VALUES (?, ?, "ready")')
            ->execute([$supplierId, $employeeId]);
        $pdo->prepare(
            "INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type, status, start_date, actual_start_date, is_primary)
             VALUES (?, ?, ?, ?, 'dpp', 'active', '2026-01-01', '2026-01-01', 1)"
        )->execute([$supplierId, $employeeId, $officeId, 'NAV-' . $employeeId]);
        $employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from, planned_start_on, actual_start_on,
                 weekly_hours, workload_basis_points, social_insurance_participation,
                 health_insurance_participation, tax_regime, tax_declaration_signed, is_primary, activity_code)
             VALUES (?, ?, ?, '2026-01-01', '2026-01-01', '2026-01-01', 10, 2500, 'automatic', 'automatic', 'advance', 0, 1, '1')"
        )->execute([$supplierId, $employmentId, $officeId]);

        return [$employeeId, $employmentId];
    }

    private function errorMessage(ResponseInterface $response): string
    {
        $error = $this->json($response)['error'] ?? null;

        return trim(is_array($error) ? (string) ($error['message'] ?? '') : '');
    }

    private function body(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return (string) $response->getBody();
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode($this->body($response), true);

        return is_array($decoded) ? $decoded : [];
    }
}

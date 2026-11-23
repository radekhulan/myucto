<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentAction;
use MyInvoice\Action\Payroll\PayrollEmploymentTermsBulkEditAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\PayrollEmploymentTermsBulkEdit;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Hromadná úprava podmínek vybraných vztahů nad skutečnými tabulkami: zápis
 * je oprava platné verze (žádná nová verze), režim „doplnit" nesahá na
 * vyplněné údaje a zamčený běh vztah vyřadí s důvodem.
 */
#[Group('integration')]
final class PayrollEmploymentTermsBulkEditTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06-01';

    private Connection $db;
    private PayrollEmploymentAction $employmentAction;
    private PayrollEmploymentTermsBulkEditAction $action;
    private PayrollEmploymentTermsBulkEdit $bulk;
    private int $supplierId;
    private int $officeId;
    private int $userId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_employment_terms')) {
            $this->markTestSkipped('Chybí tabulka podmínek pracovního vztahu.');
        }
        $this->employmentAction = $container->get(PayrollEmploymentAction::class);
        $this->action = $container->get(PayrollEmploymentTermsBulkEditAction::class);
        $this->bulk = $container->get(PayrollEmploymentTermsBulkEdit::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $office = $pdo->prepare(
            "INSERT INTO payroll_offices (supplier_id, code, name, is_active) VALUES (?, 'MAIN', 'Hlavní účtárna', 1)",
        );
        $office->execute([$this->supplierId]);
        $this->officeId = (int) $pdo->lastInsertId();
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

    public function testFillModeWritesOnlyEmptyFieldsAsCorrection(): void
    {
        $empty = $this->createDpp('Syntetická Prázdná', 'DPP-EMPTY', []);
        $filled = $this->createDpp('Syntetický Vyplněný', 'DPP-FILLED', [
            'remuneration_type' => 'hourly',
            'hourly_rate_minor' => 18000,
            'remuneration_basis_kind' => 'agreement',
            'remuneration_basis' => 'Syntetická dohoda',
            'cz_isco_code' => '43111',
        ]);

        $preview = $this->bulk->preview($this->supplierId, self::PERIOD, 'dpp', null);
        self::assertSame(2, $preview['summary']['editable']);
        self::assertContains('T', array_column($preview['activity_codes'], 'code'));
        self::assertNotContains('1', array_column($preview['activity_codes'], 'code'));

        $result = $this->bulk->apply(
            $this->supplierId,
            self::PERIOD,
            PayrollEmploymentTermsBulkEdit::MODE_FILL,
            ['hourly_rate_minor' => 25000, 'cz_isco_code' => '25120'],
            $preview['editable_employment_ids'],
            $this->userId,
            null,
            null,
        );

        self::assertSame(
            ['applied' => 1, 'skipped' => 1, 'failed' => 0],
            $result['counts'],
            json_encode($result, JSON_UNESCAPED_UNICODE),
        );
        self::assertSame($empty, $result['applied'][0]['employment_id']);
        self::assertSame(['hourly_rate', 'cz_isco_code'], $result['applied'][0]['fields']);
        self::assertSame('nothing_to_change', $result['skipped'][0]['reason']);
        self::assertSame('Syntetický Vyplněný', $result['skipped'][0]['full_name']);

        $term = $this->term($empty);
        self::assertSame('hourly', $term['remuneration_type']);
        self::assertSame(25000, (int) $term['hourly_rate_minor']);
        self::assertSame('agreement', $term['remuneration_basis_kind']);
        self::assertSame('25120', $term['cz_isco_code']);
        self::assertSame(1, $this->termCount($empty), 'Oprava platné verze nesmí založit novou verzi.');

        $kept = $this->term($filled);
        self::assertSame(18000, (int) $kept['hourly_rate_minor']);
        self::assertSame('43111', $kept['cz_isco_code']);
    }

    public function testOverwriteModeReplacesFilledValues(): void
    {
        $filled = $this->createDpp('Syntetický Vyplněný', 'DPP-FILLED', [
            'remuneration_type' => 'hourly',
            'hourly_rate_minor' => 18000,
            'remuneration_basis_kind' => 'agreement',
            'remuneration_basis' => 'Syntetická dohoda',
            'cz_isco_code' => '43111',
        ]);

        $result = $this->bulk->apply(
            $this->supplierId,
            self::PERIOD,
            PayrollEmploymentTermsBulkEdit::MODE_OVERWRITE,
            [
                'hourly_rate_minor' => 25000,
                'probable_hourly_earning_minor' => 26000,
                'probable_earning_rationale' => 'Syntetická sazba stejné práce',
                'activity_code' => 'U',
            ],
            [$filled],
            $this->userId,
            null,
            null,
        );

        self::assertSame(['applied' => 1, 'skipped' => 0, 'failed' => 0], $result['counts'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $term = $this->term($filled);
        self::assertSame(25000, (int) $term['hourly_rate_minor']);
        self::assertSame('Syntetická dohoda', $term['remuneration_basis']);
        self::assertSame(26000, (int) $term['probable_hourly_earning_minor']);
        self::assertSame('Syntetická sazba stejné práce', $term['probable_earning_rationale']);
        self::assertSame('U', $term['activity_code']);
        self::assertSame('43111', $term['cz_isco_code']);
        self::assertSame(1, $this->termCount($filled));
    }

    /**
     * Ruční účast nebo srážkový režim výpočet vždy zastaví
     * (`*_override_unsupported`); hromadně se vrátí na automatickou účast
     * a zálohu, ze které výpočet zákonné zařazení odvodí sám.
     */
    public function testUnsupportedOverridesAreResetToStatutoryDefaults(): void
    {
        $overridden = $this->createDpp('Syntetický Přepsaný', 'DPP-OVR', [
            'social_insurance_participation' => 'excluded',
            'health_insurance_participation' => 'excluded',
            'tax_regime' => 'withholding',
        ]);
        $plain = $this->createDpp('Syntetický Automatický', 'DPP-AUTO', []);

        $preview = $this->bulk->preview($this->supplierId, self::PERIOD, 'dpp', [$overridden, $plain]);
        $flags = array_column($preview['items'], 'unsupported_overrides', 'employment_id');
        self::assertSame(
            ['social_insurance_participation', 'health_insurance_participation', 'tax_regime'],
            $flags[$overridden],
        );
        self::assertSame([], $flags[$plain]);

        $result = $this->bulk->apply(
            $this->supplierId,
            self::PERIOD,
            PayrollEmploymentTermsBulkEdit::MODE_FILL,
            ['reset_unsupported_overrides' => true],
            [$overridden, $plain],
            $this->userId,
            null,
            null,
        );

        self::assertSame(['applied' => 1, 'skipped' => 1, 'failed' => 0], $result['counts'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $stmt = $this->db->pdo()->prepare(
            'SELECT social_insurance_participation, health_insurance_participation, tax_regime
               FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?
              ORDER BY effective_from DESC, id DESC LIMIT 1',
        );
        $stmt->execute([$this->supplierId, $overridden]);
        self::assertSame([
            'social_insurance_participation' => 'automatic',
            'health_insurance_participation' => 'automatic',
            'tax_regime' => 'advance',
        ], $stmt->fetch(\PDO::FETCH_ASSOC));
        self::assertSame(1, $this->termCount($overridden));
    }

    public function testActivityCodeOutsideRelationFamilyIsSkipped(): void
    {
        $employment = $this->createDpp('Syntetická Dohoda', 'DPP-ACT', []);

        $result = $this->bulk->apply(
            $this->supplierId,
            self::PERIOD,
            PayrollEmploymentTermsBulkEdit::MODE_OVERWRITE,
            ['activity_code' => '1'],
            [$employment],
            $this->userId,
            null,
            null,
        );

        self::assertSame('activity_code_relation_mismatch', $result['skipped'][0]['reason']);
    }

    public function testProbableEarningWithoutRationaleIsRefused(): void
    {
        $employment = $this->createDpp('Syntetická Dohoda', 'DPP-PE', []);

        $this->expectException(\InvalidArgumentException::class);
        $this->bulk->apply(
            $this->supplierId,
            self::PERIOD,
            PayrollEmploymentTermsBulkEdit::MODE_FILL,
            ['probable_hourly_earning_minor' => 26000],
            [$employment],
            $this->userId,
            null,
            null,
        );
    }

    public function testApprovedRunLocksTheEmployment(): void
    {
        $employment = $this->createDpp('Syntetická Zamčená', 'DPP-LOCK', []);
        $this->approvedRun($employment, 'approved');

        $preview = $this->bulk->preview($this->supplierId, self::PERIOD, null, null);
        self::assertSame('excluded', $preview['items'][0]['state']);
        self::assertSame('run_locked', $preview['items'][0]['reason']);
        self::assertSame([], $preview['editable_employment_ids']);

        $result = $this->bulk->apply(
            $this->supplierId,
            self::PERIOD,
            PayrollEmploymentTermsBulkEdit::MODE_FILL,
            ['hourly_rate_minor' => 25000],
            [$employment],
            $this->userId,
            null,
            null,
        );

        self::assertSame(['applied' => 0, 'skipped' => 1, 'failed' => 0], $result['counts']);
        self::assertSame('run_locked', $result['skipped'][0]['reason']);
        self::assertNull($this->term($employment)['hourly_rate_minor']);
    }

    /**
     * Vyplacený leden: karta by chtěla novou verzi od prvního nezúčtovaného
     * měsíce, a totéž udělá hromadná úprava. Lednová verze zůstane netknutá,
     * nová od února přenese uložené údaje a nese změněné pole.
     */
    public function testSettledMonthCreatesNewVersionFromFirstUnsettledMonth(): void
    {
        $employment = $this->createDpp('Syntetická Vyplacená', 'DPP-PAID', [
            'effective_from' => '2026-01-01',
            'contract_signed_on' => '2025-12-20',
            'planned_start_on' => '2026-01-01',
            'cz_isco_code' => '43111',
        ]);
        $this->approvedRun($employment, 'paid', '2026-01-01');

        $preview = $this->bulk->preview($this->supplierId, '2026-02-01', 'dpp', null);
        self::assertSame('editable', $preview['items'][0]['state']);
        self::assertSame('2026-02-01', $preview['items'][0]['new_version_from']);

        $result = $this->bulk->apply(
            $this->supplierId,
            '2026-02-01',
            PayrollEmploymentTermsBulkEdit::MODE_FILL,
            ['hourly_rate_minor' => 25000],
            [$employment],
            $this->userId,
            null,
            null,
        );

        self::assertSame(['applied' => 1, 'skipped' => 0, 'failed' => 0], $result['counts'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame('new_version', $result['applied'][0]['kind']);
        self::assertSame('2026-02-01', $result['applied'][0]['effective_from']);
        self::assertSame(2, $this->termCount($employment));

        $latest = $this->term($employment);
        self::assertSame('2026-02-01', $latest['effective_from']);
        self::assertSame(25000, (int) $latest['hourly_rate_minor']);
        self::assertSame('43111', $latest['cz_isco_code']);
        self::assertSame('T', $latest['activity_code']);

        $january = $this->db->pdo()->prepare(
            'SELECT effective_to, hourly_rate_minor FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ? AND effective_from = ?',
        );
        $january->execute([$this->supplierId, $employment, '2026-01-01']);
        $old = $january->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('2026-01-31', $old['effective_to']);
        self::assertNull($old['hourly_rate_minor']);
    }

    public function testApprovedRunAfterSettledMonthStillLocks(): void
    {
        $employment = $this->createDpp('Syntetická Schválená', 'DPP-APPR', [
            'effective_from' => '2026-01-01',
            'contract_signed_on' => '2025-12-20',
            'planned_start_on' => '2026-01-01',
        ]);
        $this->approvedRun($employment, 'paid', '2026-01-01');
        $this->approvedRun($employment, 'approved', '2026-02-01');

        $result = $this->bulk->apply(
            $this->supplierId,
            '2026-02-01',
            PayrollEmploymentTermsBulkEdit::MODE_FILL,
            ['hourly_rate_minor' => 25000],
            [$employment],
            $this->userId,
            null,
            null,
        );

        self::assertSame('run_locked', $result['skipped'][0]['reason']);
        self::assertSame(1, $this->termCount($employment));
    }

    /**
     * Vztah, kterému docházka importuje hodinovou mzdu jako hotovou částku
     * (MZDA_HODINOVA_DOCH), nesmí hromadná sazba přepnout na hodinový výpočet:
     * PayrollHourlyWageCollisionGuard by pak každý další import odmítl.
     * Pravděpodobný výdělek zadaný ve stejné dávce se zapíše.
     */
    public function testImportedHourlyWageKeepsRateAndStillAppliesProbableEarning(): void
    {
        $employment = $this->createDpp('Syntetická Docházka', 'DPP-DOCH', []);
        $componentId = $this->hourlyWageComponent();
        $this->importedInput($employment, $componentId, '2026-05-01');

        $result = $this->bulk->apply(
            $this->supplierId,
            self::PERIOD,
            PayrollEmploymentTermsBulkEdit::MODE_FILL,
            [
                'hourly_rate_minor' => 25000,
                'probable_hourly_earning_minor' => 26000,
                'probable_earning_rationale' => 'Syntetický průměr stejné práce',
            ],
            [$employment],
            $this->userId,
            null,
            null,
        );

        self::assertSame(['applied' => 1, 'skipped' => 0, 'failed' => 0], $result['counts'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame(['probable_earning'], $result['applied'][0]['fields']);
        self::assertSame(['hourly_rate_imported_wage'], $result['applied'][0]['warnings']);
        $term = $this->term($employment);
        self::assertNull($term['remuneration_type']);
        self::assertNull($term['hourly_rate_minor']);
        self::assertSame(26000, (int) $term['probable_hourly_earning_minor']);

        // Další měsíční import téže složky projde kontrolou zdroje mzdy.
        $guard = \MyInvoice\Tests\Support\PayrollTestContainer::build()
            ->get(\MyInvoice\Repository\Payroll\PayrollHourlyWageCollisionGuard::class);
        $guard->externalInputs($this->supplierId, self::PERIOD, [
            ['employment_id' => $employment, 'component_id' => $componentId],
        ]);

        // Jen sazba: vztah se vynechá s důvodem, nic se nezapíše.
        $rateOnly = $this->bulk->apply(
            $this->supplierId,
            self::PERIOD,
            PayrollEmploymentTermsBulkEdit::MODE_OVERWRITE,
            ['hourly_rate_minor' => 25000],
            [$employment],
            $this->userId,
            null,
            null,
        );
        self::assertSame('hourly_rate_imported_wage', $rateOnly['skipped'][0]['reason']);
    }

    /**
     * Karta vztahu: přepnutí na hodinovou sazbu u vztahu s importovanou mzdou
     * se bez výslovného potvrzení neuloží (oprava i nová verze). Odpověď
     * vysvětlí důvod; s potvrzením se uloží.
     */
    public function testCardRefusesHourlySwitchOverImportedWageUntilConfirmed(): void
    {
        $employment = $this->createDpp('Syntetická Karta', 'DPP-KARTA', []);
        $this->importedInput($employment, $this->hourlyWageComponent(), '2026-06-01');

        foreach (['correctTerms', 'addTerms'] as $method) {
            $refused = $this->cardSave($method, $employment, []);
            $body = $this->bodyOf($refused);
            self::assertSame(422, $refused->getStatusCode(), $method . ': ' . $body);
            $error = json_decode($body, true)['error'];
            self::assertSame('hourly_rate_imported_wage', $error['details']['code'], $body);
            self::assertSame('remuneration_type', $error['details']['field']);
            self::assertStringContainsString('importují z docházky', $error['message']);
            self::assertNull($this->term($employment)['remuneration_type']);
        }

        $confirmed = $this->cardSave('correctTerms', $employment, ['confirm_imported_wage_switch' => true]);
        self::assertSame(200, $confirmed->getStatusCode(), $this->bodyOf($confirmed));
        self::assertSame('hourly', $this->term($employment)['remuneration_type']);
    }

    public function testCardSwitchesToHourlyWithoutImportedWage(): void
    {
        $employment = $this->createDpp('Syntetická Bez Importu', 'DPP-KARTA2', []);

        $saved = $this->cardSave('correctTerms', $employment, []);

        self::assertSame(200, $saved->getStatusCode(), $this->bodyOf($saved));
        self::assertSame(25000, (int) $this->term($employment)['hourly_rate_minor']);
    }

    /** @param array<string,mixed> $extra */
    private function cardSave(string $method, int $employmentId, array $extra): ResponseInterface
    {
        $current = $this->db->pdo()->prepare('SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $current->execute([$this->supplierId, $employmentId]);
        $repository = \MyInvoice\Tests\Support\PayrollTestContainer::build()
            ->get(\MyInvoice\Repository\Payroll\PayrollEmploymentRepository::class);
        $terms = $repository->currentTerms($this->supplierId, $employmentId);
        self::assertIsArray($terms);
        $body = [
            ...\MyInvoice\Service\Payroll\PayrollEmploymentTermsBody::fromCurrent($terms, 'Syntetická změna'),
            'effective_from' => $method === 'addTerms' ? '2026-07-01' : (string) $terms['effective_from'],
            'remuneration_type' => 'hourly',
            'hourly_rate_minor' => 25000,
            'remuneration_basis_kind' => 'agreement',
            'remuneration_basis' => 'Syntetická dohoda',
            'row_version' => (int) $current->fetchColumn(),
            ...$extra,
        ];
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', "/api/payroll/employments/{$employmentId}/terms")
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody($body);

        return $this->employmentAction->{$method}($request, new Response(), ['id' => (string) $employmentId]);
    }

    public function testWritePermissionAndSessionAreRequired(): void
    {
        $employment = $this->createDpp('Syntetická Dohoda', 'DPP-RBAC', []);
        $body = [
            'period_start' => self::PERIOD,
            'mode' => 'fill',
            'changes' => ['hourly_rate_minor' => 25000],
            'employment_ids' => [$employment],
        ];

        $readonly = $this->action->apply($this->request($body, 'readonly'), new Response());
        self::assertSame(403, $readonly->getStatusCode(), $this->bodyOf($readonly));

        $bearer = $this->action->apply($this->request($body, 'accountant', 'bearer'), new Response());
        self::assertSame(403, $bearer->getStatusCode(), $this->bodyOf($bearer));

        self::assertNull($this->term($employment)['hourly_rate_minor']);

        $allowed = $this->action->apply($this->request($body, 'accountant'), new Response());
        self::assertSame(200, $allowed->getStatusCode(), $this->bodyOf($allowed));
        self::assertSame(25000, (int) $this->term($employment)['hourly_rate_minor']);
    }

    /**
     * „Ukončit skončené dohody" proběhne dřív než JMHZ. Ukončená dohoda pak
     * musí jít doplnit v údajích pro JMHZ, ne ve mzdě, a datum konce zůstane.
     */
    public function testEndedAgreementGetsJmhzFieldsButNotRate(): void
    {
        $employment = $this->createDpp('Syntetická Skončená', 'DPP-ENDED', []);
        $this->endEmployment($employment, '2026-06-30');

        $preview = $this->bulk->preview($this->supplierId, self::PERIOD, 'dpp', null);
        self::assertSame([$employment], $preview['editable_employment_ids'], json_encode($preview['items'], JSON_UNESCAPED_UNICODE));

        $result = $this->bulk->apply(
            $this->supplierId,
            self::PERIOD,
            PayrollEmploymentTermsBulkEdit::MODE_FILL,
            ['cz_isco_code' => '25120'],
            [$employment],
            $this->userId,
            null,
            null,
        );
        self::assertSame(['applied' => 1, 'skipped' => 0, 'failed' => 0], $result['counts'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame('25120', $this->term($employment)['cz_isco_code']);
        $state = $this->db->pdo()->prepare('SELECT status, end_date FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $state->execute([$this->supplierId, $employment]);
        self::assertSame(['status' => 'ended', 'end_date' => '2026-06-30'], $state->fetch(\PDO::FETCH_ASSOC));

        $rate = $this->bulk->apply(
            $this->supplierId,
            self::PERIOD,
            PayrollEmploymentTermsBulkEdit::MODE_FILL,
            ['hourly_rate_minor' => 25000],
            [$employment],
            $this->userId,
            null,
            null,
        );
        self::assertSame('employment_closed', $rate['skipped'][0]['reason'] ?? null, json_encode($rate, JSON_UNESCAPED_UNICODE));
        self::assertNull($this->term($employment)['hourly_rate_minor']);
    }

    public function testEndedAgreementWithFrozenJmhzReportIsExcluded(): void
    {
        $employment = $this->createDpp('Syntetická Vykázaná', 'DPP-REPORTED', []);
        $this->endEmployment($employment, '2026-06-30');
        $this->approvedRun($employment, 'approved');
        $this->freezeJmhz();

        $preview = $this->bulk->preview($this->supplierId, self::PERIOD, 'dpp', null);
        self::assertSame([], $preview['editable_employment_ids']);
        self::assertSame('employment_reported', $preview['items'][0]['reason']);
    }

    private function endEmployment(int $employmentId, string $endOn): void
    {
        $this->db->pdo()->prepare(
            "UPDATE payroll_employments SET status = 'ended', end_date = ?, row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?",
        )->execute([$endOn, $this->supplierId, $employmentId]);
    }

    /** Ostrá zmrazená příprava JMHZ z poslední revize běhu firmy. */
    private function freezeJmhz(): void
    {
        $pdo = $this->db->pdo();
        $revision = $pdo->prepare(
            'SELECT revision.id, revision.run_id, run.period_start
               FROM payroll_run_revisions revision
               JOIN payroll_runs run ON run.supplier_id = revision.supplier_id AND run.id = revision.run_id
              WHERE revision.supplier_id = ?
              ORDER BY revision.id DESC LIMIT 1',
        );
        $revision->execute([$this->supplierId]);
        $source = $revision->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($source);
        $pdo->prepare(
            "INSERT INTO payroll_jmhz_preparation_snapshots
                (supplier_id, environment, run_id, source_revision_id, period_start, scenario_key,
                 builder_version, readiness_status, issue_count, source_manifest_json,
                 source_manifest_sha256, readiness_json, readiness_sha256, snapshot_ciphertext,
                 snapshot_fingerprint, request_fingerprint, idempotency_key_hash, created_by)
             VALUES (?, 'production', ?, ?, ?, 'scenario_1', 'jmhz-preparation-source.v1',
                     'source_ready', 0, '{}', ?, '{}', ?, 'enc:v2:synteticky', ?, ?, ?, ?)",
        )->execute([
            $this->supplierId,
            (int) $source['run_id'],
            (int) $source['id'],
            (string) $source['period_start'],
            hash('sha256', 'manifest'),
            hash('sha256', 'readiness'),
            hash('sha256', 'snapshot'),
            hash('sha256', 'request' . $source['id']),
            hash('sha256', 'idempotency' . $source['id'], true),
            $this->userId,
        ]);
        \MyInvoice\Tests\Support\JmhzFrozenSubmissionFixture::freeze(
            $pdo,
            $this->supplierId,
            (string) $source['period_start'],
            hash('sha256', 'snapshot'),
            $this->userId,
        );
    }

    /** @param array<string,mixed> $body */
    private function request(array $body, string $role, string $method = 'session'): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payroll/employments/terms-bulk/apply')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $method)
            ->withParsedBody($body);
    }

    private function bodyOf(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return (string) $response->getBody();
    }

    private function approvedRun(int $employmentId, string $status, string $period = self::PERIOD): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                 (supplier_id, period_start, payment_date, status, current_revision_no, row_version)
             VALUES (?, ?, ?, ?, 1, 1)',
        )->execute([
            $this->supplierId,
            $period,
            (new \DateTimeImmutable($period))->modify('+1 month +14 days')->format('Y-m-d'),
            $status,
        ]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_run_revisions
                 (supplier_id, run_id, revision_no, status, schema_version,
                  ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                  idempotency_key_hash)
             VALUES (?, ?, 1, 'approved', 'test', ?, '{}', ?, ?)",
        )->execute([$this->supplierId, $runId, str_repeat('a', 64), str_repeat('b', 64), random_bytes(32)]);
        $revisionId = (int) $pdo->lastInsertId();
        $employeeId = $pdo->prepare('SELECT employee_id FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $employeeId->execute([$this->supplierId, $employmentId]);
        $pdo->prepare(
            "INSERT INTO payroll_run_employments
                 (supplier_id, revision_id, employee_id, employment_id, input_json, input_hash)
             VALUES (?, ?, ?, ?, '{}', ?)",
        )->execute([$this->supplierId, $revisionId, (int) $employeeId->fetchColumn(), $employmentId, str_repeat('c', 64)]);
    }

    private function hourlyWageComponent(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment,
                 jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code,
                 valid_from, is_active)
             VALUES (?, "MZDA_HODINOVA_DOCH", "Hodinová mzda podle docházky", "hourly_wage", "monetary",
                     "regular", "included", "included", "included", "included", "included",
                     "included", "included", "included", "included", "521", "331", "2026-01-01", 1)',
        )->execute([$this->supplierId]);

        return (int) $pdo->lastInsertId();
    }

    private function importedInput(int $employmentId, int $componentId, string $period): void
    {
        $pdo = $this->db->pdo();
        $employee = $pdo->prepare('SELECT employee_id FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $employee->execute([$this->supplierId, $employmentId]);
        $pdo->prepare(
            'INSERT INTO payroll_input_imports
                (supplier_id, period_start, source_kind, source_name, content_hash,
                 status, row_count, accepted_count)
             VALUES (?, ?, "csv", "dochazka-syntetika.csv", UNHEX(SHA2(?, 256)), "accepted", 1, 1)',
        )->execute([$this->supplierId, $period, 'syntetika-hromadne-' . microtime(true)]);
        $importId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id, period_start,
                 amount_minor, source_kind, import_id, external_id, status)
             VALUES (?, ?, ?, ?, ?, 1800000, 'import', ?, 'SYN-DOCH-1', 'draft')",
        )->execute([$this->supplierId, (int) $employee->fetchColumn(), $employmentId, $componentId, $period, $importId]);
    }

    /** @param array<string,mixed> $terms */
    private function createDpp(string $name, string $code, array $terms): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, 'employee', 'dpp', 0, 0, 0, NULL, 0, 1)",
        )->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();

        $response = $this->employmentAction->create(
            (new ServerRequestFactory())
                ->createServerRequest('POST', "/api/payroll/people/{$employeeId}/employments")
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withParsedBody([
                    'code' => $code,
                    'relation_type' => 'dpp',
                    'terms' => [
                        'office_id' => $this->officeId,
                        'effective_from' => self::PERIOD,
                        'contract_signed_on' => '2026-05-20',
                        'planned_start_on' => self::PERIOD,
                        'actual_start_on' => null,
                        'fixed_term_end_on' => '2026-12-31',
                        'weekly_hours' => null,
                        'workload_basis_points' => 10000,
                        'work_place' => null,
                        'regular_workplace' => null,
                        'jmhz_workplace_municipality_code' => null,
                        'jmhz_workplace_country_code' => null,
                        'jmhz_apz_contribution_status' => 'unverified',
                        'jmhz_apz_instrument_code' => null,
                        'jmhz_functional_benefits_status' => 'unverified',
                        'jmhz_temporary_assignment_status' => 'unverified',
                        'cz_isco_code' => null,
                        'activity_code' => 'T',
                        'jmhz_relationship_detail_code' => null,
                        'social_insurance_participation' => 'automatic',
                        'health_insurance_participation' => 'automatic',
                        'tax_regime' => 'advance',
                        'foreign_legislation_country_code' => null,
                        'a1_certificate_until' => null,
                        'risky_work' => false,
                        'tax_declaration_signed' => false,
                        'is_primary' => true,
                        'change_reason' => 'Syntetické podmínky',
                        ...$terms,
                    ],
                ]),
            new Response(),
            ['id' => (string) $employeeId],
        );
        $body = $this->bodyOf($response);
        self::assertSame(201, $response->getStatusCode(), $body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        return (int) $decoded['employment']['id'];
    }

    /** @return array<string,mixed> */
    private function term(int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT effective_from, remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis,
                    probable_hourly_earning_minor, probable_earning_rationale, cz_isco_code, activity_code
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY effective_from DESC, id DESC
              LIMIT 1',
        );
        $stmt->execute([$this->supplierId, $employmentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function termCount(int $employmentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?',
        );
        $stmt->execute([$this->supplierId, $employmentId]);

        return (int) $stmt->fetchColumn();
    }
}

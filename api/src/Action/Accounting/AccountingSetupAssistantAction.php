<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\DemoReadOnlyMiddleware;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\AccountingSetupRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\ExpenseClassificationRuleRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Accounting\Setup\AccountingSetupApprovalService;
use MyInvoice\Service\Accounting\Setup\AccountingSetupAiEnricherInterface;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\BackgroundProcess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AccountingSetupAssistantAction
{
    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly AccountingSetupRepository $setup,
        private readonly AccountingSetupApprovalService $approval,
        private readonly AccountingModeRepository $modes,
        private readonly ChartOfAccountsRepository $chart,
        private readonly ExpenseClassificationRuleRepository $expenseRules,
        private readonly AccountingSetupAiEnricherInterface $aiEnricher,
        private readonly Connection $db,
        private readonly ActivityLogger $activity,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        if (!$this->allowed($request, 'accounting', AccessLevel::READ, $response, $error)) {
            return $error;
        }
        $supplierId = SupplierGuard::currentId($request);
        return Json::ok($response, [
            'runs' => $this->setup->listRuns($supplierId),
            'analysis_jobs' => array_map(
                [self::class, 'jobView'],
                $this->jobs->listForTenant($supplierId, 'accounting_setup_analysis', 10),
            ),
            'reclassification_jobs' => array_map(
                fn (array $job): array => self::jobView($job) + [
                    'rollback_snapshot_available' => $this->setup->hasRollbackSnapshot(
                        $supplierId,
                        (int) $job['id'],
                    ),
                ],
                $this->jobs->listForTenant($supplierId, 'accounting_history_reclassification', 10),
            ),
            'active_expense_rule_count' => count($this->expenseRules->activeFor($supplierId)),
            'ai_available' => $this->aiEnricher->isAvailable($supplierId),
        ]);
    }

    public function startAnalysis(Request $request, Response $response): Response
    {
        if (!$this->allowed($request, 'accounting.templates', AccessLevel::WRITE, $response, $error)) {
            return $error;
        }
        $supplierId = SupplierGuard::currentId($request);
        if (!$this->modes->hasDoubleEntry($supplierId)) {
            return Json::error($response, 'not_double_entry', 'Firma nevede podvojné účetnictví.', 409);
        }
        $this->jobs->reapStale($supplierId, 'accounting_setup_analysis');
        if ($this->hasRunning($supplierId, 'accounting_setup_analysis')) {
            return Json::error($response, 'already_running', 'Analýza už běží.', 409);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $useAi = array_key_exists('use_ai', $body)
            ? filter_var($body['use_ai'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : false;
        $aiSampleLimit = (int) ($body['ai_sample_limit'] ?? 50);
        $dateFrom = self::date($body['date_from'] ?? null);
        $dateTo = self::date($body['date_to'] ?? null);
        if ($useAi === null
            || !in_array($aiSampleLimit, [50, 100, 200], true)
            || (($body['date_from'] ?? null) !== null && $dateFrom === null)
            || (($body['date_to'] ?? null) !== null && $dateTo === null)
            || ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo)) {
            return Json::error($response, 'validation_failed', 'Neplatný rozsah dat.', 422);
        }
        $params = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'months_back' => max(1, min(60, (int) ($body['months_back'] ?? 60))),
            'use_ai' => $useAi,
            'ai_sample_limit' => $aiSampleLimit,
        ];
        $jobId = $this->jobs->create($supplierId, 'accounting_setup_analysis', $params, self::userId($request));
        if (!$this->spawn($jobId)) {
            $this->jobs->markFailed($jobId, 'Worker se nepodařilo spustit.');
            return Json::error($response, 'spawn_failed', 'Analýzu se nepodařilo spustit.', 500);
        }
        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued'], 201);
    }

    public function run(Request $request, Response $response, array $args): Response
    {
        if (!$this->allowed($request, 'accounting', AccessLevel::READ, $response, $error)) {
            return $error;
        }
        $run = $this->setup->findRun(SupplierGuard::currentId($request), (int) ($args['id'] ?? 0));
        return $run === null
            ? Json::error($response, 'not_found', 'Analýza nenalezena.', 404)
            : Json::ok($response, ['run' => $run]);
    }

    public function proposals(Request $request, Response $response, array $args): Response
    {
        if (!$this->allowed($request, 'accounting', AccessLevel::READ, $response, $error)) {
            return $error;
        }
        $supplierId = SupplierGuard::currentId($request);
        $runId = (int) ($args['id'] ?? 0);
        if ($this->setup->findRun($supplierId, $runId) === null) {
            return Json::error($response, 'not_found', 'Analýza nenalezena.', 404);
        }
        $type = (string) ($request->getQueryParams()['type'] ?? '');
        $valid = ['chart_account', 'expense_rule', 'posting_rule', 'bank_rule', 'asset_candidate', 'data_quality'];
        return Json::ok($response, [
            'items' => $this->setup->proposals($supplierId, $runId, in_array($type, $valid, true) ? $type : null),
        ]);
    }

    public function updateProposal(Request $request, Response $response, array $args): Response
    {
        if (!$this->allowed($request, 'accounting.templates', AccessLevel::WRITE, $response, $error)) {
            return $error;
        }
        $supplierId = SupplierGuard::currentId($request);
        $runId = (int) ($args['id'] ?? 0);
        $proposalId = (int) ($args['proposalId'] ?? 0);
        $existing = $this->setup->findProposal($supplierId, $runId, $proposalId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Návrh nenalezen.', 404);
        }
        if ($existing['proposal_type'] === 'bank_rule'
            && !RequestAuthorization::allows($request, 'bank.rules', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pro úpravu bankovních pravidel chybí oprávnění.', 403);
        }
        try {
            [$title, $proposal] = self::editableProposal(
                (string) $existing['proposal_type'],
                (array) $existing['proposal_json'],
                (array) ($request->getParsedBody() ?? []),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, $e->getMessage(), 'Návrh obsahuje neplatné údaje.', 422);
        }
        if ($existing['proposal_type'] === 'chart_account' && ($proposal['create'] ?? true) === false) {
            $replacement = $this->chart->findByCode(
                $supplierId,
                (string) ($proposal['replacement_account_code'] ?? ''),
            );
            if ($replacement === null || empty($replacement['is_active']) || !empty($replacement['is_synthetic'])) {
                return Json::error($response, 'replacement_account_not_postable', 'Náhradní účet musí být aktivní analytický účet.', 422);
            }
        }
        $updated = $existing['proposal_type'] === 'chart_account'
            ? $this->setup->updatePendingChartProposal($supplierId, $runId, $proposalId, $title, $proposal)
            : $this->setup->updatePendingProposal($supplierId, $runId, $proposalId, $title, $proposal);
        if (!$updated) {
            return Json::error($response, 'proposal_locked', 'Schválený nebo uzamčený návrh už nelze upravit.', 409);
        }
        return Json::ok($response, [
            'proposal' => $this->setup->findProposal($supplierId, $runId, $proposalId),
        ]);
    }

    public function approve(Request $request, Response $response, array $args): Response
    {
        if (!$this->allowed($request, 'accounting.templates', AccessLevel::WRITE, $response, $error)) {
            return $error;
        }
        $supplierId = SupplierGuard::currentId($request);
        $runId = (int) ($args['id'] ?? 0);
        $ids = (array) (((array) ($request->getParsedBody() ?? []))['proposal_ids'] ?? []);
        $selected = array_filter(
            $this->setup->proposals($supplierId, $runId),
            static fn (array $proposal): bool => in_array((int) $proposal['id'], array_map('intval', $ids), true),
        );
        if (array_filter($selected, static fn (array $proposal): bool => $proposal['proposal_type'] === 'bank_rule')
            && !RequestAuthorization::allows($request, 'bank.rules', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pro schválení bankovních pravidel chybí oprávnění.', 403);
        }
        try {
            return Json::ok($response, [
                'bundle' => $this->approval->approve($supplierId, $runId, $ids, self::userId($request)),
            ], 201);
        } catch (\Throwable $e) {
            return Json::error($response, $e->getMessage(), 'Návrhy nelze schválit.', 409);
        }
    }

    public function startReclassification(Request $request, Response $response, array $args): Response
    {
        if (!$this->allowed($request, 'accounting.journal.post', AccessLevel::WRITE, $response, $error)) {
            return $error;
        }
        $supplierId = SupplierGuard::currentId($request);
        if (!$this->modes->hasDoubleEntry($supplierId)) {
            return Json::error($response, 'not_double_entry', 'Firma nevede podvojné účetnictví.', 409);
        }
        $bundle = $this->setup->findBundle($supplierId, (int) ($args['id'] ?? 0));
        if ($bundle === null) {
            return Json::error($response, 'not_found', 'Balíček pravidel nenalezen.', 404);
        }
        if ($this->hasRunning($supplierId, 'accounting_history_reclassification')) {
            return Json::error($response, 'already_running', 'Jiná úloha přeúčtování už běží.', 409);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $parsedDryRun = array_key_exists('dry_run', $body)
            ? filter_var($body['dry_run'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : true;
        if ($parsedDryRun === null) {
            return Json::error($response, 'validation_failed', 'Pole dry_run musí být boolean.', 422);
        }
        $dateFrom = self::date($body['date_from'] ?? null);
        $dateTo = self::date($body['date_to'] ?? null);
        $scopeMode = (string) ($body['scope_mode'] ?? 'matched');
        if ((($body['date_from'] ?? null) !== null && ($body['date_from'] ?? null) !== '' && $dateFrom === null)
            || (($body['date_to'] ?? null) !== null && ($body['date_to'] ?? null) !== '' && $dateTo === null)
            || ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo)
            || !in_array($scopeMode, ['matched', 'all'], true)) {
            return Json::error($response, 'validation_failed', 'Neplatné nastavení přeúčtování.', 422);
        }
        $dryRun = $parsedDryRun;
        if (!$dryRun && (int) ($body['dry_run_job_id'] ?? 0) <= 0) {
            return Json::error($response, 'matching_dry_run_required', 'Nejdříve spusťte kontrolu nanečisto.', 422);
        }
        $params = [
            'bundle_id' => (int) $bundle['id'],
            'bundle_hash' => (string) $bundle['bundle_hash'],
            'input_hash' => (string) $bundle['input_hash'],
            'dry_run' => $dryRun,
            'dry_run_job_id' => $dryRun ? null : (int) $body['dry_run_job_id'],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'scope_mode' => $scopeMode,
        ];
        $jobId = $this->jobs->create($supplierId, 'accounting_history_reclassification', $params, self::userId($request));
        if (!$this->spawn($jobId)) {
            $this->jobs->markFailed($jobId, 'Worker se nepodařilo spustit.');
            return Json::error($response, 'spawn_failed', 'Přeúčtování se nepodařilo spustit.', 500);
        }
        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued', 'dry_run' => $dryRun], 201);
    }

    public function job(Request $request, Response $response, array $args): Response
    {
        if (!$this->allowed($request, 'accounting', AccessLevel::READ, $response, $error)) {
            return $error;
        }
        $supplierId = SupplierGuard::currentId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $supplierId);
        if ($job === null || !in_array($job['source'], ['accounting_setup_analysis', 'accounting_history_reclassification'], true)) {
            return Json::error($response, 'not_found', 'Úloha nenalezena.', 404);
        }
        $result = ['job' => self::jobView($job) + ['log_text' => $job['log_text'] ?? null]];
        if ($job['source'] === 'accounting_setup_analysis') {
            $result['run'] = $this->setup->runByJob((int) $job['id']);
        } else {
            $result['items'] = $this->setup->reclassificationItems($supplierId, (int) $job['id']);
            $result['job']['rollback_snapshot_available'] = $this->setup->hasRollbackSnapshot(
                $supplierId,
                (int) $job['id'],
            );
        }
        return Json::ok($response, $result);
    }

    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (DemoReadOnlyMiddleware::enabled($request)) {
            return Json::error($response, 'demo_read_only', 'Demo režim neumožňuje měnit úlohy.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $supplierId);
        if ($job === null || !in_array($job['source'], ['accounting_setup_analysis', 'accounting_history_reclassification'], true)) {
            return Json::error($response, 'not_found', 'Úloha nenalezena.', 404);
        }
        $permission = $job['source'] === 'accounting_setup_analysis'
            ? 'accounting.templates'
            : 'accounting.journal.post';
        if (!$this->allowed($request, $permission, AccessLevel::WRITE, $response, $error)) {
            return $error;
        }
        $this->jobs->requestCancel((int) $job['id'], $supplierId);
        return Json::ok($response, ['ok' => true]);
    }

    public function rollback(Request $request, Response $response, array $args): Response
    {
        if (!$this->allowed($request, 'accounting.journal.post', AccessLevel::WRITE, $response, $error)) {
            return $error;
        }
        $supplierId = SupplierGuard::currentId($request);
        if (!$this->modes->hasDoubleEntry($supplierId)) {
            return Json::error($response, 'not_double_entry', 'Firma nevede podvojné účetnictví.', 409);
        }
        $appliedJobId = (int) ($args['id'] ?? 0);
        $applied = $this->jobs->find($appliedJobId, $supplierId);
        if ($applied === null || $applied['source'] !== 'accounting_history_reclassification'
            || !in_array($applied['status'], ['completed', 'completed_with_warnings'], true)
            || !array_key_exists('dry_run', (array) ($applied['params'] ?? []))
            || ($applied['params'] ?? [])['dry_run'] !== false
            || !empty(($applied['params'] ?? [])['rollback_of_job_id'])) {
            return Json::error($response, 'not_found', 'Přeúčtování nenalezeno.', 404);
        }
        $lockName = self::lifecycleLockName($supplierId, $appliedJobId);
        if (!$this->acquireLifecycleLock($lockName)) {
            return Json::error($response, 'operation_busy', 'S touto zálohou právě pracuje jiná operace.', 409);
        }
        try {
            if ($this->hasRunning($supplierId, 'accounting_history_reclassification')) {
                return Json::error($response, 'already_running', 'Nejdříve nechte běžící úlohu dokončit.', 409);
            }
            if ($this->hasRollbackFor($supplierId, $appliedJobId)) {
                return Json::error($response, 'rollback_already_exists', 'Obnova tohoto přeúčtování už byla spuštěna.', 409);
            }
            if (!$this->setup->hasRollbackSnapshot($supplierId, $appliedJobId)) {
                return Json::error($response, 'rollback_snapshot_missing', 'Záloha původního účtování už není dostupná.', 409);
            }
            $jobId = $this->jobs->create($supplierId, 'accounting_history_reclassification', [
                'rollback_of_job_id' => $appliedJobId,
            ], self::userId($request));
        } finally {
            $this->releaseLifecycleLock($lockName);
        }
        if (!$this->spawn($jobId)) {
            $this->jobs->markFailed($jobId, 'Worker se nepodařilo spustit.');
            return Json::error($response, 'spawn_failed', 'Obnovu se nepodařilo spustit.', 500);
        }
        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued'], 201);
    }

    public function deleteSnapshot(Request $request, Response $response, array $args): Response
    {
        if (!$this->allowed($request, 'accounting.journal.post', AccessLevel::WRITE, $response, $error)) {
            return $error;
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        if (filter_var($body['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true) {
            return Json::error($response, 'confirmation_required', 'Smazání snapshotu vyžaduje výslovné potvrzení.', 422);
        }
        $jobId = (int) ($args['id'] ?? 0);
        $job = $this->jobs->find($jobId, $supplierId);
        if ($job === null || $job['source'] !== 'accounting_history_reclassification'
            || !in_array($job['status'], ['completed', 'completed_with_warnings'], true)
            || (($job['params'] ?? [])['dry_run'] ?? null) !== false
            || !empty(($job['params'] ?? [])['rollback_of_job_id'])) {
            return Json::error($response, 'not_found', 'Přeúčtování nenalezeno.', 404);
        }
        $lockName = self::lifecycleLockName($supplierId, $jobId);
        if (!$this->acquireLifecycleLock($lockName)) {
            return Json::error($response, 'operation_busy', 'S touto zálohou právě pracuje jiná operace.', 409);
        }
        try {
            if ($this->hasRunning($supplierId, 'accounting_history_reclassification')) {
                return Json::error($response, 'already_running', 'Nejdříve nechte běžící úlohu dokončit.', 409);
            }
            $pdo = $this->db->pdo();
            $ownTransaction = !$pdo->inTransaction();
            if ($ownTransaction) {
                $pdo->beginTransaction();
            }
            try {
                $deleted = $this->setup->deleteRollbackSnapshot($supplierId, $jobId);
                if ($deleted === 0) {
                    if ($ownTransaction) {
                        $pdo->rollBack();
                    }
                    return Json::error($response, 'rollback_snapshot_missing', 'Záloha původního účtování už není dostupná.', 409);
                }
                $this->activity->log(
                    'accounting.setup_assistant.snapshot_deleted',
                    self::userId($request),
                    'import_job',
                    $jobId,
                    ['deleted_items' => $deleted],
                    supplierId: $supplierId,
                );
                if ($ownTransaction) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($ownTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } finally {
            $this->releaseLifecycleLock($lockName);
        }
        return Json::ok($response, ['deleted_items' => $deleted]);
    }

    private function allowed(Request $request, string $permission, AccessLevel $level, Response $response, ?Response &$error): bool
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            $error = Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
            return false;
        }
        if (!RequestAuthorization::allows($request, $permission, $level)) {
            $error = Json::error($response, 'forbidden', 'Chybí oprávnění.', 403);
            return false;
        }
        return true;
    }

    private function hasRunning(int $supplierId, string $source): bool
    {
        $this->jobs->reapStale($supplierId, $source);
        foreach ($this->jobs->listForTenant($supplierId, $source, 5) as $job) {
            if (in_array($job['status'], ['queued', 'running'], true)) {
                return true;
            }
        }
        return false;
    }

    private function hasRollbackFor(int $supplierId, int $appliedJobId): bool
    {
        return $this->jobs->hasAccountingRollbackFor($supplierId, $appliedJobId);
    }

    private function acquireLifecycleLock(string $name): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT GET_LOCK(?, 5)');
        $stmt->execute([$name]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private function releaseLifecycleLock(string $name): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$name]);
    }

    private static function lifecycleLockName(int $supplierId, int $jobId): string
    {
        return "accounting_setup_snapshot:{$supplierId}:{$jobId}";
    }

    private function spawn(int $jobId): bool
    {
        return BackgroundProcess::spawnPhp(
            Bootstrap::rootDir() . '/api/bin/import-worker.php',
            ['--job-id=' . $jobId],
            RuntimePaths::log('import-worker.log'),
            Bootstrap::rootDir(),
        );
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $date->format('Y-m-d') : null;
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function editableProposal(string $type, array $existing, array $body): array
    {
        if (!in_array($type, ['chart_account', 'expense_rule', 'posting_rule', 'bank_rule'], true)) {
            throw new \InvalidArgumentException('proposal_not_editable');
        }
        $proposal = $existing;
        if ($type === 'chart_account') {
            $proposal['name'] = self::requiredText($body['name'] ?? null, 160);
            $create = array_key_exists('create', $body)
                ? filter_var($body['create'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : true;
            if ($create === null) {
                throw new \InvalidArgumentException('invalid_create_mode');
            }
            $proposal['create'] = $create;
            $proposal['replacement_account_code'] = $create
                ? null
                : self::accountCode($body['replacement_account_code'] ?? null);
            return [
                $create
                    ? 'Nová analytika ' . (string) ($proposal['account_code'] ?? '') . ' - ' . $proposal['name']
                    : 'Použít účet ' . $proposal['replacement_account_code'] . ' místo analytiky '
                        . (string) ($proposal['account_code'] ?? ''),
                $proposal,
            ];
        }
        if ($type === 'expense_rule') {
            $kind = ExpenseKind::tryFrom(self::requiredText($body['expense_kind'] ?? null, 40));
            if ($kind === null) {
                throw new \InvalidArgumentException('invalid_expense_kind');
            }
            $proposal['name'] = self::requiredText($body['name'] ?? null, 120);
            $proposal['description_contains'] = self::requiredText($body['description_contains'] ?? null, 190);
            $proposal['expense_kind'] = $kind->value;
            $proposal['target_account_code'] = self::accountCode($body['target_account_code'] ?? null);
            $proposal['application_mode'] = 'suggest';
            $proposal['is_active'] = true;
            return [$proposal['name'], $proposal];
        }
        if ($type === 'posting_rule') {
            $proposal['description'] = self::requiredText($body['description'] ?? null, 255);
            $proposal['debit_account_code'] = self::accountCode($body['debit_account_code'] ?? null);
            $proposal['credit_account_code'] = self::accountCode($body['credit_account_code'] ?? null);
            $label = self::expenseKindLabel((string) ($proposal['rule_key'] ?? ''));
            return [
                'Předkontace pro ' . $label . ' na '
                    . $proposal['debit_account_code'] . '/' . $proposal['credit_account_code'],
                $proposal,
            ];
        }

        $proposal['name'] = self::requiredText($body['name'] ?? null, 120);
        $proposal['message_contains'] = self::optionalText($body['message_contains'] ?? null, 190);
        $proposal['debit_account_code'] = self::accountCode($body['debit_account_code'] ?? null);
        $proposal['credit_account_code'] = self::accountCode($body['credit_account_code'] ?? null);
        $proposal['mode'] = 'suggest';
        return [$proposal['name'], $proposal];
    }

    private static function requiredText(mixed $value, int $maxLength): string
    {
        $text = self::optionalText($value, $maxLength);
        if ($text === null) {
            throw new \InvalidArgumentException('required_field_missing');
        }
        return $text;
    }

    private static function optionalText(mixed $value, int $maxLength): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : mb_substr($text, 0, $maxLength);
    }

    private static function accountCode(mixed $value, bool $optional = false): ?string
    {
        $code = trim((string) ($value ?? ''));
        if ($code === '' && $optional) {
            return null;
        }
        if (preg_match('/^[0-9][0-9A-Za-z.\/-]{0,31}$/', $code) !== 1) {
            throw new \InvalidArgumentException('invalid_account_code');
        }
        return $code;
    }

    private static function expenseKindLabel(string $kind): string
    {
        return match ($kind) {
            'service', 'invoice.services.received' => 'služby',
            'material', 'invoice.material.received' => 'materiál, energie a PHM',
            'small_asset', 'invoice.small_asset.received' => 'drobný hmotný majetek',
            'small_intangible', 'invoice.small_intangible.received' => 'drobný nehmotný majetek',
            'fixed_asset', 'invoice.dhm.received' => 'dlouhodobý majetek',
            default => 'přijaté faktury',
        };
    }

    private static function userId(Request $request): int
    {
        return (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);
    }

    private static function jobView(array $job): array
    {
        return [
            'id' => (int) $job['id'],
            'source' => (string) $job['source'],
            'status' => (string) $job['status'],
            'total_items' => $job['total_items'] === null ? null : (int) $job['total_items'],
            'processed' => (int) $job['processed'],
            'created_count' => (int) $job['created_count'],
            'skipped_count' => (int) $job['skipped_count'],
            'failed_count' => (int) $job['failed_count'],
            'current_step' => $job['current_step'],
            'last_error' => $job['last_error'],
            'params' => $job['params'],
            'created_at' => $job['created_at'],
            'finished_at' => $job['finished_at'],
        ];
    }
}

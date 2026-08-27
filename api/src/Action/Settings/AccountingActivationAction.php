<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\AccountingBackfillJobRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Activation\OpeningBalanceService;
use MyInvoice\Service\Accounting\Activation\PendingBackfillCounter;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\BackgroundProcess;
use MyInvoice\Service\IpMatcher;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AccountingActivationAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccountingBackfillJobRepository $jobs,
        private readonly OpeningBalanceService $opening,
        private readonly PendingBackfillCounter $pending,
        private readonly ChartOfAccountsSeeder $seeder,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'settings.company', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění zobrazit stav aktivace.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) return Json::error($response, 'not_found', 'Firma nebyla nalezena.', 404);
        $payload = $this->statusPayload($supplierId);
        return $payload === null
            ? Json::error($response, 'not_found', 'Firma nebyla nalezena.', 404)
            : Json::ok($response, $payload);
    }

    private function statusPayload(int $supplierId): ?array
    {
        $this->jobs->reapStale($supplierId);
        $stmt = $this->db->pdo()->prepare(
            'SELECT accounting_mode, accounting_starts_on, accounting_activation_status FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($supplier === false) return null;
        $startsOn = $supplier['accounting_starts_on'] === null ? null : (string) $supplier['accounting_starts_on'];
        $draft = $this->opening->draft($supplierId);
        $lock = $this->db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $lock->execute([$supplierId]);
        $lockedUntil = $lock->fetchColumn();
        // `editable` drží průvodce průchodným i po dokončené aktivaci: dokud jde
        // otevírací zápis založit, musí být krok „Otevírací rozvaha" dosažitelný.
        // Bez toho se chybějící počáteční stavy nedaly doplnit už nikdy.
        $blocker = $startsOn === null ? null : $this->opening->postBlocker($supplierId, $startsOn);
        return [
            'activation_status' => (string) $supplier['accounting_activation_status'],
            'accounting_mode' => (string) $supplier['accounting_mode'],
            'starts_on' => $startsOn,
            'pending' => $this->pending->count($supplierId, $startsOn),
            'opening' => [
                'rows' => count($draft['rows']),
                'balanced' => $draft['totals']['balanced'],
                'posted' => $startsOn !== null && $this->opening->isPosted($supplierId, $startsOn),
                'editable' => $startsOn !== null && $blocker === null,
                'blocked_reason' => $blocker['code'] ?? null,
            ],
            'locked_until' => $lockedUntil === false || $lockedUntil === null ? null : (string) $lockedUntil,
            'active_job' => $this->jobs->activeForTenant($supplierId),
            'last_job' => $this->jobs->lastForTenant($supplierId),
        ];
    }

    public function start(Request $request, Response $response): Response
    {
        if (($error = $this->requireAdmin($request, $response)) !== null) return $error;
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $startsOn = trim((string) ($body['starts_on'] ?? date('Y-01-01')));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $startsOn);
        $limit = (new \DateTimeImmutable('today'))->modify('+1 year');
        if ($date === false || $date->format('Y-m-d') !== $startsOn || $date > $limit) {
            return Json::error($response, 'validation_failed', 'Datum zahájení musí být platné a nejvýše rok dopředu.', 400);
        }
        if ($this->jobs->activeForTenant($supplierId) !== null) {
            return Json::error($response, 'job_already_running', 'Doúčtování už běží.', 409);
        }
        $this->seeder->seedForSupplier($supplierId);
        $this->db->pdo()->prepare(
            "UPDATE supplier SET accounting_starts_on = ?, accounting_activation_status = 'draft' WHERE id = ?"
        )->execute([$startsOn, $supplierId]);
        $this->audit($request, 'accounting_activation.started', $supplierId, ['starts_on' => $startsOn]);
        $payload = $this->statusPayload($supplierId);
        return $payload === null
            ? Json::error($response, 'not_found', 'Firma nebyla nalezena.', 404)
            : Json::ok($response, $payload);
    }

    public function opening(Request $request, Response $response): Response
    {
        if (($error = $this->requireAdmin($request, $response)) !== null) return $error;
        return Json::ok($response, $this->opening->draft(SupplierGuard::currentId($request)));
    }

    public function saveOpening(Request $request, Response $response): Response
    {
        if (($error = $this->requireAdmin($request, $response)) !== null) return $error;
        try {
            $body = (array) ($request->getParsedBody() ?? []);
            return Json::ok($response, $this->opening->saveDraft(
                SupplierGuard::currentId($request),
                is_array($body['rows'] ?? null) ? $body['rows'] : [],
            ));
        } catch (PostingException $e) {
            // $context nese index vadného řádku — editor podle něj chybu ukáže na místě.
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->context);
        }
    }

    public function prefillOpening(Request $request, Response $response): Response
    {
        if (($error = $this->requireAdmin($request, $response)) !== null) return $error;
        $supplierId = SupplierGuard::currentId($request);
        $startsOn = $this->startsOn($supplierId);
        if ($startsOn === null) return Json::error($response, 'activation_wrong_state', 'Nejdřív zvolte datum zahájení.', 409);
        $asOf = (new \DateTimeImmutable($startsOn))->modify('-1 day')->format('Y-m-d');
        try {
            return Json::ok($response, $this->opening->prefill($supplierId, $asOf));
        } catch (PostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->context);
        }
    }

    public function dryRun(Request $request, Response $response): Response
    {
        return $this->createJob($request, $response, 'dry_run');
    }

    public function execute(Request $request, Response $response): Response
    {
        return $this->createJob($request, $response, 'execute');
    }

    public function jobs(Request $request, Response $response): Response
    {
        if (($error = $this->requireAdmin($request, $response)) !== null) return $error;
        $supplierId = SupplierGuard::currentId($request);
        $this->jobs->reapStale($supplierId);
        $q = $request->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($q['per_page'] ?? 20)));
        $result = $this->jobs->paginateForTenant($supplierId, $perPage, ($page - 1) * $perPage);
        return Json::ok($response, ['items' => $result['items'], 'total' => $result['total'], 'page' => $page, 'per_page' => $perPage]);
    }

    public function job(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->requireAdmin($request, $response)) !== null) return $error;
        $job = $this->jobs->find((int) ($args['id'] ?? 0), SupplierGuard::currentId($request));
        return $job === null
            ? Json::error($response, 'not_found', 'Doúčtování nebylo nalezeno.', 404)
            : Json::ok($response, $job);
    }

    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->requireAdmin($request, $response)) !== null) return $error;
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if (!$this->jobs->requestCancel($id, $supplierId)) {
            return Json::error($response, 'not_found', 'Běžící doúčtování nebylo nalezeno.', 404);
        }
        $job = $this->jobs->find($id, $supplierId);
        if (($job['status'] ?? null) === 'cancelled') {
            $this->db->pdo()->prepare(
                "UPDATE supplier SET accounting_activation_status = 'draft'
                  WHERE id = ? AND accounting_activation_status = 'running'"
            )->execute([$supplierId]);
        }
        $this->audit($request, 'accounting_activation.cancel_requested', $supplierId, ['job_id' => $id]);
        return Json::ok($response, ['cancel_requested' => true, 'job' => $job]);
    }

    private function createJob(Request $request, Response $response, string $kind): Response
    {
        if (($error = $this->requireAdmin($request, $response)) !== null) return $error;
        $supplierId = SupplierGuard::currentId($request);
        $this->jobs->reapStale($supplierId);
        $startsOn = $this->startsOn($supplierId);
        if ($startsOn === null) return Json::error($response, 'activation_wrong_state', 'Nejdřív zvolte datum zahájení.', 409);
        $opening = $this->opening->draft($supplierId);
        if (!$opening['totals']['balanced']) {
            return Json::error($response, 'opening_unbalanced', 'Otevírací rozvaha není vyrovnaná.', 422);
        }
        if ($kind === 'execute') {
            $dryRun = $this->jobs->completedDryRun($supplierId, $startsOn, $opening['hash']);
            if ($dryRun === null || (int) ($dryRun['report_json']['failed_total'] ?? 1) !== 0) {
                return Json::error($response, 'dry_run_required', 'Po změnách spusťte znovu kontrolu nanečisto.', 409);
            }
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $params = [
            'starts_on' => $startsOn,
            'opening_hash' => $opening['hash'],
            'with_rules' => (bool) ($body['with_rules'] ?? false),
        ];
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        try {
            $jobId = $this->jobs->create($supplierId, $kind, $params, (int) ($user['id'] ?? 0));
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                return Json::error($response, 'job_already_running', 'Doúčtování už běží.', 409);
            }
            throw $e;
        }
        if ($kind === 'execute') {
            $this->db->pdo()->prepare("UPDATE supplier SET accounting_activation_status = 'running' WHERE id = ?")
                ->execute([$supplierId]);
        }
        $root = Bootstrap::rootDir();
        // ⚠️ `$diag` se ZAHODIT NESMÍ. Nejčastější příčina je prostředí, ne
        // aplikace — na sdíleném hostingu chybí použitelné CLI php. Bez téhle
        // věty zbyde adminovi „zkuste to znovu", což s tímtéž prostředím
        // dopadne pokaždé stejně.
        $diag = null;
        $spawned = BackgroundProcess::spawnPhp(
            $root . '/api/bin/accounting-backfill-worker.php',
            ['--job-id=' . $jobId],
            RuntimePaths::log('accounting-backfill-worker.log'),
            $root,
            $diag,
        );
        if (!$spawned) {
            $this->audit($request, 'accounting_activation.worker_start_failed', $supplierId, ['job_id' => $jobId, 'diag' => $diag]);
            $this->jobs->markFailed($jobId, 'Worker se nepodařilo spustit' . ($diag === null ? '.' : ': ' . $diag));
            if ($kind === 'execute') {
                $this->db->pdo()->prepare("UPDATE supplier SET accounting_activation_status = 'failed' WHERE id = ?")
                    ->execute([$supplierId]);
            }
            return Json::error($response, 'worker_start_failed', 'Doúčtování se nepodařilo spustit. Zkuste to znovu.', 500);
        }
        $this->audit($request, 'accounting_activation.' . $kind . '_started', $supplierId, ['job_id' => $jobId] + $params);
        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued'], 201);
    }

    private function requireAdmin(Request $request, Response $response): ?Response
    {
        if (!RequestAuthorization::allows($request, 'accounting.periods.manage', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Aktivaci může spustit pouze administrátor firmy.', 403);
        }
        if (SupplierGuard::currentId($request) <= 0) return Json::error($response, 'not_found', 'Firma nebyla nalezena.', 404);
        return null;
    }

    private function startsOn(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_starts_on FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    private function audit(Request $request, string $action, int $supplierId, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->logger->log(
            $action,
            (int) ($user['id'] ?? 0),
            'supplier',
            $supplierId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }
}

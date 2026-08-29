<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollErasureException;
use MyInvoice\Repository\Payroll\PayrollErasureProposalRepository;
use MyInvoice\Repository\Payroll\PayrollRetentionPolicyException;
use MyInvoice\Repository\Payroll\PayrollRetentionPolicyRepository;
use MyInvoice\Repository\RetentionHoldRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Retention\PayrollRetentionCatalog;
use MyInvoice\Service\Payroll\Retention\PayrollRetentionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Retence mzdové agendy, zadržení výmazu a výmaz jako návrh ke schválení.
 *
 *   GET    /api/payroll/retention                       — katalog lhůt + odchylky firmy
 *   GET    /api/payroll/retention/assessment            — posudek po osobách
 *   PUT    /api/payroll/retention/policies/{category}   — prodloužit / dodat lhůtu
 *   DELETE /api/payroll/retention/policies/{category}   — zrušit odchylku
 *   GET    /api/payroll/retention/holds                 — zadržení vázaná na osobu
 *   POST   /api/payroll/retention/holds                 — zadržet výmaz osoby
 *   DELETE /api/payroll/retention/holds/{id}            — uvolnit
 *   GET    /api/payroll/retention/erasure               — návrhy výmazu
 *   POST   /api/payroll/retention/erasure               — sestavit návrh
 *   GET    /api/payroll/retention/erasure/{id}          — co přesně návrh obsahuje
 *   POST   /api/payroll/retention/erasure/{id}/approve  — schválit
 *   POST   /api/payroll/retention/erasure/{id}/reject   — zamítnout
 *   POST   /api/payroll/retention/erasure/{id}/execute  — provést SCHVÁLENÝ návrh
 *
 * Žádný endpoint nemaže bez schválení: `execute` odmítne návrh, který neprošel
 * `approve`, a `approve` a `execute` jsou dva různé požadavky, takže se výmaz
 * nedá odklepnout jedním kliknutím.
 *
 * Sestavení návrhu i jeho schválení jsou úkony nad nejcitlivějšími osobními údaji
 * v aplikaci, proto jdou jen z přihlášené relace (ne přes API token).
 */
final class PayrollRetentionAction
{
    use PayrollActionSupport;

    /** Důvody zadržení, které dávají smysl u osoby (§ 32 ZoÚ + mzdové důvody). */
    private const REASONS = [
        'tax_audit',
        'appeal',
        'litigation',
        'enforcement',
        'insolvency',
        'other',
    ];

    public function __construct(
        private readonly PayrollRetentionService $retention,
        private readonly PayrollRetentionPolicyRepository $policies,
        private readonly RetentionHoldRepository $holds,
        private readonly PayrollErasureProposalRepository $proposals,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function overview(Request $request, Response $response): Response
    {
        if (($guard = $this->guard($request, $response, 'payroll.retention', AccessLevel::READ)) !== null) {
            return $guard;
        }
        $supplierId = $this->currentSupplierId($request);
        $effective = $this->policies->effectiveYears($supplierId);

        $categories = [];
        foreach (PayrollRetentionCatalog::rules() as $rule) {
            // Tabulky do payloadu patří, i když je `toArray()` nenese: bez nich se
            // z obrazovky nedá poznat, ČEHO se lhůta drží, a katalog by tvrdil
            // lhůtu nad neznámým rozsahem dat. Do auditní stopy naopak nepatří,
            // proto zůstávají tady a ne v `toArray()`.
            $categories[] = $rule->toArray() + [
                'employee_tables' => $rule->employeeTables,
                'employment_tables' => $rule->employmentTables,
                'effective_years' => $effective[$rule->category] ?? null,
                'determined' => ($effective[$rule->category] ?? null) !== null,
            ];
        }

        return Json::ok($response, [
            'categories' => $categories,
            'policies' => $this->policies->all($supplierId),
        ]);
    }

    public function assessment(Request $request, Response $response): Response
    {
        if (($guard = $this->guard($request, $response, 'payroll.retention', AccessLevel::READ)) !== null) {
            return $guard;
        }
        $supplierId = $this->currentSupplierId($request);
        $asOf = $this->asOf($request);
        if ($asOf === null) {
            return Json::error($response, 'validation_failed', 'as_of musí být ve tvaru Y-m-d.', 422);
        }

        $items = [];
        $proposable = 0;
        foreach ($this->retention->assess($supplierId, $asOf) as $assessment) {
            $items[] = $assessment->toArray();
            if ($assessment->isProposable()) {
                $proposable++;
            }
        }

        return Json::ok($response, [
            'as_of' => $asOf,
            'items' => $items,
            'proposable' => $proposable,
        ]);
    }

    /** @param array{category:string} $args */
    public function putPolicy(Request $request, Response $response, array $args): Response
    {
        if (($guard = $this->guard($request, $response, 'payroll.retention', AccessLevel::WRITE)) !== null) {
            return $guard;
        }
        $supplierId = $this->currentSupplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $extraYears = (int) ($body['extra_years'] ?? 0);
        $overrideYears = isset($body['override_years']) && $body['override_years'] !== ''
            ? (int) $body['override_years']
            : null;

        try {
            $this->policies->upsert(
                $supplierId,
                (string) $args['category'],
                $extraYears,
                $overrideYears,
                (string) ($body['reason'] ?? ''),
                $this->userId($request),
            );
        } catch (PayrollRetentionPolicyException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 422);
        }

        $this->log($request, 'payroll.retention_policy_set', 0, [
            'category' => (string) $args['category'],
            'extra_years' => $extraYears,
            'override_years' => $overrideYears,
        ]);

        return Json::ok($response, ['ok' => true]);
    }

    /** @param array{category:string} $args */
    public function deletePolicy(Request $request, Response $response, array $args): Response
    {
        if (($guard = $this->guard($request, $response, 'payroll.retention', AccessLevel::WRITE)) !== null) {
            return $guard;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->policies->delete($supplierId, (string) $args['category'])) {
            return Json::error($response, 'not_found', 'Odchylka nenalezena.', 404);
        }
        $this->log($request, 'payroll.retention_policy_del', 0, [
            'category' => (string) $args['category'],
        ]);

        return Json::ok($response, ['ok' => true]);
    }

    public function listHolds(Request $request, Response $response): Response
    {
        if (($guard = $this->guard($request, $response, 'payroll.retention', AccessLevel::READ)) !== null) {
            return $guard;
        }
        $includeReleased = ($request->getQueryParams()['include_released'] ?? '') === '1';

        return Json::ok($response, [
            'holds' => $this->holds->payrollHolds(
                $this->currentSupplierId($request),
                $includeReleased,
            ),
        ]);
    }

    public function placeHold(Request $request, Response $response): Response
    {
        if (($guard = $this->guard($request, $response, 'payroll.retention', AccessLevel::WRITE)) !== null) {
            return $guard;
        }
        $supplierId = $this->currentSupplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $employeeId = (int) ($body['employee_id'] ?? 0);
        $reason = trim((string) ($body['reason'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $placedOn = trim((string) ($body['placed_on'] ?? date('Y-m-d')));

        if (!in_array($reason, self::REASONS, true)) {
            return Json::error(
                $response,
                'validation_failed',
                'reason musí být jedna z: ' . implode(', ', self::REASONS) . '.',
                422,
            );
        }
        if ($description === '') {
            return Json::error(
                $response,
                'validation_failed',
                'Vyplňte č. j. nebo popis řízení — bez něj nelze doložit, proč se výmaz zadržel.',
                422,
            );
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $placedOn) !== 1) {
            return Json::error($response, 'validation_failed', 'placed_on musí být ve tvaru Y-m-d.', 422);
        }
        // 404 dřív, než se cokoli dozví o stavu — cizí tenant nesmí poznat ani to,
        // jestli id existuje.
        if (!$this->employeeExists($supplierId, $employeeId)) {
            return Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404);
        }

        $id = $this->holds->place(
            $supplierId,
            null,
            $reason,
            mb_substr($description, 0, 255),
            $placedOn,
            $this->userId($request),
            RetentionHoldRepository::SUBJECT_PAYROLL_EMPLOYEE,
            $employeeId,
        );
        $this->log($request, 'payroll.retention_hold_placed', $id, [
            'employee_id' => $employeeId,
            'reason' => $reason,
            'description' => $description,
        ]);

        return Json::ok($response, ['id' => $id]);
    }

    /** @param array{id:string} $args */
    public function releaseHold(Request $request, Response $response, array $args): Response
    {
        if (($guard = $this->guard($request, $response, 'payroll.retention', AccessLevel::WRITE)) !== null) {
            return $guard;
        }
        $id = (int) $args['id'];
        $released = $this->holds->release(
            $this->currentSupplierId($request),
            $id,
            date('Y-m-d'),
            $this->userId($request),
            RetentionHoldRepository::SUBJECT_PAYROLL_EMPLOYEE,
        );
        if (!$released) {
            return Json::error($response, 'not_found', 'Aktivní zadržení nenalezeno.', 404);
        }
        $this->log($request, 'payroll.retention_hold_released', $id, []);

        return Json::ok($response, ['ok' => true]);
    }

    public function listProposals(Request $request, Response $response): Response
    {
        if (($guard = $this->guard($request, $response, 'payroll.erasure', AccessLevel::READ)) !== null) {
            return $guard;
        }

        return Json::ok($response, [
            'proposals' => $this->proposals->all($this->currentSupplierId($request)),
        ]);
    }

    public function createProposal(Request $request, Response $response): Response
    {
        if (($guard = $this->guard($request, $response, 'payroll.erasure', AccessLevel::WRITE)) !== null) {
            return $guard;
        }
        $supplierId = $this->currentSupplierId($request);
        $asOf = $this->asOf($request);
        if ($asOf === null) {
            return Json::error($response, 'validation_failed', 'as_of musí být ve tvaru Y-m-d.', 422);
        }
        $body = (array) ($request->getParsedBody() ?? []);

        $id = $this->proposals->create(
            $supplierId,
            $this->userId($request),
            $asOf,
            isset($body['note']) ? (string) $body['note'] : null,
            $this->clientIp($request),
            $request->getHeaderLine('User-Agent'),
        );
        if ($id === null) {
            return Json::error(
                $response,
                'payroll_erasure_nothing_to_propose',
                'K datu ' . $asOf . ' není co navrhnout — všem osobám buď běží retenční '
                . 'lhůta, drží je zadržení, nebo jejich lhůta není určená.',
                409,
            );
        }

        return Json::ok($response, ['id' => $id], 201);
    }

    /** @param array{id:string} $args */
    public function showProposal(Request $request, Response $response, array $args): Response
    {
        if (($guard = $this->guard($request, $response, 'payroll.erasure', AccessLevel::READ)) !== null) {
            return $guard;
        }
        $supplierId = $this->currentSupplierId($request);
        $proposalId = (int) $args['id'];
        $proposal = $this->proposals->find($supplierId, $proposalId);
        if ($proposal === null) {
            return Json::error($response, 'not_found', 'Návrh výmazu nebyl nalezen.', 404);
        }

        return Json::ok($response, [
            'proposal' => $proposal,
            'items' => array_map(
                $this->decodeCascade(...),
                $this->proposals->items($supplierId, $proposalId),
            ),
        ]);
    }

    /**
     * `cascade_counts` je JSON sloupec, PDO ho vrací jako řetězec. Kdyby se
     * posílal takhle, musel by ho rozebírat prohlížeč — a náhled dopadu výmazu
     * by se rozpadl na tiše chybějící čísla, kdyby se tvar někdy změnil.
     *
     * @param  array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function decodeCascade(array $item): array
    {
        $raw = $item['cascade_counts'] ?? null;
        if (!is_string($raw) || $raw === '') {
            $item['cascade_counts'] = null;

            return $item;
        }

        $decoded = json_decode($raw, true);
        $item['cascade_counts'] = is_array($decoded) ? $decoded : null;

        return $item;
    }

    /** @param array{id:string} $args */
    public function approveProposal(Request $request, Response $response, array $args): Response
    {
        return $this->transition($request, $response, $args, 'approve');
    }

    /** @param array{id:string} $args */
    public function rejectProposal(Request $request, Response $response, array $args): Response
    {
        return $this->transition($request, $response, $args, 'reject');
    }

    /** @param array{id:string} $args */
    public function executeProposal(Request $request, Response $response, array $args): Response
    {
        return $this->transition($request, $response, $args, 'execute');
    }

    /**
     * Vzetí schválení zpět během odkladné lhůty (C-07). Půlka, bez které by
     * byl odklad jen zdržením — omyl musí jít napravit dřív, než se provede.
     *
     * @param array{id:string} $args
     */
    public function revokeProposal(Request $request, Response $response, array $args): Response
    {
        return $this->transition($request, $response, $args, 'revoke');
    }

    /** @param array{id:string} $args */
    private function transition(
        Request $request,
        Response $response,
        array $args,
        string $step,
    ): Response {
        if (($guard = $this->guard($request, $response, 'payroll.erasure', AccessLevel::WRITE)) !== null) {
            return $guard;
        }
        $supplierId = $this->currentSupplierId($request);
        $proposalId = (int) $args['id'];
        $userId = $this->userId($request);
        $ip = $this->clientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');

        try {
            $result = match ($step) {
                'approve' => $this->proposals->approve($supplierId, $proposalId, $userId, $ip, $userAgent),
                'reject' => $this->proposals->reject($supplierId, $proposalId, $userId, $ip, $userAgent),
                'revoke' => $this->proposals->revoke($supplierId, $proposalId, $userId, $ip, $userAgent),
                default => $this->proposals->execute(
                    $supplierId,
                    $proposalId,
                    $userId,
                    $ip,
                    $userAgent,
                    self::confirmation($request),
                ),
            };
        } catch (PayrollErasureException $e) {
            return Json::error(
                $response,
                $e->errorCode,
                $e->getMessage(),
                match ($e->errorCode) {
                    'not_found' => 404,
                    // Chybějící potvrzovací fráze je vada požadavku, ne stavu.
                    'payroll_erasure_confirmation_required' => 422,
                    default => 409,
                },
            );
        }

        return Json::ok($response, is_array($result) ? ['summary' => $result] : ['ok' => true]);
    }

    private function employeeExists(int $supplierId, int $employeeId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_employees WHERE supplier_id = ? AND id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $employeeId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Vrací `null`, když je `as_of` zadané špatně — volající to překlopí na 422. */
    private function asOf(Request $request): ?string
    {
        $raw = trim((string) ($request->getQueryParams()['as_of'] ?? ''));
        if ($raw === '') {
            return date('Y-m-d');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    private function guard(
        Request $request,
        Response $response,
        string $permission,
        AccessLevel $level,
    ): ?Response {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
            return $this->guardFailure($error);
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $this->guardFailure($error);
        }

        return null;
    }

    private function guardFailure(?Response $error): Response
    {
        if ($error === null) {
            throw new \LogicException('Payroll guard selhal bez chybové odpovědi.');
        }

        return $error;
    }

    /** Opsaná potvrzovací fráze z těla požadavku na provedení výmazu. */
    private static function confirmation(Request $request): string
    {
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body['confirmation'] ?? null) : null;

        return is_string($value) ? $value : '';
    }

    private function clientIp(Request $request): string
    {
        $serverParams = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $serverParams[$key] = $value;
            }
        }

        return $this->ipMatcher->clientIpFromRequest($serverParams);
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'payroll_retention',
            $id,
            $payload,
            $this->clientIp($request),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}

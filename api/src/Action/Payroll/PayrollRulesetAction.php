<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetAdminService;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetConflictException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetGovernanceException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Správa legislativních rulesetů mezd (GLOBÁLNÍ, jako číselník daňových konstant):
 *   GET    /api/payroll/rulesets                                — přehled domén a verzí
 *   GET    /api/payroll/rulesets/{id}                           — detail verze
 *   GET    /api/payroll/rulesets/{id}/diff?against=default|{id} — diff parametrů
 *   GET    /api/payroll/rulesets/{id}/impact-preview            — read-only dopad před aktivací
 *   PUT    /api/payroll/rulesets/{id}                           — uložit override obsahu
 *   DELETE /api/payroll/rulesets/{id}                           — reset na default z kódu
 *   POST   /api/payroll/rulesets/{id}/commands/{command}        — review|approve|activate|supersede
 *
 * Čtení stačí oprávnění `payroll.rulesets`; zápis je jen pro superadmina, protože
 * data jsou národní a sdílená všemi firmami (stejně jako `tax_constants`).
 * Endpoint pro „nastav libovolný stav" záměrně neexistuje — jen pojmenované příkazy.
 * Modul mezd tu schválně negatujeme: legislativní sada je globální číselník.
 */
final class PayrollRulesetAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollRulesetAdminService $service,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorizeRead($request, $response)) !== null) {
            return $error;
        }

        return Json::ok($response, $this->service->overview());
    }

    /** @param array<string,string> $args */
    public function detail(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorizeRead($request, $response)) !== null) {
            return $error;
        }
        $detail = $this->service->detail((string) ($args['rulesetId'] ?? ''));

        return $detail === null
            ? Json::error($response, 'not_found', 'Ruleset nebyl nalezen.', 404)
            : Json::ok($response, ['ruleset' => $detail]);
    }

    /** @param array<string,string> $args */
    public function diff(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorizeRead($request, $response)) !== null) {
            return $error;
        }
        $against = $request->getQueryParams()['against'] ?? 'default';
        if (!is_string($against) || $against === '') {
            $against = 'default';
        }

        try {
            $diff = $this->service->diff((string) ($args['rulesetId'] ?? ''), $against);
        } catch (PayrollRulesetGovernanceException $e) {
            return Json::error($response, $e->reasonCode, $e->getMessage(), 422, $e->context);
        }

        return $diff === null
            ? Json::error($response, 'not_found', 'Ruleset nebyl nalezen.', 404)
            : Json::ok($response, ['diff' => $diff]);
    }

    /** @param array<string,string> $args */
    public function impactPreview(Request $request, Response $response, array $args): Response
    {
        // Náhled sice nic nemění, ale ukazuje globální legislativní kandidát.
        // Drží proto stejnou hranici superadmina jako aktivace.
        if (($error = $this->authorizeWrite($request, $response)) !== null) {
            return $error;
        }
        try {
            $preview = $this->service->impactPreview((string) ($args['rulesetId'] ?? ''));
        } catch (PayrollRulesetGovernanceException $e) {
            return Json::error($response, $e->reasonCode, $e->getMessage(), 422, $e->context);
        }

        return $preview === null
            ? Json::error($response, 'not_found', 'Ruleset nebyl nalezen.', 404)
            : Json::ok($response, ['impact_preview' => $preview]);
    }

    /** @param array<string,string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorizeWrite($request, $response)) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $rulesetId = (string) ($args['rulesetId'] ?? '');

        try {
            $saved = $this->service->save(
                $rulesetId,
                $body,
                is_string($body['reason'] ?? null) ? $body['reason'] : '',
                self::rowVersion($body),
                $this->userId($request),
            );
        } catch (PayrollRulesetConflictException $e) {
            return Json::error(
                $response,
                'row_version_conflict',
                $e->getMessage(),
                409,
                ['current_row_version' => $e->currentVersion],
            );
        } catch (PayrollRulesetGovernanceException $e) {
            return Json::error($response, $e->reasonCode, $e->getMessage(), 422, $e->context);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        $this->audit($request, 'payroll.ruleset.updated', $rulesetId);

        return Json::ok($response, ['ruleset' => $saved]);
    }

    /** @param array<string,string> $args */
    public function reset(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorizeWrite($request, $response)) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $rulesetId = (string) ($args['rulesetId'] ?? '');

        try {
            $after = $this->service->reset(
                $rulesetId,
                is_string($body['reason'] ?? null) ? $body['reason'] : 'Reset na ověřený default z kódu.',
                $this->userId($request),
            );
        } catch (PayrollRulesetGovernanceException $e) {
            return Json::error($response, $e->reasonCode, $e->getMessage(), 422, $e->context);
        }

        $this->audit($request, 'payroll.ruleset.reset', $rulesetId);

        return Json::ok($response, ['ruleset' => $after, 'removed' => $after === null]);
    }

    /** @param array<string,string> $args */
    public function command(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorizeWrite($request, $response)) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $rulesetId = (string) ($args['rulesetId'] ?? '');
        $command = (string) ($args['command'] ?? '');

        try {
            $result = $this->service->command(
                $rulesetId,
                $command,
                is_string($body['reason'] ?? null) ? $body['reason'] : '',
                self::rowVersion($body),
                $this->userId($request),
            );
        } catch (PayrollRulesetConflictException $e) {
            return Json::error(
                $response,
                'row_version_conflict',
                $e->getMessage(),
                409,
                ['current_row_version' => $e->currentVersion],
            );
        } catch (PayrollRulesetGovernanceException $e) {
            $status = $e->reasonCode === 'not_found' ? 404 : 422;
            return Json::error($response, $e->reasonCode, $e->getMessage(), $status, $e->context);
        }

        if ($result['changed']) {
            $this->audit($request, 'payroll.ruleset.' . $command, $rulesetId);
        }

        return Json::ok($response, $result);
    }

    private function authorizeRead(Request $request, Response $response): ?Response
    {
        if (($error = $this->requireSession($request, $response)) !== null) {
            return $error;
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.rulesets',
            AccessLevel::READ,
            $error,
        )) {
            return $error;
        }

        return null;
    }

    private function authorizeWrite(Request $request, Response $response): ?Response
    {
        if (($error = $this->requireSession($request, $response)) !== null) {
            return $error;
        }
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error(
                $response,
                'forbidden',
                'Legislativní rulesety jsou společné pro všechny firmy — měnit je smí jen superadmin.',
                403,
            );
        }

        return null;
    }

    private function requireSession(Request $request, Response $response): ?Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }

        return null;
    }

    /** @param array<string,mixed> $body */
    private static function rowVersion(array $body): int
    {
        $value = filter_var(
            $body['row_version'] ?? 0,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );

        return $value === false ? -1 : (int) $value;
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $parsed = $request->getParsedBody();
        if (!is_array($parsed)) {
            return [];
        }
        $result = [];
        foreach ($parsed as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function audit(Request $request, string $action, string $rulesetId): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'payroll_ruleset',
            null,
            ['ruleset_id' => $rulesetId],
            $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
            $request->getHeaderLine('User-Agent'),
        );
    }

    /** @return array<string,mixed> */
    private function serverParams(Request $request): array
    {
        $result = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}

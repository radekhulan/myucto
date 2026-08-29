<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollSubmissionConflictException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpManualCompletionException;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpManualCompletionService;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementService;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Evidenční list důchodového pojištění.
 *
 * `prepare` dovede evidenční list do stavu **připraveno** a tam skončí.
 * Žádná routa tady nic neodesílá; člověk dokončí úkon v oficiálním rozhraní
 * a samostatná routa pak pouze uloží ověřitelný DMS důkaz výsledku.
 */
final class PayrollEldpAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly EldpStatementService $service,
        private readonly EldpManualCompletionService $completions,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $this->guardFailure($error);
        }
        try {
            $query = $request->getQueryParams();
            $employmentId = $this->queryPositiveInt($query, 'employment_id');
            $year = $this->queryPositiveInt($query, 'year');
            $environment = $this->queryEnvironment($request);
        } catch (EldpValidationException $exception) {
            return $this->failure($response, $exception);
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        $statement = $this->service->statement(
                $this->currentSupplierId($request),
                $environment,
                $employmentId,
                $year,
            );
        $manualCompletion = null;
        if ($statement !== null
            && RequestAuthorization::allows($request, 'documents', AccessLevel::READ)
        ) {
            $manualCompletion = $this->completions->overview(
                $this->currentSupplierId($request),
                $environment,
                (int) $statement['id'],
            );
        }

        return Json::ok($response, [
            'statement' => $statement,
            'manual_completion' => $manualCompletion,
            // Přípustnost se vydává hned s podklady, ne až jako chyba přípravy:
            // od roku 2026 zaměstnavatel evidenční list nevede a obrazovka to
            // musí říct dřív, než na ní někdo začne vyplňovat potvrzení.
            'eligibility' => $this->service->eligibility(
                $this->currentSupplierId($request),
                $employmentId,
                $year,
            ),
            'supported' => [
                'agenda_code' => EldpStatementService::AGENDA_CODE,
                'evidence_schema' => 'jmhz-1.4.3.4 eldpType',
                'submission_schema_available' => false,
                'stops_at_status' => 'prepared',
                'legal_basis' => 'Zákon č. 582/1991 Sb., § 38 odst. 4 a § 39 odst. 2 '
                    . 'až 4, ve znění účinném do 31. 12. 2025, § 38a odst. 2 a 3 '
                    . 'a čl. V bod 8 zákona č. 360/2025 Sb.',
                // Od roku 2026 už zaměstnavatel roční evidenční list nevede;
                // agenda zůstává jen pro vyjmenované výjimky.
                'annual_employer_duty' => false,
                'last_annual_year' => EldpDeadlinePolicy::LAST_ANNUAL_YEAR,
                'deadline_rulesets' => [
                    EldpDeadlinePolicy::ANNUAL_RULESET,
                    EldpDeadlinePolicy::TERMINATION_RULESET,
                    EldpDeadlinePolicy::AUTHORITY_REQUEST_RULESET,
                ],
            ],
        ]);
    }

    public function prepare(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $this->guardFailure($error);
        }
        $employmentId = 0;
        try {
            $body = $this->body($request);
            $employmentId = $this->positiveInt($body, 'employment_id');
            $result = $this->service->prepare(
                $this->currentSupplierId($request),
                $employmentId,
                $this->positiveInt($body, 'year'),
                $this->environment($body),
                [
                    'excluded_days_confirmed' =>
                        $this->bool($body, 'excluded_days_confirmed'),
                    'deducted_days_none' =>
                        $this->bool($body, 'deducted_days_none'),
                    'requested_by_authority' =>
                        $this->bool($body, 'requested_by_authority'),
                    'authority_request_received_on' =>
                        $this->nullableString($body, 'authority_request_received_on'),
                    'note' => $this->string($body, 'note'),
                ],
                $this->string($body, 'idempotency_key'),
                $this->requiredUserId($request),
            );
        } catch (EldpValidationException $exception) {
            return $this->failure($response, $exception);
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        $this->audit(
            $request,
            'payroll.eldp.prepared',
            'payroll_eldp_statements',
            $result['statement_id'],
            [
                'employment_id' => $employmentId,
                'statement_kind' => $result['statement_kind'],
                'due_on' => $result['due_on'],
                'submission_id' => $result['submission_id'],
                'submission_status' => $result['submission_status'],
                'created' => $result['created'],
                'environment' => $result['environment'],
            ],
        );

        return Json::ok($response, ['statement' => $result]);
    }

    /** @param array<string,string> $args */
    public function complete(Request $request, Response $response, array $args): Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return Json::error($response, 'session_required', 'Ruční dokončení ELDP vyžaduje přihlášenou relaci.', 403);
        }
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $this->guardFailure($error);
        }
        if (!$this->requirePermission($request, $response, 'documents', AccessLevel::READ, $error)) {
            return $this->guardFailure($error);
        }

        try {
            $body = $this->body($request);
            $result = $this->completions->record(
                $this->currentSupplierId($request),
                $this->environment($body),
                $this->positiveInt($args, 'statementId'),
                $this->positiveInt($body, 'expected_obligation_row_version'),
                $this->string($body, 'authority_status'),
                $this->positiveInt($body, 'confirmation_document_id'),
                $this->string($body, 'authority_reference'),
                $this->string($body, 'confirmed_on'),
                $this->string($body, 'idempotency_key'),
                $this->requiredUserId($request),
            );
        } catch (EldpManualCompletionException $exception) {
            $extra = $exception->currentRowVersion === null
                ? []
                : ['current_row_version' => $exception->currentRowVersion];
            return Json::error($response, $exception->errorCode, $exception->getMessage(), $exception->httpStatus, $extra);
        } catch (PayrollSubmissionConflictException $exception) {
            return Json::error($response, 'row_version_conflict', $exception->getMessage(), 409);
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }

        $this->audit(
            $request,
            'payroll.eldp.manual_completion_recorded',
            'payroll_eldp_manual_completions',
            $result['id'],
            [
                'statement_id' => $result['statement_id'],
                'obligation_id' => $result['obligation_id'],
                'authority_status' => $result['authority_status'],
                'obligation_status' => $result['obligation_status'],
                'local_submission_status' => $result['local_submission_status'],
                'confirmation_document_id' => $result['confirmation_document_id'],
                'confirmation_sha256' => $result['confirmation_sha256'],
                'created' => $result['created'],
            ],
        );

        return Json::ok($response, ['manual_completion' => $result]);
    }

    private function failure(
        Response $response,
        EldpValidationException $exception,
    ): Response {
        return Json::error(
            $response,
            $exception->validationCode,
            $exception->getMessage(),
            422,
            $exception->blockers === []
                ? []
                : ['blockers' => $exception->blockers],
        );
    }

    private function guard(
        Request $request,
        Response $response,
        AccessLevel $level,
        ?Response &$error,
    ): bool {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            $level,
            $error,
        )) {
            return false;
        }

        return $this->requirePayrollEnabled(
            $request,
            $response,
            $this->access,
            $error,
        );
    }

    private function guardFailure(?Response $error): Response
    {
        return $error
            ?? throw new \LogicException('Payroll ELDP guard selhal bez odpovědi.');
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
        }
        $normalized = [];
        foreach ($body as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException(
                    'Tělo požadavku musí být objekt.',
                );
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** @param array<string,mixed> $body */
    private function bool(array $body, string $key): bool
    {
        if (!array_key_exists($key, $body) || !is_bool($body[$key])) {
            throw new \InvalidArgumentException(
                $key . ' musí být pravdivostní hodnota.',
            );
        }

        return $body[$key];
    }

    /** @param array<string,mixed> $body */
    private function positiveInt(array $body, string $key): int
    {
        $value = filter_var(
            $body[$key] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($value === false) {
            throw new \InvalidArgumentException(
                $key . ' musí být kladné celé číslo.',
            );
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $query */
    private function queryPositiveInt(array $query, string $key): int
    {
        return $this->positiveInt($query, $key);
    }

    /** @param array<string,mixed> $body */
    private function string(array $body, string $key): string
    {
        if (!array_key_exists($key, $body) || !is_string($body[$key])) {
            throw new \InvalidArgumentException($key . ' musí být text.');
        }

        return $body[$key];
    }

    /** @param array<string,mixed> $body */
    private function nullableString(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        if (!is_string($body[$key])) {
            throw new \InvalidArgumentException($key . ' musí být text nebo null.');
        }

        return $body[$key];
    }

    /** @param array<string,mixed> $body */
    private function environment(array $body): string
    {
        $environment = $this->string($body, 'environment');
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new EldpValidationException(
                'eldp_environment_invalid',
                'Prostředí evidenčního listu není platné.',
            );
        }

        return $environment;
    }

    private function queryEnvironment(Request $request): string
    {
        $environment = $request->getQueryParams()['environment'] ?? null;
        if (!is_string($environment)
            || !in_array($environment, ['production', 'test'], true)
        ) {
            throw new EldpValidationException(
                'eldp_environment_invalid',
                'Prostředí evidenčního listu není platné.',
            );
        }

        return $environment;
    }

    private function requiredUserId(Request $request): int
    {
        return $this->userId($request)
            ?? throw new \InvalidArgumentException(
                'Přihlášený uživatel není platný.',
            );
    }

    /** @param array<string,mixed> $metadata */
    private function audit(
        Request $request,
        string $action,
        string $entityType,
        int $entityId,
        array $metadata,
    ): void {
        $serverParams = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $serverParams[$key] = $value;
            }
        }
        $this->logger->log(
            $action,
            $this->userId($request),
            $entityType,
            $entityId,
            $metadata,
            $this->ipMatcher->clientIpFromRequest($serverParams),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementService;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Evidenční list důchodového pojištění.
 *
 * `prepare` dovede evidenční list do stavu **připraveno** a tam skončí.
 * Žádná routa tady nic neodesílá; odeslání spouští člověk přes společnou
 * platformu podání.
 */
final class PayrollEldpAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly EldpStatementService $service,
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

        return Json::ok($response, [
            'statement' => $this->service->statement(
                $this->currentSupplierId($request),
                $environment,
                $employmentId,
                $year,
            ),
            'supported' => [
                'agenda_code' => EldpStatementService::AGENDA_CODE,
                'evidence_schema' => 'jmhz-1.4.3.4 eldpType',
                'submission_schema_available' => false,
                'stops_at_status' => 'prepared',
                'legal_basis' => 'Zákon č. 582/1991 Sb., § 38 odst. 4 a § 39 odst. 2 '
                    . 'až 4, ve znění účinném do 31. 12. 2025, a čl. V bod 8 '
                    . 'zákona č. 360/2025 Sb.',
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

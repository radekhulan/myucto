<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollRunConflictException;
use MyInvoice\Repository\Payroll\PayrollRunIdempotencyException;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Run\PayrollRunValidationOverrideResult;
use MyInvoice\Service\Payroll\Run\PayrollRunValidationOverrideService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Cesta ke schválení výjimky u mzdové validace.
 *
 * Varování s `requires_override = 1` zastaví schválení běhu
 * ({@see \MyInvoice\Service\Payroll\Run\PayrollRunWorkflow}). Do migrace 1210
 * k tomu patřily sloupce `override_reason` / `overridden_by` / `overridden_at`,
 * ale endpoint k nim nikdy nevznikl, takže varování nešlo odklidit a běh
 * zůstal viset. Tahle Action tu cestu otevírá.
 *
 * Konvence drží {@see PayrollRunsAction}: `row_version` v těle, povinná
 * hlavička `Idempotency-Key`, české doménové hlášky, `row_version_conflict`
 * (409), `idempotency_conflict` (409), `validation_failed` (422),
 * `not_found` (404).
 */
final class PayrollRunValidationOverrideAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollRunValidationOverrideService $overrides,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** @param array<string,string> $args */
    public function grant(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        return $this->handle($request, $response, $args, true);
    }

    /** @param array<string,string> $args */
    public function revoke(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        return $this->handle($request, $response, $args, false);
    }

    /** @param array<string,string> $args */
    private function handle(
        Request $request,
        Response $response,
        array $args,
        bool $granting,
    ): Response {
        // Schválení výjimky je věcně část schválení běhu („vím o vadě a přesto
        // se vyplácí"), proto stejné právo jako `approve`, ne slabší
        // `payroll.review`.
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.approve',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $version = filter_var($body['row_version'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $runId = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $validationId = filter_var(
            $args['validationId'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
        if (!is_int($version)
            || !is_int($runId)
            || !is_int($validationId)
            || $idempotencyKey === ''
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Výjimka vyžaduje row_version a hlavičku Idempotency-Key.',
                422,
            );
        }
        try {
            $userId = $this->userId($request)
                ?? throw new \DomainException(
                    'Uživatel schvalující výjimku není dostupný.',
                );
            $result = $granting
                ? $this->overrides->grant(
                    $this->currentSupplierId($request),
                    $runId,
                    $validationId,
                    $version,
                    $idempotencyKey,
                    $userId,
                    $body['reason'] ?? null,
                )
                : $this->overrides->revoke(
                    $this->currentSupplierId($request),
                    $runId,
                    $validationId,
                    $version,
                    $idempotencyKey,
                    $userId,
                    $body['reason'] ?? null,
                );
        } catch (PayrollRunConflictException $e) {
            return Json::error(
                $response,
                'row_version_conflict',
                $e->getMessage(),
                409,
                ['current_row_version' => $e->currentVersion],
            );
        } catch (PayrollRunIdempotencyException $e) {
            return Json::error(
                $response,
                'idempotency_conflict',
                $e->getMessage(),
                409,
            );
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, $this->serialize($result));
    }

    /** @return array<string,mixed> */
    private function serialize(PayrollRunValidationOverrideResult $result): array
    {
        return [
            'granted' => $result->granted,
            'four_eyes_met' => $result->fourEyesMet,
            'idempotent_replay' => $result->idempotentReplay,
            'run' => $result->run,
            'validation' => $result->validation,
        ];
    }

    private function authorize(
        Request $request,
        Response $response,
        string $permission,
        AccessLevel $level,
    ): ?Response {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            $permission,
            $level,
            $error,
        )) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? PayrollTimeValue::row($body, 'request_body') : [];
    }
}

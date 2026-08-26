<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Payroll\PayrollProductionGateException;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolError;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationTransportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Ručně spuštěný přenos zmrazené registrace PREZEC/REGZEC přes VREP. */
final class PayrollRegistrationTransportAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollRegistrationTransportService $transport,
        private readonly PayrollModuleAccess $access,
        private readonly PayrollProductionGate $productionGate,
    ) {}

    /** @param array{submissionId:string} $args */
    public function send(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $key = trim($request->getHeaderLine('Idempotency-Key'));
        if ($key === '') {
            return $this->invalid($response, 'Hlavička Idempotency-Key je povinná.');
        }

        return $this->run($request, $response, function (string $environment) use (
            $request,
            $args,
            $key,
        ): array {
            $supplierId = $this->currentSupplierId($request);
            $this->productionGate->assertEnvironmentActive(
                $supplierId,
                $environment,
            );

            return $this->transport->send(
                $supplierId,
                $environment,
                $this->id($args, 'submissionId'),
                $key,
                $this->userId($request),
            );
        });
    }

    /** @param array{submissionId:string} $args */
    public function status(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }

        return $this->run($request, $response, fn (string $environment): array =>
            $this->transport->status(
                $this->currentSupplierId($request),
                $environment,
                $this->id($args, 'submissionId'),
            ));
    }

    /** @param array{attemptId:string} $args */
    public function poll(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }

        return $this->run($request, $response, function (string $environment) use ($request, $args): array {
            return $this->outcome($this->transport->poll(
                $this->currentSupplierId($request),
                $environment,
                $this->id($args, 'attemptId'),
            ));
        });
    }

    /** @param array{attemptId:string} $args */
    public function close(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }

        return $this->run($request, $response, fn (string $environment): array =>
            $this->transport->close(
                $this->currentSupplierId($request),
                $environment,
                $this->id($args, 'attemptId'),
            ));
    }

    /** @param callable(string):array<string,mixed> $operation */
    private function run(Request $request, Response $response, callable $operation): Response
    {
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }
        try {
            return $this->noStore(Json::ok($response, $operation($environment)));
        } catch (PayrollProductionGateException $exception) {
            return $this->noStore(Json::error(
                $response,
                PayrollProductionGateException::ERROR_CODE,
                $exception->getMessage(),
                409,
            ));
        } catch (JmhzTransportException $exception) {
            $status = $exception->remoteHttpStatus === 404 ? 404 : 422;
            if (str_starts_with($exception->errorCode, 'jmhz_vrep_')) {
                $status = 502;
            }

            return $this->noStore(Json::error(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                $status,
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->noStore(Json::error(
                $response,
                'conflict',
                $exception->getMessage(),
                409,
            ));
        }
    }

    /** @return array<string,mixed> */
    private function outcome(JmhzDispatchOutcome $outcome): array
    {
        return [
            'attempt' => $outcome->attempt,
            'acknowledgement' => $outcome->acknowledgement === null ? null : [
                'correlation_id' => $outcome->acknowledgement->correlationId,
                'poll_interval_seconds' => $outcome->acknowledgement->pollIntervalSeconds,
                'gateway_timestamp' => $outcome->acknowledgement->gatewayTimestamp,
            ],
            'settled' => $outcome->isSettled(),
            'report' => $outcome->report === null ? null : [
                'status' => $outcome->report->status->name,
                'errors' => array_map(
                    static fn (JmhzProtocolError $error): array => [
                        'code' => $error->code,
                        'message' => $error->message,
                    ],
                    $outcome->report->errors,
                ),
            ],
        ];
    }

    private function environment(Request $request): ?string
    {
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body['environment'] ?? null) : null;
        if (!is_string($value)) {
            $value = $request->getQueryParams()['environment'] ?? 'test';
        }

        return in_array($value, ['test', 'production'], true) ? $value : null;
    }

    /** @param array<string,string> $args */
    private function id(array $args, string $key): int
    {
        $value = $args[$key] ?? '';
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException("{$key} musí být kladné celé číslo.");
        }

        return (int) $value;
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level = AccessLevel::WRITE,
    ): ?Response {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Odeslání registračního podání vyžaduje přihlášenou relaci účetní.',
                403,
            );
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.submissions', $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }

    private function invalid(Response $response, string $message): Response
    {
        return $this->noStore(Json::error($response, 'validation_failed', $message, 422));
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}

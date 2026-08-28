<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\PayrollStatutoryObligationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollStatutoryObligationAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollStatutoryObligationService $service,
        private readonly PayrollModuleAccess $access,
    ) {}

    public function overview(Request $request, Response $response): Response
    {
        $denied = $this->authorize($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $query = $request->getQueryParams();
            $result = $this->service->overview(
                $this->currentSupplierId($request),
                $this->environment($query['environment'] ?? null),
                $this->period($query['period'] ?? null),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->error($response, 'validation_failed', $exception, 422);
        }

        return $this->noStore(Json::ok($response, $result));
    }

    public function record(Request $request, Response $response): Response
    {
        $denied = $this->authorize($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));

        try {
            $body = (array) ($request->getParsedBody() ?? []);
            $result = $this->service->recordEvidence(
                $this->currentSupplierId($request),
                $this->environment($body['environment'] ?? null),
                $this->period($body['period'] ?? null),
                $body,
                $idempotencyKey,
                $this->userId($request) ?? 0,
            );
        } catch (\OutOfBoundsException $exception) {
            return $this->error($response, 'not_found', $exception, 404);
        } catch (\DomainException $exception) {
            return $this->error($response, 'conflict', $exception, 409);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($response, 'validation_failed', $exception, 422);
        }

        return $this->noStore(Json::ok(
            $response,
            $result,
            $result['created'] === true ? 201 : 200,
        ));
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level,
    ): ?Response {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return $this->noStore(Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            ));
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            $level,
            $error,
        )) {
            return $error;
        }
        if (!$this->requirePayrollEnabled(
            $request,
            $response,
            $this->access,
            $error,
        )) {
            return $error;
        }

        return null;
    }

    private function environment(mixed $value): string
    {
        if (!is_string($value)
            || !in_array($value, ['production', 'test'], true)
        ) {
            throw new \InvalidArgumentException(
                'Prostředí musí být production nebo test.',
            );
        }

        return $value;
    }

    private function period(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Období musí mít formát RRRR-MM.',
            );
        }

        return $value;
    }

    private function error(
        Response $response,
        string $code,
        \Throwable $exception,
        int $status,
    ): Response {
        return $this->noStore(Json::error(
            $response,
            $code,
            $exception->getMessage(),
            $status,
        ));
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}

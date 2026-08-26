<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Zmrazení zdrojů měsíčního hlášení nad schválenou mzdovou revizí. Vzniká tím
 * immutable preparation snapshot, na který teprve navazuje test XML.
 *
 * Není to podání ani nic, co by šlo ven — snapshot je jen důkaz, z jakých
 * přesně dat by hlášení vzniklo. Opakované volání se stejným klíčem vrací
 * tentýž snapshot; `created` říká, jestli vznikl teď.
 */
final class PayrollJmhzPreparationAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly JmhzPreparationSnapshotService $service,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** @param array{revisionId:string} $args */
    public function __invoke(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $body = $request->getParsedBody();
        $environment = is_array($body) ? ($body['environment'] ?? 'test') : 'test';
        if (!in_array($environment, ['test', 'production'], true)) {
            return Json::error(
                $response,
                'validation_failed',
                'Prostředí musí být test nebo production.',
                422,
            );
        }
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
        if ($idempotencyKey === '') {
            return Json::error(
                $response,
                'validation_failed',
                'Hlavička Idempotency-Key je povinná.',
                422,
            );
        }
        try {
            $result = $this->service->freeze(
                $this->currentSupplierId($request),
                $this->revisionId($args),
                $environment,
                $idempotencyKey,
                $this->userId($request),
            );
        } catch (JmhzPreparationSnapshotException $exception) {
            return Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                $this->status($exception->validationCode),
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok(
            $response,
            $result,
            ($result['created'] ?? false) === true ? 201 : 200,
        )
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private function authorize(Request $request, Response $response): ?Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            AccessLevel::WRITE,
            $error,
        )) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }

    private function status(string $code): int
    {
        return match ($code) {
            'jmhz_revision_not_found' => 404,
            'jmhz_preparation_idempotency_incomplete',
            'jmhz_preparation_idempotency_scope_mismatch' => 409,
            default => 422,
        };
    }

    /** @param array{revisionId:string} $args */
    private function revisionId(array $args): int
    {
        $value = $args['revisionId'];
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException(
                'revisionId musí být kladné celé číslo.',
            );
        }

        return (int) $value;
    }
}

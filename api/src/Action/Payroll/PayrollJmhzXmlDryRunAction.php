<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlDryRunService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Test měsíčního hlášení nad zmrazeným preparation snapshotem. Jen čtení —
 * endpoint nic neodesílá, nic neukládá a nezakládá podání. Je záměrně
 * session-only, protože vrací celý obsah hlášení včetně identifikátorů osob.
 */
final class PayrollJmhzXmlDryRunAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly JmhzScenario1XmlDryRunService $service,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** @param array{preparationId:string} $args */
    public function __invoke(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $environment = $request->getQueryParams()['environment'] ?? 'test';
        if (!in_array($environment, ['test', 'production'], true)) {
            return Json::error(
                $response,
                'validation_failed',
                'Prostředí musí být test nebo production.',
                422,
            );
        }
        $query = $request->getQueryParams();
        $officeId = self::narrowingId(is_array($query) ? $query : [], 'office');
        if ($officeId !== null && $officeId <= 0) {
            return Json::error(
                $response,
                'validation_failed',
                'office musí být kladné celé číslo.',
                422,
            );
        }
        try {
            $result = $this->service->dryRun(
                $this->currentSupplierId($request),
                $environment,
                $this->preparationId($args),
                $officeId,
            );
        } catch (JmhzXmlException $exception) {
            return Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                422,
            );
        } catch (JmhzPreparationSnapshotException $exception) {
            return Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                $exception->validationCode === 'jmhz_preparation_not_found' ? 404 : 422,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok($response, $result)
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
            AccessLevel::READ,
            $error,
        )) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }

    /** @param array{preparationId:string} $args */
    private function preparationId(array $args): int
    {
        $value = $args['preparationId'];
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException(
                'preparationId musí být kladné celé číslo.',
            );
        }

        return (int) $value;
    }
}

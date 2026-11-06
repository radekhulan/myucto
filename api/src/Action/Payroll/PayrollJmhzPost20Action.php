<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPost20GuideService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Průvodce postupem po 20. dni pro jeden pracovní vztah: které REGZEC
 * podání a která opravná měsíční hlášení k situaci patří a v jakém jsou
 * stavu. Jen čte; podání se zakládají na svých místech (registrace,
 * anulace měsíce).
 */
final class PayrollJmhzPost20Action
{
    use PayrollActionSupport;

    public function __construct(
        private readonly JmhzPost20GuideService $guides,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** @param array{employmentId:string} $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.submissions', AccessLevel::READ, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }
        $query = $request->getQueryParams();
        $environment = $query['environment'] ?? 'production';
        $situation = $query['situation'] ?? null;
        if (!in_array($environment, ['test', 'production'], true)
            || !is_string($situation)
            || preg_match('/^[1-9][0-9]*$/D', $args['employmentId']) !== 1
        ) {
            return Json::error($response, 'validation_failed', 'Vztah, prostředí nebo situace průvodce nejsou platné.', 422);
        }
        try {
            $guide = $this->guides->guide(
                $this->currentSupplierId($request),
                $environment,
                (int) $args['employmentId'],
                $situation,
            );
        } catch (JmhzXmlException|\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        } catch (\DomainException $exception) {
            return Json::error($response, 'not_found', $exception->getMessage(), 404);
        }

        return Json::ok($response, $guide)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}

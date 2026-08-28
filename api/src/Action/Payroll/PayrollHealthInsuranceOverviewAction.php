<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Codebook\HealthInsurers;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceOverviewException;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationException;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverview;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewReconciliationService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollHealthInsuranceOverviewAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly HealthPaymentOverviewService $service,
        private readonly HealthPaymentOverviewReconciliationService $reconciliation,
        private readonly HealthInsuranceSubmissionService $submissions,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array{revisionId:string} $args */
    public function index(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $this->guardFailure($error);
        }

        try {
            $revisionId = $this->routePositiveInt($args, 'revisionId');
            $items = array_map(
                fn (HealthPaymentOverview $overview): array => [
                    ...$overview->toArray(),
                    'sha256' => $overview->sha256(),
                    'filename' => $overview->filename(),
                    'payment_reconciliation' =>
                        $this->reconciliation->forOverview($overview),
                ],
                $this->service->overviews(
                    $this->currentSupplierId($request),
                    $revisionId,
                ),
            );
        } catch (HealthInsuranceOverviewException $exception) {
            return Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                422,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok($response, [
            'items' => $items,
            'electronic_submission' => [
                'direct_portal' => [
                    'supported' => false,
                    'reason_code' =>
                        'health_insurance_portal_transport_undocumented',
                ],
                'isds' => [
                    'supported' => true,
                    'requires_ready' => true,
                    'requires_production_gate' => true,
                    'requires_user_confirmation' => true,
                ],
            ],
        ]);
    }

    /** @param array{revisionId:string,insurerCode:string} $args */
    public function download(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $this->guardFailure($error);
        }

        try {
            $revisionId = $this->routePositiveInt($args, 'revisionId');
            $insurerCode = $args['insurerCode'];
            if (!HealthInsurers::isValid($insurerCode)) {
                throw new \InvalidArgumentException(
                    HealthInsurers::invalidCodeMessage($insurerCode),
                );
            }
            $artifact = $this->submissions->paymentOverviewDownload(
                $this->currentSupplierId($request),
                $revisionId,
                $insurerCode,
            );
        } catch (\OutOfBoundsException) {
            return Json::error(
                $response,
                'not_found',
                'Přehled zdravotní pojišťovny nebyl nalezen.',
                404,
            );
        } catch (HealthInsuranceOverviewException $exception) {
            return Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                422,
            );
        } catch (HealthNotificationException $exception) {
            return Json::error(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                422,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        $this->auditDownload(
            $request,
            $revisionId,
            $insurerCode,
            $artifact['sha256'],
        );
        $bytes = $artifact['bytes'];
        $response->getBody()->write($bytes);

        return $response
            ->withHeader('Content-Type', $artifact['mime_type'])
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $artifact['filename'] . '"',
            )
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('Content-SHA256', $artifact['sha256']);
    }

    private function guard(
        Request $request,
        Response $response,
        AccessLevel $minimum,
        ?Response &$error,
    ): bool {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            $error = Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
            return false;
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            $minimum,
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
            ?? throw new \LogicException(
                'Payroll zdravotní přehled guard selhal bez odpovědi.',
            );
    }

    private function auditDownload(
        Request $request,
        int $revisionId,
        string $insurerCode,
        string $sha256,
    ): void {
        $serverParams = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $serverParams[$key] = $value;
            }
        }
        $this->logger->log(
            'payroll.health_overview.downloaded',
            $this->userId($request),
            'payroll_run_revisions',
            $revisionId,
            [
                'insurer_code' => $insurerCode,
                'sha256' => $sha256,
            ],
            $this->ipMatcher->clientIpFromRequest($serverParams),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }

    /** @param array<string,string> $args */
    private function routePositiveInt(array $args, string $field): int
    {
        $value = $args[$field] ?? null;
        if (!is_string($value)
            || preg_match('/^[1-9][0-9]*$/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException(
                "Parametr {$field} musí být kladné celé číslo.",
            );
        }

        return (int) $value;
    }
}

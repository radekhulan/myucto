<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmployerSettingsConflictException;
use MyInvoice\Repository\Payroll\PayrollEmployerSettingsRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollEmployerSettingsValidator;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollEmployerSettingsAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollEmployerSettingsRepository $settings,
        private readonly PayrollEmployerSettingsValidator $validator,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $error = null;
        if (!$this->requireSession($request, $response, $error)) {
            return $this->errorResponse($error);
        }
        if (!$this->requirePermission($request, $response, 'payroll.settings', AccessLevel::READ, $error)) {
            return $this->errorResponse($error);
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $this->errorResponse($error);
        }

        return Json::ok($response, [
            'settings' => $this->settings->get($this->currentSupplierId($request)),
        ]);
    }

    public function put(Request $request, Response $response): Response
    {
        $error = null;
        if (!$this->requireSession($request, $response, $error)) {
            return $this->errorResponse($error);
        }
        if (!$this->requirePermission($request, $response, 'payroll.settings', AccessLevel::WRITE, $error)) {
            return $this->errorResponse($error);
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $this->errorResponse($error);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $version = filter_var($body['row_version'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        if ($version === false) {
            return Json::error(
                $response,
                'validation_failed',
                'row_version musí být nezáporné celé číslo.',
                422,
            );
        }

        $supplierId = $this->currentSupplierId($request);
        try {
            $normalized = $this->validator->validate($supplierId, $body);
            $settings = $this->settings->save($supplierId, $normalized, (int) $version);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollEmployerSettingsConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }

        $this->logger->log(
            'payroll.employer_settings.updated',
            $this->userId($request),
            'payroll_employer_settings',
            $supplierId,
            [
                'row_version' => $settings['row_version'],
                'default_office_code' => $settings['default_office_code'],
                'office_count' => count($settings['offices']),
            ],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, ['settings' => $settings]);
    }

    private function requireSession(
        Request $request,
        Response $response,
        ?Response &$error,
    ): bool {
        if (!RequestAuthorization::isSessionAuth($request)) {
            $error = Json::sessionRequired($response);
            return false;
        }
        $error = null;
        return true;
    }

    private function errorResponse(?Response $error): Response
    {
        return $error ?? throw new \LogicException('Chybí chybová HTTP odpověď.');
    }
}

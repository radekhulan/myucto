<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollYearCloseBlockedException;
use MyInvoice\Service\Payroll\PayrollYearCloseConflictException;
use MyInvoice\Service\Payroll\PayrollYearCloseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollYearCloseAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollYearCloseService $service,
        private readonly PayrollModuleAccess $moduleAccess,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array{year:string} $args */
    public function get(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, 'payroll', AccessLevel::READ, $error)) {
            return $error;
        }
        try {
            return Json::ok($response, $this->service->status(
                $this->currentSupplierId($request), $this->year($args),
            ))->withHeader('Cache-Control', 'private, no-store');
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }
    }

    /** @param array{year:string} $args */
    public function close(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, 'payroll.approve', AccessLevel::WRITE, $error)) {
            return $error;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        if (!is_numeric($body['row_version'] ?? null) || (int) $body['row_version'] < 0) {
            return Json::error($response, 'validation_failed', 'row_version je povinný (celé číslo alespoň 0).', 422);
        }
        try {
            $closure = $this->service->close(
                $this->currentSupplierId($request), $this->year($args), (int) $body['row_version'],
                $this->userId($request),
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'),
            );
            return Json::ok($response, ['closure' => $closure])
                ->withHeader('Cache-Control', 'private, no-store');
        } catch (PayrollYearCloseBlockedException $exception) {
            return Json::error($response, 'year_close_blocked', $exception->getMessage(), 422, ['blockers' => $exception->blockers]);
        } catch (PayrollYearCloseConflictException $exception) {
            return Json::error($response, 'row_version_conflict', $exception->getMessage(), 409);
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }
    }

    /** @param array{year:string} $args */
    public function reopen(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, 'payroll.reopen', AccessLevel::WRITE, $error)) {
            return $error;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        if (!is_numeric($body['row_version'] ?? null) || (int) $body['row_version'] < 1) {
            return Json::error($response, 'validation_failed', 'row_version je povinný (celé číslo alespoň 1).', 422);
        }
        try {
            $closure = $this->service->reopen(
                $this->currentSupplierId($request), $this->year($args), (int) $body['row_version'],
                $this->userId($request), trim((string) ($body['reason'] ?? '')),
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'),
            );
            return Json::ok($response, ['closure' => $closure])
                ->withHeader('Cache-Control', 'private, no-store');
        } catch (PayrollYearCloseConflictException $exception) {
            return Json::error($response, 'row_version_conflict', $exception->getMessage(), 409);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }
    }

    private function guard(
        Request $request,
        Response $response,
        string $permission,
        AccessLevel $level,
        ?Response &$error,
    ): bool
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            $error = Json::error($response, 'session_required', 'Tento endpoint je dostupný pouze z přihlášené webové session.', 403);
            return false;
        }
        if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
            return false;
        }
        return $this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error);
    }

    /** @param array<string,string> $args */
    private function year(array $args): int
    {
        $year = (int) ($args['year'] ?? 0);
        if ($year < 2000 || $year > 2200) {
            throw new \InvalidArgumentException('Mzdový rok musí být v rozsahu 2000 až 2200.');
        }
        return $year;
    }
}

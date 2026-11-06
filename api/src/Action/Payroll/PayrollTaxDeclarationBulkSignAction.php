<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollTaxDeclarationBulkSign;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hromadný zápis podepsaných prohlášení poplatníka ({@see PayrollTaxDeclarationBulkSign}).
 *
 * Právo `payroll.person.write` — tentýž zápis zákonné evidence jako na kartě
 * osoby, jen se session. Náhled chrání totéž právo: slouží jen k rozhodnutí
 * o zápisu a vypisuje stav prohlášení napříč firmou.
 */
final class PayrollTaxDeclarationBulkSignAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollTaxDeclarationBulkSign $bulk,
        private readonly PayrollModuleAccess $access,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function preview(Request $request, Response $response, array $args = []): Response
    {
        $error = null;
        $body = $this->body($request, $response, $error);
        if ($body === null) {
            return $error ?? throw new \LogicException('Chybí chybová HTTP odpověď.');
        }
        $employeeIds = $body['employee_ids'] ?? null;
        if ($employeeIds !== null && (!is_array($employeeIds) || !array_is_list($employeeIds))) {
            return $this->invalid($response, 'employee_ids musí být seznam nebo null.');
        }

        try {
            $preview = $this->bulk->preview(
                $this->currentSupplierId($request),
                $body['year'] ?? null,
                $body['signed_on'] ?? null,
                $employeeIds,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        }

        return Json::ok($response, ['preview' => $preview]);
    }

    /** @param array<string,string> $args */
    public function apply(Request $request, Response $response, array $args = []): Response
    {
        $error = null;
        $body = $this->body($request, $response, $error);
        if ($body === null) {
            return $error ?? throw new \LogicException('Chybí chybová HTTP odpověď.');
        }
        $employeeIds = $body['employee_ids'] ?? null;
        if (!is_array($employeeIds) || !array_is_list($employeeIds)) {
            return $this->invalid($response, 'employee_ids musí být seznam osob z náhledu.');
        }
        $params = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        try {
            $result = $this->bulk->apply(
                $this->currentSupplierId($request),
                $body['year'] ?? null,
                $body['signed_on'] ?? null,
                $employeeIds,
                $this->userId($request),
                $this->ipMatcher->clientIpFromRequest($params),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        }

        return Json::ok($response, ['result' => $result]);
    }

    /** @return array<string,mixed>|null */
    private function body(Request $request, Response $response, ?Response &$error): ?array
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            $error = Json::sessionRequired($response);
            return null;
        }
        if (!$this->requirePermission($request, $response, 'payroll.person.write', AccessLevel::WRITE, $error)) {
            return null;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return null;
        }
        $parsed = $request->getParsedBody();
        if (!is_array($parsed) || array_is_list($parsed)) {
            $error = $this->invalid($response, 'Tělo požadavku musí být objekt.');
            return null;
        }
        $body = [];
        foreach ($parsed as $key => $value) {
            if (is_string($key)) {
                $body[$key] = $value;
            }
        }

        return $body;
    }

    private function invalid(Response $response, string $message): Response
    {
        return Json::error($response, 'validation_failed', $message, 422);
    }
}

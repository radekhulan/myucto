<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollStatutoryEvidenceBulkDefaults;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hromadné doplnění výchozí zákonné evidence ({@see PayrollStatutoryEvidenceBulkDefaults}).
 *
 * Náhled i zápis chrání `payroll.person.write` — náhled je sice čtení, ale
 * slouží jen k tomu, aby účetní rozhodla o zápisu, a vypisuje osobní údaje
 * napříč celou firmou (věk, cizí prvek). Stejně jako karta osoby jen se session.
 */
final class PayrollStatutoryEvidenceBulkDefaultsAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollStatutoryEvidenceBulkDefaults $bulk,
        private readonly PayrollModuleAccess $access,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function preview(Request $request, Response $response, array $args = []): Response
    {
        $error = null;
        $body = $this->body($request, $response, $error);
        if ($body === null) {
            return $this->errorResponse($error);
        }
        $employeeIds = $body['employee_ids'] ?? null;
        if ($employeeIds !== null && (!is_array($employeeIds) || !array_is_list($employeeIds))) {
            return $this->invalid($response, 'employee_ids musí být seznam nebo null.');
        }

        try {
            $preview = $this->bulk->preview(
                $this->currentSupplierId($request),
                $this->effectiveOn($body),
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
            return $this->errorResponse($error);
        }
        $employeeIds = $body['employee_ids'] ?? null;
        if (!is_array($employeeIds) || !array_is_list($employeeIds)) {
            return $this->invalid($response, 'employee_ids musí být seznam osob z náhledu.');
        }
        $sections = $body['sections'] ?? PayrollStatutoryEvidenceBulkDefaults::DEFAULT_SECTIONS;
        if (!is_array($sections) || !array_is_list($sections)) {
            return $this->invalid($response, 'sections musí být seznam.');
        }
        // Jen doslovné `true`: nepodepsané prohlášení je tvrzení, které
        // nesmí vzniknout z „1", „ano" ani z chybějícího klíče.
        $recordUnsigned = ($body['record_unsigned_declaration'] ?? false) === true;
        // Cizinec s pobytem a prací v Česku: českou rezidenci a příslušnost
        // potvrzuje účetní výslovně, opět jen doslovné `true`.
        $confirmForeign = ($body['confirm_foreign_citizens'] ?? false) === true;

        try {
            $result = $this->bulk->apply(
                $this->currentSupplierId($request),
                $this->effectiveOn($body),
                $employeeIds,
                $sections,
                $recordUnsigned,
                $this->userId($request),
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent'),
                $confirmForeign,
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
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.person.write',
            AccessLevel::WRITE,
            $error,
        )) {
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

    /** @param array<string,mixed> $body */
    private function effectiveOn(array $body): string
    {
        $value = $body['effective_on'] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException('effective_on musí být datum YYYY-MM-DD.');
        }

        return $value;
    }

    private function invalid(Response $response, string $message): Response
    {
        return Json::error($response, 'validation_failed', $message, 422);
    }

    private function clientIp(Request $request): ?string
    {
        $params = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $this->ipMatcher->clientIpFromRequest($params);
    }

    private function errorResponse(?Response $error): Response
    {
        return $error ?? throw new \LogicException('Chybí chybová HTTP odpověď.');
    }
}

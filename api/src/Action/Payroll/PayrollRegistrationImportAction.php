<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Mzdy → Importy → Registrace JMHZ: náhled a použití XML registrací ČSSZ.
 *
 * Jen přihlášená relace: import zakládá osoby a zapisuje identifikátory ČSSZ,
 * stejně jako karta osoby, která je session-only. Použití navíc vyžaduje právo
 * na pracovní vztahy, protože vztahy zakládá, aktivuje a ukončuje. Věty NEMPRI,
 * HZUPN a OZUSPOJ zakládají případy dávek a záměry slevy, takže jejich zápis
 * navíc vyžaduje právo `payroll.submissions` (náhled ho nevyžaduje).
 */
final class PayrollRegistrationImportAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly RegistrationImportService $imports,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, false)) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $preview = $this->imports->preview(
                $this->currentSupplierId($request),
                $this->environment($body),
                $body['files'] ?? null,
                $body['pairs'] ?? null,
                $body['relation_types'] ?? null,
                $body['terminations'] ?? null,
                $body['received_on'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, $preview);
    }

    public function apply(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, true)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $ip = $this->ipMatcher->clientIpFromRequest($this->serverParams($request));
        try {
            $body = $this->input($request);
            $officeId = $body['office_id'] ?? null;
            if ($officeId !== null && !is_int($officeId)) {
                throw new \InvalidArgumentException('Mzdová účtárna není platná.');
            }
            $result = $this->imports->apply(
                $supplierId,
                $this->environment($body),
                $body['files'] ?? null,
                $body['keys'] ?? null,
                ($body['evidence_confirmed'] ?? false) === true,
                $officeId,
                $this->userId($request),
                $ip,
                $request->getHeaderLine('User-Agent'),
                $body['pairs'] ?? null,
                ($body['apply_opening_balances'] ?? false) === true,
                ($body['apply_averages'] ?? false) === true,
                ($body['auto_approve_changes'] ?? false) === true,
                ($body['auto_approve_averages'] ?? false) === true,
                ($body['apply_takeover'] ?? false) === true,
                $body['relation_types'] ?? null,
                $body['terminations'] ?? null,
                $body['received_on'] ?? null,
                // Podání dávek (NEMPRI, HZUPN) a záměry slevy (OZUSPOJ) jinde chrání
                // právo ke správě podání; import je smí zapsat jen s ním.
                RequestAuthorization::allows($request, 'payroll.submissions', AccessLevel::WRITE),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $this->logger->log(
            'payroll.registration_import.applied',
            $this->userId($request),
            'payroll_registration_import',
            null,
            $result['summary'] + [
                'environment' => $body['environment'] ?? null,
                'opening_balances_saved' => $result['opening_balances']['saved'],
                'averages_created' => $result['averages']['created'],
                'takeover_months_saved' => $result['takeover']['saved'] ?? 0,
                'outcome' => $result['outcome'],
                'unresolved' => count($result['unresolved']),
            ],
            $ip,
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, $result);
    }

    private function authorize(Request $request, Response $response, bool $writesEmployments): ?Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.person.write', AccessLevel::WRITE, $error)) {
            return $error;
        }
        if ($writesEmployments && !$this->requirePermission(
            $request,
            $response,
            'payroll.employment.write',
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

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || array_is_list($body)) {
            throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
        }
        $input = [];
        foreach ($body as $key => $value) {
            if (is_string($key)) {
                $input[$key] = $value;
            }
        }

        return $input;
    }

    /** @param array<string,mixed> $body */
    private function environment(array $body): string
    {
        $environment = $body['environment'] ?? null;
        if (!is_string($environment)) {
            throw new \InvalidArgumentException('Zvolte prostředí ČSSZ: ostré (production), nebo testovací (test).');
        }

        return $environment;
    }

    /** @return array<string,mixed> */
    private function serverParams(Request $request): array
    {
        $params = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}

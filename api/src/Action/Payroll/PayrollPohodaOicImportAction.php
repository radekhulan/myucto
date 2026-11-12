<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Import\ImportFiles;
use MyInvoice\Service\Payroll\Import\Pohoda\PohodaPersonnelOicImportService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Mzdy → Importy → OIČ z POHODY. Jen session: export Personalistiky nese
 * rodná čísla celé firmy a nahrává se z obrazovky, ne z integrace přes token.
 *
 * Práva jsou stejná jako u ručního zadání OIČ na kartě vztahu
 * ({@see PayrollJmhzIdentityAction}): osoba i pracovní vztah.
 */
final class PayrollPohodaOicImportAction
{
    use PayrollActionSupport;

    private const FILE_EXTENSIONS = ['xlsx', 'csv'];

    public function __construct(
        private readonly PohodaPersonnelOicImportService $imports,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $preview = $this->imports->preview(
                $this->currentSupplierId($request),
                ImportFiles::fromRequest($body['files'] ?? null, self::FILE_EXTENSIONS),
                $this->environment($body),
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, $preview);
    }

    public function apply(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $body = $this->input($request);
            $environment = $this->environment($body);
            $result = $this->imports->apply(
                $supplierId,
                ImportFiles::fromRequest($body['files'] ?? null, self::FILE_EXTENSIONS),
                $environment,
                $body['keys'] ?? null,
                ($body['evidence_confirmed'] ?? false) === true,
                $this->userId($request),
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $this->logger->log(
            'payroll.pohoda_oic_import.applied',
            $this->userId($request),
            'payroll_pohoda_oic_import',
            null,
            $result['summary'] + [
                'environment' => $environment,
                'employment_ids' => array_values(array_map(
                    static fn (array $row): ?int => $row['employment_id'],
                    array_filter($result['results'], static fn (array $row): bool => $row['status'] === 'applied'),
                )),
            ],
            $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, $result);
    }

    private function authorize(Request $request, Response $response): ?Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        foreach (['payroll.person.write', 'payroll.employment.write'] as $permission) {
            if (!$this->requirePermission($request, $response, $permission, AccessLevel::WRITE, $error)) {
                return $error;
            }
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
        $environment = $body['environment'] ?? 'production';
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

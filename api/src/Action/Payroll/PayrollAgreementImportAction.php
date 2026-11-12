<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Import\Agreement\AgreementImportService;
use MyInvoice\Service\Payroll\Import\Agreement\AgreementImportTemplate;
use MyInvoice\Service\Payroll\Import\ImportFiles;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Mzdy → Importy → Dohody: založení dohod a osob ze souboru bez docházky.
 * Jen session, soubor nese osobní údaje.
 */
final class PayrollAgreementImportAction
{
    use PayrollActionSupport;

    private const FILE_EXTENSIONS = ['xlsx', 'csv'];

    public function __construct(
        private readonly AgreementImportService $imports,
        private readonly AgreementImportTemplate $template,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {
    }

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
        $ip = $this->ipMatcher->clientIpFromRequest(PayrollTimeValue::row($request->getServerParams(), 'server_params'));
        try {
            $body = $this->input($request);
            $result = $this->imports->apply(
                $this->currentSupplierId($request),
                ImportFiles::fromRequest($body['files'] ?? null, self::FILE_EXTENSIONS),
                $body['confirmations'] ?? null,
                $body['person_keys'] ?? null,
                $this->userId($request),
                $ip,
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $this->logger->log(
            'payroll.agreement_import.applied',
            $this->userId($request),
            'payroll_employment',
            null,
            $result['counts'],
            $ip,
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );

        return Json::ok($response, $result, 201);
    }

    public function template(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        $file = $this->template->build();
        $response->getBody()->write($file['bytes']);

        return $response
            ->withHeader('Content-Type', $file['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Content-Length', (string) strlen($file['bytes']));
    }

    private function authorize(Request $request, Response $response): ?Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.person.write', AccessLevel::WRITE, $error)) {
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

        return is_array($body) ? PayrollTimeValue::row($body, 'request_body') : [];
    }
}

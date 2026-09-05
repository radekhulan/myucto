<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AnnualTaxCertificateAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly AnnualTaxCertificateService $certificates,
        private readonly PayrollModuleAccess $moduleAccess,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function generate(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response, 'Tento endpoint je dostupný pouze z přihlášené webové session.');
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::WRITE,
            $error,
        ) || !$this->requirePayrollEnabled(
            $request,
            $response,
            $this->moduleAccess,
            $error,
        )) {
            return $error ?? Json::error(
                $response,
                'forbidden',
                'Pro tuto akci nemáš oprávnění.',
                403,
            );
        }
        $supplierId = $this->currentSupplierId($request);
        $userId = $this->userId($request);
        $employeeId = (int) ($args['employeeId'] ?? 0);
        $year = (int) ($args['year'] ?? 0);
        $kind = self::kind($args['kind'] ?? '');
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $supersedesDocumentId = self::optionalPositiveInt(
            $body['supersedes_document_id'] ?? null,
        );
        $correctionReason = self::optionalText(
            $body['correction_reason'] ?? null,
        );
        if ($userId === null
            || $employeeId <= 0
            || $year < 2000
            || $year > 2199
            || $kind === null
            || (($body['supersedes_document_id'] ?? null) !== null
                && $supersedesDocumentId === null)
            || (($body['correction_reason'] ?? null) !== null
                && $correctionReason === null)
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Požadavek na roční daňové potvrzení není platný.',
                422,
            );
        }
        try {
            $document = $this->certificates->generate(
                $supplierId,
                $employeeId,
                $year,
                $kind,
                $userId,
                function (array $document) use (
                    $request,
                    $supplierId,
                    $userId,
                ): void {
                    $this->activity->log(
                        'payroll.annual_tax_certificate_generated',
                        $userId,
                        'payroll_document',
                        self::positiveDocumentInt($document, 'id'),
                        [
                            'document_kind' => self::documentText(
                                $document,
                                'document_kind',
                            ),
                            'annual_revision_id' =>
                                self::positiveDocumentInt(
                                    $document,
                                    'annual_revision_id',
                                ),
                            'file_sha256' => self::documentText(
                                $document,
                                'file_sha256',
                            ),
                        ],
                        $this->ipMatcher->clientIpFromRequest(
                            self::serverParams($request),
                        ),
                        $request->getHeaderLine('User-Agent'),
                        $supplierId,
                    );
                },
                $supersedesDocumentId,
                $correctionReason,
            );
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'tax_certificate_incomplete',
                $exception->getMessage(),
                422,
            );
        } catch (\Throwable) {
            return Json::error(
                $response,
                'tax_certificate_failed',
                'Daňové potvrzení se nepodařilo vytvořit. Zkontrolujte '
                . 'schválené mzdy, zákonnou evidenci a skutečné úhrady.',
                409,
            );
        }
        return Json::ok($response, self::publicDocument($document), 201);
    }

    private static function kind(string $routeKind): ?PayrollDocumentKind
    {
        return match ($routeKind) {
            'advance' =>
                PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            'withholding' =>
                PayrollDocumentKind::TaxableIncomeWithholdingCertificate,
            default => null,
        };
    }

    private static function optionalPositiveInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if ((!is_int($value) && !is_string($value))
            || filter_var($value, FILTER_VALIDATE_INT) === false
            || (int) $value <= 0
        ) {
            return null;
        }

        return (int) $value;
    }

    private static function optionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $document */
    private static function positiveDocumentInt(
        array $document,
        string $key,
    ): int {
        $value = $document[$key] ?? null;
        if ((!is_int($value) && !is_string($value))
            || filter_var($value, FILTER_VALIDATE_INT) === false
            || (int) $value <= 0
        ) {
            throw new \UnexpectedValueException(
                "Vygenerované potvrzení nemá platné pole {$key}.",
            );
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $document */
    private static function documentText(
        array $document,
        string $key,
    ): string {
        $value = $document[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \UnexpectedValueException(
                "Vygenerované potvrzení nemá platné pole {$key}.",
            );
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private static function serverParams(Request $request): array
    {
        $result = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private static function publicDocument(array $document): array
    {
        $keys = [
            'id', 'run_id', 'revision_id', 'revision_no', 'revision_status',
            'annual_revision_id', 'annual_revision_no', 'tax_year', 'purpose',
            'office_id', 'office_name', 'employee_id', 'employee_name',
            'document_kind', 'document_revision_no', 'supersedes_document_id',
            'file_sha256', 'size_bytes', 'mime_type', 'suggested_filename',
            'created_at',
        ];
        $result = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $document)) {
                $result[$key] = $document[$key];
            }
        }

        return $result;
    }
}

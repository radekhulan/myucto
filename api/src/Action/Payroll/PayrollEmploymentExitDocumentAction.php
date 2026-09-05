<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Document\AverageEarningsSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\EmploymentExitDocumentService;
use MyInvoice\Service\Payroll\Document\EmploymentExitReadinessException;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollEmploymentExitDocumentAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly EmploymentExitDocumentService $documents,
        private readonly PayrollDocumentRepository $documentRepository,
        private readonly PayrollModuleAccess $moduleAccess,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function list(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($sessionError = $this->sessionOnly($request, $response)) !== null) {
            return $sessionError;
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::READ,
            $error,
        ) || !$this->requirePayrollEnabled(
            $request,
            $response,
            $this->moduleAccess,
            $error,
        )) {
            return $this->privateResponse(
                $error ?? Json::error(
                    $response,
                    'forbidden',
                    'Pro tuto akci nemáš oprávnění.',
                    403,
                ),
            );
        }
        $supplierId = $this->currentSupplierId($request);
        $employmentId = (int) ($args['id'] ?? 0);
        if ($employmentId <= 0) {
            return $this->privateResponse(Json::error(
                $response,
                'validation_failed',
                'Pracovní vztah není platný.',
                422,
            ));
        }
        try {
            $readiness = $this->documents->readiness(
                $supplierId,
                $employmentId,
            );
            $items = $this->documentRepository->listEmploymentExitDocuments(
                $supplierId,
                $employmentId,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->privateResponse(Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            ));
        }

        return $this->privateJson($response, [
            'employment_id' => $employmentId,
            'readiness' => $readiness,
            'items' => array_map(self::publicDocument(...), $items),
        ]);
    }

    /** @param array<string,string> $args */
    public function generate(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($sessionError = $this->sessionOnly($request, $response)) !== null) {
            return $sessionError;
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
            return $this->privateResponse(
                $error ?? Json::error(
                    $response,
                    'forbidden',
                    'Pro tuto akci nemáš oprávnění.',
                    403,
                ),
            );
        }
        $supplierId = $this->currentSupplierId($request);
        $userId = $this->userId($request);
        $employmentId = (int) ($args['id'] ?? 0);
        $kind = $args['kind'] ?? '';
        $idempotencyKey = trim(
            $request->getHeaderLine('Idempotency-Key'),
        );
        $parsed = $request->getParsedBody();
        $body = is_array($parsed) && !array_is_list($parsed)
            ? self::objectInput($parsed)
            : null;
        if ($userId === null
            || $employmentId <= 0
            || $idempotencyKey === ''
            || $body === null
            || !in_array($kind, [
                'employment-certificate',
                'average-earnings-certificate',
                'average-earnings-statement',
            ], true)
        ) {
            return $this->privateResponse(Json::error(
                $response,
                'validation_failed',
                'Požadavek na výstupní dokument není platný.',
                422,
            ));
        }

        try {
            $document = $kind === 'employment-certificate'
                ? $this->documents->generateEmploymentCertificate(
                    $supplierId,
                    $employmentId,
                    $body,
                    $idempotencyKey,
                    $userId,
                )
                : $this->documents->generateAverageEarningsDocument(
                    $supplierId,
                    $employmentId,
                    $kind === 'average-earnings-certificate'
                        ? AverageEarningsSnapshotBuilder::CERTIFICATE_PURPOSE
                        : AverageEarningsSnapshotBuilder::STATEMENT_PURPOSE,
                    $body,
                    $idempotencyKey,
                    $userId,
                );
        } catch (EmploymentExitReadinessException $exception) {
            return $this->privateResponse(Json::error(
                $response,
                $exception->readinessCode,
                $exception->getMessage(),
                422,
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->privateResponse(Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            ));
        } catch (\Throwable) {
            return $this->privateResponse(Json::error(
                $response,
                'employment_exit_generation_failed',
                'Výstupní dokument se nepodařilo bezpečně vytvořit.',
                409,
            ));
        }

        $this->activity->log(
            'payroll.employment_exit_document_generated',
            $userId,
            'payroll_document',
            self::positiveInt($document, 'id'),
            [
                'employment_id' => $employmentId,
                'document_kind' => self::text($document, 'document_kind'),
                'employment_exit_revision_id' =>
                    self::positiveInt(
                        $document,
                        'employment_exit_revision_id',
                    ),
                'file_sha256' => self::text($document, 'file_sha256'),
            ],
            $this->ipMatcher->clientIpFromRequest(
                self::serverParams($request),
            ),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return $this->privateJson(
            $response,
            self::publicDocument($document),
            201,
        );
    }

    private function sessionOnly(
        Request $request,
        Response $response,
    ): ?Response {
        if (RequestAuthorization::isSessionAuth($request)) {
            return null;
        }

        return $this->privateResponse(Json::sessionRequired($response, 'Tento endpoint je dostupný pouze z přihlášené webové session.'));
    }

    private function privateJson(
        Response $response,
        mixed $data,
        int $status = 200,
    ): Response {
        return Json::ok($response, $data, $status)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private function privateResponse(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,mixed> $document
     *  @return array<string,mixed>
     */
    private static function publicDocument(array $document): array
    {
        $keys = [
            'id',
            'employee_id',
            'employee_name',
            'employment_id',
            'employment_end_date',
            'employment_exit_revision_id',
            'employment_exit_revision_no',
            'purpose',
            'document_kind',
            'document_revision_no',
            'supersedes_document_id',
            'file_sha256',
            'size_bytes',
            'mime_type',
            'suggested_filename',
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

    /** @param array<string,mixed> $row */
    private static function positiveInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) || $value <= 0) {
            throw new \UnexpectedValueException(
                "Výstupní dokument nemá platné pole {$field}.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function text(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Výstupní dokument nemá platné pole {$field}.",
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
     * @param array<array-key,mixed> $value
     * @return array<string,mixed>|null
     */
    private static function objectInput(array $value): ?array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return null;
            }
            $result[$key] = $item;
        }

        return $result;
    }
}

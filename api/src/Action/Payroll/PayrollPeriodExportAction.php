<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Export\PayrollPeriodExportService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollPeriodExportAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollPeriodExportService $exports,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function createMonthly(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        return $this->create($request, $response, function (
            int $supplierId,
            int $userId,
        ) use ($args): array {
            return $this->exports->createMonthly(
                $supplierId,
                (string) ($args['period'] ?? ''),
                $userId,
            );
        });
    }

    /** @param array<string,string> $args */
    public function createAnnual(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        return $this->create($request, $response, function (
            int $supplierId,
            int $userId,
        ) use ($args): array {
            return $this->exports->createAnnual(
                $supplierId,
                (int) ($args['year'] ?? 0),
                $userId,
            );
        });
    }

    /** @param array<string,string> $args */
    public function grant(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            AccessLevel::READ,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $exportId = $this->positiveInteger($args['exportId'] ?? null);
        $userId = $this->userId($request);
        $body = $request->getParsedBody();
        $ttlSeconds = is_array($body)
            && !array_is_list($body)
            && array_key_exists('ttl_seconds', $body)
                ? $body['ttl_seconds']
                : 300;
        if ($exportId === null || $userId === null || !is_int($ttlSeconds)) {
            return Json::error(
                $response,
                'not_found',
                'Export mezd nebyl nalezen.',
                404,
            );
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $grant = $this->exports->issueDownloadGrant(
                $supplierId,
                $exportId,
                $userId,
                $ttlSeconds,
                /** @param array{grant_id:int,export_id:int,ttl_seconds:int} $issued */
                function (array $issued) use (
                    $request,
                    $supplierId,
                    $userId,
                ): void {
                    $this->activity->log(
                        'payroll.period_export_download_grant_issued',
                        $userId,
                        'payroll_period_export',
                        $this->requiredInteger($issued, 'export_id'),
                        [
                            'grant_id' => $issued['grant_id'],
                            'ttl_seconds' => $issued['ttl_seconds'],
                        ],
                        $this->ipMatcher->clientIpFromRequest(
                            $this->serverParams($request),
                        ),
                        $request->getHeaderLine('User-Agent'),
                        $supplierId,
                    );
                },
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException) {
            return Json::error(
                $response,
                'not_found',
                'Export mezd nebyl nalezen.',
                404,
            );
        }

        return Json::ok($response, $grant, 201)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function download(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            AccessLevel::READ,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $body = $request->getParsedBody();
        $token = is_array($body) && !array_is_list($body)
            ? ($body['token'] ?? null)
            : null;
        $userId = $this->userId($request);
        if (!is_string($token) || $userId === null) {
            return Json::error(
                $response,
                'validation_failed',
                'Stažení vyžaduje platný jednorázový odkaz.',
                422,
            );
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $file = $this->exports->consumeDownload(
                $supplierId,
                $userId,
                $token,
                /** @param array{export_id:int,file_sha256:string,size_bytes:int} $downloaded */
                function (array $downloaded) use (
                    $request,
                    $supplierId,
                    $userId,
                ): void {
                    $this->activity->log(
                        'payroll.period_export_downloaded',
                        $userId,
                        'payroll_period_export',
                        $this->requiredInteger($downloaded, 'export_id'),
                        [
                            'file_sha256' => $downloaded['file_sha256'],
                            'size_bytes' => $downloaded['size_bytes'],
                        ],
                        $this->ipMatcher->clientIpFromRequest(
                            $this->serverParams($request),
                        ),
                        $request->getHeaderLine('User-Agent'),
                        $supplierId,
                    );
                },
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException) {
            return Json::error(
                $response,
                'download_grant_invalid',
                'Odkaz ke stažení není platný nebo již vypršel.',
                409,
            );
        }
        if ($response->getBody()->write($file['bytes'])
            !== $file['size_bytes']
        ) {
            return Json::error(
                $response,
                'download_failed',
                'Export mezd nelze stáhnout.',
                500,
            );
        }

        return $response
            ->withHeader('Content-Type', $file['mime_type'])
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="'
                    . $file['suggested_filename']
                    . '"',
            )
            ->withHeader('Content-Length', (string) $file['size_bytes'])
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'none'; sandbox",
            );
    }

    /**
     * @param callable(int,int):array{id:int,export_scope:string,period_start:string,period_end:string,file_sha256:string,size_bytes:int,storage_key:string,suggested_filename:string,source_manifest_hash:string,manifest_json:string,mime_type:string,created_at:string} $factory
     */
    private function create(
        Request $request,
        Response $response,
        callable $factory,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $supplierId = $this->currentSupplierId($request);
        $userId = $this->userId($request);
        if ($userId === null) {
            return Json::error(
                $response,
                'session_required',
                'Chybí přihlášený uživatel.',
                403,
            );
        }
        try {
            $export = $factory($supplierId, $userId);
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException $exception) {
            return Json::error(
                $response,
                'payroll_export_not_ready',
                $exception->getMessage(),
                409,
            );
        } catch (\Throwable) {
            return Json::error(
                $response,
                'payroll_export_failed',
                'Export mezd se nepodařilo bezpečně sestavit.',
                409,
            );
        }
        $this->activity->log(
            'payroll.period_export_created',
            $userId,
            'payroll_period_export',
            (int) $export['id'],
            [
                'scope' => $export['export_scope'],
                'period_start' => $export['period_start'],
                'period_end' => $export['period_end'],
                'file_sha256' => $export['file_sha256'],
                'size_bytes' => $export['size_bytes'],
            ],
            $this->ipMatcher->clientIpFromRequest(
                $this->serverParams($request),
            ),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, [
            'id' => (int) $export['id'],
            'scope' => $export['export_scope'],
            'period_start' => $export['period_start'],
            'period_end' => $export['period_end'],
            'file_sha256' => $export['file_sha256'],
            'size_bytes' => (int) $export['size_bytes'],
            'suggested_filename' => $export['suggested_filename'],
        ], 201)->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level,
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
        return $this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            $level,
            $error,
        ) && $this->requirePayrollEnabled(
            $request,
            $response,
            $this->access,
            $error,
        );
    }

    private function errorResponse(?Response $error): Response
    {
        return $error ?? throw new \LogicException(
            'Chybí odpověď pro zamítnuté oprávnění.',
        );
    }

    private function positiveInteger(mixed $value): ?int
    {
        $result = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        return is_int($result) ? $result : null;
    }

    /** @param array<string,mixed> $row */
    private function requiredInteger(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Pole {$field} exportu mezd není celé číslo.",
            );
        }
        $result = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($result)) {
            throw new \UnexpectedValueException(
                "Pole {$field} exportu mezd není celé číslo.",
            );
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function serverParams(Request $request): array
    {
        $result = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}

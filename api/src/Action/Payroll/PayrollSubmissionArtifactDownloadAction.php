<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionArtifactDownloadService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollSubmissionArtifactDownloadAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollSubmissionArtifactDownloadService $downloads,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function grant(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize($request, $response, $error)) {
            return $this->errorResponse($error);
        }
        [$submissionId, $artifactId] = $this->identifiers($args);
        $userId = $this->userId($request);
        $body = $request->getParsedBody();
        if ($body !== null && (!is_array($body) || array_is_list($body))) {
            return Json::error(
                $response,
                'validation_failed',
                'Tělo požadavku musí být objekt.',
                422,
            );
        }
        $ttlSeconds = is_array($body)
            && array_key_exists('ttl_seconds', $body)
                ? $body['ttl_seconds']
                : 120;
        if ($submissionId === null
            || $artifactId === null
            || $userId === null
            || !is_int($ttlSeconds)
        ) {
            return Json::error(
                $response,
                'not_found',
                'Artefakt podání nebyl nalezen.',
                404,
            );
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $grant = $this->downloads->issue(
                $supplierId,
                $submissionId,
                $artifactId,
                $userId,
                $ttlSeconds,
                function (array $issued) use (
                    $request,
                    $supplierId,
                    $userId,
                ): void {
                    $this->activity->log(
                        'payroll.submission_artifact_download_grant_issued',
                        $userId,
                        'payroll_submission_artifact',
                        $issued['artifact_id'],
                        [
                            'grant_id' => $issued['grant_id'],
                            'submission_id' => $issued['submission_id'],
                            'environment' => $issued['environment'],
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
                'Artefakt podání nebyl nalezen.',
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
        if (!$this->authorize($request, $response, $error)) {
            return $this->errorResponse($error);
        }
        [$submissionId, $artifactId] = $this->identifiers($args);
        $userId = $this->userId($request);
        $token = trim(
            $request->getHeaderLine('X-Payroll-Download-Token'),
        );
        if ($submissionId === null
            || $artifactId === null
            || $userId === null
            || $token === ''
        ) {
            return Json::error(
                $response,
                'not_found',
                'Artefakt podání nebyl nalezen.',
                404,
            );
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $file = $this->downloads->consume(
                $supplierId,
                $submissionId,
                $artifactId,
                $userId,
                $token,
                function (array $downloaded) use (
                    $request,
                    $supplierId,
                    $userId,
                ): void {
                    $this->activity->log(
                        'payroll.submission_artifact_downloaded',
                        $userId,
                        'payroll_submission_artifact',
                        $downloaded['artifact_id'],
                        [
                            'submission_id' =>
                                $downloaded['submission_id'],
                            'environment' => $downloaded['environment'],
                            'artifact_kind' =>
                                $downloaded['artifact_kind'],
                            'byte_size' => $downloaded['byte_size'],
                            'mime_type' => $downloaded['mime_type'],
                        ],
                        $this->ipMatcher->clientIpFromRequest(
                            $this->serverParams($request),
                        ),
                        $request->getHeaderLine('User-Agent'),
                        $supplierId,
                    );
                },
            );
        } catch (\InvalidArgumentException|\DomainException) {
            return Json::error(
                $response,
                'not_found',
                'Artefakt podání nebyl nalezen.',
                404,
            );
        }
        if ($response->getBody()->write($file['bytes'])
            !== $file['byte_size']
        ) {
            return Json::error(
                $response,
                'download_failed',
                'Artefakt podání nelze stáhnout.',
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
            ->withHeader(
                'Content-Length',
                (string) $file['byte_size'],
            )
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'none'; sandbox",
            );
    }

    private function authorize(
        Request $request,
        Response $response,
        ?Response &$error,
    ): bool {
        if (!RequestAuthorization::isSessionAuth($request)) {
            $error = Json::sessionRequired($response);

            return false;
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            AccessLevel::READ,
            $error,
        )) {
            return false;
        }

        return $this->requirePayrollEnabled(
            $request,
            $response,
            $this->access,
            $error,
        );
    }

    private function errorResponse(?Response $error): Response
    {
        if ($error === null) {
            throw new \LogicException(
                'Chybí odpověď pro zamítnuté oprávnění.',
            );
        }

        return $error;
    }

    /**
     * @param array<string,string> $args
     * @return array{?int,?int}
     */
    private function identifiers(array $args): array
    {
        return [
            $this->positiveInteger($args['submissionId'] ?? null),
            $this->positiveInteger($args['artifactId'] ?? null),
        ];
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

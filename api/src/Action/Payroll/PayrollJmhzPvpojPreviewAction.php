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
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreview;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreviewException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreviewService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollJmhzPvpojPreviewAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly JmhzPvpojPreviewService $service,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array{revisionId:string} $args */
    public function __invoke(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->guard($request, $response, $error)) {
            return $this->guardFailure($error);
        }

        try {
            $preview = $this->preview($request, $args);
        } catch (JmhzPvpojPreviewException $exception) {
            return Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                422,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok($response, [
            ...$preview->toArray(),
            'sha256' => $preview->sha256(),
            'filename' => $preview->filename(),
        ])
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array{revisionId:string} $args */
    public function download(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->guard($request, $response, $error)) {
            return $this->guardFailure($error);
        }

        try {
            $preview = $this->preview($request, $args);
        } catch (JmhzPvpojPreviewException $exception) {
            return Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                422,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        $this->auditDownload($request, $preview);
        $bytes = $preview->downloadBytes();
        $response->getBody()->write($bytes);

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $preview->filename() . '"',
            )
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('Content-SHA256', $preview->sha256());
    }

    /**
     * Mzdové účtárny, za které se z revize podává.
     *
     * PVPOJ je podání ZA REGISTRACI U OSSZ, takže běh přes víc účtáren dá víc
     * přehledů. Bez tohohle seznamu by uživatel neměl kde zjistit, které
     * `office` má do náhledu poslat.
     *
     * @param array{revisionId:string} $args
     */
    public function offices(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->guard($request, $response, $error)) {
            return $this->guardFailure($error);
        }

        try {
            $offices = $this->service->offices(
                $this->currentSupplierId($request),
                $this->routePositiveInt($args, 'revisionId'),
            );
        } catch (JmhzPvpojPreviewException $exception) {
            return Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                422,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok($response, ['offices' => $offices])
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /**
     * @param array{revisionId:string} $args
     */
    private function preview(
        Request $request,
        array $args,
    ): JmhzPvpojPreview {
        $query = $request->getQueryParams();

        return $this->service->preview(
            $this->currentSupplierId($request),
            $this->routePositiveInt($args, 'revisionId'),
            self::narrowingId(is_array($query) ? $query : [], 'office'),
        );
    }

    private function guard(
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

    private function guardFailure(?Response $error): Response
    {
        return $error
            ?? throw new \LogicException(
                'Payroll PVPOJ preview guard selhal bez odpovědi.',
            );
    }

    private function auditDownload(
        Request $request,
        JmhzPvpojPreview $preview,
    ): void {
        $serverParams = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $serverParams[$key] = $value;
            }
        }
        $this->logger->log(
            'payroll.jmhz_pvpoj_preview.downloaded',
            $this->userId($request),
            'payroll_run_revisions',
            $preview->revisionId,
            [
                'sha256' => $preview->sha256(),
                'period' => $preview->period,
                'office_id' => $preview->office['office_id'],
            ],
            $this->ipMatcher->clientIpFromRequest($serverParams),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }

    /** @param array<string,string> $args */
    private function routePositiveInt(array $args, string $field): int
    {
        $value = $args[$field] ?? null;
        if (!is_string($value)
            || preg_match('/^[1-9][0-9]*$/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException(
                "Parametr {$field} musí být kladné celé číslo.",
            );
        }

        return (int) $value;
    }
}

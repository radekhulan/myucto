<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\JmhzEmployerAnnualEvidenceConflictException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEmployerAnnualEvidenceService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollJmhzEmployerAnnualEvidenceAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly JmhzEmployerAnnualEvidenceService $service,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array{reportYear:string} $args */
    public function get(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        try {
            $view = $this->service->view(
                $this->currentSupplierId($request),
                $this->year($args),
            );
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }

        return Json::ok($response, $view)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array{reportYear:string} $args */
    public function save(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = self::object($request->getParsedBody());
        if ($body === null) {
            return Json::error($response, 'validation_failed', 'Tělo požadavku není objekt.', 422);
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $year = $this->year($args);
            $evidence = $this->service->save(
                $supplierId,
                $year,
                $body,
                $this->userId($request),
            );
            $view = $this->service->view($supplierId, $year);
        } catch (JmhzEmployerAnnualEvidenceConflictException $exception) {
            return Json::error(
                $response,
                'revision_conflict',
                $exception->getMessage(),
                409,
                ['current_revision_id' => $exception->currentRevisionId],
            );
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }
        $this->logger->log(
            'payroll.jmhz_employer_annual_evidence.created',
            $this->userId($request),
            'payroll_jmhz_employer_annual_evidence',
            self::positiveInt($evidence['id'] ?? null, 'id'),
            [
                'report_year' => $year,
                'revision_no' => $evidence['revision_no'],
                'payload_sha256' => $evidence['payload_sha256'],
            ],
            $this->ipMatcher->clientIpFromRequest(
                self::object($request->getServerParams()) ?? [],
            ),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, $view, 201)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level,
    ): ?Response {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            $level,
            $error,
        )) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }

    /** @param array{reportYear:string} $args */
    private function year(array $args): int
    {
        $value = $args['reportYear'];
        if (preg_match('/^20[2-9][0-9]$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Rok ročních údajů JMHZ není platný.');
        }

        return (int) $value;
    }

    /** @return array<string,mixed>|null */
    private static function object(mixed $value): ?array
    {
        if (!is_array($value) || array_is_list($value)) {
            return null;
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return null;
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new \UnexpectedValueException("{$field} není kladné celé číslo.");
        }

        return $value;
    }
}

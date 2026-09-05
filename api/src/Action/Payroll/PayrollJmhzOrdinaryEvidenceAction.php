<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOrdinaryEvidenceException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOrdinaryEvidenceService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollJmhzOrdinaryEvidenceAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly JmhzOrdinaryEvidenceService $service,
        private readonly PayrollModuleAccess $access,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array{revisionId:string} $args */
    public function get(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        try {
            $state = $this->service->evidence(
                $this->currentSupplierId($request),
                $this->revisionId($args),
            );
        } catch (JmhzOrdinaryEvidenceException $exception) {
            return $this->domainError($response, $exception);
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }

        return Json::ok($response, $state)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array{revisionId:string,employmentId:string} $args */
    public function confirm(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = $request->getParsedBody();
        $bodyKeys = is_array($body) ? array_keys($body) : [];
        sort($bodyKeys, SORT_STRING);
        if (!is_array($body) || array_is_list($body)
            || $bodyKeys !== ['evidence_confirmed', 'facts']
            || !is_array($body['facts']) || array_is_list($body['facts'])
            || $body['evidence_confirmed'] !== true
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Tělo musí obsahovat přesně facts a výslovné evidence_confirmed=true.',
                422,
            );
        }
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
        $actor = $this->userId($request);
        if ($actor === null) {
            return Json::error($response, 'actor_required', 'Potvrzující uživatel chybí.', 422);
        }
        $serverParams = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $serverParams[$key] = $value;
            }
        }
        try {
            $result = $this->service->confirm(
                $this->currentSupplierId($request),
                $this->revisionId($args),
                $this->positiveId($args['employmentId'] ?? '', 'employmentId'),
                $body['facts'],
                $idempotencyKey,
                $actor,
                $this->ipMatcher->clientIpFromRequest($serverParams),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (JmhzOrdinaryEvidenceException $exception) {
            return $this->domainError($response, $exception);
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }

        return Json::ok($response, $result, ($result['created'] ?? false) === true ? 201 : 200)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level,
    ): ?Response {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
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

    /** @param array<string,string> $args */
    private function revisionId(array $args): int
    {
        return $this->positiveId($args['revisionId'] ?? '', 'revisionId');
    }

    private function positiveId(string $value, string $field): int
    {
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException("{$field} musí být kladné celé číslo.");
        }
        return (int) $value;
    }

    private function domainError(
        Response $response,
        JmhzOrdinaryEvidenceException $exception,
    ): Response {
        $status = match ($exception->validationCode) {
            'jmhz_ordinary_evidence_not_found' => 404,
            'jmhz_ordinary_evidence_idempotency_scope_mismatch',
            'jmhz_ordinary_evidence_idempotency_payload_mismatch',
            'jmhz_ordinary_evidence_idempotency_incomplete',
            'jmhz_ordinary_evidence_scope_already_frozen' => 409,
            default => 422,
        };
        return Json::error(
            $response,
            $exception->validationCode,
            $exception->getMessage(),
            $status,
        );
    }
}

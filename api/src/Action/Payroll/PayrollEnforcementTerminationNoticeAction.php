<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Garnishment\EnforcementTerminationNoticeService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Oznámení soudu / exekutorovi o skončení pracovního poměru povinného
 * (§ 295 odst. 2 o. s. ř.) — přehled, vystavení, PDF, odeslání.
 */
final class PayrollEnforcementTerminationNoticeAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly EnforcementTerminationNoticeService $notices,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
    ) {}

    /** @param array<string,string> $args */
    public function overview(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        try {
            return Json::ok($response, $this->notices->overview(
                $this->currentSupplierId($request),
                (int) $args['id'],
            ));
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        }
    }

    /** @param array<string,string> $args */
    public function generate(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = $this->body($request);
        try {
            $notice = $this->notices->generate(
                $this->currentSupplierId($request),
                (int) $args['id'],
                self::text($body['new_payer_name'] ?? null),
                self::text($body['new_payer_reference'] ?? null),
                $this->userId($request),
            );
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\DomainException $e) {
            return Json::error($response, 'termination_notice_blocked', $e->getMessage(), 409);
        }
        $this->log($request, 'payroll.enforcement.termination_notice.generated', $notice);

        return Json::ok($response, $notice, 201);
    }

    /** @param array<string,string> $args */
    public function pdf(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        try {
            $pdf = $this->notices->pdf($this->currentSupplierId($request), (int) $args['noticeId']);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'termination_notice_invalid', $e->getMessage(), 409);
        }
        $response->getBody()->write($pdf['bytes']);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $pdf['filename'] . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string,string> $args */
    public function markSent(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = $this->body($request);
        try {
            $notice = $this->notices->markSent(
                $this->currentSupplierId($request),
                (int) $args['noticeId'],
                (string) ($body['sent_on'] ?? ''),
                (string) ($body['channel'] ?? ''),
                $this->userId($request),
            );
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $this->log($request, 'payroll.enforcement.termination_notice.sent', $notice);

        return Json::ok($response, $notice);
    }

    /** @param array<string,string> $args */
    public function enqueue(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = $this->body($request);
        $recipientId = filter_var($body['recipient_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!is_int($recipientId)) {
            return Json::error(
                $response,
                'validation_failed',
                'Vyberte adresáta z číselníku příjemců datové schránky.',
                422,
            );
        }
        try {
            $queued = $this->notices->enqueueIsds(
                $this->currentSupplierId($request),
                (int) $args['noticeId'],
                $recipientId,
                (string) ($body['environment'] ?? 'production'),
                $this->userId($request),
            );
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'enqueue_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, $queued);
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.enforcement', $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** @param array<string,mixed> $notice */
    private function log(Request $request, string $action, array $notice): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'payroll_enforcement_termination_notice',
            PayrollTimeValue::int($notice['id'] ?? null, 'id'),
            [
                'case_id' => $notice['case_id'] ?? null,
                'revision_no' => $notice['revision_no'] ?? null,
                'snapshot_hash' => $notice['snapshot_hash'] ?? null,
            ],
            null,
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}

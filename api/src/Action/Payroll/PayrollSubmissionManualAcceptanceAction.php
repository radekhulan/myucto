<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollSubmissionConflictException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollYearClosedException;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionManualAcceptanceService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Ruční potvrzení přijetí podání podle aplikace úřadu.
 *
 *   GET  /submissions/{submissionId}/manual-acceptance?environment=…
 *        smí se potvrdit + historie ručních potvrzení i rozporů s protokolem
 *   POST /submissions/{submissionId}/manual-acceptance
 *        multipart: environment, row_version, variant, note, accepted_on?, file?
 *        hlavička Idempotency-Key je povinná
 *
 * Oprávnění je stejné jako u načtení protokolu: přihlášená relace a zápis do
 * `payroll.submissions`. Nic se neodesílá ven, platí proto pro test
 * i produkci stejně.
 */
final class PayrollSubmissionManualAcceptanceAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollSubmissionManualAcceptanceService $acceptances,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array{submissionId?:string} $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $environment = $request->getQueryParams()['environment'] ?? null;
        if (!is_string($environment) || !in_array($environment, ['production', 'test'], true)) {
            return $this->invalid($response, 'Prostředí podání musí být test nebo production.');
        }
        $submissionId = $this->submissionId($args);
        if ($submissionId === null) {
            return $this->invalid($response, 'Identifikátor podání musí být kladné celé číslo.');
        }
        $overview = $this->acceptances->overview(
            $this->currentSupplierId($request),
            $environment,
            $submissionId,
        );
        if ($overview === null) {
            return $this->noStore(Json::error($response, 'not_found', 'Podání nebylo nalezeno.', 404));
        }

        return $this->noStore(Json::ok($response, $overview));
    }

    /** @param array{submissionId?:string} $args */
    public function accept(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $userId = $this->userId($request);
        if ($userId === null) {
            return $this->noStore(Json::sessionRequired($response));
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $environment = $body['environment'] ?? null;
        if (!is_string($environment) || !in_array($environment, ['production', 'test'], true)) {
            return $this->invalid($response, 'Prostředí podání musí být test nebo production.');
        }
        $submissionId = $this->submissionId($args);
        if ($submissionId === null) {
            return $this->invalid($response, 'Identifikátor podání musí být kladné celé číslo.');
        }
        $rowVersion = $body['row_version'] ?? null;
        if (!is_int($rowVersion)
            && (!is_string($rowVersion) || preg_match('/^[1-9][0-9]*$/D', $rowVersion) !== 1)
        ) {
            return $this->invalid($response, 'Verze podání musí být kladné celé číslo.');
        }
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
        if ($idempotencyKey === '') {
            return $this->invalid($response, 'Hlavička Idempotency-Key je povinná.');
        }
        $attachment = null;
        $file = $this->firstFile($request->getUploadedFiles());
        if ($file !== null && $file->getError() !== UPLOAD_ERR_NO_FILE) {
            if ($file->getError() !== UPLOAD_ERR_OK) {
                return $this->invalid($response, 'Přílohu se nepodařilo nahrát.');
            }
            if ((int) ($file->getSize() ?? 0) > PayrollSubmissionManualAcceptanceService::ATTACHMENT_MAX_BYTES) {
                return $this->noStore(Json::error($response, 'too_large', 'Příloha je větší než 10 MB.', 413));
            }
            $attachment = $file->getStream()->getContents();
        }
        $acceptedOn = $body['accepted_on'] ?? null;

        try {
            $result = $this->acceptances->accept(
                $this->currentSupplierId($request),
                $environment,
                $submissionId,
                (int) $rowVersion,
                is_string($body['variant'] ?? null) ? $body['variant'] : '',
                is_string($body['note'] ?? null) ? $body['note'] : '',
                is_string($acceptedOn) ? $acceptedOn : null,
                $attachment,
                $idempotencyKey,
                $userId,
            );
        } catch (PayrollSubmissionConflictException $exception) {
            return $this->noStore(Json::error($response, 'row_version_conflict', $exception->getMessage(), 409));
        } catch (PayrollYearClosedException $exception) {
            return $this->noStore(self::yearClosedError($response, $exception));
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->noStore(Json::error($response, 'conflict', $exception->getMessage(), 409));
        }

        if ($result['created'] === true) {
            $acceptance = $result['acceptance'];
            $this->logger->log(
                'payroll_submission.manual_acceptance',
                $userId,
                'payroll_submission',
                $submissionId,
                [
                    'environment' => $environment,
                    'acceptance_id' => $acceptance['id'],
                    'variant' => $acceptance['variant'],
                    'status_before' => $acceptance['status_before'],
                    'authority_accepted_on' => $acceptance['authority_accepted_on'],
                    'attachment_artifact_id' => $acceptance['attachment_artifact_id'],
                ],
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'),
                $this->currentSupplierId($request),
            );
        }

        return $this->noStore(Json::ok($response, ['environment' => $environment, ...$result]));
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response, 'Ruční potvrzení podání je dostupné jen z přihlášené relace.');
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.submissions', $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }

    /** @param array{submissionId?:string} $args */
    private function submissionId(array $args): ?int
    {
        $value = $args['submissionId'] ?? '';

        return preg_match('/^[1-9][0-9]*$/D', $value) === 1 ? (int) $value : null;
    }

    /** @param array<array-key,mixed> $uploads */
    private function firstFile(array $uploads): ?UploadedFileInterface
    {
        foreach ($uploads as $node) {
            if ($node instanceof UploadedFileInterface) {
                return $node;
            }
        }

        return null;
    }

    private function invalid(Response $response, string $message): Response
    {
        return $this->noStore(Json::error($response, 'validation_failed', $message, 422));
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}

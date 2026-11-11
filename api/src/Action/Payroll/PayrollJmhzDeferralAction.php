<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeferralService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Odložení pracovního vztahu z řádného měsíčního hlášení JMHZ.
 *
 * Rozhodnutí účetní, které má dopad na splnění zákonné povinnosti, proto:
 * jen z přihlášené relace, s právem zapisovat do mzdových podání, s povinným
 * důvodem a se zápisem do auditní stopy. Čtení přehledu stačí právo číst.
 */
final class PayrollJmhzDeferralAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly JmhzDeferralService $deferrals,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $query = $request->getQueryParams();
        $revisionId = self::positive(is_array($query) ? ($query['revision'] ?? null) : null);
        $environment = self::environment(is_array($query) ? ($query['environment'] ?? null) : null);
        if ($revisionId === null || $environment === null) {
            return $this->invalid($response, 'validation_failed', 'Revize nebo prostředí nejsou platné.');
        }
        try {
            $result = $this->deferrals->listForRevision(
                $this->currentSupplierId($request),
                $revisionId,
                $environment,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, 'validation_failed', $exception->getMessage());
        } catch (\DomainException $exception) {
            return Json::error($response, 'not_found', $exception->getMessage(), 404);
        }

        return $this->ok($response, $result);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $environment = self::environment($body['environment'] ?? null);
        $preparationId = self::positive($body['preparation_id'] ?? null);
        $employmentId = self::positive($body['employment_id'] ?? null);
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';
        $officeId = self::narrowingId($body, 'office');
        if ($environment === null || $preparationId === null || $employmentId === null
            || ($officeId !== null && $officeId <= 0)
        ) {
            return $this->invalid(
                $response,
                'validation_failed',
                'Příprava, pracovní vztah nebo prostředí odložení nejsou platné.',
            );
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $result = $this->deferrals->defer(
                $supplierId,
                $environment,
                $preparationId,
                $employmentId,
                $reason,
                (int) $this->userId($request),
                $officeId,
            );
        } catch (JmhzXmlException | JmhzPreparationSnapshotException $exception) {
            return $this->invalid($response, $exception->validationCode, $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, 'validation_failed', $exception->getMessage());
        }
        foreach ($result['deferral_ids'] as $deferralId) {
            $this->audit($request, $supplierId, 'payroll.jmhz_deferral.created', $deferralId, [
                'employment_ids' => $result['employment_ids'],
                'source_revision_id' => $result['source_revision_id'],
                'preparation_id' => $preparationId,
                'environment' => $environment,
                'blocker_codes' => $result['blocker_codes'],
                'reason' => trim($reason),
            ]);
        }

        return $this->ok($response, $result, $result['created'] ? 201 : 200);
    }

    /**
     * „Odložit všechny dotčené": jedním důvodem odloží každou osobu přípravy
     * s nálezem, kvůli kterému lze odložit (nebo jen zadané vztahy).
     */
    public function createMany(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $environment = self::environment($body['environment'] ?? null);
        $preparationId = self::positive($body['preparation_id'] ?? null);
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';
        $officeId = self::narrowingId($body, 'office');
        $employmentIds = null;
        if (array_key_exists('employment_ids', $body) && $body['employment_ids'] !== null) {
            $employmentIds = [];
            foreach (is_array($body['employment_ids']) ? $body['employment_ids'] : [false] as $value) {
                $id = self::positive($value);
                if ($id === null) {
                    $employmentIds = false;
                    break;
                }
                $employmentIds[] = $id;
            }
        }
        if ($environment === null || $preparationId === null || $employmentIds === false || $employmentIds === []
            || ($officeId !== null && $officeId <= 0)
        ) {
            return $this->invalid(
                $response,
                'validation_failed',
                'Příprava, pracovní vztahy nebo prostředí odložení nejsou platné.',
            );
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $result = $this->deferrals->deferMany(
                $supplierId,
                $environment,
                $preparationId,
                $employmentIds,
                $reason,
                (int) $this->userId($request),
                $officeId,
            );
        } catch (JmhzXmlException | JmhzPreparationSnapshotException $exception) {
            return $this->invalid($response, $exception->validationCode, $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, 'validation_failed', $exception->getMessage());
        }
        foreach ($result['deferral_ids'] as $deferralId) {
            $this->audit($request, $supplierId, 'payroll.jmhz_deferral.created', $deferralId, [
                'bulk' => true,
                'employment_ids' => $result['employment_ids'],
                'source_revision_id' => $result['source_revision_id'],
                'preparation_id' => $preparationId,
                'environment' => $environment,
                'reason' => trim($reason),
            ]);
        }

        return $this->ok($response, $result, $result['created'] ? 201 : 200);
    }

    /** Převezme trvající odložení z dřívější revize běhu do revize přípravy. */
    public function carryOver(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $environment = self::environment($body['environment'] ?? null);
        $preparationId = self::positive($body['preparation_id'] ?? null);
        $officeId = self::narrowingId($body, 'office');
        if ($environment === null || $preparationId === null || ($officeId !== null && $officeId <= 0)) {
            return $this->invalid($response, 'validation_failed', 'Příprava nebo prostředí nejsou platné.');
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $result = $this->deferrals->carryOver(
                $supplierId,
                $environment,
                $preparationId,
                (int) $this->userId($request),
                $officeId,
            );
        } catch (JmhzXmlException | JmhzPreparationSnapshotException $exception) {
            return $this->invalid($response, $exception->validationCode, $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, 'validation_failed', $exception->getMessage());
        }
        foreach ($result['deferral_ids'] as $deferralId) {
            $this->audit($request, $supplierId, 'payroll.jmhz_deferral.carried_over', $deferralId, [
                'carried_from_deferral_id' => $result['carried_from'][$deferralId] ?? null,
                'source_revision_id' => $result['source_revision_id'],
                'preparation_id' => $preparationId,
                'environment' => $environment,
            ]);
        }

        return $this->ok($response, $result, $result['created'] ? 201 : 200);
    }

    /** „Doplnit všechny odložené" jedním opravným hlášením za registraci. */
    public function completeAll(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $revisionId = self::positive($body['revision'] ?? null);
        $environment = self::environment($body['environment'] ?? null);
        if ($revisionId === null || $environment === null) {
            return $this->invalid($response, 'validation_failed', 'Revize nebo prostředí nejsou platné.');
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $result = $this->deferrals->completeAll(
                $supplierId,
                $revisionId,
                $environment,
                (int) $this->userId($request),
            );
        } catch (JmhzXmlException | JmhzPreparationSnapshotException $exception) {
            return $this->invalid($response, $exception->validationCode, $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, 'validation_failed', $exception->getMessage());
        } catch (\DomainException $exception) {
            return Json::error($response, 'conflict', $exception->getMessage(), 409);
        }
        foreach ($result['submissions'] as $submission) {
            $this->audit($request, $supplierId, 'payroll.jmhz_deferral.completed', (int) ($submission['submission_id'] ?? 0), [
                'bulk' => true,
                'environment' => $environment,
                'correction_submission_id' => $submission['submission_id'] ?? null,
                'preparation_id' => $result['preparation_id'],
                'employment_ids' => $result['completed_employment_ids'],
            ]);
        }

        return $this->ok($response, $result, 201);
    }

    /** @param array{id:string} $args */
    public function revoke(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $deferralId = self::positive($args['id'] ?? null);
        $rowVersion = self::positive($body['row_version'] ?? null);
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';
        if ($deferralId === null || $rowVersion === null) {
            return $this->invalid($response, 'validation_failed', 'Odložení nebo jeho verze nejsou platné.');
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $result = $this->deferrals->revoke(
                $supplierId,
                $deferralId,
                $rowVersion,
                $reason,
                (int) $this->userId($request),
            );
        } catch (JmhzXmlException $exception) {
            return Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                $exception->validationCode === 'jmhz_deferral_conflict' ? 409 : 422,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, 'validation_failed', $exception->getMessage());
        } catch (\DomainException $exception) {
            return Json::error($response, 'not_found', $exception->getMessage(), 404);
        }
        foreach ($result['revoked_ids'] as $revokedId) {
            $this->audit($request, $supplierId, 'payroll.jmhz_deferral.revoked', $revokedId, [
                'source_revision_id' => $result['source_revision_id'],
                'reason' => trim($reason),
            ]);
        }

        return $this->ok($response, $result);
    }

    /** @param array{id:string} $args */
    public function complete(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $deferralId = self::positive($args['id'] ?? null);
        $environment = self::environment($body['environment'] ?? null);
        if ($deferralId === null || $environment === null) {
            return $this->invalid($response, 'validation_failed', 'Odložení nebo prostředí nejsou platné.');
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $result = $this->deferrals->complete(
                $supplierId,
                $deferralId,
                $environment,
                (int) $this->userId($request),
            );
        } catch (JmhzXmlException | JmhzPreparationSnapshotException $exception) {
            return $this->invalid($response, $exception->validationCode, $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, 'validation_failed', $exception->getMessage());
        } catch (\DomainException $exception) {
            return Json::error($response, 'conflict', $exception->getMessage(), 409);
        }
        $this->audit($request, $supplierId, 'payroll.jmhz_deferral.completed', $deferralId, [
            'environment' => $environment,
            'correction_submission_id' => $result['submission_id'] ?? null,
            'preparation_id' => $result['preparation_id'],
            'employment_ids' => $result['completed_employment_ids'],
        ]);

        return $this->ok($response, $result, ($result['created'] ?? false) === true ? 201 : 200);
    }

    /** @param array<string,mixed> $payload */
    private function audit(Request $request, int $supplierId, string $action, int $entityId, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'payroll_jmhz_deferral',
            $entityId,
            $payload,
            $this->ipMatcher->clientIpFromRequest(
                PayrollTimeValue::row($request->getServerParams(), 'server_params'),
            ),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
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

    /** @param array<string,mixed> $data */
    private function ok(Response $response, array $data, int $status = 200): Response
    {
        return Json::ok($response, $data, $status)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private function invalid(Response $response, string $code, string $message): Response
    {
        return Json::error($response, $code, $message, 422)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private static function positive(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        return is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1
            ? (int) $value
            : null;
    }

    private static function environment(mixed $value): ?string
    {
        return in_array($value, ['test', 'production'], true) ? $value : null;
    }
}

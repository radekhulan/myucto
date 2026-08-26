<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Repository\Payroll\PayrollEnforcementClaimMutationBlockedException;
use MyInvoice\Repository\Payroll\PayrollEnforcementConflictException;
use MyInvoice\Repository\Payroll\PayrollEnforcementDeletionBlockedException;
use MyInvoice\Repository\Payroll\PayrollEnforcementRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseCommand;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseLifecycle;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseStatus;
use MyInvoice\Service\Payroll\Garnishment\EnforcementDecisionDocumentReference;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollEnforcementAction
{
    use PayrollActionSupport;

    private const SAVEPOINT = 'payroll_enforcement_action';

    public function __construct(
        private readonly PayrollEnforcementRepository $repository,
        private readonly EnforcementCaseLifecycle $lifecycle,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
        private readonly DocumentRepository $documents,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        try {
            $query = $request->getQueryParams();
            $employeeId = $this->optionalPositiveInt($query['employee_id'] ?? null, 'employee_id');
            $status = isset($query['status']) && $query['status'] !== ''
                ? EnforcementCaseStatus::from(
                    PayrollTimeValue::string($query['status'], 'status'),
                )
                : null;
            // Oba filtry jsou volitelné, takže bez stropu tenhle endpoint četl
            // všechny případy, které firma kdy vedla. Strop je tvrdý (ne jen
            // výchozí), aby ho nešlo zvednout parametrem z URL.
            $limit = max(1, min(
                PayrollEnforcementRepository::LIST_MAX_LIMIT,
                (int) ($query['limit'] ?? PayrollEnforcementRepository::LIST_DEFAULT_LIMIT),
            ));
            $offset = max(0, (int) ($query['offset'] ?? 0));
        } catch (\ValueError|\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        $page = $this->repository->listCases(
            $this->currentSupplierId($request),
            $employeeId,
            $status,
            $limit,
            $offset,
        );

        // Klíč `cases` zůstává, aby stávající volající nespadli; `total`/`limit`/
        // `offset` přibyly vedle něj, protože seznam už nemusí být úplný.
        return Json::ok($response, [
            'cases' => $page['items'],
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** @param array{id:string} $args */
    public function detail(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        $case = $this->repository->findCase(
            $this->currentSupplierId($request),
            (int) $args['id'],
        );
        return $case === null
            ? Json::error($response, 'not_found', 'Exekuční případ nebyl nalezen.', 404)
            : Json::ok($response, ['case' => $this->presentCase($request, $case)]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $case = PayrollTimeValue::row($this->transactional(
                function () use ($request, $body): array {
                    $case = $this->repository->createCase(
                        $this->currentSupplierId($request),
                        $this->positiveInt($body['employee_id'] ?? null, 'employee_id'),
                        PayrollTimeValue::string($body['case_kind'] ?? null, 'case_kind'),
                        PayrollTimeValue::string(
                            $body['effective_from'] ?? null,
                            'effective_from',
                        ),
                        $this->userId($request),
                    );
                    $this->audit($request, 'payroll.enforcement.case.created', $case);
                    return $case;
                },
            ), 'enforcement_case');
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        return Json::ok($response, ['case' => $this->presentCase($request, $case)], 201);
    }

    /** @param array{id:string} $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $deleted = $this->transactional(
                function () use ($request, $args, $body): ?array {
                    $case = $this->repository->deleteUnusedCase(
                        $this->currentSupplierId($request),
                        (int) $args['id'],
                        $this->positiveInt($body['row_version'] ?? null, 'row_version'),
                    );
                    if ($case !== null) {
                        $this->audit(
                            $request,
                            'payroll.enforcement.case.deleted',
                            $case,
                        );
                    }
                    return $case;
                },
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollEnforcementConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        } catch (PayrollEnforcementDeletionBlockedException $e) {
            return Json::error(
                $response,
                'enforcement_case_delete_blocked',
                $e->getMessage(),
                409,
                ['blocker' => $e->blockerCode, 'suggestion' => 'stop'],
            );
        }
        if ($deleted === null) {
            return Json::error(
                $response,
                'not_found',
                'Exekuční případ nebyl nalezen.',
                404,
            );
        }
        return Json::ok($response, [
            'deleted' => true,
            'id' => PayrollTimeValue::int($deleted['id'] ?? null, 'id'),
        ]);
    }

    /** @param array{id:string} $args */
    public function addClaim(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $claim = $this->transactional(
                function () use ($request, $args): array {
                    $claim = $this->repository->addClaim(
                        $this->currentSupplierId($request),
                        (int) $args['id'],
                        $this->input($request),
                    );
                    $this->audit(
                        $request,
                        'payroll.enforcement.claim.created',
                        $claim,
                        'payroll_enforcement_claim',
                    );
                    return $claim;
                },
            );
        } catch (\ValueError|\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\DomainException $e) {
            return Json::error($response, 'invalid_case_state', $e->getMessage(), 409);
        }
        return Json::ok($response, ['claim' => $claim], 201);
    }

    /** @param array{id:string,claimId:string} $args */
    public function updateClaim(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $claim = $this->transactional(
                function () use ($request, $args, $body): ?array {
                    $claim = $this->repository->updateUnusedClaim(
                        $this->currentSupplierId($request),
                        (int) $args['id'],
                        (int) $args['claimId'],
                        $body,
                        $this->positiveInt($body['row_version'] ?? null, 'row_version'),
                    );
                    if ($claim !== null) {
                        $this->audit(
                            $request,
                            'payroll.enforcement.claim.updated',
                            $claim,
                            'payroll_enforcement_claim',
                        );
                    }
                    return $claim;
                },
            );
        } catch (\ValueError|\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollEnforcementConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        } catch (PayrollEnforcementClaimMutationBlockedException $e) {
            return $this->claimMutationBlocked($response, $e);
        }
        return $claim === null
            ? Json::error($response, 'not_found', 'Pohledávka nebyla nalezena.', 404)
            : Json::ok($response, ['claim' => $claim]);
    }

    /** @param array{id:string,claimId:string} $args */
    public function deleteClaim(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $deletedValue = $this->transactional(
                function () use ($request, $args, $body): ?array {
                    $claim = $this->repository->deleteUnusedClaim(
                        $this->currentSupplierId($request),
                        (int) $args['id'],
                        (int) $args['claimId'],
                        $this->positiveInt($body['row_version'] ?? null, 'row_version'),
                    );
                    if ($claim !== null) {
                        $this->audit(
                            $request,
                            'payroll.enforcement.claim.deleted',
                            $claim,
                            'payroll_enforcement_claim',
                        );
                    }
                    return $claim;
                },
            );
            $deleted = $deletedValue === null
                ? null
                : PayrollTimeValue::row($deletedValue, 'deleted_claim');
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollEnforcementConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        } catch (PayrollEnforcementClaimMutationBlockedException $e) {
            return $this->claimMutationBlocked($response, $e);
        }
        if ($deleted === null) {
            return Json::error($response, 'not_found', 'Pohledávka nebyla nalezena.', 404);
        }
        return Json::ok($response, [
            'deleted' => true,
            'id' => PayrollTimeValue::int($deleted['id'] ?? null, 'id'),
            'case_id' => PayrollTimeValue::int($deleted['case_id'] ?? null, 'case_id'),
            'case_row_version' => PayrollTimeValue::int(
                $deleted['case_row_version'] ?? null,
                'case_row_version',
            ),
        ]);
    }

    /** @param array{id:string} $args */
    public function updateEvidence(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $case = PayrollTimeValue::row($this->transactional(
                function () use ($request, $args, $body): array {
                    $case = $this->repository->updateCaseEvidence(
                        $this->currentSupplierId($request),
                        (int) $args['id'],
                        $this->requiredBool(
                            $body['evidence_complete'] ?? null,
                            'evidence_complete',
                        ),
                        $this->requiredBool(
                            $body['recipient_verified'] ?? null,
                            'recipient_verified',
                        ),
                        $this->positiveInt($body['row_version'] ?? null, 'row_version'),
                        $this->userId($request),
                        $this->optionalPositiveInt(
                            $body['recipient_institution_id'] ?? null,
                            'recipient_institution_id',
                        ),
                        array_key_exists('recipient_institution_id', $body),
                    );
                    $this->audit(
                        $request,
                        'payroll.enforcement.case.evidence.updated',
                        $case,
                    );
                    return $case;
                },
            ), 'enforcement_case');
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollEnforcementConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        } catch (\DomainException $e) {
            return Json::error($response, 'evidence_incomplete', $e->getMessage(), 409);
        }
        return Json::ok($response, ['case' => $this->presentCase($request, $case)]);
    }

    /** @param array{id:string,command:string} $args */
    public function transition(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $command = EnforcementCaseCommand::from($args['command']);
            $decisionDocumentId = $this->optionalPositiveInt(
                $body['decision_document_id'] ?? null,
                'decision_document_id',
            );
            if ($command->requiresDecisionDocument() && $decisionDocumentId === null) {
                throw new \InvalidArgumentException(
                    'Přechod vyžaduje rozhodnutí z dokumentů.',
                );
            }
            if (!$command->requiresDecisionDocument() && $decisionDocumentId !== null) {
                throw new \InvalidArgumentException(
                    'Tento přechod nepřijímá rozhodnutí z dokumentů.',
                );
            }
            $reason = $this->optionalString($body['reason'] ?? null);
            if ($reason !== null && mb_strlen($reason) > 500) {
                throw new \InvalidArgumentException('Důvod může mít nejvýše 500 znaků.');
            }
            if (
                $command->requiresDecisionDocument()
                && !$this->requirePermission(
                    $request,
                    $response,
                    'documents',
                    AccessLevel::READ,
                    $error,
                )
            ) {
                return $error ?? Json::error(
                    $response,
                    'forbidden',
                    'Pro tuto akci nemáš oprávnění.',
                    403,
                );
            }
            $case = PayrollTimeValue::row($this->transactional(
                function () use (
                    $request,
                    $args,
                    $body,
                    $command,
                    $decisionDocumentId,
                    $reason,
                ): array {
                    $decisionDocument = null;
                    if ($decisionDocumentId !== null) {
                        $document = $this->documents->findActiveReferenceForUpdate(
                            $decisionDocumentId,
                            $this->currentSupplierId($request),
                            $this->documentViewer($request),
                        );
                        if ($document === null) {
                            throw new \InvalidArgumentException(
                                'Ověřené rozhodnutí není dostupné.',
                            );
                        }
                        $decisionDocument = new EnforcementDecisionDocumentReference(
                            $document['id'],
                            $document['sha256'],
                        );
                    }
                    $case = $this->repository->transition(
                        $this->currentSupplierId($request),
                        (int) $args['id'],
                        $command,
                        $this->positiveInt($body['row_version'] ?? null, 'row_version'),
                        $reason,
                        $decisionDocument,
                        $this->userId($request),
                        $this->lifecycle,
                    );
                    $this->audit(
                        $request,
                        'payroll.enforcement.case.transitioned',
                        $case,
                        'payroll_enforcement_case',
                        [
                            'command' => $args['command'],
                            'decision_document_id' =>
                                $body['decision_document_id'] ?? null,
                            'reason_hash' => isset($body['reason'])
                                ? hash(
                                    'sha256',
                                    PayrollTimeValue::string($body['reason'], 'reason'),
                                )
                                : null,
                        ],
                    );
                    return $case;
                },
            ), 'enforcement_case');
        } catch (\ValueError|\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollEnforcementConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        } catch (\DomainException $e) {
            return Json::error($response, 'invalid_case_transition', $e->getMessage(), 409);
        }
        return Json::ok($response, ['case' => $this->presentCase($request, $case)]);
    }

    /** @param array{employeeId:string,period:string} $args */
    public function monthEvidence(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        if (($error = $this->authorizeInsolvency(
            $request,
            $response,
            AccessLevel::READ,
        )) !== null) {
            return $error;
        }
        try {
            $evidence = $this->repository->monthEvidence(
                $this->currentSupplierId($request),
                (int) $args['employeeId'],
                $args['period'],
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        return Json::ok($response, ['evidence' => $evidence]);
    }

    /** @param array{employeeId:string} $args */
    public function dependants(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        if (($error = $this->authorizeInsolvency(
            $request,
            $response,
            AccessLevel::READ,
        )) !== null) {
            return $error;
        }
        try {
            $dependants = $this->repository->dependantsForEmployee(
                $this->currentSupplierId($request),
                (int) $args['employeeId'],
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        return Json::ok($response, ['dependants' => $dependants]);
    }

    /** @param array{employeeId:string,period:string} $args */
    public function saveMonthEvidence(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        if (($error = $this->authorizeInsolvency(
            $request,
            $response,
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            if (($body['insolvency_mode'] ?? null) === 'approved_standard'
                && !$this->requirePermission(
                    $request,
                    $response,
                    'documents',
                    AccessLevel::READ,
                    $error,
                )
            ) {
                return $error ?? Json::error(
                    $response,
                    'forbidden',
                    'Pro výběr rozhodnutí oddlužení nemáš oprávnění k dokumentům.',
                    403,
                );
            }
            $evidence = $this->transactional(
                function () use ($request, $args, $body): array {
                    $evidence = $this->repository->saveMonthEvidence(
                        $this->currentSupplierId($request),
                        (int) $args['employeeId'],
                        $args['period'],
                        $body,
                        $this->userId($request),
                        $this->optionalPositiveInt($body['row_version'] ?? null, 'row_version'),
                    );
                    $this->audit(
                        $request,
                        'payroll.enforcement.month_evidence.updated',
                        $evidence,
                        'payroll_enforcement_month_evidence',
                    );
                    return $evidence;
                },
            );
        } catch (\ValueError|\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollEnforcementConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        } catch (\DomainException $e) {
            return Json::error(
                $response,
                'invalid_insolvency_evidence',
                $e->getMessage(),
                409,
            );
        }
        return Json::ok($response, ['evidence' => $evidence]);
    }

    /** @param array{employeeId:string} $args */
    public function addDependant(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        if (($error = $this->authorizeInsolvency(
            $request,
            $response,
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        try {
            $dependant = $this->transactional(
                function () use ($request, $args): array {
                    $dependant = $this->repository->addDependant(
                        $this->currentSupplierId($request),
                        (int) $args['employeeId'],
                        $this->input($request),
                    );
                    $this->audit(
                        $request,
                        'payroll.enforcement.dependant.created',
                        $dependant,
                        'payroll_enforcement_dependant',
                    );
                    return $dependant;
                },
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        return Json::ok($response, ['dependant' => $dependant], 201);
    }

    private function authorizeInsolvency(
        Request $request,
        Response $response,
        AccessLevel $level,
    ): ?Response {
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.insolvency',
            $level,
            $error,
        )) {
            return $error ?? Json::error(
                $response,
                'forbidden',
                'Pro tuto akci nemáš oprávnění.',
                403,
            );
        }
        return null;
    }

    private function claimMutationBlocked(
        Response $response,
        PayrollEnforcementClaimMutationBlockedException $exception,
    ): Response {
        return Json::error(
            $response,
            'enforcement_claim_change_blocked',
            $exception->getMessage(),
            409,
            ['blocker' => $exception->blockerCode],
        );
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level,
    ): ?Response {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
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
            'payroll.enforcement',
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

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $body = $request->getParsedBody();
        return $body === null ? [] : PayrollTimeValue::row($body, 'request_body');
    }

    private function positiveInt(mixed $value, string $field): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!is_int($result)) {
            throw new \InvalidArgumentException("Pole {$field} musí být kladné celé číslo.");
        }
        return $result;
    }

    private function optionalPositiveInt(mixed $value, string $field): ?int
    {
        return $value === null || $value === ''
            ? null
            : $this->positiveInt($value, $field);
    }

    private function requiredBool(mixed $value, string $field): bool
    {
        return PayrollTimeValue::bool($value, $field);
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return trim(PayrollTimeValue::string($value, 'optional_string'));
    }

    private function documentViewer(Request $request): DocumentViewerContext
    {
        return DocumentViewerContext::fromAuthorization(
            RequestAuthorization::isSuperadmin($request),
            $this->userId($request),
        );
    }

    /**
     * @param array<string,mixed> $case
     * @return array<string,mixed>
     */
    private function presentCase(Request $request, array $case): array
    {
        $events = $case['events'] ?? null;
        if (!is_array($events)) {
            return $case;
        }
        $canReadDocuments = RequestAuthorization::allows(
            $request,
            'documents',
            AccessLevel::READ,
        );
        $supplierId = $this->currentSupplierId($request);
        $viewer = $this->documentViewer($request);
        foreach ($events as $index => $event) {
            if (!is_array($event)) {
                continue;
            }
            $documentId = isset($event['decision_document_id'])
                ? PayrollTimeValue::int(
                    $event['decision_document_id'],
                    'decision_document_id',
                )
                : 0;
            if (
                !$canReadDocuments
                || $documentId <= 0
                || $this->documents->find($documentId, $supplierId, $viewer) === null
            ) {
                $event['decision_document_id'] = null;
            }
            unset($event['decision_case_document_id']);
            $events[$index] = $event;
        }
        $case['events'] = $events;
        return $case;
    }

    private function transactional(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $nested = $pdo->inTransaction();
        if ($nested) {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        } else {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($nested) {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } else {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($nested && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $case
     * @param array<string,mixed> $context
     */
    private function audit(
        Request $request,
        string $action,
        array $case,
        string $entityType = 'payroll_enforcement_case',
        array $context = [],
    ): void
    {
        $id = PayrollTimeValue::int($case['id'] ?? null, 'id');
        $payload = ['row_version' => PayrollTimeValue::int(
            $case['row_version'] ?? null,
            'row_version',
        )];
        if (isset($case['status'])) {
            $payload['status'] = PayrollTimeValue::string($case['status'], 'status');
        }
        $payload['snapshot_hash'] = hash(
            'sha256',
            CanonicalJson::encode($case),
        );
        $payload = [...$payload, ...$this->logger->redact($context)];
        $this->logger->log(
            $action,
            $this->userId($request),
            $entityType,
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest(
                PayrollTimeValue::row($request->getServerParams(), 'server_params'),
            ),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}

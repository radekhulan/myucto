<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\Payroll\PayrollEnforcementFactsRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\DocumentViewerResolver;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Narrow MZ-14 legal-facts API; calculation and payment targets stay elsewhere. */
final class PayrollEnforcementFactsAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollEnforcementFactsRepository $facts,
        private readonly DocumentRepository $documents,
        private readonly PayrollModuleAccess $moduleAccess,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    /** @param array{id:string} $args */
    public function parties(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        try {
            return Json::ok($response, [
                'items' => $this->facts->parties(
                    $this->currentSupplierId($request),
                    $this->id($args['id'] ?? null, 'case_id'),
                ),
            ]);
        } catch (\OutOfBoundsException) {
            return Json::error($response, 'not_found', 'Exekuční případ nebyl nalezen.', 404);
        }
    }

    /** @param array{id:string} $args */
    public function appendParty(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        if (!$this->requirePermission($request, $response, 'documents', AccessLevel::READ, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        try {
            $body = $this->input($request);
            $caseId = $this->id($args['id'] ?? null, 'case_id');
            $role = $this->partyRole($body['party_role'] ?? null);
            $effectiveFrom = $this->date($body['effective_from'] ?? null, 'effective_from');
            $name = $this->text($body['party_name'] ?? null, 'party_name', 255);
            $reference = $this->optionalText($body['party_reference'] ?? null, 'party_reference', 128);
            $documentId = $this->id($body['source_document_id'] ?? null, 'source_document_id');
            $actorUserId = $this->actor($request);
            $party = $this->transactional(function () use ($request, $caseId, $role, $effectiveFrom, $name, $reference, $documentId, $actorUserId): array {
                $document = $this->sourceDocument($request, $documentId);
                $party = $this->facts->appendParty(
                    $this->currentSupplierId($request), $caseId, $role, $effectiveFrom,
                    $name, $reference, $document['id'], $document['sha256'], $actorUserId,
                );
                $this->audit($request, 'payroll.enforcement.case_party_recorded', 'payroll_enforcement_case_party', (int) $party['id'], [
                    'case_id' => $caseId,
                    'party_role' => $role,
                    'revision_no' => (int) $party['revision_no'],
                    'source_document_id' => $document['id'],
                ]);
                return $party;
            });
            return Json::ok($response, ['party' => $party], 201);
        } catch (\OutOfBoundsException) {
            return Json::error($response, 'not_found', 'Exekuční případ nebo důkazní dokument nebyl nalezen.', 404);
        } catch (\InvalidArgumentException) {
            return Json::error($response, 'validation_failed', 'Právní strana případu není platná.', 422);
        }
    }

    /** @param array{id:string} $args */
    public function recipientInstructions(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        try {
            return Json::ok($response, ['items' => $this->facts->recipientInstructions(
                $this->currentSupplierId($request),
                $this->id($args['id'] ?? null, 'case_id'),
            )]);
        } catch (\OutOfBoundsException) {
            return Json::error($response, 'not_found', 'Exekuční případ nebyl nalezen.', 404);
        }
    }

    /** @param array{id:string} $args */
    public function appendRecipientInstruction(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        if (!$this->requirePermission($request, $response, 'documents', AccessLevel::READ, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        try {
            $body = $this->input($request);
            $caseId = $this->id($args['id'] ?? null, 'case_id');
            $documentId = $this->id($body['source_document_id'] ?? null, 'source_document_id');
            $reason = $this->optionalText($body['change_reason'] ?? null, 'change_reason', 500);
            $instruction = $this->transactional(function () use ($request, $body, $caseId, $documentId, $reason): array {
                $document = $this->sourceDocument($request, $documentId);
                $instruction = $this->facts->appendRecipientInstruction(
                    $this->currentSupplierId($request),
                    $caseId,
                    $this->date($body['effective_from'] ?? null, 'effective_from'),
                    $this->id($body['recipient_party_id'] ?? null, 'recipient_party_id'),
                    $this->id($body['payment_account_id'] ?? null, 'payment_account_id'),
                    $document['id'],
                    $document['sha256'],
                    $reason,
                    $this->actor($request),
                );
                $this->audit($request, 'payroll.enforcement.recipient_instruction_recorded', 'payroll_enforcement_instruction', (int) $instruction['id'], [
                    'case_id' => $caseId,
                    'revision_no' => (int) $instruction['revision_no'],
                    'recipient_party_id' => (int) $instruction['recipient_party_id'],
                    'payment_account_id' => (int) $instruction['payment_account_id'],
                    'source_document_id' => $document['id'],
                ]);
                return $instruction;
            });
            return Json::ok($response, ['instruction' => $instruction], 201);
        } catch (\OutOfBoundsException) {
            return Json::error($response, 'not_found', 'Exekuční případ nebo důkazní dokument nebyl nalezen.', 404);
        } catch (\InvalidArgumentException|\PDOException) {
            return Json::error($response, 'validation_failed', 'Instrukce příjemce není platná.', 422);
        }
    }

    /** @param array{id:string,claimId:string} $args */
    public function breakdowns(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        try {
            return Json::ok($response, ['items' => $this->facts->breakdowns(
                $this->currentSupplierId($request),
                $this->id($args['id'] ?? null, 'case_id'),
                $this->id($args['claimId'] ?? null, 'claim_id'),
            )]);
        } catch (\OutOfBoundsException) {
            return Json::error($response, 'not_found', 'Pohledávka exekučního případu nebyla nalezena.', 404);
        }
    }

    /** @param array{id:string,claimId:string} $args */
    public function appendBreakdown(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        if (!$this->requirePermission($request, $response, 'documents', AccessLevel::READ, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        try {
            $body = $this->input($request);
            $caseId = $this->id($args['id'] ?? null, 'case_id');
            $claimId = $this->id($args['claimId'] ?? null, 'claim_id');
            $documentId = $this->id($body['source_document_id'] ?? null, 'source_document_id');
            $reason = $this->optionalText($body['change_reason'] ?? null, 'change_reason', 500);
            $actorUserId = $this->actor($request);
            $breakdown = $this->transactional(function () use ($request, $caseId, $claimId, $body, $documentId, $reason, $actorUserId): array {
                $document = $this->sourceDocument($request, $documentId);
                $breakdown = $this->facts->appendBreakdown(
                    $this->currentSupplierId($request), $caseId, $claimId,
                    $this->money($body['principal_minor_units'] ?? null, 'principal_minor_units'),
                    $this->money($body['interest_minor_units'] ?? null, 'interest_minor_units'),
                    $this->money($body['costs_minor_units'] ?? null, 'costs_minor_units'),
                    $this->money($body['maintenance_minor_units'] ?? null, 'maintenance_minor_units'),
                    $document['id'], $document['sha256'], $reason, $actorUserId,
                );
                $this->audit($request, 'payroll.enforcement.claim_breakdown_recorded', 'payroll_enforcement_claim_breakdown', (int) $breakdown['id'], [
                    'case_id' => $caseId,
                    'claim_id' => $claimId,
                    'revision_no' => (int) $breakdown['revision_no'],
                    'total_minor_units' => (int) $breakdown['total_minor_units'],
                    'source_document_id' => $document['id'],
                ]);
                return $breakdown;
            });
            return Json::ok($response, ['breakdown' => $breakdown], 201);
        } catch (\OutOfBoundsException) {
            return Json::error($response, 'not_found', 'Pohledávka exekučního případu nebo důkazní dokument nebyl nalezen.', 404);
        } catch (\DomainException) {
            return Json::error($response, 'enforcement_claim_change_blocked', 'Použitou pohledávku nelze tiše překlasifikovat.', 409);
        } catch (\InvalidArgumentException) {
            return Json::error($response, 'validation_failed', 'Rozpad pohledávky není platný.', 422);
        }
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return Json::error($response, 'session_required', 'Tento endpoint je dostupný pouze z přihlášené webové session.', 403);
        }
        if (!$this->requirePermission($request, $response, 'payroll.enforcement', $level, $error)
            || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || array_is_list($body)) {
            throw new \InvalidArgumentException('Požadavek musí být objekt.');
        }
        return $body;
    }

    /** @return array{id:int,sha256:string} */
    private function sourceDocument(Request $request, int $documentId): array
    {
        $document = $this->documents->findActiveReferenceForUpdate(
            $documentId,
            $this->currentSupplierId($request),
            DocumentViewerResolver::fromRequest($request),
        );
        if ($document === null) {
            throw new \OutOfBoundsException('Důkazní dokument není dostupný.');
        }
        return $document;
    }

    private function transactional(\Closure $callback): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        $owns ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT payroll_enforcement_facts');
        try {
            $result = $callback();
            $owns ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT payroll_enforcement_facts');
            return $result;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                if ($owns) {
                    $pdo->rollBack();
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT payroll_enforcement_facts');
                    $pdo->exec('RELEASE SAVEPOINT payroll_enforcement_facts');
                }
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $payload */
    private function audit(Request $request, string $action, string $entity, int $id, array $payload): void
    {
        $this->activity->log($action, $this->actor($request), $entity, $id, $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }

    private function actor(Request $request): int
    {
        $id = $this->userId($request);
        if ($id === null) {
            throw new \InvalidArgumentException('Chybí přihlášený uživatel.');
        }
        return $id;
    }

    private function id(mixed $value, string $name): int
    {
        return $this->money($value, $name, false);
    }

    private function money(mixed $value, string $name, bool $allowZero = true): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \InvalidArgumentException($name);
        }
        $result = (int) $value;
        if ($result < 0 || (!$allowZero && $result === 0)) {
            throw new \InvalidArgumentException($name);
        }
        return $result;
    }

    private function date(mixed $value, string $name): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new \InvalidArgumentException($name);
        }
        return $value;
    }

    private function text(mixed $value, string $name, int $limit): string
    {
        if (!is_string($value) || ($value = trim($value)) === '' || mb_strlen($value) > $limit) {
            throw new \InvalidArgumentException($name);
        }
        return $value;
    }

    private function optionalText(mixed $value, string $name, int $limit): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->text($value, $name, $limit);
    }

    private function partyRole(mixed $value): string
    {
        $role = $this->text($value, 'party_role', 32);
        if (!in_array($role, ['court', 'executor', 'beneficiary'], true)) {
            throw new \InvalidArgumentException('party_role');
        }
        return $role;
    }
}

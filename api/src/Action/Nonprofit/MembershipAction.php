<?php

declare(strict_types=1);

namespace MyInvoice\Action\Nonprofit;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBalanceRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Nonprofit\Membership\MembershipChargeService;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipMemberService;
use MyInvoice\Service\Nonprofit\Membership\MembershipReconciliationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MembershipAction
{
    use AccountingActionSupport;

    private readonly MembershipRepository $members;
    private readonly MembershipMemberService $memberService;
    private readonly MembershipChargeService $chargeService;
    private readonly MembershipReconciliationService $reconciliation;
    private readonly TenantReferenceGuard $tenantRefs;

    public function __construct(Connection $db, PostingService $posting)
    {
        $this->tenantRefs = new TenantReferenceGuard($db);
        $this->members = new MembershipRepository($db);
        $charges = new MembershipChargeRepository($db);
        $this->memberService = new MembershipMemberService($db, $this->members);
        $this->chargeService = new MembershipChargeService($db, $this->members, $charges, $posting);
        $this->reconciliation = new MembershipReconciliationService(new MembershipBalanceRepository($db));
    }

    public function members(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, false, function (int $supplier) use ($request): array {
            $query = $request->getQueryParams();
            $after = $this->queryInteger($query['after_id'] ?? 0, 'after_id', 0);
            $limit = $this->queryInteger($query['limit'] ?? 100, 'limit', 1, 100);
            $items = $this->members->page($supplier, $after, $limit);
            return ['items' => $items, 'next_after_id' => count($items) === $limit ? $items[array_key_last($items)]['id'] : null];
        });
    }

    public function member(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, function (int $supplier) use ($request, $args): array {
            $member = $this->members->member($supplier, (int) $args['id'])
                ?? throw new MembershipException('nonprofit_membership_member_not_found', 'Člen nebyl nalezen v této organizaci.', 404);
            $query = $request->getQueryParams();
            $after = $this->queryInteger($query['after_claim_id'] ?? 0, 'after_claim_id', 0);
            $limit = $this->queryInteger($query['limit'] ?? 100, 'limit', 1, 100);
            return ['member' => $member, 'history' => $this->members->history($supplier, $member['id']),
                'balance' => $this->reconciliation->memberBalance($supplier, $member['id'], $after, $limit)];
        });
    }

    public function claim(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, fn (int $supplier): array => $this->reconciliation->claimSettlement($supplier, (int) $args['id']));
    }

    public function reconciliation(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, fn (int $supplier): array => $this->reconciliation->periodReconciliation($supplier, (int) $args['id']));
    }

    public function createMember(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, true, function (int $supplier) use ($request): array {
            $body = $this->body($request, ['client_id', 'member_number']);
            if (!is_int($body['client_id']) || !is_string($body['member_number'])) throw new MembershipException('nonprofit_membership_invalid', 'Člen vyžaduje klienta a členské číslo.');
            $badRefs = $this->tenantRefs->violations($supplier, $body, ['client_id']);
            if ($badRefs !== []) throw new MembershipException('invalid_reference', TenantReferenceGuard::message($badRefs), 400);
            return $this->memberService->create($supplier, $body['client_id'], $body['member_number'], $this->actor($request));
        }, 201);
    }

    public function appendRevision(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, true, function (int $supplier) use ($request, $args): array {
            $body = $this->body($request, ['status', 'effective_from', 'legal_basis', 'expected_revision', 'idempotency_key']);
            return $this->memberService->appendRevision($supplier, (int) $args['id'], $body, $this->actor($request));
        }, 201);
    }

    public function preview(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, true, function (int $supplier) use ($request): array {
            $body = $this->body($request, ['operation', 'request']);
            if (!is_array($body['request'])) throw new MembershipException('nonprofit_membership_invalid', 'Požadavek operace musí být strukturovaný.');
            return match ($body['operation']) {
                'issue' => $this->chargeService->previewIssue($supplier, $body['request']),
                'reverse' => $this->chargeService->previewReverse($supplier, $body['request']),
                default => throw new MembershipException('nonprofit_membership_invalid', 'Neplatný druh členské operace.'),
            };
        });
    }

    public function confirm(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, true, function (int $supplier) use ($request): array {
            $body = $this->body($request, ['operation', 'request', 'expected_case_version', 'preview_hash', 'idempotency_key']);
            if (!is_array($body['request']) || !is_int($body['expected_case_version']) || !is_string($body['preview_hash']) || !is_string($body['idempotency_key'])) {
                throw new MembershipException('nonprofit_membership_invalid', 'Potvrzení členské operace nemá platný tvar.');
            }
            return match ($body['operation']) {
                'issue' => $this->chargeService->confirmIssue($supplier, $body['request'], $body['expected_case_version'], $body['preview_hash'], $body['idempotency_key'], $this->actor($request)),
                'reverse' => $this->chargeService->confirmReverse($supplier, $body['request'], $body['expected_case_version'], $body['preview_hash'], $body['idempotency_key'], $this->actor($request)),
                default => throw new MembershipException('nonprofit_membership_invalid', 'Neplatný druh členské operace.'),
            };
        });
    }

    private function handle(Request $request, Response $response, bool $write, callable $work, int $status = 200): Response
    {
        if (!$write && !RequestAuthorization::isSessionAuth($request)) {
            return Json::error($response, 'token_endpoint_forbidden', 'API token má přístup pouze k veřejnému API (/api/v1). Tento endpoint je dostupný jen z webového rozhraní.', 403);
        }
        if ($write && !RequestAuthorization::isSessionAuth($request)) return Json::sessionRequired($response, 'Změna členské evidence vyžaduje přihlášenou relaci.');
        $allowed = $write
            ? $this->requirePermission($request, $response, 'accounting.journal.post', AccessLevel::WRITE, $error)
            : $this->requirePermission($request, $response, 'accounting', AccessLevel::READ, $error);
        if (!$allowed) return $error;
        try {
            return Json::ok($response, $work($this->currentSupplierId($request)), $status);
        } catch (MembershipException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        } catch (PostingException $e) {
            return $this->mapPostingError($response, $e);
        }
    }

    private function body(Request $request, array $fields): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || array_diff(array_keys($body), $fields) !== [] || array_diff($fields, array_keys($body)) !== []) {
            throw new MembershipException('nonprofit_membership_invalid', 'Požadavek musí obsahovat právě podporovaná pole.');
        }
        return $body;
    }

    private function actor(Request $request): int
    {
        return $this->userId($request) ?? throw new MembershipException('nonprofit_membership_actor_required', 'Změna členské evidence vyžaduje autora.', 403);
    }

    private function queryInteger(mixed $value, string $field, int $minimum, int $maximum = PHP_INT_MAX): int
    {
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) $value = (int) $value;
        if (!is_int($value) || $value < $minimum || $value > $maximum) throw new MembershipException('nonprofit_membership_invalid', 'Neplatný parametr ' . $field . '.');
        return $value;
    }
}

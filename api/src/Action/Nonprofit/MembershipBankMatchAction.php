<?php

declare(strict_types=1);

namespace MyInvoice\Action\Nonprofit;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBankMatchRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Nonprofit\Membership\MembershipBankMatchService;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipListFilter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MembershipBankMatchAction
{
    use AccountingActionSupport;

    public function __construct(private readonly MembershipBankMatchService $service, private readonly IpMatcher $ipMatcher) {}

    public function candidates(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, false, function () use ($request): array {
            $q = $request->getQueryParams();
            $search = MembershipListFilter::search($q['search'] ?? '');
            if ($search === null) {
                throw new MembershipException('nonprofit_membership_invalid', 'Neplatné hledání.', 422);
            }
            $cursor = $q['cursor'] ?? '0';
            if (!is_string($cursor) || preg_match(MembershipBankMatchRepository::CURSOR_PATTERN, $cursor) !== 1) {
                throw new MembershipException('nonprofit_membership_invalid', 'Neplatná pozice stránkování.', 422);
            }
            return $this->service->candidates($this->currentSupplierId($request),
                $this->integer($q['transaction_id'] ?? null, 1), $cursor,
                $this->integer($q['limit'] ?? 50, 1, 50), $search);
        });
    }

    public function match(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, true, function () use ($request): array {
            $body = $request->getParsedBody();
            if (!is_array($body) || count($body) !== 2 || !is_int($body['transaction_id'] ?? null)
                || $body['transaction_id'] <= 0 || !is_array($body['claim_ids'] ?? null)) {
                throw new MembershipException('nonprofit_membership_invalid', 'Zadejte transaction_id a seznam claim_ids.', 422);
            }
            $actor = $this->userId($request) ?? throw new MembershipException('nonprofit_membership_actor_required', 'Úhrada vyžaduje autora.', 403);
            return $this->service->match($this->currentSupplierId($request), $body['transaction_id'], $body['claim_ids'], $actor,
                $this->auditMeta($request),
                RequestAuthorization::allows($request, 'bank.post', AccessLevel::WRITE)
                    && RequestAuthorization::allows($request, 'accounting', AccessLevel::WRITE));
        });
    }

    private function handle(Request $request, Response $response, bool $write, callable $work): Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response, 'Párování členských příspěvků vyžaduje přihlášenou relaci.');
        }
        if (!RequestAuthorization::canMatchMembershipBank($request, $write)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáte oprávnění.', 403);
        }
        try {
            return Json::ok($response, $work());
        } catch (MembershipException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        } catch (OtherItemException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (PostingException $e) {
            return $this->mapPostingError($response, $e);
        } catch (DimensionException $e) {
            // Přerazítkování úhrady v uzavřeném období nebo se zmrazenými dimenzemi (DimensionPeriodGuard).
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    private function integer(mixed $value, int $min, int $max = PHP_INT_MAX): int
    {
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1 && strlen($value) < 19) $value = (int) $value;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new MembershipException('nonprofit_membership_invalid', 'Neplatný parametr bankovního párování.', 422);
        }
        return $value;
    }
}

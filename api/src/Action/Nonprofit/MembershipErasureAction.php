<?php

declare(strict_types=1);

namespace MyInvoice\Action\Nonprofit;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\Nonprofit\Membership\MembershipErasureRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Nonprofit\Membership\MembershipErasureService;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Výmaz osobních údajů bývalých členů (#321, krok 10). Interní routy jen pro přihlášenou relaci
 * s právem accounting.membership_erasure; veřejné API je nemá.
 */
final class MembershipErasureAction
{
    use AccountingActionSupport;

    public const PERMISSION = 'accounting.membership_erasure';

    public function __construct(
        private readonly MembershipErasureService $erasure,
        private readonly MembershipErasureRepository $erasures,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function assessment(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, false, function (int $supplier) use ($request): array {
            $query = $request->getQueryParams();
            if (array_diff(array_keys($query), ['after_id', 'limit']) !== []) $this->invalid();
            return $this->erasure->assessment($supplier, $this->integer($query['after_id'] ?? 0, 0), $this->integer($query['limit'] ?? 100, 1, 500));
        });
    }

    public function proposals(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, false, fn (int $supplier): array => ['items' => $this->erasures->proposals($supplier)]);
    }

    public function proposal(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, function (int $supplier) use ($request, $args): array {
            $query = $request->getQueryParams();
            if (array_diff(array_keys($query), ['after_member_id']) !== []) $this->invalid();
            $proposal = $this->erasures->proposal($supplier, (int) $args['id'])
                ?? throw new MembershipException('nonprofit_membership_erasure_not_found', 'Návrh výmazu nebyl nalezen.', 404);
            $items = $this->erasures->items($supplier, $proposal['id'], $this->integer($query['after_member_id'] ?? 0, 0), 101);
            $more = count($items) > 100;
            if ($more) array_pop($items);
            return ['proposal' => $proposal, 'items' => $items, 'next_after_member_id' => $more ? $items[array_key_last($items)]['member_id'] : null,
                'confirmation' => MembershipErasureService::EXECUTE_CONFIRMATION, 'cooling_off_hours' => MembershipErasureService::SOLO_COOLING_OFF_HOURS];
        });
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, true, function (int $supplier, int $actor, array $origin) use ($request): array {
            $body = $this->body($request, ['note'], ['note']);
            if (array_key_exists('note', $body) && $body['note'] !== null && !is_string($body['note'])) $this->invalid();
            $note = $body['note'] ?? null;
            return ['proposal' => $this->erasure->create($supplier, $actor, $note === '' ? null : $note, ...$origin)
                ?? throw new MembershipException('nonprofit_membership_erasure_nothing', 'Není koho navrhnout: všem bývalým členům ještě běží lhůta, nebo je drží neuhrazený předpis, zadržení či rozpracovaná dávka.', 409)];
        }, 201);
    }

    public function approve(Request $request, Response $response, array $args): Response
    {
        return $this->transition($request, $response, fn (int $supplier, int $actor, array $origin): array => $this->erasure->approve($supplier, (int) $args['id'], $actor, ...$origin));
    }

    public function revoke(Request $request, Response $response, array $args): Response
    {
        return $this->transition($request, $response, fn (int $supplier, int $actor, array $origin): array => $this->erasure->revoke($supplier, (int) $args['id'], $actor, ...$origin));
    }

    public function reject(Request $request, Response $response, array $args): Response
    {
        return $this->transition($request, $response, fn (int $supplier, int $actor, array $origin): array => $this->erasure->reject($supplier, (int) $args['id'], $actor, ...$origin));
    }

    public function execute(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, true, function (int $supplier, int $actor, array $origin) use ($request, $args): array {
            $body = $this->body($request, ['confirmation']);
            if (!is_string($body['confirmation'])) $this->invalid();
            return $this->erasure->execute($supplier, (int) $args['id'], $actor, $body['confirmation'], ...$origin);
        });
    }

    private function transition(Request $request, Response $response, callable $work): Response
    {
        return $this->handle($request, $response, true, function (int $supplier, int $actor, array $origin) use ($request, $work): array {
            $this->body($request, []);
            return ['proposal' => $work($supplier, $actor, $origin)];
        });
    }

    private function handle(Request $request, Response $response, bool $write, callable $work, int $status = 200): Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return $write
                ? Json::sessionRequired($response, 'Výmaz osobních údajů vyžaduje přihlášenou relaci.')
                : Json::error($response, 'token_endpoint_forbidden', 'API token má přístup pouze k veřejnému API (/api/v1). Tento endpoint je dostupný jen z webového rozhraní.', 403);
        }
        if (!$this->requirePermission($request, $response, self::PERMISSION, $write ? AccessLevel::WRITE : AccessLevel::READ, $error)) return $error;
        try {
            $supplier = $this->currentSupplierId($request);
            if (!$write) return Json::ok($response, $work($supplier), $status);
            $actor = $this->userId($request) ?? throw new MembershipException('nonprofit_membership_actor_required', 'Výmaz osobních údajů vyžaduje autora.', 403);
            $origin = [$this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent')];
            return Json::ok($response, $work($supplier, $actor, $origin), $status);
        } catch (MembershipException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }
    }

    /** @param list<string> $fields @param list<string> $optional */
    private function body(Request $request, array $fields, array $optional = []): array
    {
        $body = $request->getParsedBody() ?? [];
        if (!is_array($body) || array_diff(array_keys($body), $fields) !== [] || array_diff(array_diff($fields, $optional), array_keys($body)) !== []) $this->invalid();
        return $body;
    }

    private function integer(mixed $value, int $minimum, int $maximum = PHP_INT_MAX): int
    {
        if (is_string($value) && preg_match('/^[0-9]{1,18}$/D', $value) === 1) $value = (int) $value;
        if (!is_int($value) || $value < $minimum || $value > $maximum) $this->invalid();
        return $value;
    }

    private function invalid(): never
    {
        throw new MembershipException('nonprofit_membership_invalid', 'Požadavek musí obsahovat právě podporovaná pole s platnými hodnotami.');
    }
}

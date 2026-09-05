<?php

declare(strict_types=1);

namespace MyInvoice\Action\Codebook;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Oss\OssRateCodebook;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Správa číselníku sazeb DPH členských států pro OSS (OSS-9):
 *   GET    /api/codebooks/oss-member-state-rates            — čtení (i vyřazené řádky)
 *   POST   /api/codebooks/oss-member-state-rates            — vlastní sazba (`is_custom = 1`)
 *   PUT    /api/codebooks/oss-member-state-rates/{id}       — oprava / zkrácení platnosti / vyřazení
 *   DELETE /api/codebooks/oss-member-state-rates/{id}       — smazání vlastní sazby
 *
 * ── Kdo smí zapisovat ───────────────────────────────────────────────────────────────
 * Tabulka je GLOBÁLNÍ (nemá `supplier_id`) — jeden zápis platí pro všechny firmy v
 * instanci, takže tenantní scope tu není co řešit; je potřeba ho naopak nahradit něčím
 * silnějším než běžné `settings.company.write`, které má i účetní jedné z firem.
 *
 * Druhý důvod je věcný: tenhle číselník je AUTORITA, proti které se ověřuje sazba na
 * dokladu, a {@see \MyInvoice\Service\Oss\OssItemDeriver} podle něj rozhoduje, jestli řádek
 * patří do českého přiznání, nebo do OSS. Právě proto se od `vat_rates` liší tím, že si ho
 * uživatel neupravuje. Zápis proto vyžaduje superadmina; čtení stačí běžné oprávnění
 * k číselníkům, aby účetní viděla, proti čemu se její doklad porovnává.
 *
 * Route permission map pokrývá cestu `/api/codebooks/...` (READ `settings.company`,
 * WRITE `settings.company.write`); {@see self::canWrite()} je nad tím druhá, přísnější
 * brána — superadmin a jen ze session, ne přes API token.
 */
final class OssMemberStateRatesAction
{
    public function __construct(
        private readonly OssRateCodebook $codebook,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'settings.company', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $country = trim((string) ($request->getQueryParams()['country'] ?? ''));

        return Json::ok($response, [
            // Chybějící tabulka je stav instalace, ne prázdný číselník — UI musí umět
            // říct „spusťte migrace" místo „žádné sazby".
            'available'      => $this->codebook->isAvailable(),
            'manageable'     => $this->codebook->isManageable(),
            'can_write'      => $this->canWrite($request),
            'rate_types'     => OssRateCodebook::RATE_TYPES,
            'rates'          => $this->codebook->listAll($country !== '' ? $country : null),
            // Neúplný seed (viz migrace 1319) se jinak projeví jen jako stovky stejných
            // hlášek u importu — tady dostane uživatel jednu srozumitelnou větu na
            // stránce, kde se dá i opravit.
            'coverage_gaps'  => $this->codebook->countriesMissingCurrentRate(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->canWrite($request)) {
            return $this->forbidden($response);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $id = $this->codebook->createCustom([
                'country'      => $body['country']      ?? '',
                'rate_type'    => $body['rate_type']    ?? '',
                'rate_percent' => $body['rate_percent'] ?? null,
                'valid_from'   => $body['valid_from']   ?? null,
                'valid_to'     => $body['valid_to']     ?? null,
                'note'         => $body['note']         ?? null,
            ], $this->userId($request));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'conflict', $e->getMessage(), $this->statusOf($e));
        }

        $this->audit($request, 'oss_member_state_rate.created', $id, $body);

        return Json::ok($response, ['id' => $id, 'rates' => $this->codebook->listAll()], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->canWrite($request)) {
            return $this->forbidden($response);
        }
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        // Předává se jen to, co klient skutečně poslal — prázdný klíč jinak znamená
        // „vynuluj" a smazal by uživateli překryv, o kterém vůbec nemluvil.
        $patch = array_intersect_key($body, array_flip([
            'country', 'rate_type', 'rate_percent', 'valid_from', 'valid_to', 'note',
            'valid_to_override', 'disabled',
        ]));
        if (array_key_exists('disabled', $patch)) {
            $patch['disabled'] = (bool) $patch['disabled'];
        }

        try {
            $this->codebook->update($id, $patch, $this->userId($request));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            return Json::error($response, $e->getCode() === 404 ? 'not_found' : 'conflict',
                $e->getMessage(), $this->statusOf($e));
        }

        $this->audit($request, 'oss_member_state_rate.updated', $id, $patch);

        return Json::ok($response, ['id' => $id, 'rates' => $this->codebook->listAll()]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->canWrite($request)) {
            return $this->forbidden($response);
        }
        $id = (int) ($args['id'] ?? 0);
        try {
            $this->codebook->delete($id);
        } catch (\RuntimeException $e) {
            return Json::error($response, $e->getCode() === 404 ? 'not_found' : 'conflict',
                $e->getMessage(), $this->statusOf($e));
        }

        $this->audit($request, 'oss_member_state_rate.deleted', $id, []);

        return Json::ok($response, ['ok' => true, 'rates' => $this->codebook->listAll()]);
    }

    /**
     * Zápis smí jen superadmin z webového rozhraní. Bearer token je vyloučený zvlášť:
     * `/api/codebooks/*` je ve veřejném subsetu ({@see \MyInvoice\Middleware\ApiScopeMiddleware}),
     * takže by token se `read_write` jinak směl přepsat autoritu, proti které se ověřují
     * doklady všech firem v instanci.
     */
    private function canWrite(Request $request): bool
    {
        return RequestAuthorization::isSuperadmin($request)
            && !RequestAuthorization::isBearerAuth($request);
    }

    private function forbidden(Response $response): Response
    {
        return Json::error(
            $response,
            'forbidden',
            'Číselník sazeb členských států je globální — měnit ho smí jen správce instance '
                . 'z webového rozhraní.',
            403,
        );
    }

    private function statusOf(\RuntimeException $e): int
    {
        $code = $e->getCode();
        return in_array($code, [400, 404, 409], true) ? (int) $code : 409;
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $id = (int) ($user['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** @param array<string,mixed> $payload */
    private function audit(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'oss_member_state_rate',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );
    }
}

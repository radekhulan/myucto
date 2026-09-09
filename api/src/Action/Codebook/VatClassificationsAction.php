<?php

declare(strict_types=1);

namespace MyInvoice\Action\Codebook;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\VatClassificationRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\VatClassificationLineGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * VAT classification codes CRUD:
 *   GET    /api/vat-classifications?direction=sale|purchase|both
 *   POST   /api/vat-classifications     — custom kód pro tenant
 *   PUT    /api/vat-classifications/{id} — jen tenant kódy (globální seed nelze)
 *   DELETE /api/vat-classifications/{id} — soft archived
 */
final class VatClassificationsAction
{
    public function __construct(
        private readonly VatClassificationRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $direction = in_array($q['direction'] ?? '', ['sale', 'purchase', 'both'], true)
            ? $q['direction'] : null;
        $includeArchived = !empty($q['include_archived']);
        return Json::ok($response, $this->repo->listForTenant($supplierId, $direction, $includeArchived));
    }

    /**
     * Řádky přiznání, které smí klasifikace nést.
     *
     * UI z nich staví výběr místo volného textu — číslo řádku se do políčka nedá napsat
     * omylem (past „kód se jmenuje jako řádek", nález L-1 auditu VAT klasifikací).
     * Seznam se servíruje ze SSOT `DphPriznaniBuilder::USER_SELECTABLE_LINES`, ne z kopie
     * v JS: jinak by se whitelist validace a nabídka v UI rozešly.
     */
    public function lines(Request $request, Response $response): Response
    {
        return Json::ok($response, ['lines' => DphPriznaniBuilder::USER_SELECTABLE_LINES]);
    }

    public function create(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'settings.company.write', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $err = $this->validate($body);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        try {
            $id = $this->repo->create($supplierId, $body);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                return Json::error($response, 'duplicate_code', "Kód '{$body['code']}' už existuje.", 409);
            }
            return Json::error($response, 'create_failed', $e->getMessage(), 500);
        }
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('vat_classification.created', $userId, 'vat_classification', $id, $body,
            $ip, $request->getHeaderLine('User-Agent'));
        return Json::ok($response, $this->repo->find($id, $supplierId), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'settings.company.write', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        // Update kód neposílá (měnit ho nejde), ale kontrola kolize „kód vs. řádek přiznání"
        // ho potřebuje — jinak by past L-1 hlídala jen zakládání, ne pozdější přenastavení řádku.
        $existing = $this->repo->find($id, $supplierId);
        if ($existing !== null && !array_key_exists('code', $body)) {
            $body['code'] = $existing['code'];
        }
        $err = $this->validate($body, isUpdate: true);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        try {
            $this->repo->update($id, $supplierId, $body);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'cannot_edit_global', $e->getMessage(), 409);
        }
        return Json::ok($response, $this->repo->find($id, $supplierId));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'settings.company.write', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        try {
            $this->repo->delete($id, $supplierId);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'cannot_delete_global', $e->getMessage(), 409);
        }
        return Json::ok($response, ['ok' => true, 'archived' => true]);
    }

    private function validate(array $body, bool $isUpdate = false): ?string
    {
        if (!$isUpdate) {
            $code = trim((string) ($body['code'] ?? ''));
            if ($code === '' || !preg_match('/^[A-Za-z0-9_-]{1,8}$/', $code)) {
                return 'code: povinné, max 8 znaků [A-Za-z0-9_-].';
            }
        }
        $label = trim((string) ($body['label'] ?? ''));
        if ($label === '' || strlen($label) > 150) {
            return 'label povinný, max 150 znaků.';
        }
        if (!in_array($body['direction'] ?? 'both', ['sale', 'purchase', 'both'], true)) {
            return 'direction musí být sale|purchase|both.';
        }
        if (array_key_exists('kh_regime_code', $body)
            && $body['kh_regime_code'] !== null
            && !in_array($body['kh_regime_code'], ['0', '1', '2'], true)) {
            return 'kh_regime_code musí být 0|1|2 nebo null.';
        }
        // Kód předmětu plnění (§ 92b–92f) — jde do KH A.1/B.1. Hodnotový výčet je
        // v EXTERNÍM číselníku MFČR, ne v XSD (to omezuje jen délku na 3 znaky), takže se
        // kontroluje TVAR, ne členství v seznamu. Vlastní seznam kódů by se s číselníkem
        // rozešel a odmítal by legitimní hodnoty — to je horší než žádná kontrola.
        //
        // Číselník ale NENÍ jen číselný: má i kódy s písmenným sufixem ('1a', '3a').
        // Tvarová kontrola na samé číslice je proto odmítala, takže část režimů § 92
        // nešla v číselníku vůbec zavést — kontrola tvaru se změnila ve skrytý výčet.
        if (array_key_exists('kod_pred_pl', $body)
            && $body['kod_pred_pl'] !== null
            && $body['kod_pred_pl'] !== ''
            && !preg_match('/^\d{1,2}[a-z]?$/', (string) $body['kod_pred_pl'])) {
            return 'kod_pred_pl musí být 1–2 číslice s volitelným písmenem (např. 4, 5, 1a) '
                . 'z číselníku MFČR, nebo prázdné.';
        }
        if (array_key_exists('kh_bad_debt', $body)
            && $body['kh_bad_debt'] !== null
            && !in_array($body['kh_bad_debt'], ['N', 'P'], true)) {
            return 'kh_bad_debt musí být N|P nebo null.';
        }
        // Řádek přiznání, který generátor neumí, by se do XML nedostal — základ i daň
        // by tiše zmizely (builder na to sice varuje, ale až při sestavení výkazu).
        // Whitelist je proto přímo u zdroje pravdy, DphPriznaniBuilder::USER_SELECTABLE_LINES;
        // vědomě z něj vypadává '34' (jeho base slot nese daň, plní ho interní injekce
        // § 74b) a krácené 40k/41k/42k (tvoří si je VatLedgerService sám).
        foreach (['dphdp3_line', 'dphdp3_line_secondary'] as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            if ($value === null || $value === '') {
                continue;
            }
            if (!in_array((string) $value, DphPriznaniBuilder::USER_SELECTABLE_LINES, true)) {
                return $field . ' musí být jeden z podporovaných řádků přiznání ('
                    . implode(', ', DphPriznaniBuilder::USER_SELECTABLE_LINES) . ') nebo prázdné.';
            }
            // Past „kód se jmenuje jako řádek" (audit L-1). Seed ji v číselníku vyrobil
            // třikrát a musely ji opravovat migrace 0063 a 0106 — u kódu 42 přitom vzniká
            // neexistující odpočet při dovozu. SSOT je VatClassificationLineGuard.
            $collision = VatClassificationLineGuard::lineCollision(
                isset($body['code']) ? (string) $body['code'] : null,
                (string) $value,
            );
            if ($collision !== null) {
                return $field . ': ' . $collision;
            }
        }
        return null;
    }
}

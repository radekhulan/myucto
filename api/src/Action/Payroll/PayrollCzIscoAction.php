<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\CzIscoCodebook;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/payroll/cz-isco?q=…&limit=… — našeptávač klasifikace zaměstnání.
 *
 * Hledání běží **na serveru**. CZ-ISCO má 1 992 položek a bundle si projekt
 * hlídá tak pečlivě, že i překlady dělí do chunků — posílat celý číselník do
 * prohlížeče kvůli jednomu poli v kartě vztahu by bylo neúměrné. Endpoint
 * proto vrací jen shodu, oříznutou na rozumný počet.
 *
 * Hledá podle kódu (prefix, „2411") i podle názvu bez ohledu na diakritiku
 * („ucetni" najde „Účetní všeobecní"). Nabízí jen úrovně, které jde do pole
 * zadat — podskupinu (4 místa) a kategorii (5 míst).
 *
 * Response: { "items": [ { "code": "43111", "label": "Účetní všeobecní",
 *                          "level": 5, "parent_code": "4311",
 *                          "parent_label": "Úředníci v oblasti účetnictví" } ],
 *             "codebook": { …provenience připnutého číselníku… } }
 *
 * Právo: `payroll` + READ, stejně jako sousední `/api/payroll/jmhz/municipalities`.
 * Jde o veřejná referenční data ČSÚ, ne o data nájemce, a mzdová účetní
 * s právem jen ke čtení musí být schopná kartu vztahu přečíst i s názvem
 * profese. Zápis kódu si dál vynucuje `payroll.employment.write` na PUT terms.
 */
final class PayrollCzIscoAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly CzIscoCodebook $codebook,
        private readonly PayrollModuleAccess $access,
    ) {}

    public function search(Request $request, Response $response): Response
    {
        if (($error = $this->authorizeRead($request, $response)) !== null) {
            return $error;
        }
        $query = $request->getQueryParams();
        $rawLimit = $query['limit'] ?? CzIscoCodebook::DEFAULT_SEARCH_LIMIT;
        $limit = filter_var($rawLimit, FILTER_VALIDATE_INT);
        if ($limit === false) {
            return Json::error(
                $response,
                'validation_failed',
                'Limit našeptávače CZ-ISCO není celé číslo.',
                422,
            );
        }
        try {
            $items = $this->codebook->search(
                is_string($query['q'] ?? null) ? $query['q'] : '',
                $limit,
            );
            $codebook = $this->codebook->provenance();
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, ['items' => $items, 'codebook' => $codebook]);
    }

    private function authorizeRead(Request $request, Response $response): ?Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll', AccessLevel::READ, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď oprávnění.');
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď modulu.');
        }

        return null;
    }
}

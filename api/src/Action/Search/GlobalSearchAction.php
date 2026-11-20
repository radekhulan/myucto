<?php

declare(strict_types=1);

namespace MyInvoice\Action\Search;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Service\Search\GlobalSearch;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/search?q=...  — globální vyhledávač (Alt+Q a paleta Ctrl+K).
 *
 * Vrací klienty/dodavatele, vystavené i přijaté faktury a zaměstnance. Položky
 * menu se našeptávají client-side, sem nejdou. Co se hledá kde a jak se hlídá
 * hranice firem, rozhoduje {@see GlobalSearch}. Min. 2 znaky, jinak prázdné výsledky.
 */
final class GlobalSearchAction
{
    public function __construct(private readonly GlobalSearch $search) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = (string) ($request->getQueryParams()['q'] ?? '');

        return Json::ok($response, $this->search->search($request, SupplierGuard::currentId($request), $q));
    }
}

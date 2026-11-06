<?php

declare(strict_types=1);

namespace MyInvoice\Action\Client;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\DemoReadOnlyMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\Ares\VendorVatPayerResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Online ověření plátcovství DPH dodavatele (ARES dle IČO / VIES dle DIČ) při vytváření
 * či editaci přijaté faktury. Výsledek uloží na klienta (`clients.is_vat_payer`) a vrátí.
 *
 * Cache ARES (24 h) / VIES drží TTL na úrovni klientů → opakované volání je „1× denně"
 * bez zátěže registrů. Když registr nerozhodne, vrátí dosud uložený příznak.
 */
final class ClientVatStatusAction
{
    public function __construct(
        private readonly ClientRepository $repo,
        private readonly VendorVatPayerResolver $vatPayer,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);

        $client = $this->repo->find($id);
        if ($client === null || (int) ($client['supplier_id'] ?? 0) !== $sid) {
            return Json::error($response, 'not_found', 'Klient nenalezen.', 404);
        }

        $ic  = isset($client['ic'])  ? (string) $client['ic']  : null;
        $dic = isset($client['dic']) ? (string) $client['dic'] : null;
        $res = DemoReadOnlyMiddleware::enabled($request)
            ? $this->vatPayer->resolve($ic, $dic)
            : $this->vatPayer->resolveAndPersist($id, $ic, $dic);

        // Když registr nerozhodl (null), vrať dosud uložený příznak (beze změny).
        $isVatPayer = $res['is_vat_payer'] ?? (bool) ($client['is_vat_payer'] ?? true);

        return Json::ok($response, [
            'id'           => $id,
            'is_vat_payer' => $isVatPayer,
            'source'       => $res['source'],   // 'ares' | 'vies' | 'unknown'
            'ic'           => $ic,
            'dic'          => $res['dic_updated'] ?? $dic,
        ]);
    }
}

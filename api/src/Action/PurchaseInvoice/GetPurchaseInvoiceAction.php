<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Ai\AiSuggestionRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/purchase-invoices/{id}
 *
 * Vrátí detail přijaté faktury vč. items + vat_breakdown + totals.
 * Vrací 404 pokud neexistuje NEBO patří jinému tenantovi (neprozrazujeme existenci).
 */
final class GetPurchaseInvoiceAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly DocumentLockService $locks,
        private readonly AiSuggestionRepository $aiSuggestions,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }

        $invoice = $this->repo->find($id, SupplierGuard::currentId($request));
        if ($invoice === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        // Jednotný kontrakt zámku pro FE (Epic F6, §4.5) — FE nikdy neodvozuje ze status.
        $invoice['locked'] = $this->locks->forPurchaseInvoice($invoice)->toArray();
        $aiSuggestion = $invoice['status'] === 'draft'
            ? $this->aiSuggestions->pendingForEntity(SupplierGuard::currentId($request), 'purchase_invoice', $id)
            : null;
        if (is_array($aiSuggestion)) {
            unset($aiSuggestion['input_hash']);
        }
        $invoice['ai_posting_suggestion'] = $aiSuggestion;
        // Podle čeho se náklad zaúčtoval (migrace 1751). Sekce Zaúčtování dosud
        // ukazovala jen VÝSLEDEK, takže uživatel nepoznal, jestli za účtem stojí
        // firemní pravidlo (a dá se opravit), nebo jen dohad z klíčových slov.
        $invoice['expense_classification'] = $this->repo->expenseClassificationProvenance(
            SupplierGuard::currentId($request),
            $id,
        );

        return Json::ok($response, $invoice);
    }
}

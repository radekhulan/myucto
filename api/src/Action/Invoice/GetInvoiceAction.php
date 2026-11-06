<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\DemoReadOnlyMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetInvoiceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly ExchangeRateApplier $rateApplier,
        private readonly DocumentLockService $locks,
        private readonly \MyInvoice\Repository\PaymentScheduleRepository $paymentSchedule,
        private readonly \MyInvoice\Service\Invoice\InvoiceOtherItemLinkService $otherItemLinks,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $invoice = $this->repo->find($id);
        if ($invoice === null || (int) ($invoice['supplier_id'] ?? 0) !== $sid) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        // Backfill kurzu pro starší / chybějící exchange_rate (cache → ČNB → last known)
        if (
            (string) ($invoice['currency'] ?? 'CZK') !== 'CZK'
            && empty($invoice['exchange_rate'])
        ) {
            $demo = DemoReadOnlyMiddleware::enabled($request);
            $resolved = $this->rateApplier->ensureRate($id, persist: !$demo);
            $invoice = $demo
                ? $this->rateApplier->applyResolvedToInvoiceData($invoice, $resolved)
                : $this->repo->find($id);
        }

        // Jednotný kontrakt zámku pro FE (Epic F6, §4.5) — FE nikdy neodvozuje ze status.
        $invoice['locked'] = $this->locks->forInvoice($invoice)->toArray();

        // § 31/31a ZDPH — rozpis plateb kalendáře. Vrací se vždy (prázdné pole u běžných
        // dokladů), aby editor nemusel rozlišovat „nemá rozpis" od „ještě se nenačetl".
        $invoice['payment_schedule'] = $this->paymentSchedule->forInvoice($sid, $id);

        $invoice['cash_documents'] = $this->repo->cashDocumentsForInvoice($sid, $id);

        return Json::ok($response, $this->otherItemLinks->withLink($sid, $invoice));
    }
}

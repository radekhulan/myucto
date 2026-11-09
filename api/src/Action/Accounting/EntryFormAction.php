<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Forma účetního zápisu firmy (jednoduchý / složený) v čase.
 *
 *   GET  /api/accounting/entry-form   … forma dnes + historie
 *   POST /api/accounting/entry-form   { valid_from, entry_form: 'single'|'composite', conversion_preview_id?, note? }
 *
 * Čtení a zápis jdou výhradně přes {@see EntryFormService}.
 */
final class EntryFormAction
{
    use AccountingActionSupport;

    public function __construct(private readonly EntryFormService $entryForms) {}

    /** GET /api/accounting/entry-form */
    public function get(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'accounting', AccessLevel::READ, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);

        return Json::ok($response, [
            'entry_form' => $this->entryForms->formAt($supplierId, date('Y-m-d'))->value,
            'history'    => array_map(static fn (array $row): array => [
                'valid_from' => $row['valid_from'],
                'entry_form' => $row['entry_form'],
                'created_by' => $row['created_by'],
                'created_at' => $row['created_at'],
                'note'       => $row['note'],
            ], $this->entryForms->history($supplierId)),
        ]);
    }

    /** POST /api/accounting/entry-form — naplánování změny formy (jen admin firmy). */
    public function schedule(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        return Json::error($response, 'not_implemented', 'Změna formy účetního zápisu zatím není k dispozici.', 501);
    }
}

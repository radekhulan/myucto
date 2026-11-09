<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\IsoDate;
use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\EntryForm\EntryForm;
use MyInvoice\Service\Accounting\EntryForm\EntryFormException;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Accounting\Pair\JournalPairConversionService;
use MyInvoice\Service\Accounting\Pair\JournalPairException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Forma účetního zápisu firmy (jednoduchý / složený) v čase.
 *
 *   GET  /api/accounting/entry-form   … forma dnes + historie
 *   POST /api/accounting/entry-form   { valid_from, entry_form: 'single'|'composite', conversion_preview_id?, note? }
 *
 * Čtení a zápis jdou výhradně přes {@see EntryFormService}. Změnu smí jen správce firmy
 * ({@see RequestAuthorization::canChangeEntryForm()}). Přepnutí na jednoduchý zápis
 * k datu v minulosti spustí po uložení převod zápisů od `valid_from` na pozadí; jeho
 * chyba změnu formy nevrací (převod jde spustit znovu z obrazovky převodu).
 */
final class EntryFormAction
{
    use AccountingActionSupport;

    public function __construct(
        private readonly EntryFormService $entryForms,
        private readonly JournalPairConversionService $conversions,
    ) {}

    /** GET /api/accounting/entry-form */
    public function get(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'accounting', AccessLevel::READ, $err)) return $err;
        return Json::ok($response, $this->view($this->currentSupplierId($request)));
    }

    /** POST /api/accounting/entry-form — naplánování změny formy (jen správce firmy). */
    public function schedule(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        if (!RequestAuthorization::canChangeEntryForm($request)) {
            return Json::error($response, 'forbidden', 'Formu účetního zápisu mění jen správce firmy.', 403);
        }
        $supplierId = $this->currentSupplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $validFrom = is_string($body['valid_from'] ?? null) ? trim($body['valid_from']) : '';
        if (!IsoDate::isValid($validFrom)) {
            return Json::error($response, 'validation_failed', 'valid_from musí být datum (YYYY-MM-DD).', 422);
        }
        $form = is_string($body['entry_form'] ?? null) ? EntryForm::tryFrom($body['entry_form']) : null;
        if ($form === null) {
            return Json::error($response, 'validation_failed', "entry_form musí být 'single' nebo 'composite'.", 422);
        }
        $previewId = $body['conversion_preview_id'] ?? null;
        if ($previewId !== null && (!is_int($previewId) || $previewId <= 0)) {
            return Json::error($response, 'validation_failed', 'conversion_preview_id musí být kladné celé číslo.', 422);
        }
        $note = is_string($body['note'] ?? null) ? $body['note'] : null;
        $actor = (int) ($this->userId($request) ?? 0);

        try {
            $this->entryForms->schedule($supplierId, $validFrom, $form, $actor, $previewId, $note);
        } catch (EntryFormException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->context);
        }

        // Zápisy v jednoduchém okně musí být po dvojicích: převod od valid_from běží
        // až po uložení formy a jeho chyba změnu nevrací.
        $conversion = null;
        $today = date('Y-m-d');
        if ($form->isSingle() && $validFrom <= $today) {
            try {
                $conversion = ['run_id' => $this->conversions->apply($supplierId, $validFrom, $today, $actor), 'error' => null];
            } catch (\Throwable $e) {
                $conversion = ['run_id' => null, 'error' => $e instanceof JournalPairException ? $e->errorCode : 'conversion_failed'];
            }
        }

        return Json::ok($response, $this->view($supplierId) + ['conversion' => $conversion], 201);
    }

    /** @return array{entry_form:string, history:list<array<string,mixed>>} */
    private function view(int $supplierId): array
    {
        return [
            'entry_form' => $this->entryForms->formAt($supplierId, date('Y-m-d'), currentRead: true)->value,
            'history'    => array_map(static fn (array $row): array => [
                'valid_from' => $row['valid_from'],
                'entry_form' => $row['entry_form'],
                'created_by' => $row['created_by'],
                'created_at' => $row['created_at'],
                'note'       => $row['note'],
            ], $this->entryForms->history($supplierId)),
        ];
    }
}

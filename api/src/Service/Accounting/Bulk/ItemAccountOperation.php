<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Accounting\Product\ProductPostingDefaults;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\JournalRebookService;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Přeúčtování vybraných POLOŽEK (agenda Položky dokladů): položka dostane vlastní účet
 * (výnos u vydané, náklad u přijaté faktury) a zaúčtovaný doklad se zaúčtuje znovu
 * z dokladu toutéž cestou jako změna předkontace ({@see SetPostingRuleOperation}:
 * zápis účtů položek a {@see \MyInvoice\Service\Accounting\DocumentRepostService}).
 * Ostatní položky téhož dokladu zůstanou. DPH se nemění.
 *
 * Klíč operace je stejný jako přeúčtování řádků deníku nad doklady
 * ({@see JournalRebookService::KEY}); nad položkami ale mění doklad, ne zápis.
 * Volitelný `from_account` zúží změnu na položky, jejichž účet tímto začíná.
 */
final class ItemAccountOperation implements BulkOperation, BulkCandidateSource
{
    public const TARGETS = ['invoice_item', 'purchase_invoice_item'];

    public function __construct(
        private readonly SetPostingRuleOperation $postingRule,
        private readonly DocumentItemSearch $search,
        private readonly Connection $db,
        private readonly AccountingModeRepository $modes,
    ) {}

    public function key(): string
    {
        return JournalRebookService::KEY;
    }

    public function supportsRollback(): bool
    {
        return true;
    }

    public function unavailableReason(Request $request, int $supplierId, string $target): ?string
    {
        if (!RequestAuthorization::canBulkChange($request)) {
            return 'Hromadné změny smí správce firmy nebo role s právem Hromadné změny dokladů a zápisů.';
        }
        if (!in_array($target, self::TARGETS, true)) {
            return 'Účet položky jde měnit jen nad položkami dokladů.';
        }
        if (!$this->modes->hasDoubleEntry($supplierId)) {
            return 'Firma nevede podvojné účetnictví.';
        }
        $module = DocumentItemSearch::documentType($target) === 'invoice' ? 'invoices' : 'purchase_invoices';
        return RequestAuthorization::allows($request, $module, AccessLevel::WRITE) ? null : 'Chybí oprávnění upravovat doklady.';
    }

    public function normalizeParams(int $supplierId, string $target, array $raw): array
    {
        $to = is_string($raw['to_account'] ?? null) ? trim($raw['to_account']) : '';
        if ($to === '' || preg_match('/^[0-9][0-9A-Za-z.]{0,9}$/D', $to) !== 1) {
            throw new BulkActionException('validation_failed', 'Zvolte účet, na který se položky přeúčtují.', 422);
        }
        // Tatáž kontrola jako u účtu zadaného na položce v editoru: aktivní účet osnovy
        // a ne saldokontní, daňový ani peněžní (platí pro vydané i přijaté položky).
        try {
            (new ProductPostingDefaults($this->db))->validateIssuedItemAccount($supplierId, $to);
        } catch (\InvalidArgumentException $e) {
            throw new BulkActionException('validation_failed', $e->getMessage(), 422);
        }
        $params = ['to_account' => $to, 'mode' => JournalRebookService::MODE_REWRITE];
        $from = is_string($raw['from_account'] ?? null) ? trim($raw['from_account']) : '';
        if ($from !== '') {
            if (preg_match('/^[0-9][0-9A-Za-z.]{0,9}$/D', $from) !== 1) {
                throw new BulkActionException('validation_failed', 'Účet nebo začátek účtu „' . mb_substr($from, 0, 20) . '" není platný.', 422);
            }
            $params['from_account'] = $from;
        }
        return $params;
    }

    public function candidates(BulkContext $ctx): array
    {
        return $this->search->rows($ctx->supplierId, $ctx->target, array_map('intval', (array) ($ctx->params['ids'] ?? [])));
    }

    public function evaluate(BulkContext $ctx, array $row): array
    {
        return $this->postingRule->evaluate($ctx, $row);
    }

    public function apply(BulkContext $ctx, array $dry): array
    {
        return $this->postingRule->apply($ctx, $dry);
    }

    public function rollback(BulkContext $ctx, array $applied): array
    {
        return $this->postingRule->rollback($ctx, $applied);
    }
}

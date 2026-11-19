<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hromadná změna členění DPH (kód klasifikace, podle kterého se položka vykáže na řádku
 * přiznání a v oddílu KH) a u vybraných položek volitelně i sazby DPH, například
 * „místní poplatek z pobytu je mimo předmět daně" (kód `mimo` a sazba 0 %).
 *
 * Mění se položky; kód hlavičky, součty dokladu, rekapitulaci DPH a zápis v deníku
 * přepočte úprava dokladu ({@see DocumentItemEditor}). Evidence DPH, přiznání i KH čtou
 * kód z položky ({@see \MyInvoice\Service\Report\VatLedgerService}), takže se změna
 * promítne do všech výkazů. Sazba mění částky stejně jako úprava položky v editoru:
 * u dokladu s cenami s DPH zůstane cena s DPH a dopočítá se základ, jinak zůstane
 * základ a dopočítá se daň.
 *
 * Doklad v období, za které je podané přiznání k DPH nebo KH (u dokladu s OSS položkou
 * i OSS přiznání), se přeskočí vždy, i u správce. Přeskočí se i doklad s rozpisem DPH
 * (u přijatého dokladu ho určují části rozpisu, ne položky) a položka v režimu OSS
 * (do tuzemského přiznání nevstupuje, zařazení řeší hromadná změna OSS).
 */
final class SetVatClassificationOperation extends DocumentItemOperation
{
    public const KEY = 'set_vat_classification';
    public const TARGETS = ['invoice', 'purchase_invoice', 'invoice_item', 'purchase_invoice_item'];

    public function __construct(
        DocumentItemEditor $editor,
        DocumentItemSearch $search,
        BulkSelection $selection,
        private readonly Connection $db,
    ) {
        parent::__construct($editor, $search, $selection);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function unavailableReason(Request $request, int $supplierId, string $target): ?string
    {
        if (!RequestAuthorization::canBulkChange($request)) {
            return 'Hromadné změny smí správce firmy nebo role s právem Hromadné změny dokladů a zápisů.';
        }
        if (!in_array($target, self::TARGETS, true)) {
            return 'Členění DPH jde měnit jen u vydaných a přijatých faktur a jejich položek.';
        }
        $type = DocumentItemSearch::isItemTarget($target) ? DocumentItemSearch::documentType($target) : $target;
        $allowed = $type === 'invoice'
            ? RequestAuthorization::allows($request, 'invoices', AccessLevel::WRITE)
            : RequestAuthorization::allows($request, 'purchase_invoices', AccessLevel::WRITE)
                || RequestAuthorization::canChangePurchaseVatTreatment($request);
        return $allowed ? null : 'Chybí oprávnění upravovat doklady.';
    }

    public function normalizeParams(int $supplierId, string $target, array $raw): array
    {
        $type = DocumentItemSearch::isItemTarget($target) ? DocumentItemSearch::documentType($target) : $target;
        $code = is_string($raw['vat_classification_code'] ?? null) ? trim($raw['vat_classification_code']) : '';
        if ($code === '' || preg_match('/^[0-9A-Za-z]{1,10}$/D', $code) !== 1) {
            throw new BulkActionException('validation_failed', 'Zvolte členění DPH.', 422);
        }
        $direction = $type === 'invoice' ? 'sale' : 'purchase';
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM vat_classifications
              WHERE code = ? AND archived = 0 AND direction IN (?, \'both\') AND (supplier_id IS NULL OR supplier_id = ?)
              LIMIT 1'
        );
        $stmt->execute([$code, $direction, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new BulkActionException('validation_failed',
                'Členění „' . $code . '" v číselníku ' . ($type === 'invoice' ? 'uskutečněných' : 'přijatých') . ' plnění firmy není.', 422);
        }
        $params = ['vat_classification_code' => $code];
        $rate = $raw['vat_rate_id'] ?? null;
        if ($rate !== null && $rate !== '' && $rate !== 0) {
            if (!DocumentItemSearch::isItemTarget($target)) {
                throw new BulkActionException('validation_failed', 'Sazbu DPH jde hromadně měnit jen u vybraných položek.', 422);
            }
            $rateId = filter_var($rate, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $exists = $this->db->pdo()->prepare('SELECT 1 FROM vat_rates WHERE id = ?');
            $exists->execute([$rateId === false ? 0 : $rateId]);
            if ($rateId === false || $exists->fetchColumn() === false) {
                throw new BulkActionException('validation_failed', 'Neznámá sazba DPH.', 422);
            }
            $params['vat_rate_id'] = $rateId;
        }
        return $params;
    }

    protected function vatRelevant(): bool
    {
        return true;
    }

    protected function desired(BulkContext $ctx, string $type, array $item): array
    {
        $out = ['vat_classification_code' => (string) $ctx->params['vat_classification_code']];
        if (isset($ctx->params['vat_rate_id'])) {
            $out['vat_rate_id'] = (int) $ctx->params['vat_rate_id'];
        }
        return $out;
    }

    protected function documentReason(BulkContext $ctx, string $type, array $doc, array $items): ?array
    {
        if (array_filter($items, static fn (array $i): bool => $i['oss']) !== []) {
            return ['oss_item', 'Položka je v režimu OSS a do tuzemského přiznání nevstupuje; zařazení změňte hromadnou změnou OSS.'];
        }
        if ($type === 'purchase_invoice' && ((array) ($doc['vat_allocations'] ?? [])) !== []) {
            return ['vat_allocations', 'Doklad má rozpis DPH, přiznání i KH berou zařazení z částí rozpisu. Změňte ho v rozpisu dokladu.'];
        }
        return null;
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DisposalResiduals;

/**
 * Vyřazení převzaté karty dlouhodobého majetku, které zaúčtoval převzatý deník.
 *
 * Zdrojový program vyřazení zaúčtoval (zůstatková cena 54x proti oprávkám, vyřazení
 * z evidence) a jeho deník je v MyÚčtu. Karta se proto vyřadí BEZ zaúčtování
 * ({@see AssetService::disposeFromJournal()}) a naváže se na zápis, který vyřazení
 * zaúčtoval: vyřazení v modulu majetku by zůstatkovou cenu zaúčtovalo podruhé.
 *
 * Typ vyřazení bere převod ze zdroje; když ho zdroj nevede, rozhodne deník: výnos
 * z prodeje dlouhodobého majetku (D 641) ke dni vyřazení = prodej, jinak likvidace.
 */
final class MigratedDisposal
{
    /** Účtová skupina tržeb z prodeje dlouhodobého majetku. */
    private const SALE_REVENUE_PREFIX = '641';

    public function __construct(
        private readonly Connection $db,
        private readonly AssetService $assets,
        private readonly DisposalResiduals $residuals,
    ) {}

    /**
     * Prodej doložený deníkem: zaúčtovaný výnos 641 ke dni vyřazení. Faktura prodeje,
     * jen když výnos nese jediná vydaná faktura.
     *
     * @return array{sold:bool, invoice_id:?int}
     */
    public function saleEvidence(int $supplierId, string $date): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT je.id, je.source_type, je.source_id
               FROM journal_entries je
               JOIN journal_entry_lines jl ON jl.entry_id = je.id AND jl.supplier_id = je.supplier_id AND jl.side = 'credit'
               JOIN chart_of_accounts ca ON ca.id = jl.account_id
              WHERE je.supplier_id = ? AND je.entry_date = ?
                AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                AND je.source_type NOT IN ('closing', 'opening')
                AND ca.account_code LIKE ?"
        );
        $stmt->execute([$supplierId, $date, self::SALE_REVENUE_PREFIX . '%']);
        $entries = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($entries === []) {
            return ['sold' => false, 'invoice_id' => null];
        }
        $invoices = [];
        foreach ($entries as $e) {
            if ((string) $e['source_type'] === 'invoice' && $e['source_id'] !== null) {
                $invoices[(int) $e['source_id']] = true;
            }
        }
        $invoiceId = null;
        if (count($invoices) === 1 && count($entries) === 1) {
            $candidate = (int) array_key_first($invoices);
            $owned = $this->db->pdo()->prepare('SELECT 1 FROM invoices WHERE id = ? AND supplier_id = ?');
            $owned->execute([$candidate, $supplierId]);
            $invoiceId = $owned->fetchColumn() !== false ? $candidate : null;
        }
        return ['sold' => true, 'invoice_id' => $invoiceId];
    }

    /**
     * Typ vyřazení: podle zdroje, když ho vede (`$sourceType`), jinak prodej, když deník
     * ke dni vyřazení účtuje tržbu z prodeje majetku, jinak likvidace.
     */
    public function type(int $supplierId, string $date, ?string $sourceType): string
    {
        if ($sourceType !== null) {
            return $sourceType;
        }
        return $this->saleEvidence($supplierId, $date)['sold'] ? 'sold' : 'liquidated';
    }

    /**
     * Zápis deníku, který vyřazení karty zaúčtoval: jediný zápis ke dni vyřazení s MD 54x
     * proti účtu karty, u více takových ten s číslem dokladu rovným inventárnímu číslu karty.
     * Víc kandidátů ani žádný = bez vazby (přiznání vezme ZC z karty).
     *
     * @param array<string,mixed> $asset karta (účty)
     */
    public function entry(int $supplierId, array $asset, string $date): ?int
    {
        $candidates = $this->residuals->journalEntriesFor(
            $supplierId,
            $date,
            DisposalResiduals::residualCreditAccount($asset),
            (string) ($asset['inventory_number'] ?? ''),
        );
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * Vyřadí převzatou kartu v užívání bez zaúčtování, s typem ze zdroje nebo z deníku
     * a s vazbou na zápis vyřazení.
     *
     * @return array{disposed:bool, type:?string, entry_id:?int, message:?string} message = proč
     *         karta vyřazená není (ke kontrole)
     */
    public function dispose(int $supplierId, int $assetId, string $date, ?string $sourceType, ?int $userId): array
    {
        $type = $this->type($supplierId, $date, $sourceType);
        $sale = $type === 'sold' ? $this->saleEvidence($supplierId, $date) : ['invoice_id' => null];
        try {
            $result = $this->assets->disposeFromJournal($supplierId, $assetId, [
                'date' => $date,
                'type' => $type,
                'sale_invoice_id' => $sale['invoice_id'],
            ], ['user_id' => $userId]);
        } catch (AssetException $e) {
            return ['disposed' => false, 'type' => null, 'entry_id' => null, 'message' => $e->getMessage()];
        }
        $entryId = $result['asset']['disposal_entry_id'] ?? null;
        return ['disposed' => true, 'type' => $type, 'entry_id' => $entryId !== null ? (int) $entryId : null, 'message' => null];
    }

    /** Karta, kterou se vyřadit nepodařilo, zůstane konceptem ke kontrole s popisem. */
    public function toReview(int $supplierId, int $assetId, string $description): void
    {
        $this->db->pdo()->prepare("UPDATE assets SET status = 'draft', description = ? WHERE id = ? AND supplier_id = ?")
            ->execute([$description, $assetId, $supplierId]);
    }
}

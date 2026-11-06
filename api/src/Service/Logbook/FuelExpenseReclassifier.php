<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AutoPostingPolicyService;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\Expense\ExpenseAutoClassifier;
use MyInvoice\Service\Accounting\OperationType;
use PDO;

/**
 * Most mezi knihou tankování a účetnictvím.
 *
 * Když {@see FuelInvoiceScanner} rozpozná na faktuře palivo, musí se táž položka
 * překlasifikovat i účetně na PHM (analytika z nastavení firmy, např. 501.100) —
 * jinak systém o jednom řádku rozhodne dvakrát a pokaždé jinak: v knize jízd je to
 * tankování, v deníku „Ostatní služby" na 518.
 *
 * Přeúčtování běží JEN při zapnutém automatickém účtování přijatých faktur (stejná
 * politika jako {@see DocumentAutoPoster}); jinak se klasifikace uloží na položky a
 * účetní si doklad zaúčtuje sama, až uzná za vhodné.
 *
 * Zápis se PŘEPISUJE, nestornovává: {@see \MyInvoice\Service\Accounting\PostingService::postDocument()}
 * pro tutéž dvojici (source_type, source_id) smaže staré řádky a vloží nové in-place,
 * takže v deníku nezůstane protizápis ani duplicitní doklad. V zavřeném či zamčeném
 * období to odmítne (period_not_open / date_locked) — tam je storno jediná legální cesta.
 */
final class FuelExpenseReclassifier
{
    public function __construct(
        private readonly Connection $db,
        private readonly ExpenseAutoClassifier $autoClassifier,
        private readonly DocumentAutoPoster $autoPoster,
        private readonly AutoPostingPolicyService $policy,
    ) {}

    /**
     * Překlasifikuje palivové položky faktury a (je-li zapnuté auto-účtování) doklad přeúčtuje.
     *
     * @return array{changes:list<array<string,mixed>>, reposted:bool, journal_entry_id:?int, error?:string}
     */
    public function reclassifyFuelAndRepost(int $supplierId, int $purchaseInvoiceId, ?int $userId): array
    {
        $fuelItems = $this->autoClassifier->fuelItemIds($supplierId, $purchaseInvoiceId);
        $changes = $this->autoClassifier->applyToInvoice($supplierId, $purchaseInvoiceId, $fuelItems, $userId);

        if ($changes === []) {
            return ['changes' => [], 'reposted' => false, 'journal_entry_id' => null];
        }
        if (!$this->autoPostEnabled($supplierId)) {
            // Klasifikace uložena, účtování zůstává na účetní.
            return ['changes' => $changes, 'reposted' => false, 'journal_entry_id' => null];
        }
        if (!$this->isPosted($supplierId, $purchaseInvoiceId)) {
            // Doklad ještě není v deníku — zaúčtuje ho běžný auto-post hook.
            return ['changes' => $changes, 'reposted' => false, 'journal_entry_id' => null];
        }

        try {
            $entryId = $this->autoPoster->post($supplierId, 'purchase_invoice', $purchaseInvoiceId, [
                'user_id'   => $userId,
                'posted_by' => $userId,
            ], $userId);
            return ['changes' => $changes, 'reposted' => true, 'journal_entry_id' => $entryId];
        } catch (\Throwable $e) {
            // Zamčené/zavřené období apod. — klasifikace na položkách zůstává uložená,
            // účetní zápis se nezměnil. Volající to jen ohlásí, vytěžení nepadá.
            return [
                'changes'          => $changes,
                'reposted'         => false,
                'journal_entry_id' => null,
                'error'            => $e->getMessage(),
            ];
        }
    }

    private function autoPostEnabled(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $mode = $stmt->fetchColumn();
        return $mode === 'double_entry'
            && $this->policy->levelFor($supplierId, OperationType::DOCUMENT_PURCHASE) === 'auto';
    }

    /** Má doklad aktivní (nestornovaný) zápis v deníku? */
    private function isPosted(int $supplierId, int $purchaseInvoiceId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM journal_entries
              WHERE supplier_id = ? AND source_type = 'purchase_invoice' AND source_id = ?
                AND reversed_by IS NULL
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $purchaseInvoiceId]);
        return $stmt->fetch(PDO::FETCH_NUM) !== false
            || (new DocumentPostingGroup($this->db))->hasActive($supplierId, 'purchase_invoice', $purchaseInvoiceId);
    }
}

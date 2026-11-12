<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\PostingException;

/** Chrání použitý bankovní zdroj před druhým přiřazením nebo změnou finančního důkazu. */
final class MembershipBankPaymentGuard
{
    public function __construct(private readonly Connection $db) {}

    /** Indexovaná existence členského cíle na konkrétním řádku bankovního zápisu. */
    public static function entryUsedSql(string $entryAlias): string
    {
        return "EXISTS (SELECT 1 FROM journal_line_pairing_items mbp
            JOIN journal_line_pairing_items mcp ON mcp.supplier_id=mbp.supplier_id AND mcp.pairing_id=mbp.pairing_id
            JOIN nonprofit_membership_charge_operations mop ON mop.supplier_id=mcp.supplier_id
                AND mop.journal_entry_id=mcp.entry_id AND mop.operation='issue'
            JOIN journal_entry_lines mcl ON mcl.supplier_id=mcp.supplier_id AND mcl.entry_id=mcp.entry_id
                AND mcl.line_no=mcp.line_no AND mcl.account_id=mcp.account_id AND mcl.id=mop.receivable_line_id
            WHERE mbp.supplier_id=$entryAlias.supplier_id AND mbp.entry_id=$entryAlias.id)";
    }

    public function assertSourceMutable(int $supplier, string $kind, int $id): void
    {
        if ($kind !== 'bank') return;
        $this->check($supplier, "e.source_type='bank' AND e.source_id=?", [$id]);
    }

    public function assertEntriesMutable(int $supplier, array $ids): void
    {
        if ($ids === []) return;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $this->check($supplier, "e.id IN ($in)", $ids);
    }

    /** @var array{0:int,1:int}|null firma a bankovní zápis, jehož dimenze dorovnává právě probíhající párování */
    private static ?array $restamping = null;

    /**
     * Výjimka jen pro {@see MembershipBankMatchService::match()}: dimenze platby se dorovnají na pohledávku
     * až s párováním (teprve pak je výchozí dimenze pohybu vidí), ve stejné transakci a jen u tohoto zápisu.
     */
    public static function restampingMatchedPayment(int $supplier, int $entryId, callable $restamp): mixed
    {
        $previous = self::$restamping;
        self::$restamping = [$supplier, $entryId];
        try {
            return $restamp();
        } finally {
            self::$restamping = $previous;
        }
    }

    public function assertLinesMutable(int $supplier, array $ids): void
    {
        if ($ids === [] || !$this->supplierHasClaims($supplier)) return;
        // Řádky nejdřív přes primární klíč na zápisy (jako CapitalUseGuard::assertLinesMutable);
        // EXISTS nad řádky by optimalizátor řídil přes všechny bankovní zápisy firmy pod FOR UPDATE.
        $in = implode(',', array_fill(0, count($ids), '?'));
        $q = $this->db->pdo()->prepare("SELECT DISTINCT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id IN ($in)");
        $q->execute([$supplier, ...array_map('intval', $ids)]);
        $entries = array_map('intval', $q->fetchAll(\PDO::FETCH_COLUMN));
        if (self::$restamping !== null && self::$restamping[0] === $supplier) {
            $entries = array_values(array_diff($entries, [self::$restamping[1]]));
        }
        $this->assertEntriesMutable($supplier, $entries);
    }

    public function assertBankStatementMutable(int $supplier, int $statement): void
    {
        $this->check($supplier, "e.source_type='bank' AND e.source_id IN (SELECT id FROM bank_transactions WHERE statement_id=?)", [$statement]);
    }

    private function check(int $supplier, string $filter, array $params): void
    {
        if (!$this->supplierHasClaims($supplier)) return;
        $q = $this->db->pdo()->prepare("SELECT e.id FROM journal_entries e WHERE e.supplier_id=?
            AND e.source_type='bank' AND e.reversed_by IS NULL AND $filter AND " . self::entryUsedSql('e') . ' LIMIT 1 FOR UPDATE');
        $q->execute([$supplier, ...$params]);
        if ($q->fetchColumn() !== false) throw new PostingException('nonprofit_membership_payment_in_use',
            'Bankovní pohyb dokládá úhradu členských příspěvků. Nejprve zrušte jeho párování v saldokontu.', 409);
    }

    /**
     * Bez předpisu nemůže existovat ani jeho úhrada; firma bez členských příspěvků se nezamyká
     * (jako NonprofitTaxSavingUseGuard::supplierHasActiveUse). Čte bez zámku: sdílený zámek by zamkl
     * i mezeru indexu sousední firmy a před PaymentMutationContext::acquire() obrátil pořadí zámků
     * proti zakládání předpisu. Souběh úplně prvního předpisu firmy s úpravou pohybu je známý limit.
     */
    public function supplierHasClaims(int $supplier): bool
    {
        $q = $this->db->pdo()->prepare('SELECT 1 FROM nonprofit_membership_charge_claims WHERE supplier_id=? LIMIT 1');
        $q->execute([$supplier]);
        return $q->fetchColumn() !== false;
    }
}

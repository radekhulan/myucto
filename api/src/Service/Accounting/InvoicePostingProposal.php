<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ai\AiPostingOverrideResolver;

/**
 * Návrh zápisu faktury: JEDINÝ výpočet, ze kterého vychází náhled v detailu, náhled
 * v editoru i samotné zaúčtování. Kdyby si kterékoli místo skládalo řádky jinak
 * (třeba bez AI přebití účtu nákladu), uživatel by potvrzoval něco jiného, než co
 * se zapíše do deníku.
 */
final class InvoicePostingProposal
{
    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly AiPostingOverrideResolver $aiOverrides,
    ) {}

    /**
     * Datum zápisu dokladu, se kterým účtuje „Zaúčtovat" bez vlastního data: DUZP,
     * jinak datum vystavení. NULL = doklad ve firmě neexistuje.
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     */
    public function documentDate(int $supplierId, string $sourceType, int $docId): ?string
    {
        $table = $sourceType === 'invoice' ? 'invoices' : 'purchase_invoices';
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(tax_date, issue_date) AS d FROM {$table} WHERE id = ? AND supplier_id = ?"
        );
        $stmt->execute([$docId, $supplierId]);
        $d = $stmt->fetchColumn();

        return $d === false || $d === null ? null : (string) $d;
    }

    /**
     * Řádky zápisu dokladu tak, jak je zaúčtuje „Zaúčtovat" bez vlastních řádků.
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     * @return list<array<string,mixed>>
     */
    public function lines(int $supplierId, string $sourceType, int $docId, string $entryDate): array
    {
        if ($sourceType === 'invoice') {
            return $this->posting->buildFromInvoice($supplierId, $docId, ['entry_date' => $entryDate]);
        }

        // Override z AI klasifikace je součástí NÁVRHU, ne až zaúčtování.
        return $this->posting->buildFromPurchaseInvoice($supplierId, $docId, array_filter([
            'entry_date' => $entryDate,
            'debit_account_code' => $this->aiOverrides->debitOverrideForPurchase($supplierId, $docId),
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * Doplní k řádkům názvy účtů, ať uživatel nepotvrzuje holá čísla.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    public function describe(int $supplierId, array $lines): array
    {
        $codes = array_values(array_unique(array_map(
            static fn (array $l): string => (string) $l['account_code'],
            $lines,
        )));
        if ($codes === []) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT account_code, name FROM chart_of_accounts
              WHERE supplier_id = ? AND account_code IN ({$ph})"
        );
        $stmt->execute(array_merge([$supplierId], $codes));
        $names = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $names[(string) $r['account_code']] = (string) $r['name'];
        }

        return array_map(static function (array $l) use ($names): array {
            $code = (string) $l['account_code'];

            return [
                'account_code' => $code,
                'account_name' => $names[$code] ?? null,
                'side'         => (string) $l['side'],
                'amount'       => round((float) $l['amount'], 2),
                'is_red_storno' => (bool) ($l['is_red_storno'] ?? false),
                'cost_center'  => $l['cost_center'] ?? null,
            ];
        }, $lines);
    }

    /** @param list<array<string,mixed>> $lines */
    public static function balanced(array $lines): bool
    {
        $delta = 0;
        foreach ($lines as $l) {
            $cents = JournalLineAmount::signedCents($l);
            $delta += (string) $l['side'] === 'debit' ? $cents : -$cents;
        }

        return $delta === 0;
    }
}

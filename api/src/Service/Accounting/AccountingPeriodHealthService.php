<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Diagnostika účetních období — jediné čtení, ze kterého žije karta „Akce pro tebe"
 * ({@see \MyInvoice\Service\Crm\CrmAggregationService::actionItems}) i kontrolní CLI
 * ({@see \MyInvoice\Service\Accounting\AccountingPeriodHealthService::diagnose},
 * `api/bin/check-accounting-periods.php`).
 *
 * PROČ TO EXISTUJE
 * ------------------------------------------------------------------------------
 * Chybějící účetní období se do teď projevovalo AŽ ve chvíli, kdy někdo klikl na
 * „Zaúčtovat" — a to hláškou, která ho poslala do neexistující sekce menu. Firma
 * s naimportovanou historií (doklady z let před zavedením účetnictví) na to narazila
 * u prvního historického dokladu a neměla kam jít; firma, která zapomněla na přelomu
 * roku otevřít nový rok, na to narazila 2. ledna. Obojí je stav, který jde poznat
 * DOPŘEDU jedním dotazem — a právě to tahle služba dělá.
 *
 * Rozlišuje tři různé stavy, protože každý má jinou nápravu:
 *   - `no_periods`        … firma nemá ani jedno období (neproběhl setup / aktivace)
 *                           → průvodce aktivací účetnictví, ne ruční zakládání let,
 *   - `current_missing`   … řada existuje, ale nepokrývá dnešek (zapomenutý přelom
 *                           roku) → jedno kliknutí na Uzávěrku,
 *   - `documents_outside` … existují doklady k zaúčtování s datem mimo jakékoli
 *                           období (typicky import historie).
 *
 * Poslední dva stavy si {@see AccountingPeriodProvisioner} umí spravit sám ve chvíli,
 * kdy se doklad účtuje nebo importuje. Diagnostika je tu proto, že to uživatel má
 * vidět DŘÍV, než na to narazí — a proto, že provisioner odmítá případy, které
 * jednoznačné nejsou (nepravidelná řada s překryvem, nesmyslný rok, daňová evidence);
 * ty zůstanou svítit tady.
 */
final class AccountingPeriodHealthService
{
    /**
     * Predikát bookovatelných vydaných dokladů — shodný s
     * {@see \MyInvoice\Service\Automation\AutomationFeedService::unbookedInvoices}
     * a s `booked=0` v seznamech. Bez téhle shody by karta hlásila počet, který
     * uživatel v cílovém seznamu nenajde.
     */
    private const INVOICE_SCOPE = "booked_at IS NULL AND status NOT IN ('draft','cancelled')"
        . " AND invoice_type IN ('invoice','credit_note','tax_document','penalty')";

    /** Totéž pro přijaté doklady (zálohová faktura se do deníku neúčtuje). */
    private const PURCHASE_SCOPE = "booked_at IS NULL AND status NOT IN ('draft','cancelled')"
        . " AND document_kind <> 'advance'";

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   applicable: bool,
     *   state: 'ok'|'no_periods'|'current_missing'|'documents_outside',
     *   severity: 'none'|'medium'|'high',
     *   period_count: int,
     *   earliest_starts_on: ?string,
     *   latest_ends_on: ?string,
     *   accounting_starts_on: ?string,
     *   current_date: string,
     *   outside_count: int,
     *   outside_min_date: ?string,
     *   outside_max_date: ?string,
     *   outside_before_series: int,
     *   outside_after_series: int,
     *   has_bank_data: bool
     * }
     */
    public function diagnose(int $supplierId, ?string $today = null): array
    {
        $pdo = $this->db->pdo();
        $today ??= date('Y-m-d');

        $stmt = $pdo->prepare(
            'SELECT accounting_mode, accounting_enabled, accounting_starts_on FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $result = [
            'applicable'           => (string) ($supplier['accounting_mode'] ?? '') === 'double_entry'
                                      && (int) ($supplier['accounting_enabled'] ?? 1) === 1,
            'state'                => 'ok',
            'severity'             => 'none',
            'period_count'         => 0,
            'earliest_starts_on'   => null,
            'latest_ends_on'       => null,
            'accounting_starts_on' => $supplier['accounting_starts_on'] ?? null,
            'current_date'         => $today,
            'outside_count'        => 0,
            'outside_min_date'     => null,
            'outside_max_date'     => null,
            'outside_before_series' => 0,
            'outside_after_series' => 0,
            'has_bank_data'        => false,
        ];
        if (!$result['applicable']) {
            // Daňová evidence ani vypnutá nadstavba účetní období nepotřebuje —
            // hlásit jejich absenci by byl trvalý falešný poplach.
            return $result;
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS cnt, MIN(starts_on) AS min_start, MAX(ends_on) AS max_end
               FROM accounting_periods WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $result['period_count'] = (int) ($row['cnt'] ?? 0);
        $result['earliest_starts_on'] = $row['min_start'] ?? null;
        $result['latest_ends_on'] = $row['max_end'] ?? null;

        if ($result['period_count'] === 0) {
            // Sken dokladů mimo období se schválně NEDĚLÁ: bez jediného období je „mimo"
            // úplně všechno, takže by to byl drahý dotaz s předem známým a nepoužitelným
            // výsledkem. Diagnostika běží při každém načtení dashboardu.
            $result['state'] = 'no_periods';
            $result['severity'] = 'high';
            $result['has_bank_data'] = $this->hasBankData($supplierId);
            return $result;
        }

        [$outside, $minDate, $maxDate] = $this->documentsOutsidePeriods($supplierId);
        $result['outside_count'] = $outside;
        $result['outside_min_date'] = $minDate;
        $result['outside_max_date'] = $maxDate;
        if ($outside > 0) {
            $result['outside_before_series'] = $minDate !== null && $minDate < (string) $result['earliest_starts_on'] ? 1 : 0;
            $result['outside_after_series'] = $maxDate !== null && $maxDate > (string) $result['latest_ends_on'] ? 1 : 0;
        }

        $covered = $pdo->prepare(
            'SELECT 1 FROM accounting_periods WHERE supplier_id = ? AND ? BETWEEN starts_on AND ends_on LIMIT 1'
        );
        $covered->execute([$supplierId, $today]);
        if ($covered->fetchColumn() === false) {
            $result['state'] = 'current_missing';
            $result['severity'] = 'high';
            return $result;
        }

        if ($outside > 0) {
            $result['state'] = 'documents_outside';
            // Střední závažnost: účetnictví jako takové běží, jen část naimportované
            // historie do něj nespadá. Není to výpadek, je to rozhodnutí k udělání.
            $result['severity'] = 'medium';
        }
        return $result;
    }

    /**
     * Doklady čekající na zaúčtování, jejichž datum nespadá do žádného účetního
     * období. Sjednocení obou stran evidence (vydané i přijaté) — asymetrický
     * dotaz by tuhle třídu problému uviděl jen na jedné straně.
     *
     * @return array{0:int, 1:?string, 2:?string} count, min date, max date
     */
    private function documentsOutsidePeriods(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) AS cnt, MIN(d.issue_date) AS min_date, MAX(d.issue_date) AS max_date
               FROM (
                    SELECT issue_date FROM invoices
                     WHERE supplier_id = :sid AND ' . self::INVOICE_SCOPE . '
                    UNION ALL
                    SELECT issue_date FROM purchase_invoices
                     WHERE supplier_id = :sid2 AND ' . self::PURCHASE_SCOPE . '
               ) d
              WHERE NOT EXISTS (
                    SELECT 1 FROM accounting_periods ap
                     WHERE ap.supplier_id = :sid3
                       AND d.issue_date BETWEEN ap.starts_on AND ap.ends_on
              )'
        );
        $stmt->execute(['sid' => $supplierId, 'sid2' => $supplierId, 'sid3' => $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            (int) ($row['cnt'] ?? 0),
            $row['min_date'] ?? null,
            $row['max_date'] ?? null,
        ];
    }

    /**
     * Má firma vůbec nějaké bankovní pohyby? Průvodce zaúčtováním banky se
     * nabízí jen tehdy — odkaz na prázdnou frontu je horší než žádný odkaz.
     */
    private function hasBankData(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE ' . \MyInvoice\Repository\BankStatementOwnershipResolver::sql() . '
              LIMIT 1'
        );
        $stmt->execute(\MyInvoice\Repository\BankStatementOwnershipResolver::params($supplierId));
        return $stmt->fetchColumn() !== false;
    }
}

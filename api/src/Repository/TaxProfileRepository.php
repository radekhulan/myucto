<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;
use MyInvoice\Support\Sql\AdvanceSettledSql;

/**
 * Daňové profily (tax_profiles) — per supplier × rok — a agregace příjmů
 * pro daňový optimalizátor. Příjem = zaplacené vystavené faktury přepočtené
 * na CZK (kasová metoda, stejně jako sledování limitu paušálu na dashboardu).
 *
 * Rodná čísla dětí a manžela/manželky (H28) se ukládají zapečetěná stejně jako
 * u vyživovaných osob ve mzdách ({@see \MyInvoice\Repository\Payroll\PayrollDependantRepository}):
 * šifrový text vázaný na řádek, tenantový otisk a maska. {@see find()} vrací jen
 * masku a příznak `has_birth_number`, otevřenou hodnotu nikdy. Dešifruje se jen
 * přes {@see familyBirthNumbers()} s deklarovaným účelem (sestavení XML přiznání,
 * auditované odhalení), viz {@see \MyInvoice\Service\Tax\TaxFamilyIdentifierService}.
 */
final class TaxProfileRepository
{
    private const SCALAR_COLUMNS = [
        'activity_rate', 'use_actual_expenses', 'actual_expenses', 'flat_tax_band', 'is_secondary',
        'spouse_credit', 'children_count', 'mortgage_interest', 'mortgage_pre_2021', 'mortgage_months',
        'pension_contrib', 'life_insurance', 'dip_contrib', 'long_term_care', 'disability_12_months',
        'disability_3_months', 'ztpp_months', 'donations', 'sickness_insured', 'sickness_monthly_base',
    ];

    /** Znak masky; hodnota s ním je maska vrácená klientem, ne nové rodné číslo. */
    private const MASK_CHAR = '•';

    public const FAMILY_FIELD = PayrollSensitiveField::TAX_FAMILY_IDENTIFIER;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollSensitiveData $sensitiveData,
    ) {}

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $year): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, year, activity_rate, use_actual_expenses, actual_expenses,
                    flat_tax_band, is_secondary, spouse_credit, children_count, mortgage_interest,
                    mortgage_pre_2021, mortgage_months, pension_contrib, life_insurance,
                    dip_contrib, long_term_care, disability_12_months, disability_3_months,
                    ztpp_months, donations,
                    sickness_insured, sickness_monthly_base
               FROM tax_profiles WHERE supplier_id = ? AND year = ?'
        );
        $stmt->execute([$supplierId, $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $profile = $this->cast($row);
        $profile['activities'] = $this->activities($supplierId, $year);
        $profile['children'] = $this->children($supplierId, $year);
        $profile['spouse_claim'] = $this->spouseClaim($supplierId, $year);
        $profile['osvc_months'] = $this->osvcMonths($supplierId, $year);
        return $profile;
    }

    /**
     * Vytvoří nebo aktualizuje profil (UNIQUE supplier_id+year).
     * @param array<string,mixed> $data
     * @return array<string,mixed> uložený profil
     */
    public function upsert(int $supplierId, int $year, array $data): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
        // Vynechaný klíč = uložená hodnota (issue #113); defaulty níž platí jen pro nový řádek.
        // Zámek řádku, ať souběžné částečné uložení nepřepíše pole načtené před ním.
        $pdo->prepare('SELECT id FROM tax_profiles WHERE supplier_id = ? AND year = ? FOR UPDATE')
            ->execute([$supplierId, $year]);
        $existing = $this->find($supplierId, $year);
        if ($existing !== null) {
            foreach (self::SCALAR_COLUMNS as $col) {
                if (!array_key_exists($col, $data)) {
                    $data[$col] = $existing[$col];
                }
            }
        }
        $activityRate = (string) ($data['activity_rate'] ?? '60');
        $flatTaxBand = (string) ($data['flat_tax_band'] ?? 'none');
        $pdo->prepare(
            'INSERT INTO tax_profiles
                (supplier_id, year, activity_rate, use_actual_expenses, actual_expenses, flat_tax_band,
                 is_secondary, spouse_credit, children_count, mortgage_interest, mortgage_pre_2021,
                 mortgage_months, pension_contrib, life_insurance, dip_contrib, long_term_care,
                 disability_12_months, disability_3_months, ztpp_months, donations,
                 sickness_insured, sickness_monthly_base)
             VALUES (:sid, :year, :activity_rate, :use_actual_expenses, :actual_expenses, :flat_tax_band,
                 :is_secondary, :spouse_credit, :children_count, :mortgage_interest, :mortgage_pre_2021,
                 :mortgage_months, :pension_contrib, :life_insurance, :dip_contrib, :long_term_care,
                 :disability_12_months, :disability_3_months, :ztpp_months, :donations,
                 :sickness_insured, :sickness_monthly_base)
             ON DUPLICATE KEY UPDATE
                activity_rate = VALUES(activity_rate),
                use_actual_expenses = VALUES(use_actual_expenses),
                actual_expenses = VALUES(actual_expenses),
                flat_tax_band = VALUES(flat_tax_band),
                is_secondary = VALUES(is_secondary),
                spouse_credit = VALUES(spouse_credit),
                children_count = VALUES(children_count),
                mortgage_interest = VALUES(mortgage_interest),
                mortgage_pre_2021 = VALUES(mortgage_pre_2021),
                mortgage_months = VALUES(mortgage_months),
                pension_contrib = VALUES(pension_contrib),
                life_insurance = VALUES(life_insurance),
                dip_contrib = VALUES(dip_contrib),
                long_term_care = VALUES(long_term_care),
                disability_12_months = VALUES(disability_12_months),
                disability_3_months = VALUES(disability_3_months),
                ztpp_months = VALUES(ztpp_months),
                donations = VALUES(donations),
                sickness_insured = VALUES(sickness_insured),
                sickness_monthly_base = VALUES(sickness_monthly_base)'
        )->execute([
            ':sid' => $supplierId,
            ':year' => $year,
            ':activity_rate' => in_array($activityRate, ['30', '40', '60', '80'], true) ? $activityRate : '60',
            ':use_actual_expenses' => !empty($data['use_actual_expenses']) ? 1 : 0,
            ':actual_expenses' => max(0.0, (float) ($data['actual_expenses'] ?? 0)),
            ':flat_tax_band' => in_array($flatTaxBand, ['none', 'band1', 'band2', 'band3'], true) ? $flatTaxBand : 'none',
            ':is_secondary' => !empty($data['is_secondary']) ? 1 : 0,
            ':spouse_credit' => !empty($data['spouse_credit']) ? 1 : 0,
            ':children_count' => max(0, (int) ($data['children_count'] ?? 0)),
            ':mortgage_interest' => max(0.0, (float) ($data['mortgage_interest'] ?? 0)),
            ':mortgage_pre_2021' => !empty($data['mortgage_pre_2021']) ? 1 : 0,
            ':mortgage_months' => max(0, min(12, (int) ($data['mortgage_months'] ?? 12))),
            ':pension_contrib' => max(0.0, (float) ($data['pension_contrib'] ?? 0)),
            ':life_insurance' => max(0.0, (float) ($data['life_insurance'] ?? 0)),
            ':dip_contrib' => max(0.0, (float) ($data['dip_contrib'] ?? 0)),
            ':long_term_care' => max(0.0, (float) ($data['long_term_care'] ?? 0)),
            ':disability_12_months' => max(0, min(12, (int) ($data['disability_12_months'] ?? 0))),
            ':disability_3_months' => max(0, min(12, (int) ($data['disability_3_months'] ?? 0))),
            ':ztpp_months' => max(0, min(12, (int) ($data['ztpp_months'] ?? 0))),
            ':donations' => max(0.0, (float) ($data['donations'] ?? 0)),
            ':sickness_insured' => !empty($data['sickness_insured']) ? 1 : 0,
            ':sickness_monthly_base' => isset($data['sickness_monthly_base']) && $data['sickness_monthly_base'] !== null && $data['sickness_monthly_base'] !== ''
                ? max(0, (int) $data['sickness_monthly_base'])
                : null,
        ]);

        $this->replaceRelations($supplierId, $year, $data);
        if ($ownTx) {
            $pdo->commit();
        }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($supplierId, $year) ?? [];
    }

    /**
     * Stejný rozpad jako peněžní deník: z uhrazeného brutto je průběžnou položkou
     * jen uložená DPH. Zaokrouhlení zůstává v příjmu či výdaji. Dobropis má záporné
     * brutto i DPH, proto se DPH omezuje podle absolutní hodnoty a stejného znaménka.
     */
    private static function paidInvoiceBaseSql(string $alias, bool $isVatPayer): string
    {
        return $isVatPayer
            ? "({$alias}.total_with_vat - IF(SIGN({$alias}.total_vat) = SIGN({$alias}.total_with_vat),"
                . " SIGN({$alias}.total_with_vat) * LEAST(ABS({$alias}.total_vat), ABS({$alias}.total_with_vat)), 0))"
            : "{$alias}.total_with_vat";
    }

    /**
     * Roční příjem (zaplacené faktury daného roku, přepočet na CZK).
     * Pro plátce DPH se bere bez DPH, pro neplátce s DPH (= fakturovaná částka).
     */
    public function annualIncome(int $supplierId, int $year, bool $isVatPayer): float
    {
        $col = self::paidInvoiceBaseSql('i', $isVatPayer);
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM({$col} * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)), 0)
               FROM invoices i
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status = 'paid'
                AND i.paid_at IS NOT NULL
                AND i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
                AND COALESCE(i.income_tax_exempt, 0) = 0
                AND YEAR(i.paid_at) = ?"
        );
        $stmt->execute([$supplierId, $year]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Roční příjem označený „osvobozeno od daně z příjmů" (income_tax_exempt=1,
     * zaplacené faktury daného roku). Slouží jen k transparentnímu zobrazení
     * „z toho vyloučeno ze základu daně z příjmů" v daňovém optimalizátoru —
     * do výpočtu daně/pojistného NEvstupuje (ten už osvobozené příjmy nezahrnuje).
     */
    public function annualExemptIncome(int $supplierId, int $year, bool $isVatPayer): float
    {
        $col = self::paidInvoiceBaseSql('i', $isVatPayer);
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM({$col} * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)), 0)
               FROM invoices i
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status = 'paid'
                AND i.paid_at IS NOT NULL
                AND i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
                AND COALESCE(i.income_tax_exempt, 0) = 1
                AND YEAR(i.paid_at) = ?"
        );
        $stmt->execute([$supplierId, $year]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Měsíční příjmy roku [1..12] => CZK (pro projekci běžícího roku).
     * @return array<int,float>
     */
    public function monthlyIncome(int $supplierId, int $year, bool $isVatPayer): array
    {
        $col = self::paidInvoiceBaseSql('i', $isVatPayer);
        $stmt = $this->db->pdo()->prepare(
            "SELECT MONTH(i.paid_at) AS m,
                    COALESCE(SUM({$col} * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)), 0) AS total
               FROM invoices i
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status = 'paid'
                AND i.paid_at IS NOT NULL
                AND i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
                AND COALESCE(i.income_tax_exempt, 0) = 0
                AND YEAR(i.paid_at) = ?
           GROUP BY MONTH(i.paid_at)"
        );
        $stmt->execute([$supplierId, $year]);
        $out = array_fill(1, 12, 0.0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['m']] = round((float) $r['total'], 2);
        }
        return $out;
    }

    /**
     * Příjem za konkrétní měsíc (zaplacené faktury, 'YYYY-MM', kasová metoda).
     * Pro odhad "čistý příjem za minulý měsíc" v daňovém optimalizátoru.
     */
    public function monthIncome(int $supplierId, string $ym, bool $isVatPayer): float
    {
        $col = self::paidInvoiceBaseSql('i', $isVatPayer);
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM({$col} * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)), 0)
               FROM invoices i
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status = 'paid'
                AND i.paid_at IS NOT NULL
                AND i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
                AND COALESCE(i.income_tax_exempt, 0) = 0
                AND DATE_FORMAT(i.paid_at, '%Y-%m') = ?"
        );
        $stmt->execute([$supplierId, $ym]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Náklady za konkrétní měsíc (zaplacené přijaté faktury, 'YYYY-MM', kasová metoda).
     * Stejná konvence jako PurchaseSummaryAction::advanceCostExclude() — zaplacenou
     * zálohu spárovanou s vyúčtovací fakturou nepočítej dvakrát.
     */
    public function monthExpenses(int $supplierId, string $ym, bool $isVatPayer): float
    {
        $col = self::paidInvoiceBaseSql('pi', $isVatPayer);
        // Kladně uložený dobropis náklad snižuje, stejně jako v evidenci DPH a peněžním deníku.
        $docSign = \MyInvoice\Service\Report\VatLedgerService::purchaseDocumentSignSql('pi');
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM({$docSign} * {$col} * COALESCE(IF(cur.code = 'CZK', 1, pi.exchange_rate), 1)), 0)
               FROM purchase_invoices pi
          LEFT JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.status = 'paid'
                AND pi.paid_at IS NOT NULL
                AND DATE_FORMAT(pi.paid_at, '%Y-%m') = ?
                AND NOT (COALESCE(pi.document_kind, '') = 'advance'
                     AND " . AdvanceSettledSql::purchase('pi') . ")
                AND COALESCE(pi.document_kind, '') <> 'tax_document'"
        );
        $stmt->execute([$supplierId, $ym]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Roky, za které existují zaplacené faktury (pro přepínač roku).
     * @return list<int>
     */
    public function incomeYears(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT YEAR(paid_at) AS y FROM invoices
              WHERE supplier_id = ? AND status = 'paid' AND paid_at IS NOT NULL
           ORDER BY y DESC"
        );
        $stmt->execute([$supplierId]);
        return array_map(static fn ($r) => (int) $r['y'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    private function activities(int $supplierId, int $year): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, nace_code, expense_mode, expense_rate, income_amount, expense_amount,
                    active_months, allocation_note, order_index
               FROM tax_profile_activities WHERE supplier_id = ? AND year = ? ORDER BY order_index, id'
        );
        $stmt->execute([$supplierId, $year]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'nace_code' => (string) $r['nace_code'],
            'expense_mode' => (string) $r['expense_mode'],
            'expense_rate' => (int) $r['expense_rate'],
            'income' => round((float) $r['income_amount'], 2),
            'expenses' => round((float) $r['expense_amount'], 2),
            'active_months' => (int) $r['active_months'],
            'allocation_note' => $r['allocation_note'] === null ? null : (string) $r['allocation_note'],
            'order_index' => (int) $r['order_index'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string,mixed>> */
    private function children(int $supplierId, int $year): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, first_name, last_name, birth_number, birth_number_hash, birth_number_masked, birth_date,
                    shared_household_proved, other_parent_not_claimed_proved, evidence_ref, order_index
               FROM tax_profile_children WHERE supplier_id = ? AND year = ? ORDER BY order_index, id'
        );
        $stmt->execute([$supplierId, $year]);
        $out = [];
        $monthStmt = $this->db->pdo()->prepare(
            'SELECT month, child_order, ztpp, claimed FROM tax_profile_child_months WHERE child_id = ? ORDER BY month'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $monthStmt->execute([(int) $r['id']]);
            $months = array_map(static fn (array $m): array => [
                'month' => (int) $m['month'],
                'order' => (int) $m['child_order'],
                'ztpp' => (bool) $m['ztpp'],
                'claimed' => (bool) $m['claimed'],
            ], $monthStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            $out[] = [
                'id' => (int) $r['id'],
                'first_name' => (string) $r['first_name'],
                'last_name' => (string) $r['last_name'],
                ...$this->publicBirthNumber($r),
                'birth_date' => $r['birth_date'] === null ? null : (string) $r['birth_date'],
                'shared_household_proved' => (bool) $r['shared_household_proved'],
                'other_parent_not_claimed_proved' => (bool) $r['other_parent_not_claimed_proved'],
                'evidence_ref' => $r['evidence_ref'] === null ? null : (string) $r['evidence_ref'],
                'order_index' => (int) $r['order_index'],
                'months' => $months,
            ];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    private function spouseClaim(int $supplierId, int $year): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, first_name, last_name, birth_number, birth_number_hash, birth_number_masked, birth_date,
                    eligible_months, ztpp, own_income, income_proved, shared_household_proved,
                    child_under_three_proved, evidence_ref
               FROM tax_profile_spouse_claims WHERE supplier_id = ? AND year = ?'
        );
        $stmt->execute([$supplierId, $year]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        return [
            'id' => (int) $r['id'],
            'first_name' => (string) $r['first_name'],
            'last_name' => (string) $r['last_name'],
            ...$this->publicBirthNumber($r),
            'birth_date' => $r['birth_date'] === null ? null : (string) $r['birth_date'],
            'eligible_months' => (int) $r['eligible_months'],
            'ztpp' => (bool) $r['ztpp'],
            'own_income' => round((float) $r['own_income'], 2),
            'income_proved' => (bool) $r['income_proved'],
            'shared_household_proved' => (bool) $r['shared_household_proved'],
            'child_under_three_proved' => (bool) $r['child_under_three_proved'],
            'evidence_ref' => $r['evidence_ref'] === null ? null : (string) $r['evidence_ref'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function osvcMonths(int $supplierId, int $year): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT month, activity_status, social_participates, health_minimum_applies, state_insured,
                    employed, new_osvc, assessment_base, note
               FROM supplier_osvc_month_statuses WHERE supplier_id = ? AND year = ? ORDER BY month'
        );
        $stmt->execute([$supplierId, $year]);
        return array_map(static fn (array $r): array => [
            'month' => (int) $r['month'],
            'activity_status' => (string) $r['activity_status'],
            'social_participates' => (bool) $r['social_participates'],
            'health_minimum_applies' => (bool) $r['health_minimum_applies'],
            'state_insured' => (bool) $r['state_insured'],
            'employed' => (bool) $r['employed'],
            'new_osvc' => (bool) $r['new_osvc'],
            'assessment_base' => $r['assessment_base'] === null ? null : round((float) $r['assessment_base'], 2),
            'note' => $r['note'] === null ? null : (string) $r['note'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param array<string,mixed> $data */
    private function replaceRelations(int $supplierId, int $year, array $data): void
    {
        $pdo = $this->db->pdo();
        if (array_key_exists('activities', $data) && is_array($data['activities'])) {
            $pdo->prepare('DELETE FROM tax_profile_activities WHERE supplier_id = ? AND year = ?')->execute([$supplierId, $year]);
            $stmt = $pdo->prepare(
                'INSERT INTO tax_profile_activities
                    (supplier_id, year, name, nace_code, expense_mode, expense_rate, income_amount,
                     expense_amount, active_months, allocation_note, order_index)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach (array_values($data['activities']) as $idx => $a) {
                if (!is_array($a)) {
                    continue;
                }
                $rate = (int) ($a['expense_rate'] ?? 60);
                $stmt->execute([
                    $supplierId, $year, trim((string) ($a['name'] ?? '')), preg_replace('/\D/', '', (string) ($a['nace_code'] ?? '')),
                    ($a['expense_mode'] ?? 'pausal') === 'actual' ? 'actual' : 'pausal',
                    in_array($rate, [30, 40, 60, 80], true) ? $rate : 60,
                    max(0, (float) ($a['income'] ?? 0)), max(0, (float) ($a['expenses'] ?? 0)),
                    max(0, min(12, (int) ($a['active_months'] ?? 12))),
                    trim((string) ($a['allocation_note'] ?? '')) ?: null, $idx,
                ]);
            }
        }

        if (array_key_exists('children', $data) && is_array($data['children'])) {
            // Řádky dětí se přepisují celé (DELETE + INSERT), takže dostanou nová id.
            // Šifra je vázaná na id řádku: uložené rodné číslo, které klient neposlal
            // znovu (dostal jen masku), se proto převezme odhalením a novým zapečetěním.
            $previous = $this->sealedRows('tax_profile_children', $supplierId, $year);
            $children = [];
            $seenHashes = [];
            foreach (array_values($data['children']) as $idx => $child) {
                if (!is_array($child)) {
                    continue;
                }
                $birthNumber = $this->incomingBirthNumber($child, $previous, $supplierId);
                if ($birthNumber !== null) {
                    $hash = $this->sensitiveData->lookupHash($birthNumber, self::FAMILY_FIELD, $supplierId);
                    if (isset($seenHashes[$hash])) {
                        throw new \InvalidArgumentException(
                            'Stejné rodné číslo je v daňovém profilu u dvou dětí. Každé dítě uveďte jen jednou.',
                        );
                    }
                    $seenHashes[$hash] = true;
                }
                $children[] = [$idx, $child, $birthNumber];
            }
            $pdo->prepare('DELETE FROM tax_profile_children WHERE supplier_id = ? AND year = ?')->execute([$supplierId, $year]);
            $childStmt = $pdo->prepare(
                'INSERT INTO tax_profile_children
                    (supplier_id, year, first_name, last_name, birth_number_hash, birth_number_masked, birth_date,
                     shared_household_proved, other_parent_not_claimed_proved, evidence_ref, order_index)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $monthStmt = $pdo->prepare(
                'INSERT INTO tax_profile_child_months (child_id, month, child_order, ztpp, claimed) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($children as [$idx, $child, $birthNumber]) {
                $birthDate = trim((string) ($child['birth_date'] ?? '')) ?: null;
                $childStmt->execute([
                    $supplierId, $year, trim((string) ($child['first_name'] ?? '')), trim((string) ($child['last_name'] ?? '')),
                    $birthNumber === null ? null : $this->sensitiveData->lookupHash($birthNumber, self::FAMILY_FIELD, $supplierId),
                    $birthNumber === null ? null : $this->sensitiveData->mask($birthNumber, self::FAMILY_FIELD),
                    $birthDate, !empty($child['shared_household_proved']) ? 1 : 0,
                    !empty($child['other_parent_not_claimed_proved']) ? 1 : 0,
                    trim((string) ($child['evidence_ref'] ?? '')) ?: null, $idx,
                ]);
                $childId = (int) $pdo->lastInsertId();
                if ($birthNumber !== null) {
                    $this->sealBirthNumber('tax_profile_children', $supplierId, $childId, $birthNumber);
                }
                foreach ((array) ($child['months'] ?? []) as $m) {
                    if (!is_array($m) || empty($m['claimed'])) {
                        continue;
                    }
                    $monthStmt->execute([$childId, max(1, min(12, (int) ($m['month'] ?? 1))),
                        max(1, min(3, (int) ($m['order'] ?? 1))), !empty($m['ztpp']) ? 1 : 0, 1]);
                }
            }
        }

        if (array_key_exists('spouse_claim', $data)) {
            $s = $data['spouse_claim'];
            $birthNumber = is_array($s)
                ? $this->incomingBirthNumber($s, $this->sealedRows('tax_profile_spouse_claims', $supplierId, $year), $supplierId)
                : null;
            $pdo->prepare('DELETE FROM tax_profile_spouse_claims WHERE supplier_id = ? AND year = ?')->execute([$supplierId, $year]);
            if (is_array($s) && (int) ($s['eligible_months'] ?? 0) > 0) {
                $pdo->prepare(
                    'INSERT INTO tax_profile_spouse_claims
                        (supplier_id, year, first_name, last_name, birth_number_hash, birth_number_masked, birth_date,
                         eligible_months, ztpp, own_income, income_proved, shared_household_proved,
                         child_under_three_proved, evidence_ref)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $supplierId, $year, trim((string) ($s['first_name'] ?? '')), trim((string) ($s['last_name'] ?? '')),
                    $birthNumber === null ? null : $this->sensitiveData->lookupHash($birthNumber, self::FAMILY_FIELD, $supplierId),
                    $birthNumber === null ? null : $this->sensitiveData->mask($birthNumber, self::FAMILY_FIELD),
                    trim((string) ($s['birth_date'] ?? '')) ?: null,
                    max(0, min(12, (int) ($s['eligible_months'] ?? 0))), !empty($s['ztpp']) ? 1 : 0,
                    max(0, (float) ($s['own_income'] ?? 0)), !empty($s['income_proved']) ? 1 : 0,
                    !empty($s['shared_household_proved']) ? 1 : 0, !empty($s['child_under_three_proved']) ? 1 : 0,
                    trim((string) ($s['evidence_ref'] ?? '')) ?: null,
                ]);
                if ($birthNumber !== null) {
                    $this->sealBirthNumber('tax_profile_spouse_claims', $supplierId, (int) $pdo->lastInsertId(), $birthNumber);
                }
            }
        }

        if (array_key_exists('osvc_months', $data) && is_array($data['osvc_months'])) {
            $pdo->prepare('DELETE FROM supplier_osvc_month_statuses WHERE supplier_id = ? AND year = ?')->execute([$supplierId, $year]);
            $stmt = $pdo->prepare(
                'INSERT INTO supplier_osvc_month_statuses
                    (supplier_id, year, month, activity_status, social_participates, health_minimum_applies,
                     state_insured, employed, new_osvc, assessment_base, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($data['osvc_months'] as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $status = (string) ($m['activity_status'] ?? 'inactive');
                $stmt->execute([$supplierId, $year, max(1, min(12, (int) ($m['month'] ?? 1))),
                    in_array($status, ['inactive', 'main', 'secondary'], true) ? $status : 'inactive',
                    !empty($m['social_participates']) ? 1 : 0, !empty($m['health_minimum_applies']) ? 1 : 0,
                    !empty($m['state_insured']) ? 1 : 0, !empty($m['employed']) ? 1 : 0, !empty($m['new_osvc']) ? 1 : 0,
                    isset($m['assessment_base']) && $m['assessment_base'] !== '' ? max(0, (float) $m['assessment_base']) : null,
                    trim((string) ($m['note'] ?? '')) ?: null]);
            }
        }
    }

    /**
     * Otevřená rodná čísla dětí a manžela/manželky za rok, po id řádku.
     *
     * Jediná cesta, kudy otevřená hodnota z profilu odchází, a proto bez výchozího
     * účelu: volající prohlašuje, kam hodnota poteče ({@see PayrollRevealPurpose}).
     * Řádek ještě nepřevedený migrací 2160 vrátí dosavadní otevřenou hodnotu.
     * Nesouhlasí-li dešifrovaná hodnota s otiskem, je řádek poškozený nebo
     * podstrčený a odhalení selže celé.
     *
     * @return array{children: array<int,string>, spouse: array<int,string>}
     */
    public function familyBirthNumbers(int $supplierId, int $year, PayrollRevealPurpose $purpose): array
    {
        $out = ['children' => [], 'spouse' => []];
        foreach (['children' => 'tax_profile_children', 'spouse' => 'tax_profile_spouse_claims'] as $key => $table) {
            foreach ($this->sealedRows($table, $supplierId, $year) as $id => $row) {
                $plain = $this->openRow($row, $supplierId, $purpose);
                if ($plain !== null) {
                    $out[$key][$id] = $plain;
                }
            }
        }

        return $out;
    }

    /**
     * Veřejná podoba rodného čísla řádku: nikdy otevřená hodnota, jen maska
     * a příznak, že číslo je uložené. Řádek ještě nepřevedený migrací 2160 se
     * maskuje za běhu.
     *
     * @param array<string,mixed> $row
     * @return array{birth_number:null, birth_number_masked:?string, has_birth_number:bool}
     */
    private function publicBirthNumber(array $row): array
    {
        $legacy = $row['birth_number'] ?? null;
        if ($row['birth_number_hash'] !== null) {
            return [
                'birth_number' => null,
                'birth_number_masked' => $row['birth_number_masked'] === null ? null : (string) $row['birth_number_masked'],
                'has_birth_number' => true,
            ];
        }
        if (is_string($legacy) && trim($legacy) !== '') {
            return [
                'birth_number' => null,
                'birth_number_masked' => $this->sensitiveData->mask($legacy, self::FAMILY_FIELD),
                'has_birth_number' => true,
            ];
        }

        return ['birth_number' => null, 'birth_number_masked' => null, 'has_birth_number' => false];
    }

    /**
     * Uložené rodné číslo řádků roku (šifrový text, otisk, případně dosud otevřená
     * hodnota před převodem), po id řádku.
     *
     * @return array<int,array{id:int,ciphertext:?string,hash:?string,legacy:?string}>
     */
    private function sealedRows(string $table, int $supplierId, int $year): array
    {
        if (!in_array($table, ['tax_profile_children', 'tax_profile_spouse_claims'], true)) {
            throw new \LogicException('Neznámá tabulka rodných čísel daňového profilu.');
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, birth_number, birth_number_ciphertext, birth_number_hash
               FROM {$table} WHERE supplier_id = ? AND year = ? ORDER BY id"
        );
        $stmt->execute([$supplierId, $year]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'],
                'ciphertext' => $r['birth_number_ciphertext'] === null ? null : (string) $r['birth_number_ciphertext'],
                'hash' => $r['birth_number_hash'] === null ? null : (string) $r['birth_number_hash'],
                'legacy' => $r['birth_number'] === null || trim((string) $r['birth_number']) === ''
                    ? null
                    : (string) $r['birth_number'],
            ];
        }

        return $out;
    }

    /**
     * Rodné číslo, které se má po uložení u řádku držet.
     *
     *  - nové číslo v `birth_number` (ne maska vrácená klientem) → to se zapečetí;
     *  - `birth_number_clear: true` → číslo se odebere (dítě jen s datem narození);
     *  - jinak se ponechá uložené číslo řádku se shodným `id`. Klient rodné číslo
     *    nikdy nedostal, takže ho při běžném uložení profilu ani nepošle;
     *  - řádek bez `id` (nové dítě) bez čísla zůstane bez čísla.
     *
     * Text, ze kterého po odstranění oddělovačů nezbude žádná číslice, číslo
     * odebere stejně jako dřív.
     *
     * @param array<string,mixed> $input
     * @param array<int,array{id:int,ciphertext:?string,hash:?string,legacy:?string}> $previous
     */
    private function incomingBirthNumber(array $input, array $previous, int $supplierId): ?string
    {
        $raw = $input['birth_number'] ?? null;
        $raw = is_scalar($raw) ? trim((string) $raw) : '';
        if ($raw !== '' && !str_contains($raw, self::MASK_CHAR)) {
            $digits = preg_replace('/\D/', '', $raw) ?? '';

            return $digits === '' ? null : $digits;
        }
        if (!empty($input['birth_number_clear'])) {
            return null;
        }
        $id = isset($input['id']) && is_numeric($input['id']) ? (int) $input['id'] : 0;
        if ($id <= 0 || !isset($previous[$id])) {
            return null;
        }

        return $this->openRow($previous[$id], $supplierId, PayrollRevealPurpose::RESEAL_NEW_VERSION);
    }

    /**
     * @param array{id:int,ciphertext:?string,hash:?string,legacy:?string} $row
     */
    private function openRow(array $row, int $supplierId, PayrollRevealPurpose $purpose): ?string
    {
        if ($row['ciphertext'] === null) {
            return $row['legacy'];
        }
        $plain = $this->sensitiveData->reveal(
            $row['ciphertext'],
            self::FAMILY_FIELD,
            $supplierId,
            $row['id'],
            $purpose,
        );
        if ($row['hash'] === null
            || !hash_equals($row['hash'], $this->sensitiveData->lookupHash($plain, self::FAMILY_FIELD, $supplierId))
        ) {
            throw new \DomainException('Integrita rodného čísla v daňovém profilu nebyla ověřena.');
        }

        return $plain;
    }

    /** Zapečetí rodné číslo právě založeného řádku (šifra je vázaná na jeho id). */
    private function sealBirthNumber(string $table, int $supplierId, int $rowId, string $plaintext): void
    {
        if (!in_array($table, ['tax_profile_children', 'tax_profile_spouse_claims'], true)) {
            throw new \LogicException('Neznámá tabulka rodných čísel daňového profilu.');
        }
        $sealed = $this->sensitiveData->seal($plaintext, self::FAMILY_FIELD, $supplierId, $rowId);
        $update = $this->db->pdo()->prepare(
            "UPDATE {$table}
                SET birth_number = NULL, birth_number_ciphertext = ?, birth_number_hash = ?, birth_number_masked = ?
              WHERE supplier_id = ? AND id = ?"
        );
        $update->execute([$sealed->ciphertext, $sealed->lookupHash, $sealed->masked, $supplierId, $rowId]);
        if ($update->rowCount() !== 1) {
            throw new \RuntimeException('Rodné číslo v daňovém profilu se nepodařilo zapečetit.');
        }
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['year'] = (int) $r['year'];
        $r['activity_rate'] = (int) $r['activity_rate'];
        $r['use_actual_expenses'] = (bool) $r['use_actual_expenses'];
        $r['actual_expenses'] = (float) $r['actual_expenses'];
        $r['is_secondary'] = (bool) $r['is_secondary'];
        $r['spouse_credit'] = (bool) $r['spouse_credit'];
        $r['children_count'] = (int) $r['children_count'];
        $r['mortgage_interest'] = (float) $r['mortgage_interest'];
        $r['mortgage_pre_2021'] = (bool) ($r['mortgage_pre_2021'] ?? 0);
        $r['mortgage_months'] = (int) ($r['mortgage_months'] ?? 12);
        $r['pension_contrib'] = (float) $r['pension_contrib'];
        $r['life_insurance'] = (float) $r['life_insurance'];
        $r['dip_contrib'] = (float) ($r['dip_contrib'] ?? 0);
        $r['long_term_care'] = (float) ($r['long_term_care'] ?? 0);
        $r['disability_12_months'] = (int) ($r['disability_12_months'] ?? 0);
        $r['disability_3_months'] = (int) ($r['disability_3_months'] ?? 0);
        $r['ztpp_months'] = (int) ($r['ztpp_months'] ?? 0);
        $r['donations'] = (float) $r['donations'];
        $r['sickness_insured'] = (bool) ($r['sickness_insured'] ?? 0);
        $r['sickness_monthly_base'] = isset($r['sickness_monthly_base']) && $r['sickness_monthly_base'] !== null
            ? (int) $r['sickness_monthly_base']
            : null;
        return $r;
    }
}

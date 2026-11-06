<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountDefinitionRepository;
use PDO;

/**
 * Účet závazku přijatého dokladu — jediné místo, které ho určuje pro zaúčtování
 * i pro úhrady.
 *
 * Zaúčtování ({@see forPosting}): účtenka / paragon (`document_kind = 'receipt'`) jde
 * na účet z nastavení firmy `supplier.receipt_payable_account_code` (typicky 325), pokud
 * je vyplněný a předkontace dokladu nese výchozí závazek. Ručně zvolená předkontace
 * s jiným závazkem zůstává, jak ji účetní vybrala. Prázdné nastavení = beze změny.
 *
 * Úhrada ({@see forPayment}): odúčtuje se účet, na kterém závazek dokladu SKUTEČNĚ
 * vznikl (živý zápis předpisu v deníku), ne pevný účet z kontace úhrady. Bez toho by
 * účtenka zaúčtovaná 518/325 dostala úhradu 321/211 a saldo by se rozpadlo na dva účty;
 * totéž platí pro doklad s ručně zvolenou předkontací na jiný závazek. Leží-li závazek
 * na stejné syntetice jako účet z kontace úhrady, vrací se účet z kontace beze změny —
 * dosavadní úhrady (321, analytiky 321 dohledávané bankou) se tím nemění.
 *
 * Kde jinde žije týž pojem: párování v deníku ({@see JournalSettlementService::SETTLE_ACCOUNTS})
 * a saldokonto ({@see Reports\SaldoService::DEFAULT_ACCOUNTS}) 325 vedou, kontrola dokladů
 * po splatnosti ({@see Reports\DocumentCompletenessService}) bere nastavený účet navíc.
 * Záměrně beze změny zůstávají otevírací položky převodu ({@see \MyInvoice\Repository\SaldoRepository}
 * staví přijaté doklady počátečního stavu vždy na 321 — tak je zakládá převod) a uzávěrková
 * kontrola uhrazených dokladů s otevřeným saldem v ClosingRepository, která hlídá jen 321:
 * účtenka na 325 v ní chybí, ale saldo 325 kontroluje saldokonto.
 */
final class PurchasePayableAccount
{
    /** Výchozí závazek přijatého dokladu, když předkontace žádný neuvádí. */
    public const DEFAULT_PAYABLE = '321';

    /**
     * Syntetiky třídy 3, které v zápisu přijatého dokladu nejsou jeho závazkem:
     * DPH (343) a zúčtování poskytnuté zálohy (314).
     */
    private const NON_PAYABLE_SYNTHETICS = ['343', '314'];

    public function __construct(private readonly Connection $db) {}

    /** Nastavený účet závazku pro účtenky, nebo null (= výchozí chování). */
    public function receiptSetting(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT receipt_payable_account_code FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $code = $stmt->fetchColumn();
        return is_string($code) && trim($code) !== '' ? trim($code) : null;
    }

    /**
     * Ověří volbu účtu závazku pro účtenky. Prázdná hodnota = výchozí chování (null).
     * Účet musí být aktivní v osnově firmy, závazkový (pasivní) a z třídy 3; DPH (343)
     * ani zálohy (314) závazkem dokladu být nesmí.
     *
     * @throws PostingException 422
     */
    public function validateReceiptSetting(int $supplierId, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !is_int($value)) {
            throw new PostingException('invalid_account', 'Účet závazku pro účtenky musí být číslo účtu.', 422, ['field' => 'receipt_payable_account_code']);
        }
        $code = trim((string) $value);
        if ($code === '') {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT account_code, account_type FROM '
                . AccountDefinitionRepository::relation(date('Y-m-d'), $supplierId, $this->db->pdo()->inTransaction())
                . ' a WHERE supplier_id = ? AND account_code = ? AND is_active = 1'
        );
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new PostingException('invalid_account', 'Účet ' . $code . ' v účtové osnově firmy není.', 422, ['field' => 'receipt_payable_account_code']);
        }
        if ((string) $row['account_type'] !== 'liability'
            || !str_starts_with($code, '3')
            || in_array(substr($code, 0, 3), self::NON_PAYABLE_SYNTHETICS, true)
        ) {
            throw new PostingException(
                'invalid_account',
                'Účet ' . $code . ' není účet závazku. Zvolte závazkový účet třídy 3, například 321 nebo 325.',
                422,
                ['field' => 'receipt_payable_account_code'],
            );
        }
        return (string) $row['account_code'];
    }

    /**
     * Účet závazku pro zápis přijatého dokladu.
     *
     * @param array<string,mixed> $pi hlavička dokladu (document_kind)
     * @param string $rulePayable závazek z použité předkontace dokladu
     * @param string $defaultPayable závazek výchozí předkontace přijatých dokladů
     */
    public function forPosting(int $supplierId, array $pi, string $rulePayable, string $defaultPayable): string
    {
        if ((string) ($pi['document_kind'] ?? '') !== 'receipt' || $rulePayable !== $defaultPayable) {
            return $rulePayable;
        }
        return $this->receiptSetting($supplierId) ?? $rulePayable;
    }

    /**
     * Účet, který úhrada přijatého dokladu odúčtuje: závazek z živého zápisu dokladu,
     * jinak $fallback (účet z kontace úhrady). Nezaúčtovaný doklad, zápis bez závazku
     * nebo s víc různými závazky → $fallback.
     */
    public function forPayment(int $supplierId, int $purchaseInvoiceId, string $fallback): string
    {
        $posted = $this->postedPayable($supplierId, $purchaseInvoiceId);
        if ($posted === null || substr($posted, 0, 3) === substr($fallback, 0, 3)) {
            return $fallback;
        }
        return $posted;
    }

    /**
     * Účet závazku v živém zápisu přijatého dokladu, nebo null. Doklad se skupinou
     * zaúčtování (převzaté účetnictví, víc zápisů) vrací null: závazek tam může nést
     * jiný člen než přímý zápis a saldo skupiny dohledává banka sama (predpisSaldo).
     */
    public function postedPayable(int $supplierId, int $purchaseInvoiceId): ?string
    {
        $group = (new DocumentPostingGroup($this->db))->active($supplierId, 'purchase_invoice', $purchaseInvoiceId);
        if (DocumentPostingGroup::hasOwnedEntries($group)
            && !DocumentPostingGroup::isSingleDirectEntry($group, 'purchase_invoice', $purchaseInvoiceId)
        ) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT a.account_code
               FROM journal_entries je
               JOIN journal_entry_lines l ON l.entry_id = je.id AND l.supplier_id = je.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE je.supplier_id = ? AND je.source_type = 'purchase_invoice' AND je.source_id = ?
                AND je.reversed_by IS NULL
                AND a.account_code LIKE '3%'"
        );
        $stmt->execute([$supplierId, $purchaseInvoiceId]);
        $codes = array_values(array_filter(
            array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []),
            static fn (string $code): bool => !in_array(substr($code, 0, 3), self::NON_PAYABLE_SYNTHETICS, true),
        ));
        return count($codes) === 1 ? $codes[0] : null;
    }
}

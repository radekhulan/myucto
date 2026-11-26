<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\Card\CardSettlementService;
use PDO;

/**
 * Účet poskytnuté zálohy přijatého dokladu (314): jediné místo, které ho určuje pro
 * úhradu zálohy, pro daň z daňového dokladu k platbě (DDKP) i pro zúčtování zálohy
 * konečnou fakturou.
 *
 * Karta dodavatele může nést vlastní účty (`clients.advance_paid_account_code`,
 * `clients.advance_paid_vat_account_code`), typicky analytiku zálohy 314.610 a analytiku
 * daně z DDKP 314.611. Prázdná karta = předkontace firmy beze změny.
 *
 *   - Úhrada zálohy ({@see forPayment}): banka, pokladna, zápočet. Účet zálohy dodavatele,
 *     jinak MD pravidla `advance.paid.payment` (314).
 *   - DDKP ({@see forVatDocument}): účet DPH zálohy dodavatele, jinak D pravidla
 *     `advance.paid.vatdocument` (314). Účet se z karty neodvozuje (žádné „sousední
 *     analytiky“), platí jen to, co je na kartě výslovně vyplněné.
 *   - Zúčtování konečnou fakturou ({@see settlementCredit}, {@see postedVatDocumentCredits}):
 *     odúčtuje se účet, na který úhrada zálohy SKUTEČNĚ šla (živé zápisy banky a pokladny),
 *     a daň DDKP se převede z účtu, na který ji DDKP skutečně připsal, takže obě analytiky
 *     skončí na nule. Bez zaúčtované úhrady platí karta dodavatele a pak předkontace.
 *
 * Karta se uplatní jen tam, kde předkontace firmy vede zálohy na 314 a doklad se účtuje
 * výchozím pravidlem: firma, která zálohy účtuje rovnou na saldokonto (321), nebo ručně
 * zvolená předkontace s jiným účtem zůstávají, jak je nastavila účetní.
 */
final class AdvancePaidAccount
{
    public const PAYMENT_RULE = 'advance.paid.payment';
    public const VAT_DOCUMENT_RULE = 'advance.paid.vatdocument';
    public const DEFAULT_ACCOUNT = '314';

    public const FIELDS = ['advance_paid_account_code', 'advance_paid_vat_account_code'];

    public function __construct(
        private readonly Connection $db,
        private readonly ?PostingRuleRepository $rules = null,
    ) {}

    /**
     * Účty z karty dodavatele dokladu.
     *
     * @return array{payment:?string, vat_document:?string}
     */
    public function vendorAccounts(int $supplierId, int $purchaseInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.advance_paid_account_code, c.advance_paid_vat_account_code
               FROM purchase_invoices pi
               JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = pi.supplier_id
              WHERE pi.supplier_id = ? AND pi.id = ?'
        );
        $stmt->execute([$supplierId, $purchaseInvoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'payment' => self::code($row['advance_paid_account_code'] ?? null),
            'vat_document' => self::code($row['advance_paid_vat_account_code'] ?? null),
        ];
    }

    /** MD pravidla úhrady poskytnuté zálohy (předkontace firmy). */
    public function rulePaymentAccount(int $supplierId): string
    {
        $rule = $this->rules()->resolve($supplierId, self::PAYMENT_RULE);
        $code = $rule['debit_account_code'] ?? null;
        return is_string($code) && $code !== '' ? $code : self::DEFAULT_ACCOUNT;
    }

    /**
     * Účet, na který jde úhrada poskytnuté zálohy (banka, pokladna, zápočet) dokladu
     * `$purchaseInvoiceId`: účet zálohy z karty dodavatele, jinak předkontace firmy.
     */
    public function forPayment(int $supplierId, ?int $purchaseInvoiceId): string
    {
        $rule = $this->rulePaymentAccount($supplierId);
        if ($purchaseInvoiceId === null || $purchaseInvoiceId <= 0 || !self::onAdvanceSynthetic($rule)) {
            return $rule;
        }
        return $this->vendorAccounts($supplierId, $purchaseInvoiceId)['payment'] ?? $rule;
    }

    /**
     * Účet, ze kterého DDKP `$taxDocumentId` čerpá daň (D). `$ruleCredit` je D použité
     * předkontace, `$defaultCredit` D výchozí předkontace DDKP: ručně zvolená předkontace
     * s jiným účtem zůstává.
     */
    public function forVatDocument(int $supplierId, int $taxDocumentId, string $ruleCredit, string $defaultCredit): string
    {
        if ($ruleCredit !== $defaultCredit || !self::onAdvanceSynthetic($ruleCredit)) {
            return $ruleCredit;
        }
        return $this->vendorAccounts($supplierId, $taxDocumentId)['vat_document'] ?? $ruleCredit;
    }

    /**
     * Účet zálohy, který konečná faktura při zúčtování zálohy `$advanceId` odúčtuje (D).
     * Přednost má účet, na který úhrada zálohy v deníku skutečně šla, potom karta
     * dodavatele, nakonec D předkontace zúčtování `$ruleCredit`. Leží-li úhrada na
     * stejném účtu jako předkontace (po přesměrování syntetiky na její jedinou analytiku),
     * vrací se kód z předkontace beze změny.
     *
     * @param \Closure(string):string $redirect přesměrování syntetiky na jedinou analytiku
     */
    public function settlementCredit(int $supplierId, int $advanceId, string $ruleCredit, \Closure $redirect): string
    {
        if (!self::onAdvanceSynthetic($ruleCredit)) {
            return $ruleCredit;
        }
        $candidate = $this->postedPaymentAccount($supplierId, $advanceId, substr($ruleCredit, 0, 3))
            ?? $this->vendorAccounts($supplierId, $advanceId)['payment'];
        if ($candidate === null || $candidate === $ruleCredit || $redirect($candidate) === $redirect($ruleCredit)) {
            return $ruleCredit;
        }
        return $candidate;
    }

    /**
     * Účet syntetiky `$synthetic`, na který živé zápisy úhrad zálohy (banka, karta,
     * pokladna) účtovaly MD. Víc různých účtů (hromadná úhrada záloh víc dodavatelů jedním
     * pohybem, změna nastavení mezi úhradami) nebo žádný = null.
     */
    public function postedPaymentAccount(int $supplierId, int $advanceId, string $synthetic = self::DEFAULT_ACCOUNT): ?string
    {
        $card = CardSettlementService::SOURCE_SETTLEMENT;
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT a.account_code
               FROM payment_matches pm
               JOIN journal_entries je ON je.supplier_id = pm.supplier_id
                    AND je.source_type IN ('bank', '{$card}') AND je.source_id = pm.bank_transaction_id
                    AND je.reversed_by IS NULL
               JOIN journal_entry_lines l ON l.entry_id = je.id AND l.supplier_id = je.supplier_id
                    AND l.side = 'debit' AND l.is_red_storno = 0
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE pm.supplier_id = ? AND pm.purchase_invoice_id = ? AND a.account_code LIKE ?
             UNION
             SELECT a.account_code
               FROM cash_documents cd
               JOIN journal_entries je ON je.supplier_id = cd.supplier_id
                    AND je.source_type = 'cash' AND je.source_id = cd.id AND je.reversed_by IS NULL
               JOIN journal_entry_lines l ON l.entry_id = je.id AND l.supplier_id = je.supplier_id
                    AND l.side = 'debit' AND l.is_red_storno = 0
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE cd.supplier_id = ? AND cd.purchase_invoice_id = ? AND a.account_code LIKE ?"
        );
        $like = $synthetic . '%';
        $stmt->execute([$supplierId, $advanceId, $like, $supplierId, $advanceId, $like]);
        $codes = array_values(array_unique(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
        return count($codes) === 1 ? $codes[0] : null;
    }

    /**
     * Daň, kterou živé zápisy DDKP `$taxDocumentIds` připsaly na účty syntetiky
     * `$synthetic`: účet => D − MD (v Kč, se znaménkem).
     *
     * @param list<int> $taxDocumentIds
     * @return array<string,float>
     */
    public function postedVatDocumentCredits(int $supplierId, array $taxDocumentIds, string $synthetic = self::DEFAULT_ACCOUNT): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $taxDocumentIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code,
                    SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END) AS amount
               FROM journal_entries je
               JOIN journal_entry_lines l ON l.entry_id = je.id AND l.supplier_id = je.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE je.supplier_id = ? AND je.source_type = 'purchase_invoice' AND je.source_id IN ({$in})
                AND je.reversed_by IS NULL AND a.account_code LIKE ?
              GROUP BY a.account_code
              ORDER BY a.account_code"
        );
        $stmt->execute([$supplierId, ...$ids, $synthetic . '%']);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amount = round((float) $row['amount'], 2);
            if ((int) round($amount * 100) !== 0) {
                $out[(string) $row['account_code']] = $amount;
            }
        }
        return $out;
    }

    /**
     * Ověří účet z karty dodavatele. Prázdná hodnota = předkontace firmy (null). Účet musí
     * být aktivní v osnově firmy, patřit pod syntetiku 314 a dát se na něj účtovat
     * (syntetika s víc analytikami ne, vybírá se analytika).
     *
     * @throws PostingException 422
     */
    public function validateVendorSetting(int $supplierId, mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !is_int($value)) {
            throw new PostingException('invalid_account', 'Účet zálohy musí být číslo účtu.', 422, ['field' => $field]);
        }
        $code = trim((string) $value);
        if ($code === '') {
            return null;
        }
        if (!SaldoAccountMatch::belongsTo($code, self::DEFAULT_ACCOUNT)) {
            throw new PostingException(
                'invalid_account',
                'Účet ' . $code . ' není účet poskytnutých záloh. Zvolte analytiku účtu 314, například 314.610.',
                422,
                ['field' => $field],
            );
        }
        $accounts = new ChartOfAccountsRepository($this->db);
        $account = $accounts->findByCode($supplierId, $code);
        if ($account === null || empty($account['is_active'])) {
            throw new PostingException('invalid_account', 'Účet ' . $code . ' v účtové osnově firmy není.', 422, ['field' => $field]);
        }
        $children = array_filter(
            $accounts->childrenOf($supplierId, (int) $account['id']),
            static fn (array $child): bool => !empty($child['is_active']),
        );
        if (count($children) > 1) {
            throw new PostingException(
                'account_not_posting_level',
                'Účet ' . $code . ' má analytiky. Vyberte analytický účet.',
                422,
                ['field' => $field],
            );
        }
        return (string) $account['account_code'];
    }

    /**
     * Ověřené změny účtů záloh z těla požadavku na kartu dodavatele: jen klíče, které tělo
     * obsahuje a které se liší od uložené karty `$stored` (vynechaný nebo stejný klíč =
     * beze změny, uložený účet se znovu neověřuje), null = předkontace firmy.
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed> $stored
     * @return array<string,?string>
     * @throws PostingException 422
     */
    public function vendorSettingsFromBody(int $supplierId, array $body, array $stored = []): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $raw = $body[$field];
            $trimmed = is_string($raw) || is_int($raw) ? self::code((string) $raw) : $raw;
            if ($trimmed === self::code($stored[$field] ?? null)) {
                continue;
            }
            $out[$field] = $this->validateVendorSetting($supplierId, $raw, $field);
        }
        return $out;
    }

    /** Leží účet pod syntetikou poskytnutých záloh 314? */
    public static function onAdvanceSynthetic(string $code): bool
    {
        return SaldoAccountMatch::belongsTo($code, self::DEFAULT_ACCOUNT);
    }

    private function rules(): PostingRuleRepository
    {
        return $this->rules ?? new PostingRuleRepository($this->db);
    }

    private static function code(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

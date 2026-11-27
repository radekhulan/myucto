<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\PostingRuleRepository;
use PDO;

/**
 * Saldokontní účet dokladu podle partnera: jediné místo, které určuje závazek přijatého
 * dokladu a pohledávku vydaného dokladu v zápisu dokladu.
 *
 * Karta partnera může nést vlastní účet závazku (`clients.payable_account_code`, např.
 * 321.200 tuzemské faktury) a pohledávky (`clients.receivable_account_code`, např.
 * 311.200 pachtovné). Firma může mít účet závazku pro zahraniční dodavatele
 * (`supplier.foreign_payable_account_code`, např. 321.300), který platí pro dodavatele
 * z jiné země, než je země firmy, pokud jeho karta účet závazku nenese.
 *
 * Pořadí priorit (závazek přijatého dokladu, {@see purchasePayable}):
 *   0. uhrazený doklad se živým zápisem zůstává na účtu svého zápisu, ať by nový účet
 *      přišel odkudkoli ({@see keptForPaidDocument}),
 *   1. protiúčet zadaný na řádcích dokladu ({@see ItemPostingAccounts}),
 *   2. explicitní předkontace od volajícího (ruční zaúčtování s vybranou `rule_key`),
 *   3. účet závazku z karty dodavatele,
 *   4. firemní účet závazku pro zahraniční dodavatele (země dodavatele ≠ země firmy),
 *   5. dosavadní chování: předkontace dokladu a nastavení účtenek
 *      ({@see PurchasePayableAccount::forPosting}).
 * Pohledávka vydaného dokladu ({@see saleReceivable}) stejně bez kroku 4.
 *
 * Doklad má závazek či pohledávku na JEDNOM účtu (úhrada uzavírá jeden účet), u vydané
 * faktury proto i daň na výstupu: ta se v zápisu neúčtuje vlastní pohledávkou, celý
 * předpis nese jeden řádek pohledávky. Dobropis k zaúčtované faktuře jde na účet, na
 * kterém faktura v deníku skutečně leží, jinak by se v saldokontu nevyrovnaly. Doklad,
 * který zúčtovává zálohu vedenou přímo na saldokontu (předkontace zúčtování se stejnými
 * účty), zůstává na předkontaci: úhrada zálohy leží na účtu z předkontace.
 *
 * Úhrady účet nepřebírají odsud, ale z živého zápisu dokladu: přijatá strana přes
 * {@see PurchasePayableAccount::forPayment}, vydaná přes dohledání analytiky v předpisu
 * ({@see FxSettlementPosting::predpisSaldo}). Ta umí jen analytiky syntetiky, kterou
 * nese předkontace úhrady pohledávek, proto se účet pohledávky na kartě omezuje na ni
 * stejně jako protiúčet řádku vydané faktury ({@see ItemPostingAccounts::receivableSynthetic}).
 *
 * Bez nastavení na kartě a ve firmě je zápis beze změny.
 */
final class PartnerSettlementAccount
{
    public const PAYABLE_FIELD = 'payable_account_code';
    public const RECEIVABLE_FIELD = 'receivable_account_code';
    public const FOREIGN_PAYABLE_FIELD = 'foreign_payable_account_code';

    /** Sloupce karty partnera. */
    public const FIELDS = [self::PAYABLE_FIELD, self::RECEIVABLE_FIELD];

    /** Syntetiky třídy 3, které závazkem dokladu nejsou: DPH a poskytnuté zálohy. */
    private const NON_PAYABLE_SYNTHETICS = ['343', '314'];

    /** Syntetiky třídy 3, které pohledávkou dokladu nejsou: DPH a zálohy. */
    private const NON_RECEIVABLE_SYNTHETICS = ['343', '314', '324'];

    public function __construct(
        private readonly Connection $db,
        private readonly ?PostingRuleRepository $rules = null,
    ) {}

    /**
     * Závazek pro zápis přijatého dokladu. Podmínky `$keepRule` se vyhodnotí, jen když
     * partner nějaký účet nese, takže bez nastavení zůstává i počet dotazů beze změny.
     *
     * @param array<string,mixed> $pi hlavička dokladu (id, document_kind, parent_purchase_invoice_id)
     * @param string|null $itemCounter protiúčet z řádků dokladu
     * @param \Closure():bool $keepRule explicitní předkontace od volajícího nebo záloha vedená na saldokontu
     * @param \Closure():string $legacy dosavadní závazek (předkontace, nastavení účtenek)
     * @param string|null $postedSaldo závazek zápisu dokladu, který volající právě stornuje
     *                                 (přeúčtování stornem a novým zápisem); null = živý zápis
     */
    public function purchasePayable(
        int $supplierId,
        array $pi,
        ?string $itemCounter,
        \Closure $keepRule,
        \Closure $legacy,
        ?string $postedSaldo = null,
    ): string {
        $id = (int) ($pi['id'] ?? 0);
        $kept = $this->keptForPaidDocument($supplierId, 'purchase_invoice', $id, $postedSaldo);
        if ($kept !== null) {
            return $kept;
        }
        if ($itemCounter !== null) {
            return $itemCounter;
        }
        $partner = $this->purchasePartnerAccount($supplierId, $id);
        if ($partner === null || $keepRule()) {
            return $legacy();
        }
        $payables = new PurchasePayableAccount($this->db);
        $parentId = (int) ($pi['parent_purchase_invoice_id'] ?? 0);
        if ((string) ($pi['document_kind'] ?? '') === 'credit_note' && $parentId > 0) {
            return $payables->postedPayable($supplierId, $parentId) ?? $partner;
        }
        return $partner;
    }

    /**
     * Pohledávka pro zápis vydaného dokladu.
     *
     * @param array<string,mixed> $inv hlavička dokladu (id, invoice_type, parent_invoice_id)
     * @param string|null $itemCounter protiúčet z řádků dokladu
     * @param \Closure():bool $keepRule explicitní předkontace od volajícího nebo záloha vedená na saldokontu
     * @param string $ruleReceivable pohledávka použité předkontace
     * @param \Closure():string $defaultReceivable pohledávka výchozí předkontace téhož druhu dokladu
     * @param string|null $postedSaldo pohledávka zápisu, který volající právě stornuje; null = živý zápis
     */
    public function saleReceivable(
        int $supplierId,
        array $inv,
        ?string $itemCounter,
        \Closure $keepRule,
        string $ruleReceivable,
        \Closure $defaultReceivable,
        ?string $postedSaldo = null,
    ): string {
        $id = (int) ($inv['id'] ?? 0);
        $kept = $this->keptForPaidDocument($supplierId, 'invoice', $id, $postedSaldo);
        if ($kept !== null) {
            return $kept;
        }
        if ($itemCounter !== null) {
            return $itemCounter;
        }
        $partner = $this->customerAccount($supplierId, $id);
        // Hlavičková předkontace s jinou pohledávkou (např. prodej majetku na 315) zůstává.
        if ($partner === null || $keepRule() || $ruleReceivable !== $defaultReceivable()) {
            return $ruleReceivable;
        }
        $synthetic = substr($partner, 0, 3);
        $parentId = (int) ($inv['parent_invoice_id'] ?? 0);
        if ((string) ($inv['invoice_type'] ?? '') === 'credit_note' && $parentId > 0) {
            return $this->postedReceivable($supplierId, $parentId, $synthetic) ?? $partner;
        }
        return $partner;
    }

    /**
     * Účet závazku podle partnera pro přijatý doklad: karta dodavatele, jinak firemní účet
     * pro zahraniční dodavatele, je-li země dodavatele jiná než země firmy. Null = nic.
     */
    public function purchasePartnerAccount(int $supplierId, int $purchaseInvoiceId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::partnerPayableSql('c', 's') . '
               FROM purchase_invoices pi
               JOIN supplier s ON s.id = pi.supplier_id
               LEFT JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = pi.supplier_id
              WHERE pi.supplier_id = ? AND pi.id = ?'
        );
        $stmt->execute([$supplierId, $purchaseInvoiceId]);
        return self::code($stmt->fetchColumn());
    }

    /**
     * SQL výraz účtu závazku podle partnera: karta dodavatele `$client`, jinak firemní účet
     * pro zahraniční dodavatele z `$supplier`, liší-li se země dodavatele od země firmy.
     * NULL = partner nic nenese. Sdílí ho zaúčtování ({@see purchasePartnerAccount}) i karta
     * klienta, ze které editor dokladu ukazuje závazek, který se použije.
     */
    public static function partnerPayableSql(string $client, string $supplier): string
    {
        return "COALESCE(NULLIF(TRIM({$client}.payable_account_code), ''),
                CASE WHEN {$client}.country_id IS NOT NULL AND {$supplier}.country_id IS NOT NULL
                          AND {$client}.country_id <> {$supplier}.country_id
                     THEN NULLIF(TRIM({$supplier}.foreign_payable_account_code), '') END)";
    }

    /** Účet pohledávky z karty odběratele vydaného dokladu, null = nic. */
    public function customerAccount(int $supplierId, int $invoiceId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.receivable_account_code
               FROM invoices i
               JOIN clients c ON c.id = i.client_id AND c.supplier_id = i.supplier_id
              WHERE i.supplier_id = ? AND i.id = ?'
        );
        $stmt->execute([$supplierId, $invoiceId]);
        return self::code($stmt->fetchColumn());
    }

    /** Firemní účet závazku pro zahraniční dodavatele, nebo null. */
    public function foreignSetting(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT foreign_payable_account_code FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return self::code($stmt->fetchColumn());
    }

    /**
     * Syntetiky saldokonta, které vedou karty partnerů a firemní nastavení navíc proti
     * `$known` (pro kontroly saldokonta, které jinak znají jen 311 a 321).
     *
     * @param list<string> $known
     * @return list<string>
     */
    public function extraSynthetics(int $supplierId, array $known): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT LEFT(code, 3) FROM (
                SELECT payable_account_code AS code FROM clients WHERE supplier_id = ? AND payable_account_code IS NOT NULL
                UNION ALL
                SELECT receivable_account_code FROM clients WHERE supplier_id = ? AND receivable_account_code IS NOT NULL
                UNION ALL
                SELECT foreign_payable_account_code FROM supplier WHERE id = ? AND foreign_payable_account_code IS NOT NULL
             ) x WHERE code <> \'\''
        );
        $stmt->execute([$supplierId, $supplierId, $supplierId]);
        return array_values(array_diff(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), $known));
    }

    /**
     * Ověří účet závazku (karta dodavatele, firemní účet pro zahraniční dodavatele).
     * Prázdná hodnota = předkontace (null). Účet musí být aktivní v osnově firmy, pasivní,
     * z třídy 3 mimo DPH (343) a zálohy (314) a dát se na něj účtovat (syntetika s víc
     * analytikami ne).
     *
     * @throws PostingException 422
     */
    public function validatePayable(int $supplierId, mixed $value, string $field): ?string
    {
        $account = $this->accountFor($supplierId, $value, $field);
        if ($account === null) {
            return null;
        }
        $code = (string) $account['account_code'];
        if ((string) $account['account_type'] !== 'liability'
            || !str_starts_with($code, '3')
            || in_array(substr($code, 0, 3), self::NON_PAYABLE_SYNTHETICS, true)
        ) {
            throw new PostingException(
                'invalid_account',
                'Účet ' . $code . ' není účet závazku. Zvolte závazkový účet třídy 3, například 321 nebo jeho analytiku.',
                422,
                ['field' => $field],
            );
        }
        $this->assertPostingLevel($supplierId, $account, $field);
        return $code;
    }

    /**
     * Ověří účet pohledávky z karty odběratele. Prázdná hodnota = předkontace (null). Účet
     * musí být aktivní v osnově firmy, aktivní (pohledávkový), z třídy 3 mimo DPH a zálohy,
     * pod syntetikou předkontace úhrady pohledávek (typicky 311, viz třída) a dát se na
     * něj účtovat.
     *
     * @throws PostingException 422
     */
    public function validateReceivable(int $supplierId, mixed $value, string $field = self::RECEIVABLE_FIELD): ?string
    {
        $account = $this->accountFor($supplierId, $value, $field);
        if ($account === null) {
            return null;
        }
        $code = (string) $account['account_code'];
        $synthetic = (new ItemPostingAccounts($this->db, $this->rules()))->receivableSynthetic($supplierId);
        if ((string) $account['account_type'] !== 'asset'
            || !str_starts_with($code, '3')
            || in_array(substr($code, 0, 3), self::NON_RECEIVABLE_SYNTHETICS, true)
            || substr($code, 0, 3) !== $synthetic
        ) {
            throw new PostingException(
                'invalid_account',
                'Účet ' . $code . ' není účet pohledávky. Zvolte pohledávku ' . $synthetic
                    . ' nebo její analytiku, jinak by fakturu úhrada neuzavřela.',
                422,
                ['field' => $field],
            );
        }
        $this->assertPostingLevel($supplierId, $account, $field);
        return $code;
    }

    /**
     * Ověřené změny účtů z těla požadavku na kartu partnera: jen klíče, které tělo obsahuje
     * a které se liší od uložené karty `$stored` (vynechaný nebo stejný klíč = beze změny),
     * null = předkontace.
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed> $stored
     * @return array<string,?string>
     * @throws PostingException 422
     */
    public function partnerSettingsFromBody(int $supplierId, array $body, array $stored = []): array
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
            $out[$field] = $field === self::PAYABLE_FIELD
                ? $this->validatePayable($supplierId, $raw, $field)
                : $this->validateReceivable($supplierId, $raw, $field);
        }
        return $out;
    }

    /**
     * Saldokontní účet živého zápisu dokladu (závazek přijatého, pohledávka vydaného podle
     * syntetiky předkontace úhrady pohledávek). Volá ho přeúčtování, které zápis nejdřív
     * stornuje, aby uhrazený doklad zůstal na svém účtu ({@see purchasePayable}).
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     */
    public function postedSettlementAccount(int $supplierId, string $sourceType, int $documentId): ?string
    {
        return $sourceType === 'purchase_invoice'
            ? (new PurchasePayableAccount($this->db))->postedPayable($supplierId, $documentId)
            : $this->postedReceivable(
                $supplierId,
                $documentId,
                (new ItemPostingAccounts($this->db, $this->rules()))->receivableSynthetic($supplierId),
            );
    }

    /**
     * Uhrazený (i částečně) doklad se živým zápisem si při opětovném zaúčtování ponechá
     * saldokontní účet svého zápisu, ať by nový účet přišel odkudkoli: předkontace dokladu
     * nebo řádků (i hromadná Změnit předkontaci), protiúčet řádků, karta partnera, firemní
     * účet pro zahraniční dodavatele, účet pro účtenky. Úhrady už ten účet vyrovnaly
     * a přesun by saldo rozdělil na dva účty. Jediné pravidlo pro obě strany: zaúčtování
     * přijatého ({@see purchasePayable}) i vydaného dokladu ({@see saleReceivable}) se ho
     * ptá jako první. Hromadné přeúčtování saldokontního účtu uhrazený doklad vynechá
     * ({@see SettlementAccountRebook}).
     *
     * Neuhrazený doklad a doklad bez živého zápisu: null (jde podle nastavení). Pohledávka
     * se hledá v syntetice předkontace úhrady pohledávek ({@see postedSettlementAccount}).
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     * @param string|null $postedSaldo účet zápisu, který volající právě stornuje; null = živý zápis
     */
    public function keptForPaidDocument(int $supplierId, string $sourceType, int $documentId, ?string $postedSaldo = null): ?string
    {
        if ($documentId <= 0) {
            return null;
        }
        if ($sourceType === 'purchase_invoice') {
            return (new PurchasePayableAccount($this->db))->keptForPaidDocument($supplierId, $documentId, $postedSaldo);
        }
        if (!self::issuedHasPayment($this->db->pdo(), $supplierId, $documentId)) {
            return null;
        }
        return $postedSaldo ?? $this->postedSettlementAccount($supplierId, 'invoice', $documentId);
    }

    /**
     * Má doklad evidovanou úhradu (přijatý {@see PurchasePayableAccount::hasPayment}, vydaný
     * {@see issuedHasPayment})?
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     */
    public function hasPayment(int $supplierId, string $sourceType, int $documentId): bool
    {
        return $sourceType === 'purchase_invoice'
            ? (new PurchasePayableAccount($this->db))->hasPayment($supplierId, $documentId)
            : self::issuedHasPayment($this->db->pdo(), $supplierId, $documentId);
    }

    /**
     * Má vydaný doklad evidovanou úhradu? `paid_total` drží všechny kanály úhrady vydané
     * faktury (banka, pokladna, zápočet, GoPay, karta), zrcadlo {@see PurchasePayableAccount::hasPayment}.
     */
    public static function issuedHasPayment(\PDO $pdo, int $supplierId, int $invoiceId): bool
    {
        $stmt = $pdo->prepare('SELECT paid_total FROM invoices WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $invoiceId]);
        return abs((float) $stmt->fetchColumn()) >= 0.005;
    }

    /**
     * Účet pohledávky, na kterém leží živý zápis vydaného dokladu, v rámci syntetiky
     * `$synthetic`. Víc účtů, žádný nebo doklad se skupinou zaúčtování = null.
     */
    private function postedReceivable(int $supplierId, int $invoiceId, string $synthetic): ?string
    {
        $group = (new DocumentPostingGroup($this->db))->active($supplierId, 'invoice', $invoiceId);
        if (DocumentPostingGroup::hasOwnedEntries($group)
            && !DocumentPostingGroup::isSingleDirectEntry($group, 'invoice', $invoiceId)
        ) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT a.account_code
               FROM journal_entries je
               JOIN journal_entry_lines l ON l.entry_id = je.id AND l.supplier_id = je.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE je.supplier_id = ? AND je.source_type = 'invoice' AND je.source_id = ?
                AND je.reversed_by IS NULL AND a.account_code LIKE ?"
        );
        $stmt->execute([$supplierId, $invoiceId, $synthetic . '%']);
        $codes = array_values(array_filter(
            array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
            static fn (string $code): bool => SaldoAccountMatch::belongsTo($code, $synthetic),
        ));
        return count($codes) === 1 ? $codes[0] : null;
    }

    /**
     * @return array<string,mixed>|null účet osnovy firmy, null = prázdná hodnota
     * @throws PostingException 422
     */
    private function accountFor(int $supplierId, mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !is_int($value)) {
            throw new PostingException('invalid_account', 'Účet musí být číslo účtu.', 422, ['field' => $field]);
        }
        $code = trim((string) $value);
        if ($code === '') {
            return null;
        }
        $account = (new ChartOfAccountsRepository($this->db))->findByCode($supplierId, $code);
        if ($account === null || empty($account['is_active'])) {
            throw new PostingException('invalid_account', 'Účet ' . $code . ' v účtové osnově firmy není.', 422, ['field' => $field]);
        }
        return $account;
    }

    /**
     * Syntetika s víc aktivními analytikami se neúčtuje, vybírá se analytika
     * (stejně jako {@see AdvancePaidAccount::validateVendorSetting}).
     *
     * @param array<string,mixed> $account
     * @throws PostingException 422
     */
    private function assertPostingLevel(int $supplierId, array $account, string $field): void
    {
        $children = array_filter(
            (new ChartOfAccountsRepository($this->db))->childrenOf($supplierId, (int) $account['id']),
            static fn (array $child): bool => !empty($child['is_active']),
        );
        if (count($children) > 1) {
            throw new PostingException(
                'account_not_posting_level',
                'Účet ' . $account['account_code'] . ' má analytiky. Vyberte analytický účet.',
                422,
                ['field' => $field],
            );
        }
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

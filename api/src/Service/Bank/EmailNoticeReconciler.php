<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\GoPay\GoPayService;
use PDO;

/**
 * Cross-source deduplikace GPC výpis ↔ sekundární bankovní zdroj.
 *
 * GPC (oficiální bankovní výpis) je zdroj pravdy. Když dorazí GPC transakce, která
 * už předtím přišla e-mailovým avízem (`source='email_notice'`) a je spárovaná, NEMÁ
 * smysl ji párovat podruhé (vzniklo by dvojí započtení / falešný přeplatek). Místo toho
 * převezmeme párování z avíza na oficiální GPC transakci:
 *   - přepojíme evidence plateb (`invoice_payments` — vystavené) i `payment_matches`
 *     (přijaté faktury) z e-mailové transakce na GPC transakci — beze ztráty MANUÁLNÍHO
 *     i SLOUČENÉHO (split 1→N) párování; paid_total se nemění, jen se přesune ukazatel,
 *   - zkopírujeme párovací metadata (`match_status`, `matched_invoice_id`, …) na GPC tx,
 *   - e-mailovou transakci rozpárujeme (zůstane jako `unmatched` ve svém avízo-výpisu;
 *     uživatel pak může celý avízo-výpis smazat z jeho detailu, viz BankStatementAction).
 *
 * Bezpečnost: převezmeme JEN když existuje PRÁVĚ JEDEN jednoznačný kandidát (shoda
 * účtu + částky + VS + okno data + měny). 0 = nic neděláme (GPC se spáruje normálně),
 * >1 = nejednoznačné → necháme na uživateli (žádný automatický zásah).
 *
 * Identita platby se hledá ve třech úrovních (viz smyčka v takeOverFromEmailNotice):
 *   1. GPC má VS → musí číselně sedět s VS avíza,
 *   2. GPC nemá VS, ale avízo nese VS nebo protiúčet → shoda protiúčtu,
 *   3. ani jedna strana nemá VS ani protiúčet → karetní platba; spoléhá se na účet
 *      výpisu, měnu, částku na haléř, datové okno a jednoznačnost kandidáta.
 * Úroveň 3 je nutná pro karetní avíza typu „Blokace", která VS ani protiúčet
 * nenesou — bez ní platba zůstane viset na avízu, a to se nikdy neúčtuje.
 */
final class EmailNoticeReconciler
{
    /** Datum avíza (datum e-mailu) se může lišit od data zaúčtování v GPC. */
    private const DATE_WINDOW_DAYS = 5;
    /** Částka z avíza i GPC je týž převod — povolíme jen haléřový rozdíl zaokrouhlení. */
    private const AMOUNT_TOLERANCE = 0.005;
    /**
     * Karetní BLOKACE se zúčtuje jiným kurzem, než jakým byla blokovaná — u plateb
     * v cizí měně se výsledná částka liší o jednotky procent (např. avízo „Blokace"
     * 831,48 Kč × GPC „GITHUB, INC." 848,35 Kč). Bez tolerance takový pár nikdy
     * nespojíme a platba zůstane viset na avízu, které se nikdy neúčtuje.
     *
     * Volnější okno platí VÝHRADNĚ pro symetrický karetní případ (ani jedna strana
     * nemá VS ani protiúčet) a jen jako fallback, když žádný přesný kandidát není —
     * viz takeOverFromEmailNotice().
     */
    private const CARD_FX_TOLERANCE_RATIO = 0.05;

    public function __construct(
        private readonly Connection $db,
        private readonly GoPayService $gopay,
    ) {}

    /**
     * Pokus o převzetí párování z e-mailového avíza nebo iDokladu pro novou GPC transakci.
     *
     * @return array{email_tx_id:int,email_statement_id:int,secondary_tx_id:int,secondary_statement_id:int,secondary_source:string,match_status:string}|null
     *         null = nepřevzato (žádný/nejednoznačný kandidát, nebo to není GPC tx)
     */
    public function takeOverFromEmailNotice(int $gpcTxId): ?array
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT bt.amount, bt.posted_at, bt.variable_symbol, bt.currency,
                    bt.counterparty_account, bt.source,
                    bs.account_number AS stmt_account, bs.bank_code AS stmt_bank,
                    bs.currency AS stmt_currency, bs.supplier_id AS stmt_supplier_id
               FROM bank_transactions bt
               JOIN bank_statements   bs ON bs.id = bt.statement_id
              WHERE bt.id = ?'
        );
        $stmt->execute([$gpcTxId]);
        $gpc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($gpc === false || (string) $gpc['source'] !== 'statement') {
            return null; // dedup je jen směrem GPC ← avízo
        }

        $amount      = (float) $gpc['amount'];
        $gpcVsDigits = VariableSymbolNormalizer::digits((string) ($gpc['variable_symbol'] ?? ''));
        $gpcCcy      = $this->effectiveCurrency($gpc['currency'] ?? null, $gpc['stmt_currency'] ?? null);
        $gpcAccount  = (string) ($gpc['stmt_account'] ?? '');

        // Tenant scope: supplier odvodíme z účtu GPC výpisu (stejně jako StatementMatcher).
        // Bez jednoznačného supplierа NEpřebíráme nic — převzetí smí hýbat jen platbami
        // patřícími témuž tenantovi (currencies.account_number nemá UNIQUE → účet teoreticky
        // může sdílet víc supplierů; bez scope by šlo přetáhnout párování cizího tenanta).
        $supplierId = (int) ($gpc['stmt_supplier_id'] ?? 0);
        if ($supplierId === 0) {
            $supplierId = $this->resolveSupplierId($pdo, $gpcAccount, (string) ($gpc['stmt_bank'] ?? ''));
        }
        if ($supplierId === 0) {
            return null;
        }

        // Karetní pohyb: ani VS, ani protiúčet. Jen pro něj se níž povolí kurzová
        // odchylka částky (blokace × zúčtování) — u identifikovatelné platby by
        // volnější okno bylo bezdůvodné riziko.
        $gpcCardLike = $gpcVsDigits === ''
            && AccountNumberNormalizer::normalize((string) ($gpc['counterparty_account'] ?? '')) === '';
        $amountTolerance = $gpcCardLike
            ? max(self::AMOUNT_TOLERANCE, abs($amount) * self::CARD_FX_TOLERANCE_RATIO)
            : self::AMOUNT_TOLERANCE;

        // Kandidáti: spárované e-mailové transakce TÉHOŽ supplierа (vlastnictví ověřeno
        // přes invoice_payments/payment_matches.supplier_id) se stejnou částkou (vč.
        // znaménka) v okně ±DATE_WINDOW_DAYS kolem data zaúčtování.
        $cand = $pdo->prepare(
            "SELECT bt.id, bt.source, bt.match_status, bt.matched_invoice_id, bt.matched_at, bt.matched_by,
                    bt.variable_symbol, bt.counterparty_account, bt.currency, bt.statement_id,
                    bt.amount, bt.posted_at,
                    bs.account_number AS stmt_account, bs.bank_code AS stmt_bank, bs.currency AS stmt_currency
               FROM bank_transactions bt
               JOIN bank_statements   bs ON bs.id = bt.statement_id
              WHERE bt.source IN ('email_notice','idoklad')
                AND bs.source IN ('email_notice','idoklad')
                AND ((bt.source = 'email_notice' AND bs.source = 'email_notice')
                  OR (bt.source = 'idoklad' AND bs.source = 'idoklad'))
                AND bt.id <> ?
                AND ABS(bt.amount - ?) <= ?
                AND bt.posted_at BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND DATE_ADD(?, INTERVAL ? DAY)
                AND bs.supplier_id = ?
                AND bt.match_status IN ('auto_exact','auto_partial','manual')
                AND (
                      EXISTS (SELECT 1 FROM invoice_payments ip
                               WHERE ip.bank_transaction_id = bt.id AND ip.supplier_id = ?)
                   OR EXISTS (SELECT 1 FROM payment_matches pm
                               WHERE pm.bank_transaction_id = bt.id AND pm.supplier_id = ?)
                   OR (bt.source = 'idoklad' AND EXISTS (
                          SELECT 1 FROM invoices i
                           WHERE i.id = bt.matched_invoice_id AND i.supplier_id = ?
                      ))
                   OR EXISTS (SELECT 1 FROM gopay_clearings gc
                               WHERE gc.payout_match_transaction_id = bt.id
                                 AND gc.supplier_id = ?)
                )"
        );
        $cand->execute([
            $gpcTxId,
            number_format($amount, 2, '.', ''),
            $amountTolerance,
            $gpc['posted_at'], self::DATE_WINDOW_DAYS,
            $gpc['posted_at'], self::DATE_WINDOW_DAYS,
            $supplierId,
            $supplierId, $supplierId, $supplierId, $supplierId,
        ]);
        $rows = $cand->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $matches = [];
        foreach ($rows as $r) {
            // Shoda účtu (= stejný supplier; oba sloupce jsou account_number).
            if (!AccountNumberNormalizer::equals($gpcAccount, (string) ($r['stmt_account'] ?? ''))) {
                continue;
            }
            $gpcBank = trim((string) ($gpc['stmt_bank'] ?? ''));
            $candidateBank = trim((string) ($r['stmt_bank'] ?? ''));
            if ($gpcBank !== '' && $candidateBank !== '' && $gpcBank !== $candidateBank) {
                continue;
            }
            // Měna — když obě známe, musí sedět (null = legacy, nevyřazuje).
            $candCcy = $this->effectiveCurrency($r['currency'] ?? null, $r['stmt_currency'] ?? null);
            if ($gpcCcy !== null && $candCcy !== null && strtoupper($gpcCcy) !== strtoupper($candCcy)) {
                continue;
            }
            // VS: má-li GPC tx variabilní symbol, vyžaduj číselnou shodu.
            $symmetricCard = false;
            if ($gpcVsDigits !== '') {
                if (VariableSymbolNormalizer::digits((string) ($r['variable_symbol'] ?? '')) !== $gpcVsDigits) {
                    continue;
                }
            } else {
                // POZOR: `$gpcAccount` je číslo účtu VÝPISU a používá ho kontrola výš
                // v každé iteraci — protiúčet proto drž v samostatné proměnné.
                // Porovnáváme NORMALIZOVANĚ: GPC u karetních pohybů plní protiúčet
                // samými nulami (`0000000000000000`), což je „žádná protistrana",
                // ne účet — surové `!== ''` by shodilo symetrickou větev níž.
                $gpcCounterparty  = AccountNumberNormalizer::normalize((string) ($gpc['counterparty_account'] ?? ''));
                $candCounterparty = AccountNumberNormalizer::normalize((string) ($r['counterparty_account'] ?? ''));
                $candVsDigits     = VariableSymbolNormalizer::digits((string) ($r['variable_symbol'] ?? ''));

                if ($candCounterparty !== '' || $candVsDigits !== '') {
                    // Avízo něco identifikujícího nese → drž se shody protiúčtu (jako dosud).
                    if ($gpcCounterparty === '' || $candCounterparty === ''
                        || !AccountNumberNormalizer::equals($gpcCounterparty, $candCounterparty)
                    ) {
                        continue;
                    }
                } elseif ($gpcCounterparty !== '') {
                    // Avízo je bez identity, ale GPC protistranu zná → jde nejspíš o běžný
                    // převod, ne o tentýž karetní pohyb. Nepřebíráme (asymetrie = slabá shoda).
                    continue;
                } else {
                    // Zbývá symetrický případ: ani jedna strana nemá VS ani protiúčet — přesně
                    // takhle vypadá karetní platba (avízo „Blokace" × GPC řádek karty). Identitu
                    // tu nese shoda účtu výpisu, měny, částky, datového okna, tenantа
                    // a hlavně JEDNOZNAČNOST kandidáta (count($pool) === 1 níž). Bez téhle
                    // větve by karetní úhrady zůstaly na avízu napořád — a avízo se nikdy
                    // neúčtuje, takže platební noha (vč. kurzového rozdílu) nikdy nedoteče
                    // do deníku.
                    $symmetricCard = true;
                }
            }

            $candAmount = (float) $r['amount'];
            $exact = abs($candAmount - $amount) <= self::AMOUNT_TOLERANCE;
            if (!$exact && !$this->acceptableCardFxTwin($pdo, $gpc, $r, $amount, $candAmount, $symmetricCard, $supplierId)) {
                continue;
            }

            $r['__exact'] = $exact;
            $matches[] = $r;
        }

        // Kurzový fallback nesmí konkurovat přesné shodě — je-li k dispozici aspoň
        // jeden kandidát na haléř, rozhoduje se jen mezi nimi (jinak by karetní
        // blokace v okně shodila jinak jednoznačné převzetí na „nejednoznačné").
        $exactMatches = array_values(array_filter($matches, static fn (array $m): bool => $m['__exact'] === true));
        $pool = $exactMatches !== [] ? $exactMatches : $matches;

        // Jen jednoznačná shoda — 0 nebo >1 necháme na standardním párování / uživateli.
        if (count($pool) !== 1) {
            return null;
        }

        return $this->transfer($pdo, $gpcTxId, $pool[0], $supplierId, $amount);
    }

    /**
     * Smí se kandidát s ODLIŠNOU částkou brát jako tentýž pohyb? Jen pro karetní
     * blokaci zúčtovanou jiným kurzem, a to za přísných podmínek:
     *   - symetrický karetní tvar (ani jedna strana nemá VS ani protiúčet),
     *   - shodné znaménko (blokace i zúčtování jdou stejným směrem),
     *   - blokace PŘEDCHÁZÍ zúčtování (avízo nemůže dorazit po výpisu),
     *   - odchylka do CARD_FX_TOLERANCE_RATIO,
     *   - platba visí VÝHRADNĚ na `payment_matches` (přijaté faktury). Tam je
     *     částka jediným nositelem stavu a lze ji rovnou opravit na zúčtovanou;
     *     `invoice_payments` má denormalizovaný `invoices.paid_total`, který umí
     *     přepočítat jen InvoicePaymentService — do toho se odsud netrefíme.
     *
     * @param array<string,mixed> $gpc
     * @param array<string,mixed> $twin
     */
    private function acceptableCardFxTwin(
        PDO $pdo,
        array $gpc,
        array $twin,
        float $gpcAmount,
        float $twinAmount,
        bool $symmetricCard,
        int $supplierId,
    ): bool {
        if (!$symmetricCard) {
            return false;
        }
        if (($gpcAmount <=> 0.0) !== ($twinAmount <=> 0.0)) {
            return false;
        }
        if ((string) $twin['posted_at'] > (string) $gpc['posted_at']) {
            return false;
        }
        $reference = max(abs($gpcAmount), abs($twinAmount));
        if ($reference <= 0.0 || abs($gpcAmount - $twinAmount) > $reference * self::CARD_FX_TOLERANCE_RATIO) {
            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM invoice_payments ip WHERE ip.bank_transaction_id = ?) AS issued,
                (SELECT COUNT(*) FROM payment_matches pm WHERE pm.bank_transaction_id = ? AND pm.supplier_id = ?) AS payable'
        );
        $stmt->execute([(int) $twin['id'], (int) $twin['id'], $supplierId]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['issued' => 0, 'payable' => 0];

        return (int) $counts['issued'] === 0 && (int) $counts['payable'] > 0;
    }

    /**
     * Opačné pořadí: oficiální GPC/PDF už existuje a právě dorazil iDoklad.
     * Při jediné silné shodě označí sekundární záznam jako ignorovaný.
     */
    public function ignoreSecondaryWhenAuthoritativeTwinExists(int $secondaryTxId): ?int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT bt.amount, bt.posted_at, bt.variable_symbol, bt.currency,
                    bt.counterparty_account, bt.source,
                    bs.account_number AS stmt_account, bs.bank_code AS stmt_bank,
                    bs.currency AS stmt_currency, bs.supplier_id AS stmt_supplier_id
               FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ?"
        );
        $stmt->execute([$secondaryTxId]);
        $secondary = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($secondary === false || (string) $secondary['source'] !== 'idoklad') return null;
        $supplierId = (int) ($secondary['stmt_supplier_id'] ?? 0);
        if ($supplierId === 0) {
            $supplierId = $this->resolveSupplierId($pdo, (string) $secondary['stmt_account'], (string) ($secondary['stmt_bank'] ?? ''));
        }
        if ($supplierId === 0) return null;

        $candidates = $pdo->prepare(
            "SELECT bt.id, bt.variable_symbol, bt.currency, bt.counterparty_account,
                    bs.account_number AS stmt_account, bs.bank_code AS stmt_bank,
                    bs.currency AS stmt_currency
              FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.source = 'statement' AND bs.source IN ('gpc','pdf')
                AND bs.supplier_id = ?
                AND ABS(bt.amount - ?) <= ?
                AND bt.posted_at BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND DATE_ADD(?, INTERVAL ? DAY)"
        );
        $candidates->execute([
            $supplierId,
            $secondary['amount'], self::AMOUNT_TOLERANCE,
            $secondary['posted_at'], self::DATE_WINDOW_DAYS,
            $secondary['posted_at'], self::DATE_WINDOW_DAYS,
        ]);
        $matches = [];
        $vs = VariableSymbolNormalizer::digits((string) ($secondary['variable_symbol'] ?? ''));
        $currency = $this->effectiveCurrency($secondary['currency'] ?? null, $secondary['stmt_currency'] ?? null);
        foreach ($candidates->fetchAll(PDO::FETCH_ASSOC) ?: [] as $candidate) {
            if (!AccountNumberNormalizer::equals((string) $secondary['stmt_account'], (string) $candidate['stmt_account'])) continue;
            $bank = trim((string) ($secondary['stmt_bank'] ?? ''));
            $candidateBank = trim((string) ($candidate['stmt_bank'] ?? ''));
            if ($bank !== '' && $candidateBank !== '' && $bank !== $candidateBank) continue;
            $candidateCurrency = $this->effectiveCurrency($candidate['currency'] ?? null, $candidate['stmt_currency'] ?? null);
            if ($currency !== null && $candidateCurrency !== null && strtoupper($currency) !== strtoupper($candidateCurrency)) continue;
            if ($vs !== '') {
                if ($vs !== VariableSymbolNormalizer::digits((string) ($candidate['variable_symbol'] ?? ''))) continue;
            } else {
                // Normalizovaně — GPC u karetních pohybů plní protiúčet samými nulami.
                $secondaryAccount = AccountNumberNormalizer::normalize((string) ($secondary['counterparty_account'] ?? ''));
                $candidateAccount = AccountNumberNormalizer::normalize((string) ($candidate['counterparty_account'] ?? ''));
                if ($secondaryAccount === '' || $candidateAccount === ''
                    || !AccountNumberNormalizer::equals($secondaryAccount, $candidateAccount)) continue;
            }
            $matches[] = (int) $candidate['id'];
        }
        if (count($matches) !== 1) return null;
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'ignored' WHERE id = ?")
            ->execute([$secondaryTxId]);
        return $matches[0];
    }

    /**
     * Supplier (tenant) z čísla účtu výpisu — kopíruje logiku StatementMatcher::match():
     * porovnání přes AccountNumberNormalizer (zero-padding/prefix + domácí část IBANu),
     * volitelný filtr na bank_code. Vrací 0, když účet nemá právě jednoho vlastníka.
     */
    private function resolveSupplierId(PDO $pdo, string $account, string $bankCode): int
    {
        if ($account === '') {
            return 0;
        }
        $stmt = $pdo->query(
            'SELECT supplier_id, account_number, iban, bank_code
               FROM currencies
              WHERE account_number IS NOT NULL OR iban IS NOT NULL
              UNION ALL
             SELECT supplier_id, account_number, iban, bank_code
               FROM supplier_bank_accounts
              WHERE is_active = 1 AND (account_number IS NOT NULL OR iban IS NOT NULL)'
        );
        if ($stmt === false) {
            return 0;
        }
        $owners = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cand) {
            $iban = isset($cand['iban']) && is_string($cand['iban']) ? $cand['iban'] : null;
            if ($bankCode !== '') {
                $candBank = (string) ($cand['bank_code'] ?? '');
                if ($candBank === '' && $iban !== null) {
                    $candBank = (string) AccountNumberNormalizer::czechIbanBankCode($iban);
                }
                if ($candBank !== '' && $candBank !== $bankCode) {
                    continue;
                }
            }
            if (AccountNumberNormalizer::matchesAny($account, $cand['account_number'] ?? null, $iban)) {
                $owner = (int) $cand['supplier_id'];
                if ($owner > 0) {
                    $owners[$owner] = true;
                }
            }
        }
        return count($owners) === 1 ? (int) array_key_first($owners) : 0;
    }

    /**
     * Přepojí párovací záznamy z e-mailové transakce na GPC a avízo rozpáruje.
     *
     * @param array<string,mixed> $twin
     * @param float $gpcAmount Zúčtovaná částka z GPC — u karetní blokace se liší od
     *   blokované (jiný kurz) a je autoritativní, viz acceptableCardFxTwin().
     * @return array{email_tx_id:int,email_statement_id:int,secondary_tx_id:int,secondary_statement_id:int,secondary_source:string,match_status:string}
     */
    private function transfer(PDO $pdo, int $gpcTxId, array $twin, int $supplierId, float $gpcAmount): array
    {
        $emailTxId        = (int) $twin['id'];
        $emailStatementId = (int) $twin['statement_id'];
        $secondarySource  = (string) ($twin['source'] ?? 'email_notice');
        $amountDrifted    = abs((float) $twin['amount'] - $gpcAmount) > self::AMOUNT_TOLERANCE;

        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            // Přepoj evidenci plateb (vystavené) i payment_matches (přijaté) na GPC tx.
            // GPC tx je čerstvá (před auto-párováním) → žádná kolize UNIQUE(bank_tx, invoice).
            // Scope `supplier_id` = tenant brzda: nikdy nepřesuneme cizí platby (i kdyby
            // kandidátní filtr selhal), viz audit multi-tenant.
            $pdo->prepare('UPDATE invoice_payments SET bank_transaction_id = ? WHERE bank_transaction_id = ? AND supplier_id = ?')
                ->execute([$gpcTxId, $emailTxId, $supplierId]);
            $pdo->prepare('UPDATE payment_matches SET bank_transaction_id = ? WHERE bank_transaction_id = ? AND supplier_id = ?')
                ->execute([$gpcTxId, $emailTxId, $supplierId]);
            $pdo->prepare(
                'UPDATE gopay_clearings
                    SET payout_match_transaction_id = ?
                  WHERE payout_match_transaction_id = ? AND supplier_id = ?'
            )->execute([$gpcTxId, $emailTxId, $supplierId]);

            // Karetní blokace zúčtovaná jiným kurzem: evidovaná úhrada nesla blokovanou
            // částku, GPC je zdroj pravdy. `payment_matches.amount` je vždy absolutní
            // (viz StatementMatcher::matchPurchase) a u přijatých faktur je jediným
            // nositelem uhrazené částky — přepíšeme ji na skutečně zúčtovanou.
            // Jistota, že tu nejsou `invoice_payments` (a s nimi invoices.paid_total
            // k přepočtu), plyne z acceptableCardFxTwin(), která fuzzy pár jinak nepustí.
            if ($amountDrifted) {
                $pdo->prepare('UPDATE payment_matches SET amount = ? WHERE bank_transaction_id = ? AND supplier_id = ?')
                    ->execute([number_format(abs($gpcAmount), 2, '.', ''), $gpcTxId, $supplierId]);
            }

            // Zkopíruj párovací metadata (vč. původního matched_at/by pro audit) na GPC tx.
            $pdo->prepare(
                'UPDATE bank_transactions
                    SET match_status = ?, matched_invoice_id = ?, matched_at = ?, matched_by = ?
                  WHERE id = ?'
            )->execute([
                (string) $twin['match_status'],
                $twin['matched_invoice_id'] !== null ? (int) $twin['matched_invoice_id'] : null,
                $twin['matched_at'],
                $twin['matched_by'] !== null ? (int) $twin['matched_by'] : null,
                $gpcTxId,
            ]);

            // Sekundární záznam už není zdrojem úhrady. iDoklad ponecháme jako
            // auditní stopu `ignored`, avízo zůstává ručně odstranitelné jako dřív.
            $pdo->prepare(
                "UPDATE bank_transactions
                    SET match_status = ?, matched_invoice_id = NULL,
                        matched_at = NULL, matched_by = NULL
                  WHERE id = ?"
            )->execute([$secondarySource === 'idoklad' ? 'ignored' : 'unmatched', $emailTxId]);

            // Přepočti matched_count avízo-výpisu (GPC výpis řeší StatementImporter).
            $pdo->prepare(
                "UPDATE bank_statements
                    SET matched_count = (
                        SELECT COUNT(*) FROM bank_transactions
                         WHERE statement_id = ?
                           AND match_status IN ('auto_exact','auto_partial','manual')
                    )
                  WHERE id = ?"
            )->execute([$emailStatementId, $emailStatementId]);

            $this->gopay->completeTransferredPayout($supplierId, $gpcTxId);

            if ($owns) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'email_tx_id'        => $emailTxId,
            'email_statement_id' => $emailStatementId,
            'secondary_tx_id'        => $emailTxId,
            'secondary_statement_id' => $emailStatementId,
            'secondary_source'       => $secondarySource,
            'match_status'       => (string) $twin['match_status'],
        ];
    }

    private function effectiveCurrency(mixed $txCcy, mixed $stmtCcy): ?string
    {
        if (is_string($txCcy) && $txCcy !== '') {
            return $txCcy;
        }
        if (is_string($stmtCcy) && $stmtCcy !== '') {
            return $stmtCcy;
        }
        return null;
    }
}

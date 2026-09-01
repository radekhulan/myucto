<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use PDO;

/**
 * Persist naparsovaného výpisu do DB (GPC nebo bank-specifický PDF parser — obojí
 * vrací stejný tvar `['header'=>..., 'transactions'=>...]`). Dedupe podle file_hash.
 */
final class StatementImporter
{
    public function __construct(
        private readonly Connection $db,
        private readonly GpcParser $parser,
        private readonly StatementMatcher $matcher,
        // Cross-source dedup GPC ← e-mailové avízo: převezme párování (i manuální/split)
        // z už spárované avízo-transakce místo dvojího párování téže platby.
        private readonly EmailNoticeReconciler $reconciler,
        // Automatizace (mini-epic): po (re)match/importu zkusí zaúčtovat transakci.
        // Nullable — best-effort hook, fakturační tenant / tax_evidence = no-op.
        private readonly ?BankPostingService $bankPosting = null,
        private readonly ?SupplierBankAccountRepository $ownAccounts = null,
    ) {}

    /**
     * @param ?int $currencyId Cílový měnový účet (currencies.id). Když je zadán, jeho
     *   měna + kód banky jsou AUTORITATIVNÍ a přebijí lookup podle čísla účtu. Nutné
     *   u víceměnových účtů se sdíleným číslem (Raiffeisenbank: CZK/EUR/USD = jedno
     *   číslo), kde GPC hlavička měnu nenese a z čísla účtu ji nelze odvodit (#167).
     *   Volající (BankStatementAction) ověřuje příslušnost k supplierovi i shodu
     *   čísla účtu; tady už jen načteme code/bank_code. NULL = dnešní chování
     *   (lookup podle account_number — folder scan, jednoznačný účet).
     *
     * @return array{statement_id:int, transactions:int, matched:int, duplicate:bool,
     *               parsed_transactions:int, skipped_duplicates:int,
     *               warnings:list<array{code:string,message:string,parsed?:int,inserted?:int,skipped?:int}>}
     */
    public function import(string $content, string $fileName, ?int $userId, ?int $currencyId = null): array
    {
        $parsed = $this->parser->parse($content);
        return $this->persist($parsed, $content, $fileName, $userId, $currencyId, 'gpc');
    }

    /**
     * Persist výpis naparsovaný bank-specifickým PDF parserem (Creditas a další —
     * viz {@see \MyInvoice\Service\Bank\Pdf\BankStatementPdfParserRegistry}). Stejná
     * dedupe/matcher/reconciler logika jako {@see import()}, ale zdrojové bajty jsou
     * PDF (uloží se do pdf_content, ne file_content — žádný GPC ekvivalent neexistuje).
     *
     * @param array{header:array,transactions:list<array>} $parsed
     */
    public function importParsedPdf(array $parsed, string $pdfBytes, string $fileName, ?int $userId, ?int $currencyId = null): array
    {
        return $this->persist($parsed, $pdfBytes, $fileName, $userId, $currencyId, 'pdf');
    }

    /**
     * @param array{header:array,transactions:list<array>} $parsed
     * @param string $rawBytes Originální bajty souboru — hashují se pro dedup a ukládají
     *   se buď do file_content (source='gpc') nebo pdf_content (source='pdf').
     */
    private function persist(array $parsed, string $rawBytes, string $fileName, ?int $userId, ?int $currencyId, string $source): array
    {
        $hash = hash('sha256', $rawBytes);
        $pdo = $this->db->pdo();

        // Dedupe
        $exists = $pdo->prepare('SELECT id FROM bank_statements WHERE file_hash = ?');
        $exists->execute([$hash]);
        $existingId = $exists->fetchColumn();
        if ($existingId !== false) {
            return [
                'statement_id' => (int) $existingId,
                'transactions' => 0,
                'matched' => 0,
                'duplicate' => true,
                'parsed_transactions' => count($parsed['transactions']),
                'skipped_duplicates' => 0,
                'warnings' => [],
            ];
        }

        $h = $parsed['header'];

        // GPC header (074) NEMÁ pole pro měnu — máme to jen v 075 transakcích
        // (pozice 118-122, ISO 4217 numeric). Odvodíme měnu výpisu v pořadí:
        //   1) Lookup do currencies podle account_number/IBAN — GPC výpis je vždy
        //      z JEDNOHO účtu (= jedna měna), takže měna registrovaného účtu je
        //      AUTORITATIVNÍ. Per-tx pole nelze upřednostnit: Fio ho dle své
        //      specifikace plní KONSTANTNĚ "0203" (CZK) i u EUR účtu (#109 —
        //      EUR výpis se pak zobrazil v Kč a kvůli currency guardu v matcheru
        //      se nikdy nespároval).
        //   2) Fallback (účet neregistrovaný): dominantní non-null currency
        //      z 075 transakcí (CREDITAS/KB plní reálný kód; původní Creditas
        //      bug report — EUR výpis s 00978 se zobrazoval jako CZK, protože
        //      bank_statements.currency zůstával NULL).
        //   3) Bez 1 i 2: NULL (UI fallback CZK).
        // Účet z currencies (autoritativní měna + kód banky). GPC header kód banky
        // nenese (na rozdíl od e-mailových avíz) → doplníme ho z konfigurovaného účtu,
        // ať jsou data normalizovaná napříč zdroji (jinak GPC výpis bank_code = NULL).
        // Explicitně zvolený měnový účet (#167) je autoritativní; jinak lookup podle čísla.
        $explicitAccount = $currencyId !== null;
        $account = $explicitAccount
            ? $this->loadCurrencyById($currencyId)
            : $this->lookupAccount($h['account_number']);
        $registeredOwner = $this->lookupRegisteredOwner($h['account_number']);
        $statementSupplierId = $explicitAccount
            ? (isset($account['supplier_id']) && (int) $account['supplier_id'] > 0
                ? (int) $account['supplier_id']
                : null)
            : ($registeredOwner['supplier_id'] ?? null);
        $accountCurrency = $account['code'] ?? null;
        $accountBankCode = $account['bank_code'] ?? $registeredOwner['bank_code'] ?? null;
        $statementCurrency = $accountCurrency
            ?? $this->detectStatementCurrency($parsed['transactions']);

        if ($statementSupplierId !== null) {
            $this->ownAccounts?->registerSeen(
                $statementSupplierId,
                (string) $h['account_number'],
                $accountBankCode,
                $statementCurrency,
                isset($account['id']) ? (int) $account['id'] : null,
            );
        }

        // GPC: raw bajty jdou do file_content (zpětně stažitelný originál). PDF: do
        // pdf_content (existující sloupce z migrace 0052 — „Stáhnout PDF" tak funguje
        // bez jakékoli FE změny i pro tyto výpisy; file_content zůstává NULL, protože
        // žádný GPC ekvivalent neexistuje).
        $fileContent   = $source === 'gpc' ? $rawBytes : null;
        $pdfContent    = $source === 'pdf' ? $rawBytes : null;
        $pdfName       = $source === 'pdf' ? $fileName : null;
        $pdfHash       = $source === 'pdf' ? $hash : null;
        $pdfSize       = $source === 'pdf' ? strlen($rawBytes) : null;
        $pdfUploadedAt = $source === 'pdf' ? date('Y-m-d H:i:s') : null;

        $pdo->prepare(
            'INSERT INTO bank_statements
                 (source, file_name, file_hash, file_content, pdf_content, pdf_name, pdf_hash, pdf_size_bytes, pdf_uploaded_at,
                  supplier_id, account_number, bank_code, currency,
                  statement_number, statement_date,
                  prev_balance, curr_balance, credit_total, debit_total, transaction_count, imported_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $source, $fileName, $hash, $fileContent, $pdfContent, $pdfName, $pdfHash, $pdfSize, $pdfUploadedAt,
            $statementSupplierId, $h['account_number'], $accountBankCode, $statementCurrency,
            $h['statement_number'], $h['statement_date'],
            $h['prev_balance'], $h['curr_balance'], $h['credit_total'], $h['debit_total'],
            count($parsed['transactions']), $userId,
        ]);
        $statementId = (int) $pdo->lastInsertId();

        $insertTx = $pdo->prepare(
            'INSERT INTO bank_transactions
                 (statement_id, posted_at, amount, currency, variable_symbol, constant_symbol, specific_symbol,
                  counterparty_account, counterparty_bank, counterparty_name, description, bank_ref, import_fingerprint)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $findDuplicateTx = $pdo->prepare(
            'SELECT id FROM bank_transactions WHERE import_fingerprint = ? LIMIT 1'
        );

        // Bankovní reference je identitou pohybu jen tehdy, když je v souboru JEDINEČNÁ.
        // Některé banky do pole čísla dokladu píšou konstantu nebo denní pořadí — kdyby
        // se taková hodnota vzala jako identita, splynuly by v otisku dva různé pohyby.
        $referenceCounts = [];
        foreach ($parsed['transactions'] as $tx) {
            $ref = trim((string) ($tx['bank_ref'] ?? ''));
            if ($ref !== '') {
                $referenceCounts[$ref] = ($referenceCounts[$ref] ?? 0) + 1;
            }
        }
        /** @var array<string,int> $identitySeen Pořadí pohybu se shodným náhradním otiskem V RÁMCI souboru. */
        $identitySeen = [];

        $matched = 0;
        $inserted = 0;
        $skipped = 0;
        $matchIds = [];
        foreach ($parsed['transactions'] as $tx) {
            // Měna registrovaného účtu přebíjí i per-tx pole (#109): výpis je
            // jednoměnový a Fio do 075 píše konstantně CZK i u EUR účtu — per-tx
            // hodnota by rozbila currency guard v matcheru. Per-tx kód se použije
            // jen jako fallback, když účet není registrovaný (CREDITAS/KB ho
            // plní reálně) — aby se EUR transakce neztratila.
            $txCurrency = $accountCurrency ?? $tx['currency'] ?? $statementCurrency;

            // Pořadí pohybu se shodným náhradním otiskem v souboru: tři legitimní platby
            // téže částky, dne a VS dostanou pořadí 0, 1, 2 a přestanou splývat. Pořadí 0
            // otisk NEMĚNÍ, takže překrývající se výpisy dedup dál drží (týž pohyb je
            // v obou souborech pod stejným pořadím) a historické otisky zůstávají platné.
            $identityKey = implode("\x1f", $this->fallbackIdentity($tx, 0));
            $ordinal = $identitySeen[$identityKey] ?? 0;
            $identitySeen[$identityKey] = $ordinal + 1;

            $reference = trim((string) ($tx['bank_ref'] ?? ''));
            $useReference = $reference !== '' && ($referenceCounts[$reference] ?? 0) === 1;
            $identity = $useReference
                ? ['bank_ref', $reference]
                : $this->fallbackIdentity($tx, $ordinal);
            $fingerprint = $this->transactionFingerprint(
                (string) $h['account_number'],
                $accountBankCode,
                $txCurrency,
                $tx,
                $identity,
            );

            // Zpětná kompatibilita: pohyby naimportované DŘÍV (kdy GPC bank_ref neplnil)
            // nesou otisk z náhradní identity bez pořadí. Bez tohohle kandidáta by je
            // překrývající se výpis po upgradu založil ZNOVU — z opravy tiché ztráty dat
            // by se stalo tiché zdvojení. Legacy otisk platí jen pro PRVNÍ výskyt identity
            // v souboru, aby druhá legitimní platba dál prošla.
            $candidates = [$fingerprint];
            if ($ordinal === 0) {
                $legacy = $this->transactionFingerprint(
                    (string) $h['account_number'],
                    $accountBankCode,
                    $txCurrency,
                    $tx,
                    $this->fallbackIdentity($tx, 0),
                );
                if ($legacy !== $fingerprint) {
                    $candidates[] = $legacy;
                }
            }
            $alreadyStored = false;
            foreach ($candidates as $candidate) {
                $findDuplicateTx->execute([$candidate]);
                if ($findDuplicateTx->fetchColumn() !== false) {
                    $alreadyStored = true;
                    break;
                }
            }
            if ($alreadyStored) {
                $skipped++;
                continue;
            }
            try {
                $insertTx->execute([
                    $statementId, $tx['posted_at'], $tx['amount'], $txCurrency,
                    $tx['variable_symbol'], $tx['constant_symbol'], $tx['specific_symbol'],
                    $tx['counterparty_account'], $tx['counterparty_bank'], $tx['counterparty_name'],
                    $tx['description'], $tx['bank_ref'], $fingerprint,
                ]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[0] ?? null) === '23000'
                    && str_contains($e->getMessage(), 'uq_bt_import_fingerprint')) {
                    $skipped++;
                    continue;
                }
                throw $e;
            }
            $txId = (int) $pdo->lastInsertId();
            $inserted++;

            // Cross-source dedup: pokud tato platba už dorazila e-mailovým avízem a je
            // spárovaná, převezmi párování (i manuální/split) na oficiální GPC transakci
            // místo dvojího párování (jinak falešný přeplatek). GPC = zdroj pravdy.
            $takeover = $this->reconciler->takeOverFromEmailNotice($txId);
            if ($takeover !== null) {
                $matched++;
                $this->bankPosting?->handleTransaction($txId, $userId);
                continue;
            }

            $matchIds[] = $txId;
        }

        foreach ($this->matcher->matchBatch($matchIds) as $txId => $r) {
            if (in_array($r['status'], ['auto_exact', 'auto_partial'], true)) {
                $matched++;
            }
            $this->bankPosting?->handleTransaction((int) $txId, $userId, !empty($r['requires_review']));
        }

        $pdo->prepare('UPDATE bank_statements SET matched_count = ?, transaction_count = ? WHERE id = ?')
            ->execute([$matched, $inserted, $statementId]);

        // Rozdíl „řádků v souboru" × „založených pohybů" se nesmí ztratit v tichu: přesně
        // tohle skrývalo tichou ztrátu dat, protože jediný způsob, jak si toho všimnout,
        // bylo ručně přepočítat 075 řádky proti databázi.
        $parsedCount = count($parsed['transactions']);
        $warnings = [];
        if ($skipped > 0) {
            $warnings[] = [
                'code'     => 'transactions_skipped_as_duplicate',
                'message'  => sprintf(
                    'Soubor obsahuje %d pohybů, založeno %d. %d pohybů se shoduje s už evidovanými (překrývající se výpis) a nebylo založeno.',
                    $parsedCount,
                    $inserted,
                    $skipped,
                ),
                'parsed'   => $parsedCount,
                'inserted' => $inserted,
                'skipped'  => $skipped,
            ];
        }

        return [
            'statement_id'        => $statementId,
            'transactions'        => $inserted,
            'matched'             => $matched,
            'duplicate'           => false,
            'parsed_transactions' => $parsedCount,
            'skipped_duplicates'  => $skipped,
            'warnings'            => $warnings,
        ];
    }

    /**
     * Náhradní identita pohybu, když banka nepošle použitelné ID pohybu. Sama o sobě
     * NENÍ jedinečná — dvě legitimní platby téže částky, dne, VS a popisu (opakované
     * mikroplatby, poplatky, karetní pohyby bez protiúčtu) mají identickou. Proto
     * dostane pořadí výskytu v rámci souboru; pořadí 0 se do otisku nepromítá, aby
     * zůstal shodný s otisky vyrobenými před touto opravou.
     *
     * @param array<string,mixed> $tx
     * @return list<string>
     */
    private function fallbackIdentity(array $tx, int $ordinal): array
    {
        $identity = [
            'fallback',
            trim((string) ($tx['variable_symbol'] ?? '')),
            trim((string) ($tx['constant_symbol'] ?? '')),
            trim((string) ($tx['specific_symbol'] ?? '')),
            AccountNumberNormalizer::normalize((string) ($tx['counterparty_account'] ?? '')),
            trim((string) ($tx['counterparty_bank'] ?? '')),
            mb_strtoupper(trim((string) ($tx['counterparty_name'] ?? '')), 'UTF-8'),
            mb_strtoupper(trim((string) ($tx['description'] ?? '')), 'UTF-8'),
        ];
        if ($ordinal > 0) {
            $identity[] = '#' . $ordinal;
        }
        return $identity;
    }

    /**
     * Stabilní identita pohybu napříč překrývajícími se výpisy. Bankovní reference
     * je nejsilnější klíč; bez ní použijeme celý konzervativní otisk pohybu doplněný
     * o pořadí v souboru, aby dvě legitimní platby stejné částky a dne nesplynuly.
     *
     * @param array<string,mixed> $tx
     * @param list<string> $identity Identita pohybu — {@see fallbackIdentity()} nebo `['bank_ref', …]`.
     */
    private function transactionFingerprint(
        string $accountNumber,
        ?string $bankCode,
        ?string $currency,
        array $tx,
        array $identity,
    ): string {
        $account = AccountNumberNormalizer::normalize($accountNumber);
        return hash('sha256', json_encode([
            'v' => 1,
            'account' => $account,
            'bank' => $bankCode,
            'currency' => strtoupper((string) $currency),
            'date' => (string) ($tx['posted_at'] ?? ''),
            'amount' => number_format((float) ($tx['amount'] ?? 0), 2, '.', ''),
            'identity' => $identity,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * Dominantní currency z transakcí — vrátí ten kód, který se vyskytuje
     * nejčastěji (po vyřazení NULL). NULL pokud ani jedna transakce currency
     * nemá. Multi-currency výpisy jsou v praxi vzácné; když je víc kódů,
     * statement.currency dostane majoritní.
     *
     * @param list<array{currency?:?string}> $transactions
     */
    private function detectStatementCurrency(array $transactions): ?string
    {
        $counts = [];
        foreach ($transactions as $tx) {
            $c = $tx['currency'] ?? null;
            if (is_string($c) && $c !== '') {
                $counts[$c] = ($counts[$c] ?? 0) + 1;
            }
        }
        if ($counts === []) return null;
        arsort($counts);
        return (string) array_key_first($counts);
    }

    /**
     * Lookup currency podle account_number v `currencies` tabulce. Pro případy,
     * kdy banka nevyplňuje 075.currency (= per-tx detection selže) — vezmeme
     * měnu jednoznačnou napříč nalezenými currencies řádky se stejným číslem
     * účtu. Vlastník výpisu se určuje odděleně přes sjednocený registr
     * currencies + supplier_bank_accounts.
     *
     * AccountNumberNormalizer::equals normalizuje leading zeros / dashes pro
     * porovnání (např. `0000000123456789` z GPC vs `123456789` z UI inputu).
     * Porovnává se i domácí část IBANu (#109) — cizoměnové účty bývají
     * evidované jen IBANem a bez toho EUR výpis spadl na CZK fallback.
     *
     * @return array{id:?int,supplier_id:?int,code:?string,bank_code:?string}|null
     */
    private function lookupAccount(string $accountNumber): ?array
    {
        if ($accountNumber === '') return null;
        $stmt = $this->db->pdo()->query(
            'SELECT id, supplier_id, account_number, iban, code, bank_code FROM currencies
              WHERE account_number IS NOT NULL OR iban IS NOT NULL'
        );
        if ($stmt === false) return null;
        $matches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $iban = isset($row['iban']) && is_string($row['iban']) ? $row['iban'] : null;
            if (AccountNumberNormalizer::matchesAny($accountNumber, $row['account_number'] ?? null, $iban)) {
                $matches[] = $row;
            }
        }
        if ($matches === []) return null;
        $supplierIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['supplier_id'], $matches)));
        $codes = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['code'], $matches)));
        $bankCodes = array_values(array_unique(array_filter(array_map(
            static fn (array $r): string => trim((string) ($r['bank_code'] ?? '')),
            $matches,
        ))));
        $first = $matches[0];
        return [
            'id'          => count($matches) === 1 ? (int) $first['id'] : null,
            'supplier_id' => count($supplierIds) === 1 ? $supplierIds[0] : null,
            'code'        => count($codes) === 1 ? $codes[0] : null,
            'bank_code'   => count($bankCodes) === 1 ? $bankCodes[0] : null,
        ];
    }

    /**
     * Měna + kód banky podle konkrétního currencies.id — pro víceměnové účty se
     * sdíleným číslem (#167), kde lookup podle account_number nestačí (vrátil by
     * první z N měnových variant). Příslušnost k supplierovi a shodu čísla účtu
     * ověřuje caller (BankStatementAction); tady už jen načteme řádek.
     *
     * @return array{id:int,supplier_id:int,code:string,bank_code:?string}|null
     */
    private function loadCurrencyById(int $currencyId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, supplier_id, code, bank_code FROM currencies WHERE id = ?');
        $stmt->execute([$currencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return [
            'id'        => (int) $row['id'],
            'supplier_id' => (int) $row['supplier_id'],
            'code'      => (string) $row['code'],
            'bank_code' => isset($row['bank_code']) && (string) $row['bank_code'] !== '' ? (string) $row['bank_code'] : null,
        ];
    }

    /**
     * Automatický import smí přiřadit tenant jen při právě jednom vlastníkovi
     * napříč oběma registry vlastních účtů. Konflikt mezi registry je stejně
     * nejednoznačný jako dvě shody uvnitř jednoho registru.
     *
     * @return array{supplier_id:?int,bank_code:?string}|null
     */
    private function lookupRegisteredOwner(string $accountNumber): ?array
    {
        if ($accountNumber === '') {
            return null;
        }
        $stmt = $this->db->pdo()->query(
            'SELECT supplier_id, account_number, iban, bank_code
               FROM currencies
              WHERE account_number IS NOT NULL OR iban IS NOT NULL
              UNION ALL
             SELECT supplier_id, account_number, iban, bank_code
               FROM supplier_bank_accounts
              WHERE is_active = 1'
        );
        if ($stmt === false) {
            return null;
        }
        $matches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (AccountNumberNormalizer::matchesAny($accountNumber, $row['account_number'] ?? null, $row['iban'] ?? null)) {
                $matches[] = $row;
            }
        }
        $supplierIds = array_values(array_unique(array_filter(
            array_map(static fn (array $row): int => (int) $row['supplier_id'], $matches),
            static fn (int $supplierId): bool => $supplierId > 0,
        )));
        $bankCodes = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['bank_code'] ?? '')),
            $matches,
        ))));
        return [
            'supplier_id' => count($supplierIds) === 1 ? $supplierIds[0] : null,
            'bank_code' => count($bankCodes) === 1 ? $bankCodes[0] : null,
        ];
    }
}

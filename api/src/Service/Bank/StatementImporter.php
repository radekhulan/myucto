<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentSettlementRecognizer;
use PDO;
use Psr\Log\LoggerInterface;

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
        // Mzdové odvody: výpis je zdroj pravdy, takže tady vzniká SKUTEČNÁ úhrada
        // a provizorní signál z avíza se uzavírá. Nullable — instalace bez mezd.
        private readonly ?PayrollPaymentSettlementRecognizer $payrollSettlements = null,
        private readonly ?LoggerInterface $logger = null,
        // Pravidla pohybů v daňové evidenci (issue #140) — ignorovat / zařadit v peněžním
        // deníku. Nullable a mimo daňovou evidenci no-op.
        private readonly ?\MyInvoice\Service\TaxEvidence\TaxEvidenceBankRules $taxEvidenceRules = null,
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
     *               parsed_transactions:int, skipped_duplicates:int, superseded_notices:int,
     *               warnings:list<array{code:string,message:string,parsed?:int,inserted?:int,skipped?:int}>}
     */
    public function import(string $content, string $fileName, ?int $userId, ?int $currencyId = null, array $reconciliationConfirmations = [], ?array $parsed = null, ?array $ignoreDecision = null): array
    {
        // Volající, který výpis už rozparsoval kvůli hlavičce, ho předá - soubor se
        // nečte podruhé.
        $parsed = $this->withRegisteredAccountFormat($parsed ?? $this->parser->parse($content));
        $account = $currencyId !== null ? $this->loadCurrencyById($currencyId) : $this->lookupAccount($parsed['header']['account_number']);
        $owner = $currencyId !== null ? $account : $this->lookupRegisteredOwner($parsed['header']['account_number']);
        if (!empty($account['id']) && !empty($owner['supplier_id']) && $owner['supplier_id'] === $account['supplier_id']) {
            return $this->importScoped($parsed, $content, $fileName, $userId, (int) $account['id'], (int) $account['supplier_id'], 'gpc', false, $reconciliationConfirmations, $ignoreDecision);
        }
        return $this->persist($parsed, $content, $fileName, $userId, $currencyId, 'gpc');
    }

    /**
     * GPC z banky, která čísla účtů píše ve „vnitřním formátu" (KB, MONETA: tytéž
     * číslice v jiném pořadí), převede na ediční tvar. Rozhoduje registr vlastních
     * účtů: převádí se jen tehdy, když číslo účtu z hlavičky v něm není a jeho ediční
     * podoba ano. Bez převodu by výpis nešel přiřadit účtu firmy a protiúčty by
     * nesouhlasily s pohyby z jiných zdrojů, takže by se tytéž platby založily znovu.
     *
     * @param array{header:array<string,mixed>,transactions:list<array<string,mixed>>} $parsed
     * @return array{header:array<string,mixed>,transactions:list<array<string,mixed>>}
     */
    public function withRegisteredAccountFormat(array $parsed): array
    {
        $raw = trim((string) ($parsed['header']['account_number'] ?? ''));
        $edition = GpcParser::internalToEdition($raw);
        if ($edition === null || $edition === $raw
            || $this->registeredAccounts($raw) !== [] || $this->registeredAccounts($edition) === []) {
            return $parsed;
        }
        return GpcParser::withEditionAccounts($parsed);
    }

    public function importConnected(string $content, string $fileName, ?int $userId, int $currencyId, int $supplierId, array $reconciliationConfirmations = []): array
    {
        return $this->importConnectedParsed($this->parser->parse($content), $content, $fileName, $userId, $currencyId, $supplierId, 'gpc', $reconciliationConfirmations);
    }

    public function importConnectedParsed(array $parsed, string $content, string $fileName, ?int $userId, int $currencyId, int $supplierId, string $source = 'bank_api', array $reconciliationConfirmations = []): array
    {
        return $this->importScoped($parsed, $content, $fileName, $userId, $currencyId, $supplierId, $source, true, $reconciliationConfirmations);
    }

    private function importScoped(array $parsed, string $content, string $fileName, ?int $userId, int $currencyId, int $supplierId, string $source, bool $requireActive, array $reconciliationConfirmations = [], ?array $ignoreDecision = null): array
    {
        $pdo = $this->db->pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return $this->importScopedLocked($parsed, $content, $fileName, $userId, $currencyId, $supplierId, $source, $requireActive, $reconciliationConfirmations, $ignoreDecision);
        }
        $name = \MyInvoice\Infrastructure\Database\NamedLockName::for($this->db, 'bank-import', (string) $supplierId);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 30)');
        $lock->execute([$name]);
        if ((int) $lock->fetchColumn() !== 1) throw new \RuntimeException('Probíhá jiný import bankovních pohybů této firmy. Opakujte načtení později.');
        try {
            return $this->importScopedLocked($parsed, $content, $fileName, $userId, $currencyId, $supplierId, $source, $requireActive, $reconciliationConfirmations, $ignoreDecision);
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$name]);
        }
    }

    private function importScopedLocked(array $parsed, string $content, string $fileName, ?int $userId, int $currencyId, int $supplierId, string $source, bool $requireActive, array $reconciliationConfirmations = [], ?array $ignoreDecision = null): array
    {
        if (!in_array($source, ['gpc', 'bank_api', 'pdf'], true)) {
            throw new \InvalidArgumentException('Unsupported connected statement source.');
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Connected import requires its own transaction.');
        }
        $accountQuery = $pdo->prepare('SELECT account_number, iban, code, bank_code FROM currencies WHERE id = ? AND supplier_id = ?' . ($requireActive ? ' AND is_active = 1' : ''));
        $accountQuery->execute([$currencyId, $supplierId]);
        $account = $accountQuery->fetch(PDO::FETCH_ASSOC);
        if (!$account || !AccountNumberNormalizer::matchesAny(
            (string) $parsed['header']['account_number'], $account['account_number'], $account['iban'],
        )) {
            throw new \InvalidArgumentException('Bankovní výpis neodpovídá připojenému účtu firmy.');
        }
        $pdo->beginTransaction();
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $lock = $pdo->prepare('SELECT id FROM currencies WHERE supplier_id = ? ORDER BY id FOR UPDATE');
                $lock->execute([$supplierId]);
                $lock->fetchAll(PDO::FETCH_COLUMN);
            }
            $accountQuery->execute([$currencyId, $supplierId]);
            if ($accountQuery->fetch(PDO::FETCH_ASSOC) !== $account) {
                throw new \InvalidArgumentException('Nastavení účtu se během importu změnilo. Opakujte načtení.');
            }
            if ($source === 'bank_api' && trim((string) $account['account_number']) !== '') {
                $parsed['header']['account_number'] = trim((string) $account['account_number']);
            }
            $touched = new StatementImportTransactions();
            $result = $this->persist($parsed, $content, $fileName, $userId, $currencyId, $source, true, $touched, $reconciliationConfirmations, $supplierId, $ignoreDecision);
            $scope = $pdo->prepare("SELECT id FROM bank_statements WHERE id = ? AND supplier_id = ? AND source = ? AND currency = ? AND COALESCE(bank_code, '') = ?");
            $scope->execute([$result['statement_id'], $supplierId, $source, $account['code'], $account['bank_code'] ?? '']);
            if ($scope->fetchColumn() === false) {
                throw new \InvalidArgumentException('Výpis nelze přiřadit připojenému účtu firmy.');
            }
            $affectedStatements = [$result['statement_id']];
            $transactionScope = $pdo->prepare('SELECT bs.id, bs.supplier_id FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id WHERE bt.id = ?');
            foreach ($touched->all() as $txId) {
                $transactionScope->execute([$txId]);
                $owner = $transactionScope->fetch(PDO::FETCH_ASSOC);
                if (!$owner || (int) $owner['supplier_id'] !== $supplierId) {
                    throw new \InvalidArgumentException('Pohyb nelze přiřadit připojenému účtu firmy.');
                }
                $affectedStatements[] = (int) $owner['id'];
            }
            $monthly = new BankApiMonthlyStatements($pdo);
            // PDF za období se do měsíčního výpisu neskládá (BankApiMonthlyStatements::PROJECTABLE),
            // zůstává samostatným dokladem i na účtu, který jinak skládá.
            if ($source === 'bank_api'
                || ($parsed['header']['period_kind'] ?? 'period') === 'day'
                || $source !== 'pdf' && $monthly->aggregatesMonthly($supplierId, (string) $parsed['header']['account_number'], (string) ($account['bank_code'] ?? ''), (string) $account['code'])) {
                $months = $monthly->projectAccount(
                    $supplierId, (string) $parsed['header']['account_number'], (string) ($account['bank_code'] ?? ''), (string) $account['code'], $userId,
                );
                $result['evidence_statement_id'] = $result['statement_id'];
                $result['statement_ids'] = $monthly->monthIds((int) $result['statement_id'], $supplierId);
                $result['statement_id'] = $result['statement_ids'][array_key_last($result['statement_ids'])];
                foreach ($months as $ids) array_push($affectedStatements, ...$ids);
                array_push($affectedStatements, ...$result['statement_ids']);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $result['superseded_notices'] = 0;
        $failure = $this->processSafely($touched->toProcess(), (int) ($result['evidence_statement_id'] ?? $result['statement_id']), function () use ($pdo, $touched, $userId, &$result): void {
            $pendingIds = [];
            $state = $pdo->prepare('SELECT match_status FROM bank_transactions WHERE id = ?');
            foreach ($touched->toProcess() as $txId) {
                $state->execute([$txId]);
                $matchStatus = $state->fetchColumn();
                if ($matchStatus === 'unmatched') {
                    $pendingIds[] = $txId;
                } else {
                    if ($matchStatus === 'auto_exact') $this->matcher->match($txId);
                    $this->bankPosting?->handleTransaction($txId, $userId);
                }
            }
            $result['superseded_notices'] = $this->processTransactions($pendingIds, $userId)['superseded'];
        });
        $result = $this->withProcessingOutcome($result, $failure);
        $update = $pdo->prepare('UPDATE bank_statements SET matched_count = ? WHERE id = ?');
        foreach (array_unique($affectedStatements) as $statementId) {
            $matched = (int) $pdo->query("SELECT COUNT(*) FROM bank_transactions bt WHERE " . StatementTransactionScope::sql((int) $statementId) . " AND bt.match_status IN ('auto_exact', 'auto_partial', 'manual')")->fetchColumn();
            $update->execute([$matched, $statementId]);
            if ($statementId === $result['statement_id']) $result['matched'] = $matched;
        }
        $result['matched'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM bank_transactions bt WHERE " . StatementTransactionScope::sql((int) ($result['evidence_statement_id'] ?? $result['statement_id']))
            . " AND bt.match_status IN ('auto_exact', 'auto_partial', 'manual')"
        )->fetchColumn();
        try {
            $this->payrollSettlements?->recognizeForSupplier($supplierId, $userId);
        } catch (\Throwable) {
        }
        // Až po párování: pravidla berou jen pohyby, které nenašly doklad.
        try {
            $this->taxEvidenceRules?->apply($supplierId, null, $userId);
        } catch (\Throwable $e) {
            $this->logger?->warning('Pravidla pohybů daňové evidence selhala: ' . $e->getMessage());
        }
        return $result;
    }

    /**
     * Persist výpis naparsovaný bank-specifickým PDF parserem (Creditas a další —
     * viz {@see \MyInvoice\Service\Bank\Pdf\BankStatementPdfParserRegistry}). Stejná
     * dedupe/matcher/reconciler logika jako {@see import()}, ale zdrojové bajty jsou
     * PDF (uloží se do pdf_content, ne file_content — žádný GPC ekvivalent neexistuje).
     *
     * @param array{header:array,transactions:list<array>} $parsed
     */
    public function importParsedPdf(array $parsed, string $pdfBytes, string $fileName, ?int $userId, ?int $currencyId = null, ?int $supplierId = null, ?array $ignoreDecision = null): array
    {
        // Denní výpis („výpis při pohybu") se v přehledu nezobrazuje samostatně —
        // skládá se do měsíčního výpisu účtu stejně jako pohyby ze strojového feedu.
        // Projekci umí jen scoped cesta (zámek + transakce), a ta potřebuje jednoznačný
        // měnový účet firmy; bez něj se výpis uloží postaru, jako samostatný doklad.
        // Potvrzený přenos ignorování také potřebuje atomické uložení pod zámkem účtu.
        if ($ignoreDecision !== null || ($parsed['header']['period_kind'] ?? 'period') === 'day') {
            $account = $currencyId !== null ? $this->loadCurrencyById($currencyId) : $this->lookupAccount($parsed['header']['account_number']);
            $owner = $currencyId !== null ? $account : $this->lookupRegisteredOwner($parsed['header']['account_number']);
            if (!empty($account['id']) && !empty($owner['supplier_id']) && $owner['supplier_id'] === $account['supplier_id']) {
                return $this->importScoped($parsed, $pdfBytes, $fileName, $userId, (int) $account['id'], (int) $account['supplier_id'], 'pdf', false, [], $ignoreDecision);
            }
        }
        return $this->persist($parsed, $pdfBytes, $fileName, $userId, $currencyId, 'pdf', false, null, [], $supplierId);
    }

    /**
     * @param array{header:array,transactions:list<array>} $parsed
     * @param string $rawBytes Originální bajty souboru — hashují se pro dedup a ukládají
     *   se buď do file_content (source='gpc') nebo pdf_content (source='pdf').
     */
    private function persist(array $parsed, string $rawBytes, string $fileName, ?int $userId, ?int $currencyId, string $source, bool $deferProcessing = false, ?StatementImportTransactions $touched = null, array $reconciliationConfirmations = [], ?int $supplierId = null, ?array $ignoreDecision = null): array
    {
        $touched ??= new StatementImportTransactions();
        $hash = hash('sha256', $rawBytes);
        $pdo = $this->db->pdo();

        if (!empty($parsed['header']['reconstructed'])) {
            throw new \InvalidArgumentException('GPC vytvořené MyÚčtem je export pro jiný systém, nikoli bankou potvrzený výpis. Nahrajte originální výpis banky.');
        }
        $h = $parsed['header'];
        $explicitAccount = $currencyId !== null;
        $account = $explicitAccount
            ? $this->loadCurrencyById($currencyId)
            : $this->lookupAccount($h['account_number']);
        $registeredOwner = $this->lookupRegisteredOwner($h['account_number']);
        $statementSupplierId = $supplierId ?? ($explicitAccount
            ? (isset($account['supplier_id']) && (int) $account['supplier_id'] > 0
                ? (int) $account['supplier_id']
                : null)
            : ($registeredOwner['supplier_id'] ?? null));

        if ($statementSupplierId !== null && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $this->claimLegacyStatements($pdo, $statementSupplierId, (string) $h['account_number']);
        }

        $exists = $pdo->prepare('SELECT id FROM bank_statements WHERE (supplier_id = ? OR (supplier_id IS NULL AND ? IS NULL)) AND file_hash = ?');
        $exists->execute([$statementSupplierId, $statementSupplierId, $hash]);
        $existingId = $exists->fetchColumn();
        if ($existingId !== false) {
            if ($deferProcessing) {
                $query = $pdo->prepare('SELECT bt.id FROM bank_transactions bt WHERE ' . StatementTransactionScope::sql((int) $existingId) . ' ORDER BY bt.id');
                $query->execute();
                foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $touched->linked((int) $id);
                }
            }
            return [
                'statement_id' => (int) $existingId,
                'transactions' => 0,
                'matched' => 0,
                'duplicate' => true,
                'ignored_transferred' => 0,
                'parsed_transactions' => count($parsed['transactions']),
                'skipped_duplicates' => 0,
                'warnings' => [],
            ];
        }

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
        $accountCurrency = $account['code'] ?? null;
        $accountBankCode = $account['bank_code'] ?? $registeredOwner['bank_code'] ?? null;
        $statementCurrency = $accountCurrency
            ?? $this->detectStatementCurrency($parsed['transactions']);

        $identities = $this->transactionIdentities($parsed['transactions'], (string) $h['account_number'], $accountBankCode, $accountCurrency, $statementCurrency, $statementSupplierId);

        $crossSource = $deferProcessing && $statementSupplierId !== null
            ? (new AuthoritativeTransactionReconciler($pdo))->candidates(
                $parsed['transactions'], $statementSupplierId, (string) $h['account_number'],
                (string) $accountBankCode, (string) $statementCurrency, $source,
                $reconciliationConfirmations, array_column($identities, 'fingerprint'),
                array_column($identities, 'candidates'),
            ) : [];

        // Evidované pohyby a převzaté otisky pro všechny kandidáty souboru se načtou
        // předem po dávkách, ne dotazem na každý řádek a kandidáta. Co tahle smyčka sama
        // založí nebo propojí, se do map doplňuje, takže pozdější řádky souboru to vidí
        // stejně, jako by se ptaly databáze.
        $allCandidates = array_values(array_unique(array_merge(...array_map(
            static fn (array $identity): array => $identity['candidates'],
            array_values($identities),
        ) ?: [[]])));
        $storedIds = $this->storedFingerprints($pdo, $allCandidates, $statementSupplierId);
        $aliasMap = $deferProcessing ? $this->aliasFingerprints($pdo, $allCandidates, $statementSupplierId) : [];

        $transfer = new IgnoreNoticeTransfer($this->db);
        $selectedIgnores = [];
        if ($ignoreDecision !== null && $account !== null && $statementSupplierId !== null) {
            $excluded = array_keys($crossSource);
            foreach ($identities as $index => $identity) {
                foreach ($identity['candidates'] as $candidate) {
                    if (isset($storedIds[$candidate]) || !empty($aliasMap[$candidate])) {
                        $excluded[] = $index;
                        break;
                    }
                }
            }
            $selectedIgnores = $transfer->select($parsed['transactions'], $account, $hash, $ignoreDecision, $excluded);
        }
        $ignoredTransferred = 0;

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
        $fileContent   = in_array($source, ['gpc', 'bank_api'], true) ? $rawBytes : null;
        $pdfContent    = $source === 'pdf' ? $rawBytes : null;
        $pdfName       = $source === 'pdf' ? $fileName : null;
        $pdfHash       = $source === 'pdf' ? $hash : null;
        $pdfSize       = $source === 'pdf' ? strlen($rawBytes) : null;
        $pdfUploadedAt = $source === 'pdf' ? date('Y-m-d H:i:s') : null;

        $pdo->prepare(
            'INSERT INTO bank_statements
                 (source, period_kind, file_name, file_hash, file_content, pdf_content, pdf_name, pdf_hash, pdf_size_bytes, pdf_uploaded_at,
                  supplier_id, account_number, bank_code, currency,
                  statement_number, statement_date,
                  prev_balance, curr_balance, credit_total, debit_total, transaction_count, imported_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $source, ($h['period_kind'] ?? 'period') === 'day' ? 'day' : 'period',
            $fileName, $hash, $fileContent, $pdfContent, $pdfName, $pdfHash, $pdfSize, $pdfUploadedAt,
            $statementSupplierId, $h['account_number'], $accountBankCode, $statementCurrency,
            $h['statement_number'], $h['statement_date'],
            $h['prev_balance'], $h['curr_balance'], $h['credit_total'], $h['debit_total'],
            count($parsed['transactions']), $userId,
        ]);
        $statementId = (int) $pdo->lastInsertId();

        $insertTx = $pdo->prepare(
            'INSERT INTO bank_transactions
                 (statement_id, posted_at, amount, currency, variable_symbol, constant_symbol, specific_symbol,
                  counterparty_account, counterparty_bank, counterparty_name, card_last4, description, bank_ref, import_fingerprint, portable_fingerprint,
                  processing_pending_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $pendingSince = StatementProcessingState::pendingSince();
        $findDuplicateTx = $pdo->prepare(
            'SELECT bt.id FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
             WHERE bt.import_fingerprint = ? AND (bs.supplier_id = ? OR (bs.supplier_id IS NULL AND ? IS NULL)) LIMIT 1'
        );
        $linkImport = $deferProcessing ? $pdo->prepare(
            'INSERT INTO bank_transaction_imports (statement_id, bank_transaction_id, import_fingerprint, supplier_id, original_statement_id)
             SELECT ?, ?, ?, bs.supplier_id, bs.id FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id WHERE bt.id = ?'
        ) : null;

        $matched = 0;
        $inserted = 0;
        $skipped = 0;
        $ignoredIds = [];
        foreach ($parsed['transactions'] as $index => $tx) {
            ['currency' => $txCurrency, 'fingerprint' => $fingerprint, 'portable_fingerprint' => $portableFingerprint, 'candidates' => $candidates] = $identities[$index];
            $alreadyStored = false;
            $duplicateId = $crossSource[$index] ?? false;
            if ($duplicateId === false && $deferProcessing) {
                $aliasIds = [];
                foreach ($candidates as $candidate) {
                    foreach ($aliasMap[$candidate] ?? [] as $aliasId => $_) {
                        $aliasIds[$aliasId] = true;
                    }
                }
                if (count($aliasIds) > 1) throw new StatementReconciliationException();
                if ($aliasIds !== []) $duplicateId = (int) array_key_first($aliasIds);
            }
            if ($duplicateId !== false) {
                $touched->linked((int) $duplicateId);
                $this->linkImport($linkImport, $aliasMap, $statementId, (int) $duplicateId, $fingerprint);
                $skipped++;
                continue;
            }
            foreach ($candidates as $candidate) {
                $duplicateId = $storedIds[$candidate] ?? false;
                if ($duplicateId !== false) {
                    $touched->linked((int) $duplicateId);
                    $alreadyStored = true;
                    break;
                }
            }
            if ($alreadyStored) {
                $this->linkImport($linkImport, $aliasMap, $statementId, (int) $duplicateId, $fingerprint);
                $skipped++;
                continue;
            }
            try {
                $insertTx->execute([
                    $statementId, $tx['posted_at'], $tx['amount'], $txCurrency,
                    $tx['variable_symbol'], $tx['constant_symbol'], $tx['specific_symbol'],
                    $tx['counterparty_account'], $tx['counterparty_bank'], $tx['counterparty_name'],
                    \MyInvoice\Service\Bank\Card\CardNumberMask::forParsedTransaction($tx),
                    $tx['description'], $tx['bank_ref'], $fingerprint, $portableFingerprint,
                    $pendingSince,
                ]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[0] ?? null) === '23000'
                    && str_contains($e->getMessage(), 'uq_bt_import_fingerprint')) {
                    $findDuplicateTx->execute([$fingerprint, $statementSupplierId, $statementSupplierId]);
                    $concurrentId = $findDuplicateTx->fetchColumn();
                    if ($concurrentId === false) throw $e;
                    $storedIds[$fingerprint] = (int) $concurrentId;
                    $touched->linked((int) $concurrentId);
                    $skipped++;
                    continue;
                }
                throw $e;
            }
            $txId = (int) $pdo->lastInsertId();
            $storedIds[$fingerprint] = $txId;
            $touched->inserted($txId);
            $inserted++;

            if (isset($selectedIgnores[$index])) {
                $transfer->apply($txId, $selectedIgnores[$index], $statementSupplierId, $userId);
                $ignoredTransferred++;
                $ignoredIds[$txId] = true;
            }
        }

        $processed = ['matched' => 0, 'superseded' => 0];
        $failure = null;
        if (!$deferProcessing) {
            $failure = $this->processSafely($touched->toProcess(), $statementId, function () use (&$processed, $touched, $ignoredIds, $userId): void {
                $processed = $this->processTransactions(array_values(array_filter(
                    $touched->toProcess(),
                    static fn (int $id): bool => !isset($ignoredIds[$id]),
                )), $userId);
            });
        }
        $matched = $processed['matched'];

        $pdo->prepare('UPDATE bank_statements SET matched_count = ?, transaction_count = ? WHERE id = ?')
            ->execute([$matched, $inserted, $statementId]);

        if (!$deferProcessing && $statementSupplierId !== null && $inserted > 0) {
            try {
                $this->payrollSettlements?->recognizeForSupplier($statementSupplierId, $userId);
            } catch (\Throwable) {
                // Rozpoznání mzdových úhrad je nadstavba — nesmí shodit import výpisu.
            }
        }

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

        $result = [
            'statement_id'        => $statementId,
            'transactions'        => $inserted,
            'matched'             => $matched,
            'duplicate'           => false,
            'ignored_transferred'  => $ignoredTransferred,
            'parsed_transactions' => $parsedCount,
            'skipped_duplicates'  => $skipped,
            'superseded_notices'  => $processed['superseded'],
            'warnings'            => $warnings,
        ];
        return $deferProcessing ? $result : $this->withProcessingOutcome($result, $failure);
    }

    /**
     * Zpracování založených pohybů po jejich uložení. Selhání import nezahodí: pohyby
     * v evidenci zůstávají, dostanou chybu ({@see StatementProcessingState}) a výpis
     * upozorní na „Přepárovat výpis". Opakované nahrání souboru by je už nezpracovalo.
     *
     * @param list<int> $transactionIds
     * @param callable():void $work
     * @return ?\Throwable Selhání zpracování, nebo null.
     */
    private function processSafely(array $transactionIds, int $statementId, callable $work): ?\Throwable
    {
        $pdo = $this->db->pdo();
        try {
            $work();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            try {
                StatementProcessingState::fail($pdo, $transactionIds, $e);
            } catch (\Throwable) {
                throw $e;
            }
            $this->logger?->error('Bank statement processing failed after import', [
                'statement_id' => $statementId,
                'transactions' => count($transactionIds),
                'exception' => $e,
            ]);
            return $e;
        }
        StatementProcessingState::complete($pdo, $transactionIds);
        return null;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function withProcessingOutcome(array $result, ?\Throwable $failure): array
    {
        $result['processing_failed'] = $failure !== null;
        if ($failure !== null) {
            $result['warnings'][] = [
                'code'    => 'processing_failed',
                'message' => 'Výpis je uložený, ale párování a zaúčtování jeho pohybů se nepodařilo dokončit. Otevřete výpis a použijte „Přepárovat výpis".',
                'error'   => mb_substr($failure->getMessage(), 0, 500, 'UTF-8'),
            ];
        }
        return $result;
    }

    /**
     * Pohyby firmy (výpisu bez firmy) evidované pod některým z otisků. Otisk je
     * v `bank_transactions` jedinečný, na otisk tedy nejvýš jeden pohyb.
     *
     * @param list<string> $fingerprints
     * @return array<string,int> otisk => id pohybu
     */
    private function storedFingerprints(PDO $pdo, array $fingerprints, ?int $supplierId): array
    {
        $out = [];
        foreach (array_chunk($fingerprints, 500) as $chunk) {
            $stmt = $pdo->prepare(
                'SELECT bt.import_fingerprint, bt.id FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
                  WHERE bt.import_fingerprint IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')
                    AND (bs.supplier_id = ? OR (bs.supplier_id IS NULL AND ? IS NULL))'
            );
            $stmt->execute([...$chunk, $supplierId, $supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$fingerprint, $id]) {
                $out[(string) $fingerprint] ??= (int) $id;
            }
        }
        return $out;
    }

    /**
     * Pohyby, na které dřívější importy firmy otisk už převedly (`bank_transaction_imports`).
     *
     * @param list<string> $fingerprints
     * @return array<string,array<int,true>> otisk => id pohybů
     */
    private function aliasFingerprints(PDO $pdo, array $fingerprints, ?int $supplierId): array
    {
        $out = [];
        foreach (array_chunk($fingerprints, 500) as $chunk) {
            $stmt = $pdo->prepare(
                'SELECT bti.import_fingerprint, bti.bank_transaction_id FROM bank_transaction_imports bti
                   JOIN bank_statements bs ON bs.id = bti.statement_id
                  WHERE bti.import_fingerprint IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') AND bs.supplier_id = ?'
            );
            $stmt->execute([...$chunk, $supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$fingerprint, $id]) {
                $out[(string) $fingerprint][(int) $id] = true;
            }
        }
        return $out;
    }

    /** @param array<string,array<int,true>> $aliasMap MĚNÍ SE */
    private function linkImport(?\PDOStatement $linkImport, array &$aliasMap, int $statementId, int $transactionId, string $fingerprint): void
    {
        if ($linkImport === null) {
            return;
        }
        $linkImport->execute([$statementId, $transactionId, $fingerprint, $transactionId]);
        if ($linkImport->rowCount() > 0) {
            $aliasMap[$fingerprint][$transactionId] = true;
        }
    }

    /** @return array{matched:int,superseded:int} */
    private function processTransactions(array $transactionIds, ?int $userId): array
    {
        if ($transactionIds === []) return ['matched' => 0, 'superseded' => 0];
        $matched = 0;
        $superseded = 0;
        $matchIds = [];
        foreach ($transactionIds as $txId) {
            $takeover = $this->reconciler->takeOverFromEmailNotice($txId);
            if ($takeover !== null) {
                $matched++;
                if ($takeover['match_status'] === 'auto_exact') $this->matcher->match($txId);
                $this->bankPosting?->handleTransaction($txId, $userId);
                continue;
            }
            // Nespárované avízo se nemá s čím párovat, takže ho převzetí výš minulo —
            // bez tohohle kroku by tentýž karetní výdaj / poplatek zůstal v evidenci
            // dvakrát (#76). Nic se nepřepojuje, avízo se jen označí za nahrazené.
            if ($this->reconciler->supersedeUnmatchedEmailNotice($txId) !== null) $superseded++;
            $matchIds[] = $txId;
        }
        foreach ($this->matcher->matchBatch($matchIds) as $txId => $result) {
            if (in_array($result['status'], ['auto_exact', 'auto_partial'], true)) {
                $matched++;
            }
            $this->bankPosting?->handleTransaction((int) $txId, $userId, !empty($result['requires_review']));
        }
        return ['matched' => $matched, 'superseded' => $superseded];
    }

    private function transactionIdentities(array $transactions, string $accountNumber, ?string $accountBankCode, ?string $accountCurrency, ?string $statementCurrency, ?int $supplierId): array
    {
        // Bankovní reference je identitou pohybu jen tehdy, když je v souboru JEDINEČNÁ.
        // Některé banky do pole čísla dokladu píšou konstantu nebo denní pořadí — kdyby
        // se taková hodnota vzala jako identita, splynuly by v otisku dva různé pohyby.
        $referenceCounts = [];
        foreach ($transactions as $tx) {
            $ref = trim((string) ($tx['bank_ref'] ?? ''));
            if ($ref !== '') {
                $referenceCounts[$ref] = ($referenceCounts[$ref] ?? 0) + 1;
            }
        }
        /** @var array<string,int> $identitySeen Pořadí pohybu se shodným náhradním otiskem V RÁMCI souboru. */
        $identitySeen = [];

        $result = [];
        foreach ($transactions as $index => $tx) {
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
            $legacyFingerprint = $this->transactionFingerprint(
                $accountNumber,
                $accountBankCode,
                $txCurrency,
                $tx,
                $identity,
            );
            $fingerprint = $supplierId === null
                ? $legacyFingerprint
                : hash('sha256', 'supplier:' . $supplierId . ':' . $legacyFingerprint);

            // Zpětná kompatibilita: pohyby naimportované DŘÍV (kdy GPC bank_ref neplnil)
            // nesou otisk z náhradní identity bez pořadí. Bez tohohle kandidáta by je
            // překrývající se výpis po upgradu založil ZNOVU — z opravy tiché ztráty dat
            // by se stalo tiché zdvojení. Legacy otisk platí jen pro PRVNÍ výskyt identity
            // v souboru, aby druhá legitimní platba dál prošla.
            $candidates = [$fingerprint];
            if ($fingerprint !== $legacyFingerprint) {
                $candidates[] = $legacyFingerprint;
            }
            if ($ordinal === 0) {
                $legacy = $this->transactionFingerprint(
                    $accountNumber,
                    $accountBankCode,
                    $txCurrency,
                    $tx,
                    $this->fallbackIdentity($tx, 0),
                );
                if ($supplierId !== null) {
                    $scopedLegacy = hash('sha256', 'supplier:' . $supplierId . ':' . $legacy);
                    if (!in_array($scopedLegacy, $candidates, true)) {
                        $candidates[] = $scopedLegacy;
                    }
                }
                if (!in_array($legacy, $candidates, true)) {
                    $candidates[] = $legacy;
                }
            }
            $result[$index] = ['currency' => $txCurrency, 'fingerprint' => $fingerprint, 'portable_fingerprint' => $legacyFingerprint, 'candidates' => $candidates];
        }
        return $result;
    }

    private function claimLegacyStatements(PDO $pdo, int $supplierId, string $accountNumber): void
    {
        $normalized = AccountNumberNormalizer::normalize($accountNumber);
        if ($normalized === '') return;

        $owned = BankStatementOwnershipResolver::sql('bs');
        $candidates = $pdo->prepare(
            'SELECT bs.id, bs.account_number FROM bank_statements bs
              WHERE bs.supplier_id IS NULL
                AND ' . $owned . '
              ORDER BY bs.id' . ($pdo->inTransaction() ? ' FOR UPDATE' : '')
        );
        $candidates->execute(BankStatementOwnershipResolver::params($supplierId));
        $claim = $pdo->prepare(
            'UPDATE bank_statements bs SET bs.supplier_id = ?
              WHERE bs.id = ? AND bs.supplier_id IS NULL AND ' . $owned
        );
        foreach ($candidates->fetchAll(PDO::FETCH_ASSOC) as $legacy) {
            if (AccountNumberNormalizer::normalize((string) $legacy['account_number']) !== $normalized) continue;
            try {
                $claim->execute([$supplierId, (int) $legacy['id'], ...BankStatementOwnershipResolver::params($supplierId)]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[0] ?? null) !== '23000'
                    || (!str_contains($e->getMessage(), 'uq_bs_supplier_hash')
                        && !str_contains($e->getMessage(), 'uq_bs_scope_hash'))) {
                    throw $e;
                }
            }
        }
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
        $stmt = $this->db->pdo()->prepare('SELECT id, supplier_id, account_number, iban, code, bank_code FROM currencies WHERE id = ?');
        $stmt->execute([$currencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return [
            'id'        => (int) $row['id'],
            'account_number' => $row['account_number'],
            'iban' => $row['iban'],
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
        $matches = $this->registeredAccounts($accountNumber);
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

    /**
     * Záznamy obou registrů vlastních účtů (currencies + supplier_bank_accounts),
     * kterým číslo účtu odpovídá.
     *
     * @return list<array{supplier_id:mixed,account_number:mixed,iban:mixed,bank_code:mixed}>
     */
    private function registeredAccounts(string $accountNumber): array
    {
        if ($accountNumber === '') {
            return [];
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
            return [];
        }
        $matches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (AccountNumberNormalizer::matchesAny($accountNumber, $row['account_number'] ?? null, $row['iban'] ?? null)) {
                $matches[] = $row;
            }
        }
        return $matches;
    }
}

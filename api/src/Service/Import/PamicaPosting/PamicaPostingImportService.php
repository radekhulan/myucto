<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import\PamicaPosting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\PayrollPostingImportRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\InternalDocumentException;
use MyInvoice\Service\Accounting\InternalDocumentService;
use MyInvoice\Service\Accounting\OtherItemCounterpartyAccount;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Import\ClientResolver;

/**
 * Měsíční zaúčtování mezd z PAMICY: náhled a načtení dataPacku pro POHODU.
 *
 * Mzdy dál počítá PAMICA, do MyÚčta jde jen jejich zaúčtování:
 *  - interní doklad (agenda Interní doklady) se zápisem mzdových nákladů, typicky 521/331,
 *  - ostatní závazky (agenda Ostatní závazky) k finančnímu úřadu, pojišťovnám
 *    a zaměstnancům, s protistranou, symboly, splatností a účtem pro platební příkaz.
 *    Závazek se zaúčtováním (např. 524/336, 331/336) jde do deníku přes zaúčtování
 *    ostatní položky. Závazek „bez zaúčtování" (předkontace BEZ331000) se jen potvrdí bez
 *    vlastního zápisu: předpis 331 už nese interní doklad, úhrada pak účtuje 331/221.
 *
 * Všechno vzniká přes služby agend (číselné řady, kontroly období, zámky, deník), import
 * nepíše do deníku sám. Celé načtení je jedna transakce a měsíc (IČO + symPar) jde načíst
 * jen jednou ({@see PayrollPostingImportRepository}).
 *
 * Kódy účtů PAMICA uvádí šestimístně (521000, 336001). V osnově MyÚčta se hledají
 * v tomto pořadí: stejný kód, tečkovaná analytika (336.001) a u analytiky 000 samotná
 * syntetika (521), pokud ji osnova analyticky nečlení. Chybějící analytiku pod existující
 * syntetikou nabídne náhled založit, chybějící syntetika import zastaví.
 *
 * Zkratky předkontací POHODY (7Int, 4Oz) překládá na účty mapování firmy
 * ({@see PamicaPostingCodeMap}); jeho účty jsou už kódy osnovy MyÚčta a hledají se přímo.
 */
final class PamicaPostingImportService
{
    public const APPLICATION = 'PAMICA';

    public function __construct(
        private readonly Connection $db,
        private readonly PamicaPostingParser $parser,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly DocumentLockService $locks,
        private readonly InternalDocumentService $internalDocuments,
        private readonly OtherItemService $otherItems,
        private readonly ClientResolver $clients,
        private readonly PayrollPostingImportRepository $imports,
        private readonly PamicaPostingCodeMap $codeMap,
    ) {}

    /**
     * Náhled bez zápisu: doklady, účty a kontroly. Zkratky předkontací bez přiřazených
     * účtů vrátí chybou `unmapped_accounting_codes` ({@see PamicaPostingParser}).
     *
     * @return array<string,mixed>
     */
    public function preview(int $supplierId, string $bytes): array
    {
        $parsed = $this->parse($supplierId, $bytes);
        return $this->analyze($supplierId, $parsed, false) + [
            'file_sha256' => hash('sha256', $bytes),
            'code_map' => $this->usedCodeMap($supplierId, $parsed),
        ];
    }

    /** @return array<string,mixed> */
    private function parse(int $supplierId, string $bytes): array
    {
        return $this->parser->parse($bytes, $this->codeMap->forSupplier($supplierId, self::APPLICATION));
    }

    /**
     * Přiřazení zkratek, které soubor používá (aby je náhled ukázal k úpravě).
     *
     * @param array<string,mixed> $parsed
     * @return list<array<string,mixed>>
     */
    private function usedCodeMap(int $supplierId, array $parsed): array
    {
        $codes = [];
        foreach ([...$parsed['internal_documents'], ...$parsed['commitments']] as $doc) {
            foreach ($doc['lines'] as $line) {
                if ($line['code'] !== null) $codes[PamicaPostingCodeMap::key($line['code'])] = $line['code'];
            }
        }
        return $this->codeMap->rows($supplierId, self::APPLICATION, array_values($codes));
    }

    /**
     * Načte zaúčtování v jedné transakci. Kontroly náhledu se opakují nad zamčenou evidencí
     * importů; jakákoli chyba vrátí vše zpět.
     *
     * @param array{user_id:?int,posted_by:?int,ip:?string,user_agent:?string} $audit
     * @return array<string,mixed>
     */
    public function apply(int $supplierId, string $bytes, ?string $fileName, bool $createMissingAccounts, array $audit): array
    {
        $parsed = $this->parse($supplierId, $bytes);
        $userId = $audit['user_id'];
        $pdo = $this->db->pdo();
        return TransactionFailure::retrying($pdo, function () use ($pdo, $supplierId, $parsed, $bytes, $fileName, $createMissingAccounts, $audit, $userId): array {
            $ownTx = !$pdo->inTransaction();
            if ($ownTx) $pdo->beginTransaction();
            try {
                $previous = $this->imports->find($supplierId, self::APPLICATION, $parsed['ico'], $parsed['sym_par'], true);
                $analysis = $this->analyze($supplierId, $parsed, true, $previous);
                $blocking = array_values(array_filter($analysis['checks'], static fn (array $c): bool =>
                    $c['level'] === 'error' && !($createMissingAccounts && $c['code'] === 'accounts_creatable')));
                if ($blocking !== []) {
                    throw new PamicaPostingException($blocking[0]['code'], $blocking[0]['message'], 409, ['checks' => $analysis['checks']]);
                }
                if ($previous !== null) {
                    // Dřívější načtení je celé stornované nebo smazané (jinak by kontrola výše stála).
                    $this->imports->delete($supplierId, (int) $previous['id']);
                }
                $created = $this->createMissingAccounts($supplierId, $analysis['accounts'], $parsed, $userId);
                $map = [];
                foreach ($analysis['accounts'] as $account) {
                    $map[$account['source']] = $account['target'] ?? $created[$account['source']] ?? null;
                }

                $internalIds = [];
                $internalNumbers = [];
                foreach ($parsed['internal_documents'] as $doc) {
                    $posted = $this->postInternalDocument($supplierId, $doc, $parsed, $map, $audit);
                    $internalIds[] = (int) $posted['id'];
                    $internalNumbers[] = (string) $posted['document_no'];
                }
                $itemIds = [];
                $items = [];
                foreach ($parsed['commitments'] as $commitment) {
                    $item = $this->createCommitment($supplierId, $commitment, $parsed, $map, $userId);
                    $itemIds[] = (int) $item['id'];
                    $items[] = ['id' => (int) $item['id'], 'document_no' => $item['document_no'], 'status' => $item['status'],
                        'title' => $item['title'], 'partner_name' => $item['partner_name'], 'amount' => (float) $item['amount']];
                }
                $importId = $this->imports->insert($supplierId, [
                    'application' => self::APPLICATION,
                    'ico' => $parsed['ico'],
                    'sym_par' => $parsed['sym_par'],
                    'period' => $parsed['period'],
                    'pack_id' => $parsed['pack_id'],
                    'file_name' => $fileName !== null ? mb_substr($fileName, 0, 255) : null,
                    'file_sha256' => hash('sha256', $bytes),
                    'total_amount' => $analysis['totals']['internal'],
                ], $internalIds, $itemIds, $userId);
                if ($ownTx) $pdo->commit();
            } catch (\Throwable $e) {
                if (!TransactionFailure::rollbackIfAborted($pdo, $e) && $ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw self::translate($e);
            }
            return [
                'import_id' => $importId,
                'sym_par' => $parsed['sym_par'],
                'period' => $parsed['period'],
                'internal_documents' => array_map(static fn (int $id, string $no): array => ['id' => $id, 'document_no' => $no],
                    $internalIds, $internalNumbers),
                'other_items' => $items,
                'created_accounts' => array_values($created),
                'totals' => $analysis['totals'],
            ];
        });
    }

    /**
     * @param array<string,mixed> $parsed
     * @param array<string,mixed>|null $previous dřívější načtení (u apply zamčené)
     * @return array<string,mixed>
     */
    private function analyze(int $supplierId, array $parsed, bool $forApply, ?array $previous = null): array
    {
        $checks = [];
        $supplierIc = $this->supplierIc($supplierId);
        if ($supplierIc === null) {
            $checks[] = self::check('supplier_ico_missing', 'error', 'Firma nemá vyplněné IČO, shodu se souborem nelze ověřit.');
        } elseif ($supplierIc !== $parsed['ico']) {
            $checks[] = self::check('ico_mismatch', 'error',
                'Soubor patří firmě s IČO ' . $parsed['ico'] . ', aktuální firma má IČO ' . $supplierIc . '.');
        } else {
            $checks[] = self::check('ico_match', 'ok', 'IČO v souboru odpovídá firmě.');
        }

        $doubleEntry = $this->otherItems->isDoubleEntry($supplierId);
        if (!$doubleEntry) {
            $checks[] = self::check('not_double_entry', 'error',
                'Zaúčtování mezd jde načíst jen do firmy vedené v podvojném účetnictví.');
        }

        // Kontrola období: datum účetního případu musí ležet v otevřeném, nezamčeném období.
        $dates = array_values(array_unique(array_merge(
            array_map(static fn (array $d): string => $d['accounting_date'], $parsed['internal_documents']),
            array_map(static fn (array $c): string => $c['accounting_date'], $parsed['commitments']),
        )));
        $periodProblem = null;
        foreach ($dates as $date) {
            if (!$this->hasPeriod($supplierId, $date)) {
                $periodProblem ??= self::check('no_accounting_period', 'error',
                    'Pro datum ' . $date . ' neexistuje účetní období. Založte ho v Účetnictví → Uzávěrka.');
                continue;
            }
            $lock = $this->locks->forDate($supplierId, $date);
            if ($lock->inClosedPeriod || $lock->inClosingPeriod || $lock->dateLocked) {
                $periodProblem ??= self::check('period_locked', 'error',
                    'Měsíc ' . self::monthLabel($parsed['period']) . ' je uzamčený nebo v uzavřeném období. Zaúčtování do něj načíst nejde.');
            }
        }
        $checks[] = $periodProblem ?? self::check('period_open', 'ok', 'Měsíc ' . self::monthLabel($parsed['period']) . ' je otevřený.');

        // Duplicita: stejný měsíc (IČO + symPar) už načtený a jeho doklady platí.
        $previous ??= $forApply ? null : $this->imports->find($supplierId, self::APPLICATION, $parsed['ico'], $parsed['sym_par']);
        if ($previous !== null && $this->imports->hasActiveDocuments($supplierId, $previous)) {
            $checks[] = self::check('already_imported', 'error',
                'Zaúčtování mezd ' . $parsed['sym_par'] . ' už bylo načteno ' . substr((string) $previous['created_at'], 0, 10)
                . '. Druhé načtení by zdvojilo náklady i závazky. Chcete-li ho načíst znovu, nejdřív stornujte jeho interní doklad i ostatní závazky.',
                ['internal_document_ids' => $previous['internal_document_ids'], 'other_item_ids' => $previous['other_item_ids']]);
        } else {
            $checks[] = self::check('not_imported_yet', 'ok', 'Zaúčtování ' . $parsed['sym_par'] . ' ještě nebylo načteno.');
        }

        // Účty: PAMICA → osnova firmy.
        $accounts = [];
        $codes = [];
        foreach ($parsed['internal_documents'] as $doc) {
            foreach ($doc['lines'] as $line) {
                $codes[$line['debit']] = true;
                $codes[$line['credit']] = true;
            }
        }
        foreach ($parsed['commitments'] as $c) {
            $codes[$c['credit']] = true;
            foreach ($c['lines'] as $line) {
                if ($line['debit'] !== null) $codes[$line['debit']] = true;
            }
        }
        $liabilityCodes = array_fill_keys(array_map(static fn (array $c): string => $c['credit'], $parsed['commitments']), true);
        ksort($codes, SORT_STRING);
        foreach (array_keys($codes) as $code) {
            $accounts[] = $this->resolveAccount($supplierId, (string) $code, $parsed['accounting_date'], isset($liabilityCodes[$code]));
        }
        $missing = array_values(array_filter($accounts, static fn (array $a): bool => $a['status'] === 'missing'));
        $creatable = array_values(array_filter($accounts, static fn (array $a): bool => $a['status'] === 'creatable'));
        $invalid = array_values(array_filter($accounts, static fn (array $a): bool => in_array($a['status'], ['inactive', 'not_liability', 'has_analytics'], true)));
        if ($missing !== []) {
            $checks[] = self::check('accounts_missing', 'error',
                'V účtové osnově chybí syntetické účty ' . implode(', ', array_map(static fn (array $a): string => $a['synthetic'], $missing))
                . ' (pro účty PAMICY ' . implode(', ', array_column($missing, 'source')) . '). Založte je v Účetnictví → Účtová osnova.');
        }
        if ($invalid !== []) {
            $checks[] = self::check('accounts_invalid', 'error',
                'Účty ' . implode(', ', array_map(static fn (array $a): string => (string) $a['target'], $invalid))
                . ' nejde použít: jsou neaktivní, syntetika má analytiky, nebo závazek neleží na účtu pasiv.');
        }
        if ($creatable !== []) {
            $checks[] = self::check('accounts_creatable', 'error',
                'V účtové osnově chybí analytické účty ' . implode(', ', array_map(static fn (array $a): string => (string) $a['proposed'], $creatable))
                . '. Import je může založit pod existující syntetiky, nebo je založte sami v Účtové osnově.');
        }
        if ($missing === [] && $invalid === [] && $creatable === []) {
            $checks[] = self::check('accounts_ok', 'ok', 'Všechny účty jsou v účtové osnově.');
        }

        // Účty protistran pro platební příkaz.
        $commitments = [];
        foreach ($parsed['commitments'] as $c) {
            $account = null;
            $accountError = null;
            if ($c['account_number'] !== null) {
                try {
                    $account = OtherItemCounterpartyAccount::normalize($c['account_number'], $c['bank_code']);
                } catch (OtherItemException $e) {
                    $accountError = $e->getMessage();
                    $checks[] = self::check('counterparty_account_invalid', 'warning',
                        'Závazek „' . $c['title'] . '" (' . ($c['partner']['name'] ?? '—') . '): ' . $e->getMessage() . ' Závazek se načte bez účtu protistrany.');
                }
            }
            $commitments[] = [
                'item_id' => $c['item_id'],
                'title' => $c['title'],
                'partner_name' => $c['partner']['name'] ?? null,
                'partner_kind' => $c['partner'] === null ? 'none' : ($c['partner']['is_person'] ? 'person' : 'company'),
                'variable_symbol' => $c['variable_symbol'],
                'constant_symbol' => $c['constant_symbol'],
                'document_date' => $c['document_date'],
                'accounting_date' => $c['accounting_date'],
                'due_on' => $c['due_on'],
                'payment_type' => $c['payment_type'],
                'account' => $account !== null && $account['counterparty_account'] !== null
                    ? $account['counterparty_account'] . ($account['counterparty_bank_code'] !== null ? '/' . $account['counterparty_bank_code'] : '')
                    : null,
                'account_error' => $accountError,
                'posting' => $c['without_posting'] ? 'without_posting' : 'posted',
                'credit' => $c['credit'],
                'lines' => $c['lines'],
                'amount' => $c['amount'],
            ];
            if ($c['variable_symbol'] !== null && preg_match('/^[0-9]{1,10}$/', $c['variable_symbol']) !== 1) {
                $checks[] = self::check('variable_symbol_invalid', 'warning',
                    'Závazek „' . $c['title'] . '": variabilní symbol „' . $c['variable_symbol'] . '" není číselný, načte se bez něj.');
            }
        }

        // Součty a kontrola účtu závazku: Dal z interních dokladů = MD ze závazků + závazky bez zaúčtování.
        $resolved = [];
        foreach ($accounts as $a) {
            $resolved[$a['source']] = (string) ($a['target'] ?? $a['proposed'] ?? $a['source']);
        }
        $totals = self::totals($parsed, $resolved);
        foreach ($totals['clearing'] as $code => $balance) {
            if (abs($balance['difference']) < 0.005) {
                $checks[] = self::check('clearing_balanced', 'ok',
                    'Účet ' . $code . ': hrubé mzdy ' . self::money($balance['credit']) . ' Kč = srážky a čisté mzdy '
                    . self::money($balance['debit'] + $balance['without_posting']) . ' Kč.', ['account' => $code]);
            } else {
                $checks[] = self::check('clearing_difference', 'warning',
                    'Účet ' . $code . ' po načtení nebude vyrovnaný: Dal ' . self::money($balance['credit']) . ' Kč, MD a závazky bez zaúčtování '
                    . self::money($balance['debit'] + $balance['without_posting']) . ' Kč (rozdíl ' . self::money($balance['difference']) . ' Kč).',
                    ['account' => $code]);
            }
        }

        array_push($checks, ...self::clearingAccountCommitments($parsed, $resolved));

        $errors = array_filter($checks, static fn (array $c): bool => $c['level'] === 'error');
        $onlyCreatable = $errors !== [] && array_values(array_unique(array_column($errors, 'code'))) === ['accounts_creatable'];
        return [
            'format' => 'pamica_payroll_posting',
            'source' => [
                'application' => $parsed['application'],
                'ico' => $parsed['ico'],
                'pack_id' => $parsed['pack_id'],
                'sym_par' => $parsed['sym_par'],
                'period' => $parsed['period'],
                'accounting_date' => $parsed['accounting_date'],
                'document_date' => $parsed['document_date'],
            ],
            'internal_documents' => array_map(static fn (array $d): array => [
                'item_id' => $d['item_id'], 'title' => $d['title'], 'document_date' => $d['document_date'],
                'accounting_date' => $d['accounting_date'], 'lines' => $d['lines'], 'amount' => $d['amount'],
            ], $parsed['internal_documents']),
            'commitments' => $commitments,
            'accounts' => $accounts,
            'totals' => $totals,
            'checks' => $checks,
            'can_apply' => $errors === [],
            'can_apply_with_new_accounts' => $onlyCreatable,
        ];
    }

    /**
     * Účet PAMICY → účet osnovy firmy k datu zaúčtování.
     *
     * @return array{source:string,synthetic:string,target:?string,proposed:?string,status:string}
     */
    private function resolveAccount(int $supplierId, string $code, string $date, bool $liability): array
    {
        if (!ctype_digit($code)) {
            // Účet z mapování zkratky je kód osnovy MyÚčta (336.001): jen přímá shoda,
            // nic se nedomýšlí ani nezakládá.
            $base = ['source' => $code, 'synthetic' => $code, 'target' => null, 'proposed' => null];
            $row = $this->accounts->findByCode($supplierId, $code, $date);
            return $row !== null ? $this->usable($row, $code, $liability, $base) : ['status' => 'missing'] + $base;
        }
        $synthetic = substr($code, 0, 3);
        $analytic = substr($code, 3);
        $candidates = array_values(array_unique(array_filter([$code, $analytic !== '' ? $synthetic . '.' . $analytic : null])));
        $base = ['source' => $code, 'synthetic' => $synthetic, 'target' => null, 'proposed' => null];
        foreach ($candidates as $candidate) {
            $row = $this->accounts->findByCode($supplierId, $candidate, $date);
            if ($row !== null) return $this->usable($row, $candidate, $liability, $base);
        }
        $parent = $this->accounts->findByCode($supplierId, $synthetic, $date);
        if ($parent === null) {
            return ['status' => 'missing'] + $base;
        }
        // Analytika 000 = účet bez analytického členění: syntetika, pokud ji osnova nečlení.
        // Syntetika s analytikami se účtovat nemá (součet analytik by na ni neseděl).
        $hasAnalytics = array_filter($this->accounts->childrenOf($supplierId, (int) $parent['id'], $date),
            static fn (array $c): bool => !empty($c['is_active'])) !== [];
        if (($analytic === '' || trim($analytic, '0') === '') && !$hasAnalytics) {
            return $this->usable($parent, $synthetic, $liability, $base);
        }
        if ($analytic === '') {
            return ['target' => $synthetic, 'status' => 'has_analytics'] + $base;
        }
        $usable = $this->usable($parent, $synthetic, $liability, $base);
        return $usable['status'] === 'ok'
            ? ['proposed' => $synthetic . '.' . $analytic, 'status' => 'creatable'] + $base
            : $usable;
    }

    /**
     * @param array<string,mixed> $row účet osnovy
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private function usable(array $row, string $code, bool $liability, array $base): array
    {
        if (empty($row['is_active'])) {
            return ['target' => $code, 'status' => 'inactive'] + $base;
        }
        if ($liability && ($row['account_type'] ?? null) !== 'liability') {
            return ['target' => $code, 'status' => 'not_liability'] + $base;
        }
        return ['target' => $code, 'status' => 'ok'] + $base;
    }

    /**
     * Založí navržené analytiky pod existující syntetiky (typ a strana po syntetice), platné
     * od nejstaršího data v souboru, aby na ně šlo účtovat k datu účetního případu.
     *
     * @param list<array<string,mixed>> $accounts
     * @param array<string,mixed> $parsed
     * @return array<string,string> kód PAMICY => založený kód
     */
    private function createMissingAccounts(int $supplierId, array $accounts, array $parsed, ?int $userId): array
    {
        $created = [];
        $from = min($parsed['accounting_date'], $parsed['document_date']);
        foreach ($accounts as $account) {
            if ($account['status'] !== 'creatable') continue;
            $parent = $this->accounts->findByCode($supplierId, $account['synthetic'], $from)
                ?? throw new PamicaPostingException('accounts_missing', 'Syntetický účet ' . $account['synthetic'] . ' v osnově chybí.', 409);
            $this->accounts->insert($supplierId, [
                'account_code' => (string) $account['proposed'],
                'name' => 'Analytika ' . $account['source'] . ' (PAMICA)',
                'account_type' => (string) $parent['account_type'],
                'normal_side' => $parent['normal_side'] !== null ? (string) $parent['normal_side'] : null,
                'is_synthetic' => false,
                'parent_id' => (int) $parent['id'],
                'is_active' => true,
            ], $from, $userId);
            $created[$account['source']] = (string) $account['proposed'];
        }
        return $created;
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $parsed
     * @param array<string,?string> $map
     * @param array<string,mixed> $audit
     * @return array<string,mixed>
     */
    private function postInternalDocument(int $supplierId, array $doc, array $parsed, array $map, array $audit): array
    {
        $pairs = [];
        foreach ($doc['lines'] as $line) {
            $pair = [
                'debit_account_code' => self::mapped($map, $line['debit']),
                'credit_account_code' => self::mapped($map, $line['credit']),
                'amount' => $line['amount'],
            ];
            $text = trim($line['text'] . ($line['note'] !== null ? ' ' . $line['note'] : ''));
            if ($text !== '') $pair['line_text'] = mb_substr($text, 0, 255);
            $pairs[] = $pair;
        }
        $draft = $this->internalDocuments->create($supplierId, [
            'kind' => 'other',
            'title' => $doc['title'],
            'document_date' => $doc['document_date'],
            'entry_date' => $doc['accounting_date'],
            'note' => self::sourceNote($parsed),
            'pairs' => $pairs,
        ], $audit['user_id']);
        return $this->internalDocuments->post($supplierId, (int) $draft['id'], $audit);
    }

    /**
     * @param array<string,mixed> $c
     * @param array<string,mixed> $parsed
     * @param array<string,?string> $map
     * @return array<string,mixed>
     */
    private function createCommitment(int $supplierId, array $c, array $parsed, array $map, ?int $userId): array
    {
        $partnerId = null;
        if ($c['partner'] !== null) {
            $partnerId = $this->clients->resolveVendor(array_filter([
                'company_name' => $c['partner']['name'],
                'ic' => $c['partner']['ic'],
                'street' => $c['partner']['street'],
                'city' => $c['partner']['city'],
                'zip' => $c['partner']['zip'],
            ], static fn (?string $v): bool => $v !== null && $v !== ''), $supplierId)['id'];
        }
        $account = ['counterparty_account' => null, 'counterparty_bank_code' => null];
        if ($c['account_number'] !== null) {
            try {
                $account = OtherItemCounterpartyAccount::normalize($c['account_number'], $c['bank_code']);
            } catch (OtherItemException) {
                // Náhled na neplatný účet upozornil; závazek vznikne bez něj.
            }
        }
        $vs = $c['variable_symbol'] !== null && preg_match('/^[0-9]{1,10}$/', $c['variable_symbol']) === 1 ? $c['variable_symbol'] : null;
        $noteParts = [];
        if ($c['constant_symbol'] !== null) $noteParts[] = 'KS ' . $c['constant_symbol'];
        if ($c['payment_type'] === 'cash') $noteParts[] = 'Vyplácí se hotově.';
        $noteParts[] = self::sourceNote($parsed);
        $input = [
            'side' => 'payable',
            'kind' => 'other',
            'title' => $c['title'],
            'partner_id' => $partnerId,
            'partner_name' => $c['partner']['name'] ?? null,
            'issued_on' => $c['document_date'],
            'accounting_on' => $c['accounting_date'],
            'due_on' => $c['due_on'],
            'currency' => 'CZK',
            'amount' => $c['amount'],
            'variable_symbol' => $vs,
            'counterparty_account' => $account['counterparty_account'],
            'counterparty_bank_code' => $account['counterparty_bank_code'],
            'account_code' => self::mapped($map, $c['credit']),
            'note' => implode(' ', $noteParts),
        ];
        if ($c['without_posting']) {
            // Předpis závazku je v interním dokladu (331 Dal); položka se jen potvrdí bez zápisu.
            return $this->otherItems->createFromJournalLine($supplierId, $input, $userId);
        }
        $input['posting_lines'] = array_map(static fn (array $line): array => [
            'account_code' => self::mapped($map, (string) $line['debit']),
            'amount' => $line['amount'],
        ], $c['lines']);
        $draft = $this->otherItems->create($supplierId, $input, $userId);
        return $this->otherItems->post($supplierId, (int) $draft['id'], $userId);
    }

    /**
     * Součty dokladů a kontrola účtů, přes které mzdy tečou: co interní doklady dají na Dal
     * účtu závazku (331), mají závazky srazit na MD nebo převzít bez zaúčtování.
     * Účty se sčítají podle účtu osnovy, na který půjdou: zkratka předkontace dá 331,
     * předkontace BEZ331000 kód PAMICY 331000, a přitom jde o tentýž účet.
     *
     * @param array<string,mixed> $parsed
     * @param array<string,string> $resolved kód ze souboru => účet osnovy
     * @return array<string,mixed>
     */
    private static function totals(array $parsed, array $resolved): array
    {
        $cents = static fn (float $v): int => (int) round($v * 100);
        $key = static fn (string $code): string => $resolved[$code] ?? $code;
        $internal = 0;
        $posted = 0;
        $without = 0;
        $clearing = [];
        foreach ($parsed['internal_documents'] as $doc) {
            $internal += $cents($doc['amount']);
            foreach ($doc['lines'] as $line) {
                $k = $key((string) $line['credit']);
                $clearing[$k]['credit'] = ($clearing[$k]['credit'] ?? 0) + $cents($line['amount']);
            }
        }
        $withoutCodes = [];
        foreach ($parsed['commitments'] as $c) {
            if ($c['without_posting']) {
                $without += $cents($c['amount']);
                $withoutCodes[$c['credit']] = true;
                $k = $key((string) $c['credit']);
                $clearing[$k]['without'] = ($clearing[$k]['without'] ?? 0) + $cents($c['amount']);
                continue;
            }
            $posted += $cents($c['amount']);
            foreach ($c['lines'] as $line) {
                $k = $key((string) $line['debit']);
                $clearing[$k]['debit'] = ($clearing[$k]['debit'] ?? 0) + $cents($line['amount']);
            }
        }
        $out = [];
        foreach ($withoutCodes as $code => $_) {
            $row = $clearing[$key((string) $code)];
            $credit = $row['credit'] ?? 0;
            $debit = $row['debit'] ?? 0;
            $w = $row['without'] ?? 0;
            $out[(string) $code] = ['credit' => self::fromCents($credit), 'debit' => self::fromCents($debit),
                'without_posting' => self::fromCents($w), 'difference' => self::fromCents($credit - $debit - $w)];
        }
        return [
            'internal' => self::fromCents($internal),
            'commitments_posted' => self::fromCents($posted),
            'commitments_without_posting' => self::fromCents($without),
            'commitments' => self::fromCents($posted + $without),
            'clearing' => $out,
        ];
    }

    /**
     * Závazek se zaúčtováním nesmí ležet na účtu, na který interní doklad účtuje hrubé mzdy
     * (Dal řádků s MD na mzdových nákladech 521 až 523, typicky 331). Srážka ze mzdy se
     * z toho účtu odepisuje na MD a závazek vzniká na Dal jiného účtu (342, 336); závazek
     * na Dal 331 by zúčtování mezd zdvojil a typicky prozrazuje prohozené účty v přiřazení
     * zkratky. Čistá mzda zaměstnance jde jako závazek bez zaúčtování (BEZ331000), ten na
     * účtu hrubých mezd ležet má a kontrola ho vynechá.
     *
     * @param array<string,mixed> $parsed
     * @param array<string,string> $resolved kód ze souboru => účet osnovy
     * @return list<array{code:string,level:string,message:string,context:array<string,mixed>}>
     */
    private static function clearingAccountCommitments(array $parsed, array $resolved): array
    {
        $key = static fn (string $code): string => $resolved[$code] ?? $code;
        $grossWage = [];
        foreach ($parsed['internal_documents'] as $doc) {
            foreach ($doc['lines'] as $line) {
                if (in_array(substr($key((string) $line['debit']), 0, 3), ['521', '522', '523'], true)) {
                    $grossWage[$key((string) $line['credit'])] = true;
                }
            }
        }
        // Tentýž účet, nebo syntetika a její analytika (331 a 331.000).
        $related = static fn (string $a, string $b): bool => strcasecmp($a, $b) === 0
            || stripos($a, $b . '.') === 0 || stripos($b, $a . '.') === 0;
        $checks = [];
        foreach ($parsed['commitments'] as $c) {
            if ($c['without_posting']) continue;
            $account = $key((string) $c['credit']);
            $clearing = null;
            foreach (array_keys($grossWage) as $gross) {
                if ($related($account, (string) $gross)) {
                    $clearing = (string) $gross;
                    break;
                }
            }
            if ($clearing === null) continue;
            $codes = array_values(array_unique(array_filter(array_map(static fn (array $l): ?string => $l['code'], $c['lines']))));
            $hint = $codes !== []
                ? 'Zkontrolujte ' . (count($codes) === 1 ? 'zkratku ' : 'zkratky ') . implode(', ', $codes)
                    . ' (srážka ze mzdy se účtuje obvykle MD ' . $clearing . ' / Dal účet závazku, např. 342 nebo 336).'
                : 'Zkontrolujte předkontaci závazku v PAMICE (obvykle MD ' . $clearing . ' / Dal účet závazku, např. 342 nebo 336).';
            $checks[] = self::check('commitment_on_clearing_account', 'error',
                'Závazek „' . $c['title'] . '" by ležel na účtu ' . $account . ', na který se účtují hrubé mzdy. ' . $hint,
                ['item_id' => $c['item_id'], 'account' => $account, 'clearing_account' => $clearing, 'codes' => $codes]);
        }
        return $checks;
    }

    private static function fromCents(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private function supplierIc(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $ic = preg_replace('/\D+/', '', (string) $stmt->fetchColumn()) ?? '';
        return $ic === '' ? null : str_pad($ic, 8, '0', STR_PAD_LEFT);
    }

    private function hasPeriod(int $supplierId, string $date): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM accounting_periods WHERE supplier_id = ? AND starts_on <= ? AND ends_on >= ? LIMIT 1');
        $stmt->execute([$supplierId, $date, $date]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string,?string> $map */
    private static function mapped(array $map, string $code): string
    {
        return $map[$code] ?? throw new PamicaPostingException('accounts_missing', 'Účet ' . $code . ' v osnově chybí.', 409);
    }

    /** @param array<string,mixed> $parsed */
    private static function sourceNote(array $parsed): string
    {
        return 'Načteno ze zaúčtování mezd PAMICA ' . $parsed['sym_par']
            . ($parsed['pack_id'] !== null ? ' (export ' . $parsed['pack_id'] . ')' : '') . '.';
    }

    /**
     * Chyby agend při zakládání dokladů jsou validační: přeloží se na chybu importu se stejnou
     * hláškou, aby uživatel neviděl 500.
     */
    private static function translate(\Throwable $e): \Throwable
    {
        if ($e instanceof PamicaPostingException || TransactionFailure::aborted($e)) return $e;
        // Souběžné načtení téhož měsíce: druhé narazí na unikátní klíč evidence importů.
        if ($e instanceof \PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062
            && str_contains($e->getMessage(), 'uq_payroll_posting_import_source')) {
            return new PamicaPostingException('already_imported', 'Zaúčtování tohoto měsíce právě načetl jiný uživatel.', 409);
        }
        if ($e instanceof InternalDocumentException) {
            return new PamicaPostingException('internal_document_' . $e->errorCode, 'Interní doklad: ' . $e->getMessage(), 409);
        }
        if ($e instanceof OtherItemException) {
            return new PamicaPostingException('other_item_' . $e->errorCode, 'Ostatní závazek: ' . $e->getMessage(), 409);
        }
        if ($e instanceof \MyInvoice\Service\Accounting\PostingException
            || $e instanceof \MyInvoice\Service\Accounting\Closing\ClosingException
            || $e instanceof \MyInvoice\Service\Accounting\AccountDefinitionException) {
            return new PamicaPostingException($e->errorCode, $e->getMessage(), 409);
        }
        return $e;
    }

    /**
     * @param array<string,mixed> $context
     * @return array{code:string,level:string,message:string,context:array<string,mixed>}
     */
    private static function check(string $code, string $level, string $message, array $context = []): array
    {
        return ['code' => $code, 'level' => $level, 'message' => $message, 'context' => $context];
    }

    private static function monthLabel(string $period): string
    {
        return substr($period, 5, 2) . '/' . substr($period, 0, 4);
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }
}

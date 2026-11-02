<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Cash;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use MyInvoice\Repository\CashRegisterRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\LedgerReportRepository;

/**
 * Správa pokladen (mini-epic POKLADNA #14). Pokladna = analytika účtu 211;
 * v podvojném účetnictví je zůstatek VŽDY počítán z ledgeru nad analytikou
 * registru (R6 — ledger je pravda), Σ dokladů je jen kontrolní číslo. V daňové
 * evidenci (tax_evidence, §6 — pokladna bez COA/journal) je ledger nedostupný,
 * takže zůstatek je přímo Σ dokladů kasové báze (documentsSignedTotal).
 * CZK-only v1 (O4).
 */
final class CashRegisterService
{
    /** Syntetický účet pokladny, pod který analytiky patří. */
    public const CASH_SYNTHETIC = '211';

    public function __construct(
        private readonly Connection $db,
        private readonly CashRegisterRepository $registers,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly LedgerReportRepository $ledger,
        private readonly DocumentSeriesService $series,
    ) {}

    /**
     * Pokladny firmy vč. zůstatku k dnešku, account_id/name a počtu dokladů.
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId, bool $includeInactive = false): array
    {
        $rows = $this->registers->listForTenant($supplierId, $includeInactive);
        $today = date('Y-m-d');
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $countMap = $this->registers->documentsCountMap($supplierId, $ids);
        $mode = $this->supplierAccountingMode($supplierId);

        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->decorate($supplierId, $r, $today, (int) ($countMap[(int) $r['id']] ?? 0), $mode);
        }
        return $out;
    }

    /** Detail registru vč. zůstatku k ?date (default dnes) a kontrolní Σ dokladů. */
    public function get(int $supplierId, int $id, ?string $date = null): array
    {
        $register = $this->registers->find($supplierId, $id);
        if ($register === null) {
            throw new CashException('register_not_found', 'Pokladna nenalezena.', 404);
        }
        $asOf = $date ?? date('Y-m-d');
        $count = $this->registers->documentsCount($supplierId, $id);
        $detail = $this->decorate($supplierId, $register, $asOf, $count, $this->supplierAccountingMode($supplierId));
        $detail['documents_total'] = $this->documentsSignedTotal($supplierId, $id, $asOf);
        return $detail;
    }

    public function create(int $supplierId, array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new CashException('validation', 'Název pokladny je povinný.');
        }
        $currency = strtoupper(trim((string) ($data['currency_code'] ?? 'CZK')));
        if ($currency === '' || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new CashException('validation', 'Kód měny musí být trojznakový (CZK, EUR, USD…).');
        }
        $accountCode = $this->resolveAccountCode($supplierId, trim((string) ($data['account_code'] ?? '')));
        $isForeign = $currency !== 'CZK';
        $taxEvidence = $this->supplierAccountingMode($supplierId) === 'tax_evidence';

        $needsAnalyticInsert = false;
        if ($isForeign) {
            // Valutová pokladna: jen podvojné účetnictví (DE nemá journal ani COA).
            if ($taxEvidence) {
                throw new CashException('currency_unsupported', 'Valutová pokladna je jen v podvojném účetnictví, ne v daňové evidenci.');
            }
            // Dedikovaná analytika 211<suffix> (nosič CZK zůstatku i cizoměnové stopy, §11).
            // Když ji uživatel nezadá, auto-přiděl volný kód a založ ji v osnově (analogicky
            // BankAnalyticResolver pro valutovou banku).
            if ($accountCode === '') {
                $accountCode = $this->nextFreeCashAnalytic($supplierId);
            }
            $needsAnalyticInsert = $this->assertForeignAnalyticUsable($supplierId, $accountCode);
        } elseif (!$taxEvidence) {
            // Podvojné účetnictví, CZK: účet musí být existující aktivní analytika 211.
            // Daňová evidence (Epic DE §6): pokladna nemá journal → COA se nekontroluje.
            $this->assertAccountUsable($supplierId, $accountCode);
        } elseif ($accountCode === '' && $this->registers->findByAccountCode($supplierId, '') !== null) {
            // DE bez zadaného účtu: prázdný kód smí mít jen jedna pokladna (unikátní klíč
            // supplier + account_code), druhá by spadla na duplicitě. Přidělí se volná
            // analytika 211.xxx, která se hodí i po případném přechodu na podvojné účetnictví.
            $accountCode = $this->nextFreeCashAnalytic($supplierId);
        }

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            // COA analytiku dohraj UVNITŘ transakce — při pádu (unique name/account) se
            // sirotčí účet zase odrolluje (LOW: dřív běžel insert před beginTransaction).
            if ($needsAnalyticInsert) {
                $this->insertForeignAnalytic($supplierId, $accountCode, $name, $currency);
            }
            $wantsDefault = !empty($data['is_default']);
            if ($wantsDefault) {
                $this->registers->clearDefault($supplierId);
            }
            $ownSeries = !empty($data['own_series']);
            $id = $this->registers->create($supplierId, [
                'name'          => $name,
                'currency_code' => $currency,
                'account_code'  => $accountCode,
                'is_default'    => $wantsDefault,
                'own_series'    => $ownSeries,
                'is_active'     => true,
            ]);
            if ($ownSeries) {
                $this->ensureOwnSeries($supplierId, $id, (int) date('Y'));
            }
            if ($ownTx) {
                $pdo->commit();
            }
            return $id;
        } catch (\PDOException $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $this->mapUniqueViolation($e);
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function update(int $supplierId, int $id, array $data): void
    {
        $register = $this->registers->find($supplierId, $id);
        if ($register === null) {
            throw new CashException('register_not_found', 'Pokladna nenalezena.', 404);
        }

        $patch = [];
        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new CashException('validation', 'Název pokladny je povinný.');
            }
            $patch['name'] = $name;
        }
        if (array_key_exists('account_code', $data)) {
            $newCode = $this->resolveAccountCode($supplierId, trim((string) $data['account_code']));
            if ($newCode !== (string) $register['account_code']) {
                if ($this->registers->hasPostedDocuments($supplierId, $id)) {
                    throw new CashException('account_locked', 'Analytiku pokladny nelze změnit — existuje zaúčtovaný doklad.');
                }
                // V daňové evidenci (DE §6) pokladna nemá journal → COA se nekontroluje.
                if ($this->supplierAccountingMode($supplierId) !== 'tax_evidence') {
                    $this->assertAccountUsable($supplierId, $newCode, $id);
                }
                $patch['account_code'] = $newCode;
            }
        }
        if (array_key_exists('is_active', $data)) {
            $patch['is_active'] = (bool) $data['is_active'];
        }
        $wantsOwnSeries = array_key_exists('own_series', $data) ? (bool) $data['own_series'] : null;
        if ($wantsOwnSeries !== null && $wantsOwnSeries !== (bool) ($register['own_series'] ?? false)) {
            $this->assertSeriesSwitchable($supplierId, $id);
            $patch['own_series'] = $wantsOwnSeries;
        }

        $wantsDefault = array_key_exists('is_default', $data) ? (bool) $data['is_default'] : null;

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            if ($wantsDefault === true) {
                $this->registers->clearDefault($supplierId);
                $patch['is_default'] = true;
            } elseif ($wantsDefault === false) {
                $patch['is_default'] = false;
            }
            if ($patch !== []) {
                $this->registers->update($supplierId, $id, $patch);
            }
            if (!empty($patch['own_series'])) {
                $this->ensureOwnSeries($supplierId, $id, (int) date('Y'));
            }
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\PDOException $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $this->mapUniqueViolation($e);
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function delete(int $supplierId, int $id): void
    {
        $register = $this->registers->find($supplierId, $id);
        if ($register === null) {
            throw new CashException('register_not_found', 'Pokladna nenalezena.', 404);
        }
        if ($this->registers->documentsCount($supplierId, $id) > 0) {
            throw new CashException(
                'register_has_documents',
                'Pokladnu s doklady nelze smazat — deaktivujte ji.',
                409,
            );
        }
        $this->registers->delete($supplierId, $id);
    }

    /**
     * Zůstatek analytiky registru k datu (ledger = pravda, R6). Signed delta MD−D
     * všech zaúčtovaných zápisů do konce dne `date` vč.
     */
    public function balance(int $supplierId, string $accountCode, string $date): float
    {
        $account = $this->accounts->findByCode($supplierId, $accountCode);
        if ($account === null) {
            return 0.0;
        }
        // accountOpening počítá entry_date < from → posun o den zahrne i pohyby k `date`.
        $dayAfter = date('Y-m-d', strtotime($date . ' +1 day'));
        // 211 je aktivní účet (asset) → parametr periodStart je pro něj neutrální.
        //
        // Uzávěrkový zápis se VYLUČUJE: k rozvahovému dni převádí zůstatek pokladny
        // na 702, takže stav vycházel 0 Kč přesně v den, ke kterému se pokladna
        // inventarizuje (30. 12. 2025 = 14 232,00 → 31. 12. 2025 = 0,00 → 1. 1. 2026
        // = 14 232,00). Uživatel potřebuje stav peněz, ne stav po uzavření knih.
        return $this->ledger->accountOpening($supplierId, (int) $account['id'], $dayAfter, $dayAfter, true);
    }

    /** @param array<string,mixed> $register */
    private function decorate(int $supplierId, array $register, string $asOf, int $documentsCount, string $mode): array
    {
        $account = $this->accounts->findByCode($supplierId, (string) $register['account_code']);
        $register['account_id'] = $account !== null ? (int) $account['id'] : null;
        $register['account_name'] = $account !== null ? (string) $account['name'] : null;
        $register['documents_count'] = $documentsCount;
        $register['balance'] = $mode === 'tax_evidence'
            ? $this->documentsSignedTotal($supplierId, (int) $register['id'], $asOf)
            : $this->balance($supplierId, (string) $register['account_code'], $asOf);
        $register['balance_date'] = $asOf;
        // Valutová pokladna vede duální zůstatek: CZK (výše) + částka v cizí měně (§4/12).
        $register['balance_foreign'] = strtoupper((string) $register['currency_code']) !== 'CZK'
            ? $this->documentsSignedForeignTotal($supplierId, (int) $register['id'], $asOf)
            : null;
        return $register;
    }

    /**
     * Σ dokladů registru (příjmy − výdaje), jen zaúčtované, do data vč. — kasová báze.
     * V daňové evidenci (bez ledgeru) je to PŘÍMO zůstatek pokladny (viz decorate()),
     * v podvojném účetnictví jde jen o kontrolní číslo vůči ledgeru (documents_total).
     */
    public function documentsSignedTotal(int $supplierId, int $registerId, string $asOf): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN doc_type = 'in' THEN total_amount ELSE -total_amount END), 0)
               FROM cash_documents
              WHERE supplier_id = ? AND register_id = ? AND status = 'posted' AND issue_date <= ?"
        );
        $stmt->execute([$supplierId, $registerId, $asOf]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Σ dokladů registru v CIZÍ měně (příjmy − výdaje), jen zaúčtované, do data vč.
     * Duální zůstatek valutové pokladny — vede se souběžně s CZK ekvivalentem (§4/12).
     */
    public function documentsSignedForeignTotal(int $supplierId, int $registerId, string $asOf): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN doc_type = 'in' THEN amount_foreign ELSE -amount_foreign END), 0)
               FROM cash_documents
              WHERE supplier_id = ? AND register_id = ? AND status = 'posted'
                AND amount_foreign IS NOT NULL AND issue_date <= ?"
        );
        $stmt->execute([$supplierId, $registerId, $asOf]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * L-3: přepnutí pokladny na vlastní řadu (a zpět) smí projít jen tehdy, když
     * pokladna v AKTUÁLNÍM roce ještě žádné číslo nevydala. Uprostřed roku by se
     * jinak číselná řada té knihy zlomila — část dokladů z firemní řady, zbytek
     * z vlastní — a §11 ZoÚ chce souvislé označení dokladů.
     */
    private function assertSeriesSwitchable(int $supplierId, int $registerId): void
    {
        if ($this->hasNumberedDocuments($supplierId, $registerId, (int) date('Y'))) {
            throw new CashException(
                'series_switch_locked',
                'Číselnou řadu pokladny lze přepnout jen v roce, ve kterém ještě nevydala žádné číslo — přepněte ji od nového účetního roku.',
            );
        }
    }

    /**
     * Založí pokladně vlastní řady PPD/VPD pro daný rok s prefixem, který zatím nikdo
     * nepoužívá (PPD2, PPD3 …) — ale jen pokud řádek řady ještě neexistuje; nastavení
     * od uživatele se nepřepisuje.
     *
     * Vlastní řada začíná od jedničky, takže s VÝCHOZÍM prefixem by hned kolidovala
     * s doklady firemní řady (`uq_cashdoc_supplier_number`). Proto se volá i před
     * každým výdejem čísla, ne jen při zapnutí volby: lazy `ensure()` v
     * {@see DocumentSeriesService::next()} zná jen výchozí prefix, takže v roce, pro
     * který řada ještě založená není (import staršího dokladu, přelom roku), by
     * vyrobil kolizní `PPD-…-0001`.
     */
    public function ensureOwnSeries(int $supplierId, int $registerId, int $fiscalYear): bool
    {
        // Rozhodnutí padá PER ROK DOKLADU, ne podle „dneška": pokladna přepnutá v lednu
        // 2027 nesmí zlomit číslování roku 2026, kde už z firemní řady čísla vydala
        // (doúčtovaný koncept, import staršího dokladu). Pro takový rok zůstává firemní
        // řada — jinak by kniha toho roku měla dvě různé řady.
        if (!$this->hasOwnSeriesRow($supplierId, $registerId, $fiscalYear)
            && $this->hasNumberedDocuments($supplierId, $registerId, $fiscalYear)) {
            return false;
        }

        // Prefix a šablona pokladny se přes roky nemění (ensureOwnSeriesYear); úplně první
        // rok řady dostane volný prefix, aby nekolidoval s firemní PPD/VPD.
        foreach (['cash_in', 'cash_out'] as $seriesCode) {
            if ($this->series->ensureOwnSeriesYear($supplierId, $seriesCode, $registerId, $fiscalYear)) {
                continue;
            }
            $this->series->ensureSeriesRow(
                $supplierId,
                $seriesCode,
                $fiscalYear,
                $this->freeSeriesPrefix($supplierId, DocumentSeriesService::DEFAULT_PREFIXES[$seriesCode]),
                null,
                $registerId,
            );
        }
        return true;
    }

    /** Má pokladna pro daný rok už založenou vlastní řadu? */
    private function hasOwnSeriesRow(int $supplierId, int $registerId, int $fiscalYear): bool
    {
        foreach ($this->series->list($supplierId) as $row) {
            if ((int) $row['register_id'] === $registerId
                && (int) $row['fiscal_year'] === $fiscalYear
                && in_array((string) $row['series_code'], ['cash_in', 'cash_out'], true)) {
                return true;
            }
        }
        return false;
    }

    private function hasNumberedDocuments(int $supplierId, int $registerId, int $fiscalYear): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (SELECT 1 FROM cash_documents
                             WHERE supplier_id = ? AND register_id = ? AND doc_number IS NOT NULL
                               AND YEAR(issue_date) = ?)'
        );
        $stmt->execute([$supplierId, $registerId, $fiscalYear]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Prefix, který nedrží jiná řada firmy ani žádné už vystavené číslo dokladu.
     * Kontrola nad `doc_number` je podstatná: řada mohla být mezitím přejmenovaná,
     * ale její čísla v dokladech zůstala.
     */
    private function freeSeriesPrefix(int $supplierId, string $base): string
    {
        $pdo = $this->db->pdo();
        for ($ordinal = 2; $ordinal <= 99; $ordinal++) {
            $candidate = $base . $ordinal;
            if (mb_strlen($candidate) > 10) {
                break;
            }
            $stmt = $pdo->prepare('SELECT EXISTS (SELECT 1 FROM accounting_document_series WHERE supplier_id = ? AND prefix = ?)');
            $stmt->execute([$supplierId, $candidate]);
            if ((bool) $stmt->fetchColumn()) {
                continue;
            }
            $stmt = $pdo->prepare("SELECT EXISTS (SELECT 1 FROM cash_documents WHERE supplier_id = ? AND doc_number LIKE ?)");
            $stmt->execute([$supplierId, $candidate . '%']);
            if (!(bool) $stmt->fetchColumn()) {
                return $candidate;
            }
        }
        throw new CashException(
            'series_prefix_unavailable',
            'Nepodařilo se najít volný prefix pro vlastní řadu pokladny — nastavte jej ručně v Nástrojích → Číselné řady.',
        );
    }

    /**
     * První volná analytika pokladny 211.<NNN> (211.001…211.999), kterou zatím nedrží
     * žádná pokladna firmy — deterministické auto-přidělení pro valutovou pokladnu.
     *
     * Tvar kódu je TEČKOVANÝ, shodně se zbytkem osnovy (501.100, 221.100 —
     * viz {@see \MyInvoice\Service\Accounting\Bank\BankAnalyticAssigner}). Bezteččkové
     * kódy z doby před migrací 1322 zůstávají platné (existující pokladny se nepřečíslují),
     * jen se nové už nezakládají — proto se obsazenost kontroluje na OBOU tvarech.
     */
    private function nextFreeCashAnalytic(int $supplierId): string
    {
        // Preferuj kód BEZ účtu v osnově — ať auto-přidělení NIKDY neadoptuje existující
        // analytiku s ledgerovou historií (kontaminace zůstatku). Fallback (vše obsazené v
        // COA): první volný mezi pokladnami, u kterého assertForeignAnalyticUsable ověří,
        // že nemá zápisy.
        $firstRegisterFree = null;
        for ($n = 1; $n <= 999; $n++) {
            $suffix = str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            $code = self::CASH_SYNTHETIC . '.' . $suffix;
            $legacy = self::CASH_SYNTHETIC . $suffix;
            if ($this->registers->findByAccountCode($supplierId, $code) !== null
                || $this->registers->findByAccountCode($supplierId, $legacy) !== null
            ) {
                continue;
            }
            $firstRegisterFree ??= $code;
            if ($this->accounts->findByCode($supplierId, $code) === null
                && $this->accounts->findByCode($supplierId, $legacy) === null
            ) {
                return $code;
            }
        }
        if ($firstRegisterFree !== null) {
            return $firstRegisterFree;
        }
        throw new CashException('account_taken', 'Vyčerpány volné analytiky pokladny 211.xxx.');
    }

    /**
     * Ověří analytiku valutové pokladny (BEZ zápisu): musí být 211<suffix> (ne holé 211),
     * nesmí ji držet jiná pokladna a existuje-li v osnově, musí být aktivní a BEZ ledgerové
     * historie (jinak by valutová pokladna adoptovala cizí zůstatek). Vrací true, když
     * analytika v osnově chybí a je potřeba ji dohrát (insert dělá volající uvnitř transakce).
     */
    private function assertForeignAnalyticUsable(int $supplierId, string $accountCode): bool
    {
        if ($accountCode === '' || !str_starts_with($accountCode, '211') || $accountCode === '211') {
            throw new CashException('account_invalid', 'Účet valutové pokladny musí být samostatná analytika 211 (211001, 211500…), ne holé 211.');
        }
        $existing = $this->registers->findByAccountCode($supplierId, $accountCode);
        if ($existing !== null) {
            throw new CashException('account_taken', 'Analytiku ' . $accountCode . ' už používá jiná pokladna (i neaktivní).', 409);
        }
        $account = $this->accounts->findByCode($supplierId, $accountCode);
        if ($account === null) {
            return true;
        }
        if (empty($account['is_active'])) {
            throw new CashException('account_invalid', 'Účet ' . $accountCode . ' je v osnově neaktivní.');
        }
        if ($this->accountHasLedgerEntries($supplierId, (int) $account['id'])) {
            throw new CashException(
                'account_taken',
                'Účet ' . $accountCode . ' už má účetní zápisy — valutová pokladna potřebuje čistou analytiku. Zvolte jiný kód.',
                409,
            );
        }
        return false;
    }

    /** Dohraje analytiku valutové pokladny do osnovy (dědí typ/stranu ze syntetiky 211). */
    private function insertForeignAnalytic(int $supplierId, string $accountCode, string $name, string $currency): void
    {
        if ($this->accounts->findByCode($supplierId, $accountCode) !== null) {
            return;
        }
        $parent = $this->accounts->findByCode($supplierId, '211');
        $this->accounts->insert($supplierId, [
            'account_code' => $accountCode,
            'name'         => $name !== '' ? ($name . ' (' . $currency . ')') : ('Valutová pokladna ' . $currency),
            'account_type' => $parent['account_type'] ?? 'asset',
            'normal_side'  => $parent['normal_side'] ?? 'debit',
            'is_synthetic' => false,
            'parent_id'    => $parent !== null ? (int) $parent['id'] : null,
            'is_active'    => true,
        ]);
    }

    /** Má účet aspoň jeden řádek v deníku (ledgerová historie)? */
    private function accountHasLedgerEntries(int $supplierId, int $accountId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (SELECT 1 FROM journal_entry_lines WHERE supplier_id = ? AND account_id = ?)'
        );
        $stmt->execute([$supplierId, $accountId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * L-9: osnova ukládá analytiku tečkovaně (`ChartOfAccountsAction::withAnalyticDot`
     * doplní tečku i tomu, kdo zadá `211200`), formulář pokladny ale kód nenormalizoval.
     * Účetní, která totéž číslo zadala na obou místech, tedy dostala `account_invalid`
     * u účtu, který v osnově existuje. Kód se proto bere nejdřív tak, jak přišel
     * (legacy netečkované účty zůstávají platné), a teprve když v osnově není, zkusí
     * se jeho tečkovaný protějšek. `nextFreeCashAnalytic()` obsazenost na obou tvarech
     * kontroluje už dávno.
     */
    private function resolveAccountCode(int $supplierId, string $accountCode): string
    {
        $synthetic = self::CASH_SYNTHETIC;
        if ($accountCode === ''
            || str_contains($accountCode, '.')
            || preg_match('/^' . $synthetic . '\d+$/', $accountCode) !== 1
        ) {
            return $accountCode;
        }
        if ($this->accounts->findByCode($supplierId, $accountCode) !== null) {
            return $accountCode;
        }
        $dotted = $synthetic . '.' . substr($accountCode, strlen($synthetic));
        return $this->accounts->findByCode($supplierId, $dotted) !== null ? $dotted : $accountCode;
    }

    /** Ověří, že account_code je platná aktivní analytika 211 a není obsazená jinou pokladnou. */
    private function assertAccountUsable(int $supplierId, string $accountCode, ?int $excludeRegisterId = null): void
    {
        if ($accountCode === '' || !str_starts_with($accountCode, self::CASH_SYNTHETIC)) {
            throw new CashException('account_invalid', 'Účet pokladny musí být analytika 211 (211, 211.100…).');
        }
        $account = $this->accounts->findByCode($supplierId, $accountCode);
        if ($account === null || empty($account['is_active'])) {
            throw new CashException('account_invalid', 'Účet ' . $accountCode . ' není v osnově firmy nebo je neaktivní.');
        }
        $existing = $this->registers->findByAccountCode($supplierId, $accountCode);
        if ($existing !== null && (int) $existing['id'] !== $excludeRegisterId) {
            throw new CashException(
                'account_taken',
                'Analytiku ' . $accountCode . ' už používá jiná pokladna (i neaktivní).',
                409,
            );
        }
    }

    /**
     * Účetní režim firmy (Epic DE §2.1). `tax_evidence` = daňová evidence OSVČ
     * (kasová báze, no-journal path §6 — pokladna bez účtu v osnově),
     * `double_entry` = podvojné účetnictví (pokladna = analytika 211 v COA).
     */
    private function supplierAccountingMode(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $mode = $stmt->fetchColumn();
        return $mode === 'tax_evidence' ? 'tax_evidence' : 'double_entry';
    }

    private function mapUniqueViolation(\PDOException $e): \Throwable
    {
        if (($e->errorInfo[0] ?? null) !== '23000') {
            return $e;
        }
        $msg = $e->getMessage();
        if (str_contains($msg, 'uq_cashreg_supplier_account')) {
            return new CashException('account_taken', 'Analytiku už používá jiná pokladna (i neaktivní).', 409);
        }
        if (str_contains($msg, 'uq_cashreg_supplier_name')) {
            return new CashException('validation', 'Pokladna se stejným názvem už existuje.', 409);
        }
        return $e;
    }
}

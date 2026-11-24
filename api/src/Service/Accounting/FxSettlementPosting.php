<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\Pair\JournalPairer;
use PDO;

/**
 * Zaúčtování úhrady cizoměnového dokladu v jiné hodnotě, než v jaké vznikl předpis
 * (korunová úhrada eurové faktury, úhrada kurzem dne platby). Jediné místo pro:
 *
 *   - dohledání aktivního předpisu dokladu a jeho saldokontního účtu ({@see predpis()},
 *     {@see predpisSaldo()}),
 *   - kurz předpisu, kterým se saldokonto odúčtuje ({@see predpisFxRate()}),
 *   - cizoměnovou stopu saldokontního řádku ({@see withFxTrace()}),
 *   - vyrovnávací řádek kurzového rozdílu 563/663 ({@see appendFxDifference()}).
 *
 * Používá ho banka ({@see Bank\BankPostingService}) i pokladna
 * ({@see Cash\CashDocumentService}): saldokonto se v obou kanálech odúčtuje kurzem
 * předpisu, peněžní účet skutečně přijatou nebo vydanou částkou a rozdíl je realizovaný
 * kurzový zisk nebo ztráta. Dvě kopie téhož výpočtu by se rozešly v kurzu nebo v účtech
 * kurzových rozdílů a saldokonto by po úhradě zůstalo otevřené.
 */
final class FxSettlementPosting
{
    public function __construct(
        private readonly Connection $db,
        private readonly PostingRuleRepository $postingRules,
        private readonly JournalEntryRepository $journal,
    ) {}

    /**
     * Saldokontní účet, na kterém je předpis dokladu. Pravidlo úhrady dává syntetiku (321,
     * 311); vede-li firma saldokonto na analytice (převzaté účetnictví: předpis na 321.001),
     * musí úhrada odúčtovat tutéž analytiku - jinak předpis na analytice zůstane otevřený
     * a úhrada visí na syntetice. Jen když předpis má pod syntetikou právě jednu analytiku;
     * předpis na syntetice (běžná firma) nebo víc analytik = kód z pravidla beze změny.
     *
     * Platí i pro cizoměnové větve: převod z cizího programu zakládá doklad v cizí měně
     * s předpisem na analytice ze zdroje ({@see \MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover}).
     * Kurzový rozdíl jde na 563/663, saldokonto se odúčtuje na účtu předpisu.
     */
    private function predpisSaldoCode(int $supplierId, int $entryId, string $code): string
    {
        if (preg_match('/^\d{3}$/', $code) !== 1) {
            return $code;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT a.account_code
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.entry_id = ? AND a.account_code LIKE CONCAT(?, '.%')"
        );
        $stmt->execute([$supplierId, $entryId, $code]);
        return self::singleAnalyticCode($code, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Saldokontní účet dokladu z počátečních stavů. Otevírací zápis nese všechny účty firmy
     * a doklad v něm vlastní řádek nemá, takže účet jde určit jen tehdy, když saldokonto
     * pravidla (311, 321) vede počáteční stav na jediném účtu: syntetice, nebo právě jedné
     * její analytice. Víc analytik nebo žádný řádek = odhad by nechal saldo otevřené na
     * jiném účtu, úhrada se proto automaticky nezaúčtuje.
     *
     * @param array{grouped:bool, entry_ids:list<int>, doc_type:string, doc_id:int, opening?:bool} $predpis
     */
    private function openingSaldoCode(int $supplierId, array $predpis, string $code): string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT a.account_code
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.entry_id = ? AND (a.account_code = ? OR a.account_code LIKE CONCAT(?, '.%'))"
        );
        $stmt->execute([$supplierId, $predpis['entry_ids'][0], $code, $code]);
        return self::openingCode($predpis, $code, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param list<string> $analytics */
    private static function singleAnalyticCode(string $code, array $analytics): string
    {
        return preg_match('/^\d{3}$/', $code) === 1 && count($analytics) === 1 ? $analytics[0] : $code;
    }

    /**
     * @param array{doc_type:string, doc_id:int} $predpis
     * @param list<string> $codes
     */
    private static function openingCode(array $predpis, string $code, array $codes): string
    {
        if (count($codes) === 1) {
            return $codes[0];
        }
        $label = ($predpis['doc_type'] === 'invoice' ? 'Vydaná faktura #' : 'Přijatá faktura #') . $predpis['doc_id'];
        throw new PostingException(
            'opening_saldo_ambiguous',
            $label . ' je v počátečních stavech, ale saldokonto ' . $code . ' v nich '
                . ($codes === [] ? 'nemá řádek' : 'vede na více účtech (' . implode(', ', $codes) . ')')
                . '. Úhradu zaúčtujte ručně.',
        );
    }

    /**
     * Aktivní předpis dokladu, proti kterému se úhrada odúčtuje. Nativní doklad (jen přímý
     * zápis, případně skupina s jediným přímým členem) se čte beze změny přes findBySource.
     * Doklad se skupinou zaúčtování ({@see DocumentPostingGroup}) má předpis, dokud skupina
     * drží aktivní člen: ruční storno přímého člena převzatého dokladu předpis neruší.
     *
     * @return array{grouped:bool, entry_ids:list<int>, doc_type:string, doc_id:int, opening?:bool}
     */
    public function predpis(int $supplierId, string $docType, int $docId, bool $requirePosted, string $notPostedMessage): array
    {
        $group = (new DocumentPostingGroup($this->db))->active($supplierId, $docType, $docId);
        $groupPredpis = self::predpisFromGroup($docType, $docId, $group);
        if ($groupPredpis !== null) {
            return $groupPredpis;
        }
        $entry = $this->journal->findBySource($supplierId, $docType, $docId);
        $opening = $entry === null ? ($this->openingEntryIds($supplierId, $docType, [$docId])[$docId] ?? null) : null;
        return self::directPredpis($docType, $docId, $entry, $opening, $requirePosted)
            ?? throw new PostingException('document_not_posted', $notPostedMessage);
    }

    /**
     * @param list<array<string,mixed>> $group
     * @return array{grouped:bool, entry_ids:list<int>, doc_type:string, doc_id:int}|null
     */
    private static function predpisFromGroup(string $docType, int $docId, array $group): ?array
    {
        if (!DocumentPostingGroup::hasOwnedEntries($group) || DocumentPostingGroup::isSingleDirectEntry($group, $docType, $docId)) {
            return null;
        }
        return [
            'grouped' => true,
            'entry_ids' => array_map(static fn (array $entry): int => (int) $entry['id'], $group),
            'doc_type' => $docType,
            'doc_id' => $docId,
        ];
    }

    /**
     * @param array<string,mixed>|null $entry
     * @return array{grouped:bool, entry_ids:list<int>, doc_type:string, doc_id:int, opening?:bool}|null
     */
    private static function directPredpis(string $docType, int $docId, ?array $entry, ?int $opening, bool $requirePosted): ?array
    {
        $document = ['doc_type' => $docType, 'doc_id' => $docId, 'grouped' => false];
        if ($entry === null && $opening !== null) {
            return $document + ['entry_ids' => [$opening], 'opening' => true];
        }
        if ($entry === null || ($requirePosted && ($entry['posted_at'] ?? null) === null) || ($entry['reversed_by'] ?? null) !== null) {
            return null;
        }
        return $document + ['entry_ids' => [(int) $entry['id']]];
    }

    /**
     * Otevírací zápis, ve kterém je doklad zastoupený ({@see OpeningBalanceDocuments}):
     * faktura minulého roku převzatá do počátečních stavů vlastní zápis nemá a mít nesmí,
     * jejím předpisem je řádek saldokonta v počátečním stavu. Saldokonto ji tak už vede
     * ({@see \MyInvoice\Repository\SaldoRepository} fetchOpeningDocuments), úhrada z banky
     * nebo pokladny ji musí umět odúčtovat stejně jako fakturu s vlastním zápisem.
     * Bere se jen zaúčtovaný a nestornovaný otevírací zápis.
     *
     * @param list<int> $docIds
     * @return array<int,int>
     */
    private function openingEntryIds(int $supplierId, string $docType, array $docIds): array
    {
        if ($docIds === [] || !in_array($docType, ['invoice', 'purchase_invoice'], true)) {
            return [];
        }
        $in = implode(',', array_fill(0, count($docIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "WITH ranked AS (
                SELECT l.doc_id, e.id, ROW_NUMBER() OVER (PARTITION BY l.doc_id ORDER BY e.entry_date DESC, e.id DESC) AS position
                  FROM journal_entry_document_links l
                  JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
                 WHERE l.supplier_id = ? AND l.doc_type = ? AND l.doc_id IN ({$in})
                   AND e.source_type = 'opening' AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
             )
             SELECT doc_id, id FROM ranked WHERE position = 1"
        );
        $stmt->execute([$supplierId, $docType, ...$docIds]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /**
     * Saldokontní účet předpisu a zápisy, které ho nesou. U nativního předpisu beze změny
     * {@see predpisSaldoCode}. Ve skupině se účet bere jen z aktivních členů, které saldokontní
     * řádek (účet z pravidla nebo jeho analytika) skutečně nesou; každý člen se vyhodnotí
     * stejně jako nativní zápis. Nenese-li ho žádný člen, nebo ho dva nesou na různých účtech,
     * úhrada se automaticky nezaúčtuje: odhad by nechal otevřené saldo na jiném účtu.
     * Kurz předpisu se u skupiny bere z cizoměnové stopy saldokontních řádků nosičů.
     *
     * @param array{grouped:bool, entry_ids:list<int>, doc_type:string, doc_id:int, opening?:bool} $predpis
     * @return array{code:string, entry_ids:list<int>, fx_rate:?float, opening?:bool}
     */
    public function predpisSaldo(int $supplierId, array $predpis, string $code): array
    {
        if ($predpis['opening'] ?? false) {
            return [
                'code' => $this->openingSaldoCode($supplierId, $predpis, $code),
                'entry_ids' => $predpis['entry_ids'],
                'fx_rate' => null,
                'opening' => true,
            ];
        }
        if (!$predpis['grouped']) {
            return [
                'code' => $this->predpisSaldoCode($supplierId, $predpis['entry_ids'][0], $code),
                'entry_ids' => $predpis['entry_ids'],
                'fx_rate' => null,
            ];
        }
        return self::groupedSaldo($predpis, $code, (new DocumentPostingGroup($this->db))->lines($supplierId, $predpis['entry_ids']));
    }

    /**
     * @param array{doc_type:string, doc_id:int} $predpis
     * @param list<array<string,mixed>> $lines
     * @return array{code:string, entry_ids:list<int>, fx_rate:?float}
     */
    private static function groupedSaldo(array $predpis, string $code, array $lines): array
    {
        $byEntry = [];
        foreach ($lines as $line) {
            $account = (string) $line['account_code'];
            if ($account === $code || str_starts_with($account, $code . '.')) {
                $byEntry[(int) $line['entry_id']][] = $line;
            }
        }
        $label = ($predpis['doc_type'] === 'invoice' ? 'vydané faktury #' : 'přijaté faktury #') . $predpis['doc_id'];
        if ($byEntry === []) {
            throw new PostingException(
                'posting_group_saldo_ambiguous',
                'Žádný aktivní zápis skupiny zaúčtování ' . $label . ' nenese saldokontní řádek na účtu ' . $code
                    . '. Úhradu zaúčtujte ručně.',
            );
        }
        $codes = [];
        $rates = [];
        foreach ($byEntry as $lines) {
            $analytics = array_values(array_unique(array_filter(
                array_map(static fn (array $line): string => (string) $line['account_code'], $lines),
                static fn (string $account): bool => str_starts_with($account, $code . '.'),
            )));
            $codes[] = self::singleAnalyticCode($code, $analytics);
            foreach ($lines as $line) {
                if ($line['currency_code'] !== null && $line['fx_rate'] !== null && (float) $line['fx_rate'] > 0) {
                    $rates[] = (string) (float) $line['fx_rate'];
                }
            }
        }
        $codes = array_values(array_unique($codes));
        $rates = array_values(array_unique($rates));
        if (count($codes) > 1 || count($rates) > 1) {
            throw new PostingException(
                'posting_group_saldo_ambiguous',
                'Saldokonto ' . $label . ' nesou ve skupině zaúčtování různé účty nebo kurzy ('
                    . implode(', ', count($codes) > 1 ? $codes : $rates) . '). Úhradu zaúčtujte ručně.',
            );
        }
        return [
            'code' => $codes[0],
            'entry_ids' => array_keys($byEntry),
            'fx_rate' => $rates === [] ? null : (float) $rates[0],
        ];
    }

    /** Účet pro úhradu, kterou lze evidovat i bez předpisu. */
    public function saldoAccountOrFallback(int $supplierId, string $docType, int $docId, string $fallback): string
    {
        try {
            $predpis = $this->predpis($supplierId, $docType, $docId, false, 'Doklad nemá zaúčtovaný předpis.');
        } catch (PostingException $e) {
            if ($e->errorCode !== 'document_not_posted') {
                throw $e;
            }
            return $fallback;
        }
        if ($docType === 'purchase_invoice' && !$predpis['grouped'] && !($predpis['opening'] ?? false)) {
            $fallback = (new PurchasePayableAccount($this->db))->forPayment($supplierId, $docId, $fallback);
        }
        return $this->predpisSaldo($supplierId, $predpis, $fallback)['code'];
    }

    /**
     * Saldokonta dokladů v jedné dohodě o zápočtu. Předpisy i řádky načte dávkově.
     *
     * @param 'invoice'|'purchase_invoice' $docType
     * @param list<int> $docIds
     * @return array<int,string>
     */
    public function saldoAccountsOrFallback(int $supplierId, string $docType, array $docIds, string $fallback): array
    {
        $groups = new DocumentPostingGroup($this->db);
        $active = $groups->activeMany($supplierId, $docType, $docIds);
        $direct = $this->journal->latestBySourceIds($supplierId, $docType, $docIds);
        $opening = $this->openingEntryIds($supplierId, $docType, array_values(array_diff($docIds, array_keys($direct))));

        $predpisy = [];
        $entryIds = [];
        foreach ($docIds as $docId) {
            $predpis = self::predpisFromGroup($docType, $docId, $active[$docId] ?? [])
                ?? self::directPredpis($docType, $docId, $direct[$docId] ?? null, $opening[$docId] ?? null, false);
            if ($predpis !== null) {
                $predpisy[$docId] = $predpis;
                foreach ($predpis['entry_ids'] as $entryId) {
                    $entryIds[$entryId] = $entryId;
                }
            }
        }
        $byEntry = [];
        foreach ($groups->lines($supplierId, array_values($entryIds)) as $line) {
            $byEntry[(int) $line['entry_id']][] = $line;
        }

        $accounts = array_fill_keys($docIds, $fallback);
        foreach ($predpisy as $docId => $predpis) {
            if ($predpis['grouped']) {
                $lines = [];
                foreach ($predpis['entry_ids'] as $entryId) {
                    array_push($lines, ...($byEntry[$entryId] ?? []));
                }
                $accounts[$docId] = self::groupedSaldo($predpis, $fallback, $lines)['code'];
                continue;
            }
            $codes = array_values(array_unique(array_column($byEntry[$predpis['entry_ids'][0]] ?? [], 'account_code')));
            if ($predpis['opening'] ?? false) {
                $matches = array_values(array_filter($codes, static fn (string $code): bool => $code === $fallback || str_starts_with($code, $fallback . '.')));
                $accounts[$docId] = self::openingCode($predpis, $fallback, $matches);
                continue;
            }
            $code = $docType === 'purchase_invoice'
                ? PurchasePayableAccount::paymentCode(PurchasePayableAccount::payableFromCodes($codes), $fallback)
                : $fallback;
            $analytics = array_values(array_filter($codes, static fn (string $account): bool => str_starts_with($account, $code . '.')));
            $accounts[$docId] = self::singleAnalyticCode($code, $analytics);
        }
        return $accounts;
    }

    /**
     * Kurz předpisu z cizoměnové stopy saldokontního řádku deníku (§35 — kurz zafixovaný
     * při zaúčtování, i kdyby se exchange_rate hlavičky dokladu později změnil). Fallback
     * na exchange_rate dokladu, když stopa chybí (předpis bez FX stopy).
     *
     * Skupina zaúčtování bere kurz ze saldokontních řádků svých nosičů ({@see predpisSaldo}),
     * bez stopy z ostatních řádků těchže nosičů.
     * U počátečního stavu chybí kurz jednotlivého dokladu k rozvahovému dni.
     *
     * @param array{code:string, entry_ids:list<int>, fx_rate:?float, opening?:bool} $saldo
     * @param array<string,mixed> $doc
     */
    public function predpisFxRate(int $supplierId, array $saldo, array $doc): float
    {
        // Počáteční stav cizoměnové pohledávky nese kurz k rozvahovému dni (přecenění),
        // ne kurz dokladu; otevírací zápis kurz jednotlivého dokladu nezná.
        if ($saldo['opening'] ?? false) {
            throw new PostingException(
                'fx_not_supported',
                'Cizoměnový doklad z počátečních stavů se automaticky neúčtuje, úhradu zaúčtujte ručně.',
            );
        }
        if ($saldo['fx_rate'] !== null) {
            return $saldo['fx_rate'];
        }
        foreach ($saldo['entry_ids'] as $entryId) {
            foreach ($this->journal->linesForEntry($entryId, $supplierId) as $l) {
                if ($l['currency_code'] !== null && $l['fx_rate'] !== null && (float) $l['fx_rate'] > 0) {
                    return (float) $l['fx_rate'];
                }
            }
        }
        $rate = isset($doc['exchange_rate']) ? (float) $doc['exchange_rate'] : 0.0;
        if ($rate > 0) {
            return $rate;
        }
        throw new PostingException('missing_exchange_rate', 'Předpis dokladu nemá kurz pro přepočet cizoměnové úhrady.');
    }

    /**
     * Doplní vyrovnávací řádek kurzového rozdílu: ΣMD − ΣD dosud sestavených řádků > 0
     * (peníze přinesly víc / závazek byl vyšší v CZK) → kurzový ZISK 663 D, < 0 → kurzová
     * ZTRÁTA 563 MD. Nula (kurz úhrady == kurz předpisu) → žádný řádek. Účty z kontací
     * fx.gain/fx.loss (jako FxRevaluationService), bez stropu (kurzový rozdíl je legitimní).
     *
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     */
    public function appendFxDifference(array &$lines, int $supplierId, string $entryDate, float $remainderCzk = 0.0, bool $incoming = true): void
    {
        $balance = PostingService::balanceCents($lines);
        $diff = $balance['debit'] - $balance['credit'];
        $profile = AccountRoleResolver::fromConnection($this->db)->forDate($supplierId, $entryDate);
        $remainderCents = (int) round(abs($remainderCzk) * 100.0);
        if ($remainderCents > 0) {
            $lines[] = $incoming
                ? self::supplementaryLine($profile->account('rounding.gain'), 'credit', $remainderCents / 100.0)
                : self::supplementaryLine($profile->account('rounding.loss'), 'debit', $remainderCents / 100.0);
            $diff += $incoming ? -$remainderCents : $remainderCents;
        }
        if ($diff === 0) {
            return;
        }
        [$loss, $gain] = $this->fxResultAccounts($supplierId, $entryDate);
        if ($diff > 0) {
            $lines[] = self::supplementaryLine($gain, 'credit', $diff / 100.0);
        } else {
            $lines[] = self::supplementaryLine($loss, 'debit', -$diff / 100.0);
        }
    }

    /**
     * FX role k datu platby zachovávají kontace 500. Protiúčet je dynamický
     * (saldokonto/peněžní účet daného případu), proto seed nese jen jednu stranu.
     *
     * @return array{0:string, 1:string} [loss, gain]
     */
    public function fxResultAccounts(int $supplierId, string $entryDate): array
    {
        $lossRule = $this->postingRules->resolve($supplierId, 'fx.loss');
        $gainRule = $this->postingRules->resolve($supplierId, 'fx.gain');
        $profile = AccountRoleResolver::fromConnection($this->db)->forDate($supplierId, $entryDate);
        $loss = $profile->account('fx.loss', $lossRule['debit_account_code'] ?? null);
        $gain = $profile->account('fx.gain', $gainRule['credit_account_code'] ?? null);
        return [(string) $loss, (string) $gain];
    }

    /**
     * Doplní saldokontnímu řádku cizoměnovou stopu (§4/12 — souběžné vedení v cizí měně;
     * podklad pro přecenění §24/6). amount zůstává v CZK (hodnota předpisu).
     *
     * @param array{account_code:string, side:string, amount:float} $line
     * @return array{account_code:string, side:string, amount:float, currency_code:string, fx_rate:float, amount_foreign:float}
     */
    public static function withFxTrace(array $line, string $currency, float $fxRate, float $foreign): array
    {
        $line['currency_code'] = $currency;
        $line['fx_rate'] = $fxRate;
        $line['amount_foreign'] = round($foreign, 2);
        return $line;
    }

    /**
     * Doplňkový řádek úhrady (haléřové vyrovnání, kurzový rozdíl). V jednoduchém zápisu
     * se páruje postupně s hlavním řádkem strany (JournalPairer, role `rounding`), takže
     * hromadná úhrada s rozdílem zůstane rozdělitelná na dvojice. Složená firma klíč
     * nevidí, zaúčtování ho zahodí.
     *
     * @return array{account_code:string, side:string, amount:float, pair_role:string}
     */
    public static function supplementaryLine(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => round($amount, 2), 'pair_role' => JournalPairer::ROLE_ROUNDING];
    }
}

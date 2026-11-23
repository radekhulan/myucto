<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Bankovní účet POHODY v cizí měně: jediné místo, které převzaté výpisy, pohyby, párování
 * úhrad a deník takového účtu převádí na měnu účtu. Používá ho převod ({@see CashBankImporter},
 * {@see PohodaImporter}) i opravný průchod pro firmy převedené dřív v Kč
 * ({@see PohodaForeignBankRepair}).
 *
 * Pravidla:
 *  - pohyb i výpis nese částku v měně účtu (`foreignCurrency/priceSum`), Kč zůstávají jen
 *    v deníku, který se nemění (částky ani strany řádků);
 *  - řádek deníku na analytice účtu dostane měnu, kurz a částku v měně (souběžné vedení
 *    podle § 4 odst. 12 ZoÚ). Součet cizoměnových částek řádků pohybu se haléřově dorovná na
 *    částku pohybu, jinak by zůstatek účtu v měně neseděl na výpis a přecenění k rozvahovému
 *    dni ({@see \MyInvoice\Repository\ClosingRepository::bankProposals()}) by vycházelo jinak;
 *  - otevírací zápis účtu dostane počáteční stav v měně;
 *  - párování úhrad (`payment_matches.amount`) je v měně pohybu: přepočte se kurzem pohybu
 *    a párování, které kryla celý pohyb, ho kryje celý i v měně;
 *  - kurzový rozdíl, který POHODA zaúčtovala bankovním dokladem bez částky v měně (uzávěrka
 *    kurzových rozdílů), je pohyb s nulovou částkou v měně; jeho řádek deníku zůstává v Kč.
 */
final class PohodaForeignBank
{
    /** @var array<string,?float> */
    private array $rates = [];

    public function __construct(private readonly Connection $db) {}

    /**
     * Kurz ČNB měny k datu z uložených kurzů (`exchange_rates`): vyhlášený v ten den, jinak
     * poslední vyhlášený nejvýš týden před ním (víkend, svátek). Bez dotazu na ČNB.
     */
    public function cachedRate(string $currency, string $date): ?float
    {
        $key = $currency . '|' . $date;
        if (!array_key_exists($key, $this->rates)) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT rate FROM exchange_rates WHERE currency_code = ? AND rate_date <= ? AND rate_date >= ? ORDER BY rate_date DESC LIMIT 1'
            );
            $stmt->execute([$currency, $date, (new \DateTimeImmutable($date))->modify('-7 days')->format('Y-m-d')]);
            $rate = $stmt->fetchColumn();
            $this->rates[$key] = $rate !== false && (float) $rate > 0 ? (float) $rate : null;
        }
        return $this->rates[$key];
    }

    /**
     * Částky bankovního dokladu POHODY (XML i převod datového souboru): v Kč ze součtu
     * rekapitulace `homeCurrency` a v cizí měně z `foreignCurrency`. Výdej je záporný, příjem
     * se zápornou částkou (vratka) zůstává záporný. Kurz je za jednotku měny (`rate` za `amount`
     * jednotek); když nesedí na částky dokladu, platí poměr Kč a měny.
     *
     * @param array<string,mixed> $r záznam `lst:bank`
     * @return array{czk:float,currency:?string,foreign:?float,rate:?float}
     */
    public static function documentAmounts(array $r): array
    {
        $summary = PohodaXml::get($r, 'bankSummary/homeCurrency');
        $total = PohodaXml::num($summary, 'priceNone') + PohodaXml::num($summary, 'priceLowSum')
            + PohodaXml::num($summary, 'priceHighSum') + PohodaXml::num($summary, 'price3Sum') + PohodaXml::num($summary, 'round/priceRound');
        $sign = PohodaXml::text($r, 'bankHeader/bankType') === 'expense' ? -1 : 1;
        $czk = round($sign * $total, 2);
        $fx = PohodaXml::get($r, 'bankSummary/foreignCurrency');
        $currency = strtoupper(trim(PohodaXml::text($fx, 'currency/ids')));
        if ($fx === null || preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || $currency === 'CZK') {
            return ['czk' => $czk, 'currency' => null, 'foreign' => null, 'rate' => null];
        }
        $units = PohodaXml::num($fx, 'amount');
        $rate = PohodaXml::num($fx, 'rate') / ($units > 0 ? $units : 1.0);
        $rate = $rate > 0 ? $rate : null;
        $foreign = PohodaXml::text($fx, 'priceSum') !== ''
            ? round($sign * PohodaXml::num($fx, 'priceSum'), 2)
            : ($rate !== null ? round($czk / $rate, 2) : null);
        return ['czk' => $czk, 'currency' => $currency, 'foreign' => $foreign, 'rate' => self::rate($czk, $foreign, $rate)];
    }

    /**
     * Kurz pohybu za jednotku měny: kurz dokladu, když jeho přepočet sedí na Kč (haléřové
     * zaokrouhlení položek dokladu), jinak poměr Kč a částky v měně. Bez obojího null.
     */
    public static function rate(float $czk, ?float $foreign, ?float $declared): ?float
    {
        if ($foreign === null || abs($foreign) < 0.005 || abs($czk) < 0.005) {
            return $declared !== null ? round($declared, 6) : null;
        }
        $implied = abs($czk / $foreign);
        if ($declared !== null && abs(round($foreign * $declared, 2) - $czk) <= max(0.05, abs($czk) * 0.0005)) {
            return round($declared, 6);
        }
        return round($implied, 6);
    }

    /** Poslední id párování firmy: převod pak přepočte jen párování, která založil sám ({@see convertMatches()}). */
    public function lastMatchId(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COALESCE(MAX(id), 0) FROM payment_matches WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Párování pohybů v cizí měně, která vznikla v Kč (převod páruje úhradami z POHODY v Kč),
     * přepočte na měnu pohybu kurzem pohybu. Když párování pohybu dohromady kryla celý pohyb
     * v Kč, kryjí ho celý i v měně: haléřový rozdíl jde na největší z nich.
     *
     * @param array<int,array{foreign:float,czk:float,rate:float}> $tx bank_transactions.id => částky pohybu
     * @param int|null $afterMatchId jen párování s vyšším id (založená tímto během převodu)
     * @return int počet přepočtených párování
     */
    public function convertMatches(int $supplierId, array $tx, ?int $afterMatchId = null): int
    {
        $tx = array_filter($tx, static fn (array $t): bool => $t['rate'] > 0);
        if ($tx === []) {
            return 0;
        }
        $pdo = $this->db->pdo();
        $update = $pdo->prepare('UPDATE payment_matches SET amount = ? WHERE id = ? AND supplier_id = ? AND amount = ?');
        $converted = 0;
        foreach (array_chunk(array_keys($tx), 500) as $chunk) {
            $sql = 'SELECT id, bank_transaction_id, amount FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')' . ($afterMatchId !== null ? ' AND id > ?' : '')
                . ' ORDER BY bank_transaction_id, amount DESC, id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$supplierId], $chunk, $afterMatchId !== null ? [$afterMatchId] : []));
            $byTx = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $byTx[(int) $row['bank_transaction_id']][] = ['id' => (int) $row['id'], 'old' => self::cents((float) $row['amount'])];
            }
            foreach ($byTx as $txId => $matches) {
                $t = $tx[$txId];
                $new = [];
                foreach ($matches as $i => $m) {
                    $new[$i] = (int) round($m['old'] / $t['rate']);
                }
                $oldSum = array_sum(array_column($matches, 'old'));
                $diff = abs(self::cents($t['foreign'])) - array_sum($new);
                if ($oldSum === abs(self::cents($t['czk'])) && $diff !== 0 && $new[0] + $diff > 0) {
                    $new[0] += $diff;
                }
                foreach ($matches as $i => $m) {
                    if ($new[$i] <= 0) {
                        $new[$i] = 1;
                    }
                    $update->execute([self::amount($new[$i]), $m['id'], $supplierId, self::amount($m['old'])]);
                    $converted += $update->rowCount();
                }
            }
        }
        return $converted;
    }

    /**
     * Měna, kurz a částka v měně na řádky deníku analytiky účtu, které patří k pohybům účtu
     * (vazba zápisu na pohyb `journal_entry_document_links`). Řádek, který měnu už nese, se
     * nemění; zápis, jehož některý řádek na účtu měnu už má, se přeskočí celý (opakovaný běh).
     * Částka v měně = Kč / kurz pohybu; součet řádků zápisu se dorovná na částku jeho pohybů,
     * když řádky na účtu dávají přesně Kč těch pohybů.
     *
     * @param array<int,array{foreign:float,czk:float,rate:float}> $tx bank_transactions.id => částky pohybu
     * @return array{lines:int,entries:int,fx_differences:int}
     */
    public function fillJournal(int $supplierId, int $accountId, string $currency, array $tx): array
    {
        $out = ['lines' => 0, 'entries' => 0, 'fx_differences' => 0];
        if ($tx === []) {
            return $out;
        }
        $pdo = $this->db->pdo();
        $entries = [];
        foreach (array_chunk(array_keys($tx), 500) as $chunk) {
            $stmt = $pdo->prepare(
                "SELECT k.doc_id AS tx_id, l.id, l.entry_id, l.side, l.amount, l.is_red_storno, l.currency_code
                   FROM journal_entry_document_links k
                   JOIN journal_entries e ON e.id = k.entry_id AND e.supplier_id = k.supplier_id
                   JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  WHERE k.supplier_id = ? AND k.doc_type = 'bank' AND k.doc_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')
                    AND l.account_id = ? AND e.reversed_by IS NULL'
            );
            $stmt->execute(array_merge([$supplierId], $chunk, [$accountId]));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $entryId = (int) $row['entry_id'];
                $entries[$entryId]['tx'][(int) $row['tx_id']] = true;
                $entries[$entryId]['lines'][(int) $row['id']] = $row;
            }
        }
        // Zápis navázaný i na pohyb mimo převáděné (jiný účet, jiná dávka) zná jen část svých pohybů.
        $entryIds = array_keys($entries);
        foreach (array_chunk($entryIds, 500) as $chunk) {
            $stmt = $pdo->prepare(
                "SELECT entry_id, doc_id FROM journal_entry_document_links
                  WHERE supplier_id = ? AND doc_type = 'bank' AND entry_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $stmt->execute(array_merge([$supplierId], $chunk));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!isset($tx[(int) $row['doc_id']])) {
                    $entries[(int) $row['entry_id']]['foreign_links'] = true;
                }
            }
        }
        $update = $pdo->prepare(
            'UPDATE journal_entry_lines SET currency_code = ?, fx_rate = ?, amount_foreign = ?
              WHERE id = ? AND supplier_id = ? AND currency_code IS NULL'
        );
        foreach ($entries as $entry) {
            $lines = array_values($entry['lines']);
            if (array_filter($lines, static fn (array $l): bool => $l['currency_code'] !== null) !== []) {
                continue;
            }
            $linked = array_intersect_key($tx, $entry['tx']);
            $targetForeign = 0;
            $targetCzk = 0;
            $rates = [];
            $anyForeign = false;
            foreach ($linked as $t) {
                $targetForeign += self::cents($t['foreign']);
                $targetCzk += self::cents($t['czk']);
                $anyForeign = $anyForeign || self::cents($t['foreign']) !== 0;
                $rates[number_format($t['rate'], 6, '.', '')] = $t['rate'];
            }
            if (!$anyForeign) {
                // Kurzový rozdíl bez částky v měně: řádek zůstává v Kč.
                $out['fx_differences']++;
                continue;
            }
            // Pohyby téhož zápisu (převod tam a zpět, poplatky výpisu) mívají jeden kurz; jinak
            // poměr Kč a měny, a když se pohyby vzájemně ruší, kurz pohybu s největší částkou.
            uasort($linked, static fn (array $a, array $b): int => abs($b['foreign']) <=> abs($a['foreign']));
            $rate = count($rates) === 1 ? reset($rates)
                : ($targetCzk !== 0 && $targetForeign !== 0 ? round(abs($targetCzk / $targetForeign), 6) : reset($linked)['rate']);
            if ($rate <= 0) {
                continue;
            }
            $signed = 0;
            $foreign = [];
            $sum = 0;
            foreach ($lines as $i => $l) {
                $sgn = (($l['side'] === 'debit') !== ((int) $l['is_red_storno'] === 1)) ? 1 : -1;
                $lines[$i]['sgn'] = $sgn;
                $czk = self::cents((float) $l['amount']);
                $signed += $sgn * $czk;
                $foreign[$i] = (int) round($czk / $rate);
                $sum += $sgn * $foreign[$i];
            }
            $diff = $targetForeign - $sum;
            if (!isset($entry['foreign_links']) && $signed === $targetCzk && $diff !== 0) {
                $largest = 0;
                foreach ($lines as $i => $l) {
                    if ((float) $l['amount'] > (float) $lines[$largest]['amount']) {
                        $largest = $i;
                    }
                }
                $adjusted = $foreign[$largest] + $lines[$largest]['sgn'] * $diff;
                if ($adjusted > 0 && abs($diff) <= 5 * count($lines) + 2) {
                    $foreign[$largest] = $adjusted;
                }
            }
            $touched = 0;
            foreach ($lines as $i => $l) {
                if ($foreign[$i] <= 0) {
                    continue; // haléřový řádek, v měně nula: zůstává v Kč
                }
                $update->execute([$currency, number_format($rate, 6, '.', ''), self::amount($foreign[$i]), (int) $l['id'], $supplierId]);
                $touched += $update->rowCount();
            }
            if ($touched > 0) {
                $out['lines'] += $touched;
                $out['entries']++;
            }
        }
        return $out;
    }

    /**
     * Počáteční stav účtu v měně na řádky otevíracího zápisu období. Jediný řádek dostane
     * celý stav (strana musí odpovídat znaménku stavu); víc řádků (jednoduchá forma zápisu
     * po dvojicích) dostane Kč / kurz a součet se dorovná na stav na největším řádku.
     * Řádek, který měnu už nese, nic nemění.
     */
    public function fillOpening(int $supplierId, int $accountId, int $periodId, string $currency, float $foreign, float $rate): bool
    {
        if (abs($foreign) < 0.005 || $rate <= 0) {
            return false;
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT l.id, l.side, l.amount, l.is_red_storno, l.currency_code FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.account_id = ? AND e.period_id = ? AND e.source_type = 'opening'
              ORDER BY l.amount DESC, l.id"
        );
        $stmt->execute([$supplierId, $accountId, $periodId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($lines === [] || array_filter($lines, static fn (array $l): bool => $l['currency_code'] !== null) !== []) {
            return false;
        }
        $target = self::cents($foreign);
        $amounts = [];
        $sum = 0;
        foreach ($lines as $i => $l) {
            $lines[$i]['sgn'] = (($l['side'] === 'debit') !== ((int) $l['is_red_storno'] === 1)) ? 1 : -1;
            $amounts[$i] = count($lines) === 1 ? abs($target) : (int) round(self::cents((float) $l['amount']) / $rate);
            $sum += $lines[$i]['sgn'] * $amounts[$i];
        }
        $amounts[0] += $lines[0]['sgn'] * ($target - $sum);
        if (array_filter($amounts, static fn (int $a): bool => $a <= 0) !== []) {
            return false;
        }
        $update = $pdo->prepare(
            'UPDATE journal_entry_lines SET currency_code = ?, fx_rate = ?, amount_foreign = ?
              WHERE id = ? AND supplier_id = ? AND currency_code IS NULL'
        );
        $done = 0;
        foreach ($lines as $i => $l) {
            $update->execute([$currency, number_format($rate, 6, '.', ''), self::amount($amounts[$i]), (int) $l['id'], $supplierId]);
            $done += $update->rowCount();
        }
        return $done === count($lines);
    }

    /**
     * Převzaté výpisy účtu, které převod dřív založil v Kč, převede na měnu účtu: pohyby
     * dostanou částku v měně, výpisy měnu a stavy navazující od počátečního stavu v měně
     * ({@see rechain()}), párování se přepočtou ({@see convertMatches()}). Pohyb, který se
     * mezitím změnil (jiná částka nebo měna), převod zastaví výjimkou; volající drží transakci.
     *
     * @param list<int> $statementIds výpisy účtu v pořadí řetězu stavů
     * @param array<int,array{foreign:float,czk:float,rate:float}> $tx všechny pohyby výpisů
     * @param int|null $openingCents počáteční stav v haléřích měny (null = výpisy bez stavů)
     * @return array{transactions:int,matches:int}
     */
    public function convertStatements(int $supplierId, string $currency, array $statementIds, array $tx, ?int $openingCents): array
    {
        if ($statementIds === []) {
            return ['transactions' => 0, 'matches' => 0];
        }
        $pdo = $this->db->pdo();
        $in = implode(',', array_map('intval', $statementIds));
        $owned = $pdo->prepare("SELECT COUNT(*) FROM bank_statements WHERE supplier_id = ? AND source = 'import' AND id IN ({$in})");
        $owned->execute([$supplierId]);
        if ((int) $owned->fetchColumn() !== count(array_unique($statementIds))) {
            throw new PohodaException('foreign_bank_statement_missing', 'Převzatý výpis účtu se mezitím změnil, převod na měnu účtu se nespustil.');
        }
        $update = $pdo->prepare(
            "UPDATE bank_transactions SET amount = ?, currency = ?
              WHERE id = ? AND statement_id IN ({$in}) AND COALESCE(currency, 'CZK') = 'CZK' AND amount = ?"
        );
        $converted = 0;
        foreach ($tx as $id => $t) {
            $update->execute([self::amount(self::cents($t['foreign'])), $currency, $id, self::amount(self::cents($t['czk']))]);
            $converted += $update->rowCount();
        }
        $left = (int) $pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE statement_id IN ({$in}) AND COALESCE(currency, 'CZK') <> " . $pdo->quote($currency))->fetchColumn();
        if ($converted !== count($tx) || $left !== 0) {
            throw new PohodaException('foreign_bank_transaction_changed', 'Pohyb převzatého výpisu se mezitím změnil, převod na měnu účtu se nespustil.');
        }
        $pdo->prepare("UPDATE bank_statements SET currency = ? WHERE supplier_id = ? AND id IN ({$in})")->execute([$currency, $supplierId]);
        $this->rechain($supplierId, $statementIds, $openingCents);
        return ['transactions' => $converted, 'matches' => $this->convertMatches($supplierId, $tx)];
    }

    /**
     * Stavy a obraty převzatých výpisů znovu z pohybů, navazující od počátečního stavu
     * v pořadí `$statementIds`. Bez počátečního stavu zůstanou stavy prázdné, obraty se spočtou.
     *
     * @param list<int> $statementIds
     */
    public function rechain(int $supplierId, array $statementIds, ?int $openingCents): void
    {
        if ($statementIds === []) {
            return;
        }
        $pdo = $this->db->pdo();
        $in = implode(',', array_map('intval', $statementIds));
        $totals = [];
        $stmt = $pdo->query(
            "SELECT statement_id, SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) AS credit,
                    -SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END) AS debit, COUNT(*) AS n
               FROM bank_transactions WHERE statement_id IN ({$in}) AND source = 'statement' GROUP BY statement_id"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $totals[(int) $row['statement_id']] = [self::cents((float) $row['credit']), self::cents((float) $row['debit'])];
        }
        $update = $pdo->prepare(
            "UPDATE bank_statements SET prev_balance = ?, curr_balance = ?, credit_total = ?, debit_total = ?
              WHERE id = ? AND supplier_id = ? AND source = 'import'"
        );
        $running = $openingCents;
        foreach ($statementIds as $id) {
            [$credit, $debit] = $totals[$id] ?? [0, 0];
            $closing = $running !== null ? $running + $credit - $debit : null;
            $update->execute([
                $running !== null ? self::amount($running) : null,
                $closing !== null ? self::amount($closing) : null,
                self::amount($credit), self::amount($debit), $id, $supplierId,
            ]);
            $running = $closing;
        }
    }

    public static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public static function amount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}

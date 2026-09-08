<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Match;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Featura I (private/REAL_data_followup_UX.md) — audit spárovaných plateb banka↔faktura.
 * PŘEDdávkový report „co nesedí" u existujícího párování — na rozdíl od
 * {@see MatchSuggestionService} (která odmítá kandidáty V ČASE párování), tahle kontrola
 * běží nad UŽ hotovým párováním (vč. ručních matchů, které tolerance matcheru neprocházejí).
 *
 * Reálný nález (dnešní backfill): `match_status='manual'` NENÍ důkaz správnosti — ruční
 * spárování platby u optiky (CZK) jako úhrady USD faktury Navicat zmaterializovalo
 * vymyšlenou kurzovou ztrátu 227,63 Kč na transakci, která je v korunách (žádná konverze
 * tam neměla co dělat). Tahle kontrola takové případy chytá TŘEMI nezávislými signály:
 *
 *   1. currency_mismatch  — měna transakce ≠ měna dokladu, A NENÍ to legitimní CZK úhrada
 *      cizoměnového dokladu (ta prochází konverzí kurzem dokladu, viz {@see StatementMatcher::expectedMatch()}
 *      LOCAL_CURRENCY větev — AVYX případ v REKONCILIACI-2026-07-15.md). Flaguje se tedy jen
 *      "cizí měna transakce ≠ cizí měna dokladu" (EUR tx na USD fakturu) nebo "cizí měna
 *      transakce na CZK doklad" — obojí bez konverzního základu, tedy podezřelé.
 *   2. amount_mismatch    — částka spárování mimo toleranci (1 Kč u CZK, 1 % + 1 Kč FX floor
 *      u CZK↔cizí). Počítá se JEN pro doklady vypořádané JEDNÍM párováním (žádné částečné
 *      úhrady/přeplatky — vícero plateb na tentýž doklad se z tohoto srovnání vyjímá, aby
 *      legitimní splátky nevypadaly jako chyba).
 *   3. fx_on_czk_czk      — 563/663 (kurzový rozdíl) zaúčtovaný na bankovním zápisu, kde JAK
 *      transakce, TAK doklad jsou v CZK — přesně dnešní nález (žádná konverze = žádný kurzový
 *      rozdíl nemá vzniknout).
 *   4. counterparty_mismatch (volitelné, jen když je bt.counterparty_name vyplněný) — hrubé
 *      porovnání názvu protistrany transakce vs. partner dokladu; konzervativní (jen zjevné
 *      neshody bez společného tokenu).
 *
 * Read-only — NIC neopravuje, jen hlásí. Zapojeno do {@see \MyInvoice\Service\Accounting\Closing\ClosingService::buildChecks()}
 * jako klíč `payment_match_audit` (vzor Featura C / {@see \MyInvoice\Service\Currency\CnbRateDeviationChecker}).
 */
final class PaymentMatchAuditChecker
{
    /** Tolerance zaokrouhlení pro CZK↔CZK spárování (Kč). */
    public const CZK_TOLERANCE = 1.0;

    /**
     * Relativní tolerance pro post-hoc audit CZK úhrady cizoměnového dokladu.
     * Záměrně je přísnější než 4% přijímací tolerance matcheru: párování
     * neblokuje, pouze upozorňuje na již uloženou odchylku ke kontrole.
     */
    public const FX_TOLERANCE_PCT = 0.01;

    /** Práh podobnosti jmen (similar_text %) pod kterým se protistrana považuje za neshodnou. */
    private const NAME_SIMILARITY_THRESHOLD = 40.0;

    /**
     * Texty, které banka do pole protistrany dává MÍSTO jména — typ platby, popis
     * karetní transakce, označení terminálu.
     *
     * Naměřeno na ostrých datech: z 29 nálezů „protistrana nesedí" jich 28 vzniklo
     * porovnáním jména odběratele proti řetězci „Okamžitá platba" (22×) nebo proti
     * popisu karetní transakce („CLAUDE.AI SUBSCRIPTION SAN FRANCISCO USA",
     * „DPD DEPO 2366 EJPOVICE CZE"). To nejsou neshody — v tom poli prostě žádné
     * jméno protistrany není. Kontrola, která z 29 hlášení má 28 planých, přestane
     * být čtena, a tím zmizí i to jediné pravdivé.
     */
    private const GENERIC_COUNTERPARTY_LABELS = [
        'Okamžitá platba', 'Okamžitá odchozí platba', 'Okamžitá příchozí platba',
        'Platba kartou', 'Karetní transakce', 'Výběr z bankomatu', 'Vklad hotovosti',
        'Trvalý příkaz', 'Inkaso', 'Srážka', 'Poplatek', 'Úrok',
    ];

    /**
     * Vzory popisu karetní/terminálové transakce — velká písmena bez diakritiky
     * zakončená kódem země, případně označení depa. Jméno protistrany to není.
     */
    private const CARD_DESCRIPTOR_PATTERN = '/\b(CZE|USA|GBR|DEU|SVK|POL|AUT|NLD|IRL|FRA)\s*$/';

    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array{
     *   match_kind: 'invoice'|'purchase_invoice', doc_id: int, doc_no: ?string,
     *   partner_name: string, bank_transaction_id: int, tx_posted_at: string,
     *   tx_currency: string, doc_currency: string, tx_amount: float, doc_amount_to_pay: float,
     *   issues: list<string>, impact_czk: float, detail: array<string,mixed>,
     * }>
     */
    public function audit(int $supplierId, string $rangeFrom, string $rangeTo): array
    {
        $items = array_merge(
            $this->auditIssuedInvoices($supplierId, $rangeFrom, $rangeTo),
            $this->auditPurchaseInvoices($supplierId, $rangeFrom, $rangeTo),
        );
        usort($items, static fn (array $a, array $b): int => abs($b['impact_czk']) <=> abs($a['impact_czk']));

        return $items;
    }

    /**
     * Vydané faktury — 1:1 přes bank_transactions.matched_invoice_id.
     *
     * `allocated_amount` je částka z TÉTO transakce přiřazená TÉTO faktuře (evidence
     * `invoice_payments`). Jedna platba běžně hradí víc faktur — u odběratele s měsíčním
     * nájmem a doúčtováním je to pravidlo, ne výjimka. Porovnávat celou transakci proti
     * jedné faktuře pak hlásí rozdíl přesně ve výši té druhé faktury; naměřeno na ostrých
     * datech: transakce 154 819,50 Kč hradí 86 394,00 + 68 425,50 a kontrola z toho
     * udělala nález na 68 425,50 Kč. `tx_invoice_count` říká, kolik faktur platba kryje.
     */
    private function auditIssuedInvoices(int $supplierId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id AS tx_id, bt.posted_at AS tx_date, bt.amount AS tx_amount_raw,
                    bt.currency AS tx_currency, bs.currency AS stmt_currency,
                    bt.counterparty_name AS counterparty_name,
                    i.id AS doc_id, i.varsymbol AS doc_no, i.exchange_rate AS doc_rate,
                    i.amount_to_pay AS doc_amount_to_pay, i.status AS doc_status,
                    cur.code AS doc_currency, cl.company_name AS partner_name,
                    (SELECT COUNT(*) FROM invoice_payments ip WHERE ip.invoice_id = i.id) AS payment_count,
                    (SELECT SUM(ip2.amount) FROM invoice_payments ip2
                      WHERE ip2.invoice_id = i.id AND ip2.bank_transaction_id = bt.id) AS allocated_amount,
                    (SELECT COUNT(DISTINCT ip3.invoice_id) FROM invoice_payments ip3
                      WHERE ip3.bank_transaction_id = bt.id) AS tx_invoice_count
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
               JOIN invoices i ON i.id = bt.matched_invoice_id
               JOIN currencies cur ON cur.id = i.currency_id
               JOIN clients cl ON cl.id = i.client_id
              WHERE i.supplier_id = ?
                AND bt.match_status IN ('auto_exact', 'auto_partial', 'manual')
                AND bt.posted_at BETWEEN ? AND ?
              ORDER BY bt.id"
        );
        $stmt->execute([$supplierId, $from, $to]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $fxBooked = $this->bankFxBooked(array_column($rows, 'tx_id'));
        $items = [];
        foreach ($rows as $row) {
            // Proti dokladu se poměřuje ČÁST platby, která na něj byla přiřazena — ne celá
            // transakce. Bez evidence úhrady (starší data) zbývá jen celá částka.
            $allocated = $row['allocated_amount'] !== null ? (float) $row['allocated_amount'] : null;
            $item = $this->evaluate('invoice', $row, abs($allocated ?? (float) $row['tx_amount_raw']), $fxBooked[(int) $row['tx_id']] ?? null);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /** Přijaté faktury — N:N přes payment_matches (StatementMatcher::matchPurchase). */
    private function auditPurchaseInvoices(int $supplierId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id AS tx_id, bt.posted_at AS tx_date, pm.amount AS tx_amount_raw,
                    bt.currency AS tx_currency, bs.currency AS stmt_currency,
                    bt.counterparty_name AS counterparty_name,
                    pi.id AS doc_id,
                    COALESCE(NULLIF(pi.vendor_invoice_number, ''), pi.varsymbol) AS doc_no,
                    pi.exchange_rate AS doc_rate, pi.amount_to_pay AS doc_amount_to_pay,
                    pi.status AS doc_status, cur.code AS doc_currency, cl.company_name AS partner_name,
                    (SELECT COUNT(*) FROM payment_matches pm2 WHERE pm2.purchase_invoice_id = pi.id) AS payment_count
               FROM payment_matches pm
               JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
               JOIN bank_statements bs ON bs.id = bt.statement_id
               JOIN purchase_invoices pi ON pi.id = pm.purchase_invoice_id
               JOIN currencies cur ON cur.id = pi.currency_id
               JOIN clients cl ON cl.id = pi.vendor_id
              WHERE pm.supplier_id = ? AND pi.supplier_id = ?
                AND bt.posted_at BETWEEN ? AND ?
              ORDER BY bt.id"
        );
        $stmt->execute([$supplierId, $supplierId, $from, $to]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $fxBooked = $this->bankFxBooked(array_column($rows, 'tx_id'));
        $items = [];
        foreach ($rows as $row) {
            $item = $this->evaluate('purchase_invoice', $row, abs((float) $row['tx_amount_raw']), $fxBooked[(int) $row['tx_id']] ?? null);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * Vyhodnotí jedno spárování (tx, doklad) proti třem/čtyřem signálům. Vrací null,
     * pokud nic nesedí (žádný issue).
     *
     * @param array<string,mixed> $row
     */
    private function evaluate(string $matchKind, array $row, float $txAmountAbs, ?float $fxBooked): ?array
    {
        $txCurrency = self::effectiveCurrency($row['tx_currency'] ?? null, $row['stmt_currency'] ?? null);
        $docCurrency = strtoupper((string) $row['doc_currency']);
        $docRate = (float) ($row['doc_rate'] ?? 0);
        $docAmountToPay = (float) $row['doc_amount_to_pay'];
        $txId = (int) $row['tx_id'];

        $issues = [];
        $detail = [];
        $impact = 0.0;

        // 1) Měnový nesoulad — legitimní jen "CZK tx hradí cizoměnový doklad" (konverze kurzem
        // dokladu). Cizí měna tx na jinou cizí měnu dokladu, nebo cizí měna tx na CZK doklad,
        // konverzní základ nemají → podezřelé.
        $isLegitFxSettlement = $txCurrency === 'CZK' && $docCurrency !== 'CZK';
        if ($txCurrency !== $docCurrency && !$isLegitFxSettlement) {
            $issues[] = 'currency_mismatch';
            $detail['currency_mismatch'] = ['tx_currency' => $txCurrency, 'doc_currency' => $docCurrency];
            // Odhad dopadu v CZK: nejlepší dostupný přepočet (kurz dokladu, jinak 1:1).
            $estCzk = $docCurrency === 'CZK' ? $txAmountAbs : $txAmountAbs * ($docRate > 0 ? $docRate : 1.0);
            $impact = max($impact, abs($estCzk));
        }

        // 2) Částka mimo toleranci — jen pro doklad vypořádaný JEDNÍM párováním (žádné
        // částečné úhrady/přeplatky, ty tu NEflagujeme — legitimní, viz spec).
        $isSingleFullSettlement = (string) $row['doc_status'] === 'paid' && (int) $row['payment_count'] <= 1;
        if ($isSingleFullSettlement && ($txCurrency === $docCurrency || $isLegitFxSettlement)) {
            if ($txCurrency === $docCurrency) {
                $expected = $docAmountToPay;
                $tolerance = $docCurrency === 'CZK' ? self::CZK_TOLERANCE : max(self::CZK_TOLERANCE, $expected * self::FX_TOLERANCE_PCT);
            } else {
                // CZK tx hradí cizoměnový doklad — očekávaná CZK hodnota = doc × kurz dokladu.
                $rate = $docRate > 0 ? $docRate : 1.0;
                $expected = $docAmountToPay * $rate;
                $tolerance = max(self::CZK_TOLERANCE, $expected * self::FX_TOLERANCE_PCT);
            }
            // Dobropis/vratka má `amount_to_pay` ZÁPORNÉ, kdežto částka transakce se sem
            // předává v absolutní hodnotě. Porovnávat je přímo znamenalo hlásit dvojnásobek
            // částky jako rozdíl u každé vratky (naměřeno: doklad 3260231719, očekáváno
            // −4 453,19 vs. 4 453,00 → „rozdíl" 8 906,19 Kč, přitom sedí na 19 haléřů).
            $diff = $txAmountAbs - abs($expected);
            $explained = 0.0;
            if ($isLegitFxSettlement && abs($diff) > $tolerance) {
                // CZK úhrada cizoměnového dokladu se počítá kurzem DOKLADU, ale platí se
                // kurzem dne úhrady. Rozdíl je kurzový a účtuje se na 563/663 — pokud tam
                // zaúčtovaný JE, není to nesoulad, ale správně zachycený kurzový rozdíl.
                $explained = abs($fxBooked ?? 0.0);
            }
            if (abs($diff) - $explained > $tolerance) {
                $issues[] = 'amount_mismatch';
                $detail['amount_mismatch'] = [
                    'expected' => round(abs($expected), 2),
                    'actual' => round($txAmountAbs, 2),
                    'diff' => round($diff, 2),
                    'tolerance' => round($tolerance, 2),
                    'fx_booked' => round($explained, 2),
                ];
                // Rozdíl je v měně, ve které se porovnávalo. U dokladu v EUR hrazeného v EUR
                // je to tedy 3,67 EUR, ne 3,67 Kč — a `impact_czk` slouží i k řazení nálezů
                // podle závažnosti. Bez přepočtu by se 100 EUR zařadilo pod 200 Kč, protože
                // by se porovnávala holá čísla různých měn.
                $impactRate = $txCurrency === 'CZK' ? 1.0 : ($docRate > 0 ? $docRate : 1.0);
                $impact = max($impact, (abs($diff) - $explained) * $impactRate);
            }
        }

        // 3) Kurzový rozdíl (563/663) zaúčtovaný na bankovním zápisu, kde JAK tx, TAK
        // doklad jsou CZK — konverze tam nemá co dělat, tedy vymyšlený rozdíl (dnešní nález).
        if ($txCurrency === 'CZK' && $docCurrency === 'CZK') {
            if ($fxBooked !== null && abs($fxBooked) > 0.005) {
                $issues[] = 'fx_on_czk_czk';
                $detail['fx_on_czk_czk'] = ['amount' => round($fxBooked, 2)];
                $impact = max($impact, abs($fxBooked));
            }
        }

        // 4) Protistrana (volitelné, konzervativní) — jen když je counterparty_name k dispozici.
        $counterpartyName = trim((string) ($row['counterparty_name'] ?? ''));
        $partnerName = trim((string) ($row['partner_name'] ?? ''));
        if ($counterpartyName !== ''
            && !self::isGenericCounterpartyLabel($counterpartyName)
            && self::namesLikelyDifferent($counterpartyName, $partnerName)
        ) {
            $issues[] = 'counterparty_mismatch';
            $detail['counterparty_mismatch'] = ['counterparty_name' => $counterpartyName, 'partner_name' => $partnerName];
            $impact = max($impact, $txAmountAbs * ($txCurrency === 'CZK' ? 1.0 : ($docRate > 0 ? $docRate : 1.0)));
        }

        if ($issues === []) {
            return null;
        }

        return [
            'match_kind' => $matchKind,
            'doc_id' => (int) $row['doc_id'],
            'doc_no' => $row['doc_no'] !== null ? (string) $row['doc_no'] : null,
            'partner_name' => $partnerName,
            'bank_transaction_id' => $txId,
            'tx_posted_at' => (string) $row['tx_date'],
            'tx_currency' => $txCurrency,
            'doc_currency' => $docCurrency,
            'tx_amount' => round($txAmountAbs, 2),
            'doc_amount_to_pay' => round($docAmountToPay, 2),
            'issues' => $issues,
            'impact_czk' => round($impact, 2),
            // Dopad je přepočtený na koruny, takže v detailu musí svítit „Kč". Dokud se sem
            // brala měna dokladu, ukazovala se korunová částka s cizoměnovou zkratkou —
            // stejná záměna jako u kontroly kurzových rozdílů. Měna dokladu zůstává
            // v `doc_currency`, kde patří: údaje v ní nese `detail`.
            'currency' => 'CZK',
            'detail' => $detail,
        ];
    }

    /**
     * Součet částek na řádcích 563 (kurzová ztráta) / 663 (kurzový zisk) na bankovním zápisu
     * dané transakce — jde jen o MAGNITUDU dopadu (amount je v deníku vždy kladný, MD/D dané
     * `side`), ne o výsledovkové znaménko. Transakce bez takových řádků ve výsledku chybí.
     * Jen aktuálně platné (zaúčtované, nestornované) zápisy.
     */
    private function bankFxBooked(array $bankTransactionIds): array
    {
        $totals = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $bankTransactionIds))), 500) as $ids) {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT e.source_id, SUM(l.amount) AS total
                   FROM journal_entries e
                   JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                   JOIN chart_of_accounts ca ON ca.id = l.account_id
              LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  WHERE e.source_type = 'bank' AND e.source_id IN ($placeholders)
                    AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                    AND (ca.account_code LIKE '563%' OR ca.account_code LIKE '663%'
                         OR COALESCE(pa.account_code, '') LIKE '563%' OR COALESCE(pa.account_code, '') LIKE '663%')
                  GROUP BY e.source_id"
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $total) {
                $totals[(int) $id] = $total === null ? null : (float) $total;
            }
        }
        return $totals;
    }

    /** Efektivní měna transakce — tx.currency, jinak výpis, jinak CZK (vzor BankPostingService::effectiveCurrency). */
    private static function effectiveCurrency(?string $txCurrency, ?string $stmtCurrency): string
    {
        $c = $txCurrency ?: $stmtCurrency;
        return $c !== null && $c !== '' ? strtoupper($c) : 'CZK';
    }

    /**
     * Normalizace názvu firmy pro hrubé porovnání: diakritika → ASCII, interpunkce →
     * mezery (MUSÍ proběhnout PŘED odstraněním právní formy — jinak "s.r.o." s tečkami
     * neprojde \s*-based regexem a zbytečně nafoukne podobnost dvou jinak různých firem),
     * pak teprve odstranění právní formy jako celých tokenů.
     */
    private static function normalizeName(string $name): string
    {
        $lower = mb_strtolower($name, 'UTF-8');
        // Explicitní mapa místo iconv('ASCII//TRANSLIT'): ten na Windows z „á" udělá „'a",
        // takže „okamžitá" vyšlo jako „okamzit a" a „Nováková" jako „novak a". Rozsekané
        // slovo pak neodpovídalo ničemu a porovnání jmen hlásilo neshody, které nebyly.
        $ascii = strtr($lower, [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
            'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
            'ů' => 'u', 'ý' => 'y', 'ž' => 'z', 'ä' => 'a', 'ô' => 'o', 'ĺ' => 'l',
            'ľ' => 'l', 'ŕ' => 'r', 'ü' => 'u', 'ö' => 'o', 'ß' => 'ss', 'à' => 'a',
            'è' => 'e', 'ç' => 'c', 'ê' => 'e', 'î' => 'i', 'û' => 'u',
        ]);
        $spaced = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? $ascii;
        $stripped = preg_replace('/\b(s\s*r\s*o|a\s*s|spol|sro|gmbh|inc|ltd|co)\b/', '', $spaced) ?? $spaced;
        $normalized = preg_replace('/\s+/', ' ', $stripped) ?? $stripped;
        return trim((string) $normalized);
    }

    /**
     * Konzervativní kontrola neshody: TRUE jen když jsou po normalizaci zjevně různé
     * (žádné vzájemné obsažení, žádný sdílený smysluplný token, nízká podobnost).
     */
    /**
     * Je v poli protistrany místo jména jen technický popis platby?
     *
     * Porovnávat jméno odběratele proti „Okamžitá platba" nemá smysl — neshoda je jistá
     * a nic neznamená. Radši nález VYNECHÁME, než abychom jich vyrobili 28 planých:
     * kontrola s takovým poměrem se přestane číst celá.
     */
    private static function isGenericCounterpartyLabel(string $name): bool
    {
        $n = self::normalizeName($name);
        foreach (self::GENERIC_COUNTERPARTY_LABELS as $label) {
            // Normalizuj i vzor — porovnávat surový seznam proti normalizovanému jménu
            // je přesně ten druh tichého nesouladu, kvůli kterému filtr nezabral.
            $normalized = self::normalizeName($label);
            if ($normalized !== '' && ($n === $normalized || str_starts_with($n, $normalized))) {
                return true;
            }
        }

        return preg_match(self::CARD_DESCRIPTOR_PATTERN, trim($name)) === 1;
    }

    private static function namesLikelyDifferent(string $counterpartyName, string $partnerName): bool
    {
        $a = self::normalizeName($counterpartyName);
        $b = self::normalizeName($partnerName);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) {
            return false;
        }
        $tokensA = array_filter(explode(' ', $a), static fn (string $t): bool => strlen($t) >= 3);
        $tokensB = array_filter(explode(' ', $b), static fn (string $t): bool => strlen($t) >= 3);
        foreach ($tokensA as $t) {
            if (in_array($t, $tokensB, true)) {
                return false;
            }
        }
        similar_text($a, $b, $pct);
        return $pct < self::NAME_SIMILARITY_THRESHOLD;
    }
}

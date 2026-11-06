<?php

declare(strict_types=1);

namespace MyInvoice\Service\Currency;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Support\ExchangeRateDate;
use PDO;
use Throwable;

/**
 * Validace kurzu na dokladu proti dennímu kurzu ČNB (private/REAL_data_followup_UX.md,
 * Featura C). Cíl: chytit chybně zadaný/přepsaný kurz na cizoměnovém dokladu — v ostrém
 * auditu (2026-07) tohle odhalilo 2 reálné vady (24,710 vs 24,705 → +24 Kč;
 * 25,420 vs 25,460 → −207 Kč), obě FV, žádná PF.
 *
 * Srovnává VÝHRADNĚ kurz zapsaný na hlavičce dokladu (`invoices.exchange_rate` /
 * `purchase_invoices.exchange_rate`, platný k DUZP resp. datu vystavení — stejné
 * pole, které čte {@see \MyInvoice\Service\Accounting\PostingService} i
 * {@see \MyInvoice\Service\Report\VatLedgerService}) s denním ČNB kurzem PRO STEJNÝ
 * DEN. Nikdy nesrovnává s kurzem úhrady (`payment_exchange_rate`) — rozdíl kurz
 * předpisu vs. úhrady je legitimní kurzový rozdíl (563/663), ne chyba zadání.
 *
 * Firma v režimu pevného kurzu (§24/7 ZoÚ, `accounting_supplier_settings.fx_rate_mode`
 * != 'daily') má odchylku od ČNB ZÁMĚRNOU a zákonnou po celé období — kontrola se pro
 * ni přeskakuje úplně (nedávalo by smysl hlásit každý doklad).
 *
 * Mimo rozsah (vědomě): celní kurz pro dovozní DPH základ (jiná zákonná valuace,
 * ne chyba zadání kurzu do účetnictví) — pokud by na takový doklad zafiltroval
 * false positive, je to nutné posoudit ručně (kontrola je jen varování, ne blok).
 *
 * Read-only — jen detekce/varování, NIKDY automatická oprava kurzu.
 */
final class CnbRateDeviationChecker
{
    /** Práh odchylky v % — nad tímto se doklad označí jako podezřelý (konfigurovatelné per volání). */
    public const DEFAULT_THRESHOLD_PERCENT = 0.5;

    public function __construct(
        private readonly Connection $db,
        private readonly CnbExchangeRateClient $cnb,
        private readonly AccountingSupplierSettingsRepository $settings,
    ) {}

    /**
     * @return array{
     *   items: list<array{
     *     doc_type: 'invoice'|'purchase_invoice', doc_id: int, doc_no: ?string,
     *     date: string, currency: string, used_rate: float, cnb_rate: float,
     *     cnb_rate_date: string, diff_percent: float, impact_czk: float,
     *   }>,
     *   missing_cnb_count: int,
     *   fixed_mode_skipped: bool,
     * }
     */
    public function findDeviations(
        int $supplierId,
        string $rangeFrom,
        string $rangeTo,
        float $thresholdPercent = self::DEFAULT_THRESHOLD_PERCENT,
    ): array {
        // §24/7 — pevný kurz po celé období je legitimní, cíleně odlišný od ČNB.
        if ($this->settings->getFxRateMode($supplierId) !== 'daily') {
            return ['items' => [], 'missing_cnb_count' => 0, 'fixed_mode_skipped' => true];
        }

        $items = [];
        $missing = 0;

        foreach ($this->candidateDocs($supplierId, $rangeFrom, $rangeTo) as $doc) {
            try {
                $date = new DateTimeImmutable($doc['rate_date']);
            } catch (Throwable) {
                continue;
            }

            try {
                $result = $this->cnb->getRate($doc['currency'], $date);
            } catch (Throwable) {
                $result = null;
            }
            if ($result === null) {
                $missing++;
                continue;
            }

            $cnbRate = (float) $result['rate'];
            if ($cnbRate <= 0) {
                continue;
            }
            $usedRate = (float) $doc['exchange_rate'];
            $diffPercent = round((($usedRate - $cnbRate) / $cnbRate) * 100, 3);
            if (abs($diffPercent) <= $thresholdPercent) {
                continue;
            }

            $items[] = [
                'doc_type' => $doc['doc_type'],
                'doc_id' => $doc['id'],
                'doc_no' => $doc['doc_no'],
                'date' => $doc['rate_date'],
                'currency' => $doc['currency'],
                'used_rate' => $usedRate,
                'cnb_rate' => $cnbRate,
                'cnb_rate_date' => (string) $result['rate_date'],
                'diff_percent' => $diffPercent,
                // Dopad korekce na ČNB kurz v CZK (total_with_vat je v měně dokladu — stejná
                // konvence jako CrmAggregationService/PostingService: CZK = total * exchange_rate).
                'impact_czk' => round($doc['total_with_vat'] * ($cnbRate - $usedRate), 2),
            ];
        }

        usort($items, static fn (array $a, array $b): int => abs($b['impact_czk']) <=> abs($a['impact_czk']));

        return ['items' => $items, 'missing_cnb_count' => $missing, 'fixed_mode_skipped' => false];
    }

    /**
     * Odchylka kurzu JEDNOHO dokladu od denního ČNB kurzu k jeho rozhodnému dni —
     * pro varování při uložení dokladu (§C, save-time). Stejná logika jako
     * {@see findDeviations}, jen bez SQL: data přijdou od volajícího (uložený doklad).
     * Vrací detail odchylky, nebo NULL když se nevaruje (CZK / pevný režim §24/7 /
     * chybí kurz nebo datum / ČNB kurz nedostupný / odchylka do prahu).
     *
     * §73/6: srovnává VÝHRADNĚ účetní kurz z hlavičky (563/663 přepočet). NIKDY se
     * nedotýká korunové částky DPH na dokladu — u přijaté faktury s českou DPH je pro
     * odpočet rozhodná korunová částka na dokladu, ne přepočet ČNB. Tahle kontrola je
     * čistě o účetním přepočtu, nevynucuje přepis DPH.
     *
     * @return array{used_rate:float, cnb_rate:float, cnb_rate_date:string, diff_percent:float}|null
     */
    public function deviationWarning(
        int $supplierId,
        ?string $currencyCode,
        ?string $dateStr,
        ?float $usedRate,
        float $thresholdPercent = self::DEFAULT_THRESHOLD_PERCENT,
    ): ?array {
        if ($usedRate === null || $usedRate <= 0) {
            return null;
        }
        $code = strtoupper(trim((string) $currencyCode));
        if ($code === '' || $code === 'CZK') {
            return null;
        }
        // §24/7 — firma v pevném kurzu má odchylku od ČNB záměrnou a zákonnou.
        if ($this->settings->getFxRateMode($supplierId) !== 'daily') {
            return null;
        }
        if ($dateStr === null || $dateStr === '') {
            return null;
        }
        try {
            $date = new DateTimeImmutable($dateStr);
        } catch (Throwable) {
            return null;
        }
        try {
            $result = $this->cnb->getRate($code, $date);
        } catch (Throwable) {
            return null;
        }
        if ($result === null || !isset($result['rate'])) {
            return null;
        }
        $cnbRate = (float) $result['rate'];
        if ($cnbRate <= 0) {
            return null;
        }
        $diffPercent = round((($usedRate - $cnbRate) / $cnbRate) * 100, 3);
        if (abs($diffPercent) <= $thresholdPercent) {
            return null;
        }

        return [
            'used_rate' => $usedRate,
            'cnb_rate' => $cnbRate,
            'cnb_rate_date' => (string) $result['rate_date'],
            'diff_percent' => $diffPercent,
        ];
    }

    /**
     * Cizoměnové doklady s kurzem v rozsahu — sjednocené FV + PF, stejný filtr jako
     * {@see \MyInvoice\Repository\ClosingRepository::unpostedInvoices()}/`unpostedPurchases()`
     * (draft/cancelled a proforma/advance nedávají smysl pro účetní audit).
     *
     * Rozhodný den kurzu bere ze SSOT {@see ExchangeRateDate}. U přijatých faktur se dřív
     * používal `effective_cost_date` — to je ale GREATEST(DUZP, vystavení) z migrace 1010,
     * tedy datum uznání NÁKLADU. U dokladu s DUZP dřívějším než vystavení se ptal ČNB na
     * jiný den, než ke kterému kurz na dokladu patří, a hlásil falešnou odchylku. Větev
     * `invoices` používá `effective_tax_date` (migrace 1009) — to je týž výraz jako SSOT,
     * jen sargovatelný, takže zůstává (rozsahový filtr běží po indexu).
     *
     * @return list<array{doc_type:'invoice'|'purchase_invoice', id:int, doc_no:?string,
     *                     currency:string, exchange_rate:float, rate_date:string, total_with_vat:float}>
     */
    private function candidateDocs(int $supplierId, string $from, string $to): array
    {
        $purchaseRateDate = ExchangeRateDate::purchaseSql('pi');
        $stmt = $this->db->pdo()->prepare(
            "SELECT 'invoice' AS doc_type, i.id AS id, i.varsymbol AS doc_no, cur.code AS currency,
                    i.exchange_rate AS exchange_rate, i.effective_tax_date AS rate_date,
                    i.total_with_vat AS total_with_vat
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ? AND cur.code <> 'CZK'
                AND i.exchange_rate IS NOT NULL AND i.exchange_rate > 0
                AND i.status NOT IN ('draft','cancelled')
                AND i.invoice_type NOT IN ('proforma','cancellation','rent_calendar','advance_calendar')
                AND i.effective_tax_date BETWEEN ? AND ?
             UNION ALL
             SELECT 'purchase_invoice' AS doc_type, pi.id AS id, pi.varsymbol AS doc_no, cur2.code AS currency,
                    pi.exchange_rate AS exchange_rate, {$purchaseRateDate} AS rate_date,
                    pi.total_with_vat AS total_with_vat
               FROM purchase_invoices pi
               JOIN currencies cur2 ON cur2.id = pi.currency_id
              WHERE pi.supplier_id = ? AND cur2.code <> 'CZK'
                AND pi.exchange_rate IS NOT NULL AND pi.exchange_rate > 0
                AND pi.status NOT IN ('draft','cancelled')
                AND pi.document_kind <> 'advance'
                AND {$purchaseRateDate} BETWEEN ? AND ?
              ORDER BY doc_type, id"
        );
        $stmt->execute([$supplierId, $from, $to, $supplierId, $from, $to]);

        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['exchange_rate'] = (float) $r['exchange_rate'];
            $r['total_with_vat'] = (float) $r['total_with_vat'];
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}

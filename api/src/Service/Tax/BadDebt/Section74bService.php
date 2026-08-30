<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\BadDebt;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Section74bCorrectionRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\ActivityLogger;

/**
 * § 74b ZDPH — korekce odpočtu u neuhrazených závazků dlužníka (audit §2.5, PODVOJNE-AUDIT.md).
 *
 * Od 1. 1. 2025 musí dlužník-plátce snížit dříve uplatněný odpočet u přijatého zdanitelného
 * plnění, které neuhradil a uplynulo 6 kalendářních měsíců následujících po měsíci splatnosti;
 * po (částečné) úhradě se odpočet ve stejném poměru obnoví.
 *
 * ── MODEL VÝPOČTU (netting) ─────────────────────────────────────────────────────────────
 * Pro dané období (rok, měsíc) se u každého dotčeného plnění spočte CÍLOVÉ snížení:
 *
 *     target = aged ? round(claimed_deduction_vat × unpaid_ratio, 2) : 0
 *
 * kde `aged` = uplynulo 6 kal. měsíců po měsíci splatnosti, `unpaid_ratio` = neuhrazená část
 * / celková částka s DPH (0..1), `claimed_deduction_vat` = původně uplatněný odpočet z dokladu
 * (respektuje poměrný/krácený/žádný odpočet §75/§76). Pohyb v běžném období:
 *
 *     delta = target − net_corrected_so_far
 *
 * delta > 0 → snížení odpočtu (reduction), delta < 0 → obnovení (restoration). `unpaid` se
 * počítá ze SKUTEČNÝCH úhrad dokladu (záloha advance_paid_amount + bankovní úhrady přes
 * payment_matches s POSTED 'bank' zápisem + hotovostní úhrady cash_documents, shodná měna),
 * NE z generovaného amount_to_pay (ten úhrady bankou/hotovostí neodráží). Tím netting sám
 * správně zvládá částečné úhrady, splátky, zápočty i úplnou úhradu (unpaid_ratio → 0 ⇒
 * target 0 ⇒ plná obnova).
 *
 * ── BEZPEČNOST ──────────────────────────────────────────────────────────────────────────
 * {@see previewAging()} je READ-ONLY dry-run (nic nezapisuje/neúčtuje). Teprve
 * {@see recordAging()} vědomě zapíše pohyby do ledgeru §74b + auditní stopu — nikdy se
 * nevolá automaticky. Reverse-charge plnění (samovyměření, odpočet zrcadlí daň na výstupu)
 * jsou z §74b vyloučena, protože se dodavateli žádná úplata s DPH nedluží.
 *
 * ── FÁZOVÁNÍ ────────────────────────────────────────────────────────────────────────────
 * Jádro (aging, výpočet dotčené DPH, ledger, audit, dry-run, testy) je zde. Napojení výstupu
 * do DPHDP3/KH/knihy DPH XML (přesné řádkové mapování §74b) je označeno jako navazující —
 * viz {@see reportHintFor()} a poznámky v auditu; přesná ř. DPHDP3 vyžaduje potvrzení výkladu.
 *
 * @phpstan-type S74bRow array{
 *     purchase_invoice_id:int, vendor_name:string, vendor_dic:?string,
 *     vendor_invoice_number:string, tax_date:?string, due_date:string,
 *     total_with_vat:float, claimed_deduction_vat:float, unpaid_ratio:float,
 *     aged:bool, target_reduction:float, net_corrected:float, delta:float,
 *     movement:?string, state:string, dphdp3_line_hint:?string, kh_zdph_44:string
 * }
 */
final class Section74bService
{
    /**
     * Fallback default, kdyby daný rok neměl v TaxConstants klíč (nemělo by nastat).
     * Primárně se čte {@see TaxConstantsRepository::forYear()} pro rok korekce.
     */
    private const AGING_MONTHS = 6;

    public function __construct(
        private readonly Connection $db,
        private readonly Section74bCorrectionRepository $ledger,
        private readonly ActivityLogger $logger,
        private readonly TaxConstantsRepository $taxConstants,
    ) {}

    /**
     * READ-ONLY náhled (dry-run) korekcí §74b za dané období. Nezapisuje ani neúčtuje.
     *
     * @return array{
     *   period:array{year:int, month:int, period_end:string},
     *   rows:list<S74bRow>,
     *   totals:array{reduction:float, restoration:float, net_delta:float}
     * }
     */
    public function previewAging(int $supplierId, int $year, int $month): array
    {
        $agingMonths = (int) ($this->taxConstants->forYear($year)['s74b_aging_months'] ?? self::AGING_MONTHS);
        $periodEnd = self::monthEnd($year, $month);
        $candidates = $this->fetchCandidates($supplierId, $periodEnd);

        $invoiceIds = array_map(static fn ($r) => (int) $r['id'], $candidates);
        $netCorrectedMap = $this->ledger->netCorrectedByInvoice($supplierId, $invoiceIds);

        $periodYm = $year * 12 + $month;
        $rows = [];
        $sumReduction = 0.0;
        $sumRestoration = 0.0;

        foreach ($candidates as $c) {
            $invoiceId = (int) $c['id'];
            $totalWithVat = (float) $c['total_with_vat'];
            $claimed = $this->claimedDeductionVat($c);
            $netCorrected = $netCorrectedMap[$invoiceId] ?? 0.0;

            if ($claimed <= 0.0 && $netCorrected == 0.0) {
                continue;
            }

            // status='paid' je AUTORITATIVNÍ signál plné úhrady — nastavuje ho bankovní spárování
            // i ruční „označit uhrazeno" (vč. legacy dokladů BEZ payment_matches/cash záznamu).
            // Bez tohoto testu by ručně/legacy uhrazené doklady (realPaid z evidence = 0) vyšly
            // jako neuhrazené. Doklad zůstává v kandidátech (kvůli obnově odpočtu po úhradě, §74b
            // odst. 2/4 — target 0 vs. dřívější net_corrected dá restoration).
            // Částečné úhrady (status received/booked): neuhrazená část = total − skutečně
            // uhrazeno (záloha + banka přes payment_matches + hotovost cash_documents, viz
            // fetchCandidates); NE z generovaného amount_to_pay, který úhrady neodráží.
            $realPaid = $c['status'] === 'paid'
                ? $totalWithVat
                : ((float) $c['advance_paid_amount'] + (float) $c['bank_paid'] + (float) $c['cash_paid']);
            $unpaid = max(0.0, $totalWithVat - $realPaid);
            $unpaidRatio = $totalWithVat > 0.0 ? min(1.0, $unpaid / $totalWithVat) : 0.0;

            $aged = self::correctionPeriodYm((string) $c['due_date'], $agingMonths) <= $periodYm;
            $target = $aged ? round($claimed * $unpaidRatio, 2) : 0.0;
            $delta = round($target - $netCorrected, 2);

            // Nezobrazuj plnění, které není dotčené ani nemá historii korekce.
            if (!$aged && $netCorrected == 0.0) {
                continue;
            }
            if ($target == 0.0 && $netCorrected == 0.0) {
                continue;
            }

            $movement = $delta > 0.0 ? 'reduction' : ($delta < 0.0 ? 'restoration' : null);
            $state = $target > 0.0 ? 'corrected' : ($netCorrected > 0.0 ? 'restored' : 'identified');

            if ($movement === 'reduction') {
                $sumReduction += $delta;
            } elseif ($movement === 'restoration') {
                $sumRestoration += -$delta;
            }

            $rows[] = [
                'purchase_invoice_id'   => $invoiceId,
                'vendor_name'           => (string) ($c['vendor_name'] ?? ''),
                'vendor_dic'            => $c['vendor_dic'] !== null ? (string) $c['vendor_dic'] : null,
                'vendor_invoice_number' => (string) ($c['vendor_invoice_number'] ?? ''),
                'tax_date'              => $c['tax_date'] !== null ? (string) $c['tax_date'] : null,
                'due_date'              => (string) $c['due_date'],
                'total_with_vat'        => round($totalWithVat, 2),
                'claimed_deduction_vat' => round($claimed, 2),
                'unpaid_ratio'          => round($unpaidRatio, 6),
                'aged'                  => $aged,
                'target_reduction'      => $target,
                'net_corrected'         => round($netCorrected, 2),
                'delta'                 => $delta,
                'movement'              => $movement,
                'state'                 => $state,
                'dphdp3_line_hint'      => self::reportHintFor($movement),
                'kh_zdph_44'            => 'P',
            ];
        }

        return [
            'period' => ['year' => $year, 'month' => $month, 'period_end' => $periodEnd],
            'rows'   => $rows,
            'totals' => [
                'reduction'   => round($sumReduction, 2),
                'restoration' => round($sumRestoration, 2),
                'net_delta'   => round($sumReduction - $sumRestoration, 2),
            ],
        ];
    }

    /**
     * Zaeviduje pohyby §74b za období do ledgeru + auditní stopa. Zapisuje jen nenulové delty.
     * Vrací stejný přehled jako {@see previewAging()} doplněný o `recorded` počet pohybů.
     *
     * @return array<string,mixed>
     */
    public function recordAging(int $supplierId, int $year, int $month, ?int $userId): array
    {
        $preview = $this->previewAging($supplierId, $year, $month);
        $recorded = 0;

        foreach ($preview['rows'] as $row) {
            if ($row['movement'] === null || $row['delta'] == 0.0) {
                continue;
            }
            $this->ledger->recordMovement(
                $supplierId,
                $row['purchase_invoice_id'],
                $year,
                $month,
                $row['movement'],
                abs($row['delta']),
                $row['claimed_deduction_vat'],
                $row['unpaid_ratio'],
                $row['state'],
                null,
                $userId,
            );
            $recorded++;
        }

        $this->logger->log('tax.s74b_period_recorded', $userId, null, null, [
            'period'      => sprintf('%04d-%02d', $year, $month),
            'recorded'    => $recorded,
            'reduction'   => $preview['totals']['reduction'],
            'restoration' => $preview['totals']['restoration'],
        ]);

        $preview['recorded'] = $recorded;
        return $preview;
    }

    /**
     * Původně uplatněný odpočet DPH z dokladu. Respektuje poměrný/krácený/žádný odpočet
     * (§75/§76) přes `purchase_invoice_vat_allocations`; bez alokací = plný odpočet (total_vat).
     *
     * @param array<string,mixed> $c řádek kandidáta (musí nést claimed_alloc, alloc_count, total_vat)
     */
    private function claimedDeductionVat(array $c): float
    {
        $allocCount = (int) ($c['alloc_count'] ?? 0);
        if ($allocCount > 0) {
            return round((float) ($c['claimed_alloc'] ?? 0.0), 2);
        }
        // Bez alokací: úroveň dokladu (vat_deduction / vat_deduction_percent). Plný odpočet = total_vat.
        $deduction = (string) ($c['vat_deduction'] ?? 'full');
        if ($deduction === 'none') {
            return 0.0;
        }
        $percent = $c['vat_deduction_percent'] !== null ? (float) $c['vat_deduction_percent'] : 100.0;
        return round((float) $c['total_vat'] * $percent / 100.0, 2);
    }

    /**
     * Kandidáti §74b: tuzemská přijatá zdanitelná plnění s uplatnitelným odpočtem, jejichž
     * splatnost je do konce období. Reverse-charge (samovyměření) a stornované vyloučeny.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchCandidates(int $supplierId, string $periodEnd): array
    {
        // Skutečná úhrada přijaté faktury = záloha (advance_paid_amount) + bankovní úhrady
        // (payment_matches s POSTED 'bank' zápisem v ledgeru, reversed_by IS NULL, shodná měna)
        // + hotovostní úhrady (cash_documents s POSTED 'cash' zápisem, shodná měna). Zrcadlí
        // {@see PurchaseInvoiceRepository::paidAdvanceAmount()}. NEspoléhá na generovaný
        // amount_to_pay (= total_with_vat − advance_paid_amount), který úhrady neodráží.
        $sql =
            "SELECT pi.id, pi.vendor_id, pi.tax_date, pi.due_date,
                    pi.total_vat, pi.total_with_vat, pi.advance_paid_amount,
                    pi.paid_at, pi.status, pi.reverse_charge, pi.vendor_invoice_number,
                    pi.vat_classification_code, pi.vat_deduction, pi.vat_deduction_percent,
                    c.company_name AS vendor_name, c.dic AS vendor_dic,
                    alloc.claimed_alloc, alloc.alloc_count,
                    (SELECT COALESCE(SUM(pm.amount), 0)
                       FROM payment_matches pm
                       JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                       JOIN bank_statements bs ON bs.id = bt.statement_id
                       JOIN journal_entries je
                         ON je.supplier_id = pm.supplier_id AND je.source_type = 'bank'
                        AND je.source_id = pm.bank_transaction_id AND je.reversed_by IS NULL
                      WHERE pm.supplier_id = pi.supplier_id AND pm.purchase_invoice_id = pi.id
                        AND UPPER(COALESCE(bt.currency, bs.currency, 'CZK')) = cur.code
                    ) AS bank_paid,
                    (SELECT COALESCE(SUM(cd.total_amount), 0)
                       FROM cash_documents cd
                       JOIN journal_entries je
                         ON je.supplier_id = cd.supplier_id AND je.source_type = 'cash'
                        AND je.source_id = cd.id AND je.reversed_by IS NULL
                      WHERE cd.supplier_id = pi.supplier_id AND cd.purchase_invoice_id = pi.id
                        AND UPPER(cd.currency_code) = cur.code
                    ) AS cash_paid
               FROM purchase_invoices pi
               JOIN clients c ON c.id = pi.vendor_id
               JOIN currencies cur ON cur.id = pi.currency_id
               LEFT JOIN (
                   SELECT purchase_invoice_id,
                          SUM(CASE WHEN vat_deduction = 'none' THEN 0
                                   ELSE vat_amount * vat_deduction_percent / 100 END) AS claimed_alloc,
                          COUNT(*) AS alloc_count
                     FROM purchase_invoice_vat_allocations
                    WHERE supplier_id = ?
                 GROUP BY purchase_invoice_id
               ) alloc ON alloc.purchase_invoice_id = pi.id
              WHERE pi.supplier_id = ?
                AND pi.document_kind = 'invoice'
                -- 'draft' (odpočet ještě neuplatněn) a 'cancelled' se §74b netýkají; 'paid' se
                -- PONECHÁVÁ kvůli obnově odpočtu po úhradě (výpočet níže dá unpaid=0).
                AND pi.status IN ('received', 'booked', 'paid')
                AND pi.reverse_charge = 0
                AND pi.total_vat > 0
                AND pi.due_date <= ?
           ORDER BY pi.due_date, pi.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $supplierId, $periodEnd]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Období (year*12+month), do kterého náleží poslední den lhůty §74b a od kterého tak
     * u dokladu se splatností `dueDate` vzniká korekce odpočtu (§74b odst. 1 ZDPH):
     * pohledávka neuhrazená do posledního dne 6. kalendářního měsíce následujícího po měsíci
     * splatnosti; oprava se provádí za období, do kterého náleží poslední den lhůty.
     * Např. splatnost 2025-01 → 6 následujících měsíců únor–červenec → poslední den lhůty
     * 31. 7. 2025 → korekce za období 2025-07 (M + AGING_MONTHS).
     */
    private static function correctionPeriodYm(string $dueDate, int $agingMonths): int
    {
        $d = new \DateTimeImmutable($dueDate);
        $dueYm = ((int) $d->format('Y')) * 12 + (int) $d->format('n');
        return $dueYm + $agingMonths;
    }

    private static function monthEnd(int $year, int $month): string
    {
        return (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
            ->modify('last day of this month')->format('Y-m-d');
    }

    /**
     * Popisný hint, kam korekce §74b vstupuje v DPHDP3 (výklad potvrzen účetní expertízou):
     *   - SNÍŽENÍ (odst. 1/3): ř. 34 opr_dluz KLADNĚ + ř. 40/41 (základ i daň) ZÁPORNĚ.
     *   - OBNOVA (odst. 2/4): ř. 34 opr_dluz ZÁPORNĚ + ř. 40/41 (základ i daň) KLADNĚ.
     * Řádek 34 je informativní (daňovou povinnost neovlivní), skutečný efekt nese ř. 40/41.
     * Čistě popisné pro UI/report — samotné mapování řeší DphPriznaniBuilder.
     */
    private static function reportHintFor(?string $movement = null): string
    {
        return match ($movement) {
            'reduction'   => 'ř. 34 (+ DPH) a ř. 40/41 (− základ i daň)',
            'restoration' => 'ř. 34 (− DPH) a ř. 40/41 (+ základ i daň)',
            default       => 'ř. 34 + ř. 40/41',
        };
    }

    /**
     * EVIDOVANÉ korekce §74b za dané zdaňovací období, rozpadlé podle sazby DPH — podklad pro
     * napojení do DPHDP3 (ř. 34 + ř. 40/41) a KH (B.2, zdph_44='P'). Čte LEDGER (ne dry-run):
     * korekce se do přiznání promítne teprve po vědomém "zaevidování období" ({@see recordAging()}).
     *
     * Rozpad per sazba: zdroj je `purchase_invoice_vat_allocations` (respektuje §75/§76 odpočet),
     * jinak fallback na `purchase_invoice_items`, případně úroveň dokladu. Pohyb se rozpočítá
     * v poměru delta/claimed (base i daň stejným poměrem jako evidovaná delta) konzistentně
     * s netting modelem.
     *
     * Znaménka (potvrzený výklad + XSD anotace dphdp3.xsd):
     *   reduction (odst. 1/3):   ř. 40/41 základ i daň ZÁPORNĚ, ř. 34 opr_dluz KLADNĚ.
     *   restoration (odst. 2/4): ř. 40/41 základ i daň KLADNĚ,  ř. 34 opr_dluz ZÁPORNĚ.
     *
     * @param string $period 'monthly' (default) nebo 'quarterly' (sečte 3 měsíce čtvrtletí)
     * @return array{
     *   basic:array{base:float, vat:float}, reduced:array{base:float, vat:float}, opr_dluz:float,
     *   invoices:list<array{purchase_invoice_id:int, vendor_dic:?string, vendor_invoice_number:string,
     *                       tax_date:?string, base21:float, vat21:float, base12:float, vat12:float,
     *                       movement:string}>
     * }
     */
    public function periodCorrectionLines(int $supplierId, int $year, int $month, string $period = 'monthly'): array
    {
        $months = $period === 'quarterly' ? self::quarterMonths($month) : [$month];
        $movements = $this->correctionMovements($supplierId, $year, $months);

        $basic   = ['base' => 0.0, 'vat' => 0.0];
        $reduced = ['base' => 0.0, 'vat' => 0.0];
        $oprDluz = 0.0;
        $byInvoice = [];
        $bucketCache = [];
        // Práh dělící ř. 40 (základní sazba) od ř. 41 (snížená) se NEURČUJE konstantou, ale
        // odvozuje ze sazeb daného roku — střed mezi základní a sníženou
        // ({@see TaxConstantsRepository::vatBucketThreshold()}, tentýž zdroj jako DPHDP3
        // a KH). Natvrdo 16,5 platilo jen „dokud existují 21 a 12"; po změně kterékoli
        // sazby by tiše zařadilo korekci na špatný řádek. Rok bere z OBDOBÍ korekce, ne
        // z roku původního dokladu — sazba na řádku je snapshot dokladu, ale řádek
        // přiznání se plní ve formuláři TOHOTO období.
        $bucket = $this->taxConstants->vatBucketThreshold($year);

        foreach ($movements as $m) {
            $claimed  = (float) $m['claimed_deduction_vat'];
            $vatMoved = (float) $m['vat_amount']; // kladná absolutní hodnota pohybu
            if ($claimed <= 0.0 || $vatMoved == 0.0) {
                continue;
            }
            $invoiceId = (int) $m['purchase_invoice_id'];
            $fraction  = $vatMoved / $claimed; // podíl dokladu dotčený tímto pohybem
            if (!isset($bucketCache[$invoiceId])) {
                $bucketCache[$invoiceId] = $this->rateBucketsForInvoice($supplierId, $invoiceId, $claimed);
            }
            // reduction → ř. 40/41 záporně (sign −1); restoration → kladně (sign +1).
            $sign = $m['movement'] === 'reduction' ? -1.0 : 1.0;

            $b21 = 0.0; $v21 = 0.0; $b12 = 0.0; $v12 = 0.0;
            foreach ($bucketCache[$invoiceId] as $bk) {
                $baseCorr = round($bk['base'] * $fraction, 2);
                $vatCorr  = round($bk['claimed_vat'] * $fraction, 2);
                if ($bk['rate'] >= $bucket) {
                    $b21 += $baseCorr; $v21 += $vatCorr;
                } else {
                    $b12 += $baseCorr; $v12 += $vatCorr;
                }
            }

            $basic['base']   += $sign * $b21;
            $basic['vat']    += $sign * $v21;
            $reduced['base'] += $sign * $b12;
            $reduced['vat']  += $sign * $v12;
            // ř. 34 opr_dluz nese OPAČNÉ znaménko než ř. 40/41 (reduction kladně, restoration záporně).
            $oprDluz += (-$sign) * ($v21 + $v12);

            $key = $invoiceId;
            if (!isset($byInvoice[$key])) {
                $byInvoice[$key] = [
                    'purchase_invoice_id'   => $invoiceId,
                    'vendor_dic'            => $m['vendor_dic'] !== null ? (string) $m['vendor_dic'] : null,
                    'vendor_invoice_number' => (string) ($m['vendor_invoice_number'] ?? ''),
                    'tax_date'              => $m['tax_date'] !== null ? (string) $m['tax_date'] : null,
                    'base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0,
                ];
            }
            $byInvoice[$key]['base21'] += $sign * $b21;
            $byInvoice[$key]['vat21']  += $sign * $v21;
            $byInvoice[$key]['base12'] += $sign * $b12;
            $byInvoice[$key]['vat12']  += $sign * $v12;
        }

        $invoices = [];
        foreach ($byInvoice as $row) {
            $netVat = round($row['vat21'] + $row['vat12'], 2);
            if ($netVat == 0.0 && round($row['base21'] + $row['base12'], 2) == 0.0) {
                continue;
            }
            $invoices[] = [
                'purchase_invoice_id'   => $row['purchase_invoice_id'],
                'vendor_dic'            => $row['vendor_dic'],
                'vendor_invoice_number' => $row['vendor_invoice_number'],
                'tax_date'              => $row['tax_date'],
                'base21' => round($row['base21'], 2),
                'vat21'  => round($row['vat21'], 2),
                'base12' => round($row['base12'], 2),
                'vat12'  => round($row['vat12'], 2),
                // net < 0 = převažuje snížení (reduction), jinak obnova (restoration).
                'movement' => $netVat < 0.0 ? 'reduction' : 'restoration',
            ];
        }

        return [
            'basic'    => ['base' => round($basic['base'], 2), 'vat' => round($basic['vat'], 2)],
            'reduced'  => ['base' => round($reduced['base'], 2), 'vat' => round($reduced['vat'], 2)],
            'opr_dluz' => round($oprDluz, 2),
            'invoices' => $invoices,
        ];
    }

    /**
     * Evidované pohyby §74b za období, agregované per (doklad, typ pohybu) + metadata dokladu
     * (číslo, DUZP, DIČ dodavatele) pro KH. Kvartál = IN přes 3 měsíce.
     *
     * @param list<int> $months
     * @return list<array<string,mixed>>
     */
    private function correctionMovements(int $supplierId, int $year, array $months): array
    {
        $months = array_values(array_unique(array_map('intval', $months)));
        if ($months === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($months), '?'));
        $sql =
            "SELECT c.purchase_invoice_id, c.movement,
                    SUM(c.vat_amount) AS vat_amount,
                    MAX(c.claimed_deduction_vat) AS claimed_deduction_vat,
                    pi.vendor_invoice_number, pi.tax_date, cl.dic AS vendor_dic
               FROM vat_s74b_corrections c
               JOIN purchase_invoices pi ON pi.id = c.purchase_invoice_id
               JOIN clients cl ON cl.id = pi.vendor_id
              WHERE c.supplier_id = ? AND c.period_year = ? AND c.period_month IN ({$ph})
           GROUP BY c.purchase_invoice_id, c.movement, pi.vendor_invoice_number, pi.tax_date, cl.dic
           ORDER BY c.purchase_invoice_id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_merge([$supplierId, $year], $months));
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Rozpad dokladu na sazbové kbelíky {rate, base, claimed_vat} pro rozpočet korekce §74b.
     * Priorita zdroje: (1) `purchase_invoice_vat_allocations` (nese §75/§76 odpočet), (2) položky
     * `purchase_invoice_items` (daň se zkrátí doc-level odpočtem), (3) úroveň dokladu (jediná sazba
     * odvozená z total_vat/total_without_vat) jako poslední záchrana.
     *
     * @return list<array{rate:float, base:float, claimed_vat:float}>
     */
    private function rateBucketsForInvoice(int $supplierId, int $invoiceId, float $claimedFallback): array
    {
        $pdo = $this->db->pdo();

        // (1) Alokace — claimed_vat už respektuje rozsah odpočtu (none/proportional/reduced).
        $stmt = $pdo->prepare(
            "SELECT vat_rate AS rate,
                    SUM(base_amount) AS base,
                    SUM(CASE WHEN vat_deduction = 'none' THEN 0
                             ELSE vat_amount * vat_deduction_percent / 100 END) AS claimed_vat
               FROM purchase_invoice_vat_allocations
              WHERE supplier_id = ? AND purchase_invoice_id = ?
           GROUP BY vat_rate"
        );
        $stmt->execute([$supplierId, $invoiceId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows !== []) {
            return array_map(static fn ($r) => [
                'rate'        => (float) $r['rate'],
                'base'        => (float) $r['base'],
                'claimed_vat' => round((float) $r['claimed_vat'], 2),
            ], $rows);
        }

        // (2) Položky dokladu — daň zkrátíme doc-level odpočtem (§75/§76).
        $stmt = $pdo->prepare(
            "SELECT vat_rate_snapshot AS rate,
                    SUM(total_without_vat) AS base,
                    SUM(total_vat) AS vat
               FROM purchase_invoice_items
              WHERE purchase_invoice_id = ?
           GROUP BY vat_rate_snapshot"
        );
        $stmt->execute([$invoiceId]);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            'SELECT total_without_vat, total_vat, vat_deduction, vat_deduction_percent
               FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $deduction = (string) ($doc['vat_deduction'] ?? 'full');
        $percent = $deduction === 'none'
            ? 0.0
            : ($doc['vat_deduction_percent'] !== null ? (float) $doc['vat_deduction_percent'] : 100.0);

        if ($items !== []) {
            return array_map(static fn ($r) => [
                'rate'        => (float) $r['rate'],
                'base'        => (float) $r['base'],
                'claimed_vat' => round((float) $r['vat'] * $percent / 100.0, 2),
            ], $items);
        }

        // (3) Poslední záchrana: doklad bez položek i alokací — jediná sazba z poměru daň/základ.
        $base = (float) ($doc['total_without_vat'] ?? 0.0);
        $vat  = (float) ($doc['total_vat'] ?? 0.0);
        if ($base <= 0.0) {
            return [];
        }
        $rate = round($vat / $base * 100.0);
        return [[
            'rate'        => $rate,
            'base'        => $base,
            'claimed_vat' => round($claimedFallback, 2),
        ]];
    }

    /** @return list<int> tři měsíce čtvrtletí, do kterého spadá $month */
    private static function quarterMonths(int $month): array
    {
        $q = (int) ceil($month / 3);
        $start = ($q - 1) * 3 + 1;
        return [$start, $start + 1, $start + 2];
    }
}

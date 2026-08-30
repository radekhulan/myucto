<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\ActivityLogger;

/**
 * Neuhrazené dluhy po 30 měsících — § 23 odst. 3 písm. a) bod 12 ZDP a protistrana
 * § 23 odst. 3 písm. c) bod 6.
 *
 * Systém data MĚL (splatnost přijatých faktur i stav úhrady), dopočet ale nedělal a ani
 * neupozornil, takže základ daně vycházel podhodnocený. Audit to vedl mezi vysokými
 * riziky právě kvůli té kombinaci — mlčící systém nad daty, ze kterých se to spočítat dá.
 *
 * ── Model ───────────────────────────────────────────────────────────────────────────
 * Shodný netting jako u § 74b a § 46, potřetí:
 *
 *     target = uplynulo 30 měsíců od splatnosti ? dlužná částka : 0
 *     delta  = target − dosud evidovaný čistý stav
 *
 * delta > 0 → připočtení k základu (ř. 30 přiznání), delta < 0 → snížení po úhradě
 * (ř. 160). Částečné úhrady, splátky i doplacení po letech tím vyjdou samy a poplatník
 * nezaplatí daň dvakrát.
 *
 * ── Systém NEPŘIPOČÍTÁVÁ sám ────────────────────────────────────────────────────────
 * {@see preview()} je read-only NÁVRH — stejně jako u ostatních položek § 23 ho účetní
 * zváží a potvrdí. Důvod není opatrnost, ale zákon: bod 12 má výjimky, které z účetních
 * dat rozpoznat nelze — dluhy z titulu, u nějž výdaj nebyl daňově uznatelný, dluhy
 * v insolvenci, ze smluvních sankcí, z úvěrů a zápůjček. Automatické připočtení by
 * u firmy s takovým dluhem nadhodnotilo základ, což je stejná chyba jako dnešní
 * podhodnocení, jen opačným směrem.
 *
 * Zaevidování ({@see record()}) je proto vědomý úkon, který teprve založí protistranu
 * pro pozdější snížení.
 */
final class UnpaidLiabilityService
{
    /**
     * Fallback default, kdyby daný rok neměl v TaxConstants klíč (nemělo by nastat).
     * Primárně se čte {@see TaxConstantsRepository::forYear()} pro fiskální rok dokladu.
     */
    public const AGING_MONTHS = 30;

    /**
     * Zbytek do této výše se považuje za doplacený.
     *
     * Banka platí v celých korunách, faktura bývá na haléře — 64 393,21 Kč uhrazených
     * 64 393,00 Kč není dluh 21 haléřů. Táž hodnota jako `PaymentMatchAuditChecker`.
     */
    public const ROUNDING_TOLERANCE_CZK = 1.0;

    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly TaxConstantsRepository $taxConstants,
    ) {}

    /**
     * READ-ONLY návrh za zdaňovací období. Nic nezapisuje.
     *
     * @return array{
     *   fiscal_year:int, as_of:string, aging_months:int,
     *   rows:list<array{purchase_invoice_id:int, vendor_name:string, vendor_invoice_number:string,
     *                   due_date:string, liability_total:float, unpaid:float, unpaid_ratio:float,
     *                   aged:bool, target:float, net_recorded:float, delta:float, movement:?string}>,
     *   total_increase:float, total_decrease:float, net_delta:float, warnings:list<string>
     * }
     */
    public function preview(int $supplierId, int $fiscalYear, string $asOf): array
    {
        $agingMonths = (int) ($this->taxConstants->forYear($fiscalYear)['unpaid_liability_aging_months'] ?? self::AGING_MONTHS);
        $candidates = $this->candidates($supplierId, $asOf);
        $net = $this->netRecordedByInvoice(
            $supplierId,
            array_map(static fn ($r) => (int) $r['id'], $candidates),
        );

        $rows = [];
        $increase = 0.0;
        $decrease = 0.0;
        $agedCount = 0;

        foreach ($candidates as $c) {
            $invoiceId = (int) $c['id'];
            $total = round((float) $c['total_with_vat'], 2);
            $unpaidRatio = self::unpaidRatio($c);
            $unpaid = round($total * $unpaidRatio, 2);
            $recorded = $net[$invoiceId] ?? 0.0;

            $aged = self::agedBy((string) $c['due_date'], $asOf, $agingMonths);
            $target = $aged ? $unpaid : 0.0;
            $delta = round($target - $recorded, 2);

            // Doklad, který není dotčený a nemá historii, do návrhu nepatří.
            if ($target == 0.0 && $recorded == 0.0) {
                continue;
            }
            if ($aged) {
                $agedCount++;
            }

            $movement = $delta > 0.0 ? 'increase' : ($delta < 0.0 ? 'decrease' : null);
            if ($movement === 'increase') {
                $increase += $delta;
            } elseif ($movement === 'decrease') {
                $decrease += -$delta;
            }

            $rows[] = [
                'purchase_invoice_id'   => $invoiceId,
                'vendor_name'           => (string) ($c['vendor_name'] ?? ''),
                'vendor_invoice_number' => (string) ($c['vendor_invoice_number'] ?? ''),
                'due_date'              => (string) $c['due_date'],
                'liability_total'       => $total,
                'unpaid'                => $unpaid,
                'unpaid_ratio'          => round($unpaidRatio, 6),
                'aged'                  => $aged,
                'target'                => $target,
                'net_recorded'          => round($recorded, 2),
                'delta'                 => $delta,
                'movement'              => $movement,
            ];
        }

        $warnings = [];
        if ($agedCount > 0) {
            $warnings[] = sprintf(
                'Nalezeno %d neuhrazených dluhů po %d měsících od splatnosti (§ 23 odst. 3 písm. a) bod 12). '
                    . 'Systém je NEPŘIPOČÍTÁVÁ sám — z výčtu vylučte dluhy, u kterých výdaj nebyl daňově '
                    . 'uznatelný, dluhy v insolvenci, ze smluvních sankcí a z úvěrů a zápůjček; ty se '
                    . 'z účetních dat rozpoznat nedají.',
                $agedCount,
                $agingMonths,
            );
        }

        return [
            'fiscal_year'    => $fiscalYear,
            'as_of'          => $asOf,
            'aging_months'   => $agingMonths,
            'rows'           => $rows,
            'total_increase' => round($increase, 2),
            'total_decrease' => round($decrease, 2),
            'net_delta'      => round($increase - $decrease, 2),
            'warnings'       => $warnings,
        ];
    }

    /**
     * Zaeviduje pohyby za období do ledgeru + auditní stopa. Zapisuje jen nenulové delty
     * a jen doklady, které účetní ponechala (`$onlyInvoiceIds`); prázdný seznam = všechny
     * z návrhu.
     *
     * @param list<int> $onlyInvoiceIds
     * @return array<string,mixed>
     */
    public function record(
        int $supplierId,
        int $fiscalYear,
        string $asOf,
        array $onlyInvoiceIds = [],
        ?int $userId = null,
    ): array {
        $preview = $this->preview($supplierId, $fiscalYear, $asOf);
        $filter = $onlyInvoiceIds === [] ? null : array_flip(array_map('intval', $onlyInvoiceIds));
        $recorded = 0;

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tax_unpaid_liability_addbacks
                (supplier_id, purchase_invoice_id, fiscal_year, movement, amount,
                 liability_total, unpaid_ratio, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($preview['rows'] as $row) {
            if ($row['movement'] === null || $row['delta'] == 0.0) {
                continue;
            }
            // Snížení po úhradě se NEFILTRUJE: jakmile bylo něco připočteno, protistrana
            // musí proběhnout vždy, jinak zůstane základ trvale nadhodnocený.
            if ($filter !== null && $row['movement'] === 'increase'
                && !isset($filter[$row['purchase_invoice_id']])) {
                continue;
            }
            $stmt->execute([
                $supplierId, $row['purchase_invoice_id'], $fiscalYear, $row['movement'],
                abs($row['delta']), $row['liability_total'], $row['unpaid_ratio'], $userId,
            ]);
            $recorded++;
        }

        $this->logger->log('tax.unpaid_liability_recorded', $userId, null, null, [
            'fiscal_year' => $fiscalYear,
            'recorded'    => $recorded,
            'increase'    => $preview['total_increase'],
            'decrease'    => $preview['total_decrease'],
        ]);

        $preview['recorded'] = $recorded;
        return $preview;
    }

    /**
     * EVIDOVANÉ pohyby za období — podklad pro ř. 30 (zvýšení) a ř. 160 (snížení) přiznání.
     * Čte LEDGER, ne návrh: do přiznání se položka promítne teprve po vědomém zaevidování.
     *
     * @return array{increase:float, decrease:float}
     */
    public function recordedForYear(int $supplierId, int $fiscalYear): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT movement, COALESCE(SUM(amount), 0) AS total
               FROM tax_unpaid_liability_addbacks
              WHERE supplier_id = ? AND fiscal_year = ?
           GROUP BY movement"
        );
        $stmt->execute([$supplierId, $fiscalYear]);

        $out = ['increase' => 0.0, 'decrease' => 0.0];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['movement']] = round((float) $r['total'], 2);
        }

        return $out;
    }

    /**
     * Čistý dosud evidovaný stav (Σ increase − Σ decrease) per doklad.
     *
     * @param list<int> $invoiceIds
     * @return array<int,float>
     */
    private function netRecordedByInvoice(int $supplierId, array $invoiceIds): array
    {
        $invoiceIds = array_values(array_unique(array_map('intval', $invoiceIds)));
        if ($invoiceIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($invoiceIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT purchase_invoice_id,
                    SUM(CASE WHEN movement = 'increase' THEN amount ELSE -amount END) AS net
               FROM tax_unpaid_liability_addbacks
              WHERE supplier_id = ? AND purchase_invoice_id IN ({$ph})
           GROUP BY purchase_invoice_id"
        );
        $stmt->execute(array_merge([$supplierId], $invoiceIds));

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['purchase_invoice_id']] = round((float) $r['net'], 2);
        }

        return $out;
    }

    /**
     * Přijaté faktury se splatností do rozhodného dne. Zahrnuje i `paid` — právě z nich
     * vzniká SNÍŽENÍ základu po úhradě (§ 23/3/c/6); jejich vynechání by protistranu
     * znemožnilo a základ by zůstal trvale nadhodnocený.
     *
     * @return list<array<string,mixed>>
     */
    private function candidates(int $supplierId, string $asOf): array
    {
        // Skutečná úhrada = záloha + bankovní úhrady (payment_matches s POSTED zápisem)
        // + hotovost. Zrcadlí Section74bService::fetchCandidates() — generovaný
        // `amount_to_pay` úhrady bankou ani hotovostí neodráží.
        $sql =
            "SELECT pi.id, pi.due_date, pi.status, pi.vendor_invoice_number,
                    c.company_name AS vendor_name,
                    -- Podíl neuhrazeného se počítá v MĚNĚ DOKLADU (je bezrozměrný),
                    -- ale do základu daně jde částka v CZK. Proto obojí zvlášť.
                    ROUND(pi.total_with_vat, 2) AS total_doc,
                    ROUND(pi.total_with_vat * COALESCE(NULLIF(pi.exchange_rate, 0), 1), 2) AS total_with_vat,
                    ROUND(pi.advance_paid_amount, 2) AS advance_paid_amount,
                    COALESCE(NULLIF(pi.exchange_rate, 0), 1) AS exchange_rate,
                    -- Spárovaná platba je úhradou i tehdy, když bankovní zápis ještě
                    -- není zaúčtovaný — peníze odešly bez ohledu na stav účtování.
                    -- Původní JOIN takovou platbu zahodil a doklad vyšel jako
                    -- neuhrazený; v produkci takhle propadl desetinásobek dokladů.
                    -- Vylučuje se jen platba, jejíž zápis byl STORNOVÁN.
                    --
                    -- `payment_matches.amount` je v měně TRANSAKCE, ne dokladu. Převádí se
                    -- proto na měnu dokladu: CZK platba cizoměnového dokladu se dělí kurzem
                    -- dokladu, shodná měna se bere rovnou. Bez toho se odečítalo 180 EUR
                    -- mínus 4 374,90 CZK a doklad vyšel jako mnohonásobně přeplacený.
                    (SELECT COALESCE(SUM(
                              CASE
                                WHEN bt.currency = dcur.code THEN pm.amount
                                WHEN bt.currency = 'CZK' AND dcur.code <> 'CZK'
                                     THEN pm.amount / COALESCE(NULLIF(pi.exchange_rate, 0), 1)
                                ELSE 0
                              END), 0)
                       FROM payment_matches pm
                       JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                      WHERE pm.supplier_id = pi.supplier_id AND pm.purchase_invoice_id = pi.id
                        AND NOT EXISTS (
                              SELECT 1 FROM journal_entries je
                               WHERE je.supplier_id = pm.supplier_id AND je.source_type = 'bank'
                                 AND je.source_id = pm.bank_transaction_id
                                 AND je.reversed_by IS NOT NULL
                            )
                    ) AS bank_paid,
                    (SELECT COALESCE(SUM(cd.total_amount / COALESCE(NULLIF(pi.exchange_rate, 0), 1)), 0)
                       FROM cash_documents cd
                       JOIN journal_entries je
                         ON je.supplier_id = cd.supplier_id AND je.source_type = 'cash'
                        AND je.source_id = cd.id AND je.reversed_by IS NULL
                      WHERE cd.supplier_id = pi.supplier_id AND cd.purchase_invoice_id = pi.id
                    ) AS cash_paid
               FROM purchase_invoices pi
               JOIN clients c ON c.id = pi.vendor_id
               JOIN currencies dcur ON dcur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.document_kind = 'invoice'
                AND pi.status IN ('received', 'booked', 'paid')
                AND pi.total_with_vat > 0
                AND pi.due_date <= ?
           ORDER BY pi.due_date, pi.id";

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $asOf]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Neuhrazený podíl dluhu (0..1). `status='paid'` je autoritativní signál plné úhrady
     * i u legacy dokladů bez záznamu o platbě.
     *
     * @param array<string,mixed> $c
     */
    private static function unpaidRatio(array $c): float
    {
        // Podíl se počítá v měně dokladu — obě strany musí být v téže jednotce.
        $total = (float) ($c['total_doc'] ?? $c['total_with_vat']);
        if ($total <= 0.0) {
            return 0.0;
        }
        if ((string) $c['status'] === 'paid') {
            return 0.0;
        }
        $paid = (float) $c['advance_paid_amount'] + (float) $c['bank_paid'] + (float) $c['cash_paid'];
        $remaining = $total - $paid;

        // Zaokrouhlovací zbytek není dluh. Banka platí v celých korunách, faktura bývá
        // na haléře — bez tolerance vycházel doklad jako částečně neuhrazený se zbytkem
        // 0,04 až 0,35 Kč a generoval by dopočet k základu daně na haléře. Na ostrých
        // datech takhle propadlo 8 dokladů z 26 falešně neuhrazených.
        // Tolerance je v Kč, ale porovnává se v měně dokladu — u EUR faktury by
        // 1,00 bez přepočtu znamenalo ~24 Kč, tedy 24× benevolentnější práh.
        $rate = (float) ($c['exchange_rate'] ?? 1.0);
        $tolerance = $rate > 0.0 ? self::ROUNDING_TOLERANCE_CZK / $rate : self::ROUNDING_TOLERANCE_CZK;

        if ($remaining <= $tolerance) {
            return 0.0;
        }

        return min(1.0, $remaining / $total);
    }

    /** Uplynulo od splatnosti aspoň `$agingMonths` měsíců k rozhodnému dni? */
    private static function agedBy(string $dueDate, string $asOf, int $agingMonths): bool
    {
        $threshold = (new \DateTimeImmutable($dueDate))->modify('+' . $agingMonths . ' months');

        return new \DateTimeImmutable($asOf) >= $threshold;
    }
}

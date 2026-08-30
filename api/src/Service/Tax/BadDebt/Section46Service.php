<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\BadDebt;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Section46CorrectionRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\ActivityLogger;

/**
 * § 46 až § 46g ZDPH — oprava základu daně u nedobytné pohledávky (VĚŘITEL).
 *
 * Protějšek {@see Section74bService} na vydané straně. Systém tuhle stranu neuměl vůbec
 * (nález N-021): existoval jen ručně nastavitelný příznak `kh_bad_debt='P'`, který se
 * promítl do atributu `zdph_44` v KH A.4 — takže šlo podat kontrolní hlášení s příznakem
 * opravy, aniž by v přiznání vznikla jakákoli částka, a nic na ten rozpor neupozornilo.
 *
 * ── Proč se oprava ZADÁVÁ, a ne odvozuje ────────────────────────────────────────────
 * U § 74b je spouštěčem plynutí času a korekce je POVINNÁ, takže ji lze odvodit z dat.
 * Tady ne: věřitelská oprava je PRÁVEM a váže se na právní skutečnost, kterou účetní
 * systém nevidí — insolvenci, exekuci trvající aspoň dva roky, smrt dlužníka, likvidaci
 * (§ 46 odst. 1). Provede se navíc až vystavením a DORUČENÍM opravného daňového dokladu
 * (§ 46a–46e), a období opravy určuje datum doručení (§ 46f), ne splatnost.
 *
 * Automatické odvození by bylo horší než žádné: systém by tvrdil, že nárok na opravu
 * vznikl, aniž by měl jak ověřit důvod. {@see registerCorrection()} proto opravu přijímá
 * od uživatele a ověřuje to, co z dat ověřit LZE:
 *   - doklad je tuzemské zdanitelné plnění s daní na výstupu (reverse-charge vyloučen —
 *     věřitel tam žádnou daň neodvedl, takže nemá co opravovat),
 *   - pohledávka je skutečně neuhrazená a oprava nepřesáhne její neuhrazenou část,
 *   - u malé nedobytné pohledávky (§ 46 odst. 1 písm. f) i výše, lhůta a roční strop
 *     na dlužníka — tam jsou podmínky čistě početní a systém je ověřit umí.
 *
 * ── Obnova po úhradě (§ 46e) UŽ automatická je ──────────────────────────────────────
 * Dojde-li k (částečné) úhradě opravené pohledávky, musí věřitel daň ve stejném poměru
 * zvýšit zpět. Tady žádná externí právní skutečnost není — stačí úhrady, které systém
 * vidí. {@see previewRestorations()} je proto READ-ONLY dry-run nad evidovaným stavem
 * a {@see recordRestorations()} ho vědomě zapíše.
 *
 * ── Netting ─────────────────────────────────────────────────────────────────────────
 * Shodný model jako § 74b: cílový stav vs. dosud evidovaný.
 *
 *     target = output_vat × unpaid_ratio,   delta = target − net_corrected
 *
 * delta > 0 → oprava (correction), delta < 0 → obnova (restoration). Neuhrazená část se
 * bere z `amount_to_pay − paid_total` (SSOT úhrad vydaných faktur v celém repozitáři),
 * takže částečné úhrady, splátky i zápočty vyjdou samy.
 *
 * ── Promítnutí do výkazů ────────────────────────────────────────────────────────────
 * Zrcadlí § 74b s prohozenými stranami: oprava snižuje daň na výstupu, tedy ř. 1/2
 * (základ i daň) ZÁPORNĚ a informativní ř. 33 `opr_verit` KLADNĚ; obnova opačně.
 * Znaménko ř. 33 potvrzuje anotace XSD: „věřitel uvede kladnou hodnotu opravy daně
 * v případě nedobytné pohledávky podle § 46 a násl." Do KH jde řádek A.4 s `zdph_44='P'`.
 *
 * @phpstan-type S46Row array{
 *     invoice_id:int, varsymbol:string, client_name:string, client_dic:?string,
 *     tax_date:?string, due_date:string, total_with_vat:float, output_vat:float,
 *     unpaid_ratio:float, net_corrected:float, target:float, delta:float,
 *     movement:?string, legal_ground:?string
 * }
 */
final class Section46Service
{
    /**
     * Fallback defaulty, kdyby daný rok neměl v TaxConstants klíč (nemělo by nastat).
     * Primárně se čte {@see TaxConstantsRepository::forYear()} pro rok DORUČENÍ opravy.
     */
    public const SMALL_RECEIVABLE_LIMIT = 10000.0;

    /** § 46 odst. 1 písm. f) — roční strop souhrnu malých pohledávek za týmž dlužníkem. */
    public const SMALL_RECEIVABLE_DEBTOR_YEAR_LIMIT = 20000.0;

    /** § 46 odst. 1 písm. f) — minimální doba po splatnosti (měsíce). */
    public const SMALL_RECEIVABLE_MONTHS = 6;

    /** Právní důvody podle § 46 odst. 1. */
    public const LEGAL_GROUNDS = ['insolvency', 'execution', 'death', 'liquidation', 'small_receivable'];

    public function __construct(
        private readonly Connection $db,
        private readonly Section46CorrectionRepository $ledger,
        private readonly ActivityLogger $logger,
        private readonly TaxConstantsRepository $taxConstants,
    ) {}

    /**
     * READ-ONLY náhled pohledávek, u kterých oprava PŘIPADÁ V ÚVAHU — neuhrazené vydané
     * doklady s daní na výstupu po splatnosti. Nic netvrdí o právním důvodu: ten musí
     * doložit uživatel. Slouží jako pracovní seznam, ne jako nárok.
     *
     * @return list<S46Row>
     */
    public function previewCandidates(int $supplierId, string $asOf): array
    {
        $candidates = $this->fetchCandidates($supplierId, $asOf);
        $net = $this->ledger->netCorrectedByInvoice(
            $supplierId,
            array_map(static fn ($r) => (int) $r['id'], $candidates),
        );

        $rows = [];
        foreach ($candidates as $c) {
            $invoiceId = (int) $c['id'];
            $outputVat = round((float) $c['total_vat'], 2);
            $netCorrected = $net[$invoiceId] ?? 0.0;
            $unpaidRatio = self::unpaidRatio($c);

            if ($unpaidRatio <= 0.0 && $netCorrected == 0.0) {
                continue;
            }

            $target = round($outputVat * $unpaidRatio, 2);
            $rows[] = $this->row($c, $outputVat, $unpaidRatio, $netCorrected, $target,
                $this->ledger->legalGroundFor($supplierId, $invoiceId));
        }

        return $rows;
    }

    /**
     * Zaeviduje věřitelskou opravu k jednomu dokladu. Období určuje datum DORUČENÍ
     * opravného daňového dokladu dlužníkovi (§ 46f).
     *
     * Ověřuje jen početně ověřitelné podmínky; právní důvod (insolvence, exekuce, smrt,
     * likvidace) systém doložit neumí a bere ho jako vstup — proto se ukládá do ledgeru
     * a jde do auditní stopy.
     *
     * @return array{movement_id:int, vat_amount:float, period:array{year:int, month:int}, row:S46Row}
     */
    public function registerCorrection(
        int $supplierId,
        int $invoiceId,
        string $legalGround,
        string $deliveredOn,
        ?string $correctiveDocNumber = null,
        ?string $note = null,
        ?int $userId = null,
    ): array {
        if (!in_array($legalGround, self::LEGAL_GROUNDS, true)) {
            throw new \InvalidArgumentException(
                'Neznámý důvod opravy podle § 46 odst. 1: ' . $legalGround
            );
        }

        $c = $this->fetchInvoice($supplierId, $invoiceId);
        if ($c === null) {
            throw new \RuntimeException('Doklad #' . $invoiceId . ' nenalezen.');
        }
        $this->assertCorrectable($c);

        $delivered = new \DateTimeImmutable($deliveredOn);
        $outputVat = round((float) $c['total_vat'], 2);
        $unpaidRatio = self::unpaidRatio($c);

        if ($unpaidRatio <= 0.0) {
            throw new \RuntimeException(
                'Pohledávka je uhrazená — § 46 se vztahuje jen na neuhrazenou část.'
            );
        }
        if ($legalGround === 'small_receivable') {
            $this->assertSmallReceivable($supplierId, $c, $delivered);
        }

        $netCorrected = $this->ledger->netCorrectedByInvoice($supplierId, [$invoiceId])[$invoiceId] ?? 0.0;
        $target = round($outputVat * $unpaidRatio, 2);
        $delta = round($target - $netCorrected, 2);

        if ($delta <= 0.0) {
            throw new \RuntimeException(sprintf(
                'Oprava už je zaevidovaná v plné výši (%s Kč z %s Kč daně).',
                number_format($netCorrected, 2, ',', ' '),
                number_format($outputVat, 2, ',', ' '),
            ));
        }

        $year = (int) $delivered->format('Y');
        $month = (int) $delivered->format('n');

        $movementId = $this->ledger->recordMovement(
            $supplierId, $invoiceId, $year, $month, 'correction',
            $delta, $outputVat, $unpaidRatio, $legalGround,
            $correctiveDocNumber, $delivered->format('Y-m-d'), $note, $userId,
        );

        $this->logger->log('tax.s46_correction_registered', $userId, 'invoice', $invoiceId, [
            'period'       => sprintf('%04d-%02d', $year, $month),
            'legal_ground' => $legalGround,
            'vat_amount'   => $delta,
            'delivered_on' => $delivered->format('Y-m-d'),
        ]);

        return [
            'movement_id' => $movementId,
            'vat_amount'  => $delta,
            'period'      => ['year' => $year, 'month' => $month],
            'row'         => $this->row($c, $outputVat, $unpaidRatio, $netCorrected, $target, $legalGround),
        ];
    }

    /**
     * READ-ONLY náhled obnov daně po úhradě (§ 46e) za dané období. Nezapisuje.
     *
     * @return array{period:array{year:int, month:int, period_end:string}, rows:list<S46Row>, total:float}
     */
    public function previewRestorations(int $supplierId, int $year, int $month): array
    {
        $periodEnd = self::monthEnd($year, $month);
        $corrected = $this->ledger->correctedInvoices($supplierId);

        $rows = [];
        $total = 0.0;
        foreach ($corrected as $invoiceId => $netCorrected) {
            $c = $this->fetchInvoice($supplierId, $invoiceId);
            if ($c === null) {
                continue;
            }
            $outputVat = round((float) $c['total_vat'], 2);
            $unpaidRatio = self::unpaidRatio($c);
            $target = round($outputVat * $unpaidRatio, 2);
            $delta = round($target - $netCorrected, 2);

            // Jen obnovy: navýšení opravy je vědomý úkon přes registerCorrection()
            // (vyžaduje nový opravný doklad a jeho doručení).
            if ($delta >= 0.0) {
                continue;
            }

            $total += -$delta;
            $rows[] = $this->row($c, $outputVat, $unpaidRatio, $netCorrected, $target,
                $this->ledger->legalGroundFor($supplierId, $invoiceId));
        }

        return [
            'period' => ['year' => $year, 'month' => $month, 'period_end' => $periodEnd],
            'rows'   => $rows,
            'total'  => round($total, 2),
        ];
    }

    /**
     * Zapíše obnovy § 46e za období do ledgeru + auditní stopa.
     *
     * @return array<string,mixed>
     */
    public function recordRestorations(int $supplierId, int $year, int $month, ?int $userId): array
    {
        $preview = $this->previewRestorations($supplierId, $year, $month);
        $recorded = 0;

        foreach ($preview['rows'] as $row) {
            if ($row['delta'] >= 0.0) {
                continue;
            }
            $this->ledger->recordMovement(
                $supplierId, $row['invoice_id'], $year, $month, 'restoration',
                abs($row['delta']), $row['output_vat'], $row['unpaid_ratio'],
                $row['legal_ground'] ?? 'insolvency',
                null, null, null, $userId,
            );
            $recorded++;
        }

        $this->logger->log('tax.s46_restorations_recorded', $userId, null, null, [
            'period'   => sprintf('%04d-%02d', $year, $month),
            'recorded' => $recorded,
            'total'    => $preview['total'],
        ]);

        $preview['recorded'] = $recorded;
        return $preview;
    }

    /**
     * EVIDOVANÉ opravy § 46 za zdaňovací období rozpadlé podle sazby — podklad pro DPHDP3
     * (ř. 1/2 + informativní ř. 33 `opr_verit`) a KH (A.4 se `zdph_44='P'`). Čte LEDGER,
     * ne dry-run: do přiznání se oprava promítne teprve po vědomém zaevidování.
     *
     * Znaménka (anotace XSD `opr_verit` + zrcadlo § 74b):
     *   correction:  ř. 1/2 základ i daň ZÁPORNĚ, ř. 33 KLADNĚ.
     *   restoration: ř. 1/2 základ i daň KLADNĚ,  ř. 33 ZÁPORNĚ.
     *
     * @param string $period 'monthly' (default) nebo 'quarterly'
     * @return array{
     *   basic:array{base:float, vat:float}, reduced:array{base:float, vat:float}, opr_verit:float,
     *   invoices:list<array{invoice_id:int, client_dic:?string, varsymbol:string, tax_date:?string,
     *                       base21:float, vat21:float, base12:float, vat12:float, movement:string}>
     * }
     */
    public function periodCorrectionLines(int $supplierId, int $year, int $month, string $period = 'monthly'): array
    {
        $months = $period === 'quarterly' ? self::quarterMonths($month) : [$month];
        $movements = $this->correctionMovements($supplierId, $year, $months);

        $basic   = ['base' => 0.0, 'vat' => 0.0];
        $reduced = ['base' => 0.0, 'vat' => 0.0];
        $oprVerit = 0.0;
        $byInvoice = [];
        $bucketCache = [];
        // Práh dělící ř. 1 (základní sazba) od ř. 2 (snížená) se NEURČUJE konstantou, ale
        // odvozuje ze sazeb daného roku — střed mezi základní a sníženou
        // ({@see TaxConstantsRepository::vatBucketThreshold()}, stejný zdroj jako přiznání
        // a KH). Natvrdo 16,5 platilo jen „dokud existují 21 a 12": po změně kterékoli
        // sazby by mlčky zařadilo plnění na špatný řádek. Rok bere z OBDOBÍ korekce,
        // ne z roku původního dokladu — sazba na řádku je snapshot dokladu, ale řádek
        // přiznání se plní ve formuláři TOHOTO období.
        $bucket = $this->taxConstants->vatBucketThreshold($year);

        foreach ($movements as $m) {
            $outputVat = (float) $m['output_vat'];
            $vatMoved  = (float) $m['vat_amount'];
            if ($outputVat <= 0.0 || $vatMoved == 0.0) {
                continue;
            }
            $invoiceId = (int) $m['invoice_id'];
            $fraction  = $vatMoved / $outputVat;
            if (!isset($bucketCache[$invoiceId])) {
                $bucketCache[$invoiceId] = $this->rateBucketsForInvoice($invoiceId);
            }
            $sign = $m['movement'] === 'correction' ? -1.0 : 1.0;

            $b21 = 0.0; $v21 = 0.0; $b12 = 0.0; $v12 = 0.0;
            foreach ($bucketCache[$invoiceId] as $bk) {
                $baseCorr = round($bk['base'] * $fraction, 2);
                $vatCorr  = round($bk['vat'] * $fraction, 2);
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
            $oprVerit += (-$sign) * ($v21 + $v12);

            if (!isset($byInvoice[$invoiceId])) {
                $byInvoice[$invoiceId] = [
                    'invoice_id' => $invoiceId,
                    'client_dic' => $m['client_dic'] !== null ? (string) $m['client_dic'] : null,
                    'varsymbol'  => (string) ($m['varsymbol'] ?? ''),
                    'tax_date'   => $m['tax_date'] !== null ? (string) $m['tax_date'] : null,
                    'base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0,
                ];
            }
            $byInvoice[$invoiceId]['base21'] += $sign * $b21;
            $byInvoice[$invoiceId]['vat21']  += $sign * $v21;
            $byInvoice[$invoiceId]['base12'] += $sign * $b12;
            $byInvoice[$invoiceId]['vat12']  += $sign * $v12;
        }

        $invoices = [];
        foreach ($byInvoice as $row) {
            $netVat = round($row['vat21'] + $row['vat12'], 2);
            if ($netVat == 0.0 && round($row['base21'] + $row['base12'], 2) == 0.0) {
                continue;
            }
            $invoices[] = [
                'invoice_id' => $row['invoice_id'],
                'client_dic' => $row['client_dic'],
                'varsymbol'  => $row['varsymbol'],
                'tax_date'   => $row['tax_date'],
                'base21' => round($row['base21'], 2),
                'vat21'  => round($row['vat21'], 2),
                'base12' => round($row['base12'], 2),
                'vat12'  => round($row['vat12'], 2),
                'movement' => $netVat < 0.0 ? 'correction' : 'restoration',
            ];
        }

        return [
            'basic'     => ['base' => round($basic['base'], 2), 'vat' => round($basic['vat'], 2)],
            'reduced'   => ['base' => round($reduced['base'], 2), 'vat' => round($reduced['vat'], 2)],
            'opr_verit' => round($oprVerit, 2),
            'invoices'  => $invoices,
        ];
    }

    /**
     * Podmínky malé nedobytné pohledávky (§ 46 odst. 1 písm. f) — jediný důvod, jehož
     * splnění je čistě početní, a systém ho tedy ověřit umí. Dvě písemné výzvy k zaplacení
     * jsou hmotněprávní podmínkou, kterou z dat doložit nelze; zůstává na uživateli.
     *
     * @param array<string,mixed> $c
     */
    private function assertSmallReceivable(int $supplierId, array $c, \DateTimeImmutable $delivered): void
    {
        $year = (int) $delivered->format('Y');
        $tc = $this->taxConstants->forYear($year);
        $limit = (float) ($tc['bad_debt_small_receivable_limit'] ?? self::SMALL_RECEIVABLE_LIMIT);
        $debtorYearLimit = (float) ($tc['bad_debt_small_receivable_debtor_year_limit'] ?? self::SMALL_RECEIVABLE_DEBTOR_YEAR_LIMIT);
        $months = (int) ($tc['bad_debt_small_receivable_months'] ?? self::SMALL_RECEIVABLE_MONTHS);

        $totalWithVat = round((float) $c['total_with_vat'], 2);
        if ($totalWithVat > $limit) {
            throw new \RuntimeException(sprintf(
                'Malá nedobytná pohledávka je podle § 46 odst. 1 písm. f) nejvýše %s Kč včetně daně; doklad má %s Kč.',
                number_format($limit, 0, ',', ' '),
                number_format($totalWithVat, 2, ',', ' '),
            ));
        }

        $due = new \DateTimeImmutable((string) $c['due_date']);
        $earliest = $due->modify('+' . $months . ' months');
        if ($delivered < $earliest) {
            throw new \RuntimeException(sprintf(
                'Od splatnosti (%s) musí uplynout aspoň %d měsíců — nejdříve %s.',
                $due->format('j. n. Y'),
                $months,
                $earliest->format('j. n. Y'),
            ));
        }

        $already = $this->ledger->smallReceivableTotalForDebtor($supplierId, (int) $c['client_id'], $year);
        $unpaid = round($totalWithVat * self::unpaidRatio($c), 2);
        if ($already + $unpaid > $debtorYearLimit) {
            throw new \RuntimeException(sprintf(
                'Roční strop malých pohledávek za tímto dlužníkem je %s Kč; letos už opraveno %s Kč, tato oprava je %s Kč.',
                number_format($debtorYearLimit, 0, ',', ' '),
                number_format($already, 2, ',', ' '),
                number_format($unpaid, 2, ',', ' '),
            ));
        }
    }

    /** @param array<string,mixed> $c */
    private function assertCorrectable(array $c): void
    {
        if ((int) $c['reverse_charge'] === 1) {
            throw new \RuntimeException(
                'Reverse-charge plnění: věřitel daň na výstupu neodvedl, není co opravovat.'
            );
        }
        if (round((float) $c['total_vat'], 2) <= 0.0) {
            throw new \RuntimeException('Doklad nenese daň na výstupu — § 46 se na něj nevztahuje.');
        }
        if (in_array((string) $c['status'], ['draft', 'cancelled'], true)) {
            throw new \RuntimeException('Doklad ve stavu „' . $c['status'] . '" nelze opravovat podle § 46.');
        }
        if ((string) $c['invoice_type'] !== 'invoice') {
            throw new \RuntimeException('§ 46 se vztahuje na fakturu, ne na typ „' . $c['invoice_type'] . '".');
        }
    }

    /**
     * Neuhrazený podíl pohledávky (0..1). `amount_to_pay − paid_total` je SSOT úhrad
     * vydaných faktur napříč repozitářem; `status='paid'` je autoritativní signál plné
     * úhrady i u legacy dokladů bez záznamu v `invoice_payments`.
     *
     * @param array<string,mixed> $c
     */
    private static function unpaidRatio(array $c): float
    {
        $totalWithVat = (float) $c['total_with_vat'];
        if ($totalWithVat <= 0.0) {
            return 0.0;
        }
        if ((string) $c['status'] === 'paid') {
            return 0.0;
        }
        $unpaid = max(0.0, (float) $c['amount_to_pay'] - (float) $c['paid_total']);

        return min(1.0, $unpaid / $totalWithVat);
    }

    /**
     * SELECT vydaného dokladu vč. DIČ odběratele — DIČ preferenčně ze snapshotu
     * dokladu (stav k vystavení), fallback živý klient. SSOT výrazu:
     * {@see \MyInvoice\Service\Report\VatLedgerService::saleCounterpartyDicExpr()}.
     * Fragment KONČÍ tenant predikátem `WHERE i.supplier_id = ?` — volající
     * připojují jen další AND podmínky (a supplier_id dávají jako první parametr).
     */
    private function invoiceSelect(): string
    {
        $dicExpr = \MyInvoice\Service\Report\VatLedgerService::saleCounterpartyDicExpr($this->db, 'i', 'cl');

        return "SELECT i.id, i.varsymbol, i.invoice_type, i.client_id, i.tax_date, i.due_date,
                i.total_without_vat, i.total_vat, i.total_with_vat, i.amount_to_pay,
                i.paid_total, i.advance_paid_amount, i.status, i.reverse_charge,
                cl.company_name AS client_name, {$dicExpr} AS client_dic
           FROM invoices i
           JOIN clients cl ON cl.id = i.client_id
          WHERE i.supplier_id = ?";
    }

    /** @return array<string,mixed>|null */
    private function fetchInvoice(int $supplierId, int $invoiceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            $this->invoiceSelect() . ' AND i.id = ?'
        );
        $stmt->execute([$supplierId, $invoiceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Pohledávky, u kterých oprava připadá v úvahu: tuzemské vydané faktury s daní
     * na výstupu, po splatnosti, neuhrazené. Reverse-charge a stornované vyloučeny.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchCandidates(int $supplierId, string $asOf): array
    {
        $stmt = $this->db->pdo()->prepare(
            $this->invoiceSelect() .
            "   AND i.invoice_type = 'invoice'
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.reverse_charge = 0
                AND i.total_vat > 0
                AND i.due_date <= ?
           ORDER BY i.due_date, i.id"
        );
        $stmt->execute([$supplierId, $asOf]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Evidované pohyby § 46 za období, agregované per (doklad, pohyb) + metadata pro KH.
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
        // DIČ dlužníka ze snapshotu dokladu, fallback živý klient (viz invoiceSelect()).
        $dicExpr = \MyInvoice\Service\Report\VatLedgerService::saleCounterpartyDicExpr($this->db, 'i', 'cl');
        $sql =
            "SELECT c.invoice_id, c.movement,
                    SUM(c.vat_amount) AS vat_amount,
                    MAX(c.output_vat) AS output_vat,
                    i.varsymbol, i.tax_date, {$dicExpr} AS client_dic
               FROM vat_s46_corrections c
               JOIN invoices i ON i.id = c.invoice_id
               JOIN clients cl ON cl.id = i.client_id
              WHERE c.supplier_id = ? AND c.period_year = ? AND c.period_month IN ({$ph})
           GROUP BY c.invoice_id, c.movement, i.varsymbol, i.tax_date, {$dicExpr}
           ORDER BY c.invoice_id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_merge([$supplierId, $year], $months));

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Rozpad dokladu na sazbové kbelíky {rate, base, vat}. Zdrojem jsou položky faktury;
     * doklad bez položek spadne na jedinou sazbu odvozenou z poměru daň/základ.
     *
     * @return list<array{rate:float, base:float, vat:float}>
     */
    private function rateBucketsForInvoice(int $invoiceId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT vat_rate_snapshot AS rate,
                    SUM(total_without_vat) AS base,
                    SUM(total_vat) AS vat
               FROM invoice_items
              WHERE invoice_id = ?
           GROUP BY vat_rate_snapshot'
        );
        $stmt->execute([$invoiceId]);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($items !== []) {
            return array_map(static fn ($r) => [
                'rate' => (float) $r['rate'],
                'base' => (float) $r['base'],
                'vat'  => (float) $r['vat'],
            ], $items);
        }

        $stmt = $pdo->prepare('SELECT total_without_vat, total_vat FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $base = (float) ($doc['total_without_vat'] ?? 0.0);
        $vat  = (float) ($doc['total_vat'] ?? 0.0);
        if ($base <= 0.0) {
            return [];
        }

        return [['rate' => round($vat / $base * 100.0), 'base' => $base, 'vat' => $vat]];
    }

    /**
     * @param array<string,mixed> $c
     * @return S46Row
     */
    private function row(array $c, float $outputVat, float $unpaidRatio, float $netCorrected, float $target, ?string $legalGround): array
    {
        return [
            'invoice_id'     => (int) $c['id'],
            'varsymbol'      => (string) ($c['varsymbol'] ?? ''),
            'client_name'    => (string) ($c['client_name'] ?? ''),
            'client_dic'     => $c['client_dic'] !== null ? (string) $c['client_dic'] : null,
            'tax_date'       => $c['tax_date'] !== null ? (string) $c['tax_date'] : null,
            'due_date'       => (string) $c['due_date'],
            'total_with_vat' => round((float) $c['total_with_vat'], 2),
            'output_vat'     => $outputVat,
            'unpaid_ratio'   => round($unpaidRatio, 6),
            'net_corrected'  => round($netCorrected, 2),
            'target'         => $target,
            'delta'          => round($target - $netCorrected, 2),
            'movement'       => $target > $netCorrected ? 'correction' : ($target < $netCorrected ? 'restoration' : null),
            'legal_ground'   => $legalGround,
        ];
    }

    private static function monthEnd(int $year, int $month): string
    {
        return (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
            ->modify('last day of this month')->format('Y-m-d');
    }

    /** @return list<int> */
    private static function quarterMonths(int $month): array
    {
        $q = (int) ceil($month / 3);
        $start = ($q - 1) * 3 + 1;

        return [$start, $start + 1, $start + 2];
    }
}

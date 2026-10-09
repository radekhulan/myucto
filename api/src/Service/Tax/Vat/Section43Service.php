<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Vat;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use PDO;

/**
 * § 43 ZDPH — oprava výše daně v jiných případech (per doklad).
 *
 * Systém uměl dodatečné přiznání jako CELEK, ale neměl institut opravy per doklad ani
 * vazbu na období původního plnění: účetní musela rozdíl dopočítat ručně mimo systém
 * a nikde nezůstala stopa, ČEHO se oprava týkala — přesně to, co správce daně při
 * kontrole chce vidět.
 *
 * ── Čím se liší od § 42 ─────────────────────────────────────────────────────
 * § 42 opravuje ZÁKLAD daně (dobropis, sleva, vrácení) a patří do období DORUČENÍ
 * opravného dokladu, tedy dopředu. § 43 opravuje VÝŠI daně — plátce uplatnil daň jinak,
 * než stanoví zákon (chybná sazba, špatný výpočet) — a ta patří ZPĚTNĚ do období
 * původního plnění, do dodatečného přiznání.
 *
 * Proto se období opravy bere z `period_year`/`period_month` původního plnění, zatímco
 * `delivered_on` jen určuje, KDY nejdřív šlo opravu provést (§ 43 odst. 1 a 4).
 *
 * ── Sazba ───────────────────────────────────────────────────────────────────
 * § 43 odst. 2 přikazuje použít sazbu platnou ke dni povinnosti přiznat daň u PŮVODNÍHO
 * plnění, ne dnešní. Proto se ukládá jen sazbová SKUPINA (ř. 1 základní vs ř. 2 snížená);
 * konkrétní procento je vlastností původního dokladu a přepočítávat ho dnešní sazbou by
 * bylo v přímém rozporu s odst. 2.
 *
 * ── Prekluze ────────────────────────────────────────────────────────────────
 * § 43 odst. 3: opravu nelze provést po uplynutí lhůty pro stanovení daně (§ 148 DŘ,
 * zpravidla 3 roky). Lhůta běží ode dne, kdy uplynula lhůta pro podání ŘÁDNÉHO tvrzení
 * za období původního plnění — u DPH 25 dnů po jeho konci (§ 101 odst. 1 ZDPH) — ne od
 * konce kalendářního roku a ne od data opravného dokladu. U čtvrtletního plátce tedy
 * běží později než u měsíčního, protože jeho zdaňovací období končí až čtvrtletím.
 *
 * Read-only vůči účetnictví: nic neúčtuje, jen eviduje a sčítá do přiznání.
 */
final class Section43Service
{
    /**
     * Fallback default, kdyby daný rok neměl v TaxConstants klíč (nemělo by nastat).
     * Primárně se čte {@see TaxConstantsRepository::forYear()} pro rok PŮVODNÍHO plnění.
     */
    public const ASSESSMENT_PERIOD_YEARS = 3;

    /** Kód výjimky z {@see register()}: oprava by daň zvýšila (§ 43 odst. 1). */
    public const ERR_TAX_INCREASE = 4301;

    /** Kód výjimky z {@see register()}: přijatý doklad s přenesením daně. */
    public const ERR_REVERSE_CHARGE = 4302;

    public function __construct(
        private readonly Connection $db,
        private readonly TaxConstantsRepository $taxConstants,
    ) {}

    /**
     * Součty oprav pro řádky přiznání za období PŮVODNÍHO plnění.
     *
     * Sčítá TYTÉŽ záznamy, které {@see periodCorrections()} vrací Knize DPH a kontrolnímu
     * hlášení jednotlivě, takže přiznání, Kniha a KH nemohou mít každé svůj výběr ani
     * vlastní směrování na řádky.
     *
     * `basic`/`reduced` = daň na výstupu (ř. 1/2) z vydaných dokladů. `lines` = všechny
     * řádky přiznání včetně odpočtu příjemce (ř. 40/41, krácený 40k/41k). `warnings` =
     * opravy, které na žádný řádek přiznání nepatří (viz {@see periodCorrections()}).
     *
     * @return array{basic:array{base:float,vat:float}, reduced:array{base:float,vat:float},
     *               lines:array<string,array{base:float,vat:float}>, warnings:list<string>}
     */
    public function periodCorrectionLines(int $supplierId, int $year, int $month, string $period = 'monthly'): array
    {
        $lines = [];
        $warnings = [];
        foreach ($this->periodCorrections($supplierId, $year, $month, $period) as $r) {
            if ($r['dphdp3_line'] === null) {
                $warnings[] = (string) $r['warning'];
                continue;
            }
            $lines[$r['dphdp3_line']] ??= ['base' => 0.0, 'vat' => 0.0];
            $lines[$r['dphdp3_line']]['base'] += $r['base'];
            $lines[$r['dphdp3_line']]['vat']  += $r['vat'];
        }
        foreach ($lines as $line => $v) {
            $lines[$line] = ['base' => round($v['base'], 2), 'vat' => round($v['vat'], 2)];
        }

        return [
            'basic'    => $lines['1'] ?? ['base' => 0.0, 'vat' => 0.0],
            'reduced'  => $lines['2'] ?? ['base' => 0.0, 'vat' => 0.0],
            'lines'    => $lines,
            'warnings' => $warnings,
        ];
    }

    /**
     * Jednotlivé opravy za zdaňovací období, už zařazené na řádek přiznání. Jediné místo
     * pravidla, kam oprava patří; čte ho přiznání ({@see periodCorrectionLines()}), Kniha DPH
     * i kontrolní hlášení.
     *
     * Kam oprava patří:
     *   - VYDANÝ doklad: daň na výstupu, ř. 1 (základní) / ř. 2 (snížená). § 43 odst. 1
     *     opravuje ten, kdo daň přiznal, tedy dodavatel.
     *   - PŘIJATÝ doklad (tuzemský, bez přenesení daně): § 43 se týká dodavatele, příjemce
     *     daň na výstupu nepřiznal. U příjemce se oprava promítne do ODPOČTU, který byl
     *     uplatněn z daně uvedené dodavatelem, tedy ř. 40/41 v období, kdy byl odpočet
     *     uplatněn (proto `period_*` u přijatého dokladu = období odpočtu). Způsob odpočtu
     *     přebírá z hlavičky původního dokladu stejně jako
     *     {@see \MyInvoice\Service\Report\VatLedgerService} (normalize): poměrný § 75 se krátí
     *     procentem, krácený § 76 jde na ř. 40k/41k.
     *   - Přijatý doklad BEZ NÁROKU na odpočet: odpočet nebyl, oprava se do přiznání
     *     nepromítá (`dphdp3_line` = null + `warning`).
     *   - Přijatý doklad s PŘENESENÍM daně: příjemce daň přiznal sám (ř. 3–13 a zrcadlový
     *     odpočet), tuto opravu evidence neumí vyjádřit; registrace ji odmítá a starší
     *     záznam vrací `dphdp3_line` = null + `warning`.
     *
     * @param string $period 'monthly' (default) nebo 'quarterly'
     * @return list<array{id:int, source_type:string, source_id:int, rate_kind:string,
     *                    base_delta:float, vat_delta:float, corrective_doc_number:?string,
     *                    source_doc_number:string, source_tax_date:?string, counterparty_dic:string,
     *                    delivered_on:string, reason:string, side:string, dphdp3_line:?string,
     *                    base:float, vat:float, is_pomer:bool, warning:?string}>
     */
    public function periodCorrections(int $supplierId, int $year, int $month, string $period = 'monthly'): array
    {
        $months = $period === 'quarterly' ? self::quarterMonths($month) : [$month];
        $ph = implode(',', array_fill(0, count($months), '?'));
        $saleDic = \MyInvoice\Service\Report\VatLedgerService::saleCounterpartyDicExpr($this->db, 'i', 'ic');

        $stmt = $this->db->pdo()->prepare(
            "SELECT c.id, c.source_type, c.source_id, c.rate_kind, c.base_delta, c.vat_delta,
                    c.corrective_doc_number, c.delivered_on, c.reason,
                    i.varsymbol AS invoice_number, i.tax_date AS invoice_tax_date, {$saleDic} AS invoice_dic,
                    pi.vendor_invoice_number AS purchase_number, pi.tax_date AS purchase_tax_date,
                    pc.dic AS purchase_dic, pi.reverse_charge, pi.vat_deduction, pi.vat_deduction_percent
               FROM vat_s43_corrections c
          LEFT JOIN invoices i ON c.source_type = 'invoice' AND i.id = c.source_id
          LEFT JOIN clients ic ON ic.id = i.client_id
          LEFT JOIN purchase_invoices pi ON c.source_type = 'purchase_invoice' AND pi.id = c.source_id
          LEFT JOIN clients pc ON pc.id = pi.vendor_id
              WHERE c.supplier_id = ? AND c.period_year = ? AND c.period_month IN ({$ph})
           ORDER BY c.period_month, c.id"
        );
        $stmt->execute(array_merge([$supplierId, $year], $months));

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $kind = (string) $r['rate_kind'];
            if ($kind !== 'basic' && $kind !== 'reduced') {
                continue;
            }
            $isSale = $r['source_type'] === 'invoice';
            $baseDelta = round((float) $r['base_delta'], 2);
            $vatDelta = round((float) $r['vat_delta'], 2);
            $docNumber = (string) ($isSale ? $r['invoice_number'] : $r['purchase_number']);
            $taxDate = $isSale ? $r['invoice_tax_date'] : $r['purchase_tax_date'];
            $row = [
                'id'                    => (int) $r['id'],
                'source_type'           => (string) $r['source_type'],
                'source_id'             => (int) $r['source_id'],
                'rate_kind'             => $kind,
                'base_delta'            => $baseDelta,
                'vat_delta'             => $vatDelta,
                'corrective_doc_number' => $r['corrective_doc_number'] === null ? null : (string) $r['corrective_doc_number'],
                'source_doc_number'     => $docNumber,
                'source_tax_date'       => $taxDate !== null ? substr((string) $taxDate, 0, 10) : null,
                'counterparty_dic'      => (string) (($isSale ? $r['invoice_dic'] : $r['purchase_dic']) ?? ''),
                'delivered_on'          => (string) $r['delivered_on'],
                'reason'                => (string) $r['reason'],
                'side'                  => $isSale ? 'output' : 'deduction',
                'dphdp3_line'           => null,
                'base'                  => 0.0,
                'vat'                   => 0.0,
                'is_pomer'              => false,
                'warning'               => null,
            ];
            $label = $docNumber !== '' ? $docNumber : '#' . $row['source_id'];

            if ($isSale) {
                $row['dphdp3_line'] = $kind === 'basic' ? '1' : '2';
                $row['base'] = $baseDelta;
                $row['vat'] = $vatDelta;
            } elseif ((int) ($r['reverse_charge'] ?? 0) === 1) {
                $row['warning'] = "Oprava § 43 u přijatého dokladu {$label} s přenesením daně se do přiznání nepromítla: "
                    . 'samovyměřenou daň a zrcadlový odpočet je nutné opravit v dodatečném přiznání ručně.';
            } else {
                $deduction = (string) ($r['vat_deduction'] ?? 'full');
                $line = $kind === 'basic' ? '40' : '41';
                if ($deduction === 'none') {
                    $row['warning'] = "Oprava § 43 u přijatého dokladu {$label} se do přiznání nepromítá: "
                        . 'z dokladu nebyl uplatněn odpočet.';
                } elseif ($deduction === 'proportional') {
                    $ratio = max(0.0, min(100.0, (float) ($r['vat_deduction_percent'] ?? 100))) / 100.0;
                    $row['dphdp3_line'] = $line;
                    $row['base'] = round($baseDelta * $ratio, 2);
                    $row['vat'] = round($vatDelta * $ratio, 2);
                    $row['is_pomer'] = true;
                } else {
                    $row['dphdp3_line'] = $deduction === 'reduced' ? $line . 'k' : $line;
                    $row['base'] = $baseDelta;
                    $row['vat'] = $vatDelta;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Evidované opravy za období původního plnění — rozpis pro účetní.
     *
     * @return list<array<string,mixed>>
     */
    public function corrections(int $supplierId, int $year, ?int $month = null): array
    {
        $sql =
            'SELECT id, source_type, source_id, period_year, period_month, rate_kind,
                    base_delta, vat_delta, corrective_doc_number, delivered_on, reason
               FROM vat_s43_corrections
              WHERE supplier_id = ? AND period_year = ?';
        $params = [$supplierId, $year];
        if ($month !== null) {
            $sql .= ' AND period_month = ?';
            $params[] = $month;
        }
        $sql .= ' ORDER BY period_month, id';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn (array $r): array => [
            'id'                    => (int) $r['id'],
            'source_type'           => (string) $r['source_type'],
            'doc_type'              => (string) $r['source_type'],
            'doc_id'                => (int) $r['source_id'],
            'period_year'           => (int) $r['period_year'],
            'period_month'          => (int) $r['period_month'],
            'rate_kind'             => (string) $r['rate_kind'],
            'base_delta'            => round((float) $r['base_delta'], 2),
            'vat_delta'             => round((float) $r['vat_delta'], 2),
            'corrective_doc_number' => $r['corrective_doc_number'] === null ? null : (string) $r['corrective_doc_number'],
            'delivered_on'          => (string) $r['delivered_on'],
            'reason'                => (string) $r['reason'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Zaeviduje opravu výše daně. Vrací id záznamu.
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     * @param 'basic'|'reduced' $rateKind
     */
    public function register(
        int $supplierId,
        string $sourceType,
        int $sourceId,
        int $periodYear,
        int $periodMonth,
        string $rateKind,
        float $baseDelta,
        float $vatDelta,
        string $deliveredOn,
        string $reason,
        ?string $correctiveDocNumber = null,
        ?int $userId = null,
    ): int {
        if (!in_array($sourceType, ['invoice', 'purchase_invoice'], true)) {
            throw new \InvalidArgumentException('Zdrojem opravy je vydaná nebo přijatá faktura.');
        }
        $column = $sourceType === 'invoice' ? 'invoice_id' : 'purchase_invoice_id';
        $bad = (new \MyInvoice\Http\TenantReferenceGuard($this->db))->violations($supplierId, [$column => $sourceId], [$column]);
        if ($sourceId <= 0 || $bad !== []) {
            throw new \InvalidArgumentException('Zdroj opravy nenalezen.');
        }
        if ($sourceType === 'purchase_invoice') {
            // U přijatého dokladu se oprava promítá do odpočtu (viz periodCorrections()).
            // Přenesení daně a doklad bez nároku na odpočet na ř. 40/41 nepatří.
            $stmt = $this->db->pdo()->prepare(
                'SELECT reverse_charge, vat_deduction FROM purchase_invoices WHERE supplier_id = ? AND id = ?'
            );
            $stmt->execute([$supplierId, $sourceId]);
            $pi = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ((int) ($pi['reverse_charge'] ?? 0) === 1) {
                // Samovyměřená daň leží podle režimu dokladu na ř. 3–13 (pořízení zboží z JČS,
                // služba ze zahraničí, § 92a, dovoz) se zrcadlovým odpočtem na ř. 43/44 a v KH
                // v A.2/B.1 s kódem předmětu plnění. Záznam opravy nese jen sazbovou skupinu,
                // ne režim, takže řádek ani oddíl z něj jednoznačně určit nejde.
                throw new \InvalidArgumentException(
                    'U přijatého dokladu s přenesením daně opravuje příjemce samovyměřenou daň (ř. 3–13) '
                        . 'a zrcadlový odpočet (ř. 43/44). Evidence oprav § 43 nezná režim plnění, ze kterého '
                        . 'by řádek určila; opravu uveďte přímo v dodatečném přiznání a následném kontrolním hlášení.',
                    self::ERR_REVERSE_CHARGE,
                );
            }
            if (($pi['vat_deduction'] ?? 'full') === 'none') {
                throw new \InvalidArgumentException(
                    'Z přijatého dokladu nebyl uplatněn odpočet, oprava výše daně se u příjemce do přiznání nepromítá.'
                );
            }
        }
        if (!in_array($rateKind, ['basic', 'reduced'], true)) {
            throw new \InvalidArgumentException('Sazbová skupina je basic (ř. 1) nebo reduced (ř. 2).');
        }
        if ($periodMonth < 1 || $periodMonth > 12) {
            throw new \InvalidArgumentException('Měsíc původního plnění musí být 1–12.');
        }
        if ((int) round($vatDelta * 100) === 0) {
            // Nulová oprava není oprava — vznikla by prázdná položka, která by v rozpisu
            // budila dojem, že se něco opravovalo.
            throw new \InvalidArgumentException('Změna daně nesmí být nulová.');
        }
        if ($vatDelta > 0) {
            // § 43 odst. 1: opravu výše daně smí provést jen ten, kdo přiznal daň jinak, než
            // stanoví zákon, „a tím zvýšil daň na výstupu". Oprava tedy daň jen snižuje
            // (u přijaté faktury snižuje odpočet). Daň přiznaná v nižší částce se doplňuje
            // dodatečným přiznáním podle § 141 daňového řádu, ne opravným dokladem § 43.
            throw new \InvalidArgumentException(
                'Oprava výše daně podle § 43 ZDPH smí daň jen snížit (u přijaté faktury snížit odpočet). '
                    . 'Daň přiznanou v nižší částce, než stanoví zákon, doplňte dodatečným daňovým přiznáním '
                    . 'za období původního plnění podle § 141 daňového řádu.',
                self::ERR_TAX_INCREASE,
            );
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Důvod opravy je povinný — čím byla původní výše daně chybná.');
        }
        if ($this->isTimeBarred($periodYear, $periodMonth, $deliveredOn, $this->vatPeriodOf($supplierId))) {
            throw new \InvalidArgumentException(sprintf(
                'Opravu už provést nelze: lhůta pro stanovení daně za období %d/%02d uplynula '
                    . '(§ 43 odst. 3 ZDPH, § 148 DŘ).',
                $periodYear,
                $periodMonth,
            ));
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO vat_s43_corrections
                (supplier_id, source_type, source_id, period_year, period_month, rate_kind,
                 base_delta, vat_delta, corrective_doc_number, delivered_on, reason, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId, $sourceType, $sourceId, $periodYear, $periodMonth, $rateKind,
            round($baseDelta, 2), round($vatDelta, 2), $correctiveDocNumber, $deliveredOn, trim($reason), $userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function delete(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM vat_s43_corrections WHERE supplier_id = ? AND id = ?')
            ->execute([$supplierId, $id]);
    }

    /** Zdaňovací období plátce — rozhoduje o tom, kdy začíná běžet lhůta § 148 DŘ. */
    private function vatPeriodOf(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT vat_period FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $v = (string) ($stmt->fetchColumn() ?: 'monthly');

        return $v === 'quarterly' ? 'quarterly' : 'monthly';
    }

    /**
     * Uplynula lhůta pro stanovení daně?
     *
     * § 148 odst. 1 DŘ: lhůta počíná běžet dnem, kdy uplynula lhůta pro podání ŘÁDNÉHO
     * daňového tvrzení — u DPH 25 dnů po konci zdaňovacího období (§ 101 odst. 1 ZDPH).
     * NE od konce kalendářního roku.
     *
     * Dřív se počítalo `rok + 3` k 31. 12., což je u lednového plnění o 310 dnů POZDĚ
     * (leden 2021: podání 25. 2. 2021 → prekluze 25. 2. 2024, ale systém pouštěl opravu
     * ještě 31. 12. 2024) a u prosincového naopak asi o měsíc přísné.
     *
     * Zdaňovací období rozhoduje: u čtvrtletního plátce končí čtvrtletím, takže lhůta
     * běží později. Počítat u něj měsíčně by opravu zablokovalo dřív, než zákon velí.
     */
    public function isTimeBarred(int $periodYear, int $periodMonth, string $deliveredOn, string $vatPeriod = 'monthly'): bool
    {
        $periodEndMonth = $vatPeriod === 'quarterly'
            ? (int) (ceil($periodMonth / 3) * 3)
            : $periodMonth;

        $c = $this->taxConstants->forYear($periodYear);
        $assessmentYears = (int) ($c['assessment_period_years'] ?? self::ASSESSMENT_PERIOD_YEARS);

        $filingDeadline = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $periodYear, $periodEndMonth)))
            ->modify('last day of this month')
            ->modify('+25 days');
        $deadline = $filingDeadline->modify('+' . $assessmentYears . ' years');

        return $deliveredOn > $deadline->format('Y-m-d');
    }

    /** @return list<int> */
    private static function quarterMonths(int $month): array
    {
        $quarter = (int) ceil($month / 3);

        return [($quarter - 1) * 3 + 1, ($quarter - 1) * 3 + 2, $quarter * 3];
    }
}

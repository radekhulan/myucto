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

    public function __construct(
        private readonly Connection $db,
        private readonly TaxConstantsRepository $taxConstants,
    ) {}

    /**
     * Součty oprav pro řádky přiznání za období PŮVODNÍHO plnění.
     *
     * @return array{basic:array{base:float,vat:float}, reduced:array{base:float,vat:float}}
     */
    public function periodCorrectionLines(int $supplierId, int $year, int $month, string $period = 'monthly'): array
    {
        $months = $period === 'quarterly' ? self::quarterMonths($month) : [$month];
        $ph = implode(',', array_fill(0, count($months), '?'));

        $stmt = $this->db->pdo()->prepare(
            "SELECT rate_kind,
                    COALESCE(SUM(base_delta), 0) AS base_delta,
                    COALESCE(SUM(vat_delta), 0)  AS vat_delta
               FROM vat_s43_corrections
              WHERE supplier_id = ? AND period_year = ? AND period_month IN ({$ph})
           GROUP BY rate_kind"
        );
        $stmt->execute(array_merge([$supplierId, $year], $months));

        $out = ['basic' => ['base' => 0.0, 'vat' => 0.0], 'reduced' => ['base' => 0.0, 'vat' => 0.0]];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $kind = (string) $r['rate_kind'];
            if (!isset($out[$kind])) {
                continue;
            }
            $out[$kind] = [
                'base' => round((float) $r['base_delta'], 2),
                'vat'  => round((float) $r['vat_delta'], 2),
            ];
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

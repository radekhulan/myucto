<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Support\Sql\CzkAmountExpr;

/**
 * Nárok na čtvrtletní zdaňovací období — § 99 a § 99a ZDPH.
 *
 * `supplier.vat_period` byl ruční přepínač BEZ jakékoli kontroly nároku. Nesprávné
 * nastavení přitom neznamená jednu chybu, ale celoročně pozdě podávaná přiznání a
 * pokuty — plátce, který na čtvrtletní období nárok nemá, musí podávat měsíčně.
 *
 * ── Co se ověřit DÁ ────────────────────────────────────────────────────────────────
 * 1) Obrat za předcházející kalendářní rok proti limitu § 99a odst. 1. Počítá se stejnými
 *    pravidly jako obrat pro registraci (§ 4a): vystavené faktury, daňové doklady k přijaté
 *    platbě a dobropisy, přičemž dobropis obrat VŽDY snižuje. Koncepty, proformy a storna
 *    se nezapočítávají.
 * 2) Rok registrace (§ 99a odst. 3): od zavedení supplier_vat_status_history (EPIC VH-04)
 *    model datum registrace ZNÁ — je to poslední přechod 0→1 v historii před koncem
 *    zkoumaného období. V roce registrace ani v roce bezprostředně následujícím si plátce
 *    čtvrtletní období zvolit nemůže → tvrdý ne-nárok, ne jen warning. Firma bez přechodu
 *    v historii (odjakživa plátce, baseline 1900) žádné omezení z registrace nemá.
 *
 * ── Co se ověřit NEDÁ, a proto se o tom mlčet nesmí ────────────────────────────────
 * Nespolehlivý plátce a skupinová registrace — obojí je stav v registru MFČR, ne
 * v účetnictví, takže se na ně jen UPOZORŇUJE.
 *
 * Tvrdit „nárok máte" na základě jen ověřených podmínek by bylo horší než dnešní stav:
 * dnes uživatel ví, že si to musí ohlídat sám, kdežto falešně zelené hlášení by ho
 * uklidnilo.
 */
final class VatPeriodEntitlementService
{
    public function __construct(
        private readonly Connection $db,
        private readonly TaxConstantsRepository $constants,
    ) {}

    /**
     * Posouzení nároku pro dané zdaňovací období (rok).
     *
     * @return array{
     *   vat_period:?string, prior_year:int, prior_year_turnover:float, limit:float,
     *   over_limit:bool, ok:bool, warnings:list<string>
     * }
     */
    public function evaluate(int $supplierId, int $year): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT vat_period, is_vat_payer FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $period = $row['vat_period'] !== null ? (string) $row['vat_period'] : null;
        $isPayer = !empty($row['is_vat_payer']);
        $priorYear = $year - 1;
        $limit = (float) ($this->constants->forYear($year)['vat_quarterly_turnover_limit'] ?? 15000000);
        $turnover = $this->turnoverForYear($supplierId, $priorYear);
        $overLimit = $turnover > $limit;

        $warnings = [];
        $registrationBlocks = false;
        if ($isPayer && $period === 'quarterly') {
            if ($overLimit) {
                $warnings[] = sprintf(
                    'Obrat za rok %d byl %s Kč, tedy nad limitem %s Kč — na čtvrtletní zdaňovací období '
                        . 'nárok podle § 99a odst. 1 není a přiznání se musí podávat MĚSÍČNĚ. '
                        . 'Nastavení firmy je přitom čtvrtletní.',
                    $priorYear,
                    number_format($turnover, 0, ',', ' '),
                    number_format($limit, 0, ',', ' '),
                );
            }
            // § 99a odst. 3 — poslední registrace k DPH z historie plátcovství (EPIC VH-04):
            // v roce registrace a bezprostředně následujícím roce kvartál zvolit NELZE.
            $registrationDate = $this->lastRegistrationDate($supplierId, sprintf('%04d-12-31', $year));
            if ($registrationDate !== null) {
                $regYear = (int) substr($registrationDate, 0, 4);
                if ($year <= $regYear + 1) {
                    $registrationBlocks = true;
                    $warnings[] = sprintf(
                        'Firma se stala plátcem DPH k %s — v roce registrace (%d) ani v roce bezprostředně '
                            . 'následujícím (%d) si čtvrtletní zdaňovací období zvolit nelze (§ 99a odst. 3) '
                            . 'a přiznání se musí podávat MĚSÍČNĚ. Nastavení firmy je přitom čtvrtletní.',
                        (new \DateTimeImmutable($registrationDate))->format('j. n. Y'),
                        $regYear,
                        $regYear + 1,
                    );
                }
            }
            // Hlásí se VŽDY, i když obrat i rok registrace sedí — jsou to podmínky, které
            // systém ověřit nemůže, a mlčení by vypadalo jako potvrzení nároku.
            $warnings[] = 'Systém neověřuje zbývající podmínky § 99a: nespolehlivého plátce ani '
                . 'skupinovou registraci. Ověřte je ručně.';
        }

        return [
            'vat_period'          => $period,
            'prior_year'          => $priorYear,
            'prior_year_turnover' => round($turnover, 2),
            'limit'               => $limit,
            'over_limit'          => $overLimit,
            // `ok` je pouze o ověřitelných podmínkách — viz docblock.
            'ok'                  => !($isPayer && $period === 'quarterly' && ($overLimit || $registrationBlocks)),
            'warnings'            => $warnings,
        ];
    }

    /**
     * Datum POSLEDNÍ registrace k DPH = poslední přechod 0→1 v supplier_vat_status_history
     * před $before (koncem zkoumaného období). Vyžaduje EXPLICITNÍ předchozí neplátcovský
     * řádek — firma, jejíž historie začíná plátcovstvím (baseline 1900, „odjakživa plátce"),
     * žádné datum registrace nemá a § 99a odst. 3 ji neomezuje.
     */
    private function lastRegistrationDate(int $supplierId, string $before): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT effective_from, is_vat_payer FROM supplier_vat_status_history
              WHERE supplier_id = ? AND effective_from <= ?
              ORDER BY effective_from, id'
        );
        $stmt->execute([$supplierId, $before]);

        $prev = null;
        $registration = null;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $isPayer = (bool) $r['is_vat_payer'];
            if ($isPayer && $prev === false) {
                $registration = (string) $r['effective_from'];
            }
            $prev = $isPayer;
        }

        return $registration;
    }

    /**
     * Obrat za kalendářní rok podle § 4a. Dobropis obrat VŽDY snižuje — u dobropisu
     * chybně zadaného s kladnou částkou by se jinak obrat navýšil, a právě obrat tady
     * rozhoduje o povinnosti podávat měsíčně.
     */
    private function turnoverForYear(int $supplierId, int $year): float
    {
        $netCzk = CzkAmountExpr::issuedRevenue(isVatPayer: true);
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN i.invoice_type = 'credit_note' THEN -ABS({$netCzk}) ELSE {$netCzk} END), 0)
               FROM invoices i
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.effective_tax_date >= ? AND i.effective_tax_date < ?
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.invoice_type IN ('invoice', 'credit_note', 'tax_document')"
        );
        $stmt->execute([$supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)]);

        return (float) $stmt->fetchColumn();
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use PDO;

/**
 * § 6 a § 4a ZDPH — vznik plátcovství z obratu.
 *
 * Systém obrat i oba limity znal, ale nevyvodil z nich DŮSLEDEK: nikde se nepočítalo ani
 * nezobrazovalo, k jakému DNI se osoba povinná k dani stává plátcem. Přitom plátcovství
 * vzniká ZE ZÁKONA, ne přihláškou — kdo si toho nevšimne, neodvádí daň z plnění, ze
 * kterých ji odvádět měl, a doměrek jde zpětně od data vzniku.
 *
 * ── Dva stupně, dva různé následky ──────────────────────────────────────────
 *   obrat > 2 000 000 Kč    → plátcem od 1. LEDNA následujícího kalendářního roku
 *   obrat > 2 536 500 Kč    → plátcem DNEM NÁSLEDUJÍCÍM po dni překročení
 *
 * Rozdíl je zásadní a je to celý smysl téhle služby: první případ dává čas do ledna,
 * druhý znamená „ode zítřka vystavuj s daní". Zobrazit jen číslo obratu vedle limitu
 * (co dělal dashboard) tuhle informaci nenese.
 *
 * ── Proč se počítá kalendářní rok, ne klouzavých 12 měsíců ──────────────────
 * Do konce 2024 se sledovalo 12 bezprostředně předcházejících měsíců; od 1. 1. 2025 je
 * rozhodný KALENDÁŘNÍ ROK. Starší mechanismus se tu vědomě NEMODELUJE — má jinou lhůtu
 * i jiné datum vzniku a dopočítávat ho zpětně by znamenalo tvrdit datum podle pravidla,
 * které v daném roce neplatilo. Ročníky před 2025 proto vrací `applicable = false`;
 * poznají se podle toho, že mají oba limity shodné.
 *
 * Read-only: nic neúčtuje ani nemění stav plátcovství — to je rozhodnutí a úkon
 * uživatele vůči správci daně.
 */
final class VatRegistrationService
{
    public function __construct(
        private readonly Connection $db,
        private readonly TaxConstantsRepository $constants,
    ) {}

    /**
     * @return array{
     *   applicable:bool, year:int, turnover:float, limit_low:float, limit_high:float,
     *   status:string, crossed_on:?string, becomes_payer_on:?string,
     *   is_vat_payer:bool, basis:?string
     * }
     */
    public function evaluate(int $supplierId, int $year): array
    {
        $c = $this->constants->forYear($year);
        $low = (float) ($c['vat_limit_low'] ?? 0);
        $high = (float) ($c['vat_limit_high'] ?? $low);

        $stmt = $this->db->pdo()->prepare('SELECT is_vat_payer FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $isPayer = (bool) $stmt->fetchColumn();

        $turnover = $this->turnoverForYear($supplierId, $year);

        $base = [
            'applicable'       => true,
            'year'             => $year,
            'turnover'         => round($turnover, 2),
            'limit_low'        => $low,
            'limit_high'       => $high,
            'status'           => 'below',
            'crossed_on'       => null,
            'crossed_low_on'   => null,
            'becomes_payer_on' => null,
            'is_vat_payer'     => $isPayer,
            'basis'            => null,
        ];

        // Ročníky se shodnými limity běží na starém mechanismu klouzavých 12 měsíců,
        // který se tu nemodeluje — viz docblock.
        //
        // `status` se přitom NESMÍ nechat na inicializační hodnotě `below`: u obratu
        // 7 383 230,20 Kč proti limitu 2 000 000 Kč by konzument, který si nepřečte
        // `applicable`, dostal slovo „pod limitem". To je návnada na chybu.
        if ($high <= $low) {
            return array_merge($base, ['applicable' => false, 'status' => 'not_applicable']);
        }

        // Firma, která JIŽ plátcem je, se plátcem znovu nestává. Datum vzniku plátcovství
        // se dosud počítalo a vydávalo i pro ni, takže služba tvrdila „stáváš se plátcem
        // 3. 5. 2025" firmě, která je plátcem od svého vzniku. Obrat a limity zůstávají —
        // ty smysl dávají pořád (například pro § 99a).
        if ($isPayer) {
            return array_merge($base, ['status' => 'already_payer']);
        }

        if ($turnover > $high) {
            $crossedOn = $this->dayTurnoverCrossed($supplierId, $year, $high);

            return array_merge($base, [
                'status'           => 'exceeded_high',
                'crossed_on'       => $crossedOn,
                // Registrační povinnost (§ 94/1) běží už od překročení DOLNÍHO limitu —
                // kotva lhůty je den překročení 2 000 000 Kč, ne 2 536 500 Kč.
                'crossed_low_on'   => $this->dayTurnoverCrossed($supplierId, $year, $low),
                // § 6 odst. 2 — plátcem DNEM NÁSLEDUJÍCÍM po dni překročení.
                'becomes_payer_on' => $crossedOn === null
                    ? null
                    : (new \DateTimeImmutable($crossedOn))->modify('+1 day')->format('Y-m-d'),
                'basis'            => 'vat_registration_immediate',
            ]);
        }

        if ($turnover > $low) {
            return array_merge($base, [
                'status'           => 'exceeded_low',
                'crossed_low_on'   => $this->dayTurnoverCrossed($supplierId, $year, $low),
                // § 6 odst. 1 — plátcem od 1. ledna NÁSLEDUJÍCÍHO kalendářního roku.
                'becomes_payer_on' => sprintf('%04d-01-01', $year + 1),
                'basis'            => 'vat_registration_next_year',
            ]);
        }

        return $base;
    }

    /**
     * Termín podání přihlášky k registraci (§ 94 odst. 1 ZDPH, znění od 1. 1. 2025):
     * do 10 PRACOVNÍCH dnů ode dne překročení obratu 2 000 000 Kč (registrační
     * povinnost zakládá už DOLNÍ limit; překročení 2 536 500 Kč mění jen den
     * vzniku plátcovství, ne kotvu lhůty). Den překročení se nepočítá, lhůta
     * končí desátým pracovním dnem po něm (svátky dle 245/2000 Sb.).
     *
     * Bez známého dne překročení (chybí denní data obratu) se vrací INFORMATIVNÍ
     * termín `becomes_payer_on` s basis `informative`, aby konzument rozdíl uměl
     * říct nahlas.
     *
     * @param ?string $crossedLowOn den překročení 2 000 000 Kč ({@see evaluate} — `crossed_low_on`)
     * @return array{deadline:string, basis:'statutory'|'informative'}|null
     */
    public function applicationDeadline(?string $crossedLowOn, ?string $becomesPayerOn): ?array
    {
        if ($crossedLowOn !== null) {
            $d = new \DateTimeImmutable($crossedLowOn);
            $workingDays = (int) ($this->constants->forYear((int) $d->format('Y'))['vat_registration_application_deadline_working_days']
                ?? 10);
            for ($i = 0; $i < $workingDays; $i++) {
                $d = CzechWorkingDays::shiftToWorkingDay($d->modify('+1 day'));
            }

            return ['deadline' => $d->format('Y-m-d'), 'basis' => 'statutory'];
        }
        if ($becomesPayerOn !== null) {
            return ['deadline' => $becomesPayerOn, 'basis' => 'informative'];
        }

        return null;
    }

    /**
     * Den, ve kterém kumulovaný obrat překročil limit.
     *
     * Kumuluje se v pořadí rozhodného dne a bere se PRVNÍ den, kdy součet limit přesáhl.
     * Dobropis obrat snižuje, takže se překročení může „odčinit" — pak se datum posune na
     * pozdější den, kdy k překročení došlo znovu. Tady to jde přes celý rok v PHP, protože
     * kumulativní součet v SQL by musel řešit i to odčinění a byl by hůř ověřitelný.
     */
    private function dayTurnoverCrossed(int $supplierId, int $year, float $limit): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(i.effective_tax_date, i.tax_date, i.issue_date) AS d,
                    SUM(CASE WHEN i.invoice_type = 'credit_note'
                             THEN -ABS(i.total_without_vat)
                             ELSE i.total_without_vat END
                        * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)) AS amount
               FROM invoices i
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND YEAR(COALESCE(i.effective_tax_date, i.tax_date, i.issue_date)) = ?
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
           GROUP BY d
           ORDER BY d"
        );
        $stmt->execute([$supplierId, $year]);

        $running = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $running += (float) $row['amount'];
            if ($running > $limit) {
                return (string) $row['d'];
            }
        }

        return null;
    }

    /**
     * Obrat za kalendářní rok podle § 4a. Dobropis obrat VŽDY snižuje — u dobropisu
     * chybně zadaného s kladnou částkou by se jinak obrat navýšil, a právě obrat tady
     * rozhoduje o vzniku plátcovství. Shodná logika jako
     * {@see VatPeriodEntitlementService::turnoverForYear()}.
     */
    private function turnoverForYear(int $supplierId, int $year): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(
                        CASE WHEN i.invoice_type = 'credit_note'
                             THEN -ABS(i.total_without_vat)
                             ELSE i.total_without_vat END
                        * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)
                    ), 0)
               FROM invoices i
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND YEAR(COALESCE(i.effective_tax_date, i.tax_date, i.issue_date)) = ?
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.invoice_type IN ('invoice', 'credit_note', 'tax_document')"
        );
        $stmt->execute([$supplierId, $year]);

        return (float) $stmt->fetchColumn();
    }
}

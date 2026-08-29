<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Kolik toho na jednom pracovním vztahu (resp. na osobě za ním) visí
 * v navazujících mzdových agendách.
 *
 * Why: karta zaměstnance na agendy dosud vůbec neodkazovala a nedalo se z ní
 * poznat, jestli v nich něco je. Poskládat souhrn z existujících endpointů by
 * znamenalo deset požadavků na JEDNU rozbalenou kartu a tři z nich (docházka,
 * rychlé vstupy, dokumenty) vracejí celý měsíc za celou firmu — tedy objem,
 * který s jedním člověkem nemá nic společného. Proto jeden agregační dotaz na
 * agendu místo jednoho HTTP volání na agendu.
 *
 * Souhrn je ZÁMĚRNĚ jen počet, datum a částka: karta z něj staví rozcestník,
 * ne druhý výpis agendy. Kdo chce položky, jde tlačítkem do agendy samotné.
 */
final class PayrollEmploymentAgendaSummaryRepository
{
    /** Agenda visí buď na pracovním vztahu, nebo na osobě (víc vztahů jedné osoby). */
    public const SCOPE_EMPLOYMENT = 'employment';
    public const SCOPE_EMPLOYEE = 'employee';

    /**
     * Doména klíčů agend — SSOT pro klientský union `PayrollAgendaKey`
     * (`web/src/api/payroll.ts`) i pro pořadí, v jakém se agendy vypisují.
     *
     * Musí se krýt s klíči {@see self::AGENDAS}; drift hlídá test
     * `PayrollEmploymentAgendaSummaryApiTest::testAgendaCatalogMatchesItsDomain()`.
     * Konstanta existuje proto, že architekturní kontrakt unionů umí porovnávat
     * jen ploché seznamy řetězců, ne mapu s SQL.
     *
     * ⚠️ Pořadí je PRODUKTOVÉ rozhodnutí, ne pořadí vzniku tabulek, a musí se
     * krýt s katalogem `web/src/pages/payroll/payrollAgendaLinks.ts` — hlídá
     * `PayrollEnumContractTest::testAgendaCatalogOrderMatchesTheClientCatalog()`.
     * Řadí se podle toho, jak často k agendě účetní chodí:
     *   1) měsíční rutina (nepřítomnosti, docházka, mzdové vstupy),
     *   2) osobní evidence, která rozhoduje o SPRÁVNOSTI výpočtu — chybějící
     *      prohlášení k dani nebo nezadané dítě se neprojeví jako chybějící
     *      záznam, ale jako špatně spočítaná mzda, takže patří na oči nahoru,
     *   3) občasné agendy (složky, cesty, průměry, srážky, exekuce, insolvence),
     *   4) výstupy na konec (dokumenty, roční zúčtování).
     */
    public const AGENDA_KEYS = [
        'absences',
        'time',
        'quick_inputs',
        'statutory_evidence',
        'dependants',
        'components',
        'travel',
        'average_earnings',
        'deduction_agreements',
        'enforcement',
        'insolvency',
        'documents',
        'annual_settlement',
    ];

    /**
     * DEFINICE agend: kde se který počet bere.
     *
     * Pořadí zápisů tady je jen pořadí, v jakém dotazy vznikly — na výstupu se
     * NEPROJEVÍ. Řadí {@see self::AGENDA_KEYS}, protože dvě ručně udržovaná
     * pořadí v jednom souboru se dřív nebo později rozejdou a rozcestník by pak
     * u každého člověka vypadal jinak, než katalog na klientovi slibuje.
     * Zdejší klíče a `AGENDA_KEYS` proto musí být stejná MNOŽINA (hlídá test),
     * ale nemusí být stejný SEZNAM.
     *
     * `permission` musí sedět na `routePermissions` ve `web/src/router/index.ts` —
     * jinak by souhrn prozradil počet exekucí někomu, koho stránka exekucí
     * nepustí dovnitř.
     *
     * `pairs` = kolikrát se v dotazu opakuje dvojice (supplier_id, id rozsahu).
     * Poziční parametry se vážou v pořadí, v jakém stojí v SQL.
     *
     * @var array<string,array{scope:string,permission:string,pairs:int,sql:string}>
     */
    private const AGENDAS = [
        // Docházka a směny jsou jedna stránka (`/payroll/time`), takže i jeden
        // řádek souhrnu — rozdělit je na dva by z rozcestníku udělalo výpis.
        'time' => [
            'scope' => self::SCOPE_EMPLOYMENT,
            'permission' => 'payroll',
            'pairs' => 2,
            'sql' => <<<'SQL'
                SELECT COUNT(*) AS record_count,
                       MAX(occurred_on) AS last_on,
                       NULL AS amount_minor
                  FROM (
                       SELECT DATE(starts_at_utc) AS occurred_on
                         FROM payroll_time_entries
                        WHERE supplier_id = ? AND employment_id = ?
                        UNION ALL
                       SELECT DATE(starts_at_utc)
                         FROM payroll_shifts
                        WHERE supplier_id = ? AND employment_id = ?
                       ) AS combined
                SQL,
        ],
        // Zrušená a zamítnutá nepřítomnost není nic, co by se dalo „mít" —
        // stejné pravidlo drží karty zaměstnanců na přehledu mezd.
        'absences' => [
            'scope' => self::SCOPE_EMPLOYMENT,
            'permission' => 'payroll',
            'pairs' => 1,
            'sql' => <<<'SQL'
                SELECT COUNT(*) AS record_count,
                       MAX(date_to) AS last_on,
                       NULL AS amount_minor
                  FROM payroll_absences
                 WHERE supplier_id = ? AND employment_id = ?
                   AND status IN ('requested', 'approved')
                SQL,
        ],
        'quick_inputs' => [
            'scope' => self::SCOPE_EMPLOYMENT,
            'permission' => 'payroll',
            'pairs' => 1,
            'sql' => <<<'SQL'
                SELECT COUNT(*) AS record_count,
                       MAX(period_start) AS last_on,
                       COALESCE(SUM(amount_minor), 0) AS amount_minor
                  FROM payroll_inputs
                 WHERE supplier_id = ? AND employment_id = ? AND status <> 'cancelled'
                SQL,
        ],
        'travel' => [
            'scope' => self::SCOPE_EMPLOYMENT,
            'permission' => 'payroll',
            'pairs' => 1,
            'sql' => <<<'SQL'
                SELECT COUNT(*) AS record_count,
                       -- Datum z UTC instantu, stejně jako `DATE(starts_at_utc)`
                       -- u směn výše: rozcestník ukazuje orientační „poslední
                       -- záznam", nic nepočítá. U odjezdu krátce po půlnoci
                       -- proto může ukázat předchozí den — vědomě, protože
                       -- CONVERT_TZ tu vrací NULL (nenačtené tabulky zón).
                       MAX(DATE(departure_at_utc)) AS last_on,
                       COALESCE(SUM(entitlement_total_minor), 0) AS amount_minor
                  FROM payroll_business_trips
                 WHERE supplier_id = ? AND employment_id = ? AND status <> 'cancelled'
                SQL,
        ],
        // Jen účinné předpisy. Ukončený opakovaný příplatek je historie, kterou
        // rozcestník neřeší — a „3 opakované složky", z nichž dvě neplatí, by
        // byla horší informace než žádná.
        'components' => [
            'scope' => self::SCOPE_EMPLOYMENT,
            'permission' => 'payroll',
            'pairs' => 1,
            'sql' => <<<'SQL'
                SELECT COUNT(*) AS record_count,
                       MAX(valid_from) AS last_on,
                       COALESCE(SUM(amount_minor), 0) AS amount_minor
                  FROM payroll_recurring_components
                 WHERE supplier_id = ? AND employment_id = ? AND is_active = 1
                SQL,
        ],
        // Průměrný výdělek nemá vlastní routu — bydlí jako záložka nepřítomností
        // (`/payroll/absences?tab=averages`), protože se z něj počítá náhrada.
        // Částka je hodinový průměr POSLEDNÍHO schváleného snímku, ne součet.
        'average_earnings' => [
            'scope' => self::SCOPE_EMPLOYMENT,
            'permission' => 'payroll',
            'pairs' => 2,
            'sql' => <<<'SQL'
                SELECT COUNT(*) AS record_count,
                       MAX(decisive_to) AS last_on,
                       (SELECT s.average_hourly_minor
                          FROM payroll_average_earning_snapshots s
                         WHERE s.supplier_id = ? AND s.employment_id = ?
                           AND s.status = 'approved'
                         ORDER BY s.applicable_year DESC, s.applicable_quarter DESC,
                                  s.revision_no DESC
                         LIMIT 1) AS amount_minor
                  FROM payroll_average_earning_snapshots
                 WHERE supplier_id = ? AND employment_id = ? AND status <> 'superseded'
                SQL,
        ],
        'deduction_agreements' => [
            'scope' => self::SCOPE_EMPLOYEE,
            'permission' => 'payroll',
            'pairs' => 1,
            'sql' => <<<'SQL'
                SELECT COUNT(*) AS record_count,
                       MAX(valid_from) AS last_on,
                       COALESCE(SUM(requested_minor), 0) AS amount_minor
                  FROM payroll_deduction_agreements
                 WHERE supplier_id = ? AND employee_id = ? AND status <> 'cancelled'
                SQL,
        ],
        'enforcement' => [
            'scope' => self::SCOPE_EMPLOYEE,
            'permission' => 'payroll.enforcement',
            'pairs' => 1,
            'sql' => <<<'SQL'
                SELECT COUNT(*) AS record_count,
                       MAX(effective_from) AS last_on,
                       NULL AS amount_minor
                  FROM payroll_enforcement_cases
                 WHERE supplier_id = ? AND employee_id = ?
                SQL,
        ],
        'documents' => [
            'scope' => self::SCOPE_EMPLOYEE,
            'permission' => 'payroll.documents',
            'pairs' => 1,
            'sql' => <<<'SQL'
                SELECT COUNT(*) AS record_count,
                       MAX(DATE(created_at)) AS last_on,
                       NULL AS amount_minor
                  FROM payroll_generated_documents
                 WHERE supplier_id = ? AND employee_id = ?
                SQL,
        ],
        // Částka je rozdíl z POSLEDNÍHO provedeného zúčtování (přeplatek kladně),
        // ne součet přes roky — sečíst přeplatky za pět let nic neříká.
        'annual_settlement' => [
            'scope' => self::SCOPE_EMPLOYEE,
            'permission' => 'payroll.documents',
            'pairs' => 2,
            'sql' => <<<'SQL'
                SELECT COUNT(*) AS record_count,
                       MAX(requested_on) AS last_on,
                       (SELECT o.settlement_difference_minor
                          FROM payroll_annual_settlement_outcomes o
                         WHERE o.supplier_id = ? AND o.employee_id = ?
                         ORDER BY o.tax_year DESC, o.id DESC
                         LIMIT 1) AS amount_minor
                  FROM payroll_annual_settlement_requests
                 WHERE supplier_id = ? AND employee_id = ?
                SQL,
        ],
        /*
         * Insolvence NENÍ vlastní tabulka případů — oddlužení se vede jako
         * měsíční evidence osoby (`payroll_enforcement_person_month_evidence`),
         * kde ho zapíná `insolvency_mode`. Počítají se proto MĚSÍCE, ve kterých
         * je insolvence vedená; `none` je „nic tam není", ne řádek navíc.
         *
         * Částka zůstává prázdná záměrně: soudem určená srážka existuje jen
         * u režimu `court_determined_amount`, takže by u standardního oddlužení
         * i u pouhého upozornění musela lhát nulou.
         */
        'insolvency' => [
            'scope' => self::SCOPE_EMPLOYEE,
            'permission' => 'payroll.insolvency',
            'pairs' => 1,
            'sql' => <<<'SQL'
                SELECT COUNT(*) AS record_count,
                       MAX(period_start) AS last_on,
                       NULL AS amount_minor
                  FROM payroll_enforcement_person_month_evidence
                 WHERE supplier_id = ? AND employee_id = ?
                   AND insolvency_mode <> 'none'
                SQL,
        ],
        /*
         * Zákonná evidence osoby je ŠEST kolekcí v šesti tabulkách (prohlášení
         * k dani, daňová rezidence, sociální příslušnost, sleva na pojistném,
         * zdravotní pojišťovna, měsíční zdravotní evidence) — právě ty, do
         * kterých vede zapisovací cesta z karty osoby
         * ({@see PayrollPersonStatutoryEvidenceRepository::EDITABLE}).
         *
         * Rozcestník je nesčítá proto, že by šlo o „jednu agendu", ale proto, že
         * v UI JSOU jeden panel: účetní se ptá „mám u toho člověka vůbec něco
         * doložené?", ne „kolik mám řádků v tabulce rezidencí". Jeden UNION
         * místo šesti dotazů drží slib, že souhrn je jeden požadavek.
         */
        'statutory_evidence' => [
            'scope' => self::SCOPE_EMPLOYEE,
            'permission' => 'payroll',
            'pairs' => 6,
            'sql' => <<<'SQL'
                SELECT COUNT(*) AS record_count,
                       MAX(occurred_on) AS last_on,
                       NULL AS amount_minor
                  FROM (
                       SELECT effective_from AS occurred_on
                         FROM payroll_person_tax_declarations
                        WHERE supplier_id = ? AND employee_id = ?
                        UNION ALL
                       SELECT effective_from
                         FROM payroll_person_tax_residences
                        WHERE supplier_id = ? AND employee_id = ?
                        UNION ALL
                       SELECT effective_from
                         FROM payroll_person_social_jurisdictions
                        WHERE supplier_id = ? AND employee_id = ?
                        UNION ALL
                       SELECT effective_from
                         FROM payroll_person_social_discount_claims
                        WHERE supplier_id = ? AND employee_id = ?
                        UNION ALL
                       SELECT effective_from
                         FROM payroll_person_health_coverage_history
                        WHERE supplier_id = ? AND employee_id = ?
                        UNION ALL
                       SELECT period_start
                         FROM payroll_person_health_month_evidence
                        WHERE supplier_id = ? AND employee_id = ?
                       ) AS combined
                SQL,
        ],
        // `last_on` je NEJPOZDĚJŠÍ začátek vyživování, ne datum pořízení řádku:
        // rozcestník tím odpovídá na „kdy jsme naposledy někoho přidali", což je
        // to, co účetní na kartě hledá. Ukončené osoby se nevyřazují — nárok se
        // dokládá i zpětně a „0 vyživovaných" u člověka s dospělým dítětem by
        // vypadalo jako chybějící evidence.
        'dependants' => [
            'scope' => self::SCOPE_EMPLOYEE,
            'permission' => 'payroll',
            'pairs' => 1,
            'sql' => <<<'SQL'
                SELECT COUNT(*) AS record_count,
                       MAX(existence_from) AS last_on,
                       NULL AS amount_minor
                  FROM payroll_dependants
                 WHERE supplier_id = ? AND employee_id = ?
                SQL,
        ],
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * Agendy v pořadí, v jakém je karta vypisuje — ne v pořadí, v jakém k nim
     * v {@see self::AGENDAS} vznikl dotaz.
     *
     * @return list<string>
     */
    public static function agendaKeys(): array
    {
        return self::AGENDA_KEYS;
    }

    /**
     * Klíče, ke kterým existuje dotaz. Jen pro test, že se doména a definice
     * nerozešly — pořadí je tu nahodilé, řadit se má podle {@see agendaKeys()}.
     *
     * @return list<string>
     */
    public static function definedAgendaKeys(): array
    {
        return array_keys(self::AGENDAS);
    }

    public static function permissionOf(string $agenda): string
    {
        return self::AGENDAS[$agenda]['permission']
            ?? throw new \InvalidArgumentException("Neznámá mzdová agenda „{$agenda}“.");
    }

    /**
     * Pracovní vztah v rámci firmy. `null` = neexistuje, nebo patří jinému
     * dodavateli — volající to musí odlišit od „vztah bez záznamů".
     *
     * @return array{id:int,employee_id:int}|null
     */
    public function findEmployment(int $supplierId, int $employmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employee_id FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : ['id' => (int) $row['id'], 'employee_id' => (int) $row['employee_id']];
    }

    /**
     * Souhrn jedné agendy. Prázdná agenda vrací nulu, ne `null` — rozdíl mezi
     * „nic tam není" a „nezeptali jsme se" drží až volající tím, které agendy
     * do seznamu pustí.
     *
     * @param list<string> $agendas
     * @return list<array{key:string,count:int,last_on:string|null,amount_minor:int|null}>
     */
    public function summary(int $supplierId, int $employmentId, int $employeeId, array $agendas): array
    {
        $result = [];
        // Pořadí odpovědi drží AGENDA_KEYS (pořadí výpisu), ne pořadí definic.
        foreach (self::AGENDA_KEYS as $key) {
            if (!in_array($key, $agendas, true)) continue;
            $agenda = self::AGENDAS[$key];

            $scopeId = $agenda['scope'] === self::SCOPE_EMPLOYEE ? $employeeId : $employmentId;
            $params = [];
            for ($i = 0; $i < $agenda['pairs']; $i++) {
                $params[] = $supplierId;
                $params[] = $scopeId;
            }

            $stmt = $this->db->pdo()->prepare($agenda['sql']);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = $row === false ? 0 : (int) $row['record_count'];
            $amount = $row === false ? null : $row['amount_minor'];

            $result[] = [
                'key' => $key,
                'count' => $count,
                'last_on' => $row === false || $row['last_on'] === null
                    ? null
                    : (string) $row['last_on'],
                // Nula bez záznamů není částka, je to prázdno — COALESCE v SQL
                // slouží jen k tomu, aby SUM() nevracel NULL uprostřed součtu.
                'amount_minor' => $amount === null || $count === 0 ? null : (int) $amount,
            ];
        }

        return $result;
    }
}

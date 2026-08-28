<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Invarianty mzdového jádra — vrstva L3, mzdová obdoba {@see \MyInvoice\Service\Accounting\LedgerInvariantService}.
 *
 * Účetnictví tuhle vrstvu má od auditu F6, mzdy ji do W25 neměly vůbec — přestože
 * mají přes 250 migrací, několik backfillů a v ostrých datech i ruční zásahy. Právě
 * tyhle cesty scénářový test nevidí: ověřuje průchod, který si sám vymyslel, kdežto
 * invariant měří DATABÁZI TAK, JAK JE, a chytí i to, co do ní dostala cesta, na
 * kterou nikdo nemyslel.
 *
 * Služba je striktně READ-ONLY (samé SELECTy, žádná transakce, žádný zápis), aby
 * ji šlo bez rizika pustit i proti produkční databázi. Konzumenti:
 *   - `api/bin/check-payroll-invariants.php` (CLI / cron / CI brána),
 *   - `tests/Invariants/PayrollInvariantsTest`.
 *
 * Každý invariant je formulovaný tak, aby na legitimních datech NEMOHL svítit
 * červeně. Kde modul připouští víc variant (zápočet na účet společníka snižuje
 * vyplacenou částku, záporná čistá mzda závazek vůbec nevytvoří), je tvrzení
 * záměrně nerovnost, ne rovnost — guard, který hlásí falešný poplach, se přestane
 * číst a je pak stejně bezcenný jako ten, který mlčí vždycky.
 */
final class PayrollInvariantService
{
    /** Kolik porušení se u jednoho invariantu vypíše, než se výpis usekne. */
    private const SAMPLE_LIMIT = 50;

    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array{code:string, rule:string, source:string, checked:bool, violations:list<string>, skipped_reason:?string}>
     */
    public function checkAll(): array
    {
        return [
            $this->m1PostingBatchIsBalanced(),
            $this->m2NetWageLiabilitiesDoNotExceedResult(),
            $this->m3RevisionChainIsIntact(),
            $this->m4EachRunHasSingleCurrentApprovedRevision(),
            $this->m5DeductionAgreementBalanceMatchesLedger(),
            $this->m6SurchargeCumulativeMatchesIncrements(),
            $this->m7AppendOnlySequenceMatchesInsertOrder(),
            $this->m8FrozenSnapshotMatchesItsHash(),
        ];
    }

    /**
     * Modul je v databázi a je v něm co měřit.
     *
     * Bez tohohle rozlišení by prázdná testovací databáze vydala samé zelené
     * výsledky a tvrdila by, že hlídá něco, co hlídané není.
     */
    public function payrollIsEmpty(): bool
    {
        if (!$this->hasTable('payroll_run_revisions')) {
            return true;
        }

        return (int) $this->pdo()
            ->query('SELECT COUNT(*) FROM payroll_run_revisions')
            ->fetchColumn() === 0;
    }

    /**
     * M1 — účetní dávka mzdy je vyvážená (Σ MD = Σ D).
     *
     * `payroll_posting_allocations.signed_minor` nese stranu ve znaménku, takže
     * podvojnost se čte jako „součet přes dávku je nula". Dávka se do deníku
     * překlápí jedním zápisem (`journal_entries.source_type = 'payroll'`), takže
     * nevyvážená dávka znamená nevyvážený účetní zápis — § 3 odst. 1 ZoÚ.
     *
     * Vazba na existující revizi se sem needuplikuje: drží ji trojsloupcový FK
     * `fk_payroll_posting_batch_revision` z migrace 1262, a invariant, který jen
     * opisuje cizí klíč, nikdy nic nenajde.
     */
    private function m1PostingBatchIsBalanced(): array
    {
        $rule = 'účetní dávka mzdy je vyvážená (Σ MD = Σ D)';
        if (!$this->hasTable('payroll_posting_allocations')) {
            return $this->skipped('M1', $rule, '§ 3 odst. 1 ZoÚ', 'payroll_posting_allocations v DB není');
        }

        return $this->run('M1', $rule, '§ 3 odst. 1 ZoÚ',
            "SELECT CONCAT('mzdová účetní dávka #', batch_id, ' (firma ', supplier_id,
                           ') není vyvážená: rozdíl ', SUM(signed_minor), ' hal.') AS violation
               FROM payroll_posting_allocations
              GROUP BY supplier_id, batch_id
             HAVING SUM(signed_minor) <> 0
              LIMIT " . self::SAMPLE_LIMIT);
    }

    /**
     * M2 — materializované platební závazky čisté mzdy nepřevýší výsledek revize.
     *
     * Závazky staví {@see \MyInvoice\Service\Payroll\Payment\PayrollNetWageLiabilityMaterializer}
     * z `payable_after_enforcement_minor` uloženého ve výsledku osoby. Tvrzení je
     * NEROVNOST, ne rovnost, a to záměrně: součet závazků smí být MENŠÍ, protože
     *   - zápočet na účet společníka (`partner_settlement`) je účetní překlasifikace,
     *     ne platba, a závazek nevytvoří,
     *   - záporná výplata (doplatek ZP do minimálního vyměřovacího základu) závazek
     *     nevytvoří vůbec a vede se jako pohledávka v účetnictví.
     * Větší být nesmí NIKDY — to by znamenalo, že se do platební dávky dostane víc
     * peněz, než kolik osobě po exekučních srážkách náleží.
     */
    private function m2NetWageLiabilitiesDoNotExceedResult(): array
    {
        $rule = 'platební závazky čisté mzdy nepřevýší výsledek revize';
        $source = '§ 141 ZP; § 279 OSŘ';
        if (!$this->hasTable('payroll_payment_liabilities')
            || !$this->hasTable('payroll_run_persons')
        ) {
            return $this->skipped('M2', $rule, $source, 'platební nebo výsledková tabulka v DB není');
        }

        return $this->run('M2', $rule, $source,
            "SELECT CONCAT('revize ', t.revision_id, ' / zaměstnanec ', t.employee_id,
                           ' (firma ', t.supplier_id, '): závazky ', t.liable_minor,
                           ' hal. proti nároku ', t.payable_minor, ' hal.') AS violation
               FROM (
                    SELECT liability.supplier_id,
                           liability.revision_id,
                           liability.employee_id,
                           SUM(liability.amount_minor) AS liable_minor,
                           GREATEST(CAST(COALESCE(JSON_VALUE(
                               person.result_json,
                               '$.payable_after_enforcement_minor'
                           ), 0) AS SIGNED), 0) AS payable_minor
                      FROM payroll_payment_liabilities liability
                      JOIN payroll_run_persons person
                        ON person.supplier_id = liability.supplier_id
                       AND person.revision_id = liability.revision_id
                       AND person.employee_id = liability.employee_id
                     WHERE liability.liability_kind = 'net_wage'
                       AND liability.direction = 'outgoing'
                       AND person.result_json IS NOT NULL
                       AND JSON_VALUE(person.result_json,
                                      '$.payable_after_enforcement_minor') IS NOT NULL
                     GROUP BY liability.supplier_id,
                              liability.revision_id,
                              liability.employee_id,
                              person.result_json
               ) t
              WHERE t.liable_minor > t.payable_minor
              LIMIT " . self::SAMPLE_LIMIT);
    }

    /**
     * M3 — řetěz revizí je celistvý.
     *
     * Cizí klíč `fk_payroll_run_revision_previous` hlídá jen to, že cíl EXISTUJE
     * a patří téže firmě. Neřekne nic o tom, že patří TÉMUŽ BĚHU a že je STARŠÍ —
     * a to jsou přesně dvě věci, na kterých stojí výpočet opravné revize:
     * odkaz do jiného běhu by počítal rozdíl proti cizí mzdě, odkaz na novější
     * revizi by z řetězu oprav udělal kruh. Ověřuje se proto všechno; existence
     * cíle zůstává v dotazu jako levná pojistka pro případ, že by FK v nějakém
     * nasazení chyběl (starší schéma, obnova bez klíčů).
     */
    private function m3RevisionChainIsIntact(): array
    {
        $rule = 'revize neukazuje na neexistující, cizí ani novější předchůdce';
        $source = '§ 8 odst. 4 ZoÚ (průkaznost)';
        if (!$this->hasTable('payroll_run_revisions')) {
            return $this->skipped('M3', $rule, $source, 'payroll_run_revisions v DB není');
        }

        return $this->run('M3', $rule, $source,
            "SELECT CONCAT('revize ', revision.id, ' (firma ', revision.supplier_id,
                           ', běh ', revision.run_id, ', č. ', revision.revision_no,
                           ') má vadný odkaz previous_revision_id = ',
                           revision.previous_revision_id) AS violation
               FROM payroll_run_revisions revision
          LEFT JOIN payroll_run_revisions parent
                 ON parent.supplier_id = revision.supplier_id
                AND parent.id = revision.previous_revision_id
              WHERE revision.previous_revision_id IS NOT NULL
                AND (parent.id IS NULL
                  OR parent.run_id <> revision.run_id
                  OR parent.revision_no >= revision.revision_no)
              LIMIT " . self::SAMPLE_LIMIT);
    }

    /**
     * M4 — běh má nejvýš jednu platnou schválenou revizi a nahrazení je doložené.
     *
     * Od migrace 1621 se schválená revize nepřepisuje: překlopí se na `superseded`
     * a ukáže na svou náhradu. Dvě revize téhož běhu ve stavu `approved` by
     * znamenaly dvě „platné" pravdy o téže mzdě a každý konzument by si vybral
     * jinou. Stav `superseded` bez doloženého nástupce je totéž o kus dál:
     * revize tvrdí, že je nahrazená, ale neřekne čím.
     *
     * CHECK z migrace 1621 hlídá jen `superseded_at`; nástupce nehlídá nikdo,
     * protože sloupec je nullable (u historických řádků být musí).
     */
    private function m4EachRunHasSingleCurrentApprovedRevision(): array
    {
        $rule = 'běh má nejvýš jednu schválenou revizi a nahrazení má doloženého nástupce';
        $source = '§ 35 odst. 6 ZoÚ (opravy)';
        if (!$this->hasTable('payroll_run_revisions')) {
            return $this->skipped('M4', $rule, $source, 'payroll_run_revisions v DB není');
        }
        if (!$this->hasColumn('payroll_run_revisions', 'superseded_by_revision_id')) {
            return $this->skipped('M4', $rule, $source, 'migrace 1621 (superseded) neproběhla');
        }

        return $this->run('M4', $rule, $source,
            "SELECT CONCAT('běh ', run_id, ' (firma ', supplier_id, ') má ',
                           COUNT(*), ' schválených revizí: ',
                           GROUP_CONCAT(revision_no ORDER BY revision_no)) AS violation
               FROM payroll_run_revisions
              WHERE status = 'approved'
              GROUP BY supplier_id, run_id
             HAVING COUNT(*) > 1
              UNION ALL
             SELECT CONCAT('revize ', revision.id, ' (firma ', revision.supplier_id,
                           ', běh ', revision.run_id,
                           ') je superseded, ale nástupce ',
                           COALESCE(CAST(revision.superseded_by_revision_id AS CHAR), 'chybí'),
                           ' není platná novější revize téhož běhu') AS violation
               FROM payroll_run_revisions revision
          LEFT JOIN payroll_run_revisions successor
                 ON successor.supplier_id = revision.supplier_id
                AND successor.id = revision.superseded_by_revision_id
              WHERE revision.status = 'superseded'
                AND (successor.id IS NULL
                  OR successor.run_id <> revision.run_id
                  OR successor.revision_no <= revision.revision_no)
              LIMIT " . self::SAMPLE_LIMIT);
    }

    /**
     * M5 — zůstatek dohody o srážce sedí na append-only ledger.
     *
     * `payroll_deduction_agreements.withheld_total_minor` je MATERIALIZOVANÝ součet:
     * `PayrollNetRepository::appendLedgerMovement()` zapíše pohyb a hned nato
     * inkrementuje zůstatek dohody. Jsou to dva zápisy do dvou tabulek, takže se
     * mohou rozejít — a rozejdou-li se, přestane platit limit dohody (§ 148 ZP)
     * a firma může srazit víc, než na kolik má dohodu.
     *
     * Dohoda vzniká vždy s nulovým zůstatkem (INSERT ho nenastavuje), takže rovnost
     * musí platit i pro dohody bez jediného pohybu.
     */
    private function m5DeductionAgreementBalanceMatchesLedger(): array
    {
        $rule = 'zůstatek dohody o srážce = součet pohybů v ledgeru';
        $source = '§ 148 ZP';
        if (!$this->hasTable('payroll_deduction_agreements')
            || !$this->hasTable('payroll_deduction_ledger')
        ) {
            return $this->skipped('M5', $rule, $source, 'tabulky srážek v DB nejsou');
        }

        return $this->run('M5', $rule, $source,
            "SELECT CONCAT('dohoda o srážce #', agreement.id, ' (firma ',
                           agreement.supplier_id, ', zaměstnanec ',
                           agreement.employee_id, '): uloženo ',
                           agreement.withheld_total_minor, ' hal., ledger dává ',
                           COALESCE(ledger.moved_minor, 0), ' hal.') AS violation
               FROM payroll_deduction_agreements agreement
          LEFT JOIN (
                    SELECT supplier_id, agreement_id, SUM(amount_minor) AS moved_minor
                      FROM payroll_deduction_ledger
                     WHERE agreement_id IS NOT NULL
                       AND event_kind IN ('withheld', 'reversed')
                     GROUP BY supplier_id, agreement_id
               ) ledger
                 ON ledger.supplier_id = agreement.supplier_id
                AND ledger.agreement_id = agreement.id
              WHERE agreement.withheld_total_minor <> COALESCE(ledger.moved_minor, 0)
              LIMIT " . self::SAMPLE_LIMIT);
    }

    /**
     * M6 — kumulativní stav v ledgeru materializací = součet přírůstků.
     *
     * `payroll_surcharge_input_materializations` (migrace 1627) vede vedle přírůstku
     * (`amount_minor`) i průběžný stav (`cumulative_minor`) — dvě čísla o téže
     * koruně, takže se mohou rozejít. Rozejdou-li se, přestane platit kontrola
     * „kolik už bylo za příplatek zaplaceno" a oprava se navěsí na špatný základ.
     *
     * Kontroluje se na každém řádku, ne jen na posledním: vloudí-li se chyba
     * uprostřed řetězu, poslední součet může vyjít správně a chyba se schová.
     */
    private function m6SurchargeCumulativeMatchesIncrements(): array
    {
        $rule = 'kumulativní stav materializace příplatku = součet přírůstků';
        $source = '§ 114–118 ZP';
        if (!$this->hasTable('payroll_surcharge_input_materializations')) {
            return $this->skipped('M6', $rule, $source, 'migrace 1627 neproběhla');
        }

        return $this->run('M6', $rule, $source,
            "SELECT CONCAT('materializace #', current_row.id, ' (firma ',
                           current_row.supplier_id, ', vztah ', current_row.employment_id,
                           ', ', current_row.period_start, ', ', current_row.surcharge_kind,
                           ', poř. ', current_row.sequence_no, '): uloženo ',
                           current_row.cumulative_minor, ' hal., součet přírůstků ',
                           (SELECT COALESCE(SUM(prior.amount_minor), 0)
                              FROM payroll_surcharge_input_materializations prior
                             WHERE prior.supplier_id = current_row.supplier_id
                               AND prior.employment_id = current_row.employment_id
                               AND prior.period_start = current_row.period_start
                               AND prior.surcharge_kind = current_row.surcharge_kind
                               AND prior.sequence_no <= current_row.sequence_no),
                           ' hal.') AS violation
               FROM payroll_surcharge_input_materializations current_row
              WHERE current_row.cumulative_minor <> (
                    SELECT COALESCE(SUM(prior.amount_minor), 0)
                      FROM payroll_surcharge_input_materializations prior
                     WHERE prior.supplier_id = current_row.supplier_id
                       AND prior.employment_id = current_row.employment_id
                       AND prior.period_start = current_row.period_start
                       AND prior.surcharge_kind = current_row.surcharge_kind
                       AND prior.sequence_no <= current_row.sequence_no)
              LIMIT " . self::SAMPLE_LIMIT);
    }

    /**
     * M7 — pořadí v append-only ledgeru souhlasí s pořadím vzniku.
     *
     * `sequence_no` je logické pořadí opravného řetězu, `id` je fyzické pořadí
     * zápisu. U append-only tabulky musí obojí růst spolu. Řádek s NIŽŠÍM
     * `sequence_no`, který vznikl POZDĚJI, znamená, že se do uzavřeného řetězu
     * dodatečně vlepila položka — přesně ten zásah, kvůli kterému append-only
     * ledger existuje. FK ani unikátní klíč tohle neuhlídají: dvojice
     * (rozsah, sequence_no) je unikátní, ale nic nevynucuje monotónnost.
     */
    private function m7AppendOnlySequenceMatchesInsertOrder(): array
    {
        $rule = 'append-only ledger nemá řádek s nižším pořadím vzniklý později';
        $source = '§ 33a ZoÚ (průkaznost účetního záznamu)';
        if (!$this->hasTable('payroll_surcharge_input_materializations')) {
            return $this->skipped('M7', $rule, $source, 'migrace 1627 neproběhla');
        }

        return $this->run('M7', $rule, $source,
            "SELECT CONCAT('materializace #', later_row.id, ' má pořadí ',
                           later_row.sequence_no, ', ale vznikla až po #',
                           earlier_row.id, ' s pořadím ', earlier_row.sequence_no,
                           ' (firma ', later_row.supplier_id, ', vztah ',
                           later_row.employment_id, ', ', later_row.period_start,
                           ', ', later_row.surcharge_kind, ')') AS violation
               FROM payroll_surcharge_input_materializations later_row
               JOIN payroll_surcharge_input_materializations earlier_row
                 ON earlier_row.supplier_id = later_row.supplier_id
                AND earlier_row.employment_id = later_row.employment_id
                AND earlier_row.period_start = later_row.period_start
                AND earlier_row.surcharge_kind = later_row.surcharge_kind
                AND earlier_row.id < later_row.id
              WHERE earlier_row.sequence_no > later_row.sequence_no
              LIMIT " . self::SAMPLE_LIMIT);
    }

    /**
     * M8 — zmrazený snapshot revize sedí na svůj SHA-256.
     *
     * Vstupní i výsledkový snapshot se ukládají jako kanonický JSON
     * ({@see \MyInvoice\Service\Payroll\Ruleset\CanonicalJson}) a vedle nich otisk
     * `sha256` téhož řetězce. Otisk je JEDINÝ doklad, že se se zmrazeným podkladem
     * po schválení nehnulo — a je-li přepsaný jen JSON, nepozná to nic jiného
     * (triggery z 1621 chrání před UPDATE, ne před tím, co do řádku dostal INSERT
     * nebo zásah mimo aplikaci).
     *
     * Porovnává se přímo v SQL, tedy nad tím, co je skutečně uložené — ne nad tím,
     * co si PHP z řádku poskládá.
     */
    private function m8FrozenSnapshotMatchesItsHash(): array
    {
        $rule = 'snapshot revize odpovídá svému otisku SHA-256';
        $source = '§ 33a odst. 1 ZoÚ';
        if (!$this->hasTable('payroll_run_revisions')) {
            return $this->skipped('M8', $rule, $source, 'payroll_run_revisions v DB není');
        }

        return $this->run('M8', $rule, $source,
            "SELECT CONCAT('revize ', id, ' (firma ', supplier_id, ', běh ', run_id,
                           ', č. ', revision_no, '): rozchází se otisk ',
                           GROUP_CONCAT(kind SEPARATOR ' i ')) AS violation
               FROM (
                    SELECT id, supplier_id, run_id, revision_no, 'vstupu' AS kind
                      FROM payroll_run_revisions
                     WHERE SHA2(input_snapshot_json, 256) <> input_snapshot_hash
                     UNION ALL
                    SELECT id, supplier_id, run_id, revision_no, 'výsledku' AS kind
                      FROM payroll_run_revisions
                     WHERE result_snapshot_json IS NOT NULL
                       AND result_snapshot_hash IS NOT NULL
                       AND SHA2(result_snapshot_json, 256) <> result_snapshot_hash
               ) mismatched
              GROUP BY id, supplier_id, run_id, revision_no
              LIMIT " . self::SAMPLE_LIMIT);
    }

    /**
     * @return array{code:string, rule:string, source:string, checked:bool, violations:list<string>, skipped_reason:?string}
     */
    private function run(string $code, string $rule, string $source, string $sql): array
    {
        /** @var list<string> $violations */
        $violations = $this->pdo()->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return [
            'code' => $code,
            'rule' => $rule,
            'source' => $source,
            'checked' => true,
            'violations' => $violations,
            'skipped_reason' => null,
        ];
    }

    /**
     * @return array{code:string, rule:string, source:string, checked:bool, violations:list<string>, skipped_reason:?string}
     */
    private function skipped(string $code, string $rule, string $source, string $reason): array
    {
        return [
            'code' => $code,
            'rule' => $rule,
            'source' => $source,
            'checked' => false,
            'violations' => [],
            'skipped_reason' => $reason,
        ];
    }

    private function pdo(): PDO
    {
        return $this->db->pdo();
    }

    private function hasTable(string $table): bool
    {
        return $this->pdo()->query('SHOW TABLES LIKE ' . $this->pdo()->quote($table))->fetch() !== false;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->hasTable($table)
            && $this->pdo()->query("SHOW COLUMNS FROM `{$table}` LIKE " . $this->pdo()->quote($column))->fetch() !== false;
    }
}

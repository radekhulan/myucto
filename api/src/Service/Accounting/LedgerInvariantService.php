<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Invarianty účetního jádra — vrstva L3 z auditního plánu.
 *
 * Tvrzení, která platí VŽDY, nezávisle na datech. Rozdíl proti scénářovému testu je
 * zásadní: scénář ověřuje cestu, kterou si sám vymyslel, kdežto invariant chytí i to,
 * co do dat dostala cesta, na kterou nikdo nemyslel — backfill, import, migrace,
 * ruční zásah do DB.
 *
 * Přesně tak vznikl nález N-006: doklad ve stavu `cancelled` s aktivním zápisem,
 * zaúčtovaný AŽ PO stornu, přestože {@see PostingService} to odmítá. Vzniklo to cestou,
 * která PostingService obešla, a žádný scénářový test na to nemohl dosáhnout.
 *
 * Služba je striktně READ-ONLY (samé SELECTy, žádná transakce), aby ji šlo bez rizika
 * pustit i proti produkční databázi. Konzumenti:
 *   - `api/bin/check-invariants.php` (CLI / cron / CI brána),
 *   - `tests/Invariants/LedgerInvariantsTest` (běží nad testovací DB).
 *
 * Čísla invariantů odpovídají matici v `private/checks/MATICE-UCETNICTVI.md`.
 */
final class LedgerInvariantService
{
    /** Kolik porušení se u jednoho invariantu vypíše, než se výpis usekne. */
    private const SAMPLE_LIMIT = 50;

    public function __construct(private readonly Connection $db) {}

    /**
     * Spustí všechny invarianty a vrátí nález per invariant.
     *
     * @return list<array{code:string, rule:string, source:string, checked:bool, violations:list<string>, skipped_reason:?string}>
     */
    public function checkAll(): array
    {
        return [
            $this->i1EntriesBalanced(),
            $this->i9ResultAccountsClearedAfterClose(),
            $this->i20ClearingAccountsZero(),
            $this->i25AccumulatedNotAboveInputPrice(),
            $this->i26DisposedAssetHasNoBalance(),
            $this->i28PostedEntriesHaveAuditTrail(),
            $this->i29JournalHistoryIsVersioned(),
            $this->i30PayrollSnapshotMatchesFinals(),
        ];
    }

    /**
     * Mzdový snapshot souhlasí s uloženými finálními částkami.
     *
     * `payroll_monthly_records` nese ROZPIS (`breakdown`) i finální čísla
     * (`advance_tax_final`, `net_final`). Uživatel čte rozpis, do mzdového listu
     * a do deníku jdou finální částky — a ty se mohou rozejít, protože se ukládají
     * jako dvě samostatné hodnoty, ne jako jedna.
     *
     * Naměřeno v produkci: dvanáct měsíců neslo v rozpisu jinou zálohu na daň a jinou
     * čistou mzdu, než jaké byly v uložených finálech. Rozdíl přesně
     * odpovídal slevě na poplatníka, kterou jedna z obou cest uplatnila a druhá ne.
     * Uživateli se ukazovalo jiné číslo, než jaké bylo uložené, a nic na to
     * neupozornilo.
     */
    private function i30PayrollSnapshotMatchesFinals(): array
    {
        if (!$this->hasTable('payroll_monthly_records')) {
            return $this->skipped('I30', 'mzdový rozpis souhlasí s uloženými částkami', '§ 38j ZDP', 'mzdový modul není v DB');
        }

        return $this->run(
            'I30',
            'mzdový rozpis souhlasí s uloženými částkami',
            '§ 38j ZDP',
            "SELECT CONCAT('mzda ', r.year, '/', LPAD(r.month, 2, '0'),
                           ' (firma ', r.supplier_id, ', zaměstnanec ', r.employee_id,
                           '): rozpis ', COALESCE(JSON_VALUE(r.breakdown, '$.advance_tax_withheld'), '?'),
                           ' / ', COALESCE(JSON_VALUE(r.breakdown, '$.net'), '?'),
                           ', uloženo ', r.advance_tax_final, ' / ', r.net_final) AS violation
               FROM payroll_monthly_records r
              WHERE JSON_VALUE(r.breakdown, '$.advance_tax_withheld') IS NOT NULL
                AND (ABS(CAST(JSON_VALUE(r.breakdown, '$.advance_tax_withheld') AS DECIMAL(14,2)) - r.advance_tax_final) > 0.005
                  OR ABS(CAST(JSON_VALUE(r.breakdown, '$.net') AS DECIMAL(14,2)) - r.net_final) > 0.005)
              LIMIT " . self::SAMPLE_LIMIT,
        );
    }

    /**
     * I29 — deník má zapnuté SYSTEM VERSIONING (§ 12 ZoÚ, průkaznost účetních záznamů).
     *
     * `JournalHistoryService` sám přiznává, že na jiné DB než MariaDB vrátí jedinou verzi
     * BEZ CHYBY: `FOR SYSTEM_TIME ALL` na neverzované tabulce prostě vrátí aktuální řádek.
     * Nasazení na MySQL by tak tiše ztratilo celou auditní historii deníku a nikdo by se
     * to nedozvěděl —historie by vypadala prázdná, ne rozbitá. To je přesně ta třída chyb,
     * kterou má tenhle registr chytat.
     *
     * Kontroluje se skutečný stav v `information_schema`, ne jen typ serveru: migrace 1029
     * versioning zapíná podmíněně, takže „běží MariaDB" neznamená „je to zapnuté".
     *
     * @return array{code:string, rule:string, source:string, checked:bool, violations:list<string>, skipped_reason:?string}
     */
    private function i29JournalHistoryIsVersioned(): array
    {
        $rule = 'deník má zapnuté SYSTEM VERSIONING (auditní historie)';

        // Čte se DDL, ne `information_schema`. Tahle verze MariaDB v `COLUMNS` období
        // `row_start`/`row_end` neukazuje a `TABLES.CREATE_OPTIONS` nechává prázdné
        // — ověřeno na obou databázích. Dotaz do information_schema by proto vracel
        // „neverzováno" i tam, kde versioning JE, a invariant by hlásil falešný poplach.
        $missing = [];
        foreach (['journal_entries', 'journal_entry_lines'] as $table) {
            try {
                $row = $this->pdo()->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
            } catch (\Throwable) {
                return $this->skipped('I29', $rule, '§ 12 ZoÚ', 'tabulku ' . $table . ' nelze přečíst');
            }
            if ($row === false || !isset($row[1])) {
                return $this->skipped('I29', $rule, '§ 12 ZoÚ', 'tabulka ' . $table . ' neexistuje');
            }
            if (stripos((string) $row[1], 'WITH SYSTEM VERSIONING') === false) {
                $missing[] = $table;
            }
        }

        return [
            'code' => 'I29',
            'rule' => $rule,
            'source' => '§ 12 ZoÚ',
            'checked' => true,
            'violations' => array_map(
                static fn (string $t): string => 'tabulka ' . $t . ' NEMÁ SYSTEM VERSIONING — '
                    . 'historie zápisů se tiše neukládá a /journal/{id}/history vrátí jedinou verzi',
                array_values($missing),
            ),
            'skipped_reason' => null,
        ];
    }

    /** Má vůbec deník obsah? Nad prázdnou databází jsou všechny invarianty vakuózní. */
    public function ledgerIsEmpty(): bool
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM journal_entry_lines')->fetchColumn() === 0;
    }

    /** ČÚS 001 — Σ MD = Σ D pro každý jednotlivý zápis, počítáno v haléřích. */
    private function i1EntriesBalanced(): array
    {
        return $this->run(
            'I1',
            'Σ MD = Σ D pro každý zápis',
            'ČÚS 001',
            "SELECT CONCAT('zápis #', e.id, ' (firma ', e.supplier_id, ', ', e.entry_date,
                           ', ', e.source_type, ') rozdíl ', diff_cents, ' h') AS violation
               FROM (SELECT e.id, e.supplier_id, e.entry_date, e.source_type,
                            SUM(CASE WHEN l.side = 'debit' THEN ROUND(l.amount * 100)
                                     ELSE -ROUND(l.amount * 100) END) AS diff_cents
                       FROM journal_entries e
                       JOIN journal_entry_lines l ON l.entry_id = e.id
                      WHERE e.posted_at IS NOT NULL
                   GROUP BY e.id
                     HAVING diff_cents <> 0) e
              LIMIT " . self::SAMPLE_LIMIT,
        );
    }

    /**
     * ČÚS 002 — po uzavření období nesmí mít ŽÁDNÝ výsledkový účet (třída 5 a 6)
     * nenulový zůstatek; celý se převádí na 710.
     *
     * Do fáze F6 se ověřovala jen nulovost 710 — jenže vyvážené 710 nevylučuje, že
     * jeden nákladový účet zůstal viset a jiný ho vykompenzoval.
     */
    private function i9ResultAccountsClearedAfterClose(): array
    {
        return $this->run(
            'I9',
            'po uzavření nemá žádný účet třídy 5 ani 6 zůstatek',
            'ČÚS 002',
            "SELECT CONCAT('firma ', supplier_id, ', rok ', fiscal_year, ', účet ', account_code,
                           ' zůstatek ', balance) AS violation
               FROM (SELECT p.supplier_id, p.fiscal_year, a.account_code,
                            ROUND(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 2) AS balance
                       FROM accounting_periods p
                       JOIN journal_entries e
                         ON e.supplier_id = p.supplier_id
                        AND e.entry_date BETWEEN p.starts_on AND p.ends_on
                        AND e.posted_at IS NOT NULL
                       JOIN journal_entry_lines l ON l.entry_id = e.id
                       JOIN chart_of_accounts a ON a.id = l.account_id
                      WHERE p.status IN (" . AccountingPeriodStatus::closedSqlList() . ")
                        AND (a.account_code LIKE '5%' OR a.account_code LIKE '6%')
                   GROUP BY p.supplier_id, p.fiscal_year, a.account_code
                     HAVING ABS(balance) > 0.005) t
              LIMIT " . self::SAMPLE_LIMIT,
        );
    }

    /**
     * ČÚS 017 — zúčtovací účty (`is_clearing`, typicky 395) musí být k rozvahovému dni
     * uzavřeného období nulové. Kontrola v uzávěrce existovala, ale neměla test, takže
     * se nevědělo, jestli vůbec něco najde.
     */
    private function i20ClearingAccountsZero(): array
    {
        if (!$this->hasColumn('chart_of_accounts', 'is_clearing')) {
            return $this->skipped('I20', 'zúčtovací účty jsou k rozvahovému dni nulové', 'ČÚS 017',
                'migrace 1112 (is_clearing) neproběhla');
        }

        return $this->run(
            'I20',
            'zúčtovací účty jsou k rozvahovému dni nulové',
            'ČÚS 017',
            "SELECT CONCAT('firma ', supplier_id, ', rok ', fiscal_year, ', zúčtovací účet ',
                           account_code, ' zůstatek ', balance) AS violation
               FROM (SELECT p.supplier_id, p.fiscal_year, a.account_code,
                            ROUND(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 2) AS balance
                       FROM accounting_periods p
                       JOIN journal_entries e
                         ON e.supplier_id = p.supplier_id
                        AND e.entry_date <= p.ends_on
                        AND e.posted_at IS NOT NULL
                        -- Ze stornované DVOJICE musí vypadnout OBĚ strany. `reversed_by`
                        -- nese jen originál, takže samotné `reversed_by IS NULL` vyhodí
                        -- originál a storno nechá — zbude jednostranná částka a invariant
                        -- ohlásí zůstatek, který v účetnictví není. Naměřeno na ostrých
                        -- datech: zápis #639 (314 MD / 221 D) stornovaný #2100 (221 MD /
                        -- 314 D) vyrobil na 314 fantomových −35 375 Kč.
                        -- Sloupec `reverses_id` v schématu není, takže druhou stranu
                        -- dohledáváme poddotazem přes `reversed_by`.
                        AND e.reversed_by IS NULL
                        AND e.id NOT IN (SELECT rev.reversed_by
                                           FROM journal_entries rev
                                          WHERE rev.reversed_by IS NOT NULL)
                        -- Uzávěrkový zápis TOHOTO období převádí zůstatky na 702
                        -- a tím zúčtovací účty vynuluje. Bez jeho vyloučení vyjde
                        -- balance vždy 0 a invariant nemůže z principu nic najít —
                        -- a přitom běží VÝHRADNĚ nad uzavřenými obdobími.
                        AND NOT (e.source_type = 'closing'
                                 AND e.entry_date BETWEEN p.starts_on AND p.ends_on)
                       JOIN journal_entry_lines l ON l.entry_id = e.id
                       JOIN chart_of_accounts a ON a.id = l.account_id
                      WHERE p.status IN (" . AccountingPeriodStatus::closedSqlList() . ")
                        AND a.is_clearing = 1
                   GROUP BY p.supplier_id, p.fiscal_year, a.account_code
                     HAVING ABS(balance) > 0.005) t
              LIMIT " . self::SAMPLE_LIMIT,
        );
    }

    /** ČÚS 013 — oprávky nesmí přesáhnout vstupní cenu (vznikla by záporná zůstatková cena). */
    private function i25AccumulatedNotAboveInputPrice(): array
    {
        if (!$this->hasTable('assets') || !$this->hasTable('depreciation_entries')) {
            return $this->skipped('I25', 'oprávky nepřesahují vstupní cenu', 'ČÚS 013', 'modul majetku není v DB');
        }
        $improvements = $this->hasTable('asset_improvements')
            ? "LEFT JOIN (SELECT asset_id, SUM(amount) AS total FROM asset_improvements GROUP BY asset_id) imp
                      ON imp.asset_id = a.id"
            : '';
        $impTotal = $this->hasTable('asset_improvements') ? 'COALESCE(imp.total, 0)' : '0';

        return $this->run(
            'I25',
            'oprávky nepřesahují vstupní cenu',
            'ČÚS 013',
            "SELECT CONCAT('karta #', a.id, ' (', a.inventory_number, '): vstupní cena ',
                           ROUND(a.input_price + {$impTotal}, 2), ', oprávky ', ROUND(COALESCE(dep.total, 0), 2)) AS violation
               FROM assets a
          LEFT JOIN (SELECT asset_id, SUM(amount) AS total
                       FROM depreciation_entries
                      WHERE kind = 'accounting' AND status IN ('confirmed', 'posted')
                   GROUP BY asset_id) dep ON dep.asset_id = a.id
              {$improvements}
              WHERE COALESCE(dep.total, 0) > ROUND(a.input_price + {$impTotal}, 2) + 0.005
              LIMIT " . self::SAMPLE_LIMIT,
        );
    }

    /**
     * ČÚS 013 — vyřazená karta nesmí nechat živý zůstatek na svých účtech 02x/08x.
     *
     * Kontroluje se jen to, co lze spolehlivě přiřadit ke KARTĚ (zápisy se
     * `source_type = 'asset'`); ruční zápisy na tytéž účty ke kartě přiřadit nejdou
     * a do invariantu nepatří.
     */
    private function i26DisposedAssetHasNoBalance(): array
    {
        if (!$this->hasTable('assets') || !$this->hasTable('depreciation_entries')) {
            return $this->skipped('I26', 'vyřazená karta nemá zůstatek na 02x ani 08x', 'ČÚS 013', 'modul majetku není v DB');
        }

        // Karta se do deníku promítá TŘEMI druhy zápisů a každý na kartu ukazuje jinak:
        //   `asset` a `asset_disposal` mají `source_id` = id karty,
        //   `depreciation` má `source_id` = id řádku v `depreciation_entries` (ten nese `asset_id`).
        // Původní verze joinovala jen `asset`, takže u vyřazené karty viděla POUZE debet 022
        // ze zařazení — protikus z vyřazení ani odpisy ne. Označila tím každé korektně
        // vyřazené aktivum (ověřeno na ostrých datech: karta BMW se čtyřřádkovým a zcela
        // správným zápisem vyřazení). Falešný poplach na každém vyřazení je horší než
        // žádný invariant, protože odnaučí lidi hlášení číst.
        //
        // Stornované zápisy se ZÁMĚRNĚ nefiltrují: originál i storno se sečtou a vzájemně
        // se vyruší, což je právě správný výsledek. Jednostranné vyloučení by vyrobilo
        // fantomový zůstatek — přesně ta chyba, kterou má I20 opravenou vedle.
        return $this->run(
            'I26',
            'vyřazená karta nemá zůstatek na 02x ani 08x',
            'ČÚS 013',
            "SELECT CONCAT('vyřazená karta #', id, ' (', inventory_number, '): účet ',
                           account_code, ' drží ', balance) AS violation
               FROM (SELECT a.id, a.inventory_number, ac.account_code,
                            ROUND(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 2) AS balance
                       FROM assets a
                       JOIN journal_entries e
                         ON e.supplier_id = a.supplier_id
                        AND e.posted_at IS NOT NULL
                        AND (
                              (e.source_type IN ('asset', 'asset_disposal') AND e.source_id = a.id)
                           OR (e.source_type = 'depreciation'
                               AND e.source_id IN (SELECT d.id
                                                     FROM depreciation_entries d
                                                    WHERE d.asset_id = a.id
                                                      AND d.supplier_id = a.supplier_id))
                            )
                       JOIN journal_entry_lines l ON l.entry_id = e.id
                       JOIN chart_of_accounts ac ON ac.id = l.account_id
                      WHERE a.status = 'disposed'
                        AND ac.account_code IN (a.asset_account_code, a.accumulated_account_code)
                   GROUP BY a.id, ac.account_code
                     HAVING ABS(balance) > 0.005) t
              LIMIT " . self::SAMPLE_LIMIT,
        );
    }

    /**
     * § 33a — každý zaúčtovaný zápis má auditní stopu.
     *
     * NEMĚNNOST samotného logu tenhle invariant neověřuje a ověřit ani nemůže:
     * `activity_log` je běžná tabulka bez hash-chainu. Je to zapsaná díra v matici
     * účetnictví, ne opomenutí. Omezeno na zápisy vzniklé po zavedení logování,
     * jinak by hlásilo celou historii před migrací.
     */
    private function i28PostedEntriesHaveAuditTrail(): array
    {
        if (!$this->hasTable('activity_log')) {
            return $this->skipped('I28', 'zaúčtovaný zápis má auditní stopu', '§ 33a ZoÚ', 'activity_log v DB není');
        }
        $since = $this->pdo()->query('SELECT MIN(created_at) FROM activity_log')->fetchColumn();
        if ($since === false || $since === null) {
            return $this->skipped('I28', 'zaúčtovaný zápis má auditní stopu', '§ 33a ZoÚ', 'activity_log je prázdný');
        }

        return $this->run(
            'I28',
            'zaúčtovaný zápis má auditní stopu',
            '§ 33a ZoÚ',
            "SELECT CONCAT('zápis #', e.id, ' (firma ', e.supplier_id, ', ', e.entry_date,
                           ') nemá záznam v activity_log') AS violation
               FROM journal_entries e
              WHERE e.posted_at IS NOT NULL
                AND e.posted_at >= " . $this->pdo()->quote((string) $since) . "
                AND NOT EXISTS (SELECT 1 FROM activity_log al
                                 WHERE al.entity_type = 'journal_entry' AND al.entity_id = e.id)
              LIMIT " . self::SAMPLE_LIMIT,
        );
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
        return $this->pdo()->query("SHOW TABLES LIKE " . $this->pdo()->quote($table))->fetch() !== false;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->hasTable($table)
            && $this->pdo()->query("SHOW COLUMNS FROM `{$table}` LIKE " . $this->pdo()->quote($column))->fetch() !== false;
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\Assets\DisposalResiduals;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;

/**
 * Odpisy roku po kartách majetku pro rozdíl účetních a daňových odpisů (ř. 50/150 DPPO,
 * podklad úprav základu daně). Účetní i daňový odpis se bere jen u karet, které modul
 * majetku odpisuje, a jen za měsíce, které jsou v knihách:
 *
 *   - `module`: nákladové řádky zápisů odpisů modulu (`source_type = 'depreciation'`), tak
 *     jak se zaúčtovaly, i se stornem;
 *   - `evidence`: účetní řádky roku bez vlastního zápisu, které nejsou zaúčtované; řádek
 *     zaúčtovaný bez zápisu ({@see DepreciationPostingService::JOURNAL_MISSING}) v knihách není;
 *   - `migrated`: účetní řádky roku zaúčtované převzatým deníkem (PREMIER, Money S3,
 *     {@see DepreciationEntryRepository::isBookedByMigratedJournal()});
 *   - `taken_over`: měsíce roku, které pokrývá počáteční stav karty (`opening_acc_months`,
 *     převod z POHODY, Stereo NX), tedy odpis je v deníku převzatém z původního programu.
 *     Částka je obrat 551 zápisů s číslem dokladu rovným inventárnímu číslu karty do
 *     posledního pokrytého měsíce. Storno se přiřadí podle původního zápisu (vazba
 *     `reversed_by`), ne podle svého čísla dokladu. Bez takových zápisů se odpis odhadne
 *     z počátečního stavu karty, nejvýš do obratu 551, který žádné kartě nepatří.
 *
 * Vyřazení převzaté karty v deníku: zápis s jejím číslem dokladu, který připisuje na její
 * majetkový účet (01x/02x) v okolí data vyřazení, je zápisem vyřazení. Zůstatková cena
 * v něm je připsaná pořizovací cena minus odepsané oprávky (MD 07x/08x) a z obratu 551
 * zápisu se odečte (řeší ji můstek ZC, {@see DisposalResiduals}). Vede-li zápis ZC na 54x
 * (MD 541 / D 08x), je 551 téhož zápisu proti oprávkám odpis roku vyřazení a zůstává
 * u karty. Když ZC z řádků zápisu určit nejde, celý obrat 551 zápisu zůstane mimo karty
 * a podklady na to upozorní.
 *
 * Karta s daňovým odpisem rovným účetnímu (`tax_method = 'by_accounting'`, nehmotný
 * majetek § 32a) má daňový plán až od konce počátečního stavu: daňový odpis převzatých
 * měsíců roku je proto stejný jako jejich účetní odpis (`tax_taken_over`).
 *
 * Karty v konceptu se nepočítají ani účetně, ani daňově (rozdíl odpisů zůstane neutrální)
 * a obrat 551 mimo karty (zůstatková cena, opravné položky, ruční zápisy) do rozdílu odpisů
 * nevstupuje. Obojí jen hlásí {@see warnings()}, stejně jako kartu, jejíž rok je celý
 * v převzatém deníku a modul ho přesto zaúčtoval znovu (`duplicates`). Firma, která
 * odpisuje jen modulem majetku, má výsledek stejný jako součet svých zaúčtovaných řádků.
 */
final class BookedAccountingDepreciation
{
    /** Syntetický účet účetních odpisů (vyhláška 500/2002 i 504/2002). */
    public const EXPENSE_ACCOUNT = '551';

    /** Účtová skupina, na kterou převzaté vyřazení vede zůstatkovou cenu (541, 543, 549). */
    private const RESIDUAL_EXPENSE_PREFIX = '54';

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   total:float, module:float, evidence:float, migrated:float, taken_over:float,
     *   tax_taken_over:float,
     *   tax_taken_over_by_group:array{tangible:array<int,float>,intangible:float,unclassified:float},
     *   journal_551:float, outside_cards:float, residuals:float,
     *   estimated:array{assets:int,amount:float}, drafts:array{assets:int,amount:float,taken_over:float},
     *   disposals_unsplit:array{assets:list<string>,amount:float},
     *   duplicates:list<array{inventory_number:string,amount:float}>
     * }
     */
    public function forPeriod(int $supplierId, int $fiscalYear, string $startsOn, string $endsOn): array
    {
        [$module, $moduleByAsset, $entries, $journal551] = $this->journal($supplierId, $startsOn, $endsOn);
        [$evidence, $migrated, $migratedAssets, $migratedTaxAssets] = $this->rows($supplierId, $fiscalYear);
        $cards = $this->takenOver($supplierId, $startsOn, $endsOn, $entries, $journal551 - $migrated, $moduleByAsset, $migratedAssets, $migratedTaxAssets);
        $outside = round($journal551 - $migrated - $cards['amount'] - $cards['drafts']['amount'] - $cards['residuals'], 2);

        return [
            'total' => round($module + $evidence + $migrated + $cards['amount'], 2),
            'module' => $module,
            'evidence' => $evidence,
            'migrated' => $migrated,
            'taken_over' => $cards['amount'],
            'tax_taken_over' => $cards['tax'],
            'tax_taken_over_by_group' => $cards['tax_by_group'],
            'journal_551' => $journal551,
            'outside_cards' => $outside,
            'residuals' => $cards['residuals'],
            'estimated' => $cards['estimated'],
            'drafts' => $cards['drafts'],
            'disposals_unsplit' => $cards['disposals_unsplit'],
            'duplicates' => $cards['duplicates'],
        ];
    }

    /**
     * Upozornění k účetním odpisům pro podklady přiznání a odhad daně.
     *
     * @param array{outside_cards:float, estimated:array{assets:int,amount:float}, drafts:array{assets:int,amount:float,taken_over:float},
     *   disposals_unsplit:array{assets:list<string>,amount:float}, duplicates:list<array{inventory_number:string,amount:float}>} $booked
     * @return list<string>
     */
    public static function warnings(array $booked): array
    {
        $warnings = [];
        if ($booked['duplicates'] !== []) {
            $warnings[] = 'Účetní odpis roku je v deníku dvakrát: rok je celý v převzatém deníku (počáteční stav karty) '
                . 'a modul majetku ho zaúčtoval znovu, u karet ' . implode(', ', array_map(
                    static fn (array $d): string => $d['inventory_number'] . ' (' . self::money($d['amount']) . ')',
                    $booked['duplicates'],
                )) . '. Zápis odpisu z modulu stornujte, jinak je náklad roku nadhodnocený.';
        }
        if ($booked['drafts']['assets'] > 0 && ($booked['drafts']['amount'] !== 0.0 || abs($booked['outside_cards']) >= 0.01)) {
            $warnings[] = 'Karty majetku v konceptu (' . $booked['drafts']['assets'] . ') se neodpisují'
                . ($booked['drafts']['amount'] !== 0.0 ? ', jejich účetní odpisy v deníku jsou ' . self::money($booked['drafts']['amount']) : '')
                . '. Do rozdílu účetních a daňových odpisů (ř. 50 a 150) se nepočítají účetně ani daňově. '
                . 'Karty doplňte a zařaďte do užívání, přiznání pak uplatní jejich daňové odpisy.';
        }
        if ($booked['estimated']['assets'] > 0) {
            $warnings[] = 'U karet majetku převzatých z jiného programu (' . $booked['estimated']['assets'] . ') nejsou v deníku zápisy '
                . 's jejich inventárním číslem. Účetní odpisy měsíců v jejich počátečním stavu ('
                . self::money($booked['estimated']['amount']) . ') jsou proto odhad z počátečního stavu karty; ověřte je.';
        }
        $unsplit = $booked['disposals_unsplit'];
        if ($unsplit['assets'] !== []) {
            $warnings[] = 'Zápis vyřazení převzaté karty (' . implode(', ', $unsplit['assets']) . ') má na účtu '
                . self::EXPENSE_ACCOUNT . ' ' . self::money($unsplit['amount']) . ', který z řádků zápisu nejde rozdělit '
                . 'na odpis a zůstatkovou cenu. Do rozdílu odpisů se nepočítá; obsahuje-li odpis, doplňte ho ruční položkou.';
        }
        $outside = round($booked['outside_cards'] - $unsplit['amount'], 2);
        if ($outside >= 0.01) {
            $warnings[] = 'Obrat účtu ' . self::EXPENSE_ACCOUNT . ' mimo odpisy karet majetku je ' . self::money($outside)
                . ' (například zůstatková cena vyřazeného majetku, opravné položky nebo ruční zápisy). Do rozdílu účetních '
                . 'a daňových odpisů (ř. 50 a 150) se nepočítá; je-li v něm účetní odpis majetku, doplňte ho na kartu.';
        } elseif ($outside <= -0.01) {
            $warnings[] = 'Na účtu ' . self::EXPENSE_ACCOUNT . ' jsou mimo odpisy karet majetku zápisy snižující náklad o '
                . self::money(-$outside) . ' (storno nebo převzatý zápis bez karty). Do rozdílu účetních a daňových odpisů '
                . '(ř. 50 a 150) se nepočítají; patří-li ke kartě majetku, ověřte její odpisy.';
        }
        return $warnings;
    }

    /**
     * @return array{0:float,1:array<int,float>,2:list<array{id:int,root:int,depth:int,doc:string,date:string,amount:float}>,3:float}
     *   odpisy modulu celkem a po kartách, ostatní zápisy s obratem 551 (číslo dokladu a datum
     *   původního zápisu, u storna podle `reversed_by`, kořen a hloubka v řetězci storen),
     *   obrat 551 ostatních zápisů celkem
     */
    private function journal(int $supplierId, string $startsOn, string $endsOn): array
    {
        $type = JournalTaxOrigin::sourceTypeSql();
        $net = "COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 0)";
        $params = [$supplierId, $startsOn, $endsOn, ClosingSourceId::STOCK_SLOT_BASE];

        $module = $this->db->pdo()->prepare(
            "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . "
             SELECT de.asset_id, {$net} AS total
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               " . JournalTaxOrigin::join() . "
               JOIN chart_of_accounts a ON a.id = l.account_id
               LEFT JOIN depreciation_entries de ON de.supplier_id = l.supplier_id AND de.id = " . JournalTaxOrigin::sourceIdSql() . "
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND " . JournalTaxOrigin::includedSql() . "
                AND {$type} = 'depreciation' AND a.account_type = 'expense'
              GROUP BY de.asset_id"
        );
        $module->execute($params);
        $moduleTotal = 0.0;
        $byAsset = [];
        foreach ($module->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $moduleTotal += (float) $row['total'];
            if ($row['asset_id'] !== null) {
                $byAsset[(int) $row['asset_id']] = round((float) $row['total'], 2);
            }
        }

        $other = $this->db->pdo()->prepare(
            "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . ", " . self::documentRootsCte($supplierId) . "
             SELECT e.id, COALESCE(dr.root_id, e.id) AS root_id, COALESCE(dr.depth, 0) AS depth,
                    COALESCE(dr.root_document_no, e.document_no, '') AS doc,
                    COALESCE(dr.root_entry_date, e.entry_date) AS doc_date, {$net} AS total
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               " . JournalTaxOrigin::join() . "
               LEFT JOIN document_roots dr ON dr.id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND " . JournalTaxOrigin::includedSql() . "
                AND {$type} NOT IN ('depreciation', 'asset_disposal') AND a.account_code LIKE ?
              GROUP BY e.id, COALESCE(dr.root_id, e.id), COALESCE(dr.depth, 0),
                       COALESCE(dr.root_document_no, e.document_no, ''), COALESCE(dr.root_entry_date, e.entry_date)"
        );
        $other->execute([...$params, self::EXPENSE_ACCOUNT . '%']);
        $entries = [];
        $total = 0.0;
        foreach ($other->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $amount = round((float) $row['total'], 2);
            $entries[] = [
                'id' => (int) $row['id'],
                'root' => (int) $row['root_id'],
                'depth' => (int) $row['depth'],
                'doc' => mb_strtoupper(trim((string) $row['doc'])),
                'date' => substr((string) $row['doc_date'], 0, 10),
                'amount' => $amount,
            ];
            $total += $amount;
        }
        return [round($moduleTotal, 2), $byAsset, $entries, round($total, 2)];
    }

    /**
     * Kořen řetězce storen: storno (i storno storna) nese číslo dokladu a datum zápisu, který
     * ruší. Rozlišuje se vazbou `reversed_by`, ne textem („STORNO …").
     */
    private static function documentRootsCte(int $supplierId): string
    {
        return "document_roots AS (
            SELECT e.id, e.id AS root_id, 0 AS depth, e.document_no AS root_document_no, e.entry_date AS root_entry_date, e.reversed_by
              FROM journal_entries e
             WHERE e.supplier_id = {$supplierId} AND e.reversed_by IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM journal_entries p WHERE p.supplier_id = {$supplierId} AND p.reversed_by = e.id)
            UNION ALL
            SELECT r.id, d.root_id, d.depth + 1, d.root_document_no, d.root_entry_date, r.reversed_by
              FROM document_roots d
              JOIN journal_entries r ON r.id = d.reversed_by
             WHERE r.supplier_id = {$supplierId}
        )";
    }

    /**
     * @return array{0:float,1:float,2:array<int,true>,3:array<int,true>} nezaúčtované řádky
     *   bez zápisu, řádky převzatého deníku, karty s převzatým účetním a s převzatým daňovým řádkem
     */
    private function rows(int $supplierId, int $fiscalYear): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT de.asset_id, de.kind, de.amount, de.status, de.detail,
                    (SELECT COUNT(*) FROM journal_entries je
                      WHERE je.supplier_id = de.supplier_id AND je.source_type = 'depreciation'
                        AND je.source_id = de.id) AS journals
               FROM depreciation_entries de
              WHERE de.supplier_id = ? AND de.fiscal_year = ?"
        );
        $stmt->execute([$supplierId, $fiscalYear]);
        $evidence = 0.0;
        $migrated = 0.0;
        $migratedAssets = [];
        $migratedTaxAssets = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['kind'] === 'tax') {
                if (DepreciationEntryRepository::isConfirmedByMigration($row)) {
                    $migratedTaxAssets[(int) $row['asset_id']] = true;
                }
                continue;
            }
            if (DepreciationEntryRepository::isBookedByMigratedJournal($row)) {
                $migrated += (float) $row['amount'];
                $migratedAssets[(int) $row['asset_id']] = true;
            } elseif ((int) $row['journals'] === 0 && ($row['status'] ?? null) !== 'posted') {
                $evidence += (float) $row['amount'];
            }
        }
        return [round($evidence, 2), round($migrated, 2), $migratedAssets, $migratedTaxAssets];
    }

    /**
     * Převzaté účetní odpisy roku po kartách ({@see forPeriod()} `taken_over`).
     *
     * @param list<array{id:int,root:int,depth:int,doc:string,date:string,amount:float}> $entries zápisy s obratem 551 mimo modul
     * @param float $available obrat 551 mimo modul bez řádků převzatého deníku (strop odhadů)
     * @param array<int,float> $moduleByAsset odpisy modulu roku po kartách
     * @param array<int,true> $migratedAssets karty s účetním řádkem převzatého deníku za rok
     * @param array<int,true> $migratedTaxAssets karty s daňovým řádkem potvrzeným převodem
     * @return array{amount:float, tax:float, tax_by_group:array{tangible:array<int,float>,intangible:float,unclassified:float},
     *   residuals:float, estimated:array{assets:int,amount:float}, drafts:array{assets:int,amount:float,taken_over:float},
     *   disposals_unsplit:array{assets:list<string>,amount:float}, duplicates:list<array{inventory_number:string,amount:float}>}
     */
    private function takenOver(
        int $supplierId,
        string $startsOn,
        string $endsOn,
        array $entries,
        float $available,
        array $moduleByAsset,
        array $migratedAssets,
        array $migratedTaxAssets,
    ): array {
        $out = [
            'amount' => 0.0, 'tax' => 0.0,
            'tax_by_group' => ['tangible' => [], 'intangible' => 0.0, 'unclassified' => 0.0],
            'residuals' => 0.0,
            'estimated' => ['assets' => 0, 'amount' => 0.0],
            'drafts' => ['assets' => 0, 'amount' => 0.0, 'taken_over' => 0.0],
            'disposals_unsplit' => ['assets' => [], 'amount' => 0.0],
            'duplicates' => [],
        ];
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.id, a.inventory_number, a.kind, a.tax_group, a.tax_method, a.status, a.put_into_use_date,
                    a.opening_acc_months, a.opening_acc_amount, a.disposal_date, a.input_price,
                    a.asset_account_code, a.accumulated_account_code,
                    (SELECT COALESCE(SUM(i.amount), 0) FROM asset_improvements i
                      WHERE i.supplier_id = a.supplier_id AND i.asset_id = a.id) AS improvements,
                    (SELECT COUNT(*) FROM journal_entries d
                      WHERE d.supplier_id = a.supplier_id AND d.source_type = 'asset_disposal' AND d.source_id = a.id) AS module_disposal,
                    (SELECT COALESCE(SUM(de.amount), 0) FROM depreciation_entries de
                      WHERE de.supplier_id = a.supplier_id AND de.asset_id = a.id AND de.kind = 'accounting') AS accounting_total
               FROM assets a
              WHERE a.supplier_id = ?
                AND ((a.status = 'draft' AND (a.put_into_use_date IS NULL OR a.put_into_use_date <= ?))
                     OR (a.opening_acc_months > 0 AND a.put_into_use_date IS NOT NULL AND a.put_into_use_date <= ?
                         AND (a.status = 'in_use' OR (a.status = 'disposed' AND a.disposal_date >= ?))))"
        );
        $stmt->execute([$supplierId, $endsOn, $endsOn, $startsOn]);
        $cards = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($cards === []) {
            return $out;
        }
        $byDocument = [];
        foreach ($entries as $entry) {
            $byDocument[$entry['doc']][] = $entry;
        }
        $periodFirst = self::monthIndex($startsOn);
        $periodLast = self::monthIndex($endsOn);

        $attributed = [];
        $estimates = [];
        $draftEntries = [];
        foreach ($cards as $card) {
            $number = mb_strtoupper(trim((string) $card['inventory_number']));
            $own = $number !== '' ? ($byDocument[$number] ?? []) : [];
            if ($card['status'] === 'draft') {
                $out['drafts']['assets']++;
                $out['drafts']['amount'] += array_sum(array_column($own, 'amount'));
                foreach ($own as $entry) {
                    $draftEntries[] = $entry;
                }
                continue;
            }
            $assetId = (int) $card['id'];
            if (isset($migratedAssets[$assetId])) {
                continue;
            }
            $inUse = self::monthIndex((string) $card['put_into_use_date']);
            $openingTo = $inUse + (int) $card['opening_acc_months'];
            $coveredTo = min($periodLast, $openingTo);
            if ($card['disposal_date'] !== null) {
                $coveredTo = min($coveredTo, self::monthIndex((string) $card['disposal_date']));
            }
            if ($coveredTo < $periodFirst) {
                continue;
            }
            $coveredEnd = min($endsOn, self::monthEnd($coveredTo));
            // Zápis vyřazení převzaté karty může přijít i v měsíci po vyřazení.
            if ($card['disposal_date'] !== null && $openingTo >= self::monthIndex((string) $card['disposal_date'])) {
                $coveredEnd = min($endsOn, self::monthEnd($coveredTo + 1));
            }

            $covered = array_values(array_filter($own, static fn (array $e): bool => $e['date'] <= $coveredEnd));
            if ($openingTo >= $periodLast && ($moduleByAsset[$assetId] ?? 0.0) !== 0.0) {
                $out['duplicates'][] = ['inventory_number' => (string) $card['inventory_number'], 'amount' => $moduleByAsset[$assetId]];
            }
            if ($covered !== []) {
                $amount = array_sum(array_column($covered, 'amount'));
                if ($card['disposal_date'] !== null && (int) $card['module_disposal'] === 0) {
                    [$residual, $unsplit] = $this->disposalResidual($supplierId, $card, $covered);
                    $amount -= $residual + $unsplit;
                    $out['residuals'] += $residual;
                    if ($unsplit !== 0.0) {
                        $out['disposals_unsplit']['assets'][] = (string) $card['inventory_number'];
                        $out['disposals_unsplit']['amount'] += $unsplit;
                    }
                }
                $attributed[] = [$card, round($amount, 2)];
                continue;
            }
            // Odhad z počátečního stavu: měsíce roku po zařazení, kterým patří počáteční stav,
            // jen u karty, která v nich ještě odpisovala.
            $estimateFrom = max($periodFirst, $inUse + 1);
            $months = (int) $card['opening_acc_months'];
            $cost = (float) $card['input_price'] + (float) $card['improvements'];
            if ($coveredTo >= $estimateFrom && $months > 0 && (float) $card['opening_acc_amount'] < $cost - 0.005) {
                $estimates[] = [$card, (float) $card['opening_acc_amount'] * ($coveredTo - $estimateFrom + 1) / $months];
            }
        }

        // Odhady nesmí přesáhnout obrat 551, který žádné kartě nepatří: bez převzatého
        // deníku roku (nebo když ho celý rozdělí karty) se neodhaduje nic.
        $claimed = array_sum(array_map(static fn (array $a): float => $a[1], $attributed))
            + $out['drafts']['amount'] + $out['residuals'] + $out['disposals_unsplit']['amount'];
        $room = round($available - $claimed, 2);
        $wanted = array_sum(array_map(static fn (array $e): float => $e[1], $estimates));
        $factor = $room > 0.0 && $wanted > 0.0 ? min(1.0, $room / $wanted) : 0.0;
        foreach ($estimates as [$card, $estimate]) {
            $amount = round($estimate * $factor, 2);
            if ($amount === 0.0) {
                continue;
            }
            $out['estimated']['assets']++;
            $out['estimated']['amount'] += $amount;
            $attributed[] = [$card, $amount];
        }

        foreach ($attributed as [$card, $amount]) {
            $out['amount'] += $amount;
            if ($card['tax_method'] === 'by_accounting' && !isset($migratedTaxAssets[(int) $card['id']]) && $amount !== 0.0) {
                $out['tax'] += $amount;
                $group = $card['tax_group'] !== null ? (int) $card['tax_group'] : null;
                if ($card['kind'] === 'intangible') {
                    $out['tax_by_group']['intangible'] = round($out['tax_by_group']['intangible'] + $amount, 2);
                } elseif ($group !== null && $group >= 1 && $group <= 6) {
                    $out['tax_by_group']['tangible'][$group] = round(($out['tax_by_group']['tangible'][$group] ?? 0.0) + $amount, 2);
                } else {
                    $out['tax_by_group']['unclassified'] = round($out['tax_by_group']['unclassified'] + $amount, 2);
                }
            }
        }
        foreach (['amount', 'tax', 'residuals'] as $key) {
            $out[$key] = round($out[$key], 2);
        }
        $out['estimated']['amount'] = round($out['estimated']['amount'], 2);
        $out['drafts']['amount'] = round($out['drafts']['amount'], 2);
        $out['drafts']['taken_over'] = $this->takenOverAmount($supplierId, $draftEntries);
        $out['disposals_unsplit']['amount'] = round($out['disposals_unsplit']['amount'], 2);
        return $out;
    }

    /**
     * Zůstatková cena v převzatém zápisu vyřazení karty. Zápis vyřazení je zápis s číslem
     * dokladu karty v měsíci vyřazení nebo v měsíci před a po něm, který připisuje na
     * majetkový účet karty (u karty bez něj na 01x/02x). Počítá se z kořenového zápisu
     * řetězce storen a promítne se znaménkem podle zápisů řetězce v období (storno ZC vrací).
     *
     * Zůstatková cena na 551 = nejmenší z obratu 551 zápisu, připsané pořizovací ceny minus
     * oprávek odepsaných na MD 07x/08x a části 551 bez protějšku v dalu 07x/08x (řádek 551
     * spárovaný s oprávkami je odpis, ne zůstatková cena). Zápis, který ZC vede na 54x proti
     * oprávkám a jehož 551 pokrývá zbytek připsaných oprávek, má na 551 jen odpis: ZC na 551
     * ani nerozdělitelný obrat nemá ({@see DisposalResiduals} bere ZC z 54x). Nerozdělitelný je jen zápis bez
     * zůstatkové ceny v rozdílu ceny a oprávek, jehož 551 jde celé proti oprávkám, u karty,
     * která podle evidence plně odepsaná není: odpis od zůstatkové ceny odvedené přes
     * oprávky (MD 551 / D 08x, MD 08x / D 02x) z řádků rozlišit nejde.
     *
     * @param array<string,mixed> $card
     * @param list<array{id:int,root:int,depth:int,doc:string,date:string,amount:float}> $entries zápisy karty s obratem 551
     * @return array{0:float,1:float} zůstatková cena, nerozdělitelný obrat 551
     */
    private function disposalResidual(int $supplierId, array $card, array $entries): array
    {
        $month = self::monthIndex((string) $card['disposal_date']);
        $from = self::monthEnd($month - 2);
        $to = self::monthEnd($month + 1);
        $chains = [];
        foreach ($entries as $entry) {
            if ($entry['date'] > $from && $entry['date'] <= $to) {
                // Původní zápis řetězce se promítá kladně, storno záporně, storno storna kladně.
                $chains[$entry['root']] = ($chains[$entry['root']] ?? 0) + ($entry['depth'] % 2 === 0 ? 1 : -1);
            }
        }
        $chains = array_filter($chains, static fn (int $sign): bool => $sign !== 0);
        if ($chains === []) {
            return [0.0, 0.0];
        }
        $assetAccount = trim((string) ($card['asset_account_code'] ?? ''));
        $accumulated = trim((string) ($card['accumulated_account_code'] ?? ''));
        $ids = array_keys($chains);
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.entry_id, l.side, a.account_code, SUM(l.signed_amount) AS total
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND l.entry_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
              GROUP BY l.entry_id, l.side, a.account_code'
        );
        $stmt->execute([$supplierId, ...$ids]);
        $lines = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $lines[(int) $row['entry_id']][] = $row;
        }
        $matches = static fn (string $code, string $account, array $fallback): bool => $account !== ''
            ? str_starts_with($code, $account)
            : in_array(substr($code, 0, 2), $fallback, true);
        $cardResidual = round((float) $card['input_price'] + (float) $card['improvements']
            - (float) $card['opening_acc_amount'] - (float) $card['accounting_total'], 2);

        $residual = 0.0;
        $unsplit = 0.0;
        foreach ($chains as $root => $sign) {
            $assetCredit = 0.0;
            $accumulatedDebit = 0.0;
            $accumulatedCredit = 0.0;
            $expense = 0.0;
            $residualExpense = 0.0;
            foreach ($lines[$root] ?? [] as $line) {
                $code = (string) $line['account_code'];
                $value = (float) $line['total'];
                if (str_starts_with($code, self::EXPENSE_ACCOUNT)) {
                    $expense += $line['side'] === 'debit' ? $value : -$value;
                } elseif (str_starts_with($code, self::RESIDUAL_EXPENSE_PREFIX)) {
                    $residualExpense += $line['side'] === 'debit' ? $value : -$value;
                } elseif ($line['side'] === 'credit' && $matches($code, $assetAccount, ['01', '02'])) {
                    $assetCredit += $value;
                } elseif ($matches($code, $accumulated, ['07', '08'])) {
                    if ($line['side'] === 'debit') {
                        $accumulatedDebit += $value;
                    } else {
                        $accumulatedCredit += $value;
                    }
                }
            }
            if ($assetCredit < 0.005 || $expense <= 0.0) {
                continue;
            }
            if ($residualExpense >= 0.005 && $expense <= $accumulatedCredit - $residualExpense + 0.005) {
                continue;
            }
            $priceLessAccumulated = max(0.0, $assetCredit - $accumulatedDebit);
            $zc = round(min($expense, $priceLessAccumulated, max(0.0, $expense - $accumulatedCredit)), 2);
            if ($zc > 0.0) {
                $residual += $sign * $zc;
            } elseif ($priceLessAccumulated < 0.005 && $expense <= $accumulatedCredit + 0.005 && $cardResidual > 0.005) {
                $unsplit += $sign * $expense;
            }
        }
        return [round($residual, 2), round($unsplit, 2)];
    }

    /**
     * Obrat 551 zápisů, jejichž kořenový zápis (u storna původní) převzal převod z jiného
     * programu ({@see \MyInvoice\Service\Accounting\TakenOverRecord}).
     *
     * @param list<array{id:int,root:int,depth:int,doc:string,date:string,amount:float}> $entries
     */
    private function takenOverAmount(int $supplierId, array $entries): float
    {
        if ($entries === []) {
            return 0.0;
        }
        $roots = array_values(array_unique(array_column($entries, 'root')));
        $stmt = $this->db->pdo()->prepare(
            'SELECT je.id FROM journal_entries je
              WHERE je.supplier_id = ? AND je.id IN (' . implode(',', array_fill(0, count($roots), '?')) . ')
                AND ' . \MyInvoice\Service\Accounting\TakenOverRecord::journalEntrySql('je')
        );
        $stmt->execute([$supplierId, ...$roots]);
        $takenOver = array_fill_keys(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)), true);
        $sum = 0.0;
        foreach ($entries as $entry) {
            if (isset($takenOver[$entry['root']])) {
                $sum += $entry['amount'];
            }
        }
        return round($sum, 2);
    }

    private static function monthIndex(string $date): int
    {
        return ((int) substr($date, 0, 4)) * 12 + ((int) substr($date, 5, 2)) - 1;
    }

    private static function monthEnd(int $index): string
    {
        $first = sprintf('%04d-%02d-01', intdiv($index, 12), $index % 12 + 1);
        return date('Y-m-t', (int) strtotime($first));
    }

    private static function money(float $amount): string
    {
        return ($amount < 0 ? '−' : '') . number_format(abs($amount), 2, ',', ' ') . ' Kč';
    }
}

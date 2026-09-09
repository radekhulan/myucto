<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;

/**
 * Podklad pro tabulku C přílohy č. 1 II. oddílu DPPO (VetaG) — zákonné opravné položky
 * k pohledávkám a zákonné rezervy podle zákona č. 593/1992 Sb.
 *
 * Zdroje a proč zrovna ty:
 *   - STAV ke konci období bere z ROZVAHOVÝCH účtů (391 opravné položky k pohledávkám,
 *     451 rezervy podle zvláštních právních předpisů). Nákladové 552/558/559 se při
 *     uzávěrce převádějí na 710, takže kumulovaný obrat přes roky stav nedá.
 *   - TVORBA za období bere z NÁKLADOVÝCH účtů (552 zákonné rezervy, 558 zákonné OP,
 *     559 účetní OP) jako čistý obrat MD − D uvnitř období. Pokyny k tiskopisu u řádků
 *     tvorby výslovně říkají „týkají se pouze jejich tvorby, která se účtuje na vrub
 *     příslušného účtu účtové třídy Náklady. Proto nemohou tyto částky nabývat záporných
 *     hodnot." — proto se čerpání/zrušení do tvorby nezapočítává (zápor se ořízne na 0).
 *   - ROZPAD PODLE PARAGRAFU (§8 / §8a / §8b / §8c) nejde z hlavní knihy: kontace
 *     558/391 je pro všechny paragrafy stejná. Jediná evidence paragrafu je payload
 *     uzávěrkového kroku `provisions` ({@see \MyInvoice\Service\Accounting\Closing\ClosingService::runProvisions}),
 *     kde ho účetní per pohledávka potvrzuje. Co v něm není, zůstává NEZAŘAZENÉ a
 *     builder místo odhadu vydá varování.
 *
 * Tabulka C dělí položky na dvě věci, které si nelze plést: ZÁKONNÉ (ZoR) OP a rezervy
 * do ní patří, ÚČETNÍ (559/554) ne — ty jsou daňově neuznatelný náklad podle § 25 ZDP
 * a vykazují se na ř. 40 II. oddílu. Účetní část se proto vrací zvlášť, jen jako podklad
 * pro křížovou kontrolu zůstatku 391.
 */
final class LegalProvisionLedgerService
{
    /** Účet opravných položek k pohledávkám (rozvahový, kredit). */
    private const ACC_ALLOWANCE = '391';
    /** Účet rezerv podle zvláštních právních předpisů = zákonných (rozvahový, kredit). */
    private const ACC_LEGAL_RESERVE = '451';
    /** Tvorba a zúčtování zákonných rezerv (nákladový). */
    private const ACC_LEGAL_RESERVE_EXPENSE = '552';
    /** Tvorba a zúčtování zákonných opravných položek (nákladový). */
    private const ACC_LEGAL_ALLOWANCE_EXPENSE = '558';
    /** Tvorba a zúčtování účetních opravných položek (nákladový, daňově neuznatelný). */
    private const ACC_ACCT_ALLOWANCE_EXPENSE = '559';
    /** Odpis pohledávky (nákladový) — ř. 12 tabulky C, § 24 odst. 2 písm. y) ZDP. */
    private const ACC_RECEIVABLE_WRITEOFF = '546';

    /** Paragrafy ZoR, které má tabulka C jako samostatné řádky. */
    public const SECTIONS = ['8', '8a', '8b', '8c'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   allowance_balance: float,
     *   allowance_declared_total: float,
     *   allowance_declared_legal: float,
     *   allowance_declared_acct: float,
     *   allowance_by_section: array<string,float>,
     *   allowance_unassigned: float,
     *   allowance_split_reliable: bool,
     *   allowance_created_split_reliable: bool,
     *   legal_allowance_created: float,
     *   acct_allowance_created: float,
     *   legal_reserve_balance: float,
     *   legal_reserve_created: float,
     *   receivable_writeoff_deductible: float,
     *   has_activity: bool
     * }
     */
    public function forPeriod(int $supplierId, int $periodId, string $startsOn, string $endsOn): array
    {
        $allowanceBalance = $this->creditBalance($supplierId, self::ACC_ALLOWANCE, $endsOn);
        $legalReserveBalance = $this->creditBalance($supplierId, self::ACC_LEGAL_RESERVE, $endsOn);

        $legalAllowanceCreated = $this->expenseCreated($supplierId, self::ACC_LEGAL_ALLOWANCE_EXPENSE, $startsOn, $endsOn);
        $acctAllowanceCreated = $this->expenseCreated($supplierId, self::ACC_ACCT_ALLOWANCE_EXPENSE, $startsOn, $endsOn);
        $legalReserveCreated = $this->expenseCreated($supplierId, self::ACC_LEGAL_RESERVE_EXPENSE, $startsOn, $endsOn);
        $writeOff = $this->expenseCreated($supplierId, self::ACC_RECEIVABLE_WRITEOFF, $startsOn, $endsOn, true);

        [$bySection, $unassigned, $declaredLegal, $declaredAcct] = $this->declaredAllowances($supplierId, $periodId);
        $declaredTotal = round($declaredLegal + $declaredAcct, 2);

        // Rozpad podle paragrafu je použitelný, jen když deklarace kroku `provisions`
        // VYSVĚTLUJE CELÝ zůstatek účtu 391 a žádná zákonná OP nezůstala nezařazená.
        // Jinak by se do přiznání dostal § rozpad menší než skutečný stav — a tichá
        // nedopočítaná tabulka C je horší než prázdná.
        $splitReliable = $unassigned === 0.0
            && (int) round($declaredTotal * 100) === (int) round($allowanceBalance * 100);

        // Řádky TVORBY (ř. 3/6/8/10) smí nést § rozpad jen tehdy, když se celá zákonná
        // OP deklarovaná v kroku `provisions` skutečně vytvořila v TOMTO období — tedy
        // když čistá tvorba na 558 sedí na deklaraci. Provize z minulých let, které
        // v období nikdo nepřeúčtoval, do tvorby nepatří.
        $createdSplitReliable = $splitReliable
            && (int) round($legalAllowanceCreated * 100) === (int) round($declaredLegal * 100);

        $hasActivity = $allowanceBalance !== 0.0
            || $legalReserveBalance !== 0.0
            || $legalAllowanceCreated !== 0.0
            || $acctAllowanceCreated !== 0.0
            || $legalReserveCreated !== 0.0
            || $writeOff !== 0.0;

        return [
            'allowance_balance' => $allowanceBalance,
            'allowance_declared_total' => $declaredTotal,
            'allowance_declared_legal' => $declaredLegal,
            'allowance_declared_acct' => $declaredAcct,
            'allowance_by_section' => $bySection,
            'allowance_unassigned' => $unassigned,
            'allowance_split_reliable' => $splitReliable,
            'allowance_created_split_reliable' => $createdSplitReliable,
            'legal_allowance_created' => $legalAllowanceCreated,
            'acct_allowance_created' => $acctAllowanceCreated,
            'legal_reserve_balance' => $legalReserveBalance,
            'legal_reserve_created' => $legalReserveCreated,
            'receivable_writeoff_deductible' => $writeOff,
            'has_activity' => $hasActivity,
        ];
    }

    /** Prázdný podklad (firma bez účetního období / bez podvojného účetnictví). */
    public static function empty(): array
    {
        return [
            'allowance_balance' => 0.0,
            'allowance_declared_total' => 0.0,
            'allowance_declared_legal' => 0.0,
            'allowance_declared_acct' => 0.0,
            'allowance_by_section' => array_fill_keys(self::SECTIONS, 0.0),
            'allowance_unassigned' => 0.0,
            'allowance_split_reliable' => false,
            'allowance_created_split_reliable' => false,
            'legal_allowance_created' => 0.0,
            'acct_allowance_created' => 0.0,
            'legal_reserve_balance' => 0.0,
            'legal_reserve_created' => 0.0,
            'receivable_writeoff_deductible' => 0.0,
            'has_activity' => false,
        ];
    }

    /**
     * Zůstatek rozvahového účtu na straně DAL k datu (kladně = kredit), prefix match
     * na analytiky i po roll-upu na syntetiku — shodně s {@see \MyInvoice\Repository\ClosingRepository::accountBalance}.
     */
    private function creditBalance(int $supplierId, string $accountCode, string $asOf): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
               LEFT JOIN chart_of_accounts p ON p.id = a.parent_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date <= ?
                AND (a.account_code LIKE CONCAT(?, '%')
                     OR COALESCE(p.account_code, a.account_code) LIKE CONCAT(?, '%'))"
        );
        $stmt->execute([$supplierId, $asOf, $accountCode, $accountCode]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Čistá TVORBA na nákladovém účtu za období (MD − D), oříznutá zdola na nulu:
     * řádky tvorby v tabulce C nesmí být záporné (Pokyny, „K tabulce C."). Čerpání
     * a zrušení, které jde na stejný účet opačnou stranou, tedy tvorbu snižuje, ale
     * nikdy ji nepřeklopí do minusu.
     *
     * `$deductibleOnly` omezí součet na účty, které nejsou označené jako daňově
     * neuznatelné — ř. 12 tabulky C chce jen odpis UPLATNĚNÝ podle § 24 odst. 2 písm. y),
     * takže analytika „odpis pohledávky — daňově neuznatelný" tam nepatří.
     */
    private function expenseCreated(int $supplierId, string $accountCode, string $startsOn, string $endsOn, bool $deductibleOnly = false): float
    {
        $deductible = $deductibleOnly
            ? "AND COALESCE(a.tax_deductibility, 'deductible') <> 'non_deductible'"
            : '';
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
               LEFT JOIN chart_of_accounts p ON p.id = a.parent_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND NOT (e.source_type = 'closing' AND e.source_id < ?)
                {$deductible}
                AND (a.account_code LIKE CONCAT(?, '%')
                     OR COALESCE(p.account_code, a.account_code) LIKE CONCAT(?, '%'))"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn, ClosingSourceId::STOCK_SLOT_BASE, $accountCode, $accountCode]);

        return max(0.0, round((float) $stmt->fetchColumn(), 2));
    }

    /**
     * Rozpad deklarovaných opravných položek podle paragrafu z payloadu uzávěrkového
     * kroku `provisions` daného období.
     *
     * @return array{0: array<string,float>, 1: float, 2: float, 3: float}
     *         [podle paragrafu, nezařazená zákonná OP, Σ zákonných, Σ účetních]
     */
    private function declaredAllowances(int $supplierId, int $periodId): array
    {
        $bySection = array_fill_keys(self::SECTIONS, 0.0);
        $unassigned = 0.0;
        $legal = 0.0;
        $acct = 0.0;

        $stmt = $this->db->pdo()->prepare(
            "SELECT payload FROM accounting_closing_steps
              WHERE supplier_id = ? AND period_id = ? AND step_key = 'provisions' LIMIT 1"
        );
        $stmt->execute([$supplierId, $periodId]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw) || $raw === '') {
            return [$bySection, $unassigned, $legal, $acct];
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return [$bySection, $unassigned, $legal, $acct];
        }

        foreach ((array) ($payload['entries'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entryLegal = round(max(0.0, (float) ($entry['legal_amount'] ?? 0)), 2);
            $entryAcct = round(max(0.0, (float) ($entry['acct_amount'] ?? 0)), 2);
            $legal = round($legal + $entryLegal, 2);
            $acct = round($acct + $entryAcct, 2);
            if ($entryLegal === 0.0) {
                continue;
            }
            $section = (string) ($entry['legal_section'] ?? '');
            if (in_array($section, self::SECTIONS, true)) {
                $bySection[$section] = round($bySection[$section] + $entryLegal, 2);
            } else {
                $unassigned = round($unassigned + $entryLegal, 2);
            }
        }

        return [$bySection, $unassigned, $legal, $acct];
    }
}

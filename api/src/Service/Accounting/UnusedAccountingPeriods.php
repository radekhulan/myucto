<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use PDO;

/**
 * Jsou účetní období firmy nepoužitá? Založení firmy hned otevře období pro rok
 * založení ({@see AccountingPeriodProvisioner::REASON_SETUP}). Dokud ho nic nepoužilo,
 * smí ho nahradit změna účetního roku ({@see FiscalYearStartChanger}) i obnova firmy
 * z exportu MyÚčto.
 *
 * Nepoužité = otevřené, neschválené, nic na něj neodkazuje cizím klíčem (deník,
 * uzávěrka, inventura…), nemá přílohu k účetní závěrce a nezaložil ho převod
 * ({@see TakenOverRecord}).
 */
final class UnusedAccountingPeriods
{
    private readonly AccountingPeriodRepository $periods;
    private readonly TakenOverRecord $takenOver;

    public function __construct(private readonly Connection $db)
    {
        $this->periods = new AccountingPeriodRepository($db);
        $this->takenOver = new TakenOverRecord($db);
    }

    /**
     * Prázdný výsledek = všechna období firmy jsou nepoužitá (nebo žádná nejsou).
     *
     * @return list<array{fiscal_year:int, starts_on:string, ends_on:string, reasons:list<string>}>
     */
    public function blocking(int $supplierId): array
    {
        $blocking = [];
        foreach ($this->periods->listForTenant($supplierId) as $period) {
            $reasons = $this->usage($supplierId, $period);
            if ($reasons !== []) {
                $blocking[] = [
                    'fiscal_year' => (int) $period['fiscal_year'],
                    'starts_on'   => (string) $period['starts_on'],
                    'ends_on'     => (string) $period['ends_on'],
                    'reasons'     => $reasons,
                ];
            }
        }
        return $blocking;
    }

    /**
     * @param array<string,mixed> $period
     * @return list<string> čím je období použité (prázdné = nepoužité)
     */
    private function usage(int $supplierId, array $period): array
    {
        $pdo = $this->db->pdo();
        $id = (int) $period['id'];
        $reasons = [];
        if ((string) $period['status'] !== 'open' || ($period['approved_at'] ?? null) !== null) {
            $reasons[] = 'status:' . $period['status'];
        }

        // Co na období odkazuje cizím klíčem, se zjišťuje ze struktury databáze, aby
        // nová tabulka navázaná na období nezůstala mimo kontrolu.
        $refs = $pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND REFERENCED_TABLE_NAME = 'accounting_periods' AND REFERENCED_COLUMN_NAME = 'id'"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($refs as $ref) {
            $stmt = $pdo->prepare(sprintf('SELECT 1 FROM `%s` WHERE `%s` = ? LIMIT 1', $ref['TABLE_NAME'], $ref['COLUMN_NAME']));
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() !== false) {
                $reasons[] = (string) $ref['TABLE_NAME'];
            }
        }

        if ($this->db->hasTable('statement_notes')) {
            $stmt = $pdo->prepare('SELECT 1 FROM statement_notes WHERE supplier_id = ? AND fiscal_year = ? LIMIT 1');
            $stmt->execute([$supplierId, (int) $period['fiscal_year']]);
            if ($stmt->fetchColumn() !== false) {
                $reasons[] = 'statement_notes';
            }
        }

        // Období založené převodem patří převodu: smazání převodu ho uklízí podle mapy.
        if ($this->takenOver->isAccountingPeriod($supplierId, $id)) {
            $reasons[] = 'import';
        }
        return $reasons;
    }
}

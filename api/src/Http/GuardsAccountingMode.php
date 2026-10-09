<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use MyInvoice\Infrastructure\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Sdílený guard režimu účetnictví firmy (audit 2026-07, oblast de-mode, G6) —
 * /api/accounting/* je určeno jen pro accounting_mode='double_entry', symetricky
 * /api/tax-evidence/* jen pro 'tax_evidence'. Stejný vzor jako GuardsStockEnabled
 * (opt-in modul): DB dotaz + Json::error 403 přes by-ref $err, ne výjimka.
 */
trait GuardsAccountingMode
{
    protected function requireDoubleEntry(Connection $db, int $supplierId, Response $response, ?Response &$err): bool
    {
        if (!$this->accountingModeIs($db, $supplierId, 'double_entry')) {
            $err = Json::error(
                $response,
                'wrong_accounting_mode',
                'Tato funkce je dostupná jen pro firmu vedenou v podvojném účetnictví.',
                403,
            );
            return false;
        }
        $err = null;
        return true;
    }

    protected function requireTaxEvidence(Connection $db, int $supplierId, Response $response, ?Response &$err): bool
    {
        if (!$this->accountingModeIs($db, $supplierId, 'tax_evidence')) {
            $err = Json::error(
                $response,
                'wrong_accounting_mode',
                'Tato funkce je dostupná jen pro firmu vedenou v daňové evidenci.',
                403,
            );
            return false;
        }
        $err = null;
        return true;
    }

    /**
     * Funkce dostupná v obou režimech (podvojné účetnictví i daňová evidence), ale ne
     * firmě bez účetního režimu. Rozdíl chování mezi režimy řeší služba.
     */
    protected function requireAccountingMode(Connection $db, int $supplierId, Response $response, ?Response &$err): bool
    {
        if (!$this->accountingModeIs($db, $supplierId, 'double_entry')
            && !$this->accountingModeIs($db, $supplierId, 'tax_evidence')
        ) {
            $err = Json::error(
                $response,
                'wrong_accounting_mode',
                'Tato funkce je dostupná jen pro firmu vedenou v účetnictví nebo v daňové evidenci.',
                403,
            );
            return false;
        }
        $err = null;
        return true;
    }

    protected function requireTaxEvidenceForYear(
        Connection $db,
        int $supplierId,
        int $year,
        Response $response,
        ?Response &$err,
    ): bool {
        $stmt = $db->pdo()->prepare(
            'SELECT accounting_mode FROM supplier_accounting_modes
              WHERE supplier_id = ? AND effective_from <= ? ORDER BY effective_from DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, sprintf('%04d-12-31', $year)]);
        $mode = $stmt->fetchColumn();
        if (!is_string($mode) || $mode === '') {
            $fallback = $db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
            $fallback->execute([$supplierId]);
            $mode = (string) $fallback->fetchColumn();
        }
        if ($mode !== 'tax_evidence') {
            $err = Json::error(
                $response,
                'wrong_accounting_mode',
                'Tato funkce je dostupná jen pro rok vedený v daňové evidenci.',
                403,
            );
            return false;
        }
        $err = null;
        return true;
    }

    private function accountingModeIs(Connection $db, int $supplierId, string $mode): bool
    {
        $stmt = $db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (string) $stmt->fetchColumn() === $mode;
    }
}

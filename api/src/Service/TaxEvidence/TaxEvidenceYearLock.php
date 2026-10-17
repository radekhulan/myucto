<?php

declare(strict_types=1);

namespace MyInvoice\Service\TaxEvidence;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Zámek roku daňové evidence: rok s dokončenou (finální) roční uzávěrkou se už nemění.
 *
 * Jediné místo rozhodnutí pro majetek, daňové odpisy a přesuny bankovních úhrad
 * v daňové evidenci.
 * Uzávěrka je zdroj podkladů přiznání (Příloha 1 DPFO), takže změna po jejím dokončení by
 * přiznání tiše rozešla s evidencí; nejdřív se musí vrátit do rozpracovaného stavu.
 */
final class TaxEvidenceYearLock
{
    public const ERROR_CODE = 'closing_final';

    /** Zápisové cesty mohou pod transakcí vyžádat aktuální čtení a řádkový zámek. */
    public static function isFinal(Connection $db, int $supplierId, int $year, bool $forUpdate = false): bool
    {
        if ($forUpdate && !$db->pdo()->inTransaction()) {
            throw new \LogicException('Zamčení roční uzávěrky vyžaduje transakci.');
        }
        $stmt = $db->pdo()->prepare(
            "SELECT 1 FROM tax_evidence_closings WHERE supplier_id = ? AND year = ? AND status = 'final'"
                . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $year]);

        return $stmt->fetchColumn() !== false;
    }

    public static function message(int $year): string
    {
        return 'Roční uzávěrka daňové evidence ' . $year . ' je dokončená — nejdřív ji vraťte do rozpracovaného stavu.';
    }
}

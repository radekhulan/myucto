<?php

declare(strict_types=1);

namespace MyInvoice\Service\TaxEvidence;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Zámek roku daňové evidence: rok s dokončenou (finální) roční uzávěrkou se už nemění.
 *
 * Jediné místo rozhodnutí pro majetek a daňové odpisy v daňové evidenci (vyřazení, vrácení
 * vyřazení, ruční přepis, přerušení, technické zhodnocení i hromadné potvrzení odpisů roku).
 * Uzávěrka je zdroj podkladů přiznání (Příloha 1 DPFO), takže změna po jejím dokončení by
 * přiznání tiše rozešla s evidencí; nejdřív se musí vrátit do rozpracovaného stavu.
 */
final class TaxEvidenceYearLock
{
    public const ERROR_CODE = 'closing_final';

    public static function isFinal(Connection $db, int $supplierId, int $year): bool
    {
        $stmt = $db->pdo()->prepare(
            "SELECT 1 FROM tax_evidence_closings WHERE supplier_id = ? AND year = ? AND status = 'final'"
        );
        $stmt->execute([$supplierId, $year]);

        return $stmt->fetchColumn() !== false;
    }

    public static function message(int $year): string
    {
        return 'Roční uzávěrka daňové evidence ' . $year . ' je dokončená — nejdřív ji vraťte do rozpracovaného stavu.';
    }
}

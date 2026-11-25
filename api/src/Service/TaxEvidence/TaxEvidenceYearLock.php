<?php

declare(strict_types=1);

namespace MyInvoice\Service\TaxEvidence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;

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

    /**
     * Finalizace drží zámek od čtení deníku, klasifikace od ověření roku až po zápis.
     * Řádek firmy existuje i před založením první roční uzávěrky.
     *
     * @template T
     * @param callable():T $operation
     * @return T
     */
    public static function synchronized(Connection $db, int $supplierId, callable $operation): mixed
    {
        $pdo = $db->pdo();
        return TransactionFailure::retrying($pdo, static function () use ($pdo, $supplierId, $operation): mixed {
            $ownTransaction = !$pdo->inTransaction();
            if ($ownTransaction) {
                $pdo->beginTransaction();
            }
            try {
                $lock = $pdo->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE');
                $lock->execute([$supplierId]);
                $lock->closeCursor();
                $result = $operation();
                if ($ownTransaction) {
                    $pdo->commit();
                }
                return $result;
            } catch (\Throwable $e) {
                if ($ownTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        });
    }

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

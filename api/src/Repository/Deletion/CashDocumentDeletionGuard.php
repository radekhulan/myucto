<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Deletion;

/**
 * Co brání smazat pokladní doklad (PPD/VPD).
 *
 * Větev `?force=1` maže doklad i s účetními zápisy a nekontrolovala nic.
 * `payroll_payment_matches.cash_document_id` je RESTRICT, takže doklad, kterým se
 * vyplatila mzda v hotovosti, skončil syrovou FK chybou — a `mapPostingError()`
 * netypované výjimky přehazuje dál, takže z toho bylo HTTP 500.
 *
 * Kontrola platí pro OBĚ větve (draft i `?force=1`): rozhodovat nesmí stav
 * dokladu, ale existence vazby.
 */
final class CashDocumentDeletionGuard extends ForeignKeyDeletionGuard
{
    protected static function blockers(): array
    {
        return [
            'unit_payment_evidence' => [
                'message' => 'Pokladní doklad nelze smazat, protože %d jeho vazeb patří do plateb jednotek. Zkontrolujte evidenci plateb jednotek.',
                'references' => [
                    ['table' => 'unit_payment_sources', 'column' => 'cash_document_id'],
                    ['table' => 'unit_payment_workflows', 'column' => 'cash_document_id'],
                ],
            ],
            'payroll_payment_evidence' => [
                'message' => 'Pokladní doklad nelze smazat — je použit jako doklad o vyplacení mezd '
                    . '(%d vazeb). Nejdřív to spárování zrušte v Mzdy → Platby, teprve pak půjde '
                    . 'doklad smazat.',
                'references' => [
                    ['table' => 'payroll_payment_matches', 'column' => 'cash_document_id'],
                ],
            ],
        ];
    }

    public static function parentTables(): array
    {
        return ['cash_documents'];
    }

    public function conflict(int $supplierId, int $cashDocumentId): ?DeletionConflict
    {
        $counts = $this->countBlockers($supplierId, $cashDocumentId);
        if ($counts === []) {
            return null;
        }

        return new DeletionConflict('has_dependencies', self::describe($counts), $counts);
    }

    public static function raceMessage(): string
    {
        return 'Pokladní doklad nelze smazat — mezitím na něj vznikla vazba z jiné agendy '
            . '(typicky doklad o vyplacení mezd). Načtěte seznam znovu.';
    }
}

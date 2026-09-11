<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Deletion;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankTransactionReleaseService;

/**
 * Co brání smazat bankovní výpis.
 *
 * Routa dosud kontrolovala JEN výpisy ze zdroje `email_notice`/`idoklad` (a to
 * ještě na jinou věc — na spárované faktury). GPC výpisy, tedy naprostá většina,
 * neměly kontrolu žádnou. Mzdový modul přitom na výpis i jeho jednotlivé
 * transakce zapisuje `payroll_payment_matches` s cizím klíčem RESTRICT, takže
 * první takový výpis by uživateli spadl na HTTP 500 se syrovou hláškou databáze.
 *
 * ── Proč stačí počítat přes `bank_statement_id` ───────────────────────────────
 * `payroll_payment_matches` váže na výpis i na transakci dvěma cizími klíči:
 *   (supplier_id, bank_statement_id)  → bank_statements(supplier_id, id)
 *   (bank_statement_id, bank_transaction_id) → bank_transactions(statement_id, id)
 * Oba mají `bank_statement_id` jako první sloupec a CHECK
 * `chk_payroll_payment_match_evidence` vynucuje, že bankovní vazba má obě hodnoty
 * vyplněné zároveň. Součet přes `bank_statement_id` proto pokrývá i transakční
 * větev — a strukturální test hlídá, že to tak zůstane.
 *
 * ── Pohyby, které nejdou uvolnit ──────────────────────────────────────────────
 * Výpis se nemaže naslepo: každý spárovaný nebo zaúčtovaný pohyb se před smazáním
 * uvolní ({@see BankTransactionReleaseService}), jinak by po sobě nechal platbu
 * faktury bez vazby a sirotčí zápis v deníku. Pohyb, jehož zápis leží v uzavřeném
 * nebo zamčeném období (storno nejde), nebo k jehož platbě je vystavený daňový
 * doklad, smazání zastaví. Tyhle vazby nejsou cizí klíče, proto je registr
 * {@see blockers()} nezná a ptá se na ně služba uvolnění.
 */
final class BankStatementDeletionGuard extends ForeignKeyDeletionGuard
{
    private const RELEASE_MESSAGES = [
        'closed_period' => 'Výpis nelze smazat — %d jeho pohybů je zaúčtováno v uzavřeném nebo zamčeném '
            . 'období a jejich zápisy nejde stornovat. Smazáním by v deníku zůstaly zápisy bez pohybu.',
        'tax_document' => 'Výpis nelze smazat — k platbám z %d jeho pohybů je vystavený daňový doklad '
            . 'k přijaté platbě. Nejdřív ho smažte (koncept) nebo stornujte.',
        'draft_entry' => 'Výpis nelze smazat — %d jeho pohybů má v deníku rozpracovaný (nezaúčtovaný) '
            . 'zápis. Nejdřív ho v účetním deníku smažte.',
    ];

    public function __construct(
        Connection $db,
        private readonly BankTransactionReleaseService $release,
    ) {
        parent::__construct($db);
    }

    protected static function blockers(): array
    {
        return [
            'bank_api_monthly_evidence' => [
                'message' => 'Výpis je součástí průběžné měsíční evidence bankovního API (%d vazeb) a nelze jej samostatně smazat.',
                'references' => [
                    ['table' => 'bank_api_months', 'column' => 'statement_id'],
                    ['table' => 'bank_api_evidence_months', 'column' => 'evidence_statement_id'],
                    ['table' => 'bank_api_evidence_months', 'column' => 'monthly_statement_id'],
                ],
            ],
            'bank_import_evidence' => [
                'message' => 'Výpis nelze smazat, protože %d jeho pohybů je doloženo také jiným importem. Nejprve odstraňte navazující výpis.',
                'references' => [
                    ['table' => 'bank_transaction_imports', 'column' => 'original_statement_id'],
                ],
            ],
            'payroll_payment_evidence' => [
                'message' => 'Výpis nelze smazat — %d jeho položek je použito jako doklad o vyplacení '
                    . 'mezd. Nejdřív to spárování zrušte v Mzdy → Platby, teprve pak půjde výpis smazat.',
                'references' => [
                    ['table' => 'payroll_payment_matches', 'column' => 'bank_statement_id'],
                ],
            ],
        ];
    }

    public static function parentTables(): array
    {
        return ['bank_statements', 'bank_transactions'];
    }

    public function conflict(int $supplierId, int $statementId): ?DeletionConflict
    {
        $counts = $this->countBlockers($supplierId, $statementId);
        if ($counts !== []) {
            return new DeletionConflict('has_dependencies', self::describe($counts), $counts);
        }

        $release = $this->release->deletionBlockers($supplierId, $statementId);
        if ($release === []) {
            return null;
        }
        $sentences = [];
        foreach ($release as $code => $count) {
            $sentences[] = sprintf(self::RELEASE_MESSAGES[$code] ?? 'Výpis nelze smazat (%d pohybů).', $count);
        }

        return new DeletionConflict('transactions_not_releasable', implode(' ', $sentences), $release);
    }

    /**
     * Náhradní hláška pro odchycenou FK výjimku: vazba vznikla mezi kontrolou
     * a mazáním, nebo ukazuje z tabulky, která v registru chybí. Uživatel nesmí
     * dostat syrový text z databáze ani v tomhle případě.
     */
    public static function raceMessage(): string
    {
        return 'Výpis nelze smazat — mezitím na něj vznikla vazba z jiné agendy '
            . '(typicky doklad o vyplacení mezd). Načtěte seznam znovu.';
    }
}

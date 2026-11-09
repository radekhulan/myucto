<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use PDO;
use Psr\Container\ContainerInterface;

/**
 * Převod do instalace s výchozí formou zápisu (`MYINVOICE_ACCOUNTING_DEFAULT_ENTRY_FORM`).
 *
 * Konfigurace se čte při sestavení kontejneru, proto každý běh dostane vlastní kontejner
 * a běží v transakci (uvnitř transakce testu v savepointu), kterou na konci vrátí. Výsledkem je otisk deníku, který testy porovnají
 * mezi složenou a jednoduchou formou.
 */
trait EntryFormMigrationTrait
{
    /**
     * @template T
     * @param ?string $form hodnota ENV (null = výchozí instalace)
     * @param callable(ContainerInterface, PDO): T $run
     * @return T
     */
    protected function withDefaultEntryForm(?string $form, callable $run): mixed
    {
        $env = 'MYINVOICE_ACCOUNTING_DEFAULT_ENTRY_FORM';
        $previous = getenv($env);
        putenv($form === null ? $env : $env . '=' . $form);
        EntryFormService::forgetShared();
        $db = null;
        $savepoint = false;
        try {
            $container = Bootstrap::buildContainer();
            $db = $container->get(Connection::class);
            $pdo = $db->pdo();
            // Spojení může být sdílené s transakcí testu: pak běh vrací savepoint.
            $savepoint = $pdo->inTransaction();
            $savepoint ? $pdo->exec('SAVEPOINT entry_form_migration') : $pdo->beginTransaction();
            return $run($container, $pdo);
        } finally {
            if ($db !== null && $db->pdo()->inTransaction()) {
                $savepoint ? $db->pdo()->exec('ROLLBACK TO SAVEPOINT entry_form_migration') : $db->pdo()->rollBack();
            }
            putenv($previous === false ? $env : $env . '=' . $previous);
            EntryFormService::forgetShared();
        }
    }

    /**
     * Otisk deníku firmy: obraty po účtech a stranách, počty řádků s dvojicí a bez ní,
     * posouzení zápisů bez dvojic a historie formy.
     *
     * @return array{turnover:array<string,array{debit:string,credit:string}>,paired:int,unpaired:int,pair_status:int,history:list<string>,lines:int}
     */
    protected function journalFingerprint(PDO $pdo, int $supplierId): array
    {
        $stmt = $pdo->prepare(
            "SELECT a.account_code,
                    SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE 0 END) AS debit,
                    SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE 0 END) AS credit
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ?
              GROUP BY a.account_code
              ORDER BY a.account_code"
        );
        $stmt->execute([$supplierId]);
        $turnover = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $turnover[(string) $r['account_code']] = ['debit' => (string) $r['debit'], 'credit' => (string) $r['credit']];
        }
        $count = static function (string $sql) use ($pdo, $supplierId): int {
            $q = $pdo->prepare($sql);
            $q->execute([$supplierId]);
            return (int) $q->fetchColumn();
        };
        $history = $pdo->prepare('SELECT entry_form FROM accounting_entry_form_history WHERE supplier_id = ? ORDER BY valid_from');
        $history->execute([$supplierId]);
        return [
            'turnover'    => $turnover,
            'lines'       => $count('SELECT COUNT(*) FROM journal_entry_lines WHERE supplier_id = ?'),
            'paired'      => $count('SELECT COUNT(*) FROM journal_entry_lines WHERE supplier_id = ? AND pair_no IS NOT NULL'),
            'unpaired'    => $count('SELECT COUNT(*) FROM journal_entry_lines WHERE supplier_id = ? AND pair_no IS NULL'),
            'pair_status' => $count('SELECT COUNT(*) FROM journal_entry_pair_status WHERE supplier_id = ?'),
            'history'     => array_map('strval', $history->fetchAll(PDO::FETCH_COLUMN)),
        ];
    }

    /**
     * Řádky deníku bez čísla a textu dvojice, po zápisech určených `$keySql` (vrací
     * `entry_key` pro každý zápis). Ve složené i jednoduché formě musí vyjít stejně.
     *
     * @return array<string,list<string>>
     */
    protected function journalLinesByKey(PDO $pdo, int $supplierId, string $keySql): array
    {
        $stmt = $pdo->prepare(
            "SELECT k.entry_key, l.line_no, a.account_code, l.side, l.amount, l.is_red_storno, l.cost_center,
                    l.currency_code, l.fx_rate, l.amount_foreign
               FROM ({$keySql}) k
               JOIN journal_entry_lines l ON l.entry_id = k.entry_id AND l.supplier_id = ?
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              ORDER BY k.entry_key, l.line_no, l.side"
        );
        $stmt->execute([$supplierId, $supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = (string) $r['entry_key'];
            unset($r['entry_key']);
            $out[$key][] = implode('|', array_map(static fn (mixed $v): string => $v === null ? '∅' : (string) $v, $r));
        }
        ksort($out);
        return $out;
    }
}

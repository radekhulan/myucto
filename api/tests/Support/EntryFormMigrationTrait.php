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

    /** Firma, do které jde převod (daňová evidence, převod ji přepne). */
    protected function migrationFirm(PDO $pdo, string $name, string $ico, bool $vatPayer = false): int
    {
        $scalar = static fn (string $sql): int => (int) ($pdo->query($sql)->fetchColumn() ?: 0);
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, ?, ?, ?, "tax_evidence")'
        )->execute([$name, $scalar("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1"), $ico, $vatPayer ? 'CZ' . $ico : null, $vatPayer ? 1 : 0,
            $scalar("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1"), $scalar('SELECT id FROM vat_rates ORDER BY id LIMIT 1')]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    protected function firstUserId(PDO $pdo): int
    {
        return (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
    }

    /**
     * Složená forma bez nastavení instalace a jednoduchá s ním: deník (řádky bez čísla
     * dvojice), obraty po účtech a další srovnávané údaje musí vyjít stejně, jednoduchá
     * forma má všechny řádky po dvojicích a žádný zápis s posouzením bez dvojic.
     *
     * @param callable(ContainerInterface, PDO): array{fingerprint:array<string,mixed>,lines:array<string,list<string>>,compare?:mixed} $convert
     */
    protected function assertSingleMatchesComposite(callable $convert): void
    {
        $composite = $this->withDefaultEntryForm(null, $convert);
        $single = $this->withDefaultEntryForm('single', $convert);

        self::assertSame([], $composite['fingerprint']['history']);
        self::assertSame(0, $composite['fingerprint']['paired'], 'Složená forma bez nastavení instalace dvojice netvoří.');
        self::assertSame(0, $composite['fingerprint']['pair_status']);

        self::assertSame(['single'], $single['fingerprint']['history'], 'Forma single musí být zapsaná dřív, než převod zapíše deník.');
        self::assertGreaterThan(0, $single['fingerprint']['lines']);
        self::assertSame(0, $single['fingerprint']['unpaired'], 'Ve formě single má každý řádek deníku číslo dvojice.');
        self::assertSame(0, $single['fingerprint']['pair_status'], 'Žádný zápis nesmí zůstat složený (ineligible / composite_mn).');

        self::assertSame($composite['fingerprint']['turnover'], $single['fingerprint']['turnover'], 'Obraty po účtech musí být stejné jako ve složené formě.');
        self::assertSame($composite['lines'], $single['lines'], 'Převzaté řádky deníku (bez čísla dvojice) musí být stejné jako ve složené formě.');
        self::assertSame($composite['compare'] ?? null, $single['compare'] ?? null);
    }

    /**
     * Otisk deníku firmy:obraty po účtech a stranách, počty řádků s dvojicí a bez ní,
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

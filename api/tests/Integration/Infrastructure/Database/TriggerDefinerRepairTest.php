<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Infrastructure\Database;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerDefinerRepair;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `migrate.php --repair-definers` poznal jen definera, který na serveru
 * neexistuje. Po obnově kopie produkce ale definer existoval a práva měl jen
 * k původní databázi: výpočet mezd pak padal chybou 1142 a nástroj hlásil
 * „Není co opravovat".
 */
#[Group('integration')]
final class TriggerDefinerRepairTest extends TestCase
{
    public function testPrivilegeRuleNeedsEveryDataPrivilegeGloballyOrOnTheDatabase(): void
    {
        $all = ['Select_priv' => 'Y', 'Insert_priv' => 'Y', 'Update_priv' => 'Y', 'Delete_priv' => 'Y'];
        $none = ['Select_priv' => 'N', 'Insert_priv' => 'N', 'Update_priv' => 'N', 'Delete_priv' => 'N'];

        self::assertTrue(TriggerDefinerRepair::hasRequiredPrivileges($all, []));
        self::assertFalse(TriggerDefinerRepair::hasRequiredPrivileges($none, []));
        self::assertTrue(TriggerDefinerRepair::hasRequiredPrivileges($none, [$all]));
        self::assertFalse(TriggerDefinerRepair::hasRequiredPrivileges($none, [['Select_priv' => 'Y'] + $none]));
        self::assertTrue(TriggerDefinerRepair::hasRequiredPrivileges(
            ['Select_priv' => 'Y'] + $none,
            [['Insert_priv' => 'Y', 'Update_priv' => 'Y', 'Delete_priv' => 'Y'] + $none],
        ));
        self::assertSame(['app', '10.0.0.%'], TriggerDefinerRepair::splitDefiner('app@10.0.0.%'));
        self::assertSame(
            "GRANT SELECT, INSERT, UPDATE, DELETE ON `myucto_x`.* TO 'app'@'localhost';",
            TriggerDefinerRepair::grantStatement('app@localhost', 'myucto_x'),
        );
    }

    public function testDetectsDefinerWithoutPrivilegesAndRepairsItUnderCurrentUser(): void
    {
        $config = Config::load(Bootstrap::rootDir());
        $pdo = (new Connection($config))->pdo();
        $suffix = bin2hex(random_bytes(4));
        $user = 'mu_definer_' . $suffix;
        $table = 'zz_definer_repair_' . $suffix;
        $trigger = $table . '_bi';
        try {
            $pdo->exec("CREATE USER '{$user}'@'localhost'");
        } catch (\PDOException $e) {
            self::markTestSkipped('Testovací účet databáze nesmí zakládat uživatele: ' . $e->getMessage());
        }
        try {
            $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            $pdo->exec("CREATE TABLE `{$table}` (id INT PRIMARY KEY, note VARCHAR(10) NULL)");
            $pdo->exec(
                "CREATE DEFINER='{$user}'@'localhost' TRIGGER `{$trigger}` BEFORE INSERT ON `{$table}`"
                . ' FOR EACH ROW SET NEW.note = (SELECT COUNT(*) FROM `' . $table . '`)'
            );
            $repair = new TriggerDefinerRepair($pdo);

            // Účet existuje, práva k databázi nemá: zápis padá na 1142.
            try {
                $pdo->exec("INSERT INTO `{$table}` (id) VALUES (1)");
                self::fail('Trigger definera bez práv měl zápis odmítnout.');
            } catch (\PDOException $e) {
                self::assertSame(1142, (int) ($e->errorInfo[1] ?? 0), $e->getMessage());
            }
            self::assertSame(
                TriggerDefinerRepair::PROBLEM_NO_PRIVILEGES,
                $repair->definerProblem($user . '@localhost', $database),
            );
            $found = array_values(array_filter($repair->inspect()['triggers'], static fn (array $row): bool => $row['name'] === $trigger));
            self::assertSame(
                [['name' => $trigger, 'definer' => $user . '@localhost', 'problem' => TriggerDefinerRepair::PROBLEM_NO_PRIVILEGES]],
                $found,
            );

            // Práva k databázi (i přes vzor) problém odstraní.
            $pdo->exec(TriggerDefinerRepair::grantStatement($user . '@localhost', $database));
            self::assertNull($repair->definerProblem($user . '@localhost', $database));
            $pdo->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM '{$user}'@'localhost'");
            self::assertSame(TriggerDefinerRepair::PROBLEM_NO_PRIVILEGES, $repair->definerProblem($user . '@localhost', $database));

            // Oprava přepíše definera na aktuální účet a zápis projde.
            self::assertSame(1, $repair->repair($found));
            $definer = $pdo->prepare('SELECT DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = ?');
            $definer->execute([$database, $trigger]);
            $current = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
            self::assertSame($current, (string) $definer->fetchColumn());
            $pdo->exec("INSERT INTO `{$table}` (id) VALUES (2)");
            self::assertSame('0', (string) $pdo->query("SELECT note FROM `{$table}` WHERE id = 2")->fetchColumn());

            // Neexistující účet je dál „missing".
            self::assertSame(TriggerDefinerRepair::PROBLEM_MISSING, $repair->definerProblem('mu_nobody_' . $suffix . '@localhost', $database));
        } finally {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
            $pdo->exec("DROP USER IF EXISTS '{$user}'@'localhost'");
        }
    }
}

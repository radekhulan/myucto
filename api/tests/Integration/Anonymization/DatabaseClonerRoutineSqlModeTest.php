<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rutina založená pod ALLOW_INVALID_DATES (např. klon databáze pod výchozím sql_mode
 * serveru) se musí dát zkopírovat pod týmž režimem. information_schema.ROUTINES v MariaDB
 * 11.8 vypisuje tento režim jako INVALID_DATES, který server při SET sql_mode odmítne.
 */
#[Group('integration')]
final class DatabaseClonerRoutineSqlModeTest extends TestCase
{
    private PDO $server;
    /** @var list<string> */
    private array $owned = [];

    protected function setUp(): void
    {
        $config = Config::load(Bootstrap::rootDir());
        self::assertStringEndsWith('_test', (string) $config->get('db.name'));
        $this->server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            (string) $config->get('db.user'), (string) $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $token = bin2hex(random_bytes(5));
        $this->owned = ['myucto_routine_mode_' . $token . '_source_test', 'myucto_routine_mode_' . $token . '_target_test'];
    }

    protected function tearDown(): void
    {
        foreach ($this->owned as $database) $this->server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    }

    public function testRoutineKeepsAllowInvalidDatesThroughPlanAndFinalize(): void
    {
        [$source, $target] = $this->owned;
        $this->server->exec("CREATE DATABASE `{$source}`");
        $this->server->exec("CREATE DATABASE `{$target}`");
        $this->server->exec("USE `{$source}`");
        $this->server->exec("SET SESSION sql_mode = 'ALLOW_INVALID_DATES,NO_ENGINE_SUBSTITUTION'");
        $this->server->exec('CREATE PROCEDURE p_synthetic() SELECT 1');
        $this->server->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");

        $cloner = new DatabaseCloner($this->server);
        $plan = $cloner->plan($source);
        self::assertSame(['ALLOW_INVALID_DATES,NO_ENGINE_SUBSTITUTION'], $plan['routine_sql_modes']);
        $cloner->finalize($plan, $target);

        $mode = $this->server->prepare('SHOW CREATE PROCEDURE `' . $target . '`.p_synthetic');
        $mode->execute();
        self::assertSame('ALLOW_INVALID_DATES,NO_ENGINE_SUBSTITUTION', $mode->fetch(PDO::FETCH_ASSOC)['sql_mode']);
        self::assertSame('STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', (string) $this->server->query('SELECT @@SESSION.sql_mode')->fetchColumn());
    }
}

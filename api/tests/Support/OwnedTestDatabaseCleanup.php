<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Service\Anonymization\DatabaseCloner;
use PDO;
use PDOException;

final class OwnedTestDatabaseCleanup
{
    public static function drop(PDO $server, string $schema, array $ownedSchemas): void
    {
        if (!in_array($schema, $ownedSchemas, true)
            || preg_match('/\Amyucto_[a-z0-9_]+_test\z/D', $schema) !== 1) {
            throw new \LogicException('Cleanup requires an explicitly owned test database.');
        }
        for ($attempt = 0; ; ++$attempt) {
            try {
                $server->exec('DROP DATABASE IF EXISTS ' . DatabaseCloner::quote($schema));
                return;
            } catch (PDOException $error) {
                if ($attempt >= 4 || (int) ($error->errorInfo[1] ?? 0) !== 1010
                    || stripos($error->getMessage(), 'Directory not empty') === false) {
                    throw $error;
                }
                usleep(($attempt + 1) * 50_000);
            }
        }
    }
}

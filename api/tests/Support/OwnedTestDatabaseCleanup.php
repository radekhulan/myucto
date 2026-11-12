<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Service\Anonymization\DatabaseCloner;
use PDO;

final class OwnedTestDatabaseCleanup
{
    public static function drop(PDO $server, string $schema, array $ownedSchemas): void
    {
        if (!in_array($schema, $ownedSchemas, true)
            || preg_match('/\Amyucto_[a-z0-9_]+_test\z/D', $schema) !== 1) {
            throw new \LogicException('Cleanup requires an explicitly owned test database.');
        }
        DatabaseCloner::dropDatabase($server, $schema);
    }
}

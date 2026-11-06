<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class SecuritiesTaxConstantsCurrentTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function testCurrentReadSeesChangedOverrideAndPreviouslyMissingYear(bool $missing): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $pdo = $db->pdo();
        $year = 2197;
        $previous = $pdo->query('SELECT data FROM tax_constants WHERE year=2197')->fetchColumn();
        $isolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $other = null;
        try {
            $pdo->exec('DELETE FROM tax_constants WHERE year=2197');
            if (!$missing) $container->get(TaxConstantsRepository::class)->upsert($year, ['entity_category_thresholds' => ['micro' => ['assets_net' => 11000000]]]);
            $pdo->exec('SET SESSION innodb_snapshot_isolation=OFF');
            $pdo->beginTransaction();
            $repo = $container->get(TaxConstantsRepository::class);
            $before = $repo->forYear($year);
            $otherContainer = Connection::withoutSharedTestConnection(static function () {
                $c = Bootstrap::buildContainer();
                $c->get(Connection::class)->pdo();
                return $c;
            });
            $other = $otherContainer->get(Connection::class);
            self::assertNotSame($pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), $other->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
            $otherContainer->get(TaxConstantsRepository::class)->upsert($year, ['entity_category_thresholds' => ['micro' => ['assets_net' => 12345678]]]);
            self::assertSame($before, $repo->forYear($year));
            $current = $repo->forYear($year, true);
            self::assertSame(12345678, $current['entity_category_thresholds']['micro']['assets_net']);
            self::assertTrue($pdo->inTransaction());
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $other?->close();
            $pdo->exec('DELETE FROM tax_constants WHERE year=2197');
            if ($previous !== false) $pdo->prepare('INSERT INTO tax_constants(year,data) VALUES (2197,?)')->execute([$previous]);
            $pdo->exec('SET SESSION innodb_snapshot_isolation=' . $isolation);
        }
    }
}

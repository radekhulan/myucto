<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\Receivables\PropertyPartyReconciliation;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\TestCase;

final class PropertyPartyPaginationTest extends TestCase
{
    use ChargeIssuanceFixture;

    public function testSjmAndFractionalCoownersStayWholeAcrossBothPageSizes(): void
    {
        $db = Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test', (string) $db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $db->pdo()->beginTransaction();
        try {
            PropertyInstant::withNormalizationContext(function () use ($db): void {
                $f = $this->issuanceFixture($db);
                $request = $f['issue_request'];
                $request['units'] = array_values(array_filter($request['units'],
                    fn (array $unit): bool => in_array($unit['unit_id'], [$f['units']['U01'], $f['units']['U02']], true)));
                $owner = Bootstrap::buildContainer()->get(IssuanceRepository::class);
                $preview = (new IssuancePreviewService($db))->preview($f['supplier'], $request);
                $batch = $owner->issue($f['supplier'], ['request' => $request, 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'party-page-issue'], $f['actor']);
                $owner->post($f['supplier'], $batch['id'], array_map(
                    fn (array $charge): array => ['id' => $charge['id'], 'row_version' => $charge['row_version']], $batch['charges']), $f['actor']);
                $expected = [];
                foreach (['U01', 'U02'] as $code) {
                    $parties = array_merge(...array_column($f['states'][$code]['ownerships'], 'members'));
                    sort($parties, SORT_NUMERIC);
                    $expected[implode('+', $parties)] = ['2380000', '0'];
                }
                ksort($expected);
                $reader = new PropertyPartyReconciliation($db);
                $firstOrder = null;
                foreach ([1, 2] as $limit) {
                    $rows = [];
                    $cursor = null;
                    do {
                        $page = $reader->at($f['supplier'], '2026-12-31', $limit, $cursor);
                        self::assertSame($cursor === null ? ['receivable' => '4760000', 'credit' => '0'] : null, $page['totals']);
                        self::assertLessThanOrEqual($limit, count($page['items']));
                        foreach ($page['items'] as $row) {
                            $key = implode('+', $row['party_ids']);
                            self::assertArrayNotHasKey($key, $rows);
                            $rows[$key] = [$row['receivable_cents'], $row['credit_cents']];
                        }
                        if ($page['next_cursor'] !== null) self::assertNotSame($cursor, $page['next_cursor']);
                        $cursor = $page['next_cursor'];
                    } while ($cursor !== null);
                    $firstOrder ??= array_keys($rows);
                    self::assertSame($firstOrder, array_keys($rows));
                    ksort($rows);
                    self::assertSame($expected, $rows);
                }
            });
        } finally {
            if ($db->pdo()->inTransaction()) $db->pdo()->rollBack();
        }
    }
}

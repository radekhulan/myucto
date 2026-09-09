<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Stock\StockReferenceGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityReportReferenceTest extends TestCase
{
    public static function malformedIds(): iterable
    {
        foreach (['2abc', '2-1', '2e', true, false, [2], ['id' => 2], 'no-id', -2, 2.5, INF, NAN] as $i => $value) {
            yield (string) $i => [$value];
        }
    }

    #[DataProvider('malformedIds')]
    public function testTenantGuardRejectsMalformedReferences(mixed $value): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');
        self::assertSame(['vendor_id'], (new TenantReferenceGuard($db))->violations(1, ['vendor_id' => $value], ['vendor_id']));
    }

    #[DataProvider('malformedIds')]
    public function testStockGuardRejectsMalformedReferences(mixed $value): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');
        self::assertNotEmpty((new StockReferenceGuard($db))->violations(1, ['purchase_invoice_id' => [$value]]));
    }

    public function testEmptyOptionalReferencesRemainAllowed(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');
        foreach ([null, '', 0, '0'] as $value) {
            self::assertSame([], (new TenantReferenceGuard($db))->violations(1, ['vendor_id' => $value], ['vendor_id']));
            self::assertSame([], (new StockReferenceGuard($db))->violations(1, ['purchase_invoice_id' => [$value]]));
        }
    }

    public function testGlobalCodebookWritesRequireSuperadmin(): void
    {
        $map = new RoutePermissionMap();
        foreach (['/api/settings/vat-rates', '/api/settings/units', '/api/settings/countries', '/api/accounting/repo-rates'] as $path) {
            foreach (['POST', 'PUT', 'DELETE'] as $method) {
                foreach ([$path, $path . '/2'] as $target) {
                    self::assertSame(RoutePermissionMap::SUPERADMIN, $map->match($method, $target)?->kind, "$method $target");
                }
            }
            self::assertSame(RoutePermissionMap::PERMISSION, $map->match('GET', $path)?->kind);
        }
    }

    public function testDocumentUploadCannotGrantUnrelatedMutations(): void
    {
        $map = new RoutePermissionMap();
        foreach (['restore' => 'documents.restore', 'move' => 'documents.move', 'links' => 'documents.move'] as $action => $key) {
            self::assertSame($key, $map->match('POST', '/api/documents/12/' . $action)?->key);
        }
    }
}

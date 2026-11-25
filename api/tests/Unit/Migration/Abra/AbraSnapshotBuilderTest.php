<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Abra;

use MyInvoice\Service\Migration\Abra\AbraReadOnlyClient;
use MyInvoice\Service\Migration\Abra\AbraException;
use MyInvoice\Service\Migration\Abra\AbraSnapshotBuilder;
use PHPUnit\Framework\TestCase;

final class AbraSnapshotBuilderTest extends TestCase
{
    public function testCrossYearInvoiceLookupUsesTypedLinkReference(): void
    {
        $wanted = AbraSnapshotBuilder::linkedInvoiceKeys([
            'banka' => [['id' => 20]], 'pokladni-pohyb' => [],
            'vazba' => [[
                'id' => 40, 'a' => '999', 'a@ref' => '/c/demo/banka/20.json',
                'b' => '888', 'b@ref' => '/c/demo/faktura-prijata/31.json',
            ], [
                'id' => 41, 'a@ref' => '/c/demo/banka/20.json',
                'b@ref' => '/c/demo/prodejka/32.json',
            ], [
                'id' => 42, 'a@ref' => '/c/demo/banka/20.json',
                'b@ref' => '/c/demo/zavazek/33.json',
            ]],
        ]);

        self::assertSame([31 => true], $wanted['faktura-prijata']);
        self::assertSame([32 => true], $wanted['prodejka']);
        self::assertSame([33 => true], $wanted['zavazek']);
    }

    public function testFiscalPeriodWithoutYearInCodeUsesStartingYear(): void
    {
        $client = new FakeAbraReadOnlyClient();
        $client->changesEnabled = false;
        $client->rows['ucetni-obdobi'] = [[
            'id' => 1, 'kod' => 'FY', 'platiOdData' => '2025-07-01', 'platiDoData' => '2026-06-30',
        ]];

        $snapshot = (new AbraSnapshotBuilder($client))->build(
            self::credentials(), [2025], 'initial', [],
            ['ico' => '12345678', 'name' => 'Test s.r.o.', 'base_currency' => 'code:CZK'],
            static function (): void {}, static fn (): bool => false,
        );

        self::assertCount(1, $snapshot['_meta']['periods']);
        self::assertSame('FY', $snapshot['_meta']['periods'][0]['kod']);
    }

    public function testInitialSnapshotReplaysChangesAndKeepsReferencedCrossYearInvoice(): void
    {
        $client = new FakeAbraReadOnlyClient();
        $client->globalVersion = 10;
        $client->rows['ucetni-obdobi'] = [
            ['id' => 1, 'platiOdData' => '2024-01-01', 'platiDoData' => '2024-12-31'],
            ['id' => 2, 'platiOdData' => '2023-01-01', 'platiDoData' => '2023-12-31'],
        ];
        $client->rows['ucetni-osnova'] = [['id' => 1, 'kod' => '311']];
        $client->rows['ucetni-denik'] = [
            ['id' => 10, 'datUcto' => '2024-03-05'],
            ['id' => 11, 'datUcto' => '2023-03-05'],
        ];
        $client->rows['banka'] = [[
            'id' => 20,
            'datUcto' => '2024-04-01',
        ]];
        $client->rows['vazba'] = [[
            'id' => 40,
            'a' => 'Bankovní doklad', 'a@ref' => '/c/demo/banka/20.json',
            'b' => 'Vydaná faktura', 'b@ref' => '/c/demo/faktura-vydana/31.json',
        ]];
        $client->rows['faktura-vydana'] = [
            ['id' => 30, 'kod' => 'CURRENT-2024', 'datUcto' => '2024-02-01'],
            ['id' => 31, 'kod' => 'CROSS-2023', 'datUcto' => '2023-12-20'],
            ['id' => 32, 'kod' => 'OLD-2022', 'datUcto' => '2022-12-20'],
        ];
        $client->changes[11] = [
            '@globalVersion' => '12',
            'changes' => [['@evidence' => 'faktura-vydana', '@operation' => 'update', 'id' => '30']],
            'next' => 'none',
        ];
        $client->changes[13] = ['@globalVersion' => '12', 'changes' => [], 'next' => 'none'];

        $snapshot = (new AbraSnapshotBuilder($client))->build(
            self::credentials(),
            [2024],
            'initial',
            [],
            ['ico' => '12345678', 'name' => 'Test s.r.o.', 'base_currency' => 'code:CZK'],
            static function (): void {},
            static fn (): bool => false,
        );

        self::assertSame([10], array_column($snapshot['ucetni-denik'], 'id'));
        self::assertSame([30, 31], array_column($snapshot['faktura-vydana'], 'id'));
        self::assertSame([40], array_column($snapshot['vazba'], 'id'));
        self::assertSame(1, $snapshot['_meta']['cross_year_referenced']['faktura-vydana']);
        self::assertSame('changes', $snapshot['_meta']['sync_state']['strategy']);
        self::assertSame(12, $snapshot['_meta']['sync_state']['cursor']);
        self::assertSame([2024], $snapshot['_meta']['years']);
        self::assertSame('CZK', $snapshot['_meta']['company']['base_currency']);
        self::assertCount(1, $snapshot['_meta']['periods']);
        self::assertSame('1', (string) $client->queries['stav-uctu']['idUcetniObdobi']);
        self::assertSame('polozkyDokladu,vazby,vazebni-doklady', $client->queries['faktura-vydana']['relations']);
        self::assertContains(
            "datUcto >= '2024-01-01' and datUcto <= '2024-12-31'",
            array_column($client->queryCalls['faktura-vydana'], 'filter'),
        );
        self::assertSame('false', $client->queries['adresar']['filtrovat-platnost']);
        self::assertGreaterThanOrEqual(2, $client->listCalls['faktura-vydana']);
        self::assertContains('id in (31)', array_column($client->queryCalls['faktura-vydana'], 'filter'));
        foreach (AbraSnapshotBuilder::EVIDENCES as $evidence) {
            self::assertArrayHasKey($evidence, $snapshot);
        }
    }

    public function testVatProjectionIsAggregatedForSelectedDocumentsAndFiscalYear(): void
    {
        $client = new FakeAbraReadOnlyClient();
        $client->changesEnabled = false;
        $client->rows['ucetni-obdobi'] = [[
            'id' => 1, 'kod' => '2026', 'platiOdData' => '2026-01-01', 'platiDoData' => '2026-12-31',
        ]];
        $client->rows['faktura-prijata'] = [[
            'id' => 101, 'datUcto' => '2026-05-03',
        ]];
        $client->rows['podklady-dph'] = [
            ['datUcto' => '2026-05-03', 'rokDuzp' => 2026, 'mesicDuzp' => 5,
                'idDokl@evidencePath' => 'faktura-prijata', 'idDokl' => 101,
                'clenDph' => 'code:07-08, 43-44', 'sumZklTuz' => 60, 'vypSumDphTuz' => 0],
            ['datUcto' => '2026-05-03', 'rokDuzp' => 2026, 'mesicDuzp' => 5,
                'idDokl@evidencePath' => 'faktura-prijata', 'idDokl' => 101,
                'clenDph' => 'code:07-08, 43-44', 'sumZklTuz' => 40, 'vypSumDphTuz' => 0],
            ['datUcto' => '2026-05-03', 'rokDuzp' => 2026, 'mesicDuzp' => 5,
                'idDokl@evidencePath' => 'faktura-prijata', 'idDokl' => 102,
                'clenDph' => 'code:07-08, 43-44', 'sumZklTuz' => 999, 'vypSumDphTuz' => 0],
        ];
        $snapshot = (new AbraSnapshotBuilder($client))->build(self::credentials(), [2026], 'initial', [],
            ['ico' => '88888888', 'base_currency' => 'CZK'],
            static function (): void {}, static fn (): bool => false);

        self::assertSame(1, $client->listCalls['podklady-dph']);
        self::assertCount(1, $snapshot['_meta']['vat_projection']);
        self::assertSame([['year' => 2026, 'month' => 5, 'class' => '07-08, 43-44',
            'base' => 100.0, 'vat' => 0.0, 'rows' => 2]],
            $snapshot['_meta']['vat_projection']['faktura-prijata|101']);
    }

    public function testIncrementalVatProjectionUsesOnlyChangedDocumentMonth(): void
    {
        $client = new FakeAbraReadOnlyClient();
        $client->changesEnabled = false;
        $client->rows['ucetni-obdobi'] = [[
            'id' => 1, 'kod' => '2026', 'platiOdData' => '2026-01-01', 'platiDoData' => '2026-12-31',
        ]];
        $client->rows['faktura-prijata'] = [['id' => 101, 'datUcto' => '2026-05-03']];
        $client->rows['podklady-dph'] = [[
            'datUcto' => '2026-05-04', 'rokDuzp' => 2026, 'mesicDuzp' => 5,
            'idDokl@evidencePath' => 'faktura-prijata', 'idDokl' => 101,
            'clenDph' => 'code:40-41', 'sumZklTuz' => 100, 'vypSumDphTuz' => 21,
        ]];
        $snapshot = (new AbraSnapshotBuilder($client))->build(self::credentials(), [2026], 'sync',
            ['strategy' => 'last_update', 'watermark' => '2026-05-05T12:00:00+00:00'],
            ['ico' => '88888888', 'base_currency' => 'CZK'],
            static function (): void {}, static fn (): bool => false);

        self::assertSame("datUcto >= '2026-05-01' and datUcto <= '2026-05-31'",
            $client->queries['podklady-dph']['filter']);
        self::assertCount(1, $snapshot['_meta']['vat_projection']['faktura-prijata|101']);
    }

    public function testSyncExcludesUnrelatedHistoricalLinkAndKeepsImportedEndpoint(): void
    {
        $client = new FakeAbraReadOnlyClient();
        $client->rows['ucetni-obdobi'] = [['id' => 1, 'kod' => '2024',
            'platiOdData' => '2024-01-01', 'platiDoData' => '2024-12-31']];
        $client->rows['vazba'] = [
            ['id' => 40, 'a@ref' => '/c/demo/banka/20.json', 'b@ref' => '/c/demo/faktura-vydana/31.json'],
            ['id' => 41, 'a@ref' => '/c/demo/banka/99.json', 'b@ref' => '/c/demo/faktura-vydana/98.json'],
        ];
        $client->rows['faktura-vydana'] = [
            ['id' => 31, 'datUcto' => '2023-12-20'],
            ['id' => 98, 'datUcto' => '2023-12-21'],
        ];
        $client->changes[21] = ['@globalVersion' => '22', 'changes' => [
            ['@evidence' => 'vazba', '@operation' => 'update', 'id' => '40'],
            ['@evidence' => 'vazba', '@operation' => 'update', 'id' => '41'],
        ], 'next' => 'none'];

        $builder = new AbraSnapshotBuilder($client);
        $builder->useImportedEndpointKeys(['banka|20' => true, 'faktura-vydana|31' => true]);
        $snapshot = $builder->build(self::credentials(), [2024], 'sync',
            ['strategy' => 'changes', 'cursor' => 20], ['ico' => '12345678'],
            static function (): void {}, static fn (): bool => false);

        self::assertSame([40], array_column($snapshot['vazba'], 'id'));
        self::assertSame([31], array_column($snapshot['faktura-vydana'], 'id'));
        self::assertContains('id in (31)', array_column($client->queryCalls['faktura-vydana'], 'filter'));
        self::assertNotContains('id in (98)', array_column($client->queryCalls['faktura-vydana'], 'filter'));
        self::assertSame(1, $snapshot['_meta']['excluded_by_period']['vazba']);
    }

    public function testSingleYearImportExcludesPaymentLinkToMovementInFollowingYear(): void
    {
        $client = new FakeAbraReadOnlyClient();
        $client->changesEnabled = false;
        $client->rows['ucetni-obdobi'] = [
            ['id' => 1, 'kod' => '2025', 'platiOdData' => '2025-01-01', 'platiDoData' => '2025-12-31'],
            ['id' => 2, 'kod' => '2026', 'platiOdData' => '2026-01-01', 'platiDoData' => '2026-12-31'],
        ];
        $client->rows['faktura-vydana'] = [['id' => 101, 'datUcto' => '2025-12-20']];
        $client->rows['banka'] = [['id' => 201, 'datUcto' => '2026-01-04']];
        $client->rows['vazba'] = [[
            'id' => 301, 'a@ref' => '/c/demo/faktura-vydana/101.json',
            'b@ref' => '/c/demo/banka/201.json', 'castka' => '100', 'mena' => 'code:CZK',
        ]];

        $snapshot = (new AbraSnapshotBuilder($client))->build(self::credentials(), [2025], 'initial', [],
            ['ico' => '12345678'], static function (): void {}, static fn (): bool => false);

        self::assertCount(1, $snapshot['faktura-vydana']);
        self::assertSame([], $snapshot['banka']);
        self::assertSame([], $snapshot['vazba']);
    }

    public function testNumericMovementLinkMatchesInternalIdNotYearInDocumentNumber(): void
    {
        $client = new FakeAbraReadOnlyClient();
        $client->changesEnabled = false;
        $client->rows['ucetni-obdobi'] = [
            ['id' => 1, 'kod' => '2020', 'platiOdData' => '2020-01-01', 'platiDoData' => '2020-12-31'],
            ['id' => 2, 'kod' => '2024', 'platiOdData' => '2024-01-01', 'platiDoData' => '2024-12-31'],
            ['id' => 3, 'kod' => '2025', 'platiOdData' => '2025-01-01', 'platiDoData' => '2025-12-31'],
            ['id' => 4, 'kod' => '2026', 'platiOdData' => '2026-01-01', 'platiDoData' => '2026-12-31'],
        ];
        $client->rows['faktura-vydana'] = [
            ['id' => 501, 'datUcto' => '2024-12-15'],
            ['id' => 502, 'datUcto' => '2020-03-01'],
        ];
        $client->rows['banka'] = [
            ['id' => 71, 'kod' => 'BANK-01/2025', 'datUcto' => '2025-01-10'],
            ['id' => 2025, 'kod' => 'BANK-01/0042', 'datUcto' => '2020-03-05'],
        ];
        $client->rows['vazba'] = [
            ['id' => 801, 'a@ref' => '/c/demo/faktura-vydana/501.json',
                'b@ref' => '/c/demo/banka/71.json', 'castka' => '100', 'mena' => 'code:CZK'],
            ['id' => 802, 'a@ref' => '/c/demo/faktura-vydana/502.json',
                'b@ref' => '/c/demo/banka/2025.json', 'castka' => '100', 'mena' => 'code:CZK'],
        ];

        $snapshot = (new AbraSnapshotBuilder($client))->build(self::credentials(), [2025, 2026], 'initial', [],
            ['ico' => '12345678'], static function (): void {}, static fn (): bool => false);

        self::assertSame([71], array_column($snapshot['banka'], 'id'));
        self::assertSame([801], array_column($snapshot['vazba'], 'id'));
        self::assertSame([501], array_column($snapshot['faktura-vydana'], 'id'));
    }

    public function testInitialImportExcludesPaymentLinkToCancelledMovement(): void
    {
        $client = new FakeAbraReadOnlyClient();
        $client->changesEnabled = false;
        $client->rows['ucetni-obdobi'] = [[
            'id' => 1, 'kod' => '2025', 'platiOdData' => '2025-01-01', 'platiDoData' => '2025-12-31',
        ]];
        $client->rows['faktura-vydana'] = [['id' => 101, 'datUcto' => '2025-06-01']];
        $client->rows['banka'] = [['id' => 201, 'datUcto' => '2025-06-02', 'storno' => true]];
        $client->rows['vazba'] = [[
            'id' => 301, 'a@ref' => '/c/demo/faktura-vydana/101.json',
            'b@ref' => '/c/demo/banka/201.json', 'castka' => '100', 'mena' => 'code:CZK',
        ]];

        $snapshot = (new AbraSnapshotBuilder($client))->build(self::credentials(), [2025], 'initial', [],
            ['ico' => '12345678'], static function (): void {}, static fn (): bool => false);

        self::assertTrue($snapshot['banka'][0]['storno']);
        self::assertSame([], $snapshot['vazba']);
    }

    public function testIncrementalPaymentLinksUseIdCursorInsteadOfUnsupportedLastUpdate(): void
    {
        $client = new FakeAbraReadOnlyClient();
        $client->changesEnabled = false;
        $client->rows['ucetni-obdobi'] = [[
            'id' => 1, 'kod' => '2025', 'platiOdData' => '2025-01-01', 'platiDoData' => '2025-12-31',
        ]];
        $client->rows['faktura-vydana'] = [['id' => 101, 'datUcto' => '2025-06-01']];
        $client->rows['banka'] = [['id' => 201, 'datUcto' => '2025-06-02']];
        $client->rows['vazba'] = [[
            'id' => 301, 'a@ref' => '/c/demo/faktura-vydana/101.json',
            'b@ref' => '/c/demo/banka/201.json', 'castka' => '100', 'mena' => 'code:CZK',
        ]];

        $snapshot = (new AbraSnapshotBuilder($client))->build(self::credentials(), [2025], 'sync',
            ['strategy' => 'last_update', 'watermark' => '2025-06-05T12:00:00+00:00', 'vazba_cursor' => 300],
            ['ico' => '12345678'], static function (): void {}, static fn (): bool => false);

        self::assertSame('id > 300', $client->queries['vazba']['filter']);
        self::assertSame(301, $snapshot['_meta']['sync_state']['vazba_cursor']);
    }

    public function testChangesSyncReportsDeletionAndUnknownEvidenceWithoutSourceRowsInWarning(): void
    {
        $client = new FakeAbraReadOnlyClient();
        $client->changes[21] = [
            '@globalVersion' => '25',
            'changes' => [
                ['@evidence' => 'faktura-prijata', '@operation' => 'delete', 'id' => '998877'],
                ['@evidence' => 'skladova-karta', '@operation' => 'update', 'id' => 'secret-record'],
            ],
            'next' => 'none',
        ];
        $client->changes[26] = ['@globalVersion' => '25', 'changes' => [], 'next' => 'none'];

        $snapshot = (new AbraSnapshotBuilder($client))->build(
            self::credentials(),
            [2024],
            'sync',
            ['strategy' => 'changes', 'cursor' => 20],
            ['ico' => '12345678', 'name' => 'Test s.r.o.'],
            static function (): void {},
            static fn (): bool => false,
        );

        self::assertSame(1, $snapshot['_meta']['deletions']['faktura-prijata']);
        self::assertSame(1, $snapshot['_meta']['unhandled_changes']['skladova-karta']);
        $warnings = implode(' ', $snapshot['_meta']['warnings']);
        self::assertStringContainsString('smazaných záznamů', $warnings);
        self::assertStringContainsString('nepodporované evidence', $warnings);
        self::assertStringNotContainsString('998877', $warnings);
        self::assertStringNotContainsString('secret-record', $warnings);
    }

    public function testFallbackUsesOverlappedLastUpdateAndMarksDeletionsUntracked(): void
    {
        $client = new FakeAbraReadOnlyClient();
        $client->changesEnabled = false;

        $snapshot = (new AbraSnapshotBuilder($client))->build(
            self::credentials(),
            [2024],
            'sync',
            ['strategy' => 'last_update', 'watermark' => '2026-09-27T12:00:00+00:00', 'overlap_seconds' => 300],
            ['ico' => '12345678', 'name' => 'Test s.r.o.'],
            static function (): void {},
            static fn (): bool => false,
        );

        self::assertSame('last_update', $snapshot['_meta']['sync_state']['strategy']);
        self::assertFalse($snapshot['_meta']['sync_state']['deletions_tracked']);
        self::assertStringContainsString("2026-09-27T11:55:00+00:00", (string) $client->queries['adresar']['filter']);
        self::assertStringContainsString('nezachytí smazané záznamy', implode(' ', $snapshot['_meta']['warnings']));
    }

    public function testFailedYearFilteredEvidenceDoesNotRetryAcrossAllYears(): void
    {
        $client = new FakeAbraReadOnlyClient();
        $client->changesEnabled = false;
        $client->failFilteredEvidence = 'faktura-vydana';
        $client->rows['ucetni-obdobi'] = [
            ['id' => 1, 'kod' => '2026', 'platiOdData' => '2026-01-01', 'platiDoData' => '2026-12-31'],
        ];

        $snapshot = (new AbraSnapshotBuilder($client))->build(
            self::credentials(), [2026], 'sync',
            ['strategy' => 'last_update', 'watermark' => '2026-09-27T12:00:00+00:00'],
            ['ico' => '12345678'], static function (): void {}, static fn (): bool => false,
        );

        self::assertCount(1, $client->queryCalls['faktura-vydana']);
        self::assertStringContainsString('2026-01-01', (string) $client->queryCalls['faktura-vydana'][0]['filter']);
        self::assertContains('faktura-vydana', $snapshot['_meta']['unavailable']);
    }

    public function testFailedPaymentLinkDeltaDoesNotReadAllHistoricalLinks(): void
    {
        $client = new FakeAbraReadOnlyClient();
        $client->changesEnabled = false;
        $client->failFilteredEvidence = 'vazba';

        $snapshot = (new AbraSnapshotBuilder($client))->build(
            self::credentials(), [2026], 'sync',
            ['strategy' => 'last_update', 'watermark' => '2026-09-27T12:00:00+00:00'],
            ['ico' => '12345678'], static function (): void {}, static fn (): bool => false,
        );

        self::assertCount(1, $client->queryCalls['vazba']);
        self::assertArrayHasKey('filter', $client->queryCalls['vazba'][0]);
        self::assertContains('vazba', $snapshot['_meta']['unavailable']);
    }

    public function testNonAccountingChangeDoesNotReloadAccountingControls(): void
    {
        $client = new FakeAbraReadOnlyClient();
        $client->changes[31] = [
            '@globalVersion' => '31',
            'changes' => [['@evidence' => 'adresar', '@operation' => 'update']],
            'next' => 'none',
        ];
        $client->changes[32] = ['@globalVersion' => '31', 'changes' => [], 'next' => 'none'];

        (new AbraSnapshotBuilder($client))->build(
            self::credentials(),
            [2024],
            'sync',
            ['strategy' => 'changes', 'cursor' => 30],
            ['ico' => '12345678', 'name' => 'Test s.r.o.'],
            static function (): void {},
            static fn (): bool => false,
        );

        self::assertSame(1, $client->listCalls['adresar']);
        self::assertArrayNotHasKey('ucetni-denik', $client->listCalls);
        self::assertArrayNotHasKey('pohyb-na-uctech', $client->listCalls);
        self::assertArrayNotHasKey('stav-uctu', $client->listCalls);
    }

    public function testDuplicateFiscalYearPeriodsStopBeforeFurtherEvidenceReads(): void
    {
        $client = new FakeAbraReadOnlyClient();
        $client->changesEnabled = false;
        $client->rows['ucetni-obdobi'] = [
            ['id' => 1, 'kod' => '2024', 'platiOdData' => '2024-01-01', 'platiDoData' => '2024-12-31'],
            ['id' => 2, 'kod' => '2024_2', 'platiOdData' => '2024-07-01', 'platiDoData' => '2025-06-30'],
        ];

        try {
            (new AbraSnapshotBuilder($client))->build(
                self::credentials(),
                [2024],
                'initial',
                [],
                ['ico' => '12345678', 'name' => 'Test s.r.o.'],
                static function (): void {},
                static fn (): bool => false,
            );
            self::fail('Ambiguous fiscal periods must stop the snapshot.');
        } catch (AbraException $e) {
            self::assertSame('ambiguous_period', $e->errorCode);
        }
    }

    /** @return array{url:string,username:string,password:string} */
    private static function credentials(): array
    {
        return ['url' => 'https://example.test/c/test', 'username' => 'reader', 'password' => 'synthetic'];
    }

    public function testInternalDocumentChangeRefreshesOriginalJournal(): void
    {
        $client = new FakeAbraReadOnlyClient();
        $client->rows['ucetni-obdobi'] = [['id' => 1, 'kod' => '2024', 'platiOdData' => '2024-01-01', 'platiDoData' => '2024-12-31']];
        $client->rows['ucetni-denik'] = [['idUcetniDenik' => '93', 'datUcto' => '2024-06-01']];
        $client->changes[21] = ['@globalVersion' => '22', 'changes' => [
            ['@evidence' => 'interni-doklad', '@operation' => 'create', 'id' => '42'],
        ], 'next' => 'none'];
        $snapshot = (new AbraSnapshotBuilder($client))->build(self::credentials(), [2024], 'sync',
            ['strategy' => 'changes', 'cursor' => 20], ['ico' => '88888888', 'base_currency' => 'CZK'],
            static function (): void {}, static fn (): bool => false);
        self::assertSame('93', $snapshot['ucetni-denik'][0]['idUcetniDenik']);
        self::assertSame(1, $client->listCalls['ucetni-denik']);
        self::assertSame(22, $snapshot['_meta']['sync_state']['cursor']);
    }

    public function testPageProgressKeepsLongExportAlive(): void
    {
        $client = new FakeAbraReadOnlyClient();
        $client->changesEnabled = false;
        $client->rows['adresar'] = [['id' => 1], ['id' => 2]];
        $events = [];
        (new AbraSnapshotBuilder($client))->build(self::credentials(), [2024], 'initial', [], [],
            static function (string $step, int $done, int $all) use (&$events): void { $events[] = [$step, $done, $all]; },
            static fn (): bool => false);
        self::assertContains(['adresar', 2, 2], $events);
    }
}

final class FakeAbraReadOnlyClient extends AbraReadOnlyClient
{
    public bool $changesEnabled = true;
    public int $globalVersion = 0;
    /** @var array<string,list<array<string,mixed>>> */
    public array $rows = [];
    /** @var array<int,array<string,mixed>> */
    public array $changes = [];
    /** @var array<string,int> */
    public array $listCalls = [];
    /** @var array<string,array<string,mixed>> */
    public array $queries = [];
    /** @var array<string,list<array<string,mixed>>> */
    public array $queryCalls = [];
    public ?string $failFilteredEvidence = null;

    public function __construct() {}

    public function get(array $credentials, string $evidence, array $query = []): array
    {
        return ['winstrom' => ['@globalVersion' => (string) $this->globalVersion, $evidence => []]];
    }

    public function list(array $credentials, string $evidence, array $query = [],
        ?callable $progress = null, ?callable $cancelled = null, ?callable $consume = null): array
    {
        $this->listCalls[$evidence] = ($this->listCalls[$evidence] ?? 0) + 1;
        $this->queries[$evidence] = $query;
        $this->queryCalls[$evidence][] = $query;
        if ($evidence === $this->failFilteredEvidence && isset($query['filter'])) {
            throw new AbraException('abra_http_400', 'Synthetic invalid filter.');
        }
        $rows = $this->rows[$evidence] ?? [];
        $filter = (string) ($query['filter'] ?? '');
        if (preg_match("/datUcto >= '([^']+)' and datUcto <= '([^']+)'/", $filter, $match) === 1) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool
                => is_string($row['datUcto'] ?? null)
                    && $row['datUcto'] >= $match[1]
                    && $row['datUcto'] <= $match[2]));
        }
        if (preg_match('/id in \(([0-9,]+)\)/', $filter, $match) === 1) {
            $ids = array_fill_keys(array_map('intval', explode(',', $match[1])), true);
            $rows = array_values(array_filter($rows, static fn (array $row): bool
                => isset($ids[(int) ($row['id'] ?? 0)])));
        }
        if ($progress !== null) $progress(count($rows), count($rows));
        if ($consume !== null) { $consume($rows, 0, count($rows)); return []; }
        return $rows;
    }

    public function changesStatus(array $credentials): bool
    {
        return $this->changesEnabled;
    }

    public function changes(array $credentials, int|string $cursor): array
    {
        $root = $this->changes[(int) $cursor] ?? [
            '@globalVersion' => (string) max(0, (int) $cursor - 1),
            'changes' => [],
            'next' => 'none',
        ];
        return ['winstrom' => $root];
    }
}

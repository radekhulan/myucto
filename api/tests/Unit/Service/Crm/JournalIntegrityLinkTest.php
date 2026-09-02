<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Crm;

use MyInvoice\Service\Crm\CrmAggregationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Deep-link z dashboard karty „Zkontroluj integritu deníku" na konkrétní nález.
 *
 * `journal_integrity_findings.detail` nese JSON vzorek nálezů (migrace 1034 ho
 * zavádí výslovně „pro rozklik/diagnostiku"), ale karta ho dřív nečetla a
 * odkazovala natvrdo na `/accounting/journal` — uživatel skončil na nefiltrovaném
 * seznamu celého deníku a musel nález hledat ručně.
 *
 * Volba query parametru je daná typem nálezu a NENÍ zaměnitelná:
 *   - `booked_without_entry` nemá `entry_id` (zápis z definice neexistuje),
 *   - `orphan_entry` má `entry_id`, ale `source_id` ukazuje na neexistující doklad.
 * Proto má `entry_id` přednost a `source_type`+`source_id` je až fallback.
 */
final class JournalIntegrityLinkTest extends TestCase
{
    private const BASE = '/accounting/journal';

    private static function link(int $count, mixed $detail, string $findingType = ''): string
    {
        $m = new \ReflectionMethod(CrmAggregationService::class, 'journalIntegrityLink');
        $svc = (new \ReflectionClass(CrmAggregationService::class))->newInstanceWithoutConstructor();
        return (string) $m->invoke($svc, $count, $detail, $findingType);
    }

    /**
     * @return array<string, array{int, mixed, string}>
     */
    public static function linkCases(): array
    {
        $amountMismatch = json_encode([[
            'entry_id' => 59354, 'source_type' => 'purchase_invoice',
            'source_id' => 21306, 'document_no' => null, 'expected' => 36863.01,
        ]]);
        // booked_without_entry: doklad má booked_at, ale zápis neexistuje → bez entry_id.
        $bookedWithoutEntry = json_encode([[
            'source_type' => 'invoice', 'source_id' => 4242,
            'document_no' => '2026-0042', 'booked_at' => '2026-03-01 10:00:00',
        ]]);
        // orphan_entry: zápis existuje, doklad ne → source_id je nepoužitelný.
        $orphanEntry = json_encode([[
            'entry_id' => 777, 'source_type' => 'invoice', 'source_id' => 999999,
        ]]);

        return [
            'amount_mismatch → entry_id'        => [1, $amountMismatch, self::BASE . '?entry_id=59354'],
            'booked_without_entry → source'     => [1, $bookedWithoutEntry, self::BASE . '?source_type=invoice&source_id=4242'],
            'orphan_entry → entry_id má přednost' => [1, $orphanEntry, self::BASE . '?entry_id=777'],
            'více nálezů → bez filtru'          => [3, $amountMismatch, self::BASE],
            'detail NULL → bez filtru'          => [1, null, self::BASE],
            'detail prázdný → bez filtru'       => [1, '', self::BASE],
            'detail není JSON → bez filtru'     => [1, 'nonsense', self::BASE],
            'detail prázdné pole → bez filtru'  => [1, '[]', self::BASE],
            'neznámý source_type → bez filtru'  => [1, json_encode([['source_type' => 'cash_document', 'source_id' => 5]]), self::BASE],
            'nulové id → bez filtru'            => [1, json_encode([['entry_id' => 0, 'source_type' => 'invoice', 'source_id' => 0]]), self::BASE],
        ];
    }

    #[DataProvider('linkCases')]
    public function testLink(int $count, mixed $detail, string $expected): void
    {
        self::assertSame($expected, self::link($count, $detail));
    }

    /**
     * Víc nálezů `amount_mismatch` → filtr deníku. Deep-link umí jen JEDEN zápis,
     * takže bez filtru uživatel skončil na nefiltrovaném deníku a neměl jak zjistit,
     * které zápisy nesedí (na ostrých datech jich bylo 368).
     */
    public function testManyAmountMismatchFindingsLinkToJournalFilter(): void
    {
        $detail = json_encode([['entry_id' => 59354, 'source_type' => 'purchase_invoice', 'source_id' => 21306]]);
        self::assertSame(
            self::BASE . '?integrity=amount_mismatch',
            self::link(368, $detail, 'amount_mismatch'),
        );
    }

    /** Ostatní typy filtr nemají → chovají se jako dřív (nefiltrovaný deník). */
    public function testManyFindingsOfOtherTypeStillLinkToPlainJournal(): void
    {
        $detail = json_encode([['entry_id' => 777, 'source_type' => 'invoice', 'source_id' => 999999]]);
        self::assertSame(self::BASE, self::link(5, $detail, 'orphan_entry'));
    }

    /**
     * Parametry, které deep-link používá, musí stránka deníku skutečně číst.
     * Guard proti tichému rozpadu kontraktu při refaktoru frontendu.
     */
    public function testJournalPageReadsLinkedQueryParams(): void
    {
        $page = file_get_contents(dirname(__DIR__, 5) . '/web/src/pages/accounting/Journal.vue');
        self::assertIsString($page);
        foreach (['route.query.entry_id', 'route.query.source_id', 'hydrateFilters(route.query)', "value('source_type')", "value('integrity')"] as $param) {
            self::assertStringContainsString($param, $page, "Journal.vue přestal číst {$param} — deep-link z dashboardu je mrtvý.");
        }
    }
}

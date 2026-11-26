<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Closing\CheckFindingNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Sjednocení tvaru nálezů kontrol uzávěrky a měsíční kontroly.
 *
 * Kontroly vracely nálezy v devíti různých tvarech (`id` vs `doc_id`, `saldo` vs
 * `residual` vs `bal`, `partner_name` vs `partner`), takže frontend nemohl mít jeden
 * renderer a byl napsaný jako WHITELIST podle klíče kontroly. Každá nová kontrola tak
 * automaticky spadla do `JSON.stringify` — 16 z 33 na stránce měsíční kontroly, 29 z 33
 * na uzávěrkové. Renderer podle klíče nemůže být úplný, protože klíčů přibývá.
 *
 * Nejdůležitější je {@see testCountStaysFullAfterCapping()}: strop smí zkrátit SEZNAM,
 * ale nikdy ne POČET. Kdyby se počítalo z ořezaného pole, z „1 843 nálezů" by se stalo
 * „50 nálezů" a účetní by viděla lež — nebezpečnější než původní JSON.
 */
final class CheckFindingNormalizerTest extends TestCase
{
    private CheckFindingNormalizer $n;

    protected function setUp(): void
    {
        $this->n = new CheckFindingNormalizer();
    }

    /** @param array<string,mixed> $value */
    private function check(string $key, array $value): array
    {
        return ['key' => $key, 'severity' => 'warning', 'ok' => false, 'value' => $value];
    }

    /** Tři různé názvy částky se sjednotí na `amount`. */
    public function testAmountAliasesAreUnified(): void
    {
        $saldo = $this->n->normalize($this->check('paid_invoices_open_saldo', [
            'count' => 1, 'items' => [['id' => 7, 'doc_no' => 'FV1', 'partner_name' => 'A', 'saldo' => 1210.0]],
        ]));
        $residual = $this->n->normalize($this->check('realized_fx_unbooked', [
            'count' => 1, 'items' => [['id' => 9, 'doc_type' => 'invoice', 'doc_no' => 'FX1', 'partner' => 'B', 'residual' => -300.0]],
        ]));
        $bal = $this->n->normalize($this->check('clearing_accounts_open', [
            'count' => 1, 'accounts' => [['account_id' => 3, 'account_code' => '395', 'name' => 'X', 'bal' => 42.0]],
        ]));

        self::assertSame(1210.0, $saldo['value']['findings'][0]['amount']);
        self::assertSame(-300.0, $residual['value']['findings'][0]['amount']);
        self::assertSame(42.0, $bal['value']['findings'][0]['amount']);
    }

    /** `partner` i `partner_name` skončí jako `partner_name`. */
    public function testPartnerAliasesAreUnified(): void
    {
        $r = $this->n->normalize($this->check('realized_fx_unbooked', [
            'items' => [['id' => 1, 'doc_type' => 'invoice', 'partner' => 'Firma s.r.o.']],
        ]));

        self::assertSame('Firma s.r.o.', $r['value']['findings'][0]['partner_name']);
    }

    /** Typ dokladu se vezme z položky, když ho nese; jinak ze statické mapy kontroly. */
    public function testDocTypeComesFromItemOrCheckKey(): void
    {
        $perItem = $this->n->normalize($this->check('settled_but_unpaid', [
            'items' => [['id' => 1, 'doc_type' => 'purchase_invoice']],
        ]));
        $static = $this->n->normalize($this->check('unposted_invoices', ['ids' => [5, 6]]));

        self::assertSame('purchase_invoice', $perItem['value']['findings'][0]['doc_type']);
        self::assertSame('invoice', $static['value']['findings'][0]['doc_type']);
        self::assertSame(5, $static['value']['findings'][0]['doc_id']);
    }

    /**
     * STROP zkracuje seznam, ne počet. Tohle je ta past, kvůli které se ořezává až nad
     * hotovým polem a ne LIMITem v SQL.
     */
    public function testCountStaysFullAfterCapping(): void
    {
        $items = [];
        for ($i = 1; $i <= 120; $i++) {
            $items[] = ['id' => $i, 'doc_no' => 'D' . $i, 'saldo' => 1.0];
        }

        $r = $this->n->normalize($this->check('paid_invoices_open_saldo', ['items' => $items]), 50);

        self::assertSame(120, $r['value']['count'], 'Počet musí zůstat skutečný.');
        self::assertCount(50, $r['value']['findings']);
        self::assertTrue($r['value']['truncated']);
    }

    /** Pod stropem se nic neořezává a `truncated` je false. */
    public function testBelowCapIsNotTruncated(): void
    {
        $r = $this->n->normalize($this->check('paid_invoices_open_saldo', [
            'items' => [['id' => 1], ['id' => 2]],
        ]), 50);

        self::assertSame(2, $r['value']['count']);
        self::assertFalse($r['value']['truncated']);
    }

    /** `recap()` přeřízne snímek, ale počet nechá — payload kroku nesmí lhát o rozsahu. */
    public function testRecapKeepsFullCount(): void
    {
        $items = array_map(static fn ($i) => ['id' => $i], range(1, 120));
        $full = $this->n->normalizeAll([$this->check('paid_invoices_open_saldo', ['items' => $items])], 50);

        $snapshot = $this->n->recap($full, 10);

        self::assertCount(10, $snapshot[0]['value']['findings']);
        self::assertSame(120, $snapshot[0]['value']['count']);
        self::assertTrue($snapshot[0]['value']['truncated']);
    }

    /** Skalární kontrola (jen zůstatek) zůstává beze změny — není co normalizovat. */
    public function testScalarCheckIsUntouched(): void
    {
        $in = $this->check('internal_395_open', ['account' => '395', 'balance' => 12.5]);

        self::assertSame($in, $this->n->normalize($in));
    }

    /** Hodnota, která není pole (textový hint), projde beze změny. */
    public function testNonArrayValueIsUntouched(): void
    {
        $in = ['key' => 'income_tax_hint', 'severity' => 'info', 'ok' => true, 'value' => 'Text.'];

        self::assertSame($in, $this->n->normalize($in));
    }

    /**
     * `groups` se rozbalí na jeden nález per KARTA majetku. Zploštění, které by zahodilo
     * `asset_ids`, by uživateli sebralo jedinou cestu, jak kartu najít — a ponechat je
     * nenormalizované by po zrušení vlastního rendereru dalo počet a prázdnou tabulku.
     */
    public function testAssetGroupsAreFlattenedPerCard(): void
    {
        $r = $this->n->normalize($this->check('assets_without_accumulated_depreciation', [
            'count' => 1,
            'groups' => [[
                'asset_account_code' => '022', 'accumulated_account_code' => '082',
                'asset_balance' => 50000.0, 'asset_ids' => [4, 5],
            ]],
        ]));

        self::assertCount(2, $r['value']['findings'], 'Dvě karty = dva nálezy.');
        self::assertSame('asset', $r['value']['findings'][0]['doc_type']);
        self::assertSame(4, $r['value']['findings'][0]['doc_id']);
        self::assertSame('022 / 082', $r['value']['findings'][0]['note']);
        self::assertSame(2, $r['value']['count'], 'Počet je počet KARET, ne skupin.');
    }

    /** Doprovodné skalární klíče přežijí vedle seznamu — nesou vlastní informaci. */
    public function testCompanionScalarsSurvive(): void
    {
        $r = $this->n->normalize($this->check('cnb_rate_deviation', [
            'count' => 1, 'items' => [['doc_id' => 1, 'doc_type' => 'invoice']], 'missing_cnb_count' => 3,
        ]));

        self::assertSame(3, $r['value']['missing_cnb_count']);
        self::assertArrayNotHasKey('items', $r['value'], 'Starý klíč zmizí, aby FE neměl dvě cesty.');
    }

    /** Samostatný zápis v deníku vedle dokladu zůstává dohledatelný. */
    public function testSeparateEntryIdIsKept(): void
    {
        $r = $this->n->normalize($this->check('cancelled_with_entry', [
            'items' => [['id' => 10, 'source_type' => 'invoice', 'entry_id' => 99]],
        ]));

        self::assertSame(10, $r['value']['findings'][0]['doc_id']);
        self::assertSame(99, $r['value']['findings'][0]['entry_id']);
    }

    public function testJournalLinkIsOmittedOnlyForTheSameJournalEntry(): void
    {
        $r = $this->n->normalize($this->check('asset_sale_without_derecognition', [
            'items' => [
                ['doc_type' => 'invoice', 'doc_id' => 1, 'entry_id' => 1],
                ['doc_type' => 'journal_entry', 'doc_id' => 2, 'entry_id' => 2],
                ['doc_type' => 'journal_entry', 'doc_id' => 3, 'entry_id' => 4],
            ],
        ]));

        self::assertSame([1, null, 4], array_column($r['value']['findings'], 'entry_id'));
    }

    /** Cap 0 = bez ořezu (CSV export musí dostat všechno). */
    public function testZeroCapReturnsEverything(): void
    {
        $items = array_map(static fn ($i) => ['id' => $i], range(1, 300));

        $r = $this->n->normalize($this->check('paid_invoices_open_saldo', ['items' => $items]), 0);

        self::assertCount(300, $r['value']['findings']);
        self::assertFalse($r['value']['truncated']);
    }
}

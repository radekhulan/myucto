<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Anonymization;

use MyInvoice\Service\Anonymization\Nonprofit\J6CloneLegacyInputTransformer;
use MyInvoice\Service\Anonymization\Nonprofit\J6CloneTypedTransformer;
use MyInvoice\Service\Anonymization\Pseudonymizer;
use MyInvoice\Service\Anonymization\TextScrubber;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotPageV2;
use MyInvoice\Service\Tax\Nonprofit\SourceFacts\MembershipJ6SourceFacts;
use PHPUnit\Framework\TestCase;

final class J6CloneTypedTransformerTest extends TestCase
{
    public function testHistoricSourcePageKeepsAxisCodeByteExactAndScrubsOnlyName(): void
    {
        $external = ['version' => 3, 'payload' => ['email' => 'external-owner@example.test'],
            'hash' => hash('sha256', 'external-owner@example.test')];
        $row = $this->sourceRow($external);
        $page = $this->transformer()->sourcePage(new SnapshotPageV2([$row], [], null, null, null, 1));
        $rebased = $page->rows[0];

        self::assertSame(50010, $rebased['amount_cents']);
        self::assertSame('2026-10-19', $rebased['date']);
        self::assertSame(77, $rebased['origin']['line_id']);
        self::assertSame($external, $rebased['provenance']['external_receipt']);
        foreach (['activity', 'activity_kind', 'tax_regime'] as $axis) {
            self::assertSame($row['axes'][$axis]['code'], $rebased['axes'][$axis]['code']);
            self::assertSame($row['axes'][$axis]['semantic_key'], $rebased['axes'][$axis]['semantic_key']);
            self::assertNotSame($row['axes'][$axis]['name'], $rebased['axes'][$axis]['name']);
            self::assertStringNotContainsString('@example.test', $rebased['axes'][$axis]['name']);
        }
    }

    public function testLegacySourcePageKeepsAxisCodeAndScrubsName(): void
    {
        $legacy = new J6CloneLegacyInputTransformer($this->transformer());
        $source = $this->legacySource();
        $page = $legacy->transformPage('source', [$source]);

        self::assertSame(50010, $page[0]['amount_cents']);
        self::assertSame('HC-01 code-owner@example.test', $page[0]['axes']['activity']['code']);
        self::assertStringNotContainsString('@example.test', $page[0]['axes']['activity']['name']);
        self::assertFalse($legacy->permitsIdentityChange('source', ['axes', 'activity', 'name'],
            $source['axes']['activity']['name'], $page[0]['axes']['activity']['name']));
    }

    public function testLegacyPageTransformerIsBoundedAndAuthorizesOnlyFreshDerivedHash(): void
    {
        $legacy = new J6CloneLegacyInputTransformer($this->transformer());
        $result = ['id' => 'line:77', 'amount_cents' => 50010, 'evidence' => 'private-result@example.test',
            'result_row_hash' => hash('sha256', 'old-result')];
        $rebased = $legacy->transformPage('result', [$result]);

        self::assertSame(50010, $rebased[0]['amount_cents']);
        self::assertStringNotContainsString('@example.test', $rebased[0]['evidence']);
        self::assertNotSame($result['result_row_hash'], $rebased[0]['result_row_hash']);
        self::assertTrue($legacy->permitsIdentityChange('result', ['result_row_hash'],
            $result['result_row_hash'], $rebased[0]['result_row_hash']));
        self::assertFalse($legacy->permitsIdentityChange('result', ['result_row_hash'],
            $result['result_row_hash'], $rebased[0]['result_row_hash']));
        self::assertFalse($legacy->permitsIdentityChange('result', ['amount_cents'], 50010, 50011));

        $this->expectException(\InvalidArgumentException::class);
        $legacy->transformPage('result', array_fill(0, 501, $result));
    }

    public function testLegacyMembershipFactsWithoutExactHashMapFailClosed(): void
    {
        $legacy = new J6CloneLegacyInputTransformer($this->transformer());
        $source = $this->legacySource();
        $source['module_facts'] = [MembershipJ6SourceFacts::SCHEMA => ['operation_id' => 1]];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('exact scrub hash map');
        $legacy->transformPage('source', [$source]);
    }

    public function testUnknownTypedStringFieldFailsClosed(): void
    {
        $record = ['source_id' => 'line:77', 'payload' => ['title' => 'rent', 'untyped_text' => 'private@example.test'],
            'record_hash' => hash('sha256', 'old')];

        $this->expectException(\UnexpectedValueException::class);
        $this->transformer()->decisionRecord('source', $record);
    }

    private function transformer(): J6CloneTypedTransformer
    {
        return new J6CloneTypedTransformer(new TextScrubber(new Pseudonymizer(hash('sha256', 'j6-clone-test', true))));
    }

    private function legacySource(): array
    {
        return ['id' => 'line:77', 'line_id' => 77, 'kind' => 'revenue', 'amount_cents' => 50010,
            'activity' => 'main', 'activity_kind_id' => 2, 'tax_regime' => 'subject',
            'mandatory_non_deductible' => false, 'module_facts' => [],
            'axes' => ['activity' => ['type_id' => 1, 'value_id' => 2, 'code' => 'HC-01 code-owner@example.test',
                'name' => 'private-axis@example.test']]];
    }

    private function sourceRow(array $external): array
    {
        $axis = static fn (int $type, int $value, string $semantic, string $code, string $name): array => [
            'type_id' => $type, 'value_id' => $value, 'semantic_key' => $semantic, 'code' => $code,
            'name' => $name, 'assignment_origin' => 'direct', 'assignment_origin_ref' => 'line:77'];
        return ['id' => 'line:77', 'line_id' => 77, 'entry_id' => 70, 'line_no' => 1, 'account_id' => 60,
            'account_code' => '602100', 'date' => '2026-10-19', 'kind' => 'revenue', 'amount_cents' => 50010,
            'activity' => 'main', 'activity_kind_id' => 2, 'tax_regime' => 'subject',
            'mandatory_non_deductible' => false, 'neutral' => false,
            'axes' => ['activity' => $axis(1, 1, 'main', 'HC-01 axis-owner@example.test', 'private-axis@example.test'),
                'activity_kind' => $axis(2, 2, 'membership', 'CLEN kind-owner@example.test', 'private-kind@example.test'),
                'tax_regime' => $axis(3, 3, 'subject', 'subject regime-owner@example.test', 'private-regime@example.test')],
            'provenance' => ['external_receipt' => $external],
            'origin' => ['type' => 'membership', 'id' => 51, 'line_id' => 77],
            'source_type' => 'membership', 'source_id' => 51, 'allocation' => null, 'module_facts' => []];
    }
}

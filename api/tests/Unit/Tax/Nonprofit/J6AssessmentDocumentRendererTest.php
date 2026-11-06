<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentDocumentRenderer;
use PHPUnit\Framework\TestCase;

final class J6AssessmentDocumentRendererTest extends TestCase
{
    public function testPdfAndXlsxContainOnlyFixedSummaryAndExactBridge(): void
    {
        $renderer = new J6AssessmentDocumentRenderer();
        $payload = $this->payload();
        $tables = $renderer->tables($payload);
        self::assertSame(['Posouzení', 'Přechod výsledku'], array_keys($tables));
        self::assertCount(14, $tables['Posouzení'][1]);
        self::assertSame(['Výsledek klasifikačního posouzení', '400.00'], $tables['Přechod výsledku'][1][3]);
        self::assertStringStartsWith('%PDF-', $renderer->render($payload));
        self::assertStringStartsWith('PK', $renderer->xlsx($payload));
    }

    public function testBridgeMustReconcileExactly(): void
    {
        $payload = $this->payload();
        $payload['bridge']['assessed_result_cents']++;
        $this->expectException(\UnexpectedValueException::class);
        (new J6AssessmentDocumentRenderer())->tables($payload);
    }

    private function payload(): array
    {
        return ['schema' => 'myucto-nonprofit-tax-summary.v2',
            'scope' => 'classification_bridge_before_general_adjustments',
            'assessment' => ['id' => 9, 'supplier_id' => 3, 'period_id' => 4, 'revision_no' => 2,
                'status' => 'confirmed', 'reason' => 'Syntetické potvrzení', 'ruleset' => 'nonprofit-j6-rules-2026.1',
                'created_by' => 7, 'created_at' => '2026-10-19 09:00:00.000000',
                'source_input_hash' => str_repeat('a', 64), 'snapshot_input_hash' => str_repeat('b', 64),
                'receipt_hash' => str_repeat('c', 64)],
            'period' => ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'],
            'profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy'],
            'bridge' => ['accounting_result_cents' => 100000, 'excluded_revenue_cents' => 60000,
                'cost_addbacks_cents' => 0, 'assessed_result_cents' => 40000],
            'current_confirmed' => true, 'full_evidence_format' => 'application/x-ndjson'];
    }
}

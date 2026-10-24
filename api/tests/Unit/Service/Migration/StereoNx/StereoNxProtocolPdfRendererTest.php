<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Pdf\StereoNxProtocolPdfRenderer;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

final class StereoNxProtocolPdfRendererTest extends TestCase
{
    public function testFailedDryRunIncludesAllCountsAndReviewDetailsWithoutInternalData(): void
    {
        $html = (new StereoNxProtocolPdfRenderer())->renderHtml($this->data());
        self::assertStringContainsString('Neúspěšné', $html);
        self::assertStringContainsString('Plánovaný zápis', $html);
        self::assertStringContainsString('tento protokol nepotvrzuje zápis dat', $html);
        self::assertStringContainsString('31.08.2026', $html);
        self::assertStringContainsString('Drobný majetek - ve zdroji', $html);
        self::assertStringContainsString('>0</td>', $html);
        self::assertStringContainsString('SYNTHETIC-REVIEW-1', $html);
        self::assertStringContainsString('Syntetická tabulka', $html);
        self::assertStringNotContainsString('PRIVATE-KEY-NEVER-PRINT', $html);
        self::assertStringNotContainsString('PRIVATE-PARAM-NEVER-PRINT', $html);
        self::assertStringContainsString('&lt;img src=&quot;https://example.invalid/image&quot;&gt;', $html);
        self::assertStringNotContainsString('<img ', $html);
    }

    public function testEnglishImportDistinguishesWrittenResultAndReconciliation(): void
    {
        $data = $this->data();
        $data['mode'] = 'import';
        $data['language'] = 'en';
        $data['report']['ok'] = true;
        $data['report']['database_writes'] = true;
        $data['report']['reconciliation'] = [['year' => 2026, 'ok' => false,
            'checks' => [['key' => 'stereo_nx_journal', 'ok' => false]],
            'journal_diffs' => [['account' => '501', 'myucto' => [0, 125.5, 125.5], 'money' => [0, 125.0, 125.0]]]]];
        $html = (new StereoNxProtocolPdfRenderer())->renderHtml($data);
        self::assertStringContainsString('Write results', $html);
        self::assertStringContainsString('08/31/2026', $html);
        self::assertStringContainsString('Does not match', $html);
        self::assertStringContainsString('0.00 / 125.50 / 125.50', $html);
        self::assertStringNotContainsString('this report does not confirm', $html);
    }

    public function testInfoMessageDoesNotTurnSuccessfulRunIntoWarning(): void
    {
        $data = ['report' => ['ok' => true, 'preflight' => [['level' => 'info', 'message' => 'Synthetic information']]]];
        $html = (new StereoNxProtocolPdfRenderer())->renderHtml($data);
        self::assertStringContainsString('<strong>Dokončeno</strong>', $html);
        self::assertStringNotContainsString('Dokončeno s upozorněními', $html);
    }

    public function testReconstructedItemsInfoInWarningsCollectionRemainsInformational(): void
    {
        $data = ['report' => ['ok' => true, 'warnings' => [['level' => 'info',
            'code' => 'document_items_reconstructed', 'message' => 'Syntetické řádky vytvořeny z deníku.']]]];
        $html = (new StereoNxProtocolPdfRenderer())->renderHtml($data);
        self::assertStringContainsString('<strong>Dokončeno</strong>', $html);
        self::assertStringNotContainsString('Dokončeno s upozorněními', $html);
        self::assertStringContainsString('Syntetické řádky vytvořeny z deníku.', $html);
    }

    public function testLegacyReviewDetailsAffectStatusAndZeroSummariesAreHidden(): void
    {
        $renderer = new StereoNxProtocolPdfRenderer();
        $legacy = $renderer->renderHtml(['report' => ['ok' => true, 'review_documents' => [[
            'kind' => 'purchase', 'document_no' => 'SYNTH-LEGACY-1', 'review_codes' => ['document_item_account_ambiguous'],
        ]]]]);
        self::assertStringContainsString('<strong>Dokončeno s upozorněními</strong>', $legacy);
        self::assertStringContainsString('SYNTH-LEGACY-1', $legacy);

        $empty = $renderer->renderHtml(['report' => ['ok' => true, 'review_reasons' => ['document_item_account_ambiguous' => 0]]]);
        self::assertStringContainsString('<strong>Dokončeno</strong>', $empty);
        self::assertStringNotContainsString('data-review-code=', $empty);
        self::assertStringNotContainsString('Dokončeno s upozorněními', $empty);
    }

    public function testMessagesGroupBySeverityAndCodeWithFallbacksAndKeepReviewDetails(): void
    {
        $data = ['report' => [
            'ok' => false,
            'errors' => [['level' => 'error', 'code' => 'unknown_error', 'message' => 'Chyba syntetického převodu.']],
            'preflight' => [
                ['level' => 'info', 'code' => 'document_items_reconstructed', 'message' => 'Položky rekonstruovány z deníku.'],
                ['level' => 'info', 'code' => 'document_item_account_ambiguous', 'message' => 'Informativní zpráva se stejným kódem jako upozornění.'],
            ],
            'warnings' => [
                ['level' => 'warning', 'code' => 'document_item_account_ambiguous', 'source_key' => 'PRIVATE-SOURCE-KEY-NEVER-PRINT',
                    'document_no' => 'SYNTH-DOC-1', 'message' => 'Účet řádku dokladu není jednoznačný: syntetický detail A.'],
                ['level' => 'warning', 'code' => 'document_item_account_ambiguous', 'source_key' => 'PRIVATE-SOURCE-KEY-NEVER-PRINT',
                    'document_no' => 'SYNTH-DOC-1', 'message' => 'Účet řádku dokladu není jednoznačný: syntetický detail B.'],
                ['level' => 'warning', 'code' => 'document_item_account_ambiguous', 'source_key' => 'PRIVATE-SOURCE-KEY-NEVER-PRINT',
                    'document_no' => 'SYNTH-DOC-1', 'count' => 3, 'message' => 'Agregované syntetické upozornění se třemi výskyty.'],
                ['level' => 'warning', 'code' => 'pending_reconciliation',
                    'document_no' => 'SYNTH-MOVE-1', 'message' => 'Syntetický peněžní pohyb čeká na spárování.'],
                ['level' => 'warning', 'code' => 'future_unknown_reason', 'message' => 'První konkrétní text neznámého důvodu.'],
                ['level' => 'warning', 'code' => 'future_unknown_reason', 'message' => 'Druhý odlišný detail stejného neznámého důvodu.'],
                'Stejná zpráva bez kódu.', 'Stejná zpráva bez kódu.', 'Jiná zpráva bez kódu.',
            ],
            'review_reasons' => ['document_item_account_ambiguous' => 1],
            'review_documents' => [['kind' => 'purchase', 'document_no' => 'SYNTH-DOC-1',
                'source_key' => 'PRIVATE-SOURCE-KEY-NEVER-PRINT', 'review_codes' => ['document_item_account_ambiguous']]],
            'movement_review_reasons' => ['pending_reconciliation' => 2],
            'review_movements' => [
                ['kind' => 'bank', 'document_no' => 'SYNTH-MOVE-1', 'source_key' => 'PRIVATE-MOVEMENT-BANK-NEVER-PRINT', 'review_codes' => ['pending_reconciliation']],
                ['kind' => 'cash', 'document_no' => 'SYNTH-MOVE-1', 'source_key' => 'PRIVATE-MOVEMENT-CASH-NEVER-PRINT', 'review_codes' => ['pending_reconciliation']],
            ],
        ]];
        $html = (new StereoNxProtocolPdfRenderer())->renderHtml($data);

        self::assertLessThan(strpos($html, 'data-message-level="warning"'), strpos($html, 'data-message-level="error"'));
        self::assertLessThan(strpos($html, 'data-message-level="info"'), strpos($html, 'data-message-level="warning"'));
        preg_match_all('/class="group" data-message-level="warning"/', $html, $warningGroups);
        self::assertCount(5, $warningGroups[0], 'Same-code messages form one group; no-code messages group only by exact text.');
        self::assertStringContainsString('Účet položky nelze jednoznačně určit', $html);
        self::assertStringContainsString('Informativní zpráva se stejným kódem jako upozornění.', $html);
        self::assertStringContainsString('(Počet výskytů: 2)', $html);
        self::assertStringContainsString('Upozornění: První konkrétní text neznámého důvodu.', $html);
        self::assertStringContainsString('Upozornění: Stejná zpráva bez kódu.', $html);
        self::assertSame(2, substr_count($html, '(Počet výskytů: 2)'));
        self::assertStringContainsString('SYNTH-DOC-1', $html);
        self::assertStringContainsString('syntetický detail A.', $html);
        self::assertStringContainsString('syntetický detail B.', $html);
        self::assertStringContainsString('SYNTH-MOVE-1', $html);
        self::assertStringContainsString('Syntetický peněžní pohyb čeká na spárování.', $html);
        self::assertStringContainsString('Agregované syntetické upozornění se třemi výskyty.', $html);
        self::assertStringContainsString('(Počet výskytů: 3)', $html);
        self::assertStringContainsString('Bankovní pohyb:', $html);
        self::assertStringContainsString('Pokladní doklad:', $html);
        self::assertStringContainsString('První konkrétní text neznámého důvodu.', $html);
        self::assertStringContainsString('Druhý odlišný detail stejného neznámého důvodu.', $html);
        self::assertStringContainsString('Stejná zpráva bez kódu.', $html);
        self::assertStringContainsString('Jiná zpráva bez kódu.', $html);
        self::assertStringNotContainsString('PRIVATE-SOURCE-KEY-NEVER-PRINT', $html);
        self::assertStringNotContainsString('PRIVATE-MOVEMENT-KEY-NEVER-PRINT', $html);
        self::assertStringNotContainsString('PRIVATE-MOVEMENT-BANK-NEVER-PRINT', $html);
        self::assertStringNotContainsString('PRIVATE-MOVEMENT-CASH-NEVER-PRINT', $html);
        self::assertStringNotContainsString('future_unknown_reason', $html);
    }

    public function testUnknownMessageHtmlIsEscapedAndLongDetailIsNotTruncatedInPdf(): void
    {
        $message = '<script>synthetic ' . str_repeat('celý text důvodu ', 500) . 'DETAIL-END-SYNTHETIC</script>';
        $data = ['report' => ['ok' => false, 'warnings' => [
            ['level' => 'warning', 'code' => 'future_full_detail', 'document_no' => 'SYNTHETIC-LONG-1', 'message' => $message],
        ]]];
        $renderer = new StereoNxProtocolPdfRenderer();
        $html = $renderer->renderHtml($data);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('DETAIL-END-SYNTHETIC&lt;/script&gt;', $html);
        preg_match('/<h3 class="group-title">(.*?) <span class="group-count">/s', $html, $titleMatch);
        self::assertNotEmpty($titleMatch[1] ?? null);
        $groupTitle = html_entity_decode(strip_tags($titleMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $fallbackTitle = trim(substr($groupTitle, strlen('Upozornění:')));
        self::assertSame(160, mb_strlen($fallbackTitle, 'UTF-8'));
        self::assertStringEndsWith('...', $fallbackTitle);
        self::assertDoesNotMatchRegularExpression('/\s{2,}/u', $fallbackTitle);

        $pdf = (new Parser())->parseContent($renderer->render($data));
        self::assertStringContainsString('DETAIL-END-SYNTHETIC', $pdf->getText());
        self::assertGreaterThan(1, count($pdf->getPages()));
    }

    public function testPdfContainsDiacriticsAndCompleteLongProtocol(): void
    {
        $data = $this->data();
        $data['report']['warnings'] = array_map(static fn (int $number): array => ['level' => 'warning', 'code' => 'synthetic_long_warning',
            'message' => 'České upozornění číslo ' . $number . ': ' . str_repeat('Dlouhý syntetický důvod vyžadující ruční kontrolu. ', 3)], range(1, 70));
        $bytes = (new StereoNxProtocolPdfRenderer())->render($data);
        self::assertStringStartsWith('%PDF-', $bytes);
        $pdf = (new Parser())->parseContent($bytes);
        self::assertGreaterThan(1, count($pdf->getPages()));
        $text = $pdf->getText();
        self::assertStringContainsString('České upozornění číslo 70', $text);
        self::assertStringContainsString('SYNTHETIC-REVIEW-1', $text);
        self::assertStringNotContainsString('PRIVATE-KEY-NEVER-PRINT', $text);
    }

    private function data(): array
    {
        return ['mode' => 'dry_run', 'job_id' => 123, 'company_name' => 'Syntetická firma',
            'created_at' => '2026-08-31 12:30:00', 'params' => ['password' => 'PRIVATE-PARAM-NEVER-PRINT'],
            'report' => ['ok' => false, 'date_bounds' => ['from' => '2026-01-01', 'to' => '2026-08-31'],
                'counts' => ['assets_source' => 1, 'assets_ready' => 0, 'assets_skipped' => 1, 'small_assets_source' => 0],
                'written' => ['assets_created' => 0], 'partial' => true,
                'errors' => ['Synthetic failure <img src="https://example.invalid/image">'],
                'warnings' => [['level' => 'warning', 'code' => 'asset_tax_method_unsupported', 'message' => 'Syntetická zpráva']],
                'review_reasons' => ['vat_participation_unassigned' => 1],
                'review_documents' => [['kind' => 'purchase', 'document_no' => 'SYNTHETIC-REVIEW-1',
                    'source_key' => 'PRIVATE-KEY-NEVER-PRINT', 'review_codes' => ['vat_participation_unassigned']]],
                'not_transferred' => [['table' => 'Syntetická tabulka', 'count' => 2]],
            ]];
    }
}

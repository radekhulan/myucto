<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonSpool;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationHeader;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6PreviewCache;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6PreviewIdentity;
use PHPUnit\Framework\TestCase;

final class J6PreviewCacheTest extends TestCase
{
    private string $base;
    private J6PreviewCache $cache;
    private J6PreviewIdentity $identity;
    private J6CalculationHeader $header;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myucto-j6-preview-test-' . bin2hex(random_bytes(8));
        $this->cache = new J6PreviewCache(new CanonicalJsonSpool(), $this->base);
        $hash = hash('sha256', 'synthetic-draft-source-input');
        $this->identity = new J6PreviewIdentity(7, 11, 13, 17, 19, $hash);
        $this->header = new J6CalculationHeader(11, 13, 'nonprofit-j6-rules-2026.1', hash('sha256', 'source'),
            hash('sha256', 'decision'), hash('sha256', 'synthetic-calculation-input'), null, null,
            ['draft_id' => 17, 'draft_revision' => 19, 'source_input_hash' => $hash]);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->base)) return;
        foreach (new \DirectoryIterator($this->base) as $entry) {
            if ($entry->isDot()) continue;
            if ($entry->isDir()) {
                foreach (new \DirectoryIterator($entry->getPathname()) as $file) if (!$file->isDot()) unlink($file->getPathname());
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->base);
    }

    public function testPreviewIsBoundToOpaqueTokenOwnerRevisionAndExpiry(): void
    {
        $receipt = $this->publish(1000);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $receipt->previewToken);
        $page = $this->cache->read($receipt->previewToken, $this->identity, 'result', 0, 1001);
        self::assertSame('line:1', $page['rows'][0]['id']);
        self::assertNull($page['next_page']);
        self::assertSame(['metadata' => $page['metadata'], 'rows' => [], 'next_page' => null],
            $this->cache->read($receipt->previewToken, $this->identity, 'result', 99, 1001));

        $foreign = new J6PreviewIdentity(8, 11, 13, 17, 19, $this->identity->inputHash);
        try {
            $this->cache->read($receipt->previewToken, $foreign, 'result', 0, 1001);
            self::fail('Foreign preview owner must be rejected.');
        } catch (\UnexpectedValueException $failure) {
            self::assertSame('J6 preview was not found.', $failure->getMessage());
        }
        try {
            $this->cache->read($receipt->previewToken, $this->identity, 'result', 0, 1901);
            self::fail('Expired preview must be rejected.');
        } catch (\UnexpectedValueException $failure) {
            self::assertSame('J6 preview was not found.', $failure->getMessage());
        }
        $this->expectException(\UnexpectedValueException::class);
        $this->cache->read('../escape', $this->identity, 'result', 0, 1001);
    }

    public function testPartialFailureCleansOnlyOwnedWorkspaceAndRetentionKeepsThree(): void
    {
        $sink = $this->cache->createSink($this->identity, 2000);
        $sink->begin($this->header);
        $sink->appendDecisionPage([['source_id' => 'line:failure']]);
        try {
            $sink->complete($this->summary(0));
            self::fail('Mismatched calculation must fail.');
        } catch (\UnexpectedValueException) {}
        self::assertSame([], glob($this->base . DIRECTORY_SEPARATOR . 'work-*') ?: []);

        $tokens = [];
        for ($index = 0; $index < 4; ++$index) $tokens[] = $this->publish(3000 + $index)->previewToken;
        self::assertDirectoryDoesNotExist($this->base . DIRECTORY_SEPARATOR . 'preview-' . $tokens[0]);
        foreach (array_slice($tokens, 1) as $token) self::assertDirectoryExists($this->base . DIRECTORY_SEPARATOR . 'preview-' . $token);
        self::assertSame(3, count(glob($this->base . DIRECTORY_SEPARATOR . 'preview-*') ?: []));
        self::assertSame(3, $this->cache->cleanup(5000));
        self::assertSame([], glob($this->base . DIRECTORY_SEPARATOR . 'preview-*') ?: []);
    }

    private function publish(int $now): object
    {
        $sink = $this->cache->createSink($this->identity, $now);
        $sink->begin($this->header);
        $sink->appendDecisionPage([['source_id' => 'line:1']]);
        $sink->appendResultPage([['id' => 'line:1', 'amount_cents' => 100]]);
        $sink->appendCoveragePage([['source_id' => 'line:1', 'result_row_hash' => hash('sha256', 'row')]]);
        $sink->appendFundingUsagePage([['anchor' => 'line:1', 'drawdown_cents' => 0]]);
        return $sink->complete($this->summary(1));
    }

    private function summary(int $count): array
    {
        return ['source_row_count' => $count, 'decision_record_count' => $count, 'funding_usage_count' => $count, 'accounting_result_cents' => 100,
            'excluded_revenue_cents' => 0, 'cost_addbacks_cents' => 0, 'assessed_result_cents' => 100];
    }
}

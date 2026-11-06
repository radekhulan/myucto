<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Action\Admin\ExportAction;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Tests\Support\StandaloneIsdocTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Číslo dokladu v názvu ZIP položky i staženého souboru ISDOC exportu projde
 * stejným sanitizerem jako sourozenecké exporty a Content-Disposition nese
 * přesný název podle RFC 6266.
 */
final class IsdocExportFilenameTest extends TestCase
{
    use StandaloneIsdocTestTrait;

    protected function setUp(): void
    {
        $this->setupIsdocDatabase();
    }

    public function testZipEntriesAndFilenameContainNoPathCharacters(): void
    {
        $invoices = [
            1 => $this->isdocInvoice(['id' => 1, 'varsymbol' => '../../../../a']),
            2 => $this->isdocInvoice(['id' => 2, 'varsymbol' => 'FV/2026/..\\b', 'invoice_type' => 'credit_note']),
        ];
        $repo = $this->createStub(InvoiceRepository::class);
        $repo->method('find')->willReturnCallback(static fn (int $id): ?array => $invoices[$id] ?? null);

        $out = (new IsdocExporter($repo, $this->db))->export([1, 2], '../2026-03', static fn (string $xml): string => $xml);

        self::assertSame('isdoc-___2026-03.zip', $out['filename']);
        $tmp = tempnam(sys_get_temp_dir(), 'isdz_');
        file_put_contents($tmp, $out['content']);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tmp) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($tmp);

        self::assertSame(['Faktura-____________a.isdoc', 'Dobropis-FV_2026____b.isdoc'], $names);
        foreach ($names as $name) {
            self::assertDoesNotMatchRegularExpression('#[/\\\\]|\.\.#', $name);
        }
    }

    public function testSingleInvoiceFilenameIsSanitized(): void
    {
        $repo = $this->createStub(InvoiceRepository::class);
        $repo->method('find')->willReturn($this->isdocInvoice(['varsymbol' => '../x.y']));

        $out = (new IsdocExporter($repo, $this->db))->export([91], '', static fn (string $xml): string => $xml);

        self::assertSame('Faktura-___x_y.isdoc', $out['filename']);
    }

    public function testContentDispositionFollowsRfc6266(): void
    {
        $method = new \ReflectionMethod(ExportAction::class, 'attachmentDisposition');

        self::assertSame(
            'attachment; filename="myucto-2026-03.zip"; filename*=UTF-8\'\'myucto-2026-03.zip',
            $method->invoke(null, 'myucto-2026-03.zip'),
        );
        self::assertSame(
            'attachment; filename="export_a__b.zip"; filename*=UTF-8\'\'export%22a%22%3Bb.zip',
            $method->invoke(null, 'export"a";b.zip'),
        );
    }
}

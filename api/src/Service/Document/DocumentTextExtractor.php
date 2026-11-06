<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document;

use Smalot\PdfParser\Config as PdfConfig;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Extrahuje textovou vrstvu z dokumentů pro fulltextové vyhledávání.
 *
 *   - PDF: textová vrstva (smalot/pdfparser). Skenované PDF bez textu → unsupported.
 *   - DOCX/XLSX/PPTX/ODT/ODS: ZIP + XML (strip tagů).
 *   - XML/ISDOC/TXT/CSV/MD: přímý text.
 *
 * Běží synchronně při uploadu; chyba se nikdy nepropisuje do selhání uploadu.
 */
final class DocumentTextExtractor
{
    /** Maximální délka uloženého textu (anti-bloat fulltext indexu). */
    private const MAX_TEXT_BYTES = 1_000_000;
    /** Nad tento strop PDF neparsujeme (smalot/pdfparser je na velkých PDF velmi pomalý). */
    private const MAX_PDF_BYTES = 25 * 1024 * 1024;
    /** Kolik rozbalených bajtů XML z OOXML/ODF balíku přečteme celkem (všechny části). */
    private const MAX_OOXML_READ_BYTES = 16 * 1024 * 1024;

    /**
     * @return array{status:'extracted'|'unsupported'|'failed', text:?string}
     */
    public function extract(string $absPath, string $docType, string $ext): array
    {
        try {
            $text = match (true) {
                $docType === 'pdf'                                  => $this->fromPdf($absPath),
                in_array($ext, ['docx', 'pptx'], true)              => $this->fromOoxml($absPath, ['word/document.xml', 'ppt/slides/']),
                $ext === 'xlsx'                                     => $this->fromOoxml($absPath, ['xl/sharedStrings.xml']),
                in_array($ext, ['odt', 'ods', 'odp'], true)         => $this->fromOoxml($absPath, ['content.xml']),
                in_array($ext, ['xml', 'isdoc', 'isdocx'], true)    => $this->fromXml($absPath),
                in_array($ext, ['txt', 'csv', 'md', 'eml'], true)   => $this->fromPlain($absPath),
                default                                             => null,
            };
        } catch (\Throwable) {
            return ['status' => 'failed', 'text' => null];
        }

        if ($text === null) {
            return ['status' => 'unsupported', 'text' => null];
        }
        $text = $this->normalize($text);
        if ($text === '') {
            // Např. skenované PDF bez textové vrstvy.
            return ['status' => 'unsupported', 'text' => null];
        }
        if (strlen($text) > self::MAX_TEXT_BYTES) {
            $text = substr($text, 0, self::MAX_TEXT_BYTES);
        }
        return ['status' => 'extracted', 'text' => $text];
    }

    private function fromPdf(string $path): ?string
    {
        // Velká PDF přeskoč — parsování by job zdrželo na desítky sekund.
        if ((int) @filesize($path) > self::MAX_PDF_BYTES) {
            return null;
        }
        // Nedekóduj vložené obrázky (hlavní zdroj pomalosti/paměti u pdfparseru).
        $parser = new PdfParser();
        if (class_exists(PdfConfig::class)) {
            $cfg = new PdfConfig();
            if (method_exists($cfg, 'setRetainImageContent')) {
                $cfg->setRetainImageContent(false);
            }
            $parser = new PdfParser([], $cfg);
        }
        $pdf = $parser->parseFile($path);
        return $pdf->getText();
    }

    /** Zploští text z OOXML/ODF (ZIP + XML). $targets = soubory nebo prefixy uvnitř ZIPu. */
    private function fromOoxml(string $path, array $targets): ?string
    {
        if (!class_exists(\ZipArchive::class)) return null;
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) return null;

        $chunks = [];
        $budget = self::MAX_OOXML_READ_BYTES;
        for ($i = 0; $i < $zip->numFiles && $budget > 0; $i++) {
            $name = (string) $zip->getNameIndex($i);
            foreach ($targets as $t) {
                $matches = str_ends_with($t, '/') ? str_starts_with($name, $t) : $name === $t;
                if ($matches && str_ends_with($name, '.xml')) {
                    // Zip-bomb guard: getFromIndex() bez délky alokuje celou
                    // deklarovanou rozbalenou velikost najednou a fatal z
                    // vyčerpané paměti není \Throwable. Čte se jen omezený začátek
                    // (fulltext stejně ořezává na MAX_TEXT_BYTES).
                    $stat = $zip->statIndex($i);
                    $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
                    $xml = $size > 0 ? (string) $zip->getFromIndex($i, min($size, $budget)) : '';
                    $budget -= strlen($xml);
                    if ($xml !== '') {
                        // Mezi tagy vlož mezeru, ať se slova nespojí.
                        $chunks[] = strip_tags(str_replace('<', ' <', $xml));
                    }
                    break;
                }
            }
        }
        $zip->close();
        return $chunks === [] ? null : implode(' ', $chunks);
    }

    private function fromXml(string $path): ?string
    {
        $raw = $this->readCapped($path);
        if ($raw === null) return null;
        return strip_tags(str_replace('<', ' <', $raw));
    }

    private function fromPlain(string $path): ?string
    {
        return $this->readCapped($path);
    }

    private function readCapped(string $path): ?string
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) return null;
        $raw = (string) fread($fh, self::MAX_TEXT_BYTES + 1);
        fclose($fh);
        return $raw;
    }

    private function normalize(string $text): string
    {
        // Sjednoť whitespace, odstraň řídicí znaky, zahoď neplatné UTF-8.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $text);
        if (function_exists('mb_convert_encoding')) {
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }
}

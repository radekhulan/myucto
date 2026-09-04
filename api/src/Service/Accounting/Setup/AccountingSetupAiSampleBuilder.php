<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Setup;

use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;
use MyInvoice\Service\Ai\AiPayloadSanitizer;

final class AccountingSetupAiSampleBuilder
{
    private const DEFAULT_MAX_SAMPLES = 50;
    private const HARD_MAX_SAMPLES = 200;

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{samples:list<array{sample_id:string,text:string,occurrences:int}>,rows_by_sample:array<string,list<array<string,mixed>>>}
     */
    public function build(array $rows, int $maxSamples = self::DEFAULT_MAX_SAMPLES): array
    {
        $maxSamples = max(1, min(self::HARD_MAX_SAMPLES, $maxSamples));
        $prepared = [];
        $documentFrequency = [];
        foreach ($rows as $row) {
            $text = self::redact(
                (string) ($row['description'] ?? ''),
                isset($row['vendor_name']) ? (string) $row['vendor_name'] : null,
            );
            $text = BankMessageNormalizer::normalizeKeepDigits($text);
            $words = self::words($text);
            $invoiceId = (int) ($row['purchase_invoice_id'] ?? 0);
            $prepared[] = ['row' => $row, 'words' => $words, 'invoice_id' => $invoiceId];
            foreach (array_unique($words) as $word) {
                $documentFrequency[$word][$invoiceId] = true;
            }
        }

        $groups = [];
        foreach ($prepared as $item) {
            $words = array_values(array_filter(
                $item['words'],
                static fn (string $word): bool => count($documentFrequency[$word] ?? []) >= 2,
            ));
            $words = array_values(array_unique($words));
            if (count($words) < 2) {
                continue;
            }
            $row = $item['row'];
            $text = mb_substr(implode(' ', $words), 0, 160);
            $key = mb_strtolower($text);
            $groups[$key] ??= ['text' => $text, 'rows' => [], 'invoices' => []];
            $groups[$key]['rows'][] = $row;
            $groups[$key]['invoices'][(int) ($row['purchase_invoice_id'] ?? 0)] = true;
        }

        $groups = array_values($groups);
        usort($groups, static function (array $left, array $right): int {
            $byCount = count($right['invoices']) <=> count($left['invoices']);
            return $byCount !== 0 ? $byCount : strnatcasecmp((string) $left['text'], (string) $right['text']);
        });

        $samples = [];
        $rowsBySample = [];
        foreach (array_slice($groups, 0, $maxSamples) as $index => $group) {
            $sampleId = sprintf('s%02d', $index + 1);
            $samples[] = [
                'sample_id' => $sampleId,
                'text' => (string) $group['text'],
                'occurrences' => count($group['invoices']),
            ];
            $rowsBySample[$sampleId] = $group['rows'];
        }

        return ['samples' => $samples, 'rows_by_sample' => $rowsBySample];
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}]{3,}/u', mb_strtolower($text), $matches);
        return array_values(array_filter(
            (array) ($matches[0] ?? []),
            static fn (string $word): bool => $word !== 'num',
        ));
    }

    private static function redact(string $description, ?string $vendorName): string
    {
        $text = AiPayloadSanitizer::sanitizeItemText($description, 240);
        if ($vendorName !== null && trim($vendorName) !== '') {
            $text = preg_replace('/' . preg_quote(trim($vendorName), '/') . '/iu', ' ', $text) ?? '';
            foreach (self::words($vendorName) as $vendorWord) {
                $text = preg_replace('/\b' . preg_quote($vendorWord, '/') . '\b/iu', ' ', $text) ?? '';
            }
        }
        $text = preg_replace('~(?:https?://|www\.)\S+~iu', ' ', $text) ?? '';
        $text = preg_replace(
            '/\b(?:IČO|ICO|DIČ|DIC|VAT|invoice|faktura|objednávka|objednavka|order|zakázka|zakazka)\s*(?:č\.?|no\.?|number)?\s*[:#-]?\s*[A-Z0-9._\/-]+/iu',
            ' ',
            $text,
        ) ?? '';
        $text = preg_replace('/\p{N}+(?:[\s.,:\/-]\p{N}+)*/u', ' <num> ', $text) ?? '';
        $text = preg_replace('/\bpii\b/iu', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = trim($text, " \t\n\r\0\x0B,.;:-");
        if ($text === '' || preg_replace('/(?:<num>|\s)/u', '', $text) === '') {
            return '';
        }
        return mb_substr($text, 0, 160);
    }
}

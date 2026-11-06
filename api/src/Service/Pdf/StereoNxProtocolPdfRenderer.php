<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Bootstrap;

/** Export uloženého výsledku; nikdy znovu nečte zdrojový ZIP ani parametry jobu. */
final class StereoNxProtocolPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $view = $this->view($data);
        $mpdf = $this->mpdf(['format' => 'A4', 'orientation' => 'P', 'margin_left' => 12, 'margin_right' => 12]);
        $mpdf->SetTitle($view['labels']['title']);
        $mpdf->SetFooter($view['labels']['page'] . ' {PAGENO} ' . $view['labels']['of'] . ' {nbpg}||' . $view['labels']['created_by']);
        ChunkedHtmlWriter::write($mpdf, $this->renderHtml($data));
        return $mpdf->Output('', 'S');
    }

    public function renderHtml(array $data): string
    {
        return $this->renderTemplate('stereo_nx_protocol.twig', $this->view($data));
    }

    /** Do tiskového modelu přenášíme jen zobrazované údaje, nikoli interní identifikátory. */
    private function view(array $data): array
    {
        $lang = ($data['language'] ?? '') === 'en' ? 'en' : 'cs';
        $catalog = json_decode((string) file_get_contents(Bootstrap::rootDir() . '/api/resources/pdf/stereo-nx-protocol.' . $lang . '.json'), true, flags: JSON_THROW_ON_ERROR);
        $labels = $catalog['ui'];
        $report = is_array($data['report'] ?? null) ? $data['report'] : [];
        $mode = ($data['mode'] ?? '') === 'import' ? 'import' : 'dry_run';
        $number = static fn (mixed $value, int $decimals = 0): string => is_numeric($value)
            ? number_format((float) $value, $decimals, $lang === 'cs' ? ',' : '.', $lang === 'cs' ? ' ' : ',') : '-';
        $label = static fn (string $group, string $key): string => $catalog[$group][$key] ?? str_replace('_', ' ', $key);
        $sections = [];
        foreach (['counts' => 'source_counts', 'written' => $mode === 'dry_run' ? 'planned' : 'written'] as $key => $title) {
            $rows = [];
            foreach (($report[$key] ?? []) as $metric => $value) {
                if (is_int($value) || is_float($value)) $rows[] = [$label('counts', (string) $metric), $number($value)];
            }
            if ($rows !== []) $sections[] = ['title' => $labels[$title], 'headers' => [$labels['metric'], $labels['count']], 'rows' => $rows];
        }
        $messageGroups = [];
        foreach (['preflight' => 'info', 'errors' => 'error', 'warnings' => 'warning'] as $key => $fallback) {
            foreach (($report[$key] ?? []) as $item) {
                $text = is_string($item) ? $item : (is_array($item) && is_string($item['message'] ?? null) ? $item['message'] : '');
                if ($text === '') continue;
                $level = is_array($item) && isset($catalog['level'][$item['level'] ?? '']) ? $item['level'] : $fallback;
                $document = is_array($item) && is_string($item['document_no'] ?? null) ? $item['document_no'] : '';
                $code = is_array($item) && is_string($item['code'] ?? null) ? trim($item['code']) : '';
                $groupKey = $level . "\0" . ($code !== '' ? 'code:' . $code : 'text:' . $text);
                $occurrences = is_array($item) && is_numeric($item['count'] ?? null) ? max(1, (int) $item['count']) : 1;
                if (!isset($messageGroups[$groupKey])) {
                    $messageGroups[$groupKey] = ['level_key' => $level, 'code' => $code, 'title' => '', 'count' => 0, 'details' => []];
                }
                $messageGroups[$groupKey]['count'] += $occurrences;
                $messageGroups[$groupKey]['details'][] = [
                    'document' => $document !== '' ? $document : $labels['unknown_document'],
                    '_document_no' => $document,
                    '_source_key' => is_array($item) && is_string($item['source_key'] ?? null) ? $item['source_key'] : '',
                    '_code' => $code,
                    '_level_key' => $level,
                    'message' => $text,
                    'count' => $occurrences,
                ];
                if ($messageGroups[$groupKey]['title'] === '') {
                    $messageGroups[$groupKey]['title'] = $this->reasonTitle($catalog, $code, $text);
                }
            }
        }
        $reviewGroups = [];
        foreach ([
            'review_reasons' => ['collection' => 'review_documents', 'kind' => 'document'],
            'movement_review_reasons' => ['collection' => 'review_movements', 'kind' => 'movement'],
        ] as $summaryKey => $settings) {
            foreach (($report[$summaryKey] ?? []) as $reason => $count) {
                $reason = (string) $reason;
                if (!isset($reviewGroups[$reason])) $reviewGroups[$reason] = ['code' => $reason, 'title' => $this->reasonTitle($catalog, $reason, ''), 'count' => 0, 'documents' => [], 'movements' => []];
                $count = is_numeric($count) ? max(0, (int) $count) : 0;
                if ($settings['kind'] === 'document') $reviewGroups[$reason]['document_count'] = $count;
                else $reviewGroups[$reason]['movement_count'] = $count;
            }
        }
        foreach (['review_documents' => 'documents', 'review_movements' => 'movements'] as $key => $collection) {
            foreach (($report[$key] ?? []) as $record) {
                if (!is_array($record)) continue;
                $codes = array_values(array_unique(array_filter(array_map('strval', is_array($record['review_codes'] ?? null) ? $record['review_codes'] : []), static fn (string $code): bool => $code !== '')));
                if ($codes === []) $codes = ['unknown_review_reason'];
                foreach ($codes as $code) {
                    if (!isset($reviewGroups[$code])) $reviewGroups[$code] = ['code' => $code, 'title' => $this->reasonTitle($catalog, $code, ''), 'count' => 0, 'documents' => [], 'movements' => []];
                    $reviewGroups[$code]['count']++;
                    $reviewGroups[$code][$collection][] = [
                        'kind' => $label('kind', (string) ($record['kind'] ?? '')),
                        'document' => (string) ($record['document_no'] ?? '') ?: $labels['unknown_document'],
                        '_document_no' => (string) ($record['document_no'] ?? ''),
                        '_source_key' => is_string($record['source_key'] ?? null) ? $record['source_key'] : '',
                        'messages' => [],
                    ];
                }
            }
        }
        $reviewIndexBySource = [];
        $reviewIndexByNumber = [];
        foreach ($reviewGroups as $code => &$group) {
            foreach (['documents', 'movements'] as $collection) {
                foreach ($group[$collection] as $index => $record) {
                    $location = [$collection, $index];
                    if ($record['_source_key'] !== '') $reviewIndexBySource[$code][$record['_source_key']][] = $location;
                    if ($record['_document_no'] !== '') $reviewIndexByNumber[$code][$record['_document_no']][] = $location;
                }
            }
        }
        unset($group);
        foreach ($messageGroups as $groupKey => &$messageGroup) {
            if ($messageGroup['code'] === '' || !isset($reviewGroups[$messageGroup['code']])) continue;
            $code = $messageGroup['code'];
            if (!isset($catalog['reasons'][$code]) && !in_array($code, [
                'document_rounding_account_review', 'document_item_account_ambiguous', 'document_items_reconstructed',
            ], true)) {
                $reviewGroups[$code]['title'] = $messageGroup['title'];
            }
            $matchedDetails = [];
            foreach ($messageGroup['details'] as $detailIndex => $detail) {
                // Souhrnné varování může zastupovat několik záznamů, proto ho
                // nelze přiřadit jednomu konkrétnímu řádku kontroly.
                if ($detail['_level_key'] !== 'warning' || $detail['count'] !== 1) continue;
                $candidateLocations = $detail['_source_key'] !== ''
                    ? ($reviewIndexBySource[$code][$detail['_source_key']] ?? [])
                    : ($detail['_document_no'] !== '' ? ($reviewIndexByNumber[$code][$detail['_document_no']] ?? []) : []);
                if (count($candidateLocations) !== 1) continue;
                [$collection, $index] = $candidateLocations[0];
                $reviewGroups[$messageGroup['code']][$collection][$index]['messages'][] = [
                    'level' => $label('level', $detail['_level_key']),
                    'document' => $detail['document'], 'message' => $detail['message'], 'count' => $detail['count'],
                ];
                $reviewGroups[$messageGroup['code']]['message_count'] = ($reviewGroups[$messageGroup['code']]['message_count'] ?? 0) + $detail['count'];
                $matchedDetails[$detailIndex] = $detail['count'];
            }
            foreach ($matchedDetails as $detailIndex => $count) {
                unset($messageGroup['details'][$detailIndex]);
                $messageGroup['count'] -= $count;
            }
            if ($matchedDetails !== []) $messageGroup['details'] = array_values($messageGroup['details']);
        }
        unset($messageGroup);
        $messageGroups = array_filter($messageGroups, static fn (array $group): bool => $group['count'] > 0 && $group['details'] !== []);
        $severityRank = ['error' => 0, 'warning' => 1, 'info' => 2];
        uasort($messageGroups, static fn (array $left, array $right): int => ($severityRank[$left['level_key']] <=> $severityRank[$right['level_key']]));
        foreach ($messageGroups as &$group) $group['level'] = $label('level', $group['level_key']);
        unset($group);
        if ($messageGroups !== []) $sections[] = ['type' => 'messages', 'title' => $labels['messages'], 'groups' => array_values($messageGroups)];
        foreach ($reviewGroups as &$group) {
            $group['count'] = max($group['count'], ($group['document_count'] ?? 0) + ($group['movement_count'] ?? 0));
            $group['document_count'] ??= count($group['documents']);
            $group['movement_count'] ??= count($group['movements']);
        }
        unset($group);
        $reviewGroups = array_filter($reviewGroups, static fn (array $group): bool => $group['count'] > 0);
        if ($reviewGroups !== []) $sections[] = ['type' => 'reviews', 'title' => $labels['documents_review'], 'groups' => array_values($reviewGroups)];
        $rows = [];
        foreach (($report['not_transferred'] ?? []) as $table) $rows[] = [(string) ($table['table'] ?? ''), $number($table['count'] ?? null)];
        if ($rows !== []) $sections[] = ['title' => $labels['not_transferred'], 'headers' => [$labels['table'], $labels['count']], 'rows' => $rows];
        foreach (($report['reconciliation'] ?? []) as $year) {
            $title = $labels['reconciliation'] . ' - ' . $labels['year'] . ' ' . ($year['year'] ?? '') . ' - ' . $labels[($year['ok'] ?? false) ? 'ok' : 'not_ok'];
            $rows = [];
            foreach (($year['checks'] ?? []) as $check) $rows[] = [$label('checks', (string) ($check['key'] ?? '')), $labels[($check['ok'] ?? false) ? 'ok' : 'not_ok']];
            $sections[] = ['title' => $title, 'headers' => [$labels['metric'], $labels['message']], 'rows' => $rows];
            $rows = [];
            foreach (($year['journal_diffs'] ?? []) as $diff) {
                $triple = static fn (array $values): string => implode(' / ', array_map(static fn ($value): string => $number($value, 2), $values));
                $rows[] = [(string) ($diff['account'] ?? ''), $triple($diff['myucto'] ?? []), $triple($diff['money'] ?? [])];
            }
            if ($rows !== []) $sections[] = ['title' => $title, 'headers' => [$labels['account'], $labels['target'], $labels['source']], 'rows' => $rows];
        }
        $hasWarnings = $reviewGroups !== [] || !empty($report['partial']);
        foreach (($report['warnings'] ?? []) as $item) {
            if (!is_array($item) || ($item['level'] ?? '') !== 'info') $hasWarnings = true;
        }
        foreach (($report['preflight'] ?? []) as $item) {
            if (is_array($item) && ($item['level'] ?? '') === 'warning') $hasWarnings = true;
        }
        foreach ($sections as &$section) {
            if (isset($section['type'])) continue;
            $section['numeric_last'] = end($section['headers']) === $labels['count'];
            $section['widths'] = count($section['headers']) === 2
                ? ($section['numeric_last'] ? [82, 18] : [65, 35])
                : ($section['headers'][0] === $labels['level'] ? [15, 22, 63]
                    : ($section['headers'][0] === $labels['account'] ? [20, 40, 40] : [20, 25, 55]));
        }
        unset($section);
        return ['labels' => $labels, 'language' => $lang, 'sections' => $sections,
            'company' => (string) ($data['company_name'] ?? ''), 'job_id' => (int) ($data['job_id'] ?? 0),
            'created' => $this->date((string) ($data['created_at'] ?? ''), $lang, true),
            'from' => $this->date((string) ($report['date_bounds']['from'] ?? ''), $lang),
            'to' => $this->date((string) ($report['date_bounds']['to'] ?? ''), $lang),
            'mode' => $catalog['mode'][$mode], 'dry_run' => $mode === 'dry_run',
            'database_writes' => ($report['database_writes'] ?? false) === true,
            'partial' => !empty($report['partial']), 'failed' => ($report['ok'] ?? false) !== true,
            'status' => $catalog['status'][($report['ok'] ?? false) !== true ? 'failed' : ($hasWarnings ? 'completed_with_warnings' : 'completed')],
        ];
    }

    /** Název důvodu je lidsky čitelný i pro starší či neznámé kódy. */
    private function reasonTitle(array $catalog, string $code, string $fallback): string
    {
        if (isset($catalog['reasons'][$code])) return (string) $catalog['reasons'][$code];
        if ($fallback === '') return (string) ($catalog['ui']['unknown_reason'] ?? $code);
        $fallback = preg_replace('/\s+/u', ' ', trim($fallback)) ?? trim($fallback);
        return mb_strlen($fallback, 'UTF-8') > 160 ? mb_substr($fallback, 0, 157, 'UTF-8') . '...' : $fallback;
    }

    private function date(string $date, string $lang, bool $time = false): string
    {
        if ($date === '') return '';
        try {
            return (new \DateTimeImmutable($date))->format(($lang === 'en' ? 'm/d/Y' : 'd.m.Y') . ($time ? ' H:i' : ''));
        } catch (\Exception) {
            return '';
        }
    }
}

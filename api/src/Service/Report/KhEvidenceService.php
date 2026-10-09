<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxSubmissionRepository;

/**
 * Evidence pro kontrolní hlášení (issue #142): soupis dokladů vybraného oddílu KH
 * za období, se součty a hlavičkou pro export (firma, období, filtr, okamžik sestavení).
 *
 * Zařazení dokladů NEpočítá sama — bere ho z {@see KontrolniHlaseniBuilder::sectionDocuments()},
 * tedy ze stejného sběru, ze kterého vzniká XML hlášení.
 */
final class KhEvidenceService
{
    public const SECTION_LABELS = [
        'A.1'  => 'Uskutečněná plnění v režimu přenesení daňové povinnosti',
        'A.2'  => 'Přijatá plnění, u kterých daň přiznává příjemce',
        'A.4'  => 'Uskutečněná plnění nad 10 000 Kč',
        'A.5'  => 'Uskutečněná plnění do 10 000 Kč (souhrnně)',
        'B.1'  => 'Přijatá plnění v režimu přenesení daňové povinnosti',
        'B.2'  => 'Přijatá plnění nad 10 000 Kč',
        'B.3'  => 'Přijatá plnění do 10 000 Kč (souhrnně)',
        'none' => 'Doklady evidence DPH mimo kontrolní hlášení',
    ];

    /** Výsledek porovnání podaného KH s aktuálními daty po dokladech. */
    public const STATUS_LABELS = [
        'match'          => 'Shoda',
        'only_submitted' => 'Jen v podaném KH',
        'only_current'   => 'Jen v aktuálních datech',
        'amount_diff'    => 'Rozdíl částky',
    ];

    /** Popis zdroje sestavy do hlavičky exportu. */
    public static function sourceLabel(array $report): string
    {
        if (($report['source'] ?? '') !== 'submitted') {
            return 'aktuální data (stav ke dni sestavení)';
        }
        $s = $report['submission'] ?? [];
        $parts = ['podle podaného KH'];
        if (!empty($s['submitted_at'])) {
            $parts[] = 'podáno ' . (new \DateTimeImmutable((string) $s['submitted_at']))->format('d.m.Y');
        }
        if (!empty($s['variant_label'])) {
            $parts[] = 'typ: ' . $s['variant_label'];
        }
        return implode(', ', $parts);
    }

    public static function filterLabel(string $section): string
    {
        return $section === 'all'
            ? 'všechny oddíly'
            : ($section === 'none' ? 'mimo KH' : 'oddíl ' . $section) . ' - ' . (self::SECTION_LABELS[$section] ?? '');
    }

    public function __construct(
        private readonly Connection $db,
        private readonly KontrolniHlaseniBuilder $kh,
        private readonly TaxSubmissionRepository $submissions,
    ) {}

    private const VARIANT_LABELS = [
        'B' => 'řádné',
        'O' => 'řádné opravné',
        'N' => 'následné',
        'E' => 'následné opravné',
    ];

    /**
     * Sestava podle PODANÉHO kontrolního hlášení porovnaná po dokladech s aktuálními daty.
     * Částky řádků jsou z podaného XML (u „jen v aktuálních datech" z aktuálních dat),
     * `current` nese aktuální částky téhož dokladu.
     *
     * @return array<string,mixed>
     */
    public function submitted(int $supplierId, int $submissionId, string $section): array
    {
        $sub = $this->submissions->find($submissionId, $supplierId);
        if ($sub === null || (string) $sub['form_code'] !== 'dphkh1') {
            throw new \DomainException('Podání kontrolního hlášení nebylo nalezeno.');
        }
        if (!in_array((string) $sub['status'], ['submitted', 'accepted'], true)) {
            throw new \DomainException('Soupis podle podaného KH je k dispozici jen u podání označeného jako podané.');
        }
        $year = (int) $sub['period_year'];
        $quarter = $sub['period_quarter'] !== null ? (int) $sub['period_quarter'] : null;
        $period = $quarter !== null ? 'quarterly' : 'monthly';
        $month = $quarter !== null ? $quarter * 3 : (int) $sub['period_month'];

        $filed = KhSubmittedXmlReader::read((string) $sub['xml_content']);
        $current = $this->kh->sectionDocuments($supplierId, $year, $month, $period);
        $sections = self::compare($filed, $current['sections']);

        $counts = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        foreach ($sections as $s) {
            foreach ($s['rows'] as $row) {
                $counts[$row['status']]++;
            }
        }
        $variant = (string) ($sub['form_variant'] ?? '');

        return [
            'source'       => 'submitted',
            'comparison'   => true,
            'submission'   => [
                'id'            => (int) $sub['id'],
                'submitted_at'  => $sub['submitted_at'] ?? null,
                'variant'       => $variant !== '' ? $variant : null,
                'variant_label' => self::VARIANT_LABELS[$variant] ?? null,
            ],
            'supplier'      => $this->supplier($supplierId),
            'period'        => $current['period'],
            'section'       => $section,
            'generated_at'  => date('Y-m-d H:i:s'),
            'sections'      => self::filterSections($sections, $section),
            'excluded'      => [],
            'warnings'      => [],
            'status_counts' => $counts,
        ];
    }

    /**
     * Porovnání po dokladech. Klíč dokladu = oddíl, číslo dokladu v KH, DIČ, kód předmětu
     * plnění (A.1/B.1 mají větu per kód) a příznak opravy; souhrnné oddíly A.5/B.3 se
     * porovnávají jedním řádkem souhrnu.
     *
     * @param array<string, list<array<string,mixed>>> $filed
     * @param array<string, array{rows: list<array<string,mixed>>, totals: array<string,mixed>}> $current
     * @return array<string, array<string,mixed>>
     */
    public static function compare(array $filed, array $current): array
    {
        $out = [];
        foreach (KontrolniHlaseniBuilder::SOUPIS_SECTIONS as $section) {
            if ($section === 'none') {
                continue;
            }
            $filedRows = $filed[$section] ?? [];
            $currentSection = $current[$section] ?? ['rows' => [], 'totals' => null];
            if (in_array($section, ['A.5', 'B.3'], true)) {
                $currentRows = [];
                if (($currentSection['totals']['count'] ?? 0) > 0) {
                    $t = $currentSection['totals'];
                    $currentRows[] = ['section' => $section, 'source' => $section === 'A.5' ? 'sale' : 'purchase',
                        'invoice_id' => null, 'doc_number' => '', 'internal_number' => null,
                        'counterparty_name' => '', 'counterparty_dic' => '', 'tax_date' => null,
                        'kod_pred_pl' => null, 'is_correction' => false, 'is_aggregate' => true,
                        'base21' => $t['base21'], 'vat21' => $t['vat21'], 'base12' => $t['base12'], 'vat12' => $t['vat12'],
                        'base_total' => $t['base_total'], 'vat_total' => $t['vat_total']];
                }
            } else {
                $currentRows = $currentSection['rows'];
            }
            if ($filedRows === [] && $currentRows === []) {
                continue;
            }

            $byKey = [];
            foreach ($currentRows as $row) {
                $byKey[self::rowKey($row)][] = $row;
            }
            $rows = [];
            foreach ($filedRows as $row) {
                $key = self::rowKey($row);
                $match = isset($byKey[$key]) && $byKey[$key] !== [] ? array_shift($byKey[$key]) : null;
                if ($match === null) {
                    $rows[] = $row + ['status' => 'only_submitted', 'current' => null];
                    continue;
                }
                // Jméno a interní číslo zná jen aktuální evidence, podané XML je nenese.
                $row['counterparty_name'] = (string) ($match['counterparty_name'] ?? '');
                $row['internal_number'] = $match['internal_number'] ?? null;
                $row['invoice_id'] = $match['invoice_id'] ?? null;
                $rows[] = $row + [
                    'status'  => self::sameAmounts($row, $match) ? 'match' : 'amount_diff',
                    'current' => self::amounts($match),
                ];
            }
            foreach ($byKey as $left) {
                foreach ($left as $row) {
                    $rows[] = $row + ['status' => 'only_current', 'current' => self::amounts($row)];
                }
            }
            $out[$section] = [
                'rows'           => $rows,
                'totals'         => self::totals(array_values(array_filter($rows, static fn (array $r): bool => $r['status'] !== 'only_current'))),
                'current_totals' => self::totals(array_map(
                    static fn (array $r): array => ($r['current'] ?? []) + ['status' => $r['status']],
                    array_values(array_filter($rows, static fn (array $r): bool => $r['current'] !== null)),
                )),
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private static function rowKey(array $row): string
    {
        $dic = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($row['counterparty_dic'] ?? ''))) ?? '';
        if (str_starts_with($dic, 'CZ') && $row['section'] !== 'A.2') {
            $dic = substr($dic, 2);
        }
        return implode('|', [
            $row['section'],
            mb_strtoupper(trim((string) $row['doc_number'])),
            $dic,
            (string) ($row['kod_pred_pl'] ?? ''),
            !empty($row['is_correction']) ? 'P' : 'N',
        ]);
    }

    private const AMOUNT_KEYS = ['base21', 'vat21', 'base12', 'vat12', 'base_total', 'vat_total'];

    /** @param array<string,mixed> $row @return array<string,?float> */
    private static function amounts(array $row): array
    {
        $out = [];
        foreach (self::AMOUNT_KEYS as $k) {
            $out[$k] = $row[$k] ?? null;
        }
        return $out;
    }

    /** Shoda na haléř ve všech sazbách. */
    private static function sameAmounts(array $a, array $b): bool
    {        foreach (self::AMOUNT_KEYS as $k) {
            if ((int) round(((float) ($a[$k] ?? 0)) * 100) !== (int) round(((float) ($b[$k] ?? 0)) * 100)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Součty v haléřích, zaokrouhlené na celé Kč až na konci.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private static function totals(array $rows): array
    {
        $cents = array_fill_keys(self::AMOUNT_KEYS, 0);
        foreach ($rows as $row) {
            foreach (self::AMOUNT_KEYS as $k) {
                $cents[$k] += (int) round(((float) ($row[$k] ?? 0)) * 100);
            }
        }
        $t = ['count' => count($rows)];
        foreach (self::AMOUNT_KEYS as $k) {
            $t[$k] = $cents[$k] / 100;
        }
        $t['base_total_whole'] = (float) round($cents['base_total'] / 100);
        $t['vat_total_whole'] = (float) round($cents['vat_total'] / 100);
        $t['rounding_difference'] = 0.0;
        return $t;
    }

    public static function normalizeSection(?string $section): ?string
    {
        $section = strtoupper(trim((string) $section));
        if ($section === '' || $section === 'ALL') {
            return 'all';
        }
        if ($section === 'NONE') {
            return 'none';
        }
        return in_array($section, KontrolniHlaseniBuilder::SOUPIS_SECTIONS, true) ? $section : null;
    }

    /**
     * Sestava z aktuálních dat.
     *
     * @param string $section oddíl (A.4, …), 'none' = mimo KH, 'all' = všechny oddíly
     * @return array<string,mixed>
     */
    public function current(int $supplierId, int $year, int $month, string $period, string $section): array
    {
        $docs = $this->kh->sectionDocuments($supplierId, $year, $month, $period);
        return [
            'source'       => 'current',
            'submission'   => null,
            'supplier'     => $this->supplier($supplierId),
            'period'       => $docs['period'],
            'section'      => $section,
            'generated_at' => date('Y-m-d H:i:s'),
            'sections'     => self::filterSections($docs['sections'], $section),
            'excluded'     => array_values(array_filter(
                $docs['excluded'],
                static fn (array $r): bool => $section === 'all' || $r['section'] === $section,
            )),
            'warnings'     => $docs['warnings'],
        ];
    }

    /**
     * @param array<string,mixed> $sections
     * @return array<string,mixed>
     */
    public static function filterSections(array $sections, string $section): array
    {
        if ($section === 'all') {
            return $sections;
        }
        return isset($sections[$section]) ? [$section => $sections[$section]] : [];
    }

    /** @return array{company_name:string, ic:string, dic:string} */
    public function supplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, ic, dic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'company_name' => (string) ($row['company_name'] ?? ''),
            'ic'           => (string) ($row['ic'] ?? ''),
            'dic'          => (string) ($row['dic'] ?? ''),
        ];
    }
}

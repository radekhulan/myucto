<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;

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
    ) {}

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

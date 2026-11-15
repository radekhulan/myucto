<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\Membership\MembershipListRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Export\CsvWriter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Export seznamu členů pro hromadnou změnu stavu (krok 8 #321): stejné filtry jako seznam,
 * dnešní stav podle platných revizí, úhrady za rok a označení z filtru a prázdné sloupce změny.
 * Bez adres a kontaktních údajů. Seznam se čte po stránkách 500 členů (historie a poslední
 * předpis jedním dotazem na stránku), takže práce roste lineárně s počtem členů.
 */
final class MembershipListExporter
{
    private const PAGE = 500;
    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(private readonly Connection $db, private readonly ?string $today = null) {}

    /** @return array{bytes:string, filename:string, mime:string} */
    public function export(int $supplier, array $filter, string $format): array
    {
        if (!in_array($format, ['xlsx', 'csv'], true)) {
            throw new MembershipException('nonprofit_membership_invalid', 'Podporované formáty exportu jsou XLSX a CSV.');
        }
        $rows = $this->rows($supplier, $filter);
        $filename = 'clenove-' . $this->today() . '.' . $format;
        if ($format === 'csv') {
            $text = array_map(static fn (array $row): array => array_map(
                static fn (array $cell): string => match ($cell[0]) { 'n' => number_format((float) $cell[1], 2, ',', ''), 'i' => (string) (int) $cell[1], default => CsvWriter::safe($cell[1]) }, $row), $rows);
            return ['bytes' => CsvWriter::build(MembershipListColumns::headers(), $text), 'filename' => $filename, 'mime' => 'text/csv; charset=UTF-8'];
        }
        $book = new Spreadsheet();
        $this->sheet($book->getActiveSheet(), 'Členové', MembershipListColumns::headers(), $rows);
        $guide = [];
        foreach (MembershipListColumns::all() as $column) {
            $guide[] = [['s', $column['header']], ['s', $column['required']], ['s', implode(', ', $column['aliases'])], ['s', $column['note']]];
        }
        $this->sheet($book->createSheet(), 'Návod', ['Sloupec', 'Povinný', 'Aliasy', 'Hodnoty / pravidla'], $guide);
        $book->setActiveSheetIndex(0);
        $base = tempnam(sys_get_temp_dir(), 'nmexp_');
        $tmp = $base . '.xlsx';
        try {
            (new XlsxWriter($book))->save($tmp);
            $bytes = (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
            @unlink($base);
            $book->disconnectWorksheets();
        }
        return ['bytes' => $bytes, 'filename' => $filename, 'mime' => self::XLSX_MIME];
    }

    /**
     * Buňky v pořadí MembershipListColumns; ['s', text], ['i', počet] nebo ['n', částka v Kč].
     * @return list<list<array{0:string,1:mixed}>>
     */
    public function rows(int $supplier, array $filter): array
    {
        $list = new MembershipListRepository($this->db);
        $members = new MembershipRepository($this->db);
        $today = $this->today();
        $out = [];
        $after = 0;
        do {
            $page = $list->page($supplier, $after, self::PAGE, $filter);
            if ($page === []) break;
            $ids = array_column($page, 'id');
            $histories = $members->historiesMany($supplier, $ids);
            $claims = $list->latestClaims($supplier, $ids, $filter);
            foreach ($page as $row) {
                $now = MembershipRevisionTimeline::at($histories[$row['id']] ?? [], $today);
                $dues = $row['contribution'];
                $claim = $claims[$row['id']] ?? null;
                $out[] = [
                    ['s', $row['member_number']],
                    ['s', (string) $row['client_id']],
                    ['s', ''],
                    ['s', self::label($row)],
                    ['s', $now['status'] ?? ''],
                    ['s', $now['effective_from'] ?? ''],
                    ['i', $dues['claim_count']],
                    ...array_map(static fn (?string $cents): array => $cents === null ? ['s', ''] : ['n', (int) $cents / 100.0],
                        [$dues['prescribed_cents'], $dues['paid_cents'], $dues['open_cents']]),
                    ['s', $dues['status']],
                    ['s', $claim === null ? '' : $claim['title_key'] . ' ' . $claim['period_from'] . '–' . $claim['period_to']],
                    $claim === null ? ['s', ''] : ['n', (int) $claim['amount_cents'] / 100.0],
                    ['s', ''], ['s', ''], ['s', ''], ['s', ''],
                ];
            }
            $after = $page[array_key_last($page)]['id'];
        } while (count($page) === self::PAGE);
        return $out;
    }

    /** Stejné zobrazení jako membershipMemberLabel na webu: název, jinak jméno a příjmení. */
    private static function label(array $row): string
    {
        $company = trim((string) ($row['company_name'] ?? ''));
        return $company !== '' ? $company : trim(implode(' ', array_filter([(string) ($row['first_name'] ?? ''), (string) ($row['last_name'] ?? '')], static fn (string $part): bool => $part !== '')));
    }

    private function today(): string
    {
        return $this->today ?? (new \DateTimeImmutable())->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
    }

    /** @param list<string> $headers @param list<list<array{0:string,1:mixed}>> $rows */
    private function sheet(Worksheet $sheet, string $title, array $headers, array $rows): void
    {
        $sheet->setTitle($title);
        foreach ($headers as $i => $header) {
            $sheet->setCellValueExplicit([$i + 1, 1], $header, DataType::TYPE_STRING);
        }
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$last}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
        // Texty explicitně jako řetězec: číslo „042“ si drží nulu a „=…“ zůstane textem.
        foreach ($rows as $r => $cells) {
            foreach ($cells as $i => [$type, $value]) {
                $type !== 's'
                    ? $sheet->setCellValue([$i + 1, $r + 2], $value)
                    : $sheet->setCellValueExplicit([$i + 1, $r + 2], (string) $value, DataType::TYPE_STRING);
            }
        }
        for ($i = 1; $i <= count($headers); $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
    }
}

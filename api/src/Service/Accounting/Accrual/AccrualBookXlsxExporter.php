<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Accrual;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** XLSX rozpisu časového rozlišení k datu: položky a odsouhlasení s hlavní knihou. */
final class AccrualBookXlsxExporter
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const MONEY = '#,##0.00';
    private const DATE = 'd.m.yyyy';

    /**
     * @param array<string,mixed> $book {@see AccrualBookService::build()}
     * @return array{bytes:string, mime:string}
     */
    public function export(array $book): array
    {
        $meta = $book['meta'];
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Rozpis');
        $sheet->setCellValue('A1', $meta['title'] . ' k ' . self::czDate($meta['as_of']));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $this->text($sheet, 1, 2, 'Firma: ' . $meta['supplier']['company_name'] . ($meta['supplier']['ic'] !== '' ? ', IČ ' . $meta['supplier']['ic'] : ''));
        $this->text($sheet, 1, 3, 'Vytiskl: ' . $meta['printed_by'] . ', ' . (new \DateTimeImmutable($meta['printed_at']))->format('d.m.Y H:i'));
        $headers = ['Číslo', 'Název', 'Partner', 'Účet', 'Protiúčet', 'Zdroj', 'Od', 'Do', 'Metoda', 'Základ', 'Rozpuštěno', 'Zůstatek', 'Stav', 'Příští rozpuštění'];
        $r = 5;
        foreach ($headers as $i => $h) {
            $this->text($sheet, $i + 1, $r, $h);
        }
        $sheet->getStyle([1, $r, count($headers), $r])->getFont()->setBold(true);
        $sheet->freezePane([1, $r + 1]);
        foreach ($book['rows'] as $row) {
            $r++;
            $this->text($sheet, 1, $r, (string) $row['item_no']);
            $this->text($sheet, 2, $r, (string) $row['title']);
            $this->text($sheet, 3, $r, (string) ($row['partner_name'] ?? ''));
            $this->text($sheet, 4, $r, (string) $row['accrual_account_code']);
            $this->text($sheet, 5, $r, (string) ($row['counter_account_code'] ?? ''));
            $this->text($sheet, 6, $r, (string) ($row['source_document_no'] ?? ''));
            $this->date($sheet, 7, $r, $row['period_from'] ?? null);
            $this->date($sheet, 8, $r, $row['period_to'] ?? null);
            $this->text($sheet, 9, $r, (string) $row['method']);
            $this->money($sheet, 10, $r, (float) $row['basis_amount']);
            $this->money($sheet, 11, $r, (float) $row['released']);
            $this->money($sheet, 12, $r, (float) $row['balance']);
            $this->text($sheet, 13, $r, (string) $row['status']);
            $this->date($sheet, 14, $r, $row['next_due_on'] ?? null);
        }
        $r++;
        $this->text($sheet, 1, $r, 'Celkem');
        $this->money($sheet, 10, $r, (float) $book['totals']['basis_amount']);
        $this->money($sheet, 11, $r, (float) $book['totals']['released']);
        $this->money($sheet, 12, $r, (float) $book['totals']['balance']);
        $sheet->getStyle([1, $r, 14, $r])->getFont()->setBold(true);

        $rec = $ss->createSheet();
        $rec->setTitle('Odsouhlasení');
        $rec->setCellValue('A1', 'Odsouhlasení s hlavní knihou k ' . self::czDate($meta['as_of']));
        $rec->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $r = 3;
        foreach (['Účet', 'Název', 'Hlavní kniha', 'Položky agendy', 'Odklad uzávěrky', 'Rozdíl'] as $i => $h) {
            $this->text($rec, $i + 1, $r, $h);
        }
        $rec->getStyle([1, $r, 6, $r])->getFont()->setBold(true);
        foreach ($book['reconciliation']['accounts'] as $a) {
            $r++;
            $this->text($rec, 1, $r, (string) $a['account_code']);
            $this->text($rec, 2, $r, (string) $a['account_name']);
            $this->money($rec, 3, $r, (float) $a['ledger_balance']);
            $this->money($rec, 4, $r, (float) $a['items_balance']);
            $this->money($rec, 5, $r, (float) $a['closing_balance']);
            $this->money($rec, 6, $r, (float) $a['difference']);
        }
        foreach ([$sheet, $rec] as $ws) {
            foreach (range(1, 14) as $col) {
                $ws->getColumnDimensionByColumn($col)->setAutoSize(true);
            }
        }
        $ss->setActiveSheetIndex(0);
        $tmp = fopen('php://memory', 'w+b');
        (new Xlsx($ss))->save($tmp);
        rewind($tmp);
        $bytes = (string) stream_get_contents($tmp);
        fclose($tmp);
        return ['bytes' => $bytes, 'mime' => self::MIME];
    }

    private function text(Worksheet $sheet, int $col, int $row, string $value): void
    {
        $sheet->setCellValueExplicit([$col, $row], $value, DataType::TYPE_STRING);
    }

    private function money(Worksheet $sheet, int $col, int $row, float $value): void
    {
        $sheet->setCellValue([$col, $row], round($value, 2));
        $sheet->getStyle([$col, $row])->getNumberFormat()->setFormatCode(self::MONEY);
    }

    private function date(Worksheet $sheet, int $col, int $row, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $sheet->setCellValue([$col, $row], Date::PHPToExcel(new \DateTimeImmutable(substr($value, 0, 10))));
        $sheet->getStyle([$col, $row])->getNumberFormat()->setFormatCode(self::DATE);
    }

    private static function czDate(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('j. n. Y');
    }
}

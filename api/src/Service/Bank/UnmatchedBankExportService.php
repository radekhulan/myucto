<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PDO;

final class UnmatchedBankExportService
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(private readonly Connection $db) {}

    /** @return array{account:string,count:int} */
    public function preview(int $supplierId, int $statementId): array
    {
        [$account] = $this->statement($supplierId, $statementId);
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM bank_transactions bt WHERE ' . $this->unmatchedWhere($supplierId, $statementId)
        );
        $stmt->execute();
        return ['account' => $account, 'count' => (int) $stmt->fetchColumn()];
    }

    /** @return array{filename:string,bytes:string,count:int,account:string} */
    public function build(int $supplierId, int $statementId): array
    {
        [$account, $statementDate] = $this->statement($supplierId, $statementId);
        $tx = $this->db->pdo()->prepare(
            'SELECT bt.id, bt.posted_at, bt.amount, bt.currency, bt.description,
                    bt.counterparty_name, bt.counterparty_account, bt.counterparty_bank, bt.variable_symbol
               FROM bank_transactions bt
              WHERE ' . $this->unmatchedWhere($supplierId, $statementId)
                . ' ORDER BY bt.posted_at, bt.id'
        );
        $tx->execute();
        $rows = $tx->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['own_account'] = $account;
        unset($row);
        return $this->buildRows($supplierId, $rows, $account, $statementDate, 'nesparovane-pohyby-' . $statementId . '.xlsx');
    }

    public function buildAll(int $supplierId, array $filters): array
    {
        $repository = new \MyInvoice\Repository\BankPostingSuggestionRepository($this->db);
        $filters['scope'] = 'all';
        $filters['status'] = 'unmatched';
        $filters['active_only'] = true;
        $rows = [];
        $offset = 0;
        do {
            $page = $repository->paginateUnposted($supplierId, 500, $offset, $filters);
            foreach ($page['items'] as $row) {
                $row['own_account'] = $this->accountDisplay((string) $row['account_number'], $row['bank_code'] ?? null);
                $rows[] = $row;
            }
            $offset += count($page['items']);
        } while ($page['items'] !== [] && $offset < $page['total']);
        return $this->buildRows($supplierId, $rows, 'Všechny vlastní účty', '', 'nesparovane-pohyby.xlsx');
    }

    private function buildRows(int $supplierId, array $rows, string $account, string $statementDate, string $filename): array
    {
        $notes = $this->notes($supplierId, array_map(static fn (array $row): int => (int) $row['id'], $rows));

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Nespárované pohyby');
        $sheet->setCellValue('A1', 'Nespárované bankovní pohyby');
        $sheet->mergeCells('A1:H1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);
        $sheet->setCellValueExplicit('A2', 'Účet: ' . $account, DataType::TYPE_STRING);
        $sheet->setCellValue('A3', 'Datum výpisu: ' . $statementDate);
        $headers = ['Bankovní účet', 'Datum platby', 'Textace', 'Protistrana', 'Účet protistrany', 'Částka', 'Měna', 'Poznámka'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 5], $header);
        }
        $sheet->getStyle('A5:H5')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A5:H5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('334155');
        $line = 6;
        foreach ($rows as $row) {
            $description = trim((string) ($row['description'] ?? ''));
            if ($description === '') $description = trim((string) ($row['counterparty_name'] ?? ''));
            $sheet->setCellValueExplicit("A{$line}", (string) $row['own_account'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$line}", (string) $row['posted_at'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$line}", $description, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$line}", trim((string) ($row['counterparty_name'] ?? '')), DataType::TYPE_STRING);
            $counterAccount = trim((string) ($row['counterparty_account'] ?? ''));
            $sheet->setCellValueExplicit("E{$line}", $counterAccount === '' ? '' : $this->accountDisplay($counterAccount, $row['counterparty_bank'] ?? null), DataType::TYPE_STRING);
            $sheet->setCellValue("F{$line}", (float) $row['amount']);
            $sheet->setCellValueExplicit("G{$line}", (string) ($row['currency'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("H{$line}", implode("\n", $notes[(int) $row['id']] ?? []), DataType::TYPE_STRING);
            $line++;
        }
        $sheet->getStyle('F6:F' . max(6, $line - 1))->getNumberFormat()->setFormatCode('[Color10]+#,##0.00;[Red]-#,##0.00;0.00');
        $sheet->getStyle('H6:H' . max(6, $line - 1))->getAlignment()->setWrapText(true);
        $sheet->freezePane('A6');
        $sheet->setAutoFilter('A5:H' . max(5, $line - 1));
        foreach (['A' => 24, 'B' => 16, 'C' => 55, 'D' => 32, 'E' => 24, 'F' => 18, 'G' => 10, 'H' => 55] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $path = tempnam(sys_get_temp_dir(), 'bank_export_');
        if ($path === false) throw new \RuntimeException('Nelze vytvořit dočasný soubor exportu.');
        try {
            (new Xlsx($book))->save($path);
            $bytes = file_get_contents($path);
        } finally {
            @unlink($path);
            $book->disconnectWorksheets();
        }

        return [
            'filename' => $filename,
            'bytes' => (string) $bytes,
            'count' => count($rows),
            'account' => $account,
        ];
    }

    /** @return array{string,string} */
    private function statement(int $supplierId, int $statementId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT bs.account_number, bs.bank_code, bs.statement_date
               FROM bank_statements bs
              WHERE bs.id = ? AND ' . BankStatementOwnershipResolver::sql()
        );
        $stmt->execute([$statementId, ...BankStatementOwnershipResolver::params($supplierId)]);
        $statement = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$statement) throw new \InvalidArgumentException('statement_not_found');

        $account = $this->accountDisplay((string) $statement['account_number'], $statement['bank_code'] ?? null);

        return [$account, (string) $statement['statement_date']];
    }

    private function accountDisplay(string $number, ?string $bankCode): string
    {
        $number = trim($number);
        $bankCode = trim((string) $bankCode);
        if ($bankCode !== '' && !str_contains($number, '/') && preg_match('/^[A-Z]{2}\d{2}/i', $number) !== 1) {
            $number .= '/' . $bankCode;
        }
        return $number;
    }

    private function unmatchedWhere(int $supplierId, int $statementId): string
    {
        return BankNoticeConfirmationScope::activeSql('bt') . ' AND ' . StatementTransactionScope::sql($statementId)
            . " AND bt.match_status = 'unmatched' AND NOT "
            . NonInvoiceBankTransactionScope::sql($supplierId, 'bt.id');
    }

    /** @param list<int> $transactionIds @return array<int,list<string>> */
    private function notes(int $supplierId, array $transactionIds): array
    {
        $result = [];
        foreach (array_chunk($transactionIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT je.source_id AS transaction_id, n.body
                   FROM journal_entries je
                   JOIN journal_entry_notes n ON n.entry_id = je.id
                  WHERE je.supplier_id = ? AND je.source_type = 'bank'
                    AND je.source_id IN ($placeholders)
                    AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                    AND n.supplier_id = je.supplier_id AND n.deleted_at IS NULL
                  ORDER BY je.source_id, n.pinned DESC, n.created_at DESC, n.id DESC"
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $note) {
                $result[(int) $note['transaction_id']][] = (string) $note['body'];
            }
        }
        return $result;
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\SalesQuote;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Psr\Http\Message\UploadedFileInterface;

final class QuoteWorkflow
{
    public function __construct(
        private readonly Connection $db,
        private readonly QuoteRepository $quotes,
        private readonly QuoteContent $content,
        private readonly QuoteFileStorage $storage,
        private readonly QuoteInvoiceService $invoices,
    ) {}

    public function list(int $supplierId, array $filters, bool $canReadInvoices): array
    {
        $result = $this->quotes->list($supplierId, $filters);
        $summaries = $canReadInvoices ? $this->invoices->listSummaries($supplierId, $result['items']) : [];
        foreach ($result['items'] as &$row) {
            $row['billing'] = $summaries[$row['id']]['billing'] ?? null;
            unset($row['sections'], $row['client_snapshot']);
        }
        unset($row);
        return $result;
    }

    public function detail(int $supplierId, int $id, bool $canReadInvoices, ?int $versionId = null): array
    {
        $header = $this->quotes->header($supplierId, $id);
        $version = $this->quotes->version($header, $versionId);
        $quote = $this->quotes->present($header, $version);
        $summary = $canReadInvoices && $quote['version_id'] === $quote['current_version_id']
            ? $this->invoices->summary($supplierId, $id, $quote)
            : ['documents' => [], 'billing' => null, 'quantities' => []];
        foreach ($quote['sections'] as &$section) {
            foreach ($section['items'] as &$item) {
                $item += $summary['quantities'][$item['id']] ?? [];
            }
            unset($item);
        }
        unset($section);
        $files = $this->quotes->files($supplierId, $id, $quote['version_id']);
        foreach ($files as &$file) {
            unset($file['storage_key']);
        }
        unset($file);
        $history = $this->quotes->history($supplierId, $id);
        return $quote + [
            'files' => $files,
            'history' => $history,
            'documents' => $summary['documents'],
            'billing' => $summary['billing'],
            'revisions' => $this->quotes->revisions($supplierId, $id),
            'send_pending' => array_any($history, static fn (array $event): bool => in_array($event['delivery_status'], ['pending', 'deferred'], true)),
        ];
    }

    public function create(int $supplierId, int $userId, array $body): int
    {
        $data = $this->content->normalize($supplierId, $body);
        $data['totals'] = QuoteContent::totals($data);
        return $this->quotes->transaction(function () use ($supplierId, $userId, $data): int {
            $pdo = $this->db->pdo();
            $year = (int) date('Y');
            $pdo->prepare(
                'INSERT INTO sales_quote_counters (supplier_id, series_year, last_number) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE last_number = last_number + 1'
            )->execute([$supplierId, $year]);
            $sequence = $pdo->prepare('SELECT last_number FROM sales_quote_counters WHERE supplier_id = ? AND series_year = ?');
            $sequence->execute([$supplierId, $year]);
            $number = sprintf('NAB-%04d-%04d', $year, (int) $sequence->fetchColumn());
            $pdo->prepare(
                'INSERT INTO sales_quotes (supplier_id, quote_number, title, client_id, project_id, currency_id, valid_until, total_without_vat, total_with_vat)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $supplierId, $number, $data['title'], $data['client_id'], $data['project_id'], $data['currency_id'], $data['valid_until'],
                $data['totals']['without_vat'], $data['totals']['with_vat'],
            ]);
            $id = (int) $pdo->lastInsertId();
            $version = $this->insertVersion($supplierId, $id, 1, $data);
            $pdo->prepare('UPDATE sales_quotes SET current_version_id = ? WHERE supplier_id = ? AND id = ?')->execute([$version, $supplierId, $id]);
            $this->quotes->event($supplierId, $id, $version, $userId, 'created');
            return $id;
        });
    }

    public function update(int $supplierId, int $id, array $body): void
    {
        $this->quotes->transaction(function () use ($supplierId, $id, $body): void {
            $header = $this->quotes->header($supplierId, $id, true);
            $this->quotes->guardMutable($header);
            if ((int) ($body['row_version'] ?? 0) !== (int) $header['row_version']) {
                throw new QuoteException('quote_edit_conflict', 'Nabídku mezitím někdo změnil. Načtěte aktuální údaje.', 409);
            }
            $version = $this->quotes->version($header);
            $data = $this->content->normalize($supplierId, $body, QuoteRepository::decode($version['data_json']));
            $data['totals'] = QuoteContent::totals($data);
            $pdo = $this->db->pdo();
            $pdo->prepare('UPDATE sales_quote_versions SET data_json = ? WHERE supplier_id = ? AND id = ?')
                ->execute([QuoteRepository::encode($data), $supplierId, $version['id']]);
            $pdo->prepare(
                'UPDATE sales_quotes SET title = ?, client_id = ?, project_id = ?, currency_id = ?, valid_until = ?,
                    total_without_vat = ?, total_with_vat = ?, row_version = row_version + 1 WHERE supplier_id = ? AND id = ?'
            )->execute([
                $data['title'], $data['client_id'], $data['project_id'], $data['currency_id'], $data['valid_until'],
                $data['totals']['without_vat'], $data['totals']['with_vat'], $supplierId, $id,
            ]);
        });
    }

    public function revise(int $supplierId, int $id, int $userId): void
    {
        $this->quotes->transaction(function () use ($supplierId, $id, $userId): void {
            $header = $this->quotes->header($supplierId, $id, true);
            $this->quotes->guardDelivery($header);
            if (!in_array($header['status'], ['sent', 'declined', 'withdrawn'], true)) {
                throw new QuoteException('quote_revision_conflict', 'Novou verzi lze založit z odeslané, odmítnuté nebo stažené nabídky.', 409);
            }
            $previous = $this->quotes->version($header);
            $data = QuoteRepository::decode($previous['data_json']);
            $versionId = $this->insertVersion($supplierId, $id, (int) $previous['revision'] + 1, $data);
            $pdo = $this->db->pdo();
            $pdo->prepare(
                'INSERT INTO sales_quote_version_files (version_id, file_id, mode, position)
                 SELECT ?, file_id, mode, position FROM sales_quote_version_files WHERE version_id = ?'
            )->execute([$versionId, $previous['id']]);
            $pdo->prepare(
                "UPDATE sales_quotes SET status = 'draft', current_version_id = ?, row_version = row_version + 1 WHERE supplier_id = ? AND id = ?"
            )->execute([$versionId, $supplierId, $id]);
            $this->quotes->event($supplierId, $id, $versionId, $userId, 'revised', ['previous_revision' => (int) $previous['revision']]);
        });
    }

    public function state(int $supplierId, int $id, int $userId, array $body): void
    {
        $target = $body['status'] ?? null;
        if (!in_array($target, ['accepted', 'declined', 'withdrawn', 'restore'], true)) {
            throw new QuoteException('quote_status', 'Zvolte platný stav nabídky.');
        }
        $note = QuoteContent::string($body['note'] ?? '', 10000, 'Záznam je příliš dlouhý.', true);
        $this->quotes->transaction(function () use ($supplierId, $id, $userId, $target, $note, $body): void {
            $header = $this->quotes->header($supplierId, $id, true);
            $this->quotes->guardDelivery($header);
            $allowedTransition = match ($target) {
                'restore' => $header['status'] === 'withdrawn',
                'withdrawn' => in_array($header['status'], ['draft', 'sent'], true),
                'accepted' => QuoteRepository::status($header['status'], $header['valid_until']) === 'sent',
                'declined' => $header['status'] === 'sent',
            };
            if (!$allowedTransition || (isset($body['row_version']) && (int) $body['row_version'] !== (int) $header['row_version'])) {
                throw new QuoteException('quote_transition', 'Nabídku v tomto stavu nelze takto změnit.', 409);
            }
            $version = $this->quotes->version($header);
            $status = $target === 'restore' ? ($version['pdf_key'] === null ? 'draft' : 'sent') : $target;
            $data = QuoteRepository::decode($version['data_json']);
            $selected = $status === 'accepted' ? ($body['optional_section_ids'] ?? []) : [];
            $allowed = array_column(array_filter($data['sections'], static fn (array $section): bool => $section['kind'] === 'optional'), 'id');
            if (!is_array($selected) || !array_is_list($selected) || count($selected) > count($allowed)) {
                throw new QuoteException('quote_options', 'Vyberte volitelné sekce z této verze.');
            }
            foreach ($selected as $sectionId) {
                if (!is_string($sectionId) || !in_array($sectionId, $allowed, true)) {
                    throw new QuoteException('quote_options', 'Vyberte volitelné sekce z této verze.');
                }
            }
            $selected = array_values(array_unique($selected));
            $totals = QuoteContent::totals($data, $selected);
            $pdo = $this->db->pdo();
            $pdo->prepare('UPDATE sales_quote_versions SET status = ? WHERE supplier_id = ? AND id = ?')
                ->execute([$status, $supplierId, $version['id']]);
            $pdo->prepare(
                'UPDATE sales_quotes SET status = ?, accepted_section_ids = ?, total_without_vat = ?, total_with_vat = ?,
                    row_version = row_version + 1 WHERE supplier_id = ? AND id = ?'
            )->execute([$status, QuoteRepository::encode($selected), $totals['without_vat'], $totals['with_vat'], $supplierId, $id]);
            $this->quotes->event($supplierId, $id, (int) $version['id'], $userId, $target === 'restore' ? 'restored' : $status, [
                'text' => $note, 'optional_section_ids' => $selected,
            ]);
        });
    }

    public function history(int $supplierId, int $id, int $userId, array $body): void
    {
        $kind = $body['kind'] ?? null;
        if (!in_array($kind, ['note', 'email', 'call'], true)) {
            throw new QuoteException('quote_history', 'Zvolte druh záznamu.');
        }
        $text = QuoteContent::string($body['text'] ?? null, 10000, 'Zadejte text záznamu do 10 000 znaků.');
        $header = $this->quotes->header($supplierId, $id);
        $this->quotes->event($supplierId, $id, (int) $header['current_version_id'], $userId, $kind, ['text' => $text]);
    }

    public function upload(int $supplierId, int $id, UploadedFileInterface $upload, string $mode): void
    {
        $stored = null;
        try {
            $this->quotes->transaction(function () use ($supplierId, $id, $upload, $mode, &$stored): void {
                $header = $this->quotes->header($supplierId, $id, true);
                $this->quotes->guardMutable($header);
                $versionId = (int) $header['current_version_id'];
                $files = $this->quotes->files($supplierId, $id, $versionId);
                if (count($files) >= 30 || array_sum(array_column($files, 'size_bytes')) + (int) $upload->getSize() > QuoteFileStorage::MAX_TOTAL_BYTES) {
                    throw new QuoteException('quote_file_limit', 'Nabídka může mít nejvýše 30 příloh a celkem 20 MB.');
                }
                try {
                    $stored = $this->storage->store($upload, $supplierId, $id, $mode);
                } catch (\InvalidArgumentException $e) {
                    throw new QuoteException('quote_file', $e->getMessage());
                }
                $pdo = $this->db->pdo();
                $pdo->prepare('INSERT INTO sales_quote_files (supplier_id, quote_id, name, mime_type, size_bytes, storage_key) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$supplierId, $id, $stored['name'], $stored['mime_type'], $stored['size_bytes'], $stored['storage_key']]);
                $fileId = (int) $pdo->lastInsertId();
                $position = $files === [] ? 1 : max(array_column($files, 'position')) + 1;
                $pdo->prepare('INSERT INTO sales_quote_version_files (version_id, file_id, mode, position) VALUES (?, ?, ?, ?)')
                    ->execute([$versionId, $fileId, $mode, $position]);
                $this->bump($supplierId, $id);
            });
        } catch (\Throwable $e) {
            if ($stored !== null) {
                $this->storage->delete($supplierId, $id, $stored['storage_key']);
            }
            throw $e;
        }
    }

    public function deleteFile(int $supplierId, int $id, int $fileId): void
    {
        $orphan = $this->quotes->transaction(function () use ($supplierId, $id, $fileId): ?string {
            $header = $this->quotes->header($supplierId, $id, true);
            $this->quotes->guardMutable($header);
            $version = $this->quotes->version($header);
            $file = $this->quotes->files($supplierId, $id, (int) $version['id'], $fileId)[0] ?? null;
            if ($file === null) {
                throw new QuoteException('quote_file_not_found', 'Příloha nabídky nenalezena.', 404);
            }
            foreach (QuoteRepository::decode($version['data_json'])['sections'] as $section) {
                if (preg_match('/quote-file:' . $fileId . '(?!\d)/', $section['markdown']) === 1) {
                    throw new QuoteException('quote_file_in_use', 'Nejprve odeberte obrázek z textu nabídky.', 409);
                }
            }
            $pdo = $this->db->pdo();
            $pdo->prepare('DELETE FROM sales_quote_version_files WHERE version_id = ? AND file_id = ?')
                ->execute([$version['id'], $fileId]);
            $this->bump($supplierId, $id);
            $references = $pdo->prepare(
                "SELECT 1 FROM sales_quote_version_files WHERE file_id = ?
                 UNION ALL SELECT 1 FROM sales_quote_events WHERE supplier_id = ? AND quote_id = ? AND version_id = ? AND event = 'send' LIMIT 1"
            );
            $references->execute([$fileId, $supplierId, $id, $version['id']]);
            if ($references->fetchColumn() !== false) {
                return null;
            }
            $pdo->prepare('DELETE FROM sales_quote_files WHERE supplier_id = ? AND quote_id = ? AND id = ?')
                ->execute([$supplierId, $id, $fileId]);
            return $file['storage_key'];
        });
        if ($orphan !== null) {
            $this->storage->delete($supplierId, $id, $orphan);
        }
    }

    public function file(int $supplierId, int $id, int $fileId, ?int $versionId): array
    {
        $version = $this->quotes->version($this->quotes->header($supplierId, $id), $versionId);
        $row = $this->quotes->files($supplierId, $id, (int) $version['id'], $fileId)[0] ?? null;
        if ($row === null) {
            throw new QuoteException('quote_file_not_found', 'Příloha nabídky nenalezena.', 404);
        }
        return $row + ['path' => $this->storage->path($supplierId, $id, $row['storage_key'])];
    }

    private function insertVersion(int $supplierId, int $id, int $revision, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO sales_quote_versions (supplier_id, quote_id, revision, data_json) VALUES (?, ?, ?, ?)')
            ->execute([$supplierId, $id, $revision, QuoteRepository::encode($data)]);
        return (int) $pdo->lastInsertId();
    }

    private function bump(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare('UPDATE sales_quotes SET row_version = row_version + 1 WHERE supplier_id = ? AND id = ?')
            ->execute([$supplierId, $id]);
    }
}

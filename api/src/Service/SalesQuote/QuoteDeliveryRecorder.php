<?php

declare(strict_types=1);

namespace MyInvoice\Service\SalesQuote;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class QuoteDeliveryRecorder
{
    public function __construct(private readonly Connection $db) {}

    public function record(int $supplierId, int $attemptId, string $status, array $details = []): void
    {
        if (!in_array($status, ['sent', 'failed', 'deferred'], true)) {
            throw new \InvalidArgumentException('Neplatný stav odeslání.');
        }
        $pdo = $this->db->pdo();
        $find = $pdo->prepare("SELECT quote_id FROM sales_quote_events WHERE supplier_id = ? AND id = ? AND event = 'send'");
        $find->execute([$supplierId, $attemptId]);
        $id = $find->fetchColumn();
        if ($id === false) {
            return;
        }

        $quotes = new QuoteRepository($this->db);
        $quotes->transaction(function () use ($pdo, $quotes, $supplierId, $attemptId, $status, $details, $id): void {
            $header = $quotes->header($supplierId, (int) $id, true);
            $query = $pdo->prepare("SELECT * FROM sales_quote_events WHERE supplier_id = ? AND quote_id = ? AND id = ? AND event = 'send' FOR UPDATE");
            $query->execute([$supplierId, $id, $attemptId]);
            $attempt = $query->fetch(PDO::FETCH_ASSOC);
            if ($attempt === false || !in_array($attempt['delivery_status'], ['pending', 'deferred'], true)) {
                return;
            }
            $data = QuoteRepository::decode($attempt['data_json']);
            $data['delivery'] = array_intersect_key($details, array_flip(['outbox_id', 'imap_append', 'warning']));
            $pdo->prepare('UPDATE sales_quote_events SET delivery_status = ?, data_json = ? WHERE supplier_id = ? AND id = ?')
                ->execute([$status, QuoteRepository::encode($data), $supplierId, $attemptId]);
            if ($status !== 'sent') {
                return;
            }
            $pdo->prepare(
                "UPDATE sales_quote_versions SET status = IF(status = 'draft', 'sent', status),
                    pdf_key = COALESCE(pdf_key, ?) WHERE supplier_id = ? AND id = ?"
            )->execute([$attempt['pdf_key'], $supplierId, $attempt['version_id']]);
            if ($header['status'] === 'draft' && (int) $header['current_version_id'] === (int) $attempt['version_id']) {
                $pdo->prepare(
                    "UPDATE sales_quotes SET status = 'sent', row_version = row_version + 1 WHERE supplier_id = ? AND id = ?"
                )->execute([$supplierId, $id]);
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\SalesQuote;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Psr\Log\LoggerInterface;

final class QuoteDelivery
{
    public function __construct(
        private readonly Connection $db,
        private readonly QuoteRepository $quotes,
        private readonly QuoteFileStorage $storage,
        private readonly QuotePdfRenderer $renderer,
        private readonly QuoteMailer $mailer,
        private readonly QuoteDeliveryRecorder $recorder,
        private readonly LoggerInterface $logger,
    ) {}

    public function defaults(int $supplierId, array $quote): array
    {
        return $this->mailer->defaults($supplierId, $quote);
    }

    public function pdf(int $supplierId, int $id, ?int $versionId, ?int $eventId = null): array
    {
        $head = $this->quotes->header($supplierId, $id);
        $version = $this->quotes->version($head, $versionId);
        $quote = $this->quotes->present($head, $version);
        $archive = $version['pdf_key'];
        if ($eventId !== null) {
            $event = $this->db->pdo()->prepare(
                "SELECT pdf_key, version_id FROM sales_quote_events WHERE supplier_id = ? AND quote_id = ? AND id = ? AND event = 'send'"
            );
            $event->execute([$supplierId, $id, $eventId]);
            $row = $event->fetch(PDO::FETCH_ASSOC);
            if ($row === false || $row['pdf_key'] === null) {
                throw new QuoteException('quote_pdf_not_found', 'PDF z tohoto odeslání nebylo nalezeno.', 404);
            }
            $archive = $row['pdf_key'];
            $version = $this->quotes->version($head, (int) $row['version_id']);
        }
        $name = $quote['number'] . '-v' . $version['revision'] . '.pdf';
        if ($archive !== null) {
            return ['name' => $name, 'path' => $this->storage->path($supplierId, $id, $archive)];
        }
        return [
            'name' => $name,
            'bytes' => $this->render($quote, $this->files($supplierId, $id, (int) $version['id'])),
        ];
    }

    public function historyFile(int $supplierId, int $id, int $eventId, int $fileId): array
    {
        $query = $this->db->pdo()->prepare(
            "SELECT e.data_json FROM sales_quote_events e WHERE e.supplier_id = ? AND e.quote_id = ? AND e.id = ? AND e.event = 'send'"
        );
        $query->execute([$supplierId, $id, $eventId]);
        $data = $query->fetchColumn();
        $files = $data === false ? [] : QuoteRepository::decode($data)['files'] ?? [];
        if (!in_array($fileId, array_column($files, 'id'), true)) {
            throw new QuoteException('quote_file_not_found', 'Příloha nabídky nenalezena.', 404);
        }
        $file = $this->db->pdo()->prepare('SELECT name, mime_type, storage_key FROM sales_quote_files WHERE supplier_id = ? AND quote_id = ? AND id = ?');
        $file->execute([$supplierId, $id, $fileId]);
        $row = $file->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new QuoteException('quote_file_not_found', 'Příloha nabídky nenalezena.', 404);
        }
        return $row + ['path' => $this->storage->path($supplierId, $id, $row['storage_key'])];
    }

    public function send(int $supplierId, int $id, int $userId, array $body): void
    {
        $key = QuoteContent::string($body['idempotency_key'] ?? null, 128, 'Chybí identifikátor odeslání.');
        $to = QuoteContent::string($body['to'] ?? null, 190, 'Zadejte e-mail příjemce.');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new QuoteException('quote_mail_to', 'Zadejte platný e-mail příjemce.');
        }
        $subject = QuoteContent::string($body['subject'] ?? null, 255, 'Zadejte předmět e-mailu.');
        if (str_contains($subject, "\n") || str_contains($subject, "\r")) {
            throw new QuoteException('quote_mail_subject', 'Předmět e-mailu musí být na jednom řádku.');
        }
        $message = QuoteContent::string($body['message'] ?? '', 10000, 'Text e-mailu je příliš dlouhý.', true);
        $recipient = ['to' => $to, 'subject' => $subject, 'message' => $message];
        $hash = hash('sha256', QuoteRepository::encode($recipient));
        $head = $this->quotes->header($supplierId, $id);
        if ($this->replayed($supplierId, $id, $key, $hash)) {
            return;
        }
        $this->guardCanSend($head, $body);
        $version = $this->quotes->version($head);
        $versionId = (int) $version['id'];
        $quote = $this->quotes->present($head, $version);
        if ($quote['sections'] === []) {
            throw new QuoteException('quote_empty', 'Před odesláním přidejte alespoň jednu sekci.');
        }
        $files = $this->files($supplierId, $id, $versionId);
        $archive = $version['pdf_key'];
        $newArchive = $archive === null;
        if ($newArchive) {
            $archive = $this->storage->archivePdf($supplierId, $id, $versionId, $this->render($quote, $files));
        }

        try {
            $attempt = $this->quotes->transaction(function () use ($supplierId, $id, $userId, $body, $key, $hash, $recipient, $head, $versionId, $archive, $files): ?int {
                $live = $this->quotes->header($supplierId, $id, true);
                if ($this->replayed($supplierId, $id, $key, $hash)) {
                    return null;
                }
                $this->guardCanSend($live, $body);
                if ($head['row_version'] !== $live['row_version']) {
                    throw new QuoteException('quote_edit_conflict', 'Nabídka se během přípravy změnila. Zkontrolujte náhled a zkuste akci znovu.', 409);
                }
                $data = $recipient + ['files' => array_values(array_map(static fn (array $file): array => [
                    'id' => (int) $file['id'], 'name' => $file['name'], 'mode' => $file['mode'],
                ], array_filter($files, static fn (array $file): bool => $file['mode'] !== 'internal')))];
                $pdo = $this->db->pdo();
                $pdo->prepare(
                    "INSERT INTO sales_quote_events (supplier_id, quote_id, version_id, user_id, event, data_json, delivery_status, pdf_key, idempotency_key, request_hash)
                     VALUES (?, ?, ?, ?, 'send', ?, 'pending', ?, ?, ?)"
                )->execute([$supplierId, $id, $versionId, $userId, QuoteRepository::encode($data), $archive, $key, $hash]);
                return (int) $pdo->lastInsertId();
            });
        } catch (\Throwable $e) {
            if ($newArchive) {
                $this->storage->delete($supplierId, $id, $archive);
            }
            throw $e;
        }
        if ($attempt === null) {
            if ($newArchive) {
                $this->storage->delete($supplierId, $id, $archive);
            }
            return;
        }

        try {
            $result = $this->mailer->send(
                $supplierId, $userId, $quote, $recipient + ['attempt_id' => $attempt],
                $this->storage->path($supplierId, $id, $archive), $files,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Odeslání nabídky selhalo.', ['quote_id' => $id, 'attempt_id' => $attempt, 'exception' => $e]);
            $this->recorder->record($supplierId, $attempt, 'failed');
            throw new QuoteException('quote_send_failed', 'E-mail se nepodařilo odeslat. Výsledek je v historii nabídky.', 502);
        }
        $this->recorder->record($supplierId, $attempt, $result['status'], $result);
    }

    private function replayed(int $supplierId, int $id, string $key, string $hash): bool
    {
        $query = $this->db->pdo()->prepare(
            "SELECT request_hash, delivery_status FROM sales_quote_events WHERE supplier_id = ? AND quote_id = ? AND idempotency_key = ? AND event = 'send'"
        );
        $query->execute([$supplierId, $id, $key]);
        $existing = $query->fetch(PDO::FETCH_ASSOC);
        if ($existing === false) {
            return false;
        }
        if (!hash_equals((string) $existing['request_hash'], $hash)) {
            throw new QuoteException('quote_idempotency_conflict', 'Tento identifikátor už patří jinému odeslání.', 409);
        }
        if ($existing['delivery_status'] === 'failed') {
            throw new QuoteException('quote_send_failed', 'Předchozí pokus selhal. Zahajte nové odeslání.', 502);
        }
        return true;
    }

    private function guardCanSend(array $head, array $body): void
    {
        $this->quotes->guardDelivery($head);
        if (!in_array($head['status'], ['draft', 'sent', 'accepted'], true)
            || ($head['status'] !== 'accepted' && $head['valid_until'] < date('Y-m-d'))
            || (isset($body['row_version']) && (int) $body['row_version'] !== (int) $head['row_version'])) {
            throw new QuoteException('quote_send_conflict', 'Tuto verzi nabídky nelze odeslat. Zkontrolujte stav a platnost.', 409);
        }
    }

    private function files(int $supplierId, int $id, int $versionId): array
    {
        $files = $this->quotes->files($supplierId, $id, $versionId);
        foreach ($files as &$file) {
            $file['path'] = $this->storage->path($supplierId, $id, $file['storage_key']);
        }
        unset($file);
        return $files;
    }

    private function render(array $quote, array $files): string
    {
        $query = $this->db->pdo()->prepare('SELECT * FROM supplier WHERE id = ?');
        $query->execute([$quote['supplier_id']]);
        $supplier = $query->fetch(PDO::FETCH_ASSOC);
        if ($supplier === false) {
            throw new QuoteException('quote_supplier', 'Firma nenalezena.', 404);
        }
        try {
            return $this->renderer->render($quote, $supplier, $files);
        } catch (\InvalidArgumentException $e) {
            throw new QuoteException('quote_pdf', $e->getMessage());
        }
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentRequestRepository;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Service\Document\DocumentException;
use MyInvoice\Service\Document\DocumentIngestService;
use MyInvoice\Service\Document\DocumentStorage;
use PDOException;
use Psr\Http\Message\UploadedFileInterface;

/** Uloží originál do DMS a založí nad ním účetně neutrální staging podání. */
final class PurchaseInvoiceSubmissionUploadService
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'isdoc', 'xml', 'isdocx'];
    private const KIND_HINTS = ['invoice', 'receipt', 'credit_note', 'advance', 'tax_document', 'other'];

    public function __construct(
        private readonly Connection $db,
        private readonly DocumentStorage $storage,
        private readonly DocumentIngestService $ingest,
        private readonly PurchaseInvoiceSubmissionRepository $submissions,
        private readonly DocumentRequestRepository $requests,
    ) {}

    /**
     * @return array{submission:array<string,mixed>,duplicate:bool}
     */
    public function submit(
        UploadedFileInterface $file,
        int $supplierId,
        ?int $userId,
        string $via,
        ?string $note = null,
        ?string $kindHint = null,
        ?int $bankTransactionId = null,
        ?int $supersedesSubmissionId = null,
    ): array {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new PurchaseInvoiceSubmissionException('upload_failed', 'Nahrání souboru selhalo.', 400);
        }
        return $this->ingest(
            basename(str_replace('\\', '/', trim((string) $file->getClientFilename()))),
            $supplierId,
            $userId,
            $via,
            $note,
            $kindHint,
            $bankTransactionId,
            $supersedesSubmissionId,
            static function (string $tmp) use ($file): void {
                $file->moveTo($tmp);
            },
        );
    }

    /**
     * Podání z bajtů, které už držíme v paměti (příloha e-mailu). Prochází TOUTÉŽ
     * cestou jako upload z prohlížeče — stejná validace přípony i magic bajtů,
     * stejný dedup, stejný zápis do DMS.
     *
     * @return array{submission:array<string,mixed>,duplicate:bool}
     */
    public function submitBytes(
        string $bytes,
        string $originalName,
        int $supplierId,
        ?int $userId,
        string $via,
        ?string $note = null,
        ?string $kindHint = null,
    ): array {
        return $this->ingest(
            basename(str_replace('\\', '/', trim($originalName))),
            $supplierId,
            $userId,
            $via,
            $note,
            $kindHint,
            null,
            null,
            static function (string $tmp) use ($bytes): void {
                if (@file_put_contents($tmp, $bytes) === false) {
                    throw new \RuntimeException('Obsah přílohy se nepodařilo zapsat do dočasného souboru.');
                }
            },
        );
    }

    /**
     * @param callable(string):void $writer Uloží originální bajty na předanou dočasnou cestu.
     * @return array{submission:array<string,mixed>,duplicate:bool}
     */
    private function ingest(
        string $originalName,
        int $supplierId,
        ?int $userId,
        string $via,
        ?string $note,
        ?string $kindHint,
        ?int $bankTransactionId,
        ?int $supersedesSubmissionId,
        callable $writer,
    ): array {
        if ($originalName === '') {
            throw new PurchaseInvoiceSubmissionException('no_filename', 'Soubor nemá platný název.', 400);
        }
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new PurchaseInvoiceSubmissionException(
                'unsupported_format',
                'Podporovány jsou PDF, JPG, PNG, ISDOC a ISDOCX.',
                415,
            );
        }
        if (!in_array($via, ['portal', 'document_request', 'staff', 'email'], true)) {
            throw new \InvalidArgumentException('Neplatný zdroj podání.');
        }
        $kindHint = $kindHint !== null && in_array($kindHint, self::KIND_HINTS, true) ? $kindHint : null;
        $note = trim((string) $note);
        $note = $note !== '' ? mb_substr($note, 0, 8000) : null;

        $old = null;
        if ($supersedesSubmissionId !== null) {
            $old = $this->submissions->find($supersedesSubmissionId, $supplierId);
            if ($old === null
                || (string) $old['status'] !== 'needs_information'
                || (int) ($old['replacement_submission_id'] ?? 0) > 0) {
                throw new PurchaseInvoiceSubmissionException(
                    'invalid_replacement',
                    'Nahrazované podání neexistuje, nečeká na doplnění nebo už bylo nahrazeno.',
                    409,
                );
            }

            // Náhrada mění originální bajty, ne obchodní kontext podání. Portál
            // posílá jen nový soubor, proto zachováme poznámku, typ i vazbu na
            // bankovní pohyb, pokud je klient výslovně nenahradil novou hodnotou.
            $note ??= isset($old['note']) ? (string) $old['note'] : null;
            $oldKindHint = isset($old['document_kind_hint']) ? (string) $old['document_kind_hint'] : null;
            if ($kindHint === null && in_array($oldKindHint, self::KIND_HINTS, true)) {
                $kindHint = $oldKindHint;
            }
            $oldBankTransactionId = (int) ($old['bank_transaction_id'] ?? 0);
            if ($bankTransactionId === null && $oldBankTransactionId > 0) {
                $bankTransactionId = $oldBankTransactionId;
            }
        }
        if ($bankTransactionId !== null && !$this->bankTransactionBelongsToSupplier($bankTransactionId, $supplierId)) {
            throw new PurchaseInvoiceSubmissionException(
                'invalid_bank_transaction',
                'Bankovní transakce neexistuje v aktivní firmě.',
                400,
            );
        }

        try {
            $tmp = $this->storage->tmpPath($supplierId);
        } catch (DocumentException $e) {
            throw new PurchaseInvoiceSubmissionException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        try {
            $writer($tmp);
        } catch (\Throwable) {
            @unlink($tmp);
            throw new PurchaseInvoiceSubmissionException('move_failed', 'Soubor se nepodařilo převzít.', 500);
        }

        try {
            $this->validateMagic($tmp, $extension);
            $sha256 = hash_file('sha256', $tmp);
            if (!is_string($sha256)) {
                throw new PurchaseInvoiceSubmissionException('hash_failed', 'Soubor se nepodařilo ověřit.', 500);
            }
            $existing = $this->submissions->findByHash($supplierId, $sha256);
            if ($existing !== null) {
                @unlink($tmp);
                if ($supersedesSubmissionId !== null) {
                    throw new PurchaseInvoiceSubmissionException(
                        'replacement_unchanged',
                        'Náhradní soubor je shodný s již předaným originálem.',
                        409,
                    );
                }
                return ['submission' => $existing, 'duplicate' => true];
            }

            $pdo = $this->db->pdo();
            $ownTransaction = !$pdo->inTransaction();
            if ($ownTransaction) $pdo->beginTransaction();
            try {
                $ingested = $this->ingest->ingestOriginalTemp(
                    $tmp,
                    $supplierId,
                    null,
                    $originalName,
                    $userId,
                );
                $documentId = (int) ($ingested['created_ids'][0] ?? 0);
                if ($documentId <= 0 || count($ingested['created_ids']) !== 1) {
                    throw new \RuntimeException('DMS nevytvořilo právě jeden originál.');
                }
                $id = $this->submissions->create($supplierId, [
                    'document_id' => $documentId,
                    'document_sha256' => $sha256,
                    'submitted_by' => $userId,
                    'submitted_via' => $via,
                    'note' => $note,
                    'document_kind_hint' => $kindHint,
                    'bank_transaction_id' => $bankTransactionId,
                    'supersedes_submission_id' => $supersedesSubmissionId,
                ]);
                if ($supersedesSubmissionId !== null) {
                    $this->requests->replaceSubmissionReference(
                        $supersedesSubmissionId,
                        $id,
                        $supplierId,
                    );
                }
                if ($ownTransaction) $pdo->commit();
            } catch (PDOException $e) {
                if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
                // Současný upload stejného obsahu: unikát vyhrál jiný request.
                if ((string) $e->getCode() === '23000') {
                    if ($supersedesSubmissionId !== null) {
                        $current = $this->submissions->find($supersedesSubmissionId, $supplierId);
                        if ((int) ($current['replacement_submission_id'] ?? 0) > 0) {
                            throw new PurchaseInvoiceSubmissionException(
                                'invalid_replacement',
                                'Podání už mezitím dostalo náhradní soubor.',
                                409,
                            );
                        }
                    }
                    $existing = $this->submissions->findByHash($supplierId, $sha256);
                    if ($existing !== null) return ['submission' => $existing, 'duplicate' => true];
                }
                throw $e;
            } catch (\Throwable $e) {
                if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $submission = $this->submissions->find($id, $supplierId);
            if ($submission === null) throw new \RuntimeException('Podání po založení nebylo nalezeno.');
            return ['submission' => $submission, 'duplicate' => false];
        } catch (DocumentException $e) {
            @unlink($tmp);
            throw new PurchaseInvoiceSubmissionException($e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (PurchaseInvoiceSubmissionException $e) {
            @unlink($tmp);
            throw $e;
        } finally {
            @unlink($tmp);
        }
    }

    private function validateMagic(string $path, string $extension): void
    {
        $head = (string) @file_get_contents($path, false, null, 0, 512);
        $valid = match ($extension) {
            'pdf' => str_starts_with($head, '%PDF-'),
            'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'png' => str_starts_with($head, "\x89PNG\r\n\x1A\n"),
            'isdoc', 'xml' => str_contains($head, '<'),
            'isdocx' => str_starts_with($head, "PK\x03\x04"),
            default => false,
        };
        if (!$valid) {
            throw new PurchaseInvoiceSubmissionException(
                'invalid_document',
                'Obsah souboru neodpovídá jeho příponě.',
                422,
            );
        }
    }

    private function bankTransactionBelongsToSupplier(int $id, int $supplierId): bool
    {
        if ($id <= 0 || $supplierId <= 0) return false;
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM bank_transactions transaction_row
               JOIN bank_statements statement_row ON statement_row.id = transaction_row.statement_id
              WHERE transaction_row.id = ? AND statement_row.supplier_id = ?
              LIMIT 1'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }
}

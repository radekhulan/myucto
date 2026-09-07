<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankEmailAttachmentIngestRepository;
use MyInvoice\Service\Import\InvoiceDocumentRecognizer;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Import\PdfTotalExtractor;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionUploadService;

/**
 * Vytáhne z e-mailu PDF přílohy, které vypadají jako doklad adresovaný naší firmě,
 * a založí z nich podání ve frontě Nákup → Příchozí doklady.
 *
 * Běží jako NADSTAVBA skenu bankovních avíz nad tímtéž IMAP účtem (opt-in
 * `ingest_pdf_invoices`), protože do schránky s avízy chodí i faktury od dodavatelů.
 *
 * Rozhodovací pořadí u jedné přílohy:
 *   1. není PDF (magic `%PDF`) → `rejected`,
 *   2. větší než {@see MAX_ATTACHMENT_BYTES} → `rejected`,
 *   3. hash už jsme jednou posuzovali → `skipped_duplicate` (bez opakované extrakce),
 *   4. PDF nese embedded ISDOC (PDF/A-3) → doklad JE strojově doložený, heuristika
 *      se přeskakuje,
 *   5. jinak {@see InvoiceDocumentRecognizer} nad textovou vrstvou,
 *   6. podání do fronty přes {@see PurchaseInvoiceSubmissionUploadService::submitBytes()}
 *      — stejná cesta jako upload z prohlížeče (dedup, DMS, magic check).
 *
 * Extrakce dat z dokladu se tu ZÁMĚRNĚ nespouští: podání zůstane ve frontě a účetní
 * ho pustí ručně (nebo automatikou fronty). Tím se z e-mailu nikdy nestane výdaj
 * bez lidského oka a nespotřebuje se AI call na dosud neschválenou přílohu.
 */
final class EmailPdfInvoiceIngestor
{
    /** Nad tenhle strop přílohu neřešíme (odpovídá limitu uploadu přijaté faktury). */
    public const MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024;

    /** Kolik příloh z jedné zprávy nejvýš posuzujeme (anti-DoS na zip-bomb maily). */
    private const MAX_ATTACHMENTS_PER_MESSAGE = 20;

    public function __construct(
        private readonly Connection $db,
        private readonly BankEmailAttachmentIngestRepository $log,
        private readonly InvoiceDocumentRecognizer $recognizer,
        private readonly PdfTotalExtractor $pdfText,
        private readonly PdfIsdocExtractor $pdfIsdoc,
        private readonly PurchaseInvoiceSubmissionUploadService $submissions,
    ) {}

    /**
     * @param array<string,mixed> $settings IMAP účet
     * @return array{enabled:bool,considered:int,imported:int,skipped:int,rejected:int,failed:int,details:list<array<string,mixed>>}
     */
    public function ingestFromMessage(int $supplierId, array $settings, BankEmailNoticeMessage $message): array
    {
        $summary = [
            'enabled' => !empty($settings['ingest_pdf_invoices']),
            'considered' => 0,
            'imported' => 0,
            'skipped' => 0,
            'rejected' => 0,
            'failed' => 0,
            'details' => [],
        ];
        if (!$summary['enabled'] || $message->attachments === []) {
            return $summary;
        }

        $identity = $this->ourIdentity($supplierId);
        $imapAccountId = isset($settings['id']) ? (int) $settings['id'] : null;
        $seen = 0;

        foreach ($message->attachments as $attachment) {
            if (!$attachment instanceof EmailAttachment) {
                continue;
            }
            if (++$seen > self::MAX_ATTACHMENTS_PER_MESSAGE) {
                break;
            }
            $summary['considered']++;
            $detail = $this->ingestAttachment($supplierId, $imapAccountId, $message, $attachment, $identity);
            $summary['details'][] = $detail;
            $bucket = match ((string) $detail['status']) {
                'imported' => 'imported',
                'skipped_duplicate', 'skipped_not_invoice' => 'skipped',
                'rejected' => 'rejected',
                default => 'failed',
            };
            $summary[$bucket]++;
        }

        return $summary;
    }

    /**
     * @param array{ic:?string,dic:?string,name:?string} $identity
     * @return array<string,mixed>
     */
    private function ingestAttachment(
        int $supplierId,
        ?int $imapAccountId,
        BankEmailNoticeMessage $message,
        EmailAttachment $attachment,
        array $identity,
    ): array {
        $base = [
            'supplier_id' => $supplierId,
            'imap_account_id' => $imapAccountId,
            'message_id' => $message->messageId,
            'sender' => $message->sender,
            'subject' => $message->subject,
            'filename' => $attachment->filename,
            'sha256' => $attachment->sha256(),
            'size_bytes' => $attachment->size(),
        ];

        if (!$attachment->isPdf()) {
            return $this->record($base, 'rejected', 'Příloha není PDF.');
        }
        if ($attachment->size() > self::MAX_ATTACHMENT_BYTES) {
            return $this->record($base, 'rejected', 'Příloha je větší než 20 MiB.');
        }

        // Posuzovali jsme tenhle obsah už dřív? Pak ani neextrahujeme text —
        // rozhodnutí (i to zamítavé) drží log a nemá se měnit sám od sebe.
        $known = $this->log->findBySha($supplierId, $base['sha256']);
        if ($known !== null) {
            return $this->record(
                $base,
                'skipped_duplicate',
                'Stejná příloha už byla posouzena (' . (string) $known['status'] . ').',
                submissionId: isset($known['submission_id']) ? (int) $known['submission_id'] : null,
                persist: false,
            ) + ['known_id' => (int) $known['id']];
        }

        try {
            $embeddedIsdoc = $this->pdfIsdoc->extract($attachment->content) !== null;
        } catch (\Throwable) {
            $embeddedIsdoc = false;
        }

        $matchedBy = null;
        $matchScore = null;
        if ($embeddedIsdoc) {
            $matchedBy = 'isdoc';
            $matchScore = 100.0;
        } else {
            $text = (string) ($this->pdfText->extractText($attachment->content) ?? '');
            $verdict = $this->recognizer->recognize($text, $identity);
            if (!$verdict['is_invoice']) {
                return $this->record($base, 'skipped_not_invoice', (string) $verdict['reason']);
            }
            $matchedBy = $verdict['matched_by'] !== null ? (string) $verdict['matched_by'] : null;
            $matchScore = $verdict['match_score'] !== null ? (float) $verdict['match_score'] : null;
        }

        try {
            $result = $this->submissions->submitBytes(
                $attachment->content,
                $this->safeFilename($attachment->filename),
                $supplierId,
                null,
                'email',
                'Automaticky převzato z e-mailu od ' . $message->sender
                    . ($message->subject !== '' ? ' — ' . $message->subject : ''),
            );
        } catch (\Throwable $e) {
            return $this->record($base, 'failed', 'Založení podání selhalo: ' . $e->getMessage());
        }

        $submissionId = (int) ($result['submission']['id'] ?? 0);
        $reason = !empty($result['duplicate'])
            ? 'Doklad už ve frontě příchozích dokladů je.'
            : ($embeddedIsdoc
                ? 'PDF obsahuje strojový ISDOC — přidáno do příchozích dokladů.'
                : 'Rozpoznán doklad naší firmy — přidáno do příchozích dokladů.');

        return $this->record(
            $base,
            !empty($result['duplicate']) ? 'skipped_duplicate' : 'imported',
            $reason,
            submissionId: $submissionId > 0 ? $submissionId : null,
            matchedBy: $matchedBy,
            matchScore: $matchScore,
        );
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private function record(
        array $base,
        string $status,
        string $reason,
        ?int $submissionId = null,
        ?string $matchedBy = null,
        ?float $matchScore = null,
        bool $persist = true,
    ): array {
        $row = $base + [
            'status' => $status,
            'reason' => $reason,
            'submission_id' => $submissionId,
            'matched_by' => $matchedBy,
            'match_score' => $matchScore,
        ];
        if ($persist) {
            // Zápis logu nesmí shodit sken avíz — auditní stopa je nadstavba.
            try {
                $this->log->record($row);
            } catch (\Throwable) {
            }
        }
        return $row;
    }

    /**
     * Název souboru pro DMS. Fronta příchozích dokladů přijímá jen známé přípony,
     * takže přílohu bez `.pdf` (typicky `application/octet-stream` bez názvu)
     * přejmenujeme — obsah už magic checkem prošel.
     */
    private function safeFilename(string $filename): string
    {
        $name = trim(basename(str_replace('\\', '/', $filename)));
        $name = (string) preg_replace('/[\x00-\x1F]/', '', $name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'priloha.pdf';
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
            $name .= '.pdf';
        }
        return mb_substr($name, 0, 200);
    }

    /**
     * Identita naší firmy pro kontrolu adresáta.
     *
     * @return array{ic:?string,dic:?string,name:?string}
     */
    private function ourIdentity(int $supplierId): array
    {
        try {
            $stmt = $this->db->pdo()->prepare('SELECT company_name, ic, dic FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            $row = [];
        }
        return [
            'ic' => isset($row['ic']) && (string) $row['ic'] !== '' ? (string) $row['ic'] : null,
            'dic' => isset($row['dic']) && (string) $row['dic'] !== '' ? (string) $row['dic'] : null,
            'name' => isset($row['company_name']) && (string) $row['company_name'] !== '' ? (string) $row['company_name'] : null,
        ];
    }
}

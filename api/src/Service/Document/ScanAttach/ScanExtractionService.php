<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document\ScanAttach;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentExtractionRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Service\Import\ImageToPdfConverter;
use MyInvoice\Service\Import\LlmGatewayInterface;

/**
 * AI vytěžení skenu pro párování s existujícím dokladem.
 *
 * Výsledek se ukládá k obsahu dokumentu ({@see DocumentExtractionRepository}),
 * takže stejný sken se znovu nevytěžuje — ani v další dávce, ani jako kopie.
 * Vytěžené údaje se zároveň zapíšou jako text dokumentu: sken bez textové vrstvy
 * pak jde najít fulltextem v sekci Dokumenty.
 *
 * Firma může být na skenu odběratel i dodavatel, proto se volá v režimu
 * {@see LlmGatewayInterface::TENANT_ROLE_ANY} a model roli firmy vrátí sám.
 */
final class ScanExtractionService
{
    private const MAX_BYTES = UploadedScanSource::MAX_FILE_BYTES;

    /** Chyby, po kterých nemá smysl zkoušet další soubory dávky. */
    private const FATAL_ERRORS = ['provider_not_configured', 'residency_conflict'];

    public function __construct(
        private readonly LlmGatewayInterface $llm,
        private readonly DocumentExtractionRepository $extractions,
        private readonly DocumentRepository $documents,
        private readonly ImageToPdfConverter $images,
        private readonly Connection $db,
    ) {}

    /** Má firma nastavenou AI? Bez ní se páruje jen podle čárového kódu a čísla v názvu. */
    public function isConfigured(int $supplierId): bool
    {
        return $this->llm->getCredentials($supplierId) !== null;
    }

    /**
     * @return array{status:string, extraction:?array<string,mixed>, error:?string, fatal:bool}
     *         status: `ok` (nově vytěženo), `cached` (už uloženo), `failed`
     */
    public function extract(int $supplierId, int $documentId, string $sha256, string $absPath): array
    {
        $version = ScanExtractionNormalizer::SCHEMA_VERSION;
        $cached = $this->extractions->findOkBySha($supplierId, $sha256, $version);
        if ($cached !== null) {
            $this->ensureFulltext($supplierId, $documentId, $cached);
            return ['status' => 'cached', 'extraction' => $cached, 'error' => null, 'fatal' => false];
        }

        $bytes = is_file($absPath) ? (string) file_get_contents($absPath) : '';
        if ($bytes === '') {
            return $this->fail($supplierId, $documentId, $sha256, 'file_missing');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return $this->fail($supplierId, $documentId, $sha256, 'too_large');
        }
        if (!str_starts_with($bytes, '%PDF')) {
            $mime = $this->images->detectImageMime($bytes);
            if ($mime === null || !$this->images->isSupportedImage($mime)) {
                return $this->fail($supplierId, $documentId, $sha256, 'unsupported_type');
            }
            try {
                $bytes = $this->images->convert($bytes, $mime);
            } catch (\Throwable $e) {
                return $this->fail($supplierId, $documentId, $sha256, 'image_convert_failed: ' . $e->getMessage());
            }
        }

        $res = $this->llm->extractInvoice($supplierId, $bytes, null, LlmGatewayInterface::TENANT_ROLE_ANY);
        if (!($res['ok'] ?? false) || !is_array($res['data'] ?? null)) {
            $error = (string) ($res['error'] ?? 'extraction_failed');
            return $this->fail($supplierId, $documentId, $sha256, $error, in_array($error, self::FATAL_ERRORS, true));
        }

        $fields = ScanExtractionNormalizer::normalize($res['data']);
        $this->extractions->save(
            $supplierId, $documentId, $sha256, $version, 'ok', $fields, $res['data'],
            isset($res['provider']) ? (string) $res['provider'] : null,
            isset($res['model']) ? (string) $res['model'] : null,
            null,
        );
        $row = $this->extractions->findOkBySha($supplierId, $sha256, $version) ?? $fields;
        $this->ensureFulltext($supplierId, $documentId, $row);

        return ['status' => 'ok', 'extraction' => $row, 'error' => null, 'fatal' => false];
    }

    /** @return array{status:string, extraction:null, error:string, fatal:bool} */
    private function fail(int $supplierId, int $documentId, string $sha256, string $error, bool $fatal = false): array
    {
        if (!$fatal) {
            $this->extractions->save(
                $supplierId, $documentId, $sha256, ScanExtractionNormalizer::SCHEMA_VERSION,
                'failed', [], null, null, null, $error,
            );
        }
        return ['status' => 'failed', 'extraction' => null, 'error' => $error, 'fatal' => $fatal];
    }

    /**
     * Digitální PDF má vlastní textovou vrstvu — tu vytěžení nepřepisuje. Doplní
     * text jen tam, kde žádný není (sken, fotka).
     *
     * @param array<string,mixed> $extraction
     */
    private function ensureFulltext(int $supplierId, int $documentId, array $extraction): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT text_status, content_text FROM documents WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$documentId, $supplierId]);
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($doc === false) {
            return;
        }
        if ($doc['text_status'] === 'extracted' && trim((string) $doc['content_text']) !== '') {
            return;
        }
        $this->documents->setText($documentId, ScanExtractionNormalizer::fulltext($extraction), 'extracted');
    }
}

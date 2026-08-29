<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Service\Document\DocumentException;
use MyInvoice\Service\Document\DocumentIngestService;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Report\TaxSubmissionFilename;

final class TaxSubmissionDocumentService
{
    private const INCOME_FORMS = ['dpfdp5', 'dpfdp7', 'dppdp9'];

    public function __construct(
        private readonly Connection $db,
        private readonly TaxSubmissionEpoRepository $epo,
        private readonly DocumentFolderRepository $folders,
        private readonly DocumentIngestService $ingest,
        private readonly DocumentStorage $storage,
        private readonly EpoConfirmationParser $confirmationParser,
        private readonly EpoSubmissionXmlComparator $xmlComparator,
        private readonly EpoReceiptPdfReader $pdfReader,
    ) {}

    /**
     * @param array<string,mixed> $submission
     * @return array<string,mixed>
     */
    public function ensureSourceXml(
        array $submission,
        int $supplierId,
        ?int $attemptId,
        ?int $userId,
    ): array {
        $existing = $this->epo->sourceArtifact((int) $submission['id'], $supplierId);
        if ($existing !== null) {
            return $existing;
        }

        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            if ($this->epo->lockSubmission((int) $submission['id'], $supplierId) === null) {
                throw new EpoSubmissionException('not_found', 'Podání nebylo nalezeno.', 404);
            }
            $existing = $this->epo->sourceArtifact((int) $submission['id'], $supplierId);
            if ($existing !== null) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return $existing;
            }

            $xml = (string) $submission['xml_content'];
            $sha256 = hash('sha256', $xml);
            if (!hash_equals((string) $submission['xml_sha256'], $sha256)) {
                throw new EpoSubmissionException(
                    'snapshot_changed',
                    'Archivovaný XML snapshot neodpovídá uloženému otisku.',
                    409,
                );
            }
            $folderId = $this->ensureSubmissionFolder($submission, $supplierId, $userId);
            $filename = $this->sourceFilename($submission);
            $tmp = $this->storage->tmpPath($supplierId);
            if (file_put_contents($tmp, $xml) === false) {
                @unlink($tmp);
                throw new EpoSubmissionException(
                    'archive_failed',
                    'Nepodařilo se uložit zdrojové XML do Dokumentů.',
                    500,
                );
            }

            $result = $this->ingest->ingestUploadedTemp(
                $tmp,
                $supplierId,
                $folderId,
                $filename,
                $userId,
            );
            $documentId = (int) ($result['created_ids'][0] ?? 0);
            if ($documentId <= 0) {
                throw new EpoSubmissionException(
                    'archive_failed',
                    'Nepodařilo se vytvořit dokument zdrojového XML.',
                    500,
                );
            }

            $this->epo->addArtifact(
                $supplierId,
                (int) $submission['id'],
                $attemptId,
                $documentId,
                'source_xml',
                $sha256,
                'valid',
                ['snapshot_sha256_match' => true],
                $userId,
            );

            $artifact = $this->epo->artifactByKindAndSha(
                (int) $submission['id'],
                $supplierId,
                'source_xml',
                $sha256,
            ) ?? ['document_id' => $documentId, 'folder_id' => $folderId];
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $artifact;
        } catch (EpoSubmissionException $e) {
            if (isset($tmp)) {
                @unlink($tmp);
            }
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } catch (\Throwable) {
            if (isset($tmp)) {
                @unlink($tmp);
            }
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new EpoSubmissionException(
                'archive_failed',
                'Nepodařilo se uložit zdrojové XML do Dokumentů.',
                500,
            );
        }
    }

    /**
     * Uloží ručně nahraný výstup z EPO a přečte z něj, co jde.
     *
     * `confirmation` v odpovědi nese to, co dodejka DOKAZUJE (podací číslo, rozhodný čas
     * a heslo pro dotaz na stav), `hint` naopak jen to, co se povedlo VYČÍST z tisku
     * (PDF opis). Rozdíl je podstatný: podle prvního se smí posunout stav pokusu, druhé
     * slouží výhradně k předvyplnění formuláře.
     *
     * @param array<string,mixed> $submission
     * @return array{
     *   artifact:array<string,mixed>,
     *   confirmation:?array<string,mixed>,
     *   hint:?array<string,mixed>
     * }
     */
    public function ingestArtifact(
        string $tmpPath,
        string $originalName,
        array $submission,
        int $supplierId,
        ?int $attemptId,
        ?int $userId,
        string $environment = 'production',
    ): array {
        $kind = $this->artifactKind($originalName);
        $sha256 = hash_file('sha256', $tmpPath);
        if ($sha256 === false) {
            throw new EpoSubmissionException('hash_failed', 'Nepodařilo se ověřit soubor.', 500);
        }
        $existing = $this->epo->artifactByKindAndSha(
            (int) $submission['id'],
            $supplierId,
            $kind,
            $sha256,
        );
        if ($existing !== null) {
            // Týž soubor podruhé. Metadata z něj už jednou přečtená leží u artefaktu,
            // takže se znovu neparsuje — ale to, co z nich potřebuje volající, se vrátí
            // i teď: účetní, která dodejku nahraje podruhé, musí dostat stejné předvyplnění.
            @unlink($tmpPath);
            return [
                'artifact' => $existing,
                'confirmation' => $this->confirmationFromExisting($existing),
                'hint' => $this->hintFromExisting($existing),
            ];
        }

        $verification = null;
        $verificationStatus = 'not_applicable';
        $confirmation = null;
        $hint = null;
        if ($kind === 'confirmation_p7s') {
            $verification = $this->confirmationParser->parse(
                $tmpPath,
                (string) $submission['xml_content'],
                (string) $submission['form_code'],
                $environment,
            );
            // Heslo pro dotaz na stav se do metadat artefaktu NESMÍ dostat — ta API vrací
            // u KAŽDÉHO souboru v seznamu. Volající si ho odsud vezme a uloží zvlášť,
            // zašifrované ({@see \MyInvoice\Service\Epo\EpoAssistedConfirmationService}).
            $statePassword = $verification['state_password'];
            unset($verification['state_password']);
            $verification['epo_environment'] = $environment;
            $verificationStatus = $this->verificationStatus($verification);
            $confirmation = [
                'reference' => $verification['reference'],
                'submitted_at' => $verification['submitted_at'],
                'state_password' => is_string($statePassword) && $statePassword !== ''
                    ? $statePassword
                    : null,
                'verification_status' => $verificationStatus,
                'is_confirmation' => (bool) $verification['is_confirmation'],
                'receipt' => $verification['receipt'] ?? [],
            ];
        } elseif ($kind === 'epo_xml') {
            $snapshotMatch = hash_equals((string) $submission['xml_sha256'], $sha256);
            $verification = ['snapshot_sha256_match' => $snapshotMatch];
            if (!$snapshotMatch) {
                // Samotná neshoda otisku je slepá ulička — teprve rozdíl po položkách
                // ukáže, jestli se v EPO upravila hodnota (pak snapshot neodpovídá
                // podanému), nebo jestli byl nahrán soubor od jiného podání.
                $verification['diff'] = $this->xmlComparator->compare(
                    (string) $submission['xml_content'],
                    (string) file_get_contents($tmpPath),
                );
            }
            $verificationStatus = $snapshotMatch ? 'valid' : 'warning';
        } elseif ($kind === 'receipt_pdf') {
            $read = $this->pdfReader->read($tmpPath);
            if ($read['reference'] !== null || $read['submitted_at'] !== null) {
                $hint = $read;
                $verification = ['hint' => $read];
            }
        }

        $folderId = $this->ensureSubmissionFolder($submission, $supplierId, $userId);
        try {
            $result = $this->ingest->ingestUploadedTemp(
                $tmpPath,
                $supplierId,
                $folderId,
                $originalName,
                $userId,
            );
        } catch (\Throwable) {
            @unlink($tmpPath);
            throw new EpoSubmissionException(
                'artifact_store_failed',
                'Soubor se nepodařilo uložit do Dokumentů.',
                500,
            );
        }
        $documentId = (int) ($result['created_ids'][0] ?? 0);
        if ($documentId <= 0) {
            throw new EpoSubmissionException(
                'artifact_store_failed',
                'Soubor se nepodařilo uložit do Dokumentů.',
                500,
            );
        }

        $this->epo->addArtifact(
            $supplierId,
            (int) $submission['id'],
            $attemptId,
            $documentId,
            $kind,
            $sha256,
            $verificationStatus,
            $verification,
            $userId,
        );

        $artifact = $this->epo->artifactByKindAndSha(
            (int) $submission['id'],
            $supplierId,
            $kind,
            $sha256,
        ) ?? ['document_id' => $documentId, 'folder_id' => $folderId];

        return ['artifact' => $artifact, 'confirmation' => $confirmation, 'hint' => $hint];
    }

    /**
     * Co o dodejce víme z DŘÍV uloženého artefaktu. Heslo pro dotaz na stav mezi tím
     * není a být nemůže — do metadat se zásadně neukládá, takže opakované nahrání téhož
     * souboru umí předvyplnit formulář, ale heslo doplní jen to první.
     *
     * @param array<string,mixed> $artifact
     * @return array<string,mixed>|null
     */
    private function confirmationFromExisting(array $artifact): ?array
    {
        if ((string) ($artifact['artifact_kind'] ?? '') !== 'confirmation_p7s') {
            return null;
        }
        $verification = is_array($artifact['verification'] ?? null) ? $artifact['verification'] : [];
        if ($verification === []) {
            return null;
        }
        return [
            'reference' => $verification['reference'] ?? null,
            'submitted_at' => $verification['submitted_at'] ?? null,
            'state_password' => null,
            'verification_status' => (string) ($artifact['verification_status'] ?? 'not_applicable'),
            'is_confirmation' => (bool) ($verification['is_confirmation'] ?? false),
            'receipt' => is_array($verification['receipt'] ?? null) ? $verification['receipt'] : [],
        ];
    }

    /**
     * @param array<string,mixed> $artifact
     * @return array<string,mixed>|null
     */
    private function hintFromExisting(array $artifact): ?array
    {
        $verification = is_array($artifact['verification'] ?? null) ? $artifact['verification'] : [];
        return is_array($verification['hint'] ?? null) ? $verification['hint'] : null;
    }

    /**
     * @param array<string,mixed> $submission
     * @param array<string,mixed>|null $verification
     * @return array<string,mixed>
     */
    public function storeGeneratedArtifact(
        string $bytes,
        string $filename,
        string $kind,
        array $submission,
        int $supplierId,
        ?int $attemptId,
        ?int $userId,
        string $verificationStatus = 'not_applicable',
        ?array $verification = null,
    ): array {
        if ($bytes === '') {
            throw new EpoSubmissionException('empty_file', 'Generovaný soubor je prázdný.', 500);
        }
        $sha256 = hash('sha256', $bytes);
        $existing = $this->epo->artifactByKindAndSha(
            (int) $submission['id'],
            $supplierId,
            $kind,
            $sha256,
        );
        if ($existing !== null) {
            // Soubor je bajtově týž (klíčem je jeho hash), ale to, co o něm aplikace VÍ, se
            // mohlo zpřesnit — odvozování metadat je kód a ten se vyvíjí. Bez přepisu by
            // dodejka archivovaná starší verzí zůstala navždy bez podacího čísla i identity
            // podepisujícího a detail podání by tvrdil, že v ní nic není.
            $current = is_array($existing['verification'] ?? null) ? $existing['verification'] : null;
            $wanted = $verification === [] ? null : $verification;
            if (
                $wanted !== null
                && ($wanted !== $current
                    || (string) ($existing['verification_status'] ?? '') !== $verificationStatus)
            ) {
                $this->epo->refreshArtifactVerification(
                    (int) $existing['id'],
                    $supplierId,
                    $verificationStatus,
                    $wanted,
                );
                $existing['verification'] = $wanted;
                $existing['verification_status'] = $verificationStatus;
            }
            return $existing;
        }

        $tmp = $this->storage->tmpPath($supplierId);
        if (file_put_contents($tmp, $bytes) === false) {
            @unlink($tmp);
            throw new EpoSubmissionException(
                'artifact_store_failed',
                'Generovaný soubor se nepodařilo uložit.',
                500,
            );
        }
        $folderId = $this->ensureSubmissionFolder($submission, $supplierId, $userId);
        try {
            $result = $this->ingest->ingestUploadedTemp(
                $tmp,
                $supplierId,
                $folderId,
                $filename,
                $userId,
            );
        } catch (\Throwable) {
            @unlink($tmp);
            throw new EpoSubmissionException(
                'artifact_store_failed',
                'Generovaný soubor se nepodařilo uložit do Dokumentů.',
                500,
            );
        }
        $documentId = (int) ($result['created_ids'][0] ?? 0);
        if ($documentId <= 0) {
            throw new EpoSubmissionException(
                'artifact_store_failed',
                'Generovaný soubor se nepodařilo uložit do Dokumentů.',
                500,
            );
        }
        $this->epo->addArtifact(
            $supplierId,
            (int) $submission['id'],
            $attemptId,
            $documentId,
            $kind,
            $sha256,
            $verificationStatus,
            $verification,
            $userId,
        );
        return $this->epo->artifactByKindAndSha(
            (int) $submission['id'],
            $supplierId,
            $kind,
            $sha256,
        ) ?? ['document_id' => $documentId, 'folder_id' => $folderId];
    }

    public function validateArtifactFile(string $tmpPath, string $originalName): void
    {
        $this->artifactKind($originalName);
        $size = is_file($tmpPath) ? (int) filesize($tmpPath) : 0;
        if ($size <= 0) {
            throw new EpoSubmissionException('empty_file', 'Soubor je prázdný.', 400);
        }
        if ($size > $this->storage->maxFileBytes()) {
            throw new EpoSubmissionException('file_too_large', 'Soubor je příliš velký.', 413);
        }

        try {
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $this->storage->classify($extension, $this->storage->detectMime($tmpPath));
        } catch (DocumentException $e) {
            throw new EpoSubmissionException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    /** @param array<string,mixed> $submission */
    public function ensureSubmissionFolder(array $submission, int $supplierId, ?int $userId): ?int
    {
        $settings = $this->epo->settings($supplierId);
        $income = in_array((string) $submission['form_code'], self::INCOME_FORMS, true);
        $root = $income
            ? $settings['income_tax_root_folder_id']
            : $settings['vat_root_folder_id'];
        if ($root !== null && $this->folders->find($root, $supplierId) === null) {
            $root = null;
        }

        $segments = [];
        if ($root === null) {
            $segments[] = $income ? 'Daň z příjmů' : 'DPH a hlášení';
        }
        $segments[] = (string) $submission['period_year'];
        if ($submission['period_month'] !== null) {
            $segments[] = sprintf('%02d', (int) $submission['period_month']);
        } elseif ($submission['period_quarter'] !== null) {
            $segments[] = 'Q' . (int) $submission['period_quarter'];
        }
        $segments[] = $this->formFolder((string) $submission['form_code']);

        return $this->ingest->ensureFolderPath($supplierId, $root, $segments, $userId);
    }

    public function artifactKind(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($ext) {
            'p7s', 'p7m' => 'confirmation_p7s',
            'pdf' => 'receipt_pdf',
            'xml' => 'epo_xml',
            default => throw new EpoSubmissionException(
                'unsupported_artifact',
                'Lze nahrát pouze XML, PDF nebo potvrzení P7S.',
                415,
            ),
        };
    }

    /** @param array<string,mixed>|null $verification */
    private function verificationStatus(?array $verification): string
    {
        if (
            $verification === null
            || !($verification['signature_valid'] ?? false)
            || !($verification['is_confirmation'] ?? false)
            || ($verification['form_match'] ?? null) === false
            || ($verification['content_match'] ?? null) === false
        ) {
            return 'invalid';
        }
        return ($verification['chain_valid'] ?? false)
            && ($verification['epo_signer_valid'] ?? false)
            && ($verification['content_match'] ?? null) === true
                ? 'valid'
                : 'warning';
    }

    /** @param array<string,mixed> $submission */
    private function sourceFilename(array $submission): string
    {
        return TaxSubmissionFilename::forSnapshot($submission, 'source.xml');
    }

    private function formFolder(string $formCode): string
    {
        return match ($formCode) {
            'dphdp3' => 'DPH',
            'dphkh1' => 'Kontrolní hlášení',
            'dphshv' => 'Souhrnné hlášení',
            'dpfdp5', 'dpfdp7' => 'DPFO',
            'dppdp9' => 'DPPO',
            'ossei1' => 'OSS',
            'dpzmb1', 'dpzdb1' => 'Daňové bonusy',
            'dpzvd6', 'dpsvd2' => 'Vyúčtování daně ze závislé činnosti',
            default => strtoupper($formCode),
        };
    }
}

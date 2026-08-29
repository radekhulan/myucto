<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxSubmissionEpoRepository;

final class EpoSubmissionService
{
    private const SUPPORTED_FORMS = [
        'dphdp3', 'dphkh1', 'dphshv', 'dpfdp5', 'dpfdp7', 'dppdp9',
        'dpzvd6', 'dpsvd2', 'dpzmb1', 'dpzdb1',
    ];

    /**
     * Formuláře, které obecné EPO sice rozpozná, ale odmítne je zpracovat mimo
     * vlastní aplikaci daňového portálu. U OSS to portál říká doslova: „Pro práci
     * s písemností … musíte být přihlášeni v aplikaci MOSS/OSS!" Asistované předání
     * tedy skončí vždycky chybou, ať uděláme cokoli — nabízet ho je jen slib, který
     * nemůžeme dodržet. Odmítá se tady, ne až v UI, aby to nešlo obejít přes API.
     *
     * Přímý kanál na tom je stejně (týž endpoint `/dpr/epo_podani`) a má vlastní
     * kopii seznamu v `EpoDirectSubmissionService::MOSS_OSS_FORMS`. Shodu obou
     * seznamů hlídá `EpoMossOssChannelGuardTest`.
     */
    private const MOSS_OSS_FORMS = ['ossei1'];

    public function __construct(
        private readonly Connection $db,
        private readonly TaxSubmissionEpoRepository $repo,
        private readonly TaxSubmissionDocumentService $documents,
        private readonly EpoClient $client,
    ) {}

    /**
     * @return array{attempt_id:int,url:string,expires_at:string,archive_folder_id:?int,source_document_id:?int,environment:string}
     */
    public function createHandoff(
        int $submissionId,
        int $supplierId,
        ?int $userId,
        bool $replaceActive = false,
    ): array
    {
        $environment = $this->client->environment();
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $submission = $this->repo->lockSubmission($submissionId, $supplierId);
            if ($submission === null) {
                throw new EpoSubmissionException('not_found', 'Podání nebylo nalezeno.', 404);
            }
            if (in_array((string) $submission['form_code'], self::MOSS_OSS_FORMS, true)) {
                throw new EpoSubmissionException(
                    'moss_oss_only',
                    'OSS přiznání nelze podat obecnou cestou EPO — podává se v aplikaci MOSS/OSS'
                    . ' na Daňovém portálu, do které se musíte přihlásit. Stáhněte XML a nahrajte ho tam.',
                    422,
                );
            }
            if (!in_array((string) $submission['form_code'], self::SUPPORTED_FORMS, true)) {
                throw new EpoSubmissionException(
                    'unsupported_form',
                    'Tento formulář nelze předat do EPO.',
                    422,
                );
            }
            if (in_array((string) $submission['status'], ['submitted', 'accepted'], true)) {
                throw new EpoSubmissionException(
                    'already_submitted',
                    'Tento XML snapshot už je označen jako podaný.',
                    409,
                );
            }
            if ((string) $submission['validation_status'] !== 'passed') {
                $errors = $submission['validation_errors'] !== null
                    ? (json_decode((string) $submission['validation_errors'], true) ?: [])
                    : [];
                throw new EpoSubmissionException(
                    'validation_failed',
                    'XML neprošlo lokální XSD validací.',
                    422,
                    ['validation_errors' => array_slice($errors, 0, 50)],
                );
            }

            $xml = (string) $submission['xml_content'];
            $actualSha = hash('sha256', $xml);
            if (!hash_equals((string) $submission['xml_sha256'], $actualSha)) {
                throw new EpoSubmissionException(
                    'snapshot_changed',
                    'Archivovaný XML snapshot neodpovídá uloženému otisku.',
                    409,
                );
            }

            $this->repo->expireAttempts($submissionId, $supplierId);
            $active = $this->repo->activeAttempt($submissionId, $supplierId, $environment);
            if ($active !== null) {
                if ((string) ($active['channel'] ?? '') === 'epo_direct') {
                    throw new EpoSubmissionException(
                        'submission_outcome_unresolved',
                        'Snapshot má rozpracované nebo nejisté přímé EPO podání. Asistované předání nelze vytvořit.',
                        409,
                        ['attempt' => $active],
                    );
                }
                if (!$replaceActive) {
                    throw new EpoSubmissionException(
                        'handoff_active',
                        'Pro tento snapshot už existuje aktivní předání do EPO.',
                        409,
                        ['attempt' => $active],
                    );
                }
                $this->repo->cancelActiveAttempt(
                    (int) $active['id'],
                    $submissionId,
                    $supplierId,
                );
            }

            $attemptId = $this->repo->insertAttempt(
                $supplierId,
                $submissionId,
                bin2hex(random_bytes(16)),
                $actualSha,
                $userId,
                $environment,
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        try {
            $source = $this->documents->ensureSourceXml(
                $submission,
                $supplierId,
                $attemptId,
                $userId,
            );
            $handoff = $this->client->createHandoff((string) $submission['xml_content']);
            $pdo->beginTransaction();
            try {
                if ($this->repo->lockSubmission($submissionId, $supplierId) === null) {
                    throw new EpoSubmissionException('not_found', 'Podání nebylo nalezeno.', 404);
                }
                $active = $this->repo->activeAttempt($submissionId, $supplierId, $environment);
                if (
                    $active === null
                    || (int) $active['id'] !== $attemptId
                    || (string) $active['channel'] !== 'epo_assisted'
                    || (string) $active['status'] !== 'prepared'
                    || !$this->repo->markHandoffCreated(
                        $attemptId,
                        $handoff['http_status'],
                        $handoff['expires_at'],
                    )
                ) {
                    throw new EpoSubmissionException(
                        'handoff_replaced',
                        'Předání bylo mezitím nahrazeno nebo zablokováno jiným pokusem.',
                        409,
                    );
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            return [
                'attempt_id' => $attemptId,
                'url' => $handoff['url'],
                'expires_at' => $handoff['expires_at'],
                'archive_folder_id' => isset($source['folder_id']) ? (int) $source['folder_id'] : null,
                'source_document_id' => isset($source['document_id']) ? (int) $source['document_id'] : null,
                'environment' => $environment,
            ];
        } catch (EpoException $e) {
            $this->repo->markAttemptFailed(
                $attemptId,
                $e->errorCode,
                $e->getMessage(),
                $e->remoteHttpStatus,
            );
            throw $e;
        } catch (EpoSubmissionException $e) {
            $this->repo->markAttemptFailed($attemptId, $e->errorCode, $e->getMessage(), null);
            throw $e;
        } catch (\Throwable) {
            $this->repo->markAttemptFailed(
                $attemptId,
                'handoff_failed',
                'Předání do EPO se nepodařilo.',
                null,
            );
            throw new EpoSubmissionException(
                'handoff_failed',
                'Předání do EPO se nepodařilo.',
                500,
            );
        }
    }
}

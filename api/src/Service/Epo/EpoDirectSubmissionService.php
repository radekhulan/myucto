<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\EpoDirectSubmissionRepository;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use MyInvoice\Service\Report\TaxSubmissionFilename;

final class EpoDirectSubmissionService
{
    private const SUPPORTED_FORMS = [
        'dphdp3', 'dphkh1', 'dphshv', 'dpfdp5', 'dpfdp7', 'dppdp9',
        'dpzvd6', 'dpsvd2', 'dpzmb1', 'dpzdb1',
    ];

    /**
     * Písemnosti, které se podávají v samostatné aplikaci MOSS/OSS Daňového portálu,
     * a přímý kanál je proto odesílat nesmí.
     *
     * Není to domněnka. POST XML na `/dpr/epo_podani` vrátil chybu „Pro práci
     * s písemností 'DAP OSS - režim EU - Přiznání k DPH platné od 1.7.2021' musíte být
     * přihlášeni v aplikaci MOSS/OSS!" — XML tedy prošlo a odmítnutá byla sama
     * písemnost, ne její obsah ani formát. Přímé podání míří na TÝŽ endpoint
     * `/dpr/epo_podani` (viz {@see EpoDirectClient}); od asistovaného předání
     * (viz {@see EpoClient}) se liší jen podepsaným tělem místo `?otevriFormular=1`.
     * Láme se tedy o stejnou podmínku a podpis ze ZAREP na ní nic nezmění — portál
     * nechce jiný podpis, ale přihlášení do jiné aplikace. Nabídnout tlačítko, které
     * po odemčení klíče a podepsání skončí toutéž chybou, jen o pár kroků později
     * a hůř čitelně, je horší než ho nenabídnout.
     *
     * Blokuje se pouze NOVÉ odeslání. Dohledání stavu a obnova potvrzení u pokusu
     * založeného dřív, než tohle omezení vyšlo najevo, zůstávají dostupné — jinak by
     * takový pokus zůstal navždy viset v „nejistém" stavu a nešel uzavřít.
     *
     * Musí zůstat v souladu s `EpoSubmissionService::MOSS_OSS_FORMS`; hlídá to
     * `EpoMossOssChannelGuardTest`.
     */
    private const MOSS_OSS_FORMS = ['ossei1'];

    public function __construct(
        private readonly Connection $db,
        private readonly TaxSubmissionRepository $submissions,
        private readonly TaxSubmissionArchiver $archiver,
        private readonly TaxSubmissionEpoRepository $epo,
        private readonly EpoDirectSubmissionRepository $direct,
        private readonly EpoSigningCredentialService $credentials,
        private readonly EpoPkcs7Signer $signer,
        private readonly EpoSubmissionPayloadBuilder $payloads,
        private readonly EpoDirectClient $client,
        private readonly EpoDirectResponseParser $parser,
        private readonly TaxSubmissionDocumentService $documents,
        private readonly SecretEncryption $crypto,
        private readonly EpoConfirmationPartsArchiver $confirmationParts,
        private readonly EpoAssistedConfirmationService $assistedConfirmations,
    ) {}

    /** @return array<string,mixed> */
    public function test(
        int $submissionId,
        int $supplierId,
        int $userId,
        int $credentialId,
    ): array {
        $environment = $this->client->environment();
        // Nejdřív se ptáme, jestli tenhle snapshot vůbec smíme odeslat, a teprve
        // potom odemykáme privátní klíč. Opačné pořadí dešifrovalo PFX i pro podání,
        // které se stejně zahodí — a u MOSS/OSS formulářů by chybu o špatné cestě
        // zastínila hláška o certifikátu.
        $submission = $this->validatedSubmission(
            $submissionId,
            $supplierId,
            false,
            $environment,
            true,
        );
        $unlocked = $this->credentials->unlockForSigning(
            $credentialId,
            $userId,
            $supplierId,
        );
        $attemptId = $this->direct->createAttempt(
            $supplierId,
            $submissionId,
            $credentialId,
            (string) $unlocked['credential']['fingerprint_sha256'],
            (string) $submission['xml_sha256'],
            $userId,
            $environment,
        );
        $this->direct->setStatus($attemptId, 'testing');
        $this->event(
            $supplierId,
            $submissionId,
            $attemptId,
            'test_started',
            'testing',
            null,
            [],
            $userId,
        );

        try {
            $this->documents->ensureSourceXml(
                $submission,
                $supplierId,
                $attemptId,
                $userId,
            );
            $signed = $this->signer->sign(
                $this->payloads->build($submission),
                $unlocked['pfx'],
                $unlocked['password'],
            );
            $this->direct->storeEncryptedTestPayload(
                $attemptId,
                $this->crypto->encryptFor(base64_encode($signed), 'epo:test-payload'),
            );
            $response = $this->client->submit($signed, true, $environment);
            $result = $this->parser->testResult($response['body']);
            $this->documents->storeGeneratedArtifact(
                $response['body'],
                $this->filename($submission, $attemptId, 'test-response.xml'),
                'epo_error_xml',
                $submission,
                $supplierId,
                $attemptId,
                $userId,
                $result['passed'] ? 'valid' : 'invalid',
                [
                    'test_mode' => true,
                    'passed' => $result['passed'],
                    'large_submission' => $result['large_submission'],
                    'epo_environment' => $environment,
                ],
            );
            $this->direct->recordTest(
                $attemptId,
                $result['passed'],
                $result['messages'],
                $response['http_status'],
            );
            $this->event(
                $supplierId,
                $submissionId,
                $attemptId,
                'test_finished',
                $result['passed'] ? 'test_passed' : 'test_failed',
                $response['http_status'],
                [
                    'message_count' => count($result['messages']),
                    'large_submission' => $result['large_submission'],
                ],
                $userId,
            );
            return [
                'attempt_id' => $attemptId,
                'passed' => $result['passed'],
                'messages' => $result['messages'],
                'large_submission' => $result['large_submission'],
                'environment' => $environment,
                'artifacts' => $this->epo->artifacts($submissionId, $supplierId),
                'attempts' => $this->epo->attempts($submissionId, $supplierId),
            ];
        } catch (EpoException $e) {
            $this->fail($attemptId, $supplierId, $submissionId, $userId, $e);
            throw $e;
        } catch (EpoSubmissionException $e) {
            $this->fail($attemptId, $supplierId, $submissionId, $userId, $e);
            throw $e;
        } catch (\Throwable) {
            $error = new EpoSubmissionException(
                'epo_test_failed',
                'Test EPO se nepodařilo dokončit.',
                500,
            );
            $this->fail($attemptId, $supplierId, $submissionId, $userId, $error);
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function submit(
        int $submissionId,
        int $supplierId,
        int $userId,
        int $attemptId,
    ): array {
        $attempt = $this->direct->findAttempt($attemptId, $submissionId, $supplierId);
        if (
            $attempt === null
            || (string) $attempt['status'] !== 'test_passed'
            || (int) ($attempt['requested_by'] ?? 0) !== $userId
        ) {
            throw new EpoSubmissionException(
                'successful_test_required',
                'Před odesláním proveďte úspěšný test EPO se stejným certifikátem.',
                409,
            );
        }
        $environment = (string) ($attempt['epo_environment'] ?? 'production');
        $this->assertCurrentEnvironment($environment);
        $submission = $this->validatedSubmission(
            $submissionId,
            $supplierId,
            false,
            $environment,
        );
        if (!hash_equals((string) $attempt['request_sha256'], (string) $submission['xml_sha256'])) {
            throw new EpoSubmissionException(
                'snapshot_changed',
                'Test se nevztahuje k aktuálnímu XML snapshotu.',
                409,
            );
        }
        $credentialId = (int) ($attempt['signing_credential_id'] ?? 0);
        $unlocked = $this->credentials->unlockForSigning(
            $credentialId,
            $userId,
            $supplierId,
        );
        if (!$this->direct->claimTestPassedAttempt(
            $attemptId,
            $submissionId,
            $supplierId,
            $userId,
            (string) $submission['xml_sha256'],
            $environment,
        )) {
            throw new EpoSubmissionException(
                'successful_test_required',
                'Úspěšný test už byl použit nebo je starší než 30 minut. Proveďte nový test EPO.',
                409,
            );
        }
        $this->event(
            $supplierId,
            $submissionId,
            $attemptId,
            'submission_started',
            'submitting',
            null,
            [],
            $userId,
        );
        try {
            $signed = $this->signer->sign(
                $this->payloads->build($submission),
                $unlocked['pfx'],
                $unlocked['password'],
            );
            $this->direct->storeEncryptedSubmittedPayload(
                $attemptId,
                $this->crypto->encryptFor(base64_encode($signed), 'epo:submitted-payload'),
            );
            $this->documents->storeGeneratedArtifact(
                $signed,
                $this->filename($submission, $attemptId, 'submitted-signed.p7s'),
                'signed_submission_p7s',
                $submission,
                $supplierId,
                $attemptId,
                $userId,
                'valid',
                [
                    'purpose' => 'epo_submit',
                    'xml_sha256' => $submission['xml_sha256'],
                    'signing_fingerprint' => $unlocked['credential']['fingerprint_sha256'],
                    'epo_environment' => $environment,
                ],
            );
        } catch (EpoSubmissionException $e) {
            $this->fail($attemptId, $supplierId, $submissionId, $userId, $e);
            throw $e;
        } catch (\Throwable) {
            $error = new EpoSubmissionException(
                'epo_signing_failed',
                'Podání se nepodařilo podepsat nebo archivovat.',
                500,
            );
            $this->fail($attemptId, $supplierId, $submissionId, $userId, $error);
            throw $error;
        }

        try {
            $response = $this->client->submit($signed, false, $environment);
        } catch (EpoException $e) {
            $this->direct->setStatus(
                $attemptId,
                'uncertain',
                $e->remoteHttpStatus,
                'submission_outcome_uncertain',
                'Nelze potvrdit, zda EPO podání přijalo. Neodesílejte jej znovu bez kontroly.',
            );
            $this->event(
                $supplierId,
                $submissionId,
                $attemptId,
                'submission_uncertain',
                'uncertain',
                $e->remoteHttpStatus,
                ['cause' => $e->errorCode],
                $userId,
            );
            throw new EpoSubmissionException(
                'submission_outcome_uncertain',
                'Spojení skončilo bez jednoznačné odpovědi. Neodesílejte podání znovu bez kontroly v EPO.',
                503,
                ['attempt_id' => $attemptId],
            );
        }

        try {
            $this->direct->storeEncryptedResponse(
                $attemptId,
                $this->crypto->encryptFor(base64_encode($response['body']), 'epo:response'),
                $response['http_status'],
            );
        } catch (\Throwable) {
            $this->direct->setStatus(
                $attemptId,
                'uncertain',
                $response['http_status'],
                'response_staging_failed',
                'Odpověď EPO se nepodařilo bezpečně uložit. Výsledek podání je nejistý.',
            );
            $this->event(
                $supplierId,
                $submissionId,
                $attemptId,
                'response_staging_failed',
                'uncertain',
                $response['http_status'],
                [],
                $userId,
            );
            throw new EpoSubmissionException(
                'submission_outcome_uncertain',
                'EPO odpovědělo, ale odpověď se nepodařilo bezpečně uložit. Podání neposílejte znovu bez ruční kontroly.',
                500,
                ['attempt_id' => $attemptId],
            );
        }

        try {
            $envelope = $this->parser->submitEnvelope($response['body']);
        } catch (EpoSubmissionException $e) {
            $this->direct->setStatus(
                $attemptId,
                'uncertain',
                $response['http_status'],
                'submission_outcome_uncertain',
                'EPO vrátilo odpověď, jejíž výsledek nelze jednoznačně určit.',
            );
            $this->event(
                $supplierId,
                $submissionId,
                $attemptId,
                'submission_uncertain',
                'uncertain',
                $response['http_status'],
                ['cause' => $e->errorCode],
                $userId,
            );
            throw new EpoSubmissionException(
                'submission_outcome_uncertain',
                'Výsledek podání nelze z odpovědi EPO určit. Podání neposílejte znovu bez ruční kontroly.',
                502,
                ['attempt_id' => $attemptId],
            );
        }
        if ($envelope['kind'] === 'errors') {
            $messages = $envelope['messages'] ?? [];
            $summary = $this->messageSummary($messages);
            $this->direct->setStatus(
                $attemptId,
                'rejected',
                $response['http_status'],
                'epo_rejected',
                $summary,
            );
            $this->event(
                $supplierId,
                $submissionId,
                $attemptId,
                'submission_rejected',
                'rejected',
                $response['http_status'],
                ['message_count' => count($messages)],
                $userId,
            );
            try {
                $this->documents->storeGeneratedArtifact(
                    $response['body'],
                    $this->filename($submission, $attemptId, 'submit-errors.xml'),
                    'epo_error_xml',
                    $submission,
                    $supplierId,
                    $attemptId,
                    $userId,
                    'invalid',
                    [
                        'test_mode' => false,
                        'epo_environment' => $environment,
                    ],
                );
            } catch (\Throwable $e) {
                $this->event(
                    $supplierId,
                    $submissionId,
                    $attemptId,
                    'rejection_archive_failed',
                    'rejected',
                    $response['http_status'],
                    [
                        'cause' => $e instanceof EpoSubmissionException
                            ? $e->errorCode
                            : 'artifact_store_failed',
                    ],
                    $userId,
                );
            }
            throw new EpoSubmissionException(
                'epo_rejected',
                $summary,
                422,
                ['attempt_id' => $attemptId, 'messages' => $messages],
            );
        }
        if ($envelope['kind'] === 'offline') {
            $this->direct->recordOffline(
                $attemptId,
                (string) $envelope['transfer_id'],
                $this->crypto->encryptFor((string) $envelope['transfer_password'], 'epo:offline-password'),
                $response['http_status'],
            );
            $this->event(
                $supplierId,
                $submissionId,
                $attemptId,
                'offline_processing',
                'processing',
                $response['http_status'],
                [],
                $userId,
            );
            return [
                'attempt_id' => $attemptId,
                'status' => 'processing',
                'message' => 'Rozsáhlé podání EPO zpracovává. Stav lze bezpečně obnovit.',
                'environment' => $environment,
            ];
        }

        return $this->confirm(
            $submission,
            $supplierId,
            $userId,
            $userId,
            $attemptId,
            (string) $envelope['confirmation'],
            $signed,
            $response['http_status'],
            $environment,
        );
    }

    /** @return array<string,mixed> */
    public function refreshStatus(
        int $submissionId,
        int $supplierId,
        int $userId,
        int $attemptId,
    ): array {
        $attempt = $this->direct->findAttempt($attemptId, $submissionId, $supplierId, true);
        if ($attempt === null) {
            throw new EpoSubmissionException('attempt_not_found', 'Pokus nebyl nalezen.', 404);
        }
        $environment = (string) ($attempt['epo_environment'] ?? 'production');
        $this->assertCurrentEnvironment($environment);
        $submission = $this->validatedSubmission(
            $submissionId,
            $supplierId,
            true,
            $environment,
        );
        if ((string) ($attempt['channel'] ?? '') === 'epo_assisted') {
            return $this->refreshAssistedStatus(
                $submission,
                $supplierId,
                $userId,
                $attempt,
                $environment,
            );
        }
        // „Obnovit stav" srovná i ODVOZENÉ soubory z dodejky. Aplikace je umí vytáhnout až
        // od jisté verze, takže podání archivovaná dřív mají v Dokumentech buď jen samotnou
        // P7S, nebo rozbalené části bez shrnutí. Doplnit se to nemá jak jinak: archivace
        // běží pouze při potvrzování a to už u hotového podání znovu neproběhne.
        // Idempotentní — stejné soubory se podle hashe nezaloží podruhé.
        if (
            (string) $attempt['status'] === 'confirmed'
            && !empty($attempt['confirmation_ciphertext'])
        ) {
            try {
                $this->archiveConfirmationParts(
                    $this->decryptBinaryPayload(
                        (string) $attempt['confirmation_ciphertext'],
                        'confirmation_recovery_failed',
                        'Bezpečně uložené potvrzení EPO nelze obnovit.',
                        'epo:confirmation',
                    ),
                    $submission,
                    $supplierId,
                    $attemptId,
                    $userId,
                    $environment,
                );
            } catch (\Throwable) {
                // Doplnění příloh je pohodlí, ne důkaz — potvrzené podání kvůli němu neshodíme.
            }
        }

        if (
            (string) $attempt['status'] === 'uncertain'
            && in_array(
                (string) ($attempt['error_code'] ?? ''),
                // `confirmation_trust_store_unavailable` je tatáž situace, jen s pojmenovanou
                // příčinou na naší straně — po doplnění CA bundlu se pokus dotáhne ze stejně
                // uložené potvrzenky, bez opakovaného odeslání.
                ['invalid_confirmation', 'confirmation_trust_store_unavailable'],
                true,
            )
            && !empty($attempt['confirmation_ciphertext'])
            && !empty($attempt['submitted_signed_ciphertext'])
        ) {
            $confirmation = $this->decryptBinaryPayload(
                (string) $attempt['confirmation_ciphertext'],
                'confirmation_recovery_failed',
                'Bezpečně uložené potvrzení EPO nelze obnovit.',
                'epo:confirmation',
            );
            $submittedSigned = $this->decryptBinaryPayload(
                (string) $attempt['submitted_signed_ciphertext'],
                'submitted_payload_unavailable',
                'Odeslaný podepsaný balíček nelze bezpečně obnovit.',
                'epo:submitted-payload',
            );
            return $this->confirm(
                $submission,
                $supplierId,
                $userId,
                (int) ($attempt['requested_by'] ?? $userId),
                $attemptId,
                $confirmation,
                $submittedSigned,
                (int) ($attempt['response_http_status'] ?? 200),
                $environment,
            );
        }
        if (
            (string) $attempt['status'] === 'uncertain'
            && in_array(
                (string) ($attempt['error_code'] ?? ''),
                [
                    'confirmation_archive_failed',
                    'confirmation_unverified_archive_failed',
                    'confirmation_finalize_failed',
                ],
                true,
            )
            && !empty($attempt['confirmation_ciphertext'])
            && !empty($attempt['remote_submission_ref'])
            && !empty($attempt['state_password_ciphertext'])
            && !empty($attempt['submitted_at'])
        ) {
            $contentUnverified = (string) ($attempt['error_code'] ?? '')
                === 'confirmation_unverified_archive_failed';
            try {
                $confirmation = base64_decode(
                    $this->crypto->decryptFor((string) $attempt['confirmation_ciphertext'], 'epo:confirmation'),
                    true,
                );
            } catch (\RuntimeException) {
                $confirmation = false;
            }
            if (!is_string($confirmation) || $confirmation === '') {
                throw new EpoSubmissionException(
                    'confirmation_recovery_failed',
                    'Bezpečně uložené potvrzení EPO nelze obnovit.',
                    500,
                );
            }
            $this->documents->storeGeneratedArtifact(
                $confirmation,
                $this->filename($submission, $attemptId, 'confirmation.p7s'),
                'confirmation_p7s',
                $submission,
                $supplierId,
                $attemptId,
                $userId,
                $contentUnverified ? 'warning' : 'valid',
                [
                    'recovered_from_encrypted_stage' => true,
                    'epo_environment' => $environment,
                ],
            );
            if ($contentUnverified) {
                $this->direct->setStatus(
                    $attemptId,
                    'uncertain',
                    (int) ($attempt['response_http_status'] ?? 200),
                    'confirmation_content_mismatch',
                    'Důvěryhodné potvrzení EPO nelze přesně svázat s odeslaným CMS balíčkem.',
                );
                $this->event(
                    $supplierId,
                    $submissionId,
                    $attemptId,
                    'confirmation_unverified_archive_recovered',
                    'uncertain',
                    (int) ($attempt['response_http_status'] ?? 200),
                    [],
                    $userId,
                );
                return [
                    'attempt_id' => $attemptId,
                    'status' => 'uncertain',
                    'reference' => $attempt['remote_submission_ref'],
                    'submitted_at' => $attempt['submitted_at'],
                    'environment' => $environment,
                ];
            }
            $this->finalizeConfirmationState(
                $submission,
                $supplierId,
                (int) ($attempt['requested_by'] ?? $userId),
                $attemptId,
                (string) $attempt['remote_submission_ref'],
                (string) $attempt['submitted_at'],
                (string) $attempt['state_password_ciphertext'],
                (int) ($attempt['response_http_status'] ?? 200),
                $environment,
            );
            $this->event(
                $supplierId,
                $submissionId,
                $attemptId,
                'confirmation_archive_recovered',
                'confirmed',
                (int) ($attempt['response_http_status'] ?? 200),
                [],
                $userId,
            );
            return [
                'attempt_id' => $attemptId,
                'status' => 'confirmed',
                'reference' => $attempt['remote_submission_ref'],
                'submitted_at' => $attempt['submitted_at'],
                'environment' => $environment,
            ];
        }
        if (
            (string) $attempt['status'] === 'processing'
            && !empty($attempt['offline_transfer_id'])
            && !empty($attempt['offline_password_ciphertext'])
        ) {
            $password = $this->crypto->decryptFor(
                (string) $attempt['offline_password_ciphertext'],
                'epo:offline-password',
            );
            $response = $this->client->receiveOffline(
                (string) $attempt['offline_transfer_id'],
                $password,
                $environment,
            );
            $dom = $this->loadXml($response['body']);
            if ($dom !== null && strtolower((string) $dom->documentElement?->localName) === 'stavzpracovani') {
                $state = (string) $dom->documentElement?->getAttribute('Stav');
                if ($state === '3') {
                    $messages = $this->parser->submitEnvelope(
                        $this->offlineErrorsXml($dom),
                    )['messages'] ?? [];
                    $this->direct->setStatus(
                        $attemptId,
                        'rejected',
                        $response['http_status'],
                        'epo_rejected',
                        $this->messageSummary($messages),
                    );
                    if ($environment === 'production') {
                        $this->direct->setSubmissionRemoteStatus(
                            $submissionId,
                            $supplierId,
                            'rejected',
                        );
                    }
                    $this->direct->clearScheduledPoll($attemptId);
                    $this->event(
                        $supplierId,
                        $submissionId,
                        $attemptId,
                        'offline_rejected',
                        'rejected',
                        $response['http_status'],
                        ['message_count' => count($messages)],
                        $userId,
                    );
                    try {
                        $this->documents->storeGeneratedArtifact(
                            $this->offlineErrorsXml($dom),
                            $this->filename($submission, $attemptId, 'offline-errors.xml'),
                            'epo_error_xml',
                            $submission,
                            $supplierId,
                            $attemptId,
                            $userId,
                            'invalid',
                            [
                                'offline_processing' => true,
                                'epo_environment' => $environment,
                            ],
                        );
                    } catch (\Throwable $e) {
                        $this->event(
                            $supplierId,
                            $submissionId,
                            $attemptId,
                            'rejection_archive_failed',
                            'rejected',
                            $response['http_status'],
                            [
                                'cause' => $e instanceof EpoSubmissionException
                                    ? $e->errorCode
                                    : 'artifact_store_failed',
                            ],
                            $userId,
                        );
                    }
                    return [
                        'attempt_id' => $attemptId,
                        'status' => 'rejected',
                        'messages' => $messages,
                        'environment' => $environment,
                    ];
                }
                $this->documents->storeGeneratedArtifact(
                    $response['body'],
                    $this->filename($submission, $attemptId, 'offline-status.xml'),
                    'epo_status_xml',
                    $submission,
                    $supplierId,
                    $attemptId,
                    $userId,
                    'valid',
                    [
                        'offline_state' => $state,
                        'epo_environment' => $environment,
                    ],
                );
                $this->event(
                    $supplierId,
                    $submissionId,
                    $attemptId,
                    'offline_status_refreshed',
                    'processing',
                    $response['http_status'],
                    ['offline_state' => $state],
                    $userId,
                );
                $pollCount = (int) ($attempt['poll_count'] ?? 0);
                $this->direct->scheduleNextPoll(
                    $attemptId,
                    min(3600, 60 * (2 ** min(6, $pollCount))),
                );
                return [
                    'attempt_id' => $attemptId,
                    'status' => 'processing',
                    'environment' => $environment,
                ];
            }
            $submittedSigned = $this->decryptBinaryPayload(
                (string) ($attempt['submitted_signed_ciphertext'] ?? ''),
                'submitted_payload_unavailable',
                'Odeslaný podepsaný balíček nelze bezpečně obnovit pro ověření potvrzení.',
            );
            return $this->confirm(
                $submission,
                $supplierId,
                $userId,
                (int) ($attempt['requested_by'] ?? $userId),
                $attemptId,
                $response['body'],
                $submittedSigned,
                $response['http_status'],
                $environment,
            );
        }

        $reference = trim((string) ($attempt['remote_submission_ref'] ?? ''));
        $encryptedPassword = (string) ($attempt['state_password_ciphertext'] ?? '');
        if ($reference === '' || $encryptedPassword === '') {
            throw new EpoSubmissionException(
                'status_unavailable',
                'K tomuto pokusu nejsou dostupné údaje pro dotaz na stav.',
                409,
            );
        }
        $response = $this->client->status(
            $reference,
            $this->crypto->decryptFor($encryptedPassword, 'epo:state-password'),
            $environment,
        );
        $status = $this->parser->status($response['body']);
        $remoteApplicationStatus = (string) ($status['stav_podapl'] ?? '');
        $lifecycle = match ($remoteApplicationStatus) {
            '2' => 'rejected',
            '3' => 'confirmed',
            default => (string) $attempt['status'] === 'confirmed'
                ? 'confirmed'
                : 'processing',
        };
        $this->direct->recordRemoteStatus($attemptId, $lifecycle, $status);
        if ($remoteApplicationStatus === '2') {
            if ($environment === 'production') {
                $this->direct->setSubmissionRemoteStatus($submissionId, $supplierId, 'rejected');
            }
            $this->direct->clearScheduledPoll($attemptId);
        } elseif ($remoteApplicationStatus === '3') {
            $submittedAt = trim((string) ($attempt['submitted_at'] ?? ''));
            $statePasswordCiphertext = trim((string) ($attempt['state_password_ciphertext'] ?? ''));
            if ($submittedAt === '' || $statePasswordCiphertext === '') {
                throw new EpoSubmissionException(
                    'accepted_submission_metadata_missing',
                    'EPO podání přijalo, ale lokální důkazní metadata nejsou kompletní.',
                    500,
                    ['attempt_id' => $attemptId],
                );
            }
            $this->finalizeConfirmationState(
                $submission,
                $supplierId,
                (int) ($attempt['requested_by'] ?? $userId),
                $attemptId,
                $reference,
                $submittedAt,
                $statePasswordCiphertext,
                $response['http_status'],
                $environment,
            );
            if ($environment === 'production') {
                $this->direct->setSubmissionRemoteStatus($submissionId, $supplierId, 'accepted');
            }
            $this->direct->clearScheduledPoll($attemptId);
        } else {
            $pollCount = (int) ($attempt['poll_count'] ?? 0);
            $this->direct->scheduleNextPoll(
                $attemptId,
                min(3600, 60 * (2 ** min(6, $pollCount))),
            );
        }
        $this->documents->storeGeneratedArtifact(
            $response['body'],
            $this->filename($submission, $attemptId, 'status.xml'),
            'epo_status_xml',
            $submission,
            $supplierId,
            $attemptId,
            $userId,
            'valid',
            ['remote_application_status' => $remoteApplicationStatus],
        );
        $this->event(
            $supplierId,
            $submissionId,
            $attemptId,
            'status_refreshed',
            $lifecycle,
            $response['http_status'],
            ['remote_application_status' => $remoteApplicationStatus],
            $userId,
        );
        return [
            'attempt_id' => $attemptId,
            'status' => $lifecycle,
            'remote_status' => $status,
            'environment' => $environment,
        ];
    }

    /**
     * Dotaz na stav u ASISTOVANÉHO předání.
     *
     * Podací číslo i heslo pocházejí z ručně nahrané dodejky, takže `epo_stav` odpoví
     * stejně jako u přímého podání — endpoint neřeší, kterým kanálem podání došlo.
     * Pustí se jen ta část, kterou asistovaný kanál může mít: žádný off-line transfer,
     * žádná obnova potvrzenky z uloženého balíčku (ten neexistuje) a žádné plánování
     * dalšího pollu (cron chodí jen po přímých pokusech).
     *
     * @param array<string,mixed> $submission
     * @param array<string,mixed> $attempt
     * @return array<string,mixed>
     */
    private function refreshAssistedStatus(
        array $submission,
        int $supplierId,
        int $userId,
        array $attempt,
        string $environment,
    ): array {
        $attemptId = (int) $attempt['id'];
        $submissionId = (int) $submission['id'];

        // Části dodejky umí aplikace vytáhnout až od jisté verze a děje se to při
        // nahrání souboru. Podání archivovaná dřív by proto zůstala navždy bez shrnutí;
        // tady se doplní z už uložené P7S. Idempotentní, best-effort.
        try {
            $this->assistedConfirmations->backfillParts(
                $submission,
                $supplierId,
                $userId,
                $environment,
            );
        } catch (\Throwable) {
            // Doplnění příloh je pohodlí, ne důkaz — dotaz na stav kvůli němu neshodíme.
        }

        $reference = trim((string) ($attempt['remote_submission_ref'] ?? ''));
        $encryptedPassword = (string) ($attempt['state_password_ciphertext'] ?? '');
        if ($reference === '' || $encryptedPassword === '') {
            throw new EpoSubmissionException(
                'status_unavailable',
                'K tomuto předání nejsou dostupné údaje pro dotaz na stav.'
                . ' Nahrajte dodejku (.p7s) staženou z Daňového portálu.',
                409,
            );
        }

        $response = $this->client->status(
            $reference,
            $this->crypto->decryptFor($encryptedPassword, 'epo:state-password'),
            $environment,
        );
        $status = $this->parser->status($response['body']);
        $remoteApplicationStatus = (string) ($status['stav_podapl'] ?? '');
        $lifecycle = match ($remoteApplicationStatus) {
            '2' => 'rejected',
            '3' => 'confirmed',
            default => null,
        };
        $this->direct->recordAssistedRemoteStatus($attemptId, $lifecycle, $status);

        if ($remoteApplicationStatus === '2' && $environment === 'production') {
            $this->direct->setSubmissionRemoteStatus($submissionId, $supplierId, 'rejected');
        } elseif ($remoteApplicationStatus === '3') {
            // EPO potvrdilo, že podání zpracovalo. Tím je prokazatelně podané i bez
            // ručního označení — týž závěr dělá přímý kanál ve `finalizeConfirmationState`.
            $submittedAt = trim((string) ($attempt['submitted_at'] ?? ''));
            if ($environment === 'production' && $submittedAt !== '') {
                $this->archiver->markSubmitted(
                    $submissionId,
                    $supplierId,
                    $submittedAt,
                    $reference,
                    (int) ($attempt['requested_by'] ?? $userId),
                );
                $this->direct->setSubmissionRemoteStatus($submissionId, $supplierId, 'accepted');
            }
            if ($submittedAt !== '') {
                $this->epo->markAttemptConfirmed($attemptId, $submittedAt);
            }
        }

        try {
            $this->documents->storeGeneratedArtifact(
                $response['body'],
                $this->filename($submission, $attemptId, 'status.xml'),
                'epo_status_xml',
                $submission,
                $supplierId,
                $attemptId,
                $userId,
                'valid',
                [
                    'remote_application_status' => $remoteApplicationStatus,
                    'epo_environment' => $environment,
                ],
            );
        } catch (\Throwable) {
            // Protokol o stavu je doprovodný soubor; jeho neuložení nesmí zahodit
            // odpověď, kterou už má uživatel na obrazovce.
        }

        $this->event(
            $supplierId,
            $submissionId,
            $attemptId,
            'status_refreshed',
            $lifecycle ?? (string) $attempt['status'],
            $response['http_status'],
            ['remote_application_status' => $remoteApplicationStatus, 'channel' => 'epo_assisted'],
            $userId,
        );

        return [
            'attempt_id' => $attemptId,
            'status' => $lifecycle ?? (string) $attempt['status'],
            'remote_status' => $status,
            'environment' => $environment,
            'artifacts' => $this->epo->artifacts($submissionId, $supplierId),
            'attempts' => $this->epo->attempts($submissionId, $supplierId),
        ];
    }

    /** @return array<string,mixed> */
    public function recoverConfirmation(
        int $submissionId,
        int $supplierId,
        int $userId,
        int $attemptId,
        string $confirmationBytes,
    ): array {
        if ($confirmationBytes === '' || strlen($confirmationBytes) > 10 * 1024 * 1024) {
            throw new EpoSubmissionException(
                'invalid_confirmation_file',
                'Potvrzení P7S je prázdné nebo příliš velké.',
                400,
            );
        }
        $attempt = $this->direct->findAttempt($attemptId, $submissionId, $supplierId);
        if (
            $attempt === null
            || !in_array(
                (string) $attempt['status'],
                ['submitting', 'processing', 'confirmed', 'uncertain'],
                true,
            )
        ) {
            throw new EpoSubmissionException(
                'attempt_not_recoverable',
                'Tento pokus nelze potvrzením P7S bezpečně obnovit.',
                409,
            );
        }
        $environment = (string) ($attempt['epo_environment'] ?? 'production');
        $submission = $this->validatedSubmission(
            $submissionId,
            $supplierId,
            true,
            $environment,
        );
        $submittedSigned = $this->decryptBinaryPayload(
            (string) ($attempt['submitted_signed_ciphertext'] ?? ''),
            'submitted_payload_unavailable',
            'Odeslaný podepsaný balíček nelze bezpečně obnovit pro ověření potvrzení.',
        );
        return $this->confirm(
            $submission,
            $supplierId,
            $userId,
            (int) ($attempt['requested_by'] ?? $userId),
            $attemptId,
            $confirmationBytes,
            $submittedSigned,
            200,
            $environment,
        );
    }

    /**
     * Heslo, kterým se na Daňovém portálu dohledá stav podání a stáhne opis.
     *
     * EPO ho vydá jednou, v dodejce. Aplikace ho drží šifrovaně pro `epo_stav` a nikde
     * ho nezobrazuje — jenže bez něj se účetní k oficiálnímu PDF opisu nedostane, protože
     * portál chce podací číslo A heslo. Odemyká se proto vědomě: samostatná akce, step-up
     * ověření a záznam v auditu. Do metadat artefaktů ani do logů se heslo nekopíruje.
     *
     * @return array{attempt_id:int,reference:?string,state_password:string}
     */
    public function revealStatePassword(
        int $submissionId,
        int $supplierId,
        int $attemptId,
    ): array {
        // I u asistovaného předání: heslo vydává EPO v dodejce bez ohledu na to, kudy
        // podání došlo, a účetní ho potřebuje k opisu na portálu úplně stejně.
        $attempt = $this->direct->findAttempt($attemptId, $submissionId, $supplierId, true);
        if ($attempt === null || empty($attempt['state_password_ciphertext'])) {
            throw new EpoSubmissionException(
                'state_password_unavailable',
                'K tomuto pokusu není uložené heslo pro dotaz na stav.',
                404,
            );
        }
        try {
            $password = $this->crypto->decryptFor(
                (string) $attempt['state_password_ciphertext'],
                'epo:state-password',
            );
        } catch (\RuntimeException) {
            throw new EpoSubmissionException(
                'state_password_unavailable',
                'Uložené heslo pro dotaz na stav nelze dešifrovat.',
                500,
            );
        }
        if ($password === '') {
            throw new EpoSubmissionException(
                'state_password_unavailable',
                'Uložené heslo pro dotaz na stav je prázdné.',
                404,
            );
        }

        return [
            'attempt_id' => $attemptId,
            'reference' => $attempt['remote_submission_ref'] !== null
                ? (string) $attempt['remote_submission_ref']
                : null,
            'state_password' => $password,
        ];
    }

    /** @return array{attempt_id:int,status:string,environment:string} */
    public function resolveAsNotSubmitted(
        int $submissionId,
        int $supplierId,
        int $userId,
        int $attemptId,
        string $note,
    ): array {
        $note = trim($note);
        if (mb_strlen($note) < 10) {
            throw new EpoSubmissionException(
                'resolution_note_required',
                'Popište, jak jste ověřili, že podání nebylo přijato.',
                400,
            );
        }
        $attempt = $this->direct->findAttempt($attemptId, $submissionId, $supplierId);
        if ($attempt === null) {
            throw new EpoSubmissionException(
                'attempt_not_resolvable',
                'Pokus ještě nelze uvolnit nebo obsahuje údaje pro bezpečné ověření stavu.',
                409,
            );
        }
        $environment = (string) ($attempt['epo_environment'] ?? 'production');
        if (!$this->direct->resolveAsNotSubmitted(
            $attemptId,
            $submissionId,
            $supplierId,
            $userId,
            $note,
        )) {
            throw new EpoSubmissionException(
                'attempt_not_resolvable',
                'Pokus ještě nelze uvolnit nebo obsahuje údaje pro bezpečné ověření stavu.',
                409,
            );
        }
        $this->event(
            $supplierId,
            $submissionId,
            $attemptId,
            'submission_resolved_not_submitted',
            'cancelled',
            null,
            ['resolution_note' => $note],
            $userId,
        );
        return [
            'attempt_id' => $attemptId,
            'status' => 'cancelled',
            'environment' => $environment,
        ];
    }

    /** @return array{processed:int,confirmed:int,rejected:int,pending:int,errors:int} */
    public function pollDue(int $limit = 50): array
    {
        $result = ['processed' => 0, 'confirmed' => 0, 'rejected' => 0, 'pending' => 0, 'errors' => 0];
        foreach ($this->direct->pollableAttempts($limit, $this->client->environment()) as $attempt) {
            ++$result['processed'];
            try {
                $refreshed = $this->refreshStatus(
                    $attempt['submission_id'],
                    $attempt['supplier_id'],
                    $attempt['user_id'],
                    $attempt['attempt_id'],
                );
                $status = (string) ($refreshed['status'] ?? 'processing');
                if ($status === 'confirmed') {
                    ++$result['confirmed'];
                } elseif ($status === 'rejected') {
                    ++$result['rejected'];
                } else {
                    ++$result['pending'];
                }
            } catch (\Throwable) {
                ++$result['errors'];
                $row = $this->direct->findAttempt(
                    $attempt['attempt_id'],
                    $attempt['submission_id'],
                    $attempt['supplier_id'],
                );
                $pollCount = (int) ($row['poll_count'] ?? 0);
                $this->direct->scheduleNextPoll(
                    $attempt['attempt_id'],
                    min(3600, 60 * (2 ** min(6, $pollCount))),
                );
            }
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function confirm(
        array $submission,
        int $supplierId,
        int $userId,
        int $submittedBy,
        int $attemptId,
        string $confirmationBytes,
        string $signedData,
        int $httpStatus,
        string $environment,
    ): array {
        try {
            $confirmationCiphertext = $this->crypto->encryptFor(
                base64_encode($confirmationBytes),
                'epo:confirmation',
            );
            $this->direct->storeEncryptedConfirmationPayload(
                $attemptId,
                $confirmationCiphertext,
                $httpStatus,
            );
        } catch (\Throwable) {
            $this->direct->setStatus(
                $attemptId,
                'uncertain',
                $httpStatus,
                'confirmation_staging_failed',
                'Potvrzení EPO se nepodařilo bezpečně uložit.',
            );
            throw new EpoSubmissionException(
                'confirmation_staging_failed',
                'Potvrzení EPO se nepodařilo bezpečně uložit. Stav podání je nejistý.',
                500,
                ['attempt_id' => $attemptId],
            );
        }
        $verification = $this->parser->confirmation(
            $confirmationBytes,
            $signedData,
            $environment,
        );
        if (
            !$verification['signature_valid']
            || ($environment === 'production' && !$verification['chain_valid'])
            || !$verification['epo_signer_valid']
            || !$verification['is_confirmation']
        ) {
            // Nastavený, ale nedostupný `epo.ca_bundle_path` vypadá navlas stejně jako vadná
            // potvrzenka — ověření řetězce selže fail-closed a hláška mlčí o tom, že chyba
            // je na naší straně. Účetní pak řeší „přijal to finanční úřad?" u podání, které
            // je dávno podané, a hrozí, že ho ze strachu odešle podruhé. Konfigurační
            // příčinu proto pojmenujeme zvlášť; stav zůstává `uncertain`, protože po nápravě
            // se pokus dotáhne přes „Obnovit stav" ze stejně uložené potvrzenky.
            $misconfigured = $this->parser->trustStoreUnavailable();
            $errorCode = $misconfigured ? 'confirmation_trust_store_unavailable' : 'invalid_confirmation';
            $message = $misconfigured
                ? 'Potvrzení EPO nelze ověřit, protože nastavený CA bundle (epo.ca_bundle_path)'
                    . ' na serveru chybí. Podání je pravděpodobně přijaté — NEODESÍLEJTE ho znovu.'
                    . ' Doplňte soubor a použijte „Obnovit stav".'
                : 'EPO vrátilo potvrzení, které se nepodařilo bezpečně ověřit.';
            $this->direct->setStatus($attemptId, 'uncertain', $httpStatus, $errorCode, $message);
            $this->event(
                $supplierId,
                (int) $submission['id'],
                $attemptId,
                'confirmation_verification_failed',
                'uncertain',
                $httpStatus,
                [
                    'signature_valid' => $verification['signature_valid'],
                    'chain_valid' => $verification['chain_valid'],
                    'epo_signer_valid' => $verification['epo_signer_valid'],
                    'is_confirmation' => $verification['is_confirmation'],
                    'content_match' => $verification['content_match'],
                    'trust_store_unavailable' => $misconfigured,
                ],
                $userId,
            );
            throw new EpoSubmissionException(
                $errorCode,
                $message,
                502,
                ['attempt_id' => $attemptId],
            );
        }
        $publicVerification = $verification;
        unset($publicVerification['state_password']);
        $contentVerified = $verification['content_match'] === true;
        $statePasswordCiphertext = $this->crypto->encryptFor(
            (string) $verification['state_password'],
            'epo:state-password',
        );
        $this->direct->stageConfirmation(
            $attemptId,
            (string) $verification['reference'],
            (string) $verification['submitted_at'],
            $statePasswordCiphertext,
            $confirmationCiphertext,
            $httpStatus,
        );
        try {
            $this->documents->storeGeneratedArtifact(
                $confirmationBytes,
                $this->filename($submission, $attemptId, 'confirmation.p7s'),
                'confirmation_p7s',
                $submission,
                $supplierId,
                $attemptId,
                $userId,
                $contentVerified ? 'valid' : 'warning',
                [
                    ...$publicVerification,
                    'epo_environment' => $environment,
                ],
            );
        } catch (\Throwable $e) {
            $cause = $e instanceof EpoSubmissionException
                ? $e->errorCode
                : 'artifact_store_failed';
            $this->direct->setStatus(
                $attemptId,
                'uncertain',
                $httpStatus,
                $contentVerified
                    ? 'confirmation_archive_failed'
                    : 'confirmation_unverified_archive_failed',
                'Potvrzení EPO je bezpečně zachováno, ale nepodařilo se je uložit do Dokumentů.',
            );
            $this->event(
                $supplierId,
                (int) $submission['id'],
                $attemptId,
                'confirmation_archive_failed',
                'uncertain',
                $httpStatus,
                ['cause' => $cause],
                $userId,
            );
            throw new EpoSubmissionException(
                'confirmation_archive_failed',
                'EPO podání přijalo, potvrzení je bezpečně zachováno, ale archivace do Dokumentů selhala.',
                500,
                ['attempt_id' => $attemptId],
            );
        }
        $this->archiveConfirmationParts(
            $confirmationBytes,
            $submission,
            $supplierId,
            $attemptId,
            $userId,
            $environment,
        );
        if (!$contentVerified) {
            $this->direct->setStatus(
                $attemptId,
                'uncertain',
                $httpStatus,
                'confirmation_content_mismatch',
                'Důvěryhodné potvrzení EPO nelze přesně svázat s odeslaným CMS balíčkem.',
            );
            $this->event(
                $supplierId,
                (int) $submission['id'],
                $attemptId,
                'confirmation_content_mismatch',
                'uncertain',
                $httpStatus,
                ['form_code' => $submission['form_code']],
                $userId,
            );
            throw new EpoSubmissionException(
                'confirmation_content_mismatch',
                'Potvrzení EPO je archivováno, ale jeho redukovaný obsah nelze přesně svázat s odeslaným balíčkem. Ověřte stav podání.',
                409,
                ['attempt_id' => $attemptId],
            );
        }
        $this->finalizeConfirmationState(
            $submission,
            $supplierId,
            $submittedBy,
            $attemptId,
            (string) $verification['reference'],
            (string) $verification['submitted_at'],
            $statePasswordCiphertext,
            $httpStatus,
            $environment,
        );
        $this->event(
            $supplierId,
            (int) $submission['id'],
            $attemptId,
            'submission_confirmed',
            'confirmed',
            $httpStatus,
            [
                'reference' => $verification['reference'],
                'chain_valid' => $verification['chain_valid'],
                'epo_signer_valid' => $verification['epo_signer_valid'],
                'content_match' => $verification['content_match'],
                'epo_environment' => $environment,
            ],
            $userId,
        );
        return [
            'attempt_id' => $attemptId,
            'status' => 'confirmed',
            'reference' => $verification['reference'],
            'submitted_at' => $verification['submitted_at'],
            'chain_valid' => $verification['chain_valid'],
            'environment' => $environment,
            'artifacts' => $this->epo->artifacts((int) $submission['id'], $supplierId),
            'attempts' => $this->epo->attempts((int) $submission['id'], $supplierId),
        ];
    }

    /**
     * Rozbalí dodejku a uloží její čitelné části k podání.
     *
     * U asistovaného podání nahraje účetní tyhle soubory ručně přes „Nahrát výstupy
     * z EPO"; u přímého je aplikace umí vytáhnout sama, takže by bylo divné nechat
     * uživatele otevírat binární P7S v hex editoru.
     *
     * BEST-EFFORT ZÁMĚRNĚ: právně rozhodující doklad o přijetí je P7S, a ta je v tomhle
     * místě už archivovaná. Kdyby selhání DOPROVODNÉHO souboru shodilo potvrzené podání
     * do „nejistého" stavu, vyrobili bychom si paniku kvůli příloze. Neúspěch se proto
     * jen zaznamená do auditu a pokus doběhne.
     *
     * @param array<string,mixed> $submission
     */
    private function archiveConfirmationParts(
        string $confirmationBytes,
        array $submission,
        int $supplierId,
        int $attemptId,
        ?int $userId,
        string $environment,
    ): void {
        $result = $this->confirmationParts->archive(
            $confirmationBytes,
            $submission,
            $supplierId,
            $attemptId,
            $userId,
            $environment,
        );

        if ($result['failed'] !== []) {
            $this->event(
                $supplierId,
                (int) $submission['id'],
                $attemptId,
                'confirmation_parts_incomplete',
                'confirmed',
                null,
                ['stored' => $result['stored'], 'failed' => $result['failed']],
                $userId,
            );
        }
    }

    /** @param array<string,mixed> $submission */
    private function finalizeConfirmationState(
        array $submission,
        int $supplierId,
        int $submittedBy,
        int $attemptId,
        string $reference,
        string $submittedAt,
        string $statePasswordCiphertext,
        int $httpStatus,
        string $environment,
    ): void {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->direct->recordConfirmed(
                $attemptId,
                $reference,
                $submittedAt,
                $statePasswordCiphertext,
                $httpStatus,
            );
            if ($environment === 'production') {
                if ($this->archiver->markSubmitted(
                    (int) $submission['id'],
                    $supplierId,
                    $submittedAt,
                    $reference,
                    $submittedBy,
                ) === null) {
                    throw new \RuntimeException('Tax submission disappeared.');
                }
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->direct->setStatus(
                $attemptId,
                'uncertain',
                $httpStatus,
                'confirmation_finalize_failed',
                'Potvrzení je archivováno, ale stav podání se nepodařilo dokončit.',
            );
            throw new EpoSubmissionException(
                'confirmation_finalize_failed',
                'Potvrzení EPO je archivováno, ale stav podání se nepodařilo dokončit.',
                500,
                ['attempt_id' => $attemptId],
            );
        }
    }

    /**
     * @param bool $allowSubmitted `false` = chystáme se něco odeslat ven (test, podání),
     *     `true` = jen dohledáváme nebo uzavíráme stav už existujícího pokusu. Rozlišení
     *     používá i brána na MOSS/OSS formuláře, viz {@see self::MOSS_OSS_FORMS}.
     * @param bool $allowActiveAssisted neúčinný test `test=1` smí běžet souběžně
     *     s otevřenou vizuální kontrolou v EPO; právně účinné podání ji dál blokuje.
     *
     * @return array<string,mixed>
     */
    private function validatedSubmission(
        int $submissionId,
        int $supplierId,
        bool $allowSubmitted = false,
        string $environment = 'production',
        bool $allowActiveAssisted = false,
    ): array {
        $submission = $this->submissions->find($submissionId, $supplierId);
        if ($submission === null) {
            throw new EpoSubmissionException('not_found', 'Podání nebylo nalezeno.', 404);
        }
        $formCode = (string) $submission['form_code'];
        if (in_array($formCode, self::MOSS_OSS_FORMS, true)) {
            if (!$allowSubmitted) {
                throw new EpoSubmissionException(
                    'moss_oss_only',
                    'OSS přiznání nelze odeslat přímým EPO API — podává se v aplikaci MOSS/OSS'
                    . ' na Daňovém portálu, do které se musíte přihlásit. Stáhněte XML a nahrajte ho tam.',
                    422,
                );
            }
        } elseif (!in_array($formCode, self::SUPPORTED_FORMS, true)) {
            throw new EpoSubmissionException(
                'unsupported_form',
                'Tento formulář nelze odeslat přímým EPO API.',
                422,
            );
        }
        if (
            !$allowSubmitted
            && ($allowActiveAssisted
                ? $this->direct->hasUnresolvedDirectAttempt(
                    $submissionId,
                    $supplierId,
                    $environment,
                )
                : $this->direct->hasUnresolvedLiveAttempt(
                    $submissionId,
                    $supplierId,
                    $environment,
                ))
        ) {
            throw new EpoSubmissionException(
                'submission_outcome_unresolved',
                'Podání má rozpracovaný nebo nejistý přímý pokus. Nejprve vyřešte jeho stav.',
                409,
            );
        }
        if (
            !$allowSubmitted
            && in_array((string) $submission['status'], ['submitted', 'accepted'], true)
        ) {
            throw new EpoSubmissionException(
                'already_submitted',
                'Tento XML snapshot už byl podán.',
                409,
            );
        }
        if ((string) $submission['validation_status'] !== 'passed') {
            throw new EpoSubmissionException(
                'validation_failed',
                'XML neprošlo lokální XSD validací.',
                422,
                ['validation_errors' => $submission['validation_errors'] ?? []],
            );
        }
        $actualSha = hash('sha256', (string) $submission['xml_content']);
        if (!hash_equals((string) $submission['xml_sha256'], $actualSha)) {
            throw new EpoSubmissionException(
                'snapshot_changed',
                'Archivovaný XML snapshot neodpovídá uloženému otisku.',
                409,
            );
        }
        return $submission;
    }

    private function fail(
        int $attemptId,
        int $supplierId,
        int $submissionId,
        int $userId,
        EpoException|EpoSubmissionException $error,
    ): void {
        $remoteStatus = $error instanceof EpoException ? $error->remoteHttpStatus : null;
        $this->direct->setStatus(
            $attemptId,
            'failed',
            $remoteStatus,
            $error->errorCode,
            $error->getMessage(),
        );
        $this->event(
            $supplierId,
            $submissionId,
            $attemptId,
            'operation_failed',
            'failed',
            $remoteStatus,
            ['error_code' => $error->errorCode],
            $userId,
        );
    }

    /** @param array<string,mixed> $submission */
    private function filename(array $submission, int $attemptId, string $suffix): string
    {
        return TaxSubmissionFilename::forSnapshot(
            $submission,
            $suffix,
            $attemptId,
            new \DateTimeImmutable('now'),
        );
    }

    private function assertCurrentEnvironment(string $attemptEnvironment): void
    {
        if ($attemptEnvironment !== $this->client->environment()) {
            throw new EpoSubmissionException(
                'epo_environment_changed',
                'Prostředí EPO se od vytvoření pokusu změnilo. Proveďte nový test v aktuálním prostředí.',
                409,
            );
        }
    }

    /** @param list<array<string,mixed>> $messages */
    private function messageSummary(array $messages): string
    {
        $parts = [];
        foreach (array_slice($messages, 0, 3) as $message) {
            $text = trim((string) ($message['text'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        return mb_substr($parts !== [] ? implode(' ', $parts) : 'EPO podání odmítlo.', 0, 500);
    }

    /** @param array<string,mixed> $details */
    private function event(
        int $supplierId,
        int $submissionId,
        int $attemptId,
        string $eventType,
        string $status,
        ?int $httpStatus,
        array $details,
        int $userId,
    ): void {
        $this->direct->addEvent(
            $supplierId,
            $submissionId,
            $attemptId,
            $eventType,
            $status,
            $httpStatus,
            $details,
            $userId,
        );
    }

    private function loadXml(string $xml): ?\DOMDocument
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        return $ok ? $dom : null;
    }

    private function decryptBinaryPayload(
        string $ciphertext,
        string $errorCode,
        string $message,
        string $purpose = 'epo:submitted-payload',
    ): string {
        if ($ciphertext === '') {
            throw new EpoSubmissionException($errorCode, $message, 500);
        }
        try {
            $bytes = base64_decode(
                $this->crypto->decryptFor($ciphertext, $purpose),
                true,
            );
        } catch (\RuntimeException) {
            $bytes = false;
        }
        if (!is_string($bytes) || $bytes === '') {
            throw new EpoSubmissionException($errorCode, $message, 500);
        }
        return $bytes;
    }

    private function offlineErrorsXml(\DOMDocument $dom): string
    {
        $xpath = new \DOMXPath($dom);
        $errors = $xpath->query('//*[local-name()="Chyby"]')->item(0);
        if (!$errors instanceof \DOMElement) {
            return '<Chyby><Chyba Typ="K" Zkr="OFFLINE_REJECTED"><Text>Rozsáhlé podání nebylo přijato.</Text></Chyba></Chyby>';
        }
        $out = new \DOMDocument('1.0', 'UTF-8');
        $out->appendChild($out->importNode($errors, true));
        return (string) $out->saveXML();
    }
}

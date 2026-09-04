<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

/**
 * Odchozí fronta podání (migrace 1381).
 *
 * Projekce {@see COLUMNS} vědomě neobsahuje `idempotency_key_hash` — stejně
 * jako u `PayrollSubmissionTransportAttemptRepository`. Klíč slouží k hlídání
 * duplicit, ne k zobrazování.
 *
 * Všechny mutace jdou přes {@see mutate()} s optimistickým zámkem
 * (`row_version`), protože DB trigger vyžaduje posun právě o jedničku.
 */
final class SubmissionOutboxRepository
{
    private const TABLE = 'submission_outbox';

    private const COLUMNS = 'id, supplier_id, environment, channel, dispatch_mode, agenda_code, recipient_id,
        recipient_box_id, subject, artifact_kind, artifact_id, artifact_filename, artifact_sha256,
        dispatch_state, acceptance_state, acceptance_evidence_kind, acceptance_note,
        correlation_reference, external_message_id, artifact_validation_status, artifact_validated_at,
        recipient_box_verified_at, receipt_document_id, receipt_signature_status,
        receipt_matched_by, receipt_inbox_message_id, receipt_attached_at,
        confirmed_by, confirmed_at, sent_at,
        delivered_at, accepted_at, rejected_at, failed_at, last_error_code, last_error_message,
        row_version, created_by, created_at, updated_at';

    /**
     * Stavy, ve kterých má smysl k podání připojovat doručenku.
     *
     * `ready` je ve výčtu schválně: u ručního odeslání aplikace o odchodu
     * zprávy neví, dokud uživatel nepřinese doručenku. Ta je tedy zároveň
     * důkazem, že podání odešlo.
     */
    private const RECEIPT_OPEN_STATES = ['ready', 'sending', 'send_uncertain', 'sent'];

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable(self::TABLE);
    }

    /**
     * Zařadí podání do fronty. Idempotentní: shodný klíč vrátí existující
     * řádek místo druhého zápisu.
     *
     * @param array{
     *   supplier_id:int, environment:string, channel:string, agenda_code:string,
     *   recipient_id:?int, recipient_box_id:?string, subject:string,
     *   artifact_kind:string, artifact_id:int, artifact_filename:string, artifact_sha256:string,
     *   correlation_reference:string, created_by:?int
     * } $data
     * @return array{row:array<string,mixed>,created:bool}
     */
    public function enqueue(array $data, string $idempotencyKey): array
    {
        $this->assertAvailable();
        $hash = hash('sha256', $idempotencyKey, true);

        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO ' . self::TABLE . '
                    (supplier_id, environment, channel, agenda_code, recipient_id, recipient_box_id,
                     subject, artifact_kind, artifact_id, artifact_filename, artifact_sha256,
                     idempotency_key_hash, correlation_reference, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $data['supplier_id'],
                $data['environment'],
                $data['channel'],
                $data['agenda_code'],
                $data['recipient_id'],
                $data['recipient_box_id'],
                $data['subject'],
                $data['artifact_kind'],
                $data['artifact_id'],
                $data['artifact_filename'],
                $data['artifact_sha256'],
                $hash,
                $data['correlation_reference'],
                $data['created_by'],
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $existing = $this->findByIdempotencyHash($hash);
            if ($existing === null) {
                // Kolidovala correlation reference, ne idempotenční klíč —
                // to je jiný artefakt se stejnou značkou a musí dostat novou.
                throw new \DomainException('Spisová značka podání se opakuje, vygenerujte novou.');
            }
            if ((int) $existing['supplier_id'] !== $data['supplier_id']) {
                throw new \DomainException('Idempotenční klíč už patří jiné firmě.');
            }
            if ((string) $existing['artifact_sha256'] !== $data['artifact_sha256']) {
                throw new \DomainException(
                    'Stejný idempotenční klíč nesmí nést jiný obsah podání.',
                );
            }
            return ['row' => $existing, 'created' => false];
        }

        $id = (int) $this->db->pdo()->lastInsertId();
        $row = $this->find($data['supplier_id'], $id);
        if ($row === null) {
            throw new \RuntimeException('Podání se zařadilo, ale nepodařilo se ho načíst.');
        }
        return ['row' => $row, 'created' => true];
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::normalize($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function findByCorrelation(int $supplierId, string $correlationReference): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND correlation_reference = ?'
        );
        $stmt->execute([$supplierId, $correlationReference]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::normalize($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function findByExternalMessageId(int $supplierId, string $channel, string $messageId): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND channel = ? AND external_message_id = ?'
        );
        $stmt->execute([$supplierId, $channel, $messageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::normalize($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId, string $environment, int $limit = 100): array
    {
        $this->assertAvailable();
        $limit = max(1, min(500, $limit));
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND environment = ?
              ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute([$supplierId, $environment]);
        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Podání, u kterých má smysl se ptát na stav (odeslaná bez doručenky
     * a nedořešená po přerušeném odeslání).
     *
     * @return list<array<string,mixed>>
     */
    public function listPollable(int $limit = 50): array
    {
        $this->assertAvailable();
        $limit = max(1, min(200, $limit));
        // `sending` je ve výběru schválně: řádek, který v něm uvízl po pádu
        // procesu, je stejná nevědomost jako `send_uncertain` a musí se dořešit
        // dohledáním, ne zapomenout.
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
              WHERE dispatch_state IN (\'sent\', \'send_uncertain\', \'sending\')
              ORDER BY updated_at ASC LIMIT ' . $limit
        );
        $stmt->execute();
        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Zabere podání k odeslání. Uspěje právě jednou — na tom stojí ochrana
     * před dvojím podáním u úřadu.
     *
     * Bere i stav `failed`: neúspěšný pokus není konec cesty. Chyby, na kterých
     * odesílání padá nejčastěji — špatná metoda přihlášení, vypršelá relace,
     * odmítnutý certifikát — nastávají PŘED tím, než se zpráva dostane
     * ke zpracování, takže u úřadu nic neleží a zpráva sama je pořád platná.
     * Dokud se z `failed` zabrat nedalo, zůstala připravená zpráva viset bez
     * jediného tlačítka a účetní ji musela zahodit a připravit znovu.
     *
     * Stav `send_uncertain` se tu VĚDOMĚ nebere: tam nevíme, jestli zpráva
     * odešla, a druhý pokus by mohl založit druhé podání. Ten se řeší
     * rekonciliací (`resolveUncertain`), ne opakováním.
     */
    public function claimForSending(int $supplierId, int $id, int $confirmedBy): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . '
                SET dispatch_state = \'sending\', confirmed_by = ?, confirmed_at = UTC_TIMESTAMP(),
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ? AND dispatch_state IN (\'ready\', \'failed\')'
        );
        $stmt->execute([$confirmedBy, $supplierId, $id]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }
        return $this->find($supplierId, $id);
    }

    /** @return array<string,mixed> */
    public function markSent(int $supplierId, int $id, string $externalMessageId, int $expectedVersion): array
    {
        return $this->mutate(
            $supplierId,
            $id,
            'dispatch_state = \'sent\', external_message_id = ?, sent_at = UTC_TIMESTAMP(),
             last_error_code = NULL, last_error_message = NULL',
            [$externalMessageId],
            $expectedVersion,
        );
    }

    /** @return array<string,mixed> */
    public function markUncertain(int $supplierId, int $id, string $errorCode, string $errorMessage, int $expectedVersion): array
    {
        return $this->mutate(
            $supplierId,
            $id,
            'dispatch_state = \'send_uncertain\', last_error_code = ?, last_error_message = ?',
            [$errorCode, mb_substr($errorMessage, 0, 500)],
            $expectedVersion,
        );
    }

    /** @return array<string,mixed> */
    public function markFailed(int $supplierId, int $id, string $errorCode, string $errorMessage, int $expectedVersion): array
    {
        return $this->mutate(
            $supplierId,
            $id,
            'dispatch_state = \'failed\', failed_at = UTC_TIMESTAMP(), last_error_code = ?, last_error_message = ?',
            [$errorCode, mb_substr($errorMessage, 0, 500)],
            $expectedVersion,
        );
    }

    /**
     * Zapíše doručení — a NIC JINÉHO.
     *
     * Osy vyřízení se nedotýká ani náhodou; DB trigger by zápis, který by obojí
     * změnil jedním UPDATE, odmítl. Je to úmysl: doručenka není protokol.
     *
     * @return array<string,mixed>
     */
    public function markDelivered(int $supplierId, int $id, \DateTimeImmutable $deliveredAt, int $expectedVersion): array
    {
        return $this->mutate(
            $supplierId,
            $id,
            'dispatch_state = \'delivered\', delivered_at = ?',
            [$deliveredAt->format('Y-m-d H:i:s')],
            $expectedVersion,
        );
    }

    /**
     * Zapíše rozhodnutí úřadu. `$evidenceKind` je povinné a DB ho vyžaduje
     * také — doručenka pro něj nemá hodnotu, takže se sem nedostane.
     *
     * @return array<string,mixed>
     */
    public function recordAcceptance(
        int $supplierId,
        int $id,
        string $acceptanceState,
        string $evidenceKind,
        ?string $note,
        int $expectedVersion,
    ): array {
        $timeColumn = $acceptanceState === 'accepted' ? 'accepted_at' : 'rejected_at';
        return $this->mutate(
            $supplierId,
            $id,
            'acceptance_state = ?, acceptance_evidence_kind = ?, acceptance_note = ?, '
            . $timeColumn . ' = UTC_TIMESTAMP()',
            [$acceptanceState, $evidenceKind, $note !== null ? mb_substr($note, 0, 500) : null],
            $expectedVersion,
        );
    }

    /**
     * Zapíše výsledek lokální XSD kontroly. Bez ní se podání datovkou
     * neodešle — hlídá to CHECK `chk_submission_outbox_validation_gate`.
     *
     * @return array<string,mixed>
     */
    public function recordValidation(int $supplierId, int $id, string $status, int $expectedVersion): array
    {
        return $this->mutate(
            $supplierId,
            $id,
            'artifact_validation_status = ?, artifact_validated_at = UTC_TIMESTAMP()',
            [$status],
            $expectedVersion,
        );
    }

    /**
     * Zapíše, že schránka příjemce byla ověřena dotazem do ISDS.
     *
     * @return array<string,mixed>
     */
    public function recordRecipientVerified(int $supplierId, int $id, int $expectedVersion): array
    {
        return $this->mutate(
            $supplierId,
            $id,
            'recipient_box_verified_at = UTC_TIMESTAMP()',
            [],
            $expectedVersion,
        );
    }

    /**
     * Připojí archivovanou doručenku k podání (důkaz o dni podání dle § 73 odst. 1 DŘ).
     *
     * `$matchedBy` je auditní stopa, ne dekorace: podle ní se pozná, jestli
     * vazbu našel automat přes přesný identifikátor, nebo ji potvrdil člověk.
     * Přiřazení je jednorázové — hlídá to i DB trigger, takže druhá doručenka
     * první nepřepíše.
     *
     * `receipt_signature_status` tu ZÁMĚRNĚ zůstává `unverified`: CMS podpis
     * ani časové razítko doručenky neověřujeme.
     *
     * @param 'correlation_reference'|'external_message_id'|'manual' $matchedBy
     * @return array<string,mixed>
     */
    public function attachReceipt(
        int $supplierId,
        int $id,
        int $documentId,
        ?int $inboxMessageId,
        string $matchedBy,
        int $expectedVersion,
    ): array {
        return $this->mutate(
            $supplierId,
            $id,
            'receipt_document_id = ?, receipt_inbox_message_id = ?, receipt_matched_by = ?,
             receipt_attached_at = UTC_TIMESTAMP()',
            [$documentId, $inboxMessageId, $matchedBy],
            $expectedVersion,
        );
    }

    /**
     * Přechod `ready` → `sending` pro RUČNÍ odeslání.
     *
     * Liší se od {@see claimForSending()} jedinou věcí, která je ale zásadní:
     * zároveň přepíná `dispatch_mode` na `manual`, a tím vypíná bránu ověření
     * schránky v ISDS. Ta se u ručního odeslání nemá čím naplnit — adresáta
     * vybírá člověk ve své datové schránce. Trigger dovolí `dispatch_mode`
     * změnit jen dokud je řádek `ready`, takže se to musí stát právě tady.
     *
     * @return array<string,mixed>|null null, když už řádek `ready` není
     */
    public function claimForManualSending(int $supplierId, int $id, int $confirmedBy): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . '
                SET dispatch_state = \'sending\', dispatch_mode = \'manual\',
                    confirmed_by = ?, confirmed_at = UTC_TIMESTAMP(),
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ? AND dispatch_state = \'ready\''
        );
        $stmt->execute([$confirmedBy, $supplierId, $id]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }

        return $this->find($supplierId, $id);
    }

    /**
     * Přechod `ready` → `sending` pro ODESÍLACÍ BRÁNU ISDS.
     *
     * Volá se AŽ POTÉ, co uživatel koncept v ISDS schválil a brána vrátila
     * `conceptStatusCode`. Do té doby řádek zůstává `ready` schválně: vložený
     * koncept ještě není odeslaná zpráva a zamítnutí musí jít bez následků
     * opakovat — a z `sending` se podle triggeru zpátky na `ready` nedostaneme.
     *
     * `dispatch_mode = 'gateway'` vypíná bránu ověření schránky v ISDS
     * (`chk_submission_outbox_box_verification_gate`, migrace 1413). Odesílací
     * brána čtení schránky neumí a příjemce místo toho ověřuje samo ISDS
     * v okamžiku schválení. Trigger dovolí `dispatch_mode` změnit jen dokud je
     * řádek `ready`, takže se to musí stát právě tady.
     *
     * @return array<string,mixed>|null null, když už řádek `ready` není
     */
    public function claimForGatewaySending(int $supplierId, int $id, int $confirmedBy): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . '
                SET dispatch_state = \'sending\', dispatch_mode = \'gateway\',
                    confirmed_by = ?, confirmed_at = UTC_TIMESTAMP(),
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ? AND dispatch_state = \'ready\''
        );
        $stmt->execute([$confirmedBy, $supplierId, $id]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }

        return $this->find($supplierId, $id);
    }

    /**
     * Zapíše odeslání, které proběhlo mimo aplikaci.
     *
     * Čas odeslání se NEBERE z hodin serveru: u ručního odeslání se zpráva
     * podala dřív, než jsme se o tom dozvěděli, a `sent_at` z „teď" by se
     * dostalo za `delivered_at` z doručenky — což CHECK `chk_submission_outbox_timeline`
     * správně odmítne.
     *
     * @return array<string,mixed>
     */
    public function markSentManually(
        int $supplierId,
        int $id,
        string $externalMessageId,
        \DateTimeImmutable $sentAt,
        int $expectedVersion,
    ): array {
        return $this->mutate(
            $supplierId,
            $id,
            'dispatch_state = \'sent\', external_message_id = ?, sent_at = ?,
             last_error_code = NULL, last_error_message = NULL',
            [$externalMessageId, $sentAt->format('Y-m-d H:i:s')],
            $expectedVersion,
        );
    }

    /**
     * Podání, ke kterým může nahraná doručenka patřit.
     *
     * ⚠️ Tohle je NABÍDKA ČLOVĚKU, ne párovací pravidlo. Shoda schránky
     * příjemce a časového okna je domněnka; automatická vazba vzniká jedině
     * přes přesný identifikátor (naši spisovou značku nebo dmID). Kdyby se
     * podle téhle nabídky párovalo samo, stačily by dvě podání stejné agendy
     * do stejné schránky k tomu, aby se doručenka přilepila k tomu špatnému.
     *
     * @param ?string $recipientBoxId schránka, do které doručenka míří (z doručenky)
     * @param ?\DateTimeImmutable $around čas dodání z doručenky
     * @return list<array<string,mixed>>
     */
    public function listReceiptCandidates(
        int $supplierId,
        string $environment,
        ?string $recipientBoxId,
        ?\DateTimeImmutable $around,
        int $limit = 20,
    ): array {
        $this->assertAvailable();
        $limit = max(1, min(50, $limit));

        $states = "'" . implode("','", self::RECEIPT_OPEN_STATES) . "'";
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
                 WHERE supplier_id = ? AND environment = ? AND channel = \'isds\'
                   AND dispatch_state IN (' . $states . ')
                   AND receipt_document_id IS NULL';
        $params = [$supplierId, $environment];

        if ($recipientBoxId !== null && $recipientBoxId !== '') {
            $sql .= ' AND recipient_box_id = ?';
            $params[] = $recipientBoxId;
        }
        if ($around !== null) {
            // Podání se do fronty zařadí před odesláním, doručenka přijde po něm.
            // Okno je široké schválně — raději nabídnout víc a nechat rozhodnout
            // člověka, než tichým zúžením zatajit ten správný řádek.
            // Dopředná tolerance kryje i to, že `created_at` je serverový čas,
            // kdežto čas v doručence nese vlastní posun.
            $sql .= ' AND created_at BETWEEN DATE_SUB(?, INTERVAL 400 DAY) AND DATE_ADD(?, INTERVAL 7 DAY)';
            $params[] = $around->format('Y-m-d H:i:s');
            $params[] = $around->format('Y-m-d H:i:s');
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Odeslané zprávy datovky, které ještě nemají doručenku a jde si o ni říct.
     *
     * Slouží hromadnému stažení: jedno přihlášení do schránky vyřídí všechny
     * čekající dodejky místo toho, aby účetní potvrzovala Mobilní klíč u každé
     * zprávy zvlášť.
     *
     * Do výběru se dostane jen zpráva, u které má dotaz smysl: kanál `isds`
     * (jinde dodejka neexistuje), doložené odeslání a uložené `dmID`, kterým se
     * ISDS ptáme. Bez něj by dotaz stejně skončil chybou, takže takový řádek
     * nemá v dávce co dělat.
     *
     * @return list<array<string,mixed>>
     */
    public function listAwaitingDeliveryReceipt(
        int $supplierId,
        string $environment,
        int $limit = 50,
    ): array {
        $this->assertAvailable();
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND environment = ? AND channel = \'isds\'
                AND receipt_document_id IS NULL
                AND external_message_id IS NOT NULL
                AND external_message_id <> \'\'
                AND dispatch_state IN (\'sent\', \'delivered\')
              ORDER BY sent_at DESC, id DESC
              LIMIT ' . $limit,
        );
        $stmt->execute([$supplierId, $environment]);

        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed> */
    public function cancel(int $supplierId, int $id, int $expectedVersion): array
    {
        return $this->mutate($supplierId, $id, 'dispatch_state = \'cancelled\'', [], $expectedVersion);
    }

    /**
     * Kolik záznamů na tuhle zprávu odjinud ukazuje.
     *
     * Slouží {@see \MyInvoice\Service\Submission\SubmissionOutboxDeletionPolicy}
     * k rozhodnutí, jestli zrušená zpráva vůbec smí zmizet. Počítá se i to,
     * co samo o sobě neznamená odeslání (zahájená relace odesílací brány):
     * takový záznam je auditní stopa s `ON DELETE RESTRICT`, takže by mazání
     * stejně skončilo chybou z databáze — a to je horší než tlačítko, které
     * se vůbec nenabídne.
     *
     * `hasTable()` u každé tabulky zvlášť: instalace, kde ještě neproběhly
     * pozdější migrace (1394, 1412, 1583), nemá důvod přijít o mazání.
     *
     * @return array{
     *   inbox_messages:int,defect_notices:int,
     *   gateway_sessions:int,enforcement_dispatches:int
     * }
     */
    public function linkedRecordCounts(int $supplierId, int $id): array
    {
        $this->assertAvailable();

        return [
            'inbox_messages' => $this->countLinked(
                'submission_inbox_messages',
                'SELECT COUNT(*) FROM submission_inbox_messages
                  WHERE supplier_id = ? AND matched_outbox_id = ?',
                [$supplierId, $id],
            ),
            'defect_notices' => $this->countLinked(
                'submission_defect_notices',
                'SELECT COUNT(*) FROM submission_defect_notices
                  WHERE supplier_id = ? AND (outbox_id = ? OR response_outbox_id = ?)',
                [$supplierId, $id, $id],
            ),
            'gateway_sessions' => $this->countLinked(
                'isds_gateway_sessions',
                'SELECT COUNT(*) FROM isds_gateway_sessions
                  WHERE supplier_id = ? AND outbox_id = ?',
                [$supplierId, $id],
            ),
            'enforcement_dispatches' => $this->countLinked(
                'payroll_enforcement_xmlzam_dispatches',
                'SELECT COUNT(*) FROM payroll_enforcement_xmlzam_dispatches
                  WHERE supplier_id = ? AND outbox_id = ?',
                [$supplierId, $id],
            ),
        ];
    }

    /**
     * Trvale smaže zrušenou odchozí zprávu.
     *
     * Podmínky v `WHERE` jsou poslední pojistka, ne hlavní brána: o tom, jestli
     * se mazat smí, rozhoduje {@see \MyInvoice\Service\Submission\SubmissionOutboxDeletionPolicy}
     * nad kompletní stopou. Tady se jen ověří v SAMOTNÉM zápisu, že mezi
     * rozhodnutím a mazáním zpráva neodešla — souběžné odeslání by jinak
     * smazalo doklad.
     *
     * @return bool false, když řádek podmínkám neodpovídá (nebo už není)
     */
    public function deleteCancelled(int $supplierId, int $id): bool
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND id = ?
                AND dispatch_state = \'cancelled\'
                AND external_message_id IS NULL
                AND sent_at IS NULL
                AND delivered_at IS NULL
                AND acceptance_state = \'unknown\'
                AND receipt_document_id IS NULL
                AND receipt_inbox_message_id IS NULL'
        );
        $stmt->execute([$supplierId, $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Podklad, ze kterého zpráva vznikla, a jeho VLASTNÍ stav.
     *
     * ── Proč to fronta vůbec musí umět říct ─────────────────────────────────
     * „Zrušit" na téhle obrazovce ruší odchozí ZPRÁVU, ne povinnost. Podání
     * samo dál existuje a čeká na odeslání — jenže to je vidět až na jiné
     * obrazovce. Uživatel byl přesvědčený, že zrušením podání smazal, a pak
     * se divil, že mu ho fronta mzdových podání pořád nabízí. Obě obrazovky
     * měly pravdu o něčem jiném a nikde to nebylo napsané.
     *
     * @return array{kind:string,status:string,pending:bool,agenda_code:?string,period:?string}|null
     *         null = podklad neznáme (dokument, smazaný artefakt, chybějící tabulka)
     */
    public function sourceObligation(
        int $supplierId,
        string $environment,
        string $artifactKind,
        int $artifactId,
    ): ?array {
        return match ($artifactKind) {
            'payroll_submission' => $this->payrollSource($supplierId, $environment, $artifactId),
            'tax_submission' => $this->taxSource($supplierId, $artifactId),
            default => null,
        };
    }

    // ───────────────────────── interní ─────────────────────────

    /** @param list<mixed> $params */
    private function countLinked(string $table, string $sql, array $params): int
    {
        if (!$this->db->hasTable($table)) {
            return 0;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Mzdové podání: `artifact_id` míří na zmrazený artefakt, ne na podání,
     * takže se k povinnosti dojde přes něj. Za „čeká na odeslání" se považují
     * jen stavy PŘED podáním — `submitted` a výš už podané je, i když zpráva
     * z fronty zmizela.
     *
     * @return array{kind:string,status:string,pending:bool,agenda_code:?string,period:?string}|null
     */
    private function payrollSource(int $supplierId, string $environment, int $artifactId): ?array
    {
        if (!$this->db->hasTable('payroll_submission_artifacts')) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT submission.status, submission.submitted_at,
                    obligation.agenda_code, obligation.period_start
               FROM payroll_submission_artifacts artifact
               JOIN payroll_submissions submission
                 ON submission.supplier_id = artifact.supplier_id
                AND submission.environment = artifact.environment
                AND submission.id = artifact.submission_id
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = submission.supplier_id
                AND obligation.environment = submission.environment
                AND obligation.id = submission.obligation_id
              WHERE artifact.supplier_id = ? AND artifact.environment = ? AND artifact.id = ?'
        );
        $stmt->execute([$supplierId, $environment, $artifactId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $status = (string) $row['status'];
        $period = (string) $row['period_start'];

        return [
            'kind' => 'payroll_submission',
            'status' => $status,
            'pending' => $row['submitted_at'] === null && in_array(
                $status,
                ['draft', 'validated', 'prepared', 'ready', 'waiting_for_identity'],
                true,
            ),
            'agenda_code' => (string) $row['agenda_code'],
            'period' => substr($period, 0, 7),
        ];
    }

    /**
     * @return array{kind:string,status:string,pending:bool,agenda_code:?string,period:?string}|null
     */
    private function taxSource(int $supplierId, int $artifactId): ?array
    {
        if (!$this->db->hasTable('tax_submissions')) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT status, form_code, period_year, period_month, period_quarter
               FROM tax_submissions WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $artifactId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $period = (string) $row['period_year'];
        if ($row['period_month'] !== null) {
            $period .= '-' . str_pad((string) $row['period_month'], 2, '0', STR_PAD_LEFT);
        } elseif ($row['period_quarter'] !== null) {
            $period .= '-Q' . (string) $row['period_quarter'];
        }
        $status = (string) $row['status'];

        return [
            'kind' => 'tax_submission',
            'status' => $status,
            // `rejected` je taky nesplněná povinnost, ale podané JIŽ BYLO —
            // věta „čeká na odeslání" by u něj lhala.
            'pending' => !in_array($status, ['submitted', 'accepted', 'rejected'], true),
            'agenda_code' => strtoupper((string) $row['form_code']),
            'period' => $period,
        ];
    }

    /** @return array<string,mixed>|null */
    private function findByIdempotencyHash(string $hash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' WHERE idempotency_key_hash = ?'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * @param list<mixed> $params
     *
     * @return array<string,mixed>
     */
    private function mutate(int $supplierId, int $id, string $set, array $params, int $expectedVersion): array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . ' SET ' . $set . ', row_version = row_version + 1
              WHERE supplier_id = ? AND id = ? AND row_version = ?'
        );
        $stmt->execute([...$params, $supplierId, $id, $expectedVersion]);
        if ($stmt->rowCount() !== 1) {
            $current = $this->find($supplierId, $id);
            if ($current === null) {
                throw new \DomainException('Podání ve frontě neexistuje.');
            }
            throw new \DomainException(sprintf(
                'Podání se mezitím změnilo (očekávána verze %d, aktuální %d).',
                $expectedVersion,
                (int) $current['row_version'],
            ));
        }
        $row = $this->find($supplierId, $id);
        if ($row === null) {
            throw new \RuntimeException('Podání se změnilo, ale nepodařilo se ho načíst.');
        }
        return $row;
    }

    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new \DomainException('Fronta podání není v databázi k dispozici (chybí migrace 1381).');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        foreach (['id', 'supplier_id', 'artifact_id', 'row_version'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        foreach (['recipient_id', 'confirmed_by', 'created_by', 'receipt_document_id', 'receipt_inbox_message_id'] as $key) {
            $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
        }
        return $row;
    }
}

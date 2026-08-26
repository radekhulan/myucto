<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Repository\Payroll\PayrollSigningProfileRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Signing\PersonalCertificateVaultService;

/**
 * Odeslání měsíčního hlášení na VREP a dotažení výsledku zpracování.
 *
 * Celá cesta je OVĚŘENÁ PROVOZEM proti testovacímu prostředí ČSSZ: obálka,
 * odpojený podpis PKCS#7 nad původními bajty datové věty, gzip, šifrování CMS
 * na certifikát ČSSZ, endpoint, potvrzení převzetí, dotaz na stav i uzavření
 * transakce. Tahle třída z toho dělá cestu aplikace místo ručního skriptu.
 *
 * Tři věci, na kterých to jinak spadne a které tu proto drží pořádek:
 *
 * 1. **Potvrzení převzetí není přijetí podání.** VREP odpoví
 *    `Qualifier=acknowledgement` hned a zpracování běží dál; protokol přijde
 *    až na dotaz. Pokus proto končí ve stavu `awaiting_protocol`, ne
 *    `completed`, a uživateli se nehlásí hotovo.
 * 2. **Transakce se musí uzavřít.** Podací protokol to říká výslovně a
 *    aplikace, které to nedělají, porušují pravidla provozu. Uzavírá se
 *    FUNKCÍ `delete`, ne kvalifikátorem — `Qualifier=delete` vrátí
 *    „Invalid qualifier". Zjištěno pokusem, ne z dokumentace.
 * 3. **Bez zapsaného CorrelationID je podání ztracené.** Ledger proto dostane
 *    correlation reference dřív, než se cokoli dalšího stane, a je to
 *    jednorázové přiřazení.
 *
 * Produkční endpoint VREP doložený není a `JmhzVrepClient` ho neodhaduje.
 * Produkční pokus tedy skončí výjimkou — což je správně: odeslat ostré hlášení
 * na hádaný cíl znamená, že lhůta uplyne bez povšimnutí.
 */
final readonly class JmhzDispatchService
{
    public const CHANNEL = 'vrep_apep';
    public const SUBMISSION_CLASS = 'CSSZ_JMHZ';

    public function __construct(
        private PayrollSubmissionTransportAttemptRepository $attempts,
        private PayrollSigningProfileRepository $profiles,
        private PersonalCertificateVaultService $vault,
        private SecretEncryption $secrets,
        private JmhzSoftwareIdentification $software,
        private ?JmhzVrepClient $client = null,
        private JmhzAcknowledgementParser $acknowledgements = new JmhzAcknowledgementParser(),
        private JmhzProtocolParser $protocols = new JmhzProtocolParser(),
        // Platforma podání je volitelná jen kvůli testovacím dvojníkům; v
        // produkci je navázaná v Bootstrap. Bez ní se odesílá dál, jen podání
        // nezmění stav a datová věta se musí předat ručně.
        private ?JmhzFrozenPayloadReader $frozen = null,
        private ?PayrollSubmissionService $submissions = null,
        /**
         * Ověřovatel podpisu protokolu. `null` znamená připnutý certifikát
         * ČSSZ; podstrčit se dá jen v testech, kde protokol nikdo nepodepíše
         * klíčem ČSSZ. VYPNOUT ověření nejde — protokol bez ověřeného podpisu
         * se uloží jako nedůvěryhodná příloha a stav podání nechá být.
         */
        private ?JmhzProtocolSignatureVerifierInterface $signatures = null,
    ) {}

    /**
     * Odešle připravenou datovou větu. Idempotenční klíč je povinný: bez něj
     * by opakované kliknutí založilo druhé podání za totéž období a ČSSZ ho
     * odmítne jako duplicitu — ověřeno chybou 20022.
     */
    public function send(
        int $supplierId,
        string $environment,
        int $submissionId,
        ?string $payloadXml,
        string $variableSymbol,
        string $idempotencyKey,
        ?int $actorUserId,
        string $submissionClass = self::SUBMISSION_CLASS,
    ): JmhzDispatchOutcome {
        // Bez předané datové věty se bere ta ZMRAZENÁ. Je to jediný dokument,
        // který se pod tímhle podáním smí odeslat — postavit XML znovu by
        // znamenalo nové GUIDy a tedy jiný dokument pod týmž podáním.
        if ($payloadXml === null || trim($payloadXml) === '') {
            if ($this->frozen === null) {
                throw new JmhzTransportException(
                    'jmhz_dispatch_payload_missing',
                    'Chybí datová věta zmrazeného podání.',
                );
            }
            $payloadXml = $this->frozen->bytes($supplierId, $environment, $submissionId);
        }
        $signer = $this->signer($supplierId, $environment);
        $material = $signer->unlock();

        $sealed = (new JmhzGovTalkEnvelope(JmhzGovTalkRequestShape::documented()))->seal(
            $payloadXml,
            $variableSymbol,
            $submissionClass,
            $environment,
            $this->software,
            new JmhzDetachedSigner(),
            $material['pfx'],
            $material['password'],
        );

        // Ledger se zakládá PŘED odesláním. Kdyby se založil až potom, pád
        // mezi odesláním a zápisem by po sobě nechal podání u ČSSZ, o kterém
        // aplikace neví — a druhý pokus by narazil na duplicitu bez vysvětlení.
        $attempt = $this->attempts->open(
            $supplierId,
            $environment,
            $submissionId,
            self::CHANNEL,
            $this->attempts->nextAttemptNo($supplierId, $environment, $submissionId),
            $idempotencyKey,
            // Otisk patří zmrazenému úřednímu dokumentu, ne CMS obálce.
            // Šifrování používá náhodu, takže dvě obálky týchž bajtů mají
            // jiný hash a opakovaný idempotenční klíč by jinak falešně kolidoval.
            hash('sha256', $payloadXml),
            $actorUserId,
        );
        if (($attempt['status'] ?? null) !== 'prepared') {
            // Klíč už jednou prošel: vracíme původní pokus, ne druhé odeslání.
            return new JmhzDispatchOutcome($attempt);
        }

        $client = $this->client($environment);
        try {
            $response = $client->submit($sealed->sendableXml(null));
        } catch (JmhzTransportException $exception) {
            $this->recordFailure(
                $attempt,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->remoteHttpStatus,
            );

            throw $exception;
        }

        // Od tohoto místa je zpráva U ČSSZ. Cokoli, co selže dál, musí zůstat
        // v ledgeru jako neúspěšný pokus, ne jako nezahájený: nečitelná
        // odpověď, potvrzení bez CorrelationID i ztracený optimistický zámek
        // by jinak nechaly řádek ve stavu `prepared`, obsluha by odeslala
        // znovu a ČSSZ by druhé podání odmítla jako duplicitu.
        try {
            $acknowledgement = $this->acknowledgements->parse(
                $response->body,
                $submissionClass,
            );
            if ($acknowledgement === null) {
                // Odpověď na podání, která není potvrzením, je buď rovnou
                // protokol o zamítnutí, nebo něco neznámého.
                $failure = $this->describeImmediateFailure($response->body);

                throw new JmhzTransportException($failure[0], $failure[1], $response->httpStatus);
            }

            $attempt = $this->attempts->markSent(
                (int) $attempt['id'],
                $acknowledgement->correlationId,
                $response->httpStatus,
                (int) $attempt['row_version'],
                // Termín prvního dotazu se zapisuje rovnou při odeslání: bez něj
                // by se běh na pozadí neměl čeho chytit a podání by čekalo na
                // to, až si na ně někdo vzpomene.
                JmhzPollSchedule::nextRetryAt(
                    $this->now(),
                    0,
                    $acknowledgement->pollIntervalSeconds,
                ),
            );
        } catch (\Throwable $exception) {
            $this->recordFailure(
                $attempt,
                $exception instanceof JmhzTransportException
                    ? $exception->errorCode
                    : 'jmhz_dispatch_send_unresolved',
                $exception->getMessage(),
                $response->httpStatus,
            );

            throw $exception;
        }

        // Podání teď leží u ČSSZ, a platforma to musí vědět: dokud zůstane
        // `ready`, hlásí ho inbox jako nepodané a nedá se na něj navázat storno
        // ani oprava. Selhání téhle změny NESMÍ přebít úspěšné odeslání —
        // důkaz o něm je v ledgeru a ten je závaznější.
        $this->markSubmitted($supplierId, $submissionId, $acknowledgement->correlationId);

        return new JmhzDispatchOutcome($attempt, $acknowledgement);
    }

    private function markSubmitted(
        int $supplierId,
        int $submissionId,
        string $correlationReference,
    ): void {
        if ($this->submissions === null) {
            return;
        }
        try {
            $submission = $this->submissions->get($supplierId, $submissionId);
            if ($submission['status'] !== 'ready') {
                return;
            }
            $this->submissions->transition(
                $supplierId,
                $submissionId,
                $submission['row_version'],
                'submitted',
                $correlationReference,
            );
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * Zápis neúspěchu nesmí přebít původní chybu. Když se pokus mezitím
     * posunul (jiný běh, ztracený zámek), je zápis marný — ale zahodit kvůli
     * tomu důvod, proč odeslání selhalo, by bylo horší než neúplný ledger.
     *
     * @param array<string,mixed> $attempt
     */
    private function recordFailure(
        array $attempt,
        string $errorCode,
        string $message,
        ?int $httpStatus,
    ): void {
        try {
            $this->attempts->markFailed(
                (int) $attempt['id'],
                $errorCode,
                $message,
                $httpStatus,
                null,
                (int) $attempt['row_version'],
            );
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * Poslední pokusy o odeslání, od nejnovějšího. Čte se rovnou z ledgeru:
     * jiný zdroj pravdy o tom, co odešlo, neexistuje.
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $supplierId, string $environment, int $limit = 50): array
    {
        return $this->attempts->listRecent($supplierId, $environment, $limit);
    }

    /**
     * Dotaz na výsledek zpracování. Dokud VREP odpovídá potvrzením, zpracování
     * běží a pokus zůstává otevřený — vydávat to za výsledek by znamenalo
     * uzavřít podání, o kterém nic nevíme.
     */
    public function poll(
        int $supplierId,
        string $environment,
        int $attemptId,
        string $variableSymbol,
        int $packageCount = 1,
        string $submissionClass = self::SUBMISSION_CLASS,
    ): JmhzDispatchOutcome {
        $attempt = $this->requireAttempt($supplierId, $environment, $attemptId);
        $correlation = (string) ($attempt['correlation_reference'] ?? '');
        if ($correlation === '') {
            throw new JmhzTransportException(
                'jmhz_dispatch_correlation_missing',
                'Pokus nemá přidělený CorrelationID, takže se na jeho výsledek'
                    . ' nelze zeptat.',
            );
        }

        // Každý dotaz se zapíše, ať dopadne jakkoli. Kdyby se počítaly jen ty
        // úspěšné, mlčící protistrana by automatiku nechala běžet donekonečna
        // a strop pokusů by nikdy nesepnul.
        try {
            $response = $this->pollOnce(
                $environment,
                $correlation,
                $variableSymbol,
                false,
                $submissionClass,
            );
            $acknowledgement = $this->acknowledgements->parse(
                $response->body,
                $submissionClass,
            );
        } catch (\Throwable $exception) {
            $this->recordPoll($attempt, null, $exception->getMessage());

            throw $exception;
        }
        if ($acknowledgement !== null) {
            // Zpracování běží dál. Brána sama říká, za jak dlouho se ozvat.
            return new JmhzDispatchOutcome(
                $this->recordPoll($attempt, $acknowledgement->pollIntervalSeconds, null),
                $acknowledgement,
            );
        }

        try {
            $report = $this->protocols->parse($response->body, $packageCount, $correlation);
            if (!hash_equals($submissionClass, $report->submissionClass)) {
                throw new JmhzTransportException(
                    'jmhz_protocol_class_mismatch',
                    'Protokol ČSSZ patří jinému druhu podání.',
                );
            }
        } catch (\Throwable $exception) {
            // Odpověď, která není ani potvrzením, ani čitelným protokolem,
            // NENÍ výsledek. Pokus zůstává otevřený a důvod je v ledgeru —
            // vydávat nesrozumitelnou odpověď za vyřízené podání je přesně ta
            // záměna, po které uživatel přestane sledovat výsledek.
            $this->recordPoll($attempt, null, $exception->getMessage());

            throw $exception;
        }
        if ($report->status === JmhzSubmissionStatus::Processing) {
            return new JmhzDispatchOutcome(
                $this->recordPoll($attempt, null, null),
                null,
                $report,
            );
        }

        $attempt = $this->recordPoll($attempt, null, null);
        $attempt = $this->attempts->markCompleted(
            (int) $attempt['id'],
            (int) $attempt['row_version'],
        );
        // Teprve tady se podání hne ze stavu `submitted`. Dřív se protokol jen
        // přečetl a zahodil, takže odeslané hlášení zůstalo navždy „odesláno"
        // a uživatel se výsledek zpracování z aplikace nedozvěděl.
        $this->applyReceipt($attempt, $response->body, $correlation, $report, $packageCount);

        return new JmhzDispatchOutcome($attempt, null, $report);
    }

    /**
     * Dotažený protokol se stane DOKLADEM podání: uloží se jako příloha a —
     * pokud prošel ověřením podpisu — posune stav podání na výsledek, který
     * ČSSZ vrátila.
     *
     * Fail-closed ve dvou krocích. Napřed se import zkusí S ověřením podpisu;
     * když ověření neprojde, transakce se zahodí a protokol se uloží ZNOVU,
     * bez verifieru — tedy jako `unverified` příloha, která stav podání nechá
     * být a povinnost překlopí do `manual_review`. Neověřený protokol není
     * chyba běhu, je to jen doklad bez důkazní síly; zahodit ho by znamenalo
     * ztratit jediné, co o výsledku máme.
     *
     * Selhání celého importu NESMÍ přebít výsledek dotazu — ten už je
     * v ledgeru a ledger je závaznější než odvozený stav.
     *
     * ⚠️ Do ledgeru se odsud nezapisuje. Pokus je po `markCompleted()`
     * uzavřený a jeho strážný trigger přijme už jen uzavírací záznam.
     *
     * @param array<string,mixed> $attempt
     */
    private function applyReceipt(
        array $attempt,
        string $body,
        string $correlation,
        JmhzProtocolReport $report,
        int $packageCount,
    ): void {
        $submissions = $this->submissions;
        if ($submissions === null) {
            return;
        }
        $supplierId = (int) $attempt['supplier_id'];
        $submissionId = (int) $attempt['submission_id'];
        $idempotencyKey = 'jmhz-protocol:' . (int) $attempt['id']
            . ':' . hash('sha256', $body);
        $declared = $report->status->payrollRemoteStatus();
        $verifier = new JmhzReceiptVerifier(
            $this->signatures ?? new JmhzProtocolSignatureVerifier(),
            $this->protocols,
            [],
            $packageCount,
        );

        try {
            $this->import(
                $submissions,
                $supplierId,
                $submissionId,
                $body,
                $correlation,
                $declared,
                $idempotencyKey,
                $report->submissionClass,
                $verifier,
            );

            return;
        } catch (\Throwable $exception) {
            $reason = $exception instanceof JmhzTransportException
                ? $exception->errorCode
                : 'jmhz_protocol_untrusted';
        }

        try {
            $this->import(
                $submissions,
                $supplierId,
                $submissionId,
                $body,
                $correlation,
                $declared,
                $idempotencyKey,
                $report->submissionClass,
                null,
            );
            // Bez pojmenovaného důvodu by v podání zůstalo jen obecné
            // `receipt_unverified` a nikdo by nezjistil, PROČ se protokol
            // neověřil — jestli chybí podpis, nesedí otisk, nebo podepsal
            // někdo jiný než ČSSZ.
            $submissions->recordIssue(
                $supplierId,
                $submissionId,
                (int) $submissions->get($supplierId, $submissionId)['row_version'],
                null,
                'error',
                'remote',
                $reason,
            );
        } catch (\Throwable) {
            return;
        }
    }

    /** @return array<string,mixed> */
    private function import(
        PayrollSubmissionService $submissions,
        int $supplierId,
        int $submissionId,
        string $body,
        string $correlation,
        string $declaredRemoteStatus,
        string $idempotencyKey,
        string $submissionClass,
        ?JmhzReceiptVerifier $verifier,
    ): array {
        $submission = $submissions->get($supplierId, $submissionId);

        return $submissions->importReceipt(
            $supplierId,
            $submissionId,
            (int) $submission['row_version'],
            null,
            $body,
            $correlation,
            $correlation,
            $submissionClass,
            $declaredRemoteStatus,
            self::CHANNEL,
            $idempotencyKey,
            null,
            $verifier,
        );
    }

    /**
     * Zápis jednoho dotazu do ledgeru. Selhání zápisu nesmí přebít výsledek
     * dotazu ani původní chybu — ledger se tím zkrátí, ale nic se neztratí.
     *
     * @param array<string,mixed> $attempt
     * @return array<string,mixed>
     */
    private function recordPoll(
        array $attempt,
        ?int $gatewayIntervalSeconds,
        ?string $error,
    ): array {
        try {
            $updated = $this->attempts->recordPoll(
                (int) $attempt['id'],
                JmhzPollSchedule::nextRetryAt(
                    $this->now(),
                    (int) ($attempt['poll_count'] ?? 0),
                    $gatewayIntervalSeconds,
                ),
                $error,
                (int) $attempt['row_version'],
            );
        } catch (\Throwable) {
            return $attempt;
        }

        // Ledger je nadstavba nad výsledkem dotazu, ne jeho podmínka: kdyby
        // zápis vrátil něco, co není řádkem pokusu, pokračuje se s tím, co
        // o pokusu víme — jinak by se ztratil samotný výsledek.
        return isset($updated['id'], $updated['row_version']) ? $updated : $attempt;
    }

    /**
     * Uzavření transakce u VREP. Volá se až po dotažení protokolu — uzavřít ji
     * dřív znamená přijít o výsledek, který se pak už nedá vyzvednout.
     *
     * Je to idempotentní: druhé volání nad už uzavřenou transakcí neposílá nic
     * a vrací `already_closed`. Automatika i tlačítko běží po téže cestě, takže
     * kdyby se to nedrželo, uzavřelo by se dvakrát — a druhé uzavření by ČSSZ
     * odmítla jako dotaz na neexistující transakci.
     *
     * @return array{closed:bool,already_closed:bool,attempt:array<string,mixed>}
     */
    public function close(
        int $supplierId,
        string $environment,
        int $attemptId,
        string $variableSymbol,
        string $submissionClass = self::SUBMISSION_CLASS,
    ): array {
        $attempt = $this->requireAttempt($supplierId, $environment, $attemptId);
        if (($attempt['closed_at'] ?? null) !== null) {
            return ['closed' => true, 'already_closed' => true, 'attempt' => $attempt];
        }
        $correlation = (string) ($attempt['correlation_reference'] ?? '');
        if ($correlation === '' || ($attempt['status'] ?? null) !== 'completed') {
            throw new JmhzTransportException(
                'jmhz_dispatch_close_premature',
                'Transakci lze uzavřít až po dotažení protokolu. Nejdřív se'
                    . ' zeptejte ČSSZ na výsledek zpracování.',
            );
        }

        try {
            $this->pollOnce(
                $environment,
                $correlation,
                $variableSymbol,
                true,
                $submissionClass,
            );
        } catch (\Throwable $exception) {
            $this->recordCloseFailure($attempt, $exception->getMessage());

            throw $exception;
        }

        return [
            'closed' => true,
            'already_closed' => false,
            'attempt' => $this->attempts->markClosed(
                (int) $attempt['id'],
                (int) $attempt['row_version'],
            ),
        ];
    }

    /** @param array<string,mixed> $attempt */
    private function recordCloseFailure(array $attempt, string $message): void
    {
        try {
            $this->attempts->recordCloseFailure(
                (int) $attempt['id'],
                $message,
                JmhzPollSchedule::nextCloseAt(
                    $this->now(),
                    (int) ($attempt['close_attempts'] ?? 0),
                ),
                (int) $attempt['row_version'],
            );
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * ⚠️ DVOJE HODINY. Termíny (`next_retry_at`) se počítají TADY, tedy z času
     * PHP, kdežto `sent_at`, `completed_at` i `closed_at` plní databáze přes
     * `UTC_TIMESTAMP()`. Na jednom stroji je to totéž; s odděleným DB serverem
     * s rozjetými hodinami se rozvrh posune o ten rozdíl — dotazy budou chodit
     * dřív nebo později, než mají, a strop stáří (počítaný ze `sent_at` z DB
     * proti `now()` z PHP) může sepnout předčasně, případně vůbec.
     *
     * Nechává se tak vědomě: injektovat `ClockInterface` by znamenalo změnit
     * konstruktor, který staví testovací dvojníci pozičně. Kdyby se rozvrh
     * začal chovat nevysvětlitelně, hledej PRVNÍ tady — porovnej
     * `SELECT UTC_TIMESTAMP()` s `gmdate('Y-m-d H:i:s')` na aplikačním stroji.
     */
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function pollOnce(
        string $environment,
        string $correlation,
        string $variableSymbol,
        bool $close,
        string $submissionClass = self::SUBMISSION_CLASS,
    ): JmhzVrepPollResult {
        $request = (new JmhzGovTalkEnvelope(JmhzGovTalkRequestShape::documented()))
            ->pollRequest($correlation, $variableSymbol, $submissionClass, $close);

        return $this->client($environment)->poll($correlation, $request);
    }

    private function signer(int $supplierId, string $environment): JmhzVaultEnvelopeSigner
    {
        $profile = $this->requireProfile($supplierId, $environment);

        return new JmhzVaultEnvelopeSigner(
            $this->vault,
            $this->secrets,
            (int) $profile['credential_id'],
            (int) $profile['owner_user_id'],
            $supplierId,
            $this->registeredSerial($profile),
        );
    }

    /** @return array<string,mixed> */
    private function requireProfile(int $supplierId, string $environment): array
    {
        $profile = $this->profiles->find($supplierId, $environment);
        if ($profile === null) {
            throw new JmhzTransportException(
                'jmhz_signing_profile_missing',
                'Pro tuhle firmu a prostředí není zvolený podpisový certifikát.',
                422,
            );
        }

        return $profile;
    }

    /** @param array<string,mixed> $profile */
    private function registeredSerial(array $profile): ?string
    {
        $serial = $profile['cssz_registered_serial'] ?? null;

        return is_string($serial) && $serial !== '' ? $serial : null;
    }

    /** @return array<string,mixed> */
    private function requireAttempt(int $supplierId, string $environment, int $attemptId): array
    {
        $attempt = $this->attempts->find($supplierId, $environment, $attemptId);
        if ($attempt === null) {
            throw new JmhzTransportException(
                'jmhz_dispatch_attempt_unknown',
                'Pokus o odeslání neexistuje.',
                404,
            );
        }

        return $attempt;
    }

    private function client(string $environment): JmhzVrepClient
    {
        return $this->client ?? new JmhzVrepClient(null, $environment);
    }

    /** @return array{0:string,1:string} */
    private function describeImmediateFailure(string $body): array
    {
        try {
            $report = $this->protocols->parse($body);
        } catch (JmhzTransportException) {
            return [
                'jmhz_dispatch_response_unknown',
                'VREP vrátilo odpověď, která není ani potvrzením převzetí,'
                    . ' ani protokolem o zpracování.',
            ];
        }
        $first = $report->errors[0] ?? null;

        return [
            'jmhz_dispatch_rejected',
            $first instanceof JmhzProtocolError
                ? $first->message
                : 'ČSSZ podání odmítla už při převzetí.',
        ];
    }
}

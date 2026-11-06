<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Repository\Payroll\PayrollSigningProfileRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionConflictException;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\PayrollDispatchGate;
use MyInvoice\Service\Payroll\Submission\PayrollReceiptVerifierInterface;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\Vrep\CsszFormReceiptRecorder;
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
readonly class JmhzDispatchService
{
    public const CHANNEL = 'vrep_apep';
    public const SUBMISSION_CLASS = 'CSSZ_JMHZ';

    /**
     * Agenda povinnosti → druh podání v hlavičce GovTalk.
     *
     * Kanálem `vrep_apep` chodí i OZUSPOJ a registrace, takže samotný kanál
     * nic nerozhoduje. Bez téhle mapy stačilo poslat ID podání jiné agendy
     * a odešlo se pod hlavičkou `Class=CSSZ_JMHZ` — ČSSZ dostane dokument,
     * který k deklarovanému druhu podání nepatří, a vzít se to zpět nedá.
     */
    private const AGENDA_SUBMISSION_CLASSES = [
        'JMHZ' => self::SUBMISSION_CLASS,
        'JMHZ25' => self::SUBMISSION_CLASS,
        'JMHZ27' => self::SUBMISSION_CLASS,
        'PREZEC26' => 'CSSZ_PREZEC',
        'REGZEC25' => 'CSSZ_REGZEC',
        'REGZEC27' => 'CSSZ_REGZEC',
        // CSSZSubmClasses.pdf: NEMPRI25 i HZUPN20 sdílí CSSZ_NEM_PRI, formulář
        // (eType) proto volající předává zvlášť ({@see JmhzGovTalkRequestShape::forForm()}).
        'NEMPRI' => 'CSSZ_NEM_PRI',
        'HZUPN' => 'CSSZ_NEM_PRI',
        'OZUSPOJ' => 'CSSZ_OZUSPOJ',
    ];

    /**
     * Stav podání, ze kterého se smí odesílat. Kontrola stavu až v
     * `markSubmitted()` přicházela PO odeslání, tedy k ničemu.
     */
    private const SENDABLE_STATUS = 'ready';

    /**
     * Výslovné odmítnutí podání už při převzetí. Jediná odpověď po odeslání,
     * po které u ČSSZ prokazatelně nic nezůstalo; všechno ostatní je
     * „možná doručeno".
     */
    private const IMMEDIATE_REJECTION_CODE = 'jmhz_dispatch_rejected';

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
        /**
         * Zápis výsledku ověřeného protokolu NEMPRI, HZUPN a OZUSPOJ do
         * případu dávky nebo záměru slevy. Bez něj se posune jen podání.
         */
        private ?CsszFormReceiptRecorder $formReceipts = null,
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
        ?string $form = null,
    ): JmhzDispatchOutcome {
        $packages =$this->frozen?->packageArtifacts($supplierId, $environment, $submissionId) ?? [];
        if ($packages !== []) {
            return $this->sendPackages(
                $supplierId,
                $environment,
                $submissionId,
                $payloadXml,
                $variableSymbol,
                $idempotencyKey,
                $actorUserId,
                $submissionClass,
                $packages,
            );
        }
        $this->assertSendable(
            $supplierId,
            $environment,
            $submissionId,
            $submissionClass,
            $idempotencyKey,
        );
        // Odesílá se VÝHRADNĚ zmrazený artefakt. Dřív se bral payload tak, jak
        // dorazil, takže na VREP šlo cokoli — bez XSD, bez katalogu kontrol —
        // a do ledgeru se zapsal otisk TOHO, co přišlo. Archiv pak tvrdil
        // „odesláno bylo zmrazené XML", i když odesláno bylo něco jiného.
        //
        // Předaný payload zůstává jen jako KONTROLNÍ OTISK: registrační
        // adaptér si zmrazené bajty načítá sám a předává je dál, takže se
        // musí shodovat. Cokoli jiného je záměna dokumentu a odeslání padá.
        if ($this->frozen !== null) {
            $frozenXml = $this->frozen->bytes($supplierId, $environment, $submissionId);
            if ($payloadXml !== null
                && trim($payloadXml) !== ''
                && !hash_equals(hash('sha256', $frozenXml), hash('sha256', $payloadXml))
            ) {
                throw new JmhzTransportException(
                    'jmhz_dispatch_payload_not_frozen',
                    'Předaná datová věta neodpovídá zmrazenému artefaktu podání.',
                );
            }
            $payloadXml = $frozenXml;
        } elseif ($payloadXml === null || trim($payloadXml) === '') {
            // Bez navázané platformy (jen testovací dvojníci; v produkci je
            // `frozen` v Bootstrap svázaný) není odkud zmrazené XML vzít.
            throw new JmhzTransportException(
                'jmhz_dispatch_payload_missing',
                'Chybí datová věta zmrazeného podání.',
            );
        }
        $this->assertNoOpenAttempt($supplierId, $environment, $submissionId, $idempotencyKey);

        return $this->sendDocument(
            $supplierId,
            $environment,
            $submissionId,
            $payloadXml,
            $variableSymbol,
            $idempotencyKey,
            $actorUserId,
            $submissionClass,
            $form,
        );
    }

    /**
     * Tvar obálky pro druh podání. Formulář je povinný u třídy, kterou sdílí
     * víc formulářů (`CSSZ_NEM_PRI`), a když přijde, musí k třídě patřit.
     */
    private static function requestShape(
        string $submissionClass,
        ?string $form,
        ?string $payloadXml = null,
    ): JmhzGovTalkRequestShape {
        if ($form === null) {
            // Odesílá se zmrazená věta: ročník `eType` (JMHZ25/JMHZ27,
            // REGZEC25/REGZEC27) určuje její jmenný prostor, ne dnešní datum.
            // Dotaz na stav `eType` do obálky nepíše, tam stačí třída.
            return $payloadXml === null
                ? JmhzGovTalkRequestShape::forSubmissionClass($submissionClass)
                : JmhzGovTalkRequestShape::forPayload($submissionClass, $payloadXml);
        }
        if (JmhzGovTalkRequestShape::classForForm($form) !== $submissionClass) {
            throw new JmhzTransportException(
                'jmhz_govtalk_envelope_type_mismatch',
                'Prohlášený `eType` ČSSZ obálky patří jiné agendě než druh podání.',
            );
        }

        return JmhzGovTalkRequestShape::forForm($form);
    }

    /**
     * Odeslání hlášení rozděleného do dílčích balíků.
     *
     * Každý balík je u ČSSZ samostatné dílčí podání se stejným GUID podání:
     * vlastní obálka, vlastní CorrelationID, vlastní protokol. Odesílají se
     * postupně od prvního (ČSSZ bez přijatého prvního balíku ostatní
     * nezpracuje); balík, který už odešel, se přeskočí, takže opakované
     * kliknutí po výpadku uprostřed dopošle jen zbytek. Balík „možná
     * doručen" zastaví všechno, stejně jako u nerozděleného podání.
     *
     * @param list<array{ordinal:int,part_id:int,artifact_id:int,artifact_sha256:string}> $packages
     */
    private function sendPackages(
        int $supplierId,
        string $environment,
        int $submissionId,
        ?string $payloadXml,
        string $variableSymbol,
        string $idempotencyKey,
        ?int $actorUserId,
        string $submissionClass,
        array $packages,
    ): JmhzDispatchOutcome {
        if ($payloadXml !== null && trim($payloadXml) !== '') {
            throw new JmhzTransportException(
                'jmhz_dispatch_payload_not_frozen',
                'Rozdělené hlášení se odesílá jen ze zmrazených dílčích balíků.',
            );
        }
        $this->assertSendable(
            $supplierId,
            $environment,
            $submissionId,
            $submissionClass,
            $idempotencyKey,
            true,
        );
        $frozen = $this->frozen ?? throw new \LogicException('Zmrazené balíky nejsou k dispozici.');
        $count = count($packages);
        $previous = $this->attempts->listForSubmission($supplierId, $environment, $submissionId);
        $outcome = null;
        $replayed = null;
        foreach ($packages as $package) {
            $key = self::packageIdempotencyKey($idempotencyKey, $package['ordinal']);
            $sent = false;
            foreach ($previous as $attempt) {
                if (!hash_equals($package['artifact_sha256'], (string) ($attempt['request_sha256'] ?? ''))) {
                    continue;
                }
                $replay = $this->attempts->findByIdempotencyKey($key);
                if ($replay !== null && (int) $replay['id'] === (int) $attempt['id']) {
                    $replayed = $replay;
                    $sent = true;
                    continue;
                }
                $reason = PayrollDispatchGate::possiblyDeliveredReason($attempt);
                if ($reason !== null) {
                    throw new \DomainException(sprintf('Dílčí balík %d z %d: %s', $package['ordinal'], $count, $reason));
                }
                if (!PayrollDispatchGate::attemptAllowsRetry($attempt)) {
                    $sent = true;
                }
            }
            if ($sent) {
                continue;
            }
            $outcome = $this->sendDocument(
                $supplierId,
                $environment,
                $submissionId,
                $frozen->packageBytes($supplierId, $package['artifact_id']),
                $variableSymbol,
                $key,
                $actorUserId,
                $submissionClass,
            );
        }
        if ($outcome !== null) {
            return $outcome;
        }
        if ($replayed !== null) {
            return new JmhzDispatchOutcome($replayed);
        }

        throw new \DomainException(
            'Všechny dílčí balíky hlášení už byly odeslány; pokračujte dotazem na výsledek.',
        );
    }

    public static function packageIdempotencyKey(string $idempotencyKey, int $ordinal): string
    {
        return $ordinal === 1 ? $idempotencyKey : "{$idempotencyKey}:package:{$ordinal}";
    }

    private function sendDocument(
        int $supplierId,
        string $environment,
        int $submissionId,
        string $payloadXml,
        string $variableSymbol,
        string $idempotencyKey,
        ?int $actorUserId,
        string $submissionClass,
        ?string $form = null,
    ): JmhzDispatchOutcome {
        // Tvar obálky se odvozuje z druhu podání, ne z JMHZ: `eType` vnořené
        // ČSSZ obálky je pro REGZEC a PREZEC jiné a `documented()` by na ně
        // nalepilo `JMHZ25`. U CSSZ_NEM_PRI rozhoduje formulář.
        $envelope = new JmhzGovTalkEnvelope(self::requestShape($submissionClass, $form, $payloadXml));
        $signer = $this->signer($supplierId, $environment);
        $material = $signer->unlock();
        $sealed = $envelope->seal(
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
            // Vypršený čas čtení, spadlé spojení nebo 5xx: požadavek odešel
            // a ČSSZ ho mohla převzít. Zapsat to jako `failed` bez `sent_at`
            // by znamenalo „nic neodešlo" a brána by pustila opakování.
            if ($exception->possiblyDelivered) {
                $this->recordPossibleDelivery(
                    $attempt,
                    $exception->errorCode,
                    $exception->getMessage(),
                    $exception->remoteHttpStatus,
                    null,
                );
            } else {
                $this->recordFailure(
                    $attempt,
                    $exception->errorCode,
                    $exception->getMessage(),
                    $exception->remoteHttpStatus,
                );
            }

            throw $exception;
        }

        // Od tohoto místa je zpráva U ČSSZ. Cokoli, co selže dál, musí zůstat
        // v ledgeru jako „možná doručeno", ne jako nezahájený ani jako
        // neúspěšný pokus: nečitelná odpověď, potvrzení bez CorrelationID
        // i ztracený optimistický zámek by jinak pustily opakování a ČSSZ by
        // druhé podání odmítla jako duplicitu (20022). Jedinou výjimkou je
        // výslovné odmítnutí na vstupu, po kterém u ČSSZ nic nezůstalo.
        $acknowledgement = null;
        try {
            $acknowledgement = $this->acknowledgements->parse(
                $response->body,
                $submissionClass,
            );
            if ($acknowledgement === null) {
                // Odpověď na podání, která není potvrzením, je buď rovnou
                // protokol o zamítnutí, nebo něco neznámého.
                $failure = $this->describeImmediateFailure($response->body);

                throw new JmhzTransportException(
                    $failure[0],
                    $failure[1],
                    $response->httpStatus,
                    $failure[0] !== self::IMMEDIATE_REJECTION_CODE,
                );
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
            $code = $exception instanceof JmhzTransportException
                ? $exception->errorCode
                : 'jmhz_dispatch_send_unresolved';
            if ($code === self::IMMEDIATE_REJECTION_CODE) {
                $this->recordFailure($attempt, $code, $exception->getMessage(), $response->httpStatus);
            } else {
                $this->recordPossibleDelivery(
                    $attempt,
                    $code,
                    $exception->getMessage(),
                    $response->httpStatus,
                    $acknowledgement?->correlationId,
                );
            }

            throw $exception;
        }

        // Podání teď leží u ČSSZ, a platforma to musí vědět: dokud zůstane
        // `ready`, hlásí ho inbox jako nepodané a nedá se na něj navázat storno
        // ani oprava. Selhání téhle změny NESMÍ přebít úspěšné odeslání —
        // důkaz o něm je v ledgeru a ten je závaznější.
        $this->markSubmitted($supplierId, $submissionId, $acknowledgement->correlationId);

        return new JmhzDispatchOutcome($attempt, $acknowledgement);
    }

    /**
     * Tvrdá brána PŘED odesláním: agenda, kanál, prostředí a stav.
     *
     * Kanálem `vrep_apep` chodí i OZUSPOJ a registrace, takže bez kontroly
     * agendy stačilo poslat ID podání jiné agendy a odešlo se pod hlavičkou
     * deklarující JMHZ. Kontrola stavu byla až v `markSubmitted()`, tedy až
     * po odeslání — v okamžiku, kdy se s tím už nedalo nic dělat.
     *
     * Opakované volání s TÝMŽ idempotenčním klíčem musí projít i u podání,
     * které už je `submitted`: to není nové odeslání, ale vrácení původního
     * pokusu. Vlastní přehrání pak atomicky řeší `attempts->open()`.
     */
    private function assertSendable(
        int $supplierId,
        string $environment,
        int $submissionId,
        string $submissionClass,
        string $idempotencyKey,
        bool $packagesInFlight = false,
    ): void {
        if ($this->submissions === null) {
            return;
        }
        if (!in_array($submissionClass, self::AGENDA_SUBMISSION_CLASSES, true)) {
            throw new JmhzTransportException(
                'jmhz_dispatch_class_unsupported',
                'Transport nepodporuje tenhle druh podání ČSSZ.',
            );
        }
        $submission = $this->submissions->get($supplierId, $submissionId);
        if ($submission['environment'] !== $environment) {
            throw new \DomainException('Podání patří jinému prostředí.');
        }
        if ($submission['channel'] !== self::CHANNEL) {
            throw new \DomainException(
                'Podání není vedené na kanálu VREP/APEP, odeslat touhle cestou nejde.',
            );
        }
        $obligation = $this->submissions->obligationOf(
            $supplierId,
            $environment,
            $submissionId,
        );
        $expectedClass = $obligation === null
            ? null
            : (self::AGENDA_SUBMISSION_CLASSES[$obligation['agenda_code']] ?? null);
        if ($expectedClass === null || $expectedClass !== $submissionClass) {
            throw new \DomainException(
                'Agenda podání neodpovídá druhu podání, pod kterým se má odeslat.',
            );
        }
        if ($submission['status'] === self::SENDABLE_STATUS) {
            return;
        }
        // Rozdělené hlášení je po odeslání prvního balíku `submitted`
        // (případně `processing` po jeho protokolu) a zbylé balíky se musí
        // dát doposlat. Který balík už odešel, rozhoduje ledger pokusů.
        if ($packagesInFlight && in_array($submission['status'], ['submitted', 'processing'], true)) {
            return;
        }
        $existing = $this->attempts->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null
            && (int) ($existing['supplier_id'] ?? 0) === $supplierId
            && (string) ($existing['environment'] ?? '') === $environment
            && (int) ($existing['submission_id'] ?? 0) === $submissionId
            && (string) ($existing['channel'] ?? '') === self::CHANNEL
        ) {
            return;
        }

        throw new \DomainException(
            'Podání není ve stavu připraveno k odeslání; pokračujte dotazem na'
                . ' výsledek původního pokusu.',
        );
    }

    /**
     * Evidence úspěšného odeslání v platformě podání.
     *
     * Dřív se tu polykalo úplně všechno, takže po ÚSPĚŠNÉM odeslání mohlo
     * podání zůstat ve stavu `ready` — a to je nejhorší možný výsledek:
     * obsluha vidí nepodané hlášení, odešle znovu a ČSSZ druhé podání odmítne
     * jako duplicitu. Selhání evidence proto MUSÍ být dohledatelné.
     *
     * Zvolená cesta je zápis do provozních nálezů podání (`recordIssue`), ne
     * nový stav: stavový automat podání je sdílený všemi agendami a přidat do
     * něj stav „odesláno, evidence selhala" by znamenalo měnit ENUM i všechny
     * přechody kvůli situaci, která se řeší ručně. Nález nese kód
     * `jmhz_dispatch_submitted_not_recorded` a je vidět v inboxu; důkaz o
     * odeslání je vedle toho v ledgeru pokusů, který je závaznější.
     *
     * Výjimku ven nepouštíme ani teď: odeslání PROBĚHLO a shodit ho chybou
     * evidence by uživateli řeklo „nepodáno", což je přesně naopak.
     */
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
            if ($submission['status'] !== self::SENDABLE_STATUS) {
                return;
            }
            $this->submissions->transition(
                $supplierId,
                $submissionId,
                $submission['row_version'],
                'submitted',
                $correlationReference,
            );
        } catch (\Throwable $exception) {
            $this->recordUnrecordedSubmission(
                $supplierId,
                $submissionId,
                $correlationReference,
                $exception,
            );
        }
    }

    private function recordUnrecordedSubmission(
        int $supplierId,
        int $submissionId,
        string $correlationReference,
        \Throwable $cause,
    ): void {
        $submissions = $this->submissions;
        if ($submissions === null) {
            return;
        }
        try {
            $submissions->recordIssue(
                $supplierId,
                $submissionId,
                (int) $submissions->get($supplierId, $submissionId)['row_version'],
                null,
                'error',
                'remote',
                'jmhz_dispatch_submitted_not_recorded',
                'payroll_submission',
                (string) $submissionId,
                [
                    'correlation_reference' => $correlationReference,
                    'message' => $cause->getMessage(),
                ],
            );
        } catch (\Throwable) {
            // Poslední záchytná síť. Ledger pokusů zápis o odeslání i tak má
            // a `PayrollSubmissionTransportAttemptRepository::listReadyJmhzSubmissions()`
            // ukáže podání, které je `ready` a přitom má odeslaný pokus.
            return;
        }
    }

    private function recordOriginalAtCssz(int $supplierId, int $submissionId, string $correlation): void
    {
        $submissions = $this->submissions;
        if ($submissions === null) {
            return;
        }
        try {
            $submissions->recordIssue(
                $supplierId,
                $submissionId,
                (int) $submissions->get($supplierId, $submissionId)['row_version'],
                null,
                'warning',
                'remote',
                PayrollDispatchGate::ORIGINAL_AT_CSSZ_ERROR_CODE,
                'payroll_submission',
                (string) $submissionId,
                [
                    'correlation_reference' => $correlation,
                    'message' => 'ČSSZ hlásí, že shodné podání už existuje (kontrola 22):'
                        . ' originál je u ČSSZ. Doložte nebo načtěte protokol'
                        . ' originálu; znovu neodesílejte.',
                ],
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
     * Zápis „možná doručeno". Stejně jako u neúspěchu nesmí selhání zápisu
     * přebít původní chybu.
     *
     * @param array<string,mixed> $attempt
     */
    private function recordPossibleDelivery(
        array $attempt,
        string $errorCode,
        string $message,
        ?int $httpStatus,
        ?string $correlationReference,
    ): void {
        try {
            $this->attempts->markPossiblyDelivered(
                (int) $attempt['id'],
                $errorCode,
                $message,
                $httpStatus,
                $correlationReference,
                (int) $attempt['row_version'],
            );
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * Druhé odeslání téhož podání pustí jen ledger. `ready` samo nestačí:
     * pokus „možná doručeno" nechává podání ve stavu `ready` (potvrzení
     * převzetí nedorazilo), a dokud nerozhodla účetní, další odeslání by
     * u ČSSZ mohlo založit duplicitu. Opakování téhož idempotenčního klíče
     * není nové odeslání a vrací původní pokus.
     */
    private function assertNoOpenAttempt(
        int $supplierId,
        string $environment,
        int $submissionId,
        string $idempotencyKey,
    ): void {
        $replayed = $this->attempts->findByIdempotencyKey($idempotencyKey);
        foreach ($this->attempts->listForSubmission($supplierId, $environment, $submissionId) as $previous) {
            if ($replayed !== null && (int) $replayed['id'] === (int) $previous['id']) {
                continue;
            }
            $reason = PayrollDispatchGate::possiblyDeliveredReason($previous);
            if ($reason !== null) {
                throw new \DomainException($reason);
            }
            if (!PayrollDispatchGate::attemptAllowsRetry($previous)) {
                throw new \DomainException(sprintf(
                    'Podání už bylo odesláno (pokus č. %d, stav „%s"). Znovu se'
                        . ' neodesílá, u ČSSZ by vzniklo jako duplicita.',
                    (int) ($previous['attempt_no'] ?? 0),
                    (string) ($previous['status'] ?? ''),
                ));
            }
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
        ?string $form = null,
    ): JmhzDispatchOutcome {
        $attempt = $this->requireAttempt($supplierId, $environment, $attemptId);
        // Uzavřený pokus výsledek už má (nebo ho automatika vzdala) a ledger ho
        // znovu neotevře. Dotaz na ČSSZ by jen spálil volání a skončil chybou
        // zápisu, proto se odmítne hned a srozumitelně.
        if (in_array((string) ($attempt['status'] ?? ''), ['completed', 'expired'], true)) {
            throw new JmhzTransportException(
                'jmhz_dispatch_attempt_closed',
                'Pokus č. ' . (int) ($attempt['attempt_no'] ?? 0) . ' je už uzavřený'
                    . (($attempt['status'] ?? '') === 'completed' ? ' s dotaženým protokolem' : '')
                    . ', stav se u něj znovu nezjišťuje.',
            );
        }
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
                $form,
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
            $report = $this->protocolReport(
                $response->body,
                $packageCount,
                $correlation,
                $submissionClass,
            );
        } catch (\Throwable $exception) {
            if ($exception instanceof JmhzTransportException
                && $exception->errorCode === JmhzProtocolParser::UNDOCUMENTED_SHAPE_CODE
                && self::isFinalAnswerOf($response->body, $submissionClass, $correlation)
            ) {
                return $this->settleUndocumentedProtocol(
                    $attempt,
                    $response->body,
                    $correlation,
                    $submissionClass,
                    $exception,
                );
            }
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

        $wasPossiblyDelivered = ($attempt['status'] ?? null) === PayrollDispatchGate::POSSIBLY_DELIVERED_STATUS;
        $attempt = $this->recordPoll($attempt, null, null);
        $attempt = $this->attempts->markCompleted(
            (int) $attempt['id'],
            (int) $attempt['row_version'],
        );
        if ($wasPossiblyDelivered) {
            // Potvrzení převzetí dorazilo, ale zápis odeslání spadl, takže
            // podání zůstalo `ready`. Protokol teď doložil, že je u ČSSZ.
            $this->markSubmitted((int) $attempt['supplier_id'], (int) $attempt['submission_id'], $correlation);
        }
        // Teprve tady se podání hne ze stavu `submitted`. Dřív se protokol jen
        // přečetl a zahodil, takže odeslané hlášení zůstalo navždy „odesláno"
        // a uživatel se výsledek zpracování z aplikace nedozvěděl.
        $this->applyReceipt($attempt, $response->body, $correlation, $report, $packageCount);
        if ($report->originalAlreadyAtCssz()) {
            // Odpověď na opakované odeslání: shodné podání ČSSZ už má. Podání
            // zůstává odeslané ({@see JmhzProtocolReport::payrollRemoteStatus()})
            // a nález říká účetní, že má doložit protokol originálu.
            $this->recordOriginalAtCssz((int) $attempt['supplier_id'], (int) $attempt['submission_id'], $correlation);
        }

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
        $declared = $report->payrollRemoteStatus();
        $verifier = $this->receiptVerifier($packageCount);
        /*
         * Protokol dílčího balíku patří jeho součásti podání a stav podání
         * se skládá ze všech balíků ({@see JmhzPackageReceiptVerifier}).
         */
        $partId = null;
        $packages = $this->frozen?->packageArtifacts($supplierId, (string) $attempt['environment'], $submissionId) ?? [];
        foreach ($packages as $package) {
            if (hash_equals($package['artifact_sha256'], (string) ($attempt['request_sha256'] ?? ''))) {
                $partId = $package['part_id'];
            }
        }
        if ($partId !== null && $this->submissions !== null) {
            $verifier = new JmhzPackageReceiptVerifier(
                $verifier,
                $this->packageReceiptStatuses(...),
                $supplierId,
                (string) $attempt['environment'],
                $submissionId,
                $partId,
                array_column($packages, 'ordinal', 'part_id'),
            );
        }

        try {
            $imported = $this->import(
                $submissions,
                $supplierId,
                $submissionId,
                $body,
                $correlation,
                $correlation,
                $declared,
                $idempotencyKey,
                $report->submissionClass,
                $verifier,
                partId: $partId,
            );
        } catch (\Throwable $exception) {
            $imported = null;
            $reason = $exception instanceof JmhzTransportException
                ? $exception->errorCode
                : 'jmhz_protocol_untrusted';
        }
        if ($imported !== null) {
            if (($imported['trusted'] ?? false) === true) {
                $this->recordFormReceipt($supplierId, (string) $attempt['environment'], $submissionId, $report);
            }

            return;
        }

        try {
            $this->import(
                $submissions,
                $supplierId,
                $submissionId,
                $body,
                $correlation,
                $correlation,
                $declared,
                $idempotencyKey,
                $report->submissionClass,
                null,
                partId: $partId,
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

    /**
     * Ověřený protokol k NEMPRI, HZUPN nebo OZUSPOJ se zapíše i do případu
     * dávky nebo záměru slevy ({@see CsszFormReceiptRecorder}). Podání už je
     * posunuté a protokol uložený; selhání zápisu výsledek dotazu nepřebije,
     * důvod nese nález u podání.
     */
    private function recordFormReceipt(
        int $supplierId,
        string $environment,
        int $submissionId,
        JmhzProtocolReport $report,
    ): void {
        if ($this->formReceipts === null) {
            return;
        }
        try {
            $this->formReceipts->record($supplierId, $environment, $submissionId, $report);
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * Výsledek každého už dotaženého balíku, přečtený z originálu jeho
     * ověřeného protokolu. Stav uložený u protokolu je složený stav podání
     * v okamžiku importu, ne výsledek balíku, takže se z něj skládat nesmí.
     * Originál se při čtení ověřuje proti otisku v archivu.
     *
     * @return array<int,string> id součásti → výsledek balíku
     */
    private function packageReceiptStatuses(int $supplierId, string $environment, int $submissionId): array
    {
        $submissions = $this->submissions ?? throw new \LogicException('Platforma podání není k dispozici.');
        $statuses = [];
        foreach ($submissions->packageReceiptArtifacts($supplierId, $environment, $submissionId) as $partId => $artifactId) {
            $statuses[$partId] = $this->protocols
                ->parse($submissions->artifactBytes($supplierId, $artifactId))
                ->payrollRemoteStatus();
        }

        return $statuses;
    }

    /**
     * Znovu ověří protokol, který se uložil jako neověřený.
     *
     * Protokoly dotažené před opravou ověření podpisu (b55b96a2c) zůstaly
     * uložené jako `unverified` a podání viselo ve stavu `submitted`: pokus je
     * uzavřený a import je idempotentní, takže se sám znovu neověří nikdy.
     *
     * Ověřuje se PŘESNĚ týmž kódem jako čerstvě dotažený protokol — stejný
     * parser a kontrola `Class` ({@see protocolReport()}), stejný verifier
     * ({@see receiptVerifier()}: podpis, kotva, CMS, CorrelationID vůči podání)
     * a stejný `importReceipt()`, který teprve při úspěchu posune stav podání,
     * součástí a povinnosti. Jiná cesta k `remote_status` tudy nevede.
     *
     * Protokoly jsou neměnné, takže úspěch založí NOVÝ ověřený řádek nad
     * týmiž bajty; neověřený zůstává v historii. Opakování nic nemění:
     * protokol, vedle kterého už stojí ověřený se stejným otiskem, se hlásí
     * jako `already_verified`. Neúspěch nemění nic — celý import běží
     * v transakci a ověření padá dřív, než se cokoli zapíše.
     *
     * @return array{
     *   outcome:'verified'|'already_verified'|'failed',verified:bool,
     *   receipt_id:int,verified_receipt_id:?int,remote_status:?string,
     *   submission_id:int,submission_status:string,code:?string,message:?string
     * }
     */
    public function reverifyStoredProtocol(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $receiptId,
        ?int $actorUserId = null,
    ): array {
        $submissions = $this->submissions;
        if ($submissions === null) {
            throw new JmhzTransportException(
                'jmhz_protocol_reverify_unavailable',
                'Platforma podání není k dispozici, protokol nelze znovu ověřit.',
            );
        }
        $receipt = $submissions->storedReceipt(
            $supplierId,
            $environment,
            $submissionId,
            $receiptId,
        );
        if ($receipt === null) {
            throw new JmhzTransportException(
                'jmhz_protocol_reverify_not_found',
                'Protokol k tomuto podání nebyl nalezen.',
                404,
            );
        }
        $submission = $submissions->get($supplierId, $submissionId);
        $result = static fn (
            string $outcome,
            ?int $verifiedReceiptId,
            ?string $remoteStatus,
            string $submissionStatus,
            ?string $code = null,
            ?string $message = null,
        ): array => [
            'outcome' => $outcome,
            'verified' => $outcome !== 'failed',
            'receipt_id' => $receiptId,
            'verified_receipt_id' => $verifiedReceiptId,
            'remote_status' => $remoteStatus,
            'submission_id' => $submissionId,
            'submission_status' => $submissionStatus,
            'code' => $code,
            'message' => $message,
        ];
        $failed = static fn (string $code, string $message): array => $result(
            'failed',
            null,
            null,
            (string) $submission['status'],
            $code,
            $message,
        );

        if ($receipt['verification_status'] === 'trusted') {
            return $result(
                'already_verified',
                $receipt['id'],
                $receipt['remote_status'],
                (string) $submission['status'],
            );
        }
        if ($receipt['trusted_receipt_id'] !== null) {
            return $result(
                'already_verified',
                $receipt['trusted_receipt_id'],
                $receipt['trusted_remote_status'],
                (string) $submission['status'],
            );
        }
        if ($submission['channel'] !== self::CHANNEL
            || $receipt['artifact_channel'] !== self::CHANNEL
        ) {
            return $failed(
                'jmhz_protocol_reverify_channel_unsupported',
                'Znovu ověřit jde jen protokol dotažený z brány VREP.',
            );
        }
        $obligation = $submissions->obligationOf($supplierId, $environment, $submissionId);
        $submissionClass = $obligation === null
            ? null
            : (self::AGENDA_SUBMISSION_CLASSES[$obligation['agenda_code']] ?? null);
        if ($submissionClass === null
            || !hash_equals($submissionClass, $receipt['protocol_code'])
        ) {
            return $failed(
                'jmhz_protocol_class_mismatch',
                'Protokol ČSSZ patří jinému druhu podání.',
            );
        }
        $correlation = $submission['correlation_reference'];
        if ($correlation === null) {
            return $failed(
                'jmhz_protocol_correlation_unknown',
                'Podání nemá uložené CorrelationID, takže k němu nelze protokol'
                    . ' bezpečně přiřadit.',
            );
        }

        try {
            $bytes = $submissions->artifactBytes($supplierId, $receipt['artifact_id']);
            if (!hash_equals($receipt['summary_hash'], hash('sha256', $bytes))) {
                throw new JmhzTransportException(
                    'jmhz_protocol_reverify_artifact_mismatch',
                    'Uložený originál protokolu neodpovídá otisku protokolu.',
                );
            }
            $report = $this->protocolReport($bytes, 1, $correlation, $submissionClass);
            $imported = $this->import(
                $submissions,
                $supplierId,
                $submissionId,
                $bytes,
                self::reverifiedReceiptReference($receipt['receipt_reference'], $receiptId),
                $correlation,
                $report->payrollRemoteStatus(),
                'jmhz-protocol-reverify:' . $receiptId . ':' . $receipt['summary_hash'],
                $report->submissionClass,
                $this->receiptVerifier(1),
                $actorUserId,
            );
        } catch (JmhzTransportException $exception) {
            return $failed($exception->errorCode, $exception->getMessage());
        } catch (\LogicException | \UnexpectedValueException | PayrollSubmissionConflictException $exception) {
            return $failed('jmhz_protocol_untrusted', $exception->getMessage());
        }
        if ($imported['trusted'] !== true) {
            // Verifier je předaný vždy, takže sem se dostat nejde. Kdyby ano,
            // hlásit „ověřeno" by bylo přesně to, čemu tahle brána brání.
            throw new \LogicException('Znovu ověřený protokol se neuložil jako ověřený.');
        }
        if ($imported['created'] === true) {
            $this->recordFormReceipt($supplierId, $environment, $submissionId, $report);
        }
        $stored = $submissions->storedReceipt(
            $supplierId,
            $environment,
            $submissionId,
            (int) $imported['id'],
        );

        return $result(
            $imported['created'] ? 'verified' : 'already_verified',
            (int) $imported['id'],
            $stored['remote_status'] ?? null,
            (string) $imported['submission_status'],
        );
    }

    /**
     * Reference znovu ověřeného protokolu. Klíč `(firma, prostředí, kód
     * protokolu, reference)` je jedinečný, takže nový řádek nemůže převzít
     * referenci neověřeného předchůdce.
     */
    private static function reverifiedReceiptReference(string $original, int $receiptId): string
    {
        $reference = $original . ':reverified';

        return strlen($reference) <= 128 ? $reference : 'reverified:' . $receiptId;
    }

    /**
     * Protokol ČSSZ přečtený a navázaný na druh podání. Společné pro dotaz
     * na výsledek i pro znovu ověření uloženého protokolu.
     */
    private function protocolReport(
        string $body,
        int $packageCount,
        string $correlation,
        string $submissionClass,
    ): JmhzProtocolReport {
        $report = $this->protocols->parse($body, $packageCount, $correlation);
        if (!hash_equals($submissionClass, $report->submissionClass)) {
            throw new JmhzTransportException(
                'jmhz_protocol_class_mismatch',
                'Protokol ČSSZ patří jinému druhu podání.',
            );
        }

        return $report;
    }

    /** Jediný verifier protokolů VREP — pro dotažený i pro znovu ověřovaný. */
    private function receiptVerifier(int $packageCount): JmhzReceiptVerifier
    {
        return new JmhzReceiptVerifier(
            $this->signatures ?? new JmhzProtocolSignatureVerifier(),
            $this->protocols,
            [],
            $packageCount,
        );
    }

    /** @return array<string,mixed> */
    private function import(
        PayrollSubmissionService $submissions,
        int $supplierId,
        int $submissionId,
        string $body,
        string $receiptReference,
        string $correlation,
        string $declaredRemoteStatus,
        string $idempotencyKey,
        string $submissionClass,
        ?PayrollReceiptVerifierInterface $verifier,
        ?int $importedBy = null,
        ?int $partId = null,
    ): array {
        $submission = $submissions->get($supplierId, $submissionId);

        return $submissions->importReceipt(
            $supplierId,
            $submissionId,
            (int) $submission['row_version'],
            $partId,
            $body,
            $receiptReference,
            $correlation,
            $submissionClass,
            $declaredRemoteStatus,
            self::CHANNEL,
            $idempotencyKey,
            $importedBy,
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
        ?string $form = null,
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
                $form,
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
        ?string $form = null,
    ): JmhzVrepPollResult {
        $request = (new JmhzGovTalkEnvelope(
            self::requestShape($submissionClass, $form),
        ))->pollRequest($correlation, $variableSymbol, $submissionClass, $close);

        return $this->client($environment)->poll($correlation, $request);
    }

    /**
     * Je odpověď konečná odpověď VREP na NAŠI transakci (`response`/`error`,
     * naše třída, naše CorrelationID)? Jen takovou smí neznámý tvar protokolu
     * uzavřít k ručnímu vyřízení; cokoli jiného zůstává otevřené.
     */
    private static function isFinalAnswerOf(string $body, string $submissionClass, string $correlation): bool
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($body, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return false;
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('g', JmhzGovTalkEnvelope::NS_GOVTALK);
        $read = static fn (string $name): string => trim(
            (string) $xpath->evaluate("string(/g:GovTalkMessage/g:Header/g:MessageDetails/g:{$name})"),
        );

        return hash_equals($submissionClass, $read('Class'))
            && hash_equals($correlation, $read('CorrelationID'))
            && in_array($read('Qualifier'), ['response', 'error'], true);
    }

    /**
     * Konečná odpověď ČSSZ k NEMPRI, HZUPN nebo OZUSPOJ, která neodpovídá
     * doloženému tvaru protokolu. Nevykládá se, ale ani nezahazuje: pokus se uzavře jako
     * dotažený (transakci jde uzavřít, jak podací protokol žádá), odpověď se
     * uloží k podání jako neověřený protokol — povinnost tím přejde do ruční
     * kontroly — a podání dostane nález s pojmenovaným důvodem. Stav podání se
     * nemění: přijetí nebo odmítnutí zapíše účetní podle protokolu.
     *
     * @param array<string,mixed> $attempt
     */
    private function settleUndocumentedProtocol(
        array $attempt,
        string $body,
        string $correlation,
        string $submissionClass,
        JmhzTransportException $reason,
    ): JmhzDispatchOutcome {
        $wasPossiblyDelivered = ($attempt['status'] ?? null) === PayrollDispatchGate::POSSIBLY_DELIVERED_STATUS;
        $attempt = $this->recordPoll($attempt, null, null);
        $attempt = $this->attempts->markCompleted(
            (int) $attempt['id'],
            (int) $attempt['row_version'],
        );
        $supplierId = (int) $attempt['supplier_id'];
        $submissionId = (int) $attempt['submission_id'];
        if ($wasPossiblyDelivered) {
            $this->markSubmitted($supplierId, $submissionId, $correlation);
        }
        $submissions = $this->submissions;
        if ($submissions !== null) {
            try {
                $this->import(
                    $submissions,
                    $supplierId,
                    $submissionId,
                    $body,
                    $correlation,
                    $correlation,
                    'submitted',
                    'cssz-protocol-undocumented:' . (int) $attempt['id'] . ':' . hash('sha256', $body),
                    $submissionClass,
                    null,
                );
                $submissions->recordIssue(
                    $supplierId,
                    $submissionId,
                    (int) $submissions->get($supplierId, $submissionId)['row_version'],
                    null,
                    'warning',
                    'remote',
                    $reason->errorCode,
                    'payroll_submission',
                    (string) $submissionId,
                    [
                        'correlation_reference' => $correlation,
                        'message' => $reason->getMessage(),
                    ],
                );
            } catch (\Throwable) {
                // Pokus je dotažený a ledger to ví; uložení dokladu je
                // nadstavba, jejíž selhání nesmí výsledek dotazu přebít.
            }
        }

        return new JmhzDispatchOutcome($attempt, null, null, true);
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
        if ($report->originalAlreadyAtCssz()) {
            return [
                PayrollDispatchGate::ORIGINAL_AT_CSSZ_ERROR_CODE,
                'ČSSZ odpověděla, že shodné podání už existuje (kontrola 22):'
                    . ' originál je u ČSSZ. Znovu neodesílejte; doložte nebo'
                    . ' načtěte protokol originálu.',
            ];
        }
        $first = $report->errors[0] ?? null;

        return [
            self::IMMEDIATE_REJECTION_CODE,
            $first instanceof JmhzProtocolError
                ? $first->message
                : 'ČSSZ podání odmítla už při převzetí.',
        ];
    }
}

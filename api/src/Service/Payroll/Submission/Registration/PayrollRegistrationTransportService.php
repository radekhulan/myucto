<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;

/**
 * Překlad zmrazené PREZEC/REGZEC datové věty do existující cesty VREP/APEP.
 *
 * Adaptér nic nesestavuje znovu. Načte archivované bajty, ověří jejich agendu
 * a VS proti tenantově podání a předá je beze změny témuž transportu a ledgeru,
 * které používá JMHZ. Samotné volání je dostupné jen z explicitní HTTP akce;
 * žádný cron registrační podání sám neodesílá.
 */
final readonly class PayrollRegistrationTransportService
{
    private const DOCUMENTS = [
        'PREZEC26' => [
            'root' => 'PREZEC',
            'namespace' => 'http://schemas.cssz.cz/PREZEC/2026',
            'class' => 'CSSZ_PREZEC',
            'actions' => [9, 10],
        ],
        'REGZEC25' => [
            'root' => 'REGZEC',
            'namespace' => 'http://schemas.cssz.cz/REGZEC/2025',
            'class' => 'CSSZ_REGZEC',
            'actions' => [1, 2, 3, 4, 5, 6, 7, 8],
        ],
    ];

    public function __construct(
        private PayrollSubmissionRepository $submissions,
        private PayrollSubmissionTransportAttemptRepository $attempts,
        private JmhzFrozenPayloadReader $frozen,
        private JmhzDispatchService $dispatch,
    ) {}

    /** @return array<string,mixed> */
    public function send(
        int $supplierId,
        string $environment,
        int $submissionId,
        string $idempotencyKey,
        ?int $actorUserId,
    ): array {
        $context = $this->context($supplierId, $environment, $submissionId, []);
        $payload = $this->payload(
            $this->frozen->bytes($supplierId, $environment, $submissionId),
            $context['agenda_code'],
        );
        $existing = $this->attempts->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            $this->assertReplayScope(
                $existing,
                $supplierId,
                $environment,
                $submissionId,
                $payload['sha256'],
            );

            return $this->result(
                new JmhzDispatchOutcome($existing),
                $context['agenda_code'],
                $payload,
            );
        }
        if ($context['status'] !== 'ready') {
            // Výjimka zůstává: tohle je poslední brána před odesláním na ČSSZ.
            // Druhé odeslání téhož podání by u ČSSZ založilo duplicitní
            // přihlášku a vzít ji zpět už nejde.
            throw new \DomainException(
                'Registrační podání na ČSSZ už bylo odesláno, takže se '
                . 'podruhé neodesílá. Jak podání ČSSZ vyřídila, zjistíte '
                . 'v historii odeslání u téhož podání.',
            );
        }
        $outcome = $this->dispatch->send(
            $supplierId,
            $environment,
            $submissionId,
            $payload['bytes'],
            $payload['variable_symbol'],
            $idempotencyKey,
            $actorUserId,
            $payload['submission_class'],
        );

        return $this->result($outcome, $context['agenda_code'], $payload);
    }

    /** @return array{agenda_code:string,submission_class:string,attempt:?array<string,mixed>} */
    public function status(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): array {
        $context = $this->context($supplierId, $environment, $submissionId, []);
        $history = $this->attempts->listForSubmission(
            $supplierId,
            $environment,
            $submissionId,
        );
        $attempt = $history === [] ? null : $history[array_key_last($history)];

        return [
            'agenda_code' => $context['agenda_code'],
            'submission_class' => self::DOCUMENTS[$context['agenda_code']]['class'],
            'attempt' => $attempt,
        ];
    }

    public function poll(
        int $supplierId,
        string $environment,
        int $attemptId,
    ): JmhzDispatchOutcome {
        $attempt = $this->attempt($supplierId, $environment, $attemptId);
        if (($attempt['status'] ?? null) !== 'awaiting_protocol') {
            // Výjimka zůstává: dotaz na výsledek volá ČSSZ. U uzavřeného
            // pokusu není nač se ptát a zbytečné volání jen zatěžuje bránu.
            throw new JmhzTransportException(
                'registration_dispatch_poll_unavailable',
                'Odpověď ČSSZ se dá zjistit jen u odeslání, které na ni '
                . 'teprve čeká. Tohle odeslání už je uzavřené — jak dopadlo, '
                . 'najdete v historii odeslání.',
            );
        }
        $submissionId = (int) $attempt['submission_id'];
        $context = $this->context(
            $supplierId,
            $environment,
            $submissionId,
            ['submitted', 'processing', 'partially_accepted', 'accepted', 'rejected'],
        );
        $payload = $this->payload(
            $this->frozen->bytes($supplierId, $environment, $submissionId),
            $context['agenda_code'],
        );

        return $this->dispatch->poll(
            $supplierId,
            $environment,
            $attemptId,
            $payload['variable_symbol'],
            1,
            $payload['submission_class'],
        );
    }

    /** @return array{closed:bool,already_closed:bool,attempt:array<string,mixed>} */
    public function close(
        int $supplierId,
        string $environment,
        int $attemptId,
    ): array {
        $attempt = $this->attempt($supplierId, $environment, $attemptId);
        $submissionId = (int) $attempt['submission_id'];
        $context = $this->context(
            $supplierId,
            $environment,
            $submissionId,
            ['submitted', 'processing', 'partially_accepted', 'accepted', 'rejected'],
        );
        $payload = $this->payload(
            $this->frozen->bytes($supplierId, $environment, $submissionId),
            $context['agenda_code'],
        );

        return $this->dispatch->close(
            $supplierId,
            $environment,
            $attemptId,
            $payload['variable_symbol'],
            $payload['submission_class'],
        );
    }

    /**
     * @param list<string> $statuses
     * Prázdný seznam stavů znamená pouze kontrolu vlastnictví, prostředí a agendy.
     *
     * @return array{agenda_code:string,status:string}
     */
    private function context(
        int $supplierId,
        string $environment,
        int $submissionId,
        array $statuses,
    ): array {
        $submission = $this->submissions->findSubmission($supplierId, $submissionId);
        if ($submission === null) {
            // Výjimka zůstává: hranice mezi firmami. Podání cizí firmy se
            // nesmí odeslat ani zobrazit, takže není co sbírat do seznamu
            // nedostatků — chybí sám předmět operace.
            throw new \DomainException(
                'Registrační podání pod tímhle číslem u téhle firmy '
                . 'neexistuje. Otevřete kartu zaměstnance znovu a odeslání '
                . 'spusťte ze seznamu jejích podání.',
            );
        }
        if ($submission['environment'] !== $environment) {
            // Výjimka zůstává: testovací a ostré prostředí ČSSZ jsou dvě různé
            // identity. Odeslat testovací podání naostro je nevratná záměna.
            throw new \DomainException(
                'Registrační podání bylo připraveno pro jiné prostředí '
                . '(testovací × ostré), než ze kterého se teď odesílá. '
                . 'Přepněte prostředí zpět na to, ve kterém podání vzniklo.',
            );
        }
        if ($statuses !== [] && !in_array($submission['status'], $statuses, true)) {
            // Výjimka zůstává: dotaz na výsledek i uzavření pokusu mají smysl
            // až po odeslání. Dřív by se volala ČSSZ nad podáním, o kterém
            // ještě neví.
            throw new \DomainException(
                'Registrační podání zatím nebylo odesláno na ČSSZ, takže '
                . 'u něj nejde zjišťovat ani uzavírat odpověď. Nejdřív '
                . 'podání odešlete.',
            );
        }
        $obligation = $this->submissions->findObligationOfSubmission(
            $supplierId,
            $environment,
            $submissionId,
        );
        if ($obligation === null || $obligation['subject_type'] !== 'employment') {
            // Výjimka zůstává: poslední brána před odesláním. Poslat jiné
            // podání registrační cestou znamená doručit ČSSZ dokument do
            // špatné agendy.
            throw new \DomainException(
                'Tohle podání není přihláška ani odhláška zaměstnance '
                . 'u ČSSZ, takže se touhle cestou odeslat nedá. Odešlete '
                . 'podání z obrazovky Mzdy → Podání a hlášení, kde vzniklo.',
            );
        }
        $agenda = $obligation['agenda_code'];
        if (!isset(self::DOCUMENTS[$agenda])) {
            // Výjimka zůstává: viz výš — cesta umí jen dva formuláře ČSSZ
            // a cokoliv jiného by odešlo pod špatnou datovou větou.
            throw new \DomainException(
                'Touhle cestou lze odeslat jen přihlášky a odhlášky '
                . 'zaměstnanců u ČSSZ (formuláře PREZEC a REGZEC). Tohle '
                . 'podání patří do jiné agendy — odešlete je z obrazovky '
                . 'Mzdy → Podání a hlášení.',
            );
        }

        return [
            'agenda_code' => $agenda,
            'status' => (string) $submission['status'],
        ];
    }

    /** @param array<string,mixed> $attempt */
    private function assertReplayScope(
        array $attempt,
        int $supplierId,
        string $environment,
        int $submissionId,
        string $payloadSha256,
    ): void {
        if ((int) ($attempt['supplier_id'] ?? 0) !== $supplierId
            || (string) ($attempt['environment'] ?? '') !== $environment
            || (int) ($attempt['submission_id'] ?? 0) !== $submissionId
            || (string) ($attempt['channel'] ?? '') !== JmhzDispatchService::CHANNEL
            || !hash_equals(
                $payloadSha256,
                (string) ($attempt['request_sha256'] ?? ''),
            )
        ) {
            // Výjimka zůstává: klíč pro opakování je jediná pojistka proti
            // dvojímu odeslání. Kdyby prošel na cizí data, ČSSZ by dostala
            // jiný dokument, než na který se účetní dívá.
            throw new \DomainException(
                'Odeslání registračního podání se nedá zopakovat — pod '
                . 'stejným požadavkem je už evidované jiné podání nebo jiná '
                . 'data. Načtěte kartu zaměstnance znovu a odeslání spusťte '
                . 'od začátku.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function attempt(int $supplierId, string $environment, int $attemptId): array
    {
        $attempt = $this->attempts->find($supplierId, $environment, $attemptId);
        if ($attempt === null) {
            // Výjimka zůstává: chybí sama entita, se kterou se má pracovat,
            // a je to zároveň hranice mezi firmami.
            throw new JmhzTransportException(
                'registration_dispatch_attempt_unknown',
                'Odeslání registračního podání pod tímhle číslem u téhle '
                . 'firmy neexistuje. Otevřete historii odeslání znovu '
                . 'a vyberte konkrétní odeslání z ní.',
                404,
            );
        }

        return $attempt;
    }

    /**
     * Druh podání do závorky na konec věty. Kód akce sám o sobě účetní nic
     * neříká, takže se přeloží sdíleným slovníkem; nečitelnou hodnotu
     * pojmenujeme jako chybějící, ať se nepředstírá akce číslo nula.
     */
    private function actionReference(string $agendaCode, string $action): string
    {
        if (preg_match('/^[0-9]+$/D', $action) !== 1) {
            return ' (druh podání v datech chybí nebo je nečitelný)';
        }

        return ' (' . PayrollRegistrationFieldVocabulary::action(
            $agendaCode,
            (int) $action,
        ) . ')';
    }

    /**
     * @return array{bytes:string,sha256:string,variable_symbol:string,submission_class:string}
     */
    private function payload(string $bytes, string $agendaCode): array
    {
        $document = self::DOCUMENTS[$agendaCode];
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = false;
        try {
            $loaded = $dom->loadXML($bytes, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $dom->documentElement;
        if (!$loaded
            || !$root instanceof DOMElement
            || $root->localName !== $document['root']
            || $root->namespaceURI !== $document['namespace']
        ) {
            // Výjimka zůstává: poslední brána před odesláním. Poslat obsah,
            // který neodpovídá ohlášenému formuláři, znamená jistou chybu
            // na straně ČSSZ a zbytečně zablokované podání.
            throw new \DomainException(
                'Připravené podání neodpovídá formuláři, pod kterým je '
                . 'vedené (očekává se formulář ' . $document['root'] . '). '
                . 'Připravte registraci znovu z karty zaměstnance — v tomhle '
                . 'stavu by ji ČSSZ odmítla.',
            );
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('r', $document['namespace']);
        $employees = $xpath->query(
            '/r:' . $document['root'] . '/r:employees/r:employee',
        );
        if ($employees === false || $employees->length === 0) {
            // Výjimka zůstává: bez zaměstnance není co ČSSZ oznámit, takže
            // odeslání nemá předmět.
            throw new \DomainException(
                'Připravené podání neobsahuje žádného zaměstnance, takže '
                . 'není co ČSSZ odeslat. Připravte registraci znovu z karty '
                . 'zaměstnance.',
            );
        }
        foreach ($employees as $employee) {
            $action = $employee instanceof DOMElement
                ? $employee->getAttribute('act')
                : '';
            if (!$employee instanceof DOMElement
                || preg_match('/^[0-9]+$/D', $action) !== 1
                || !in_array((int) $action, $document['actions'], true)
            ) {
                // Výjimka zůstává: poslední brána před odesláním. Neschválený
                // druh podání by u ČSSZ založil oznámení, které účetní
                // nezadala a nemůže vzít zpět.
                throw new \DomainException(
                    'Druh registračního podání není podporovaný pro '
                    . 'odeslání na ČSSZ'
                    . $this->actionReference($agendaCode, $action)
                    . '. Připravte registraci znovu z karty zaměstnance; '
                    . 'pokud se hláška vrátí, jde o chybu aplikace '
                    . 'a odesílat se nesmí.',
                );
            }
            if ($agendaCode === 'REGZEC25' && (int) $action === 1) {
                try {
                    PayrollRegistrationBusinessMatrix::requireActionVariant(
                        1,
                        null,
                        null,
                        false,
                    );
                } catch (PayrollRegistrationXmlException $exception) {
                    // Text se záměrně nepřepisuje: business matice je jediné
                    // místo, které ví, KTERÝ údaj variantě A1 chybí, a její
                    // hláška ho jmenuje i s místem zadání. Vlastní obecnější
                    // věta by informaci ubrala.
                    throw new \DomainException(
                        $exception->getMessage(),
                        0,
                        $exception,
                    );
                }
            }
        }
        $symbols = [];
        $nodes = $xpath->query(
            '/r:' . $document['root'] . '/r:employees/r:employee/r:comp/@vs',
        );
        if ($nodes === false) {
            // Výjimka zůstává: nezkontrolované podání se odeslat nesmí —
            // právě tahle kontrola drží vazbu na variabilní symbol, pod
            // kterým ČSSZ podání spáruje se zaměstnavatelem.
            throw new \DomainException(
                'Připravené podání se nepodařilo zkontrolovat, proto se '
                . 'neodesílá. Připravte registraci znovu z karty '
                . 'zaměstnance; pokud se hláška vrátí, jde o chybu aplikace.',
            );
        }
        foreach ($nodes as $node) {
            $value = trim($node->nodeValue ?? '');
            if ($value !== '') {
                $symbols[$value] = true;
            }
        }
        if (count($symbols) !== 1) {
            // Výjimka zůstává: poslední brána před odesláním. Pod nejasným
            // variabilním symbolem by ČSSZ přiřadila zaměstnance jinému
            // zaměstnavateli, nebo podání odmítla.
            throw new \DomainException(
                PayrollRegistrationFieldVocabulary::label(
                    'employer_variable_symbol',
                )
                . ' musí být v celém podání jediný, ale připravené podání '
                . 'jich obsahuje víc, nebo žádný. '
                . PayrollRegistrationFieldVocabulary::describe(
                    'employer_variable_symbol',
                )
                . ' Potom registraci připravte znovu'
                . PayrollRegistrationFieldVocabulary::reference(
                    'employer_variable_symbol',
                )
                . '.',
            );
        }
        $variableSymbol = (string) array_key_first($symbols);
        if (preg_match('/^[0-9]{10}$/D', $variableSymbol) !== 1) {
            // Výjimka zůstává: poslední brána před odesláním. ČSSZ přijímá
            // desetimístný variabilní symbol; jiný tvar je jistý odmítnutý
            // dokument.
            throw new \DomainException(
                PayrollRegistrationFieldVocabulary::label(
                    'employer_variable_symbol',
                )
                . ' musí mít přesně deset číslic, ale v připraveném podání '
                . "je „{$variableSymbol}\". "
                . PayrollRegistrationFieldVocabulary::describe(
                    'employer_variable_symbol',
                )
                . ' Potom registraci připravte znovu'
                . PayrollRegistrationFieldVocabulary::reference(
                    'employer_variable_symbol',
                )
                . '.',
            );
        }

        return [
            'bytes' => $bytes,
            'sha256' => hash('sha256', $bytes),
            'variable_symbol' => $variableSymbol,
            'submission_class' => $document['class'],
        ];
    }

    /**
     * @param array{bytes:string,sha256:string,variable_symbol:string,submission_class:string} $payload
     * @return array<string,mixed>
     */
    private function result(
        JmhzDispatchOutcome $outcome,
        string $agendaCode,
        array $payload,
    ): array {
        return [
            'agenda_code' => $agendaCode,
            'submission_class' => $payload['submission_class'],
            'payload_sha256' => $payload['sha256'],
            'attempt' => $outcome->attempt,
            'acknowledgement' => $outcome->acknowledgement === null ? null : [
                'correlation_id' => $outcome->acknowledgement->correlationId,
                'poll_interval_seconds' => $outcome->acknowledgement->pollIntervalSeconds,
                'gateway_timestamp' => $outcome->acknowledgement->gatewayTimestamp,
            ],
            'settled' => $outcome->isSettled(),
        ];
    }
}

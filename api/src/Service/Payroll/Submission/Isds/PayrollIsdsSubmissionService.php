<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Isds;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Submission\Channel\Isds\IsdsTransportAvailabilityResolver;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionOutboxService;

/**
 * Zařazení zmrazeného mzdového podání do fronty podání datovou schránkou.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč tahle třída nic neodesílá
 * ═══════════════════════════════════════════════════════════════════════════
 * Odesílá {@see SubmissionOutboxService} a doručenku řeší
 * {@see \MyInvoice\Service\Submission\DeliveryReceiptService} — obojí existuje
 * a funguje pro daňová podání i přehledy pojišťovnám. Tahle třída je jen
 * PŘEKLADATEL: z mzdového světa (zmrazený artefakt, variabilní symbol, rozhodné
 * období) udělá to, čemu rozumí obecná fronta (příjemce z číselníku, věc,
 * artefakt), a předá to dál.
 *
 * Druhá odesílací cesta by znamenala druhý stavový automat, druhé místo, kde se
 * dá splést adresát, a druhou evidenci doručenek — přitom lhůta a povinnost
 * jsou jedny a tytéž bez ohledu na kanál.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč je agendově neutrální
 * ═══════════════════════════════════════════════════════════════════════════
 * Vznikla vytažením jádra z `JmhzIsdsSubmissionService`, které bylo strukturálně
 * obecné, ale zadrátované na JMHZ: věc zprávy nesla natvrdo „Jednotné měsíční
 * hlášení zaměstnavatele" a příjemce se bral z JMHZ katalogu. NEMPRI a HZUPN
 * mají přitom kanál ISDS doložený úplně stejně (viz
 * {@see \MyInvoice\Service\Payroll\Submission\Sickness\SicknessChannelCatalog})
 * a odesílat se nedaly vůbec. Zkopírovat tuhle třídu podruhé by znamenalo dvě
 * kopie kontroly adresáta — a právě ta je jediná pojistka proti tomu, aby mzdové
 * údaje odešly do cizí schránky.
 *
 * Agendu si služba NEBERE z požadavku, ale ČTE Z POVINNOSTI, ke které podání
 * patří. Kód agendy v požadavku by šlo podvrhnout a odeslat NEMPRI pod hlavičkou
 * JMHZ; z evidence se podvrhnout nedá.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Tři cesty ven, všechny přes tutéž frontu
 * ═══════════════════════════════════════════════════════════════════════════
 * **Odesílací brána ISDS** ({@see \MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayDispatchService}):
 * aplikace vloží koncept do perimetru ISDS a odeslání schválí uživatel přímo
 * tam. Přístupové údaje ke schránce naším serverem neprojdou vůbec, takže
 * § 9 odst. 2 zák. 300/2008 Sb. je splněn konstrukcí.
 *
 * **Mobilní klíč** ({@see \MyInvoice\Service\Submission\Channel\Isds\DirectIsdsInboxTransport}):
 * aplikace odešle přímo, ale výhradně v relaci, kterou účetní právě potvrdila
 * v mobilu (`POST /api/submissions/outbox/{id}/mobile-key/start` a `/confirm`).
 *
 * **Ruční cesta** zůstává a je celá průchozí, ať vyjde kterákoliv z předchozích
 * dvou, nebo žádná: podání se tu zařadí do fronty a dostane příjemce, věc
 * i spisovou značku, uživatel si přílohu stáhne a odešle ze SVÉ schránky,
 * `mark-sent` a nahraná doručenka (ZFO) podání uzavřou.
 *
 * Který z kanálů je zrovna k dispozici, počítá sdílené
 * {@see IsdsTransportAvailabilityResolver} — sdílí ho i zdravotní větev, ať se
 * obě cesty zase nerozejdou.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co tahle cesta NEMĚNÍ
 * ═══════════════════════════════════════════════════════════════════════════
 * Lhůtu ani povinnost. Zařazení do fronty nezakládá druhé podání — pracuje se
 * ZMRAZENÝM artefaktem téhož podání, které už v `payroll_submissions` existuje.
 * Volba kanálu je tedy rozhodnutí o dopravě, ne o tom, co a dokdy se podává.
 */
final readonly class PayrollIsdsSubmissionService
{
    private const CHANNEL = 'isds';
    private const ARTIFACT_KIND = 'payroll_submission';

    public function __construct(
        private PayrollSubmissionRepository $submissions,
        private PayrollSubmissionService $artifacts,
        private SubmissionRecipientRepository $recipients,
        private SubmissionOutboxService $outbox,
        private PayrollIsdsAgendaCatalog $agendas = new PayrollIsdsAgendaCatalog(),
        private PayrollIsdsMessageBuilder $builder = new PayrollIsdsMessageBuilder(),
        /**
         * Volitelně schválně: mzdová větev nesmí spadnout jen proto, že
         * ISDS transport není nastavený. Když chybí, hlásí se poctivě
         * „automaticky to nejde" a bez dokladu o datové schránce ani
         * „po potvrzení v mobilu".
         */
        private ?IsdsTransportAvailabilityResolver $transportAvailability = null,
    ) {}

    /**
     * Zařadí zmrazené podání do fronty a vrátí i hotovou datovou zprávu, aby ji
     * uživatel mohl v ručním režimu přesně opsat do své datové schránky.
     *
     * `$expectedAgendaCodes` je ROZSAH VOLAJÍCÍHO, ne určení agendy: obrazovka
     * nemocenských případů nesmí zařadit JMHZ a naopak. Prázdný seznam znamená
     * „cokoliv, co má doložený kanál".
     *
     * @param list<string> $expectedAgendaCodes
     * @return array{
     *   outbox_id:int,created:bool,row:array<string,mixed>,
     *   agenda_code:string,
     *   recipient:array{box_id:string,name:string,note:string},
     *   subject:string,sender_ident:string,
     *   attachment:array{filename:string,mime:string,sha256:string,bytes:int},
     *   transport:array{automatic:bool,channel:string,reason:?string}
     * }
     */
    public function enqueue(
        int $supplierId,
        string $environment,
        int $submissionId,
        array $expectedAgendaCodes,
        ?int $userId,
    ): array {
        $obligation = $this->submissions->findObligationOfSubmission(
            $supplierId,
            $environment,
            $submissionId,
        );
        if ($obligation === null) {
            throw new SubmissionChannelException(
                'payroll_isds_submission_not_found',
                'Připravené mzdové podání nebylo v tomhle prostředí nalezeno.',
                404,
            );
        }
        $agenda = $this->agendas->require((string) $obligation['agenda_code']);
        $this->assertWithinScope($agenda, $expectedAgendaCodes);

        $artifactId = $this->submissions->findOutboundXmlArtifactId(
            $supplierId,
            $environment,
            $submissionId,
        );
        if ($artifactId === null) {
            throw new SubmissionChannelException(
                'payroll_isds_frozen_payload_missing',
                'Podání nemá uloženou zmrazenou datovou větu, takže ho nelze'
                    . ' zařadit k odeslání datovou schránkou.',
                422,
            );
        }
        $payload = $this->artifacts->artifactBytes($supplierId, $artifactId);

        $recipient = $this->recipient($agenda, $supplierId, $environment);
        $periodLabel = self::periodLabel((string) $obligation['period_start']);
        $variableSymbol = self::variableSymbolIn($payload);
        $subject = $this->builder->subject($agenda, $periodLabel, $variableSymbol);

        // Stav `ready`, shodu prostředí, směr artefaktu i zapsanou verzi XSD
        // ověřuje `SubmissionArtifactValidator::assertTransportAuthority()`
        // uvnitř fronty. Kontrolovat je i tady by znamenalo druhou kopii
        // pravidla, která se s první dřív nebo později rozejde.
        $enqueued = $this->outbox->enqueue(
            $supplierId,
            $environment,
            self::CHANNEL,
            $agenda->code,
            self::ARTIFACT_KIND,
            $artifactId,
            (int) $recipient['id'],
            $subject,
            $userId,
        );
        $row = $enqueued['row'];

        // Zpráva se staví AŽ TEĎ, protože spisovou značku přiděluje fronta.
        // V ručním režimu je to jediný údaj, který se musí do datové schránky
        // opsat přesně — podle něj se pak dohledá odpověď ČSSZ.
        $message = $this->builder->build(
            $payload,
            $agenda,
            self::recipientOf($recipient, $agenda, $environment),
            $variableSymbol,
            $periodLabel,
            (string) $row['correlation_reference'],
        );

        return [
            'outbox_id' => (int) $row['id'],
            'created' => $enqueued['created'],
            'row' => $row,
            'agenda_code' => $agenda->code,
            'recipient' => [
                'box_id' => $message->recipient->boxId,
                'name' => $message->recipient->boxName,
                'note' => $message->recipient->note,
            ],
            'subject' => $message->subject,
            'sender_ident' => $message->senderIdent,
            'attachment' => [
                'filename' => $message->attachmentFilename,
                'mime' => $message->attachmentMimeType,
                'sha256' => $message->attachmentSha256(),
                'bytes' => strlen($message->attachmentBytes),
            ],
            'transport' => $this->transportAvailability($supplierId, $environment),
        ];
    }

    /**
     * Jde podání odeslat bez ručního opisování do datové schránky, a pokud jen
     * po potvrzení, KDO ho potvrzuje?
     *
     * Odpověď je tu, ne natvrdo v UI, protože se v čase mění a podle firmy.
     * `channel` říká, KTEROU cestou to půjde:
     *   `gateway`       — koncept vloží aplikace, odeslání schválí uživatel v ISDS,
     *   `mobile_key`    — aplikace odešle sama, ale až po potvrzení v mobilu,
     *   `manual_upload` — uživatel stáhne přílohu a odešle ji sám.
     *
     * @return array{automatic:bool,channel:string,reason:?string}
     */
    public function transportAvailability(int $supplierId, string $environment): array
    {
        return $this->transportAvailability !== null
            ? $this->transportAvailability->resolve($supplierId, $environment)
            : [
                'automatic' => false,
                'channel' => 'manual_upload',
                // Bez resolveru (typicky v testech, které si službu staví ručně)
                // se dostupnost nesmí hádat — poctivé „nevím" je „ručně".
                'reason' => 'isds_transport_unavailable',
            ];
    }

    /** @param list<string> $expectedAgendaCodes */
    private function assertWithinScope(
        PayrollIsdsAgenda $agenda,
        array $expectedAgendaCodes,
    ): void {
        if ($expectedAgendaCodes === []) {
            return;
        }
        $allowed = array_map(
            PayrollIsdsAgendaCatalog::canonical(...),
            $expectedAgendaCodes,
        );
        if (!in_array($agenda->code, $allowed, true)) {
            throw new SubmissionChannelException(
                'payroll_isds_agenda_scope_mismatch',
                'Podání patří jiné agendě (' . $agenda->code . '), než která se'
                    . ' z téhle obrazovky odesílá.',
                409,
            );
        }
    }

    /**
     * Příjemce se bere Z ČÍSELNÍKU, ne z konstanty v kódu.
     *
     * {@see PayrollIsdsAgendaCatalog} drží tytéž hodnoty a slouží jako kontrola:
     * kdyby někdo v číselníku ID schránky přepsal, tahle brána to zachytí dřív,
     * než mzdové údaje odejdou jinam. Číselník je editovatelný (aby šlo doplnit
     * místně příslušnou OSSZ), takže se na něj u ČSSZ nespoléhá slepě.
     *
     * @return array<string,mixed>
     */
    private function recipient(
        PayrollIsdsAgenda $agenda,
        int $supplierId,
        string $environment,
    ): array {
        $code = $agenda->recipientCode($environment);
        $row = $this->recipients->findVisibleByCode($supplierId, $code);
        if ($row === null || !$row['is_active']) {
            throw new SubmissionChannelException(
                'payroll_isds_recipient_missing',
                'V číselníku příjemců chybí (nebo je vypnutá) datová schránka'
                    . ' ČSSZ pro agendu ' . $agenda->code . ' (kód ' . $code
                    . '). Spusťte migrace.',
                422,
            );
        }

        $expected = $agenda->documentedBoxId($environment);
        $actual = strtolower(trim((string) ($row['isds_box_id'] ?? '')));
        if (!hash_equals($expected, $actual)) {
            throw new SubmissionChannelException(
                'payroll_isds_recipient_mismatch',
                'Datová schránka ČSSZ v číselníku neodpovídá doložené hodnotě.'
                    . ' Podání s mzdovými údaji se neodešle, dokud se to'
                    . ' nesrovná. ' . $agenda->sourceNote,
                409,
            );
        }

        return $row;
    }

    /** @param array<string,mixed> $row */
    private static function recipientOf(
        array $row,
        PayrollIsdsAgenda $agenda,
        string $environment,
    ): PayrollIsdsRecipient {
        return new PayrollIsdsRecipient(
            $agenda->documentedBoxId($environment),
            (string) $row['name'],
            strtolower(trim($environment)),
            $agenda->sourceNote,
        );
    }

    /** Období pro člověka; do XML nevstupuje, takže je to čistě popisek. */
    private static function periodLabel(string $periodStart): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);

        return $date instanceof \DateTimeImmutable
            ? $date->format('m/Y')
            : $periodStart;
    }

    /**
     * Variabilní symbol zaměstnavatele ze zmrazené datové věty.
     *
     * Čte se ze XML, ne z nastavení firmy: ve věci zprávy má stát přesně to,
     * co ČSSZ v příloze dostane. Každá agenda mu říká jinak — JMHZ
     * `hlavicka/variabilniSymbol`, HZUPN `zamestnani/variabilniSymbol`, NEMPRI
     * `zamestnani/VSZamestnavatel` — takže se hledá podle lokálního jména bez
     * ohledu na jmenný prostor.
     *
     * Chybějící symbol NENÍ chyba: věc zprávy ČSSZ podle protokolu v1.47
     * (strana 24) nezpracovává. Zastavit kvůli popisku odeslání, které je jinak
     * v pořádku, by bylo horší než věc bez symbolu.
     */
    private static function variableSymbolIn(string $xml): ?string
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return null;
        }
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query(
            '//*[local-name()="variabilniSymbol" or local-name()="VSZamestnavatel"]',
        );
        $node = $nodes === false ? null : $nodes->item(0);
        if ($node === null) {
            return null;
        }
        $value = trim($node->textContent);

        return $value === '' ? null : $value;
    }
}

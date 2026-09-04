<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlPassability;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenSubmissionIdentity;
use MyInvoice\Service\Payroll\Submission\PayrollReceiptVerifierInterface;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceipt;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceiptFormError;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceiptFormOutcome;

/**
 * Protokol o zpracování doručený do datové schránky ze schránky ČSSZ.
 *
 * ── Proč tahle cesta vedle podepsané ─────────────────────────────────────────
 * Obsahový výsledek hlášení nese JEDINĚ protokol o zpracování (resp. odpověď
 * DZMH) — a ten ČSSZ nepečetí, je to holé XML. Zapečetěná je jen obálka GovTalk
 * s výsledkem PŘÍJMU podání, ze které se „formulář byl odmítnut kontrolou“
 * vyčíst nedá: hlásí `result="OK"` i pro formulář, který cJMHZ následně
 * nezaevidovala. Kdyby aplikace uznávala výhradně podepsané protokoly, nikdy by
 * se nedozvěděla, že hlášení je přijaté jen částečně — a účetní by opravné
 * podání nemohla sestavit, přestože chybu drží v ruce.
 *
 * ── Čím je tedy protokol důvěryhodný ─────────────────────────────────────────
 * Nese ho datová zpráva doručená ZE SCHRÁNKY, DO KTERÉ jsme podání odeslali
 * (u JMHZ `iie254d`), staženou přímo aplikací z ISDS. K tomu musí obsah přílohy
 * sedět na zmrazenou datovou větu ve VŠECH třech údajích, které ji identifikují:
 *
 *  - `idPodani` — GUID, který jsme si vygenerovali MY a nikde jinde nefiguruje,
 *  - variabilní symbol zaměstnavatele,
 *  - rozhodné období.
 *
 * Podvrhnout takový dokument by znamenalo ovládat úřední datovou schránku ČSSZ.
 * Ručně nahraný soubor sem nepatří a nikdy se sem nedostane — ten zůstává jen
 * evidencí v {@see JmhzProtocolImportService}.
 *
 * Obálku GovTalk tenhle ověřovatel odmítá; ta má vlastní cestu s ověřením
 * pečeti ({@see JmhzReceiptVerifier}) a mísit obojí by znamenalo pustit
 * nepodepsanou obálku bez podpisu.
 */
final readonly class JmhzDeliveredProtocolVerifier implements PayrollReceiptVerifierInterface
{
    /**
     * @param list<array{
     *   form_guid:string,
     *   person_external_identifier?:?string,
     *   employment_external_identifier?:?string
     * }> $frozenForms součásti zmrazeného podání i s identitou zaměstnance
     * @param array<string,int> $formPartIds GUID formuláře → id součásti podání
     */
    public function __construct(
        private JmhzFrozenSubmissionIdentity $identity,
        private array $frozenForms,
        private array $formPartIds = [],
        private JmhzProtocolParser $parser = new JmhzProtocolParser(),
    ) {}

    public function verify(
        string $bytes,
        string $channel,
        string $environment,
        ?string $expectedCorrelationReference,
    ): PayrollVerifiedReceipt {
        if ($channel !== 'isds') {
            throw new JmhzTransportException(
                'jmhz_delivered_protocol_channel_unsupported',
                'Nepodepsaný protokol ČSSZ je důvěryhodný jen tehdy, když ho'
                    . ' doručila datová schránka.',
            );
        }
        $report = $this->parser->parse($bytes);
        if ($report->kind === JmhzProtocolKind::PartialSubmission) {
            throw new JmhzTransportException(
                'jmhz_delivered_protocol_kind_unsupported',
                'Obálka GovTalk se ověřuje pečetí ČSSZ, ne doručením.',
            );
        }
        $this->assertIdentity($report);

        $formOutcomes = $this->formOutcomes($report);

        // `CorrelationID` se ZÁMĚRNĚ nevrací. U podání odeslaného datovou
        // schránkou je correlation reference podání `dmId` odeslané zprávy,
        // kdežto protokol nese identifikátor přidělený ČSSZ — vrátit ho by
        // platforma vyhodnotila jako protokol cizího podání. Vazbu tady nese
        // shoda identity ze zmrazené datové věty, ne correlation.
        return new PayrollVerifiedReceipt(
            $report->status->payrollRemoteStatus(),
            null,
            $this->partStatuses($formOutcomes),
            $formOutcomes,
        );
    }

    /**
     * Tři údaje, které protokol váží na naše konkrétní podání. Chybějící údaj
     * je stejná chyba jako neshoda: protokol, který neřekne, ke komu a k jakému
     * období patří, se přiřadit nedá.
     */
    private function assertIdentity(JmhzProtocolReport $report): void
    {
        $guid = $report->submissionGuid === null
            ? null
            : strtoupper($report->submissionGuid);
        if ($guid === null || !hash_equals($this->identity->submissionGuid, $guid)) {
            throw new JmhzTransportException(
                'jmhz_delivered_protocol_submission_mismatch',
                'Doručený protokol neuvádí GUID tohoto podání.',
            );
        }
        if ($report->variableSymbol === null
            || !hash_equals($this->identity->variableSymbol, $report->variableSymbol)
        ) {
            throw new JmhzTransportException(
                'jmhz_delivered_protocol_variable_symbol_mismatch',
                'Doručený protokol patří jinému variabilnímu symbolu.',
            );
        }
        if ($report->periodMonth !== $this->identity->month
            || $report->periodYear !== $this->identity->year
        ) {
            throw new JmhzTransportException(
                'jmhz_delivered_protocol_period_mismatch',
                'Doručený protokol patří jinému rozhodnému období.',
            );
        }
    }

    /**
     * Výsledek každé součásti zmrazeného podání.
     *
     * Protokol o zpracování vypisuje jen CHYBY, ne stav jednotlivých formulářů.
     * Formulář, u kterého stojí nepropustná chyba, cJMHZ nezaevidovala — ten je
     * odmítnutý. Ostatní součásti téhož podání přijaté jsou; kdyby se nechaly
     * bez výsledku, účetní by u nich v opravě neviděla, co s nimi je. Když je
     * odmítnuté celé hlášení, padají s ním všechny.
     *
     * @return list<PayrollVerifiedReceiptFormOutcome>
     */
    private function formOutcomes(JmhzProtocolReport $report): array
    {
        $submissionRejected = in_array(
            $report->status,
            [JmhzSubmissionStatus::Rejected, JmhzSubmissionStatus::NotAccepted],
            true,
        );

        // Identita zaměstnance se bere ze ZMRAZENÉHO podání, ne z protokolu:
        // protokol o zpracování ji u chyby neuvádí (má jen `idFormulare`),
        // kdežto obrazovka opravy potřebuje vědět, KOHO se chyba týká. Bez
        // toho by u padesáti zaměstnanců musela účetní hledat odmítnutý
        // formulář ručně.
        /** @var array<string,array{person:?string,employment:?string}> $identities */
        $identities = [];
        foreach ($this->frozenForms as $form) {
            $guid = strtoupper(trim((string) ($form['form_guid'] ?? '')));
            if ($guid === '') {
                continue;
            }
            $identities[$guid] = [
                'person' => self::reference($form['person_external_identifier'] ?? null),
                'employment' => self::reference($form['employment_external_identifier'] ?? null),
            ];
        }

        /** @var array<string,list<PayrollVerifiedReceiptFormError>> $errorsByGuid */
        $errorsByGuid = [];
        /** @var array<string,bool> $blockedByGuid */
        $blockedByGuid = [];
        foreach ($report->parts as $part) {
            if ($part->kind !== JmhzProtocolPartKind::Form || $part->formGuid === null) {
                continue;
            }
            $guid = strtoupper($part->formGuid);
            if (!isset($identities[$guid])) {
                throw new JmhzTransportException(
                    'jmhz_delivered_protocol_form_unknown',
                    'Doručený protokol odkazuje na formulář mimo zmrazené podání.',
                );
            }
            foreach ($part->errors as $error) {
                $errorsByGuid[$guid][] = new PayrollVerifiedReceiptFormError(
                    $error->code,
                    $error->message,
                    $error->origin->value,
                    $error->controlId?->value,
                );
                if ($error->passability === JmhzControlPassability::Blocking) {
                    $blockedByGuid[$guid] = true;
                }
            }
        }

        $outcomes = [];
        foreach ($identities as $guid => $identity) {
            $rejected = $submissionRejected || ($blockedByGuid[$guid] ?? false);
            $status = $rejected
                ? JmhzSubmissionStatus::Rejected
                : JmhzSubmissionStatus::ProcessedAndComplete;
            $outcomes[] = new PayrollVerifiedReceiptFormOutcome(
                $guid,
                $this->formPartIds[$guid] ?? null,
                $status->value,
                $status->name,
                $rejected ? 'rejected' : 'accepted',
                $identity['person'],
                $identity['employment'],
                $errorsByGuid[$guid] ?? [],
            );
        }

        return $outcomes;
    }

    /** Prázdný řetězec není identifikátor; platformní hodnota to odmítne. */
    private static function reference(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param list<PayrollVerifiedReceiptFormOutcome> $outcomes
     * @return array<int,string>
     */
    private function partStatuses(array $outcomes): array
    {
        $statuses = [];
        foreach ($outcomes as $outcome) {
            if ($outcome->partId !== null && $outcome->remoteStatus !== null) {
                $statuses[$outcome->partId] = $outcome->remoteStatus;
            }
        }

        return $statuses;
    }
}

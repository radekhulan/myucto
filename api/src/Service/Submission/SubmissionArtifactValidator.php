<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use DOMDocument;
use DOMElement;
use MyInvoice\Service\Payroll\Garnishment\Xmlzam\XmlzamSchemaCatalog;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Validation\XmlSchemaValidator;

/**
 * Lokální kontrola podkladu proti XSD před odesláním.
 *
 * ── Proč to musí být povinný krok ───────────────────────────────────────────
 * Datová schránka nevaliduje obsah příloh vůbec. Na rozdíl od EPO, které
 * vrátí seznam chyb hned, tady nedostaneme ani podací číslo, ani protokol.
 * Vadné podání se ozve až jako **výzva k odstranění vad podle § 74 DŘ** —
 * běžnou zprávou, po několika dnech, kdy už lhůta může být pryč.
 *
 * Tahle kontrola je tedy jediná náhrada za kontrolu, kterou nám druhá strana
 * neudělá. Není to komfort, je to jediné místo, kde se překlep v XML dá chytit
 * včas.
 *
 * `skipped` je poctivá odpověď: pro řadu agend (přehledy pojišťoven, mzdová
 * podání) schéma v `api/xsd/` nemáme. Tvrdit `passed` u nezkontrolovaného
 * souboru by bylo horší než přiznat, že se nekontrolovalo.
 */
final readonly class SubmissionArtifactValidator
{
    /**
     * Kód agendy → `form_code` v {@see XmlSchemaValidator}.
     *
     * Mapa je úmyslně neúplná: doplňuje se jen tam, kde schéma opravdu máme.
     *
     * ── Proč tu NEJSOU mzdové agendy (JMHZ25, PREZEC26, REGZEC25, OZUSPOJ23,
     * REGZELDOPL25, ELDP, HOZ/PPZ) ─────────────────────────────────────────
     * Není to díra, je to vědomé — a nepřidávat je sem je BEZPEČNĚJŠÍ než
     * přidat. Mzdový podklad se do fronty nikdy nedostane jako „nějaké bajty":
     *
     *  1. Ověří se proti PŘIPNUTÉMU XSD své agendy už při MRAZENÍ, v době, kdy
     *     ještě existuje kontext (kdo, které období, který běh) a kdy se dá
     *     hlásit srozumitelná chyba. Podání, které schématem neprošlo, se
     *     nedostane do stavu `ready` — u zdravotních pojišťoven zůstane
     *     v `draft` s blokující výhradou ve fázi `xsd`.
     *  2. Zmrazený artefakt je hash-pinned: `artifactBytes()` ověřuje délku
     *     i SHA-256 proti archivu, takže odsud nevyjde nic jiného než přesně
     *     to, co se ověřilo.
     *  3. `assertTransportAuthority()` níž váže zařazený artefakt na
     *     autoritativní záznam podání (agenda, prostředí, směr, stav `ready`)
     *     a vyžaduje ZAPSANOU verzi XSD — tedy důkaz z bodu 1.
     *
     * Druhá validace tady by kontrolovala tytéž bajty proti témuž schématu
     * podruhé, ale s horší chybovou hláškou a s vlastní kopií mapy
     * agenda→schéma, která by se s katalogy v `Service\Payroll\Submission`
     * dřív nebo později rozešla. Tichý `skipped` byl ten skutečný problém —
     * proto ho `assertTransportAuthority()` nahrazuje ověřenou podmínkou.
     */
    private const AGENDA_SCHEMAS = [
        'DPHDP3' => 'dphdp3',
        'DPHKH1' => 'dphkh1',
        'DPHSHV' => 'dphshv',
        'DPPDP9' => 'dppdp9',
        'DPPO' => 'dppdp9',
        'DPFDP5' => 'dpfdp5',
        'DPFDP7' => 'dpfdp7',
        'DPFO' => 'dpfdp5',
        'OSSEI1' => 'ossei1',
        'OSVC25' => 'osvc25',
    ];

    public function __construct(
        private XmlSchemaValidator $schemas,
        private XmlzamSchemaCatalog $xmlzam = new XmlzamSchemaCatalog(),
    ) {}

    /**
     * @param array{filename:string,mime:string,bytes:string} $artifact
     * @return array{status:'passed'|'failed'|'skipped',errors:list<string>}
     */
    public function validateArtifact(string $agendaCode, array $artifact): array
    {
        $agendaCode = strtoupper($agendaCode);
        if ($agendaCode === 'ELDP') {
            return [
                'status' => 'failed',
                'errors' => [
                    'Samostatný ELDP obsahuje pouze kontrolní XML; odesílatelná datová věta není připnutá.',
                ],
            ];
        }
        if ($agendaCode === 'XMLZAM') {
            return $this->validateXmlzam($artifact);
        }
        $formCode = self::AGENDA_SCHEMAS[$agendaCode] ?? null;
        if ($formCode === null || !$this->schemas->hasSchema($formCode)) {
            return ['status' => 'skipped', 'errors' => []];
        }
        // Binární příloha (ZIP, PDF) se proti XSD validovat nedá — a předstírat
        // opak by znamenalo hlásit `failed` u něčeho, co je v pořádku.
        if (!str_contains($artifact['mime'], 'xml')) {
            return ['status' => 'skipped', 'errors' => []];
        }

        return $this->schemas->validate($artifact['bytes'], $formCode);
    }

    public function hasSchemaFor(string $agendaCode): bool
    {
        if (strtoupper($agendaCode) === 'XMLZAM') {
            return true;
        }
        $formCode = self::AGENDA_SCHEMAS[strtoupper($agendaCode)] ?? null;
        return $formCode !== null && $this->schemas->hasSchema($formCode);
    }

    /** @param array<string,mixed> $artifact */
    public function assertTransportAuthority(
        string $artifactKind,
        array $artifact,
        string $environment,
        string $agendaCode,
    ): void {
        if ($artifactKind !== 'payroll_submission') {
            return;
        }

        $authority = $artifact['authority'] ?? null;
        if (!is_array($authority)
            || ($authority['kind'] ?? null) !== 'payroll_submission'
        ) {
            throw new SubmissionChannelException(
                'payroll_artifact_context_missing',
                'Mzdový podklad nemá autoritativní vazbu na evidované podání.',
                409,
            );
        }

        $authoritativeAgenda = self::canonicalPayrollAgenda(strtoupper(trim(
            is_string($authority['agenda_code'] ?? null)
                ? $authority['agenda_code']
                : '',
        )));
        if ($authoritativeAgenda === 'ELDP') {
            throw new SubmissionChannelException(
                'payroll_artifact_untransportable',
                'Samostatný ELDP obsahuje pouze kontrolní XML. Odesílatelná datová věta není připnutá, proto ho nelze zařadit ani odeslat.',
                409,
            );
        }
        if (($authority['status'] ?? null) !== 'ready') {
            throw new SubmissionChannelException(
                'payroll_submission_not_ready',
                'Mzdové podání ještě není v autoritativním stavu připraveno k odeslání.',
                409,
            );
        }
        if (($authority['environment'] ?? null) !== $environment) {
            throw new SubmissionChannelException(
                'payroll_submission_environment_mismatch',
                'Prostředí fronty neodpovídá prostředí mzdového podání.',
                409,
            );
        }
        if ($authoritativeAgenda !== self::canonicalPayrollAgenda(
            strtoupper(trim($agendaCode)),
        )) {
            throw new SubmissionChannelException(
                'payroll_submission_agenda_mismatch',
                'Agenda fronty neodpovídá autoritativní agendě mzdového podání.',
                409,
            );
        }
        if (($authority['direction'] ?? null) !== 'outbound'
            || !in_array(
                $authority['artifact_kind'] ?? null,
                ['outbound_xml', 'outbound_pdf', 'outbound_zip'],
                true,
            )
        ) {
            throw new SubmissionChannelException(
                'payroll_artifact_not_outbound',
                'Do fronty lze zařadit jen zmrazený odchozí artefakt mzdového podání.',
                409,
            );
        }
        // Náhrada za XSD kontrolu, která se tady záměrně nedělá (viz komentář
        // u AGENDA_SCHEMAS): datová věta musí NÉST verzi schématu, proti
        // kterému se ověřila při mrazení. Bez ní je to nezkontrolovaný soubor
        // a poslední brána před datovou schránkou by ho pustila mlčky.
        // PDF a ZIP přílohy schéma nemají a mít nemohou.
        if (($authority['artifact_kind'] ?? null) === 'outbound_xml'
            && !is_string($authority['xsd_version'] ?? null)
        ) {
            throw new SubmissionChannelException(
                'payroll_artifact_schema_unrecorded',
                'Zmrazená datová věta mzdového podání nemá zapsanou verzi XSD,'
                    . ' takže není doložené, že prošla schématem. Zmrazte podání znovu.',
                409,
            );
        }
    }

    private static function canonicalPayrollAgenda(string $agendaCode): string
    {
        return $agendaCode === 'JMHZ' ? 'JMHZ25' : $agendaCode;
    }

    /**
     * @param array{filename:string,mime:string,bytes:string} $artifact
     * @return array{status:'passed'|'failed',errors:list<string>}
     */
    private function validateXmlzam(array $artifact): array
    {
        if (!str_contains($artifact['mime'], 'xml')) {
            return ['status' => 'failed', 'errors' => ['XMLZAM musí být XML příloha.']];
        }
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $artifact['bytes']) === 1) {
            return ['status' => 'failed', 'errors' => ['XMLZAM nesmí obsahovat deklaraci DTD.']];
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML(
                $artifact['bytes'],
                LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT,
            );
            $root = $document->documentElement;
            $declaredType = $root instanceof DOMElement
                ? $root->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type')
                : '';
            if (!$loaded || $declaredType !== 'soucinnost_odpoved') {
                return ['status' => 'failed', 'errors' => ['Příloha není odpověď na součinnost XMLZAM.']];
            }
            if (!$document->schemaValidate($this->xmlzam->schemaPath())) {
                $errors = [];
                foreach (libxml_get_errors() as $error) {
                    $message = trim($error->message);
                    if ($message !== '') {
                        $errors[] = $message;
                    }
                }
                return [
                    'status' => 'failed',
                    'errors' => $errors !== []
                        ? array_values(array_unique($errors))
                        : ['XMLZAM neodpovídá oficiálnímu schématu.'],
                ];
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return ['status' => 'passed', 'errors' => []];
    }
}

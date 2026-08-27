<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use DOMDocument;
use DOMElement;
use MyInvoice\Service\Payroll\Garnishment\Xmlzam\XmlzamSchemaCatalog;
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
        if (strtoupper($agendaCode) === 'XMLZAM') {
            return $this->validateXmlzam($artifact);
        }
        $formCode = self::AGENDA_SCHEMAS[strtoupper($agendaCode)] ?? null;
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

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;

/**
 * Zmrazená datová věta podání a její identita, načtené z archivu artefaktů.
 *
 * Existuje proto, že tutéž otázku — „co jsme vlastně odeslali?" — potřebují tři
 * různá místa: odeslání bez ručně předaného XML, dotaz na výsledek na pozadí
 * (potřebuje variabilní symbol) a storno (potřebuje GUID a rozhodné období).
 * Kdyby si každé sahalo do archivu po svém, rozešly by se v tom, co považují za
 * zdroj pravdy — a rozejít se dá jen tak, že jedno z nich sáhne po jiném podání.
 *
 * `artifactBytes()` sám ověřuje délku i SHA-256 proti archivu, takže odsud
 * nevyjde nic jiného než přesně to, co se kdysi zmrazilo.
 */
final readonly class JmhzFrozenPayloadReader
{
    public function __construct(
        private PayrollSubmissionRepository $repository,
        private PayrollSubmissionService $submissions,
    ) {}

    public function bytes(int $supplierId, string $environment, int $submissionId): string
    {
        $artifactId = $this->repository->findOutboundXmlArtifactId(
            $supplierId,
            $environment,
            $submissionId,
        );
        if ($artifactId === null) {
            throw new JmhzXmlException(
                'jmhz_submission_frozen_payload_missing',
                'Podání nemá uloženou zmrazenou datovou větu, takže s ním nelze'
                    . ' dál pracovat. Zmrazte hlášení znovu z přípravy.',
            );
        }

        return $this->submissions->artifactBytes($supplierId, $artifactId);
    }

    public function identity(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): JmhzFrozenSubmissionIdentity {
        return JmhzFrozenSubmissionIdentity::read(
            $this->bytes($supplierId, $environment, $submissionId),
        );
    }

    /**
     * Součásti přesně tak, jak byly zmrazené v řádném podání. UI z nich
     * sestaví výběr pro opravné podání, takže účetní nikdy neopisuje GUID ani
     * zákonné identifikátory ručně.
     *
     * @return list<array{
     *   form_guid:string,
     *   person_external_identifier:string,
     *   employment_external_identifier:string
     * }>
     */
    public function components(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): array {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadXML(
                $this->bytes($supplierId, $environment, $submissionId),
                LIBXML_NONET | LIBXML_NOBLANKS,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new JmhzXmlException(
                'jmhz_submission_frozen_payload_invalid',
                'Zmrazenou datovou větu podání nelze přečíst.',
            );
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('p', JmhzSchemaCatalog::NS_PODANI);
        $xpath->registerNamespace('f', JmhzSchemaCatalog::NS_FORM);
        $forms = $xpath->query('/p:jmhz/p:formulareOsob/p:formularOsoby');
        if ($forms === false) {
            throw new JmhzXmlException(
                'jmhz_submission_components_unreadable',
                'Součásti zmrazeného podání nelze načíst.',
            );
        }

        $components = [];
        foreach ($forms as $form) {
            if (!$form instanceof DOMElement) {
                continue;
            }
            $component = JmhzComponentCancellation::create(
                self::value($xpath, './p:hlavicka/p:idFormulare', $form),
                self::value($xpath, './/f:identifikace/f:ikMpsv', $form),
                self::value($xpath, './/f:identifikace/f:idPpv', $form),
            );
            $components[] = [
                'form_guid' => $component->formGuid,
                'person_external_identifier' => $component->personExternalIdentifier,
                'employment_external_identifier' => $component->employmentExternalIdentifier,
            ];
        }
        if ($components === []) {
            throw new JmhzXmlException(
                'jmhz_submission_components_missing',
                'Řádné podání neobsahuje žádný pracovní vztah, který by šel opravit.',
            );
        }

        return $components;
    }

    private static function value(
        DOMXPath $xpath,
        string $query,
        DOMElement $context,
    ): string {
        $nodes = $xpath->query($query, $context);
        $value = $nodes === false ? '' : trim((string) $nodes->item(0)?->textContent);
        if ($value === '') {
            throw new JmhzXmlException(
                'jmhz_submission_component_identity_missing',
                'Ve zmrazeném podání chybí identifikace pracovního vztahu.',
            );
        }

        return $value;
    }
}

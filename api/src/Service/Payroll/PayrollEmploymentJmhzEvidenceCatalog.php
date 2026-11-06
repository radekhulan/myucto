<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookUnavailableException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookValueException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationEmploymentStatusCodebook;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationRelationshipDetailPolicy;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSpecVersionRules;

final class PayrollEmploymentJmhzEvidenceCatalog
{
    /** @var array{manifest_sha256:string,payload:array<string,mixed>} */
    private readonly array $manifest;
    private readonly JmhzCodebookCatalog $codebooks;

    public function __construct(
        private readonly JmhzSpecPackageCatalog $packages,
        private readonly JmhzExternalCodebookCatalog $externalCodebooks,
    ) {
        $this->manifest = $packages->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        );
        $this->codebooks = new JmhzCodebookCatalog($this->manifest);
    }

    /**
     * Kód obce pracoviště (10230) proti CISOB. Název obce se tu NEPOROVNÁVÁ:
     * zdroj pravdy je kód a `work_place` je volný text smlouvy, který se
     * s úředním názvem srovná zvlášť ({@see workplaceNameMismatch()}).
     *
     * Období, na které připnuté číselníky nesahají, se NEOVĚŘUJE. JMHZ platí
     * od roku 2026 a starší stav číselníků neexistuje — u vztahu, který začal
     * dřív, tedy není co ověřit. Fail-closed zůstává tam, kam patří — při
     * sestavení podání, kde se hodnoty do JMHZ opravdu odesílají.
     */
    public function requireWorkplaceMunicipality(string $municipalityCode, string $termEffectiveOn): void
    {
        if (!$this->externalCodebooks->coversDate($termEffectiveOn)) {
            return;
        }
        try {
            $this->externalCodebooks->requireMunicipalityCode($municipalityCode, $termEffectiveOn);
        } catch (JmhzCodebookValueException|JmhzCodebookUnavailableException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    /** Kód státu pracoviště (10231) proti CZEMALFA; mimo pokrytí číselníků se neověřuje. */
    public function requireWorkplaceCountry(string $countryCode, string $termEffectiveOn): void
    {
        if (!$this->externalCodebooks->coversDate($termEffectiveOn)) {
            return;
        }
        try {
            $this->externalCodebooks->requireKnownCountry($countryCode, $termEffectiveOn);
        } catch (JmhzCodebookValueException|JmhzCodebookUnavailableException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    /** Úřední název obce podle kódu; `null` = kód k datu v CISOB není. */
    public function officialMunicipalityName(string $municipalityCode, string $validOn): ?string
    {
        $label = $this->externalCodebooks->findMunicipality($municipalityCode, $validOn)['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : null;
    }

    /**
     * Věta pro účetní, když se místo výkonu práce liší od úředního názvu obce
     * v CISOB; `null` = shoda, nebo není s čím porovnat.
     *
     * Hlášení JMHZ nese v 10229 název obce a běh ho ověřuje proti CISOB, takže
     * s neshodným názvem se měsíční hlášení nepřipraví. Uložení podmínek tím
     * ale blokované není — je to varování s návodem, co opravit.
     */
    public function workplaceNameMismatch(string $municipalityCode, string $workPlace, string $validOn): ?string
    {
        $official = $this->officialMunicipalityName($municipalityCode, $validOn);
        if ($official === null || hash_equals($official, $workPlace)) {
            return null;
        }

        return sprintf(
            'Místo výkonu práce „%s" neodpovídá úřednímu názvu obce „%s" pro kód %s v číselníku CISOB. '
                . 'Měsíční hlášení JMHZ ho vykazuje jako název obce, takže se s ním nepřipraví. '
                . 'Opravte místo výkonu práce na „%s", nebo kód obce pracoviště JMHZ.',
            $workPlace,
            $official,
            $municipalityCode,
            $official,
        );
    }

    public function requireApzInstrument(string $code): void
    {
        try {
            $this->codebooks->requireValue('nastroj_opatreni', $code);
        } catch (JmhzCodebookValueException $e) {
            throw new \InvalidArgumentException('Kód nástroje APZ není v připnutém číselníku JMHZ.', 0, $e);
        }
    }

    public function requireActivityCode(string $code): void
    {
        try {
            $this->codebooks->requireValue('druh_cinnosti', $code);
        } catch (JmhzCodebookValueException $e) {
            throw new \InvalidArgumentException('Druh činnosti není v připnutém číselníku JMHZ.', 0, $e);
        }
    }

    public function requireRelationshipDetailCode(string $code): void
    {
        try {
            $this->codebooks->requireValue('blizsi_urceni_pracovnepravn', $code);
        } catch (JmhzCodebookValueException $e) {
            throw new \InvalidArgumentException('Bližší určení pracovněprávního vztahu není v připnutém číselníku JMHZ.', 0, $e);
        }
    }

    /** Důvod předčasného ukončení REGZEC A2-SPEC. */
    public function requireEarlyTerminationReason(string $code): void
    {
        $this->requireEmbeddedCodebookValue(
            'duvod_predcasneho_ukonceni',
            $code,
            'Důvod předčasného ukončení',
        );
    }

    /** Důvod ukončení pracovního poměru v podkladech A2. */
    public function requireEmploymentTerminationReason(string $code): void
    {
        $this->requireEmbeddedCodebookValue(
            'duvod_ukonceni_ppv',
            $code,
            'Důvod ukončení pracovního vztahu',
        );
    }

    /** Důvod ukončení služebního poměru v podkladech A2. */
    public function requireServiceTerminationReason(string $code): void
    {
        $this->requireEmbeddedCodebookValue(
            'duvod_ukonceni_sluz_pomeru',
            $code,
            'Důvod ukončení služebního poměru',
        );
    }

    /** @return array{package_key:string,manifest_sha256:string} */
    public function packageProvenance(): array
    {
        return [
            'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            'manifest_sha256' => $this->manifest['manifest_sha256'],
        ];
    }

    /** @return array{package_key:string,manifest_sha256:string,external_codebooks:array<string,string|null>,activity_codes:list<array{code:string,label:string,relationship_detail_mode:string}>,relationship_detail_codes:list<array{code:string,label:string}>,apz_instruments:list<array{code:string,label:string}>,countries:list<array{code:string,label:string}>,tax_identifier_types:list<array{code:string,label:string}>,education_levels:list<array{code:string,label:string}>,work_mode_codes:list<array{code:string,label:string}>,employment_status_codes:list<array{code:string,label:string}>,workplace_progress_codes:list<array{code:string,label:string}>,pension_type_codes:list<array{code:string,label:string}>,proof_identity_type_codes:list<array{code:string,label:string}>,health_restriction_type_codes:list<array{code:string,label:string}>,foreign_worker_free_access_reason_codes:list<array{code:string,label:string}>,foreign_worker_permit_type_codes:list<array{code:string,label:string}>,labour_office_codes:list<array{code:string,label:string}>,from_2027:array<string,mixed>} */
    public function options(): array
    {
        $apzOptions = [];
        foreach (['1', '2', '3', '4'] as $code) {
            $entry = $this->codebooks->requireValue('nastroj_opatreni', $code);
            $label = $entry['label'] ?? null;
            if (!is_string($label)) {
                throw new \UnexpectedValueException('Připnutý číselník JMHZ má neplatnou položku.');
            }
            $apzOptions[] = ['code' => $code, 'label' => $label];
        }

        return [
            'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            'manifest_sha256' => $this->manifest['manifest_sha256'],
            'external_codebooks' => $this->externalCodebooks->provenance(),
            'activity_codes' => array_map(
                static fn (array $option): array => [
                    ...$option,
                    'relationship_detail_mode' =>
                        PayrollRegistrationRelationshipDetailPolicy::modeForActivity($option['code']),
                ],
                $this->codebookOptions('druh_cinnosti'),
            ),
            'relationship_detail_codes' => $this->codebookOptions(
                'blizsi_urceni_pracovnepravn',
            ),
            'apz_instruments' => $apzOptions,
            'countries' => $this->externalCodebooks->countries(
                $this->externalCodebooks->provenance()['effective_from'],
            ),
            // Zbytek jsou malé, uzavřené číselníky REGZEC A1 (GFŘ/ČSSZ/ÚP ČR/ČSÚ),
            // vložené přímo v připnutém datovém slovníku JMHZ — stejný zdroj a
            // stejná pinovaná verze jako druh_cinnosti výše, žádné nové stažení.
            'tax_identifier_types' => $this->codebookOptions('typ_danove_identifikace'),
            'education_levels' => $this->codebookOptions('kategorie_dosazeneho_vzdela'),
            'work_mode_codes' => $this->codebookOptions('pracovni_rezim'),
            // Jen čtyřmístné listy NKPZ — kratší kód ČSSZ nepřijme (EDV 10249).
            'employment_status_codes' => PayrollRegistrationEmploymentStatusCodebook::options(),
            'workplace_progress_codes' => $this->codebookOptions('prubeh_prace'),
            'pension_type_codes' => $this->codebookOptions('druh_duchodu'),
            'proof_identity_type_codes' => $this->codebookOptions('typ_dokladu'),
            'health_restriction_type_codes' => $this->codebookOptions('typ_zdravotniho_omezeni'),
            'foreign_worker_free_access_reason_codes' => $this->codebookOptions(
                'duvod_pro_volny_pristup_na',
            ),
            'foreign_worker_permit_type_codes' => $this->codebookOptions(
                'druh_pracovniho_opravneni',
            ),
            'labour_office_codes' => $this->codebookOptions('krajske_pobocky_up_cr'),
            'from_2027' => $this->optionsFrom2027(),
        ];
    }

    /**
     * Hodnoty číselníků, které přidává až EDV 1.5.0.3 a ČSSZ je přijme jen
     * pro události od 1. 1. 2027 ({@see PayrollRegistrationSpecVersionRules}).
     * Formulář je nabídne podle data události; popisky jsou z připnutého
     * balíku 2027, ne opsané.
     *
     * @return array{employment_types:list<array{code:string,label:string}>,free_access_reason_codes:list<array{code:string,label:string}>,service_termination_reasons:list<array{code:string,label:string,employment_types:list<string>}>}
     */
    private function optionsFrom2027(): array
    {
        $pin = $this->packages->requirePin(JmhzSpecVersion::V2027);
        $codebooks = new JmhzCodebookCatalog(
            $this->packages->load($pin['package_key'], $pin['manifest_sha256']),
        );
        $pick = static function (string $key, ?array $only = null) use ($codebooks): array {
            $options = [];
            foreach ($codebooks->entries($key) as $entry) {
                $code = $entry['item_code'] ?? null;
                $label = $entry['label'] ?? null;
                if (!is_string($code) || !is_string($label)) {
                    throw new \UnexpectedValueException('Připnutý číselník JMHZ má neplatnou položku.');
                }
                if ($only === null || in_array($code, $only, true)) {
                    $options[] = ['code' => $code, 'label' => trim($label)];
                }
            }

            return $options;
        };
        $serviceTypes = PayrollRegistrationSpecVersionRules::SERVICE_TERMINATION_BY_TYPE;

        return [
            'employment_types' => $pick('druh_zamestnani', ['3', '4']),
            'free_access_reason_codes' => $pick(
                'duvod_pro_volny_pristup_na',
                PayrollRegistrationSpecVersionRules::FREE_ACCESS_REASONS_2027,
            ),
            'service_termination_reasons' => array_map(
                static fn (array $option): array => [
                    ...$option,
                    'employment_types' => array_values(array_map('strval', array_keys(array_filter(
                        $serviceTypes,
                        static fn (array $codes): bool => in_array($option['code'], $codes, true),
                    )))),
                ],
                $pick('duvod_ukonceni_sluz_pomeru'),
            ),
            // Slovník 1.5.0.3 NKPZ nevkládá, nabídka 2027 jde z číselníku ČSÚ.
            'employment_status_codes' => PayrollRegistrationEmploymentStatusCodebook::options(
                JmhzSpecVersion::V2027,
            ),
        ];
    }

    /** @return list<array{code:string,label:string}> */
    public function municipalities(string $query, int $limit): array
    {
        try {
            return $this->externalCodebooks->searchKnownMunicipalities($query, $limit);
        } catch (JmhzCodebookUnavailableException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    /** Sahají připnuté číselníky JMHZ na tohle datum? */
    public function externalCodebooksCover(string $validOn): bool
    {
        return $this->externalCodebooks->coversDate($validOn);
    }

    /** @return array{overlay_key:string,manifest_sha256:string,snapshot_date:string,effective_from:string,effective_to:?string,verified_through:string,base_spec_manifest_sha256:string} */
    public function externalCodebookProvenance(?string $validOn = null): array
    {
        return $validOn === null
            ? $this->externalCodebooks->provenance()
            : $this->externalCodebooks->provenanceForDate($validOn);
    }

    /** @return list<array{code:string,label:string}> */
    private function codebookOptions(string $key): array
    {
        $options = [];
        foreach ($this->codebooks->entries($key) as $entry) {
            $code = $entry['item_code'] ?? null;
            $label = $entry['label'] ?? null;
            if (!is_string($code) || !is_string($label)) {
                throw new \UnexpectedValueException('Připnutý číselník JMHZ má neplatnou položku.');
            }
            $options[] = ['code' => $code, 'label' => $label];
        }
        return $options;
    }

    private function requireEmbeddedCodebookValue(
        string $codebookKey,
        string $code,
        string $label,
    ): void {
        try {
            $this->codebooks->requireValue($codebookKey, $code);
        } catch (JmhzCodebookValueException|JmhzCodebookUnavailableException $e) {
            throw new \InvalidArgumentException(
                "{$label} {$code} není v připnutém číselníku JMHZ.",
                0,
                $e,
            );
        }
    }
}

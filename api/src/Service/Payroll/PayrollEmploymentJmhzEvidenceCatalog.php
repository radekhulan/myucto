<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookUnavailableException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookValueException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;

final class PayrollEmploymentJmhzEvidenceCatalog
{
    /** @var array{manifest_sha256:string,payload:array<string,mixed>} */
    private readonly array $manifest;
    private readonly JmhzCodebookCatalog $codebooks;

    public function __construct(
        JmhzSpecPackageCatalog $packages,
        private readonly JmhzExternalCodebookCatalog $externalCodebooks,
    ) {
        $this->manifest = $packages->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        );
        $this->codebooks = new JmhzCodebookCatalog($this->manifest);
    }

    public function requireWorkplace(
        string $municipalityCode,
        string $municipalityName,
        string $countryCode,
        string $termEffectiveOn,
    ): void
    {
        try {
            $this->externalCodebooks->requireKnownMunicipality(
                $municipalityCode,
                $municipalityName,
                $termEffectiveOn,
            );
            $this->externalCodebooks->requireKnownCountry($countryCode, $termEffectiveOn);
        } catch (JmhzCodebookValueException|JmhzCodebookUnavailableException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
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

    /** @return array{package_key:string,manifest_sha256:string,external_codebooks:array<string,string|null>,activity_codes:list<array{code:string,label:string}>,relationship_detail_codes:list<array{code:string,label:string}>,apz_instruments:list<array{code:string,label:string}>,countries:list<array{code:string,label:string}>} */
    public function options(): array
    {
        $apzOptions = [];
        foreach (['1', '2', '3', '4'] as $code) {
            $entry = $this->codebooks->requireValue('nastroj_opatreni', $code);
            $apzOptions[] = ['code' => $code, 'label' => (string) $entry['label']];
        }

        return [
            'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            'manifest_sha256' => $this->manifest['manifest_sha256'],
            'external_codebooks' => $this->externalCodebooks->provenance(),
            'activity_codes' => $this->codebookOptions('druh_cinnosti'),
            'relationship_detail_codes' => $this->codebookOptions(
                'blizsi_urceni_pracovnepravn',
            ),
            'apz_instruments' => $apzOptions,
            'countries' => $this->externalCodebooks->countries(
                $this->externalCodebooks->provenance()['effective_from'],
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
}

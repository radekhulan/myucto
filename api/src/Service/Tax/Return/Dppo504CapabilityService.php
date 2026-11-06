<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final class Dppo504CapabilityService
{
    public const COMPUTE = 'compute';
    public const FINALIZE = 'finalize';
    public const EXPORT_XML = 'export_xml';
    public const SUBMIT = 'submit';

    private const FORM_CODE = 'dppdp9';
    private const FORM_VERSION = '05.01.01';
    private const FORM_DATE = '2025-10-21';
    private const XSD_SHA256 = 'db3846b9495f38362a845a5becee732347cea995df44ec03006011ed12f3272c';

    /**
     * $calculationAccepted = firma výslovně akceptovala výpočet pro aktuální verzi pravidel
     * a formuláře (NonprofitDppoAcceptanceService). Bez ní zůstává dostupný jen výpočet.
     */
    public function capabilities(string $startsOn, string $endsOn, string $returnType = 'A', bool $calculationAccepted = false): array
    {
        $manifest = $this->manifest();
        $result = ['manifest' => $manifest];
        foreach ([self::COMPUTE, self::FINALIZE, self::EXPORT_XML, self::SUBMIT] as $operation) {
            $result[$operation] = $this->capability($operation, $startsOn, $endsOn, $returnType, $manifest, $calculationAccepted);
        }
        return $result;
    }

    public function assert(string $operation, string $startsOn, string $endsOn, string $returnType = 'A', bool $calculationAccepted = false): array
    {
        $capability = $this->capability($operation, $startsOn, $endsOn, $returnType, $this->manifest(), $calculationAccepted);
        if (!$capability['allowed']) {
            throw new TaxReturnException($capability['code'], $capability['message'], 422);
        }
        return $capability;
    }

    public function manifest(): array
    {
        $path = dirname(__DIR__, 4) . '/xsd/dppdp9_epo2.xsd';
        $actual = is_file($path) ? hash_file('sha256', $path) : false;
        return [
            'form_code' => self::FORM_CODE,
            'form_version' => self::FORM_VERSION,
            'form_date' => self::FORM_DATE,
            'xsd_sha256' => self::XSD_SHA256,
            'xsd_verified' => is_string($actual) && hash_equals(self::XSD_SHA256, strtolower($actual)),
            'official_source' => 'https://adisspr.mfcr.cz/dpr/adis/idpr_pub/epo2_info/popis_struktury_detail.faces?zkratka=DPPDP9',
        ];
    }

    public function assertManifest(array $manifest, bool $requireVerifiedSchema): void
    {
        $expected = [
            'form_code' => self::FORM_CODE,
            'form_version' => self::FORM_VERSION,
            'form_date' => self::FORM_DATE,
            'xsd_sha256' => self::XSD_SHA256,
            'official_source' => 'https://adisspr.mfcr.cz/dpr/adis/idpr_pub/epo2_info/popis_struktury_detail.faces?zkratka=DPPDP9',
        ];
        foreach ($expected as $key => $value) {
            if (($manifest[$key] ?? null) !== $value) {
                throw new TaxReturnException('nonprofit_dppo_form_manifest_invalid', 'Zmrazený důkaz DPPO 504 neodpovídá připnuté oficiální verzi formuláře.', 409);
            }
        }
        if (!is_bool($manifest['xsd_verified'] ?? null) || ($requireVerifiedSchema && !$manifest['xsd_verified'])) {
            throw new TaxReturnException('nonprofit_dppo_form_manifest_invalid', 'Zmrazený důkaz DPPO 504 nemá ověřený otisk oficiálního schématu.', 409);
        }
    }

    private function capability(string $operation, string $startsOn, string $endsOn, string $returnType, array $manifest, bool $calculationAccepted): array
    {
        if (!in_array($operation, [self::COMPUTE, self::FINALIZE, self::EXPORT_XML, self::SUBMIT], true)) {
            throw new \InvalidArgumentException('Unknown DPPO 504 capability.');
        }
        if (!$this->date($startsOn) || !$this->date($endsOn) || $startsOn > $endsOn) {
            return $this->blocked('nonprofit_dppo_period_required', 'DPPO 504 vyžaduje platný skutečný interval účetního období.');
        }
        if ($startsOn < NonprofitTaxRules::AUTOMATIC_FROM || $endsOn > NonprofitTaxRules::AUTOMATIC_TO) {
            return $this->blocked('nonprofit_dppo_ruleset_not_supported', 'Nativní výpočet DPPO 504 je ověřen pouze pro období celé uvnitř právního rulesetu 2024 až 2026.');
        }
        if ($operation === self::COMPUTE) {
            return $this->allowed();
        }
        if (!$manifest['xsd_verified']) {
            return $this->blocked('nonprofit_dppo_form_schema_unverified', 'Připnuté oficiální schéma DPPDP9 neodpovídá ověřenému otisku.');
        }
        $latestStart = $returnType === 'A' ? '2025-12-30' : '2025-12-31';
        if ($startsOn < '2021-01-01' || $startsOn > $latestStart) {
            return $this->blocked('nonprofit_dppo_form_period_not_supported', 'Zveřejněná struktura DPPDP9 05.01.01 nepřijímá začátek tohoto období. Výpočet zůstává dostupný bez finalizace, XML a podání.');
        }
        if (!$calculationAccepted) {
            return $this->blocked('nonprofit_dppo_calculation_not_accepted', 'Finalizace, XML a podání DPPO 504 vyžadují výslovnou akceptaci výpočtu správcem firmy. Výpočet zůstává dostupný.');
        }
        return $this->allowed();
    }

    private function allowed(): array
    {
        return ['allowed' => true, 'code' => null, 'message' => null];
    }

    private function blocked(string $code, string $message): array
    {
        return ['allowed' => false, 'code' => $code, 'message' => $message];
    }

    private function date(string $date): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

/** Výslovně potvrzená země zdrojového partnera; bez dotazů do registrů a bez změn DPH. */
final class StereoNxPartnerCountries
{
    /** @return array<string,string> */
    public static function configuration(mixed $value): array
    {
        if ($value === null) return [];
        if (!is_array($value)) self::invalid();
        $result = [];
        foreach ($value as $key => $country) {
            $key = (string) $key;
            if ($key === '' || strlen($key) > 190 || str_contains($key, "\0") || !is_string($country)) self::invalid();
            $country = strtoupper(trim($country));
            if (preg_match('/^[A-Z]{2}$/D', $country) !== 1 || $country === 'EU') self::invalid();
            $result[$key] = $country;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    public static function fingerprint(mixed $value): string
    {
        return hash('sha256', json_encode(self::configuration($value), JSON_THROW_ON_ERROR));
    }

    /** @param array{code:string,unresolved:bool} $country @return array{code:string,unresolved:bool} */
    public static function resolve(array $country, string $sourceKey, array $configuration): array
    {
        if ($country['unresolved'] && isset($configuration[$sourceKey])) {
            return ['code' => $configuration[$sourceKey], 'unresolved' => false];
        }
        return $country;
    }

    /** Kontrola až po vytvoření historických partnerů, jejichž klíče nejsou v adresáři. */
    public static function assertKnown(array $configuration, array $clients, ?array $supportedIso2 = null): void
    {
        if ($supportedIso2 !== null) self::assertSupported($configuration, $supportedIso2);
        $known = [];
        foreach ($clients as $client) $known[(string) $client['source_key']] = $client;
        foreach ($configuration as $key => $country) {
            if (!isset($known[$key])) {
                throw new StereoNxException('partner_country_unknown', 'Potvrzená země odkazuje na neznámého zdrojového partnera.');
            }
            if (!$known[$key]['country_unresolved'] && $known[$key]['country_code'] !== $country) {
                throw new StereoNxException('partner_countries_invalid', 'Potvrzená země se liší od jednoznačné země zdrojového partnera.');
            }
        }
    }

    /** Číselník načte volající jedním dotazem; shodná validace pro HTTP akci i worker. */
    public static function assertSupported(array $configuration, array $supportedIso2): void
    {
        foreach ($configuration as $country) {
            if (!in_array($country, $supportedIso2, true)) self::invalid();
        }
    }

    /** Kandidáti zůstávají odděleni zdrojovým klíčem i při shodném IČO. */
    public static function candidates(array $clients, array $issued, array $purchases, array $configuration): array
    {
        $documents = [];
        $needsCountry = [];
        foreach (['issued' => $issued, 'purchase' => $purchases] as $kind => $records) {
            foreach ($records as $document) {
                $key = (string) $document['partner_key'];
                $documents[$key][] = ['source_key' => (string) $document['source_key'],
                    'document_no' => (string) $document['document_no'], 'kind' => $kind];
                if (array_intersect(['partner_country_unresolved', 'partner_country_eu_unspecified'], $document['review_codes'] ?? []) !== []) {
                    $needsCountry[$key] = true;
                }
            }
        }
        $result = [];
        foreach ($clients as $client) {
            $key = (string) $client['source_key'];
            if (!$client['country_unresolved'] && !isset($configuration[$key]) && !isset($needsCountry[$key])) continue;
            $result[] = ['source_key' => $key, 'name' => (string) $client['name'],
                'ico' => (string) $client['ico'], 'dic' => (string) $client['dic'],
                'source_country' => (string) $client['country'], 'country_code' => (string) $client['country_code'],
                'confirmed_country' => $configuration[$key] ?? null, 'documents' => $documents[$key] ?? []];
        }
        return $result;
    }

    /** Neurčená země karty adresáře je jiný nález než kontrola konkrétního dokladu. */
    public static function directoryWarnings(array $clients, array $candidates): array
    {
        $documentCounts = [];
        foreach ($candidates as $candidate) $documentCounts[(string) $candidate['source_key']] = count($candidate['documents']);
        $warnings = [];
        foreach ($clients as $client) {
            if (!$client['country_unresolved']) continue;
            $key = (string) $client['source_key'];
            $count = $documentCounts[$key] ?? 0;
            $warnings[] = ['level' => 'warning', 'code' => 'partner_country_unresolved_directory',
                'partner_source_key' => $key, 'partner_name' => (string) $client['name'],
                'ico' => (string) $client['ico'], 'dic' => (string) $client['dic'],
                'source_country' => (string) $client['country'], 'document_count' => $count,
                'message' => 'Adresář: protistrana ' . (string) $client['name']
                    . ' má neověřenou zemi. Připojených dokladů: ' . $count . '.'];
        }
        return $warnings;
    }

    private static function invalid(): never
    {
        throw new StereoNxException('partner_countries_invalid', 'Potvrzené země partnerů musí obsahovat zdrojové klíče a platné dvoupísmenné kódy zemí.');
    }
}

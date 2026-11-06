<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

use MyInvoice\Service\Payroll\Component\PayrollInputTabularParser;

final class PropertyRegistryImportParser
{
    public const COLUMNS = ['record_type', 'unit_code', 'unit_no', 'kind', 'share_num', 'share_den', 'group_key',
        'ownership_kind', 'person_ref', 'person_name', 'person_email', 'role', 'scopes', 'title_ref',
        'attribute', 'num', 'den', 'identifier_kind', 'value', 'payment_scope', 'service_code'];

    public function parse(string $format, string $content, array $partyMap, array $serviceMap): array
    {
        try {
            $parsed = (new PayrollInputTabularParser())->parse($format, $content, ['record_type', 'unit_code']);
        } catch (\InvalidArgumentException $e) {
            throw new PropertyException('property_import_invalid', $e->getMessage());
        }
        if ($format === 'xlsx') $this->assertSingleSheet($content);
        if ($parsed['errors'] !== []) throw new PropertyException('property_import_invalid', 'Soubor obsahuje neplatné řádky.', details: ['errors' => $parsed['errors']]);
        return $this->rows($parsed['rows'], $partyMap, $serviceMap);
    }

    public function rows(array $rows, array $partyMap, array $serviceMap): array
    {
        $units = [];
        $errors = [];
        $missingParties = [];
        $missingServices = [];
        foreach ($partyMap as $reference => $id) { PropertyInput::code((string) $reference, 'person_ref'); PropertyInput::id($id, 'client_id'); }
        foreach ($serviceMap as $reference => $id) { PropertyInput::code((string) $reference, 'service_code'); PropertyInput::id($id, 'service_id'); }
        foreach ($rows as $row) {
            try {
                PropertyInput::fields($row, [...self::COLUMNS, 'row_number']);
                $code = PropertyInput::code($row['unit_code'] ?? null, 'unit_code');
                if (($row['record_type'] ?? '') !== 'unit') continue;
                if (isset($units[strtolower($code)])) throw new PropertyException('property_import_duplicate_unit', 'Jednotka má v importu více základních řádků.');
                $units[strtolower($code)] = ['code' => $code, 'state' => ['unit_no' => $row['unit_no'] ?? '', 'kind' => $row['kind'] ?? '', 'active' => true,
                    'attributes' => ['share' => ['num' => $row['share_num'] ?? '', 'den' => $row['share_den'] ?? '']],
                    'ownerships' => [], 'roles' => [], 'identifiers' => [], 'services' => []]];
            } catch (PropertyException $e) { $errors[] = $this->error($row, $e); }
        }
        foreach ($rows as $row) {
            try {
                $code = strtolower(PropertyInput::code($row['unit_code'] ?? null, 'unit_code'));
                $record = $row['record_type'] ?? '';
                if ($record === 'unit') continue;
                if (!isset($units[$code])) throw new PropertyException('property_import_unknown_unit', 'Řádek odkazuje na jednotku bez základního řádku.');
                $state = &$units[$code]['state'];
                if (in_array($record, ['owner', 'role'], true)) {
                    $reference = PropertyInput::code($row['person_ref'] ?? null, 'person_ref');
                    if (!isset($partyMap[$reference])) {
                        $missingParties[$reference] = ['reference' => $reference, 'name' => $row['person_name'] ?? '', 'email' => $row['person_email'] ?? ''];
                        continue;
                    }
                    $clientId = $partyMap[$reference];
                }
                switch ($record) {
                    case 'owner':
                        $key = PropertyInput::code($row['group_key'] ?? null, 'group_key');
                        $group = ['group_key' => $key, 'kind' => $row['ownership_kind'] ?? '',
                            'share' => PropertyRational::normalize(['num' => $row['num'] ?? '', 'den' => $row['den'] ?? '']),
                            'title_ref' => PropertyInput::text($row['title_ref'] ?? null, 'title_ref', 2000)];
                        if (isset($state['ownerships'][$key])) {
                            $previous = $state['ownerships'][$key];
                            unset($previous['members']);
                            if ($previous !== $group) throw new PropertyException('property_import_group_conflict', 'Členové stejné účasti uvádějí rozdílný podíl, druh nebo titul.');
                        } else $state['ownerships'][$key] = $group + ['members' => []];
                        $state['ownerships'][$key]['members'][] = $clientId;
                        break;
                    case 'role':
                        $state['roles'][] = ['client_id' => $clientId, 'role' => $row['role'] ?? '',
                            'scopes' => trim((string) ($row['scopes'] ?? '')) === '' ? [] : explode('|', $row['scopes']), 'title_ref' => $row['title_ref'] ?? ''];
                        break;
                    case 'identifier':
                        $state['identifiers'][] = ['kind' => $row['identifier_kind'] ?? '', 'value' => $row['value'] ?? '',
                            'payment_scope' => ($row['payment_scope'] ?? '') === '' ? 'supplier' : $row['payment_scope'], 'source' => 'import', 'title_ref' => $row['title_ref'] ?? ''];
                        break;
                    case 'attribute':
                        $attribute = PropertyInput::code($row['attribute'] ?? null, 'attribute');
                        if (isset($state['attributes'][$attribute])) throw new PropertyException('property_import_duplicate_attribute', 'Atribut jednotky je uveden vícekrát.');
                        $state['attributes'][$attribute] = PropertyRational::normalize(['num' => $row['num'] ?? '', 'den' => $row['den'] ?? '']);
                        break;
                    case 'service':
                        $reference = PropertyInput::code($row['service_code'] ?? null, 'service_code');
                        if (!isset($serviceMap[$reference])) $missingServices[$reference] = $reference;
                        else $state['services'][] = $serviceMap[$reference];
                        break;
                    default:
                        throw new PropertyException('property_import_record_unknown', 'Neznámý druh importního řádku.');
                }
                unset($state);
            } catch (PropertyException $e) { $errors[] = $this->error($row, $e); }
            finally { unset($state); }
        }
        foreach ($units as &$unit) {
            $unit['state']['ownerships'] = array_values($unit['state']['ownerships']);
            if ($missingParties === [] && $missingServices === []) {
                try { $unit['state'] = PropertyUnitState::normalize($unit['state']); }
                catch (PropertyException $e) { $errors[] = ['unit_code' => $unit['code'], 'code' => $e->errorCode, 'details' => $e->details, 'message' => $e->getMessage()]; }
            }
        }
        unset($unit);
        if ($units === []) $errors[] = ['code' => 'property_import_empty', 'message' => 'Import neobsahuje jednotky.'];
        ksort($units, SORT_STRING);
        ksort($missingParties, SORT_STRING);
        ksort($missingServices, SORT_STRING);
        return ['units' => array_values($units), 'unresolved_parties' => array_values($missingParties), 'unresolved_services' => array_values($missingServices),
            'errors' => $errors, 'ready' => $units !== [] && $errors === [] && $missingParties === [] && $missingServices === []];
    }

    private function error(array $row, PropertyException $e): array
    {
        return ['row' => $row['row_number'] ?? null, 'code' => $e->errorCode, 'details' => $e->details, 'message' => $e->getMessage()];
    }

    private function assertSingleSheet(string $content): void
    {
        $path = tempnam(sys_get_temp_dir(), 'property-import-');
        if ($path === false) throw new \RuntimeException('Nelze připravit import registru.');
        try {
            file_put_contents($path, $content);
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::RDONLY) !== true) throw new PropertyException('property_import_invalid', 'Soubor XLSX nelze načíst.');
            try {
                $xml = $zip->getFromName('xl/workbook.xml');
                if (!is_string($xml) || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) throw new PropertyException('property_import_invalid', 'Neplatný sešit XLSX.');
                $dom = new \DOMDocument();
                if (!$dom->loadXML($xml, LIBXML_NONET) || $dom->getElementsByTagName('sheet')->length !== 1) {
                    throw new PropertyException('property_import_single_sheet', 'Importní šablona musí mít právě jeden list. Žádný další list nebude tiše vynechán.');
                }
            } finally { $zip->close(); }
        } finally { unlink($path); }
    }
}

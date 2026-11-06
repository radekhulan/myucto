<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges\Issuance;

use MyInvoice\Service\Property\PropertyInput as Input;
use Symfony\Polyfill\Intl\Normalizer\Normalizer;

final class IssuanceCaseIdentity
{
    public static function normalize(mixed $value): array
    {
        if (!is_array($value)) Input::invalid('case_identity');
        Input::fields($value, ['schema','case_ref','occurrence_ref','title_ref','decision_ref','decided_on','evidence_ref']);
        if (($value['schema'] ?? null) !== 1) Input::invalid('case_identity.schema');
        $out = ['schema'=>1];
        foreach (['case_ref'=>160,'occurrence_ref'=>160,'title_ref'=>500,'decision_ref'=>500] as $key=>$max)
            $out[$key] = self::text($value[$key] ?? null, 'case_identity.'.$key, $max);
        $out['decided_on'] = IssuanceRequest::date($value['decided_on'] ?? null);
        $out['evidence_ref'] = self::text($value['evidence_ref'] ?? null, 'case_identity.evidence_ref', 4000);
        return $out;
    }

    public static function distinction(mixed $value): array
    {
        if (!is_array($value)) Input::invalid('case_distinction');
        Input::fields($value, ['against_source_keys','legal_reason','evidence_ref']);
        $keys = [];
        foreach (Input::list($value['against_source_keys'] ?? null, 'against_source_keys', 10000) as $key)
            $keys[] = Input::code($key, 'against_source_keys');
        $keys = array_values(array_unique($keys, SORT_STRING)); sort($keys, SORT_STRING);
        return ['against_source_keys'=>$keys,
            'legal_reason'=>self::text($value['legal_reason'] ?? null, 'case_distinction.legal_reason', 4000),
            'evidence_ref'=>self::text($value['evidence_ref'] ?? null, 'case_distinction.evidence_ref', 4000)];
    }

    public static function canonical(string $value): string
    {
        $value = self::text($value, 'case_reference', 4000);
        return mb_convert_case(trim(preg_replace('/[\s\p{Z}]+/u', ' ', $value)), MB_CASE_FOLD, 'UTF-8');
    }

    public static function key(int $supplier, int $unit, array $identity): string
    {
        return Input::hash([$supplier,$unit,self::canonical($identity['case_ref']),self::canonical($identity['occurrence_ref'])]);
    }

    public static function families(iterable $charges): array
    {
        $families = [];
        foreach ($charges as $charge) {
            $key = Input::hash([$charge['supplier_id'],$charge['unit_id'],$charge['source_key']]);
            $snapshot = $charge['snapshot'];
            $identity = $snapshot['case_identity'] ?? null;
            $distinction = $snapshot['case_distinction'] ?? null;
            if ($identity !== null && self::normalize($identity) !== $identity) self::corrupt();
            if ($distinction !== null && self::distinction($distinction) !== $distinction) self::corrupt();
            if (!isset($families[$key])) {
                if ($charge['revision_no'] !== 1 || $charge['previous_charge_id'] !== null) self::corrupt();
                $families[$key] = ['supplier_id'=>$charge['supplier_id'],'unit_id'=>$charge['unit_id'],'source_key'=>$charge['source_key'],
                    'root_charge_id'=>$charge['id'],'case_identity'=>$identity,'case_distinction'=>$distinction,
                    'identity_status'=>$identity === null ? 'legacy_unclassified' : 'explicit','periods'=>[]];
            } else {
                $old = $families[$key];
                if ($charge['revision_no'] !== $old['latest_revision']+1 || $charge['previous_charge_id'] !== $old['latest_charge_id']
                    || Input::hash([$identity,$distinction]) !== Input::hash([$old['case_identity'],$old['case_distinction']])) self::corrupt();
                unset($old);
            }
            $family = &$families[$key];
            $family['latest_charge_id'] = $charge['id']; $family['latest_revision'] = $charge['revision_no'];
            $family['status'] = $charge['status']; $family['row_version'] = $charge['row_version'];
            $family['periods'][] = [$charge['period_from'],$charge['period_to']];
            unset($family);
        }
        return array_values($families);
    }

    public static function context(array $families): array
    {
        return array_map(static function (array $family): array { unset($family['periods']); return $family; }, $families);
    }

    public static function assertInitial(int $supplier, array $request, array $unit, array $families): array
    {
        $identity = $request['case_identity'] ?? throw new IssuanceException('property_charge_case_identity_required', 'Doložte označení případu, jeho výskyt a původní rozhodnutí.');
        $candidates = [];
        foreach ($families as $family) {
            if ($family['supplier_id'] !== $supplier || $family['unit_id'] !== $unit['unit_id'] || $family['source_key'] === $request['source_key']) continue;
            $other = $family['case_identity'];
            if ($other !== null && self::key($supplier,$unit['unit_id'],$other) === self::key($supplier,$unit['unit_id'],$identity))
                throw new IssuanceException('property_charge_case_exists', 'Tento doložený případ již má rodinu předpisů. Vyberte ji pro pokračování nebo opravu.', 409,
                    ['unit_id'=>$unit['unit_id'],'source_key'=>$family['source_key']]);
            $reasons = [];
            foreach ($family['periods'] as [$from,$to]) if ($from <= $request['period_to'] && $to >= $request['period_from']) { $reasons[] = 'period_overlap'; break; }
            if ($other !== null && self::canonical($other['decision_ref']) === self::canonical($identity['decision_ref'])
                && self::canonical($other['occurrence_ref']) === self::canonical($identity['occurrence_ref'])) $reasons[] = 'same_decision_occurrence';
            if ($reasons !== []) { unset($family['periods']); $candidates[] = $family+['reasons'=>$reasons]; }
        }
        usort($candidates, static fn(array $a,array $b): int => strcmp($a['source_key'],$b['source_key']));
        $expected = array_column($candidates,'source_key');
        if (($unit['case_distinction']['against_source_keys'] ?? []) !== $expected)
            throw new IssuanceException('property_charge_case_distinction_required', 'Doložte odlišení samostatného nároku vůči všem uvedeným případům jednotky.', 422,
                ['unit_id'=>$unit['unit_id'],'case_candidates'=>$candidates]);
        return $candidates;
    }

    public static function assertContinuation(int $supplier, array $request, array $unit, array $families): void
    {
        foreach ($families as $family) if ($family['supplier_id'] === $supplier && $family['unit_id'] === $unit['unit_id'] && $family['source_key'] === $request['source_key']) {
            if (Input::hash([$request['case_identity'] ?? null,$unit['case_distinction'] ?? null]) !== Input::hash([$family['case_identity'],$family['case_distinction']]))
                throw new IssuanceException('property_charge_case_identity_changed', 'Oprava musí zachovat doloženou identitu a odlišení původního případu.', 409, ['unit_id'=>$unit['unit_id']]);
            return;
        }
    }

    private static function text(mixed $value, string $field, int $max): string
    {
        if (!is_string($value) || !mb_check_encoding($value,'UTF-8')) Input::invalid($field);
        $value = Normalizer::normalize($value, Normalizer::FORM_C);
        return Input::text($value,$field,$max);
    }

    private static function corrupt(): never
    {
        throw new IssuanceException('property_charge_issue_history_corrupt', 'Historický předpis neodpovídá uloženému dokladu a otisku.', 409);
    }
}

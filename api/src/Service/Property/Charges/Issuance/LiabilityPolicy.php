<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges\Issuance;

use MyInvoice\Service\Property\Charges\ChargeFraction;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;

final class LiabilityPolicy
{
    public static function validate(array $groups, array $registryState, string $at, array $tenantClientIds): array
    {
        if ($groups === [] || !array_is_list($groups)) self::reject('groups');
        $at = PropertyInstant::normalize($at);
        $out = []; $keys = []; $membersSeen = []; $sum = ['num'=>'0','den'=>'1'];
        foreach ($groups as $group) {
            if (!is_array($group)) self::reject('group');
            PropertyInput::fields($group, ['group_key','role','mode','basis','members','extent','evidence_ref','legal_reason','valid_from','valid_to']);
            $key = PropertyInput::code($group['group_key'] ?? null, 'group_key');
            if (isset($keys[$key])) self::reject('group_key');
            $keys[$key] = true;
            $role = $group['role'] ?? null; $mode = $group['mode'] ?? null; $basis = $group['basis'] ?? null;
            if (!in_array($role, ['debtor','guarantor'], true) || !in_array($mode, ['individual','solidary'], true)
                || !in_array($basis, ['individual_title','sjm_713','joint_act_1127','documented_title'], true)) self::reject('liability');
            $members = PropertyInput::list($group['members'] ?? null, 'members');
            foreach ($members as $id) {
                PropertyInput::id($id, 'client_id');
                if (!in_array($id, $tenantClientIds, true) || isset($membersSeen[$role.':'.$id])) self::reject('client_id');
                $membersSeen[$role.':'.$id] = true;
            }
            sort($members, SORT_NUMERIC);
            if (($mode === 'individual' && count($members) !== 1) || ($mode === 'solidary' && count($members) < 2)) self::reject('mode');
            if ($basis === 'individual_title' && $mode !== 'individual') self::reject('basis');
            if ($basis === 'sjm_713') {
                $matched = false;
                foreach ($registryState['ownerships'] ?? [] as $ownership) {
                    $owners = $ownership['members']; sort($owners, SORT_NUMERIC);
                    if ($ownership['kind'] === 'marital_community' && $owners === $members) $matched = true;
                }
                if (!$matched || $mode !== 'solidary' || $role !== 'debtor') self::reject('sjm_facts');
            }
            if ($basis === 'joint_act_1127' && $mode !== 'solidary') self::reject('joint_act');
            $extent = $group['extent'] ?? null;
            if (!is_array($extent) || array_diff(array_keys($extent), ['num','den']) !== []) self::reject('extent');
            foreach (['num','den'] as $field) if (!is_string($extent[$field] ?? null) || !preg_match('/^[1-9][0-9]{0,29}$/D', $extent[$field])) self::reject('extent');
            $extent = ChargeFraction::reduce($extent['num'], $extent['den']);
            if (bccomp($extent['num'], $extent['den'], 0) > 0) self::reject('extent');
            [$from, $to] = PropertyInstant::interval($group['valid_from'] ?? null, $group['valid_to'] ?? null);
            if (!PropertyInstant::contains($from, $to, $at)) self::reject('liability_effective_at');
            if ($role === 'debtor') $sum = ChargeFraction::add($sum, $extent);
            $out[] = ['group_key'=>$key,'role'=>$role,'mode'=>$mode,'basis'=>$basis,'members'=>$members,'extent'=>$extent,
                'evidence_ref'=>PropertyInput::text($group['evidence_ref'] ?? null, 'evidence_ref', 500),
                'legal_reason'=>PropertyInput::text($group['legal_reason'] ?? null, 'legal_reason', 2000),
                'valid_from'=>$from,'valid_to'=>$to];
        }
        if ($sum !== ['num'=>'1','den'=>'1']) self::reject('debtor_extent_sum');
        usort($out, static fn(array $a,array $b): int => [$a['role'],$a['group_key']] <=> [$b['role'],$b['group_key']]);
        return $out;
    }

    private static function reject(string $field): never
    {
        throw new IssuanceException('property_charge_liability_unproven', 'Doložte zavázané osoby, právní titul a rozsah odpovědnosti za tuto pohledávku.', 422, ['field'=>$field]);
    }
}

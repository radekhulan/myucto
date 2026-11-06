<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

final class PropertyOwnership
{
    public static function normalize(array $groups): array
    {
        if (!array_is_list($groups) || $groups === [] || count($groups) > 200) self::invalid();
        $out = [];
        foreach ($groups as $group) {
            if (!is_array($group) || count($group) !== 5 || array_diff(array_keys($group), ['group_key', 'kind', 'share', 'members', 'title_ref']) !== []) self::invalid();
            $key = $group['group_key'] ?? null;
            $kind = $group['kind'] ?? null;
            $members = $group['members'] ?? null;
            $title = is_string($group['title_ref'] ?? null) ? trim($group['title_ref']) : '';
            if (!is_string($key) || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $key) || isset($out[strtolower($key)])
                || !in_array($kind, ['single', 'coownership_share', 'marital_community'], true)
                || !is_array($members) || !array_is_list($members) || $title === '' || mb_strlen($title) > 2000
                || count($members) !== ($kind === 'marital_community' ? 2 : 1) || count(array_unique($members, SORT_REGULAR)) !== count($members)) self::invalid();
            foreach ($members as $member) if (!is_int($member) || $member <= 0) self::invalid();
            if (!is_array($group['share'] ?? null)) self::invalid();
            $share = PropertyRational::normalize($group['share']);
            if ($share['num'] === '0' || PropertyRational::compare($share, ['num' => '1', 'den' => '1']) > 0 || ($kind === 'single' && count($groups) !== 1)) self::invalid();
            sort($members, SORT_NUMERIC);
            $out[strtolower($key)] = ['group_key' => $key, 'kind' => $kind, 'share' => $share, 'members' => $members, 'title_ref' => $title];
        }
        if (PropertyRational::sum(array_column($out, 'share')) !== ['num' => '1', 'den' => '1']) {
            throw new PropertyException('property_ownership_incomplete', 'Součet vlastnických účastí musí být přesně jeden celek. Členové SJM podíl nenásobí.');
        }
        ksort($out, SORT_STRING);
        return array_values($out);
    }

    private static function invalid(): never
    {
        throw new PropertyException('property_invalid_ownership', 'Vlastnická účast vyžaduje druh, přesný podíl, odlišné osoby a doložený titul.');
    }
}

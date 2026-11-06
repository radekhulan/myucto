<?php

declare(strict_types=1);

$units = [];
foreach (range(1, 12) as $number) {
    $code = $number === 12 ? 'G12' : sprintf('U%02d', $number);
    $person = match ($number) { 1 => 1, 2 => 3, 3, 4 => 6, 5 => 7, 6 => 10, 7 => 12, default => $number + 6 };
    $owners = [['group_key' => 'OWNER', 'kind' => 'single', 'share' => ['num' => '1', 'den' => '1'], 'members' => [$person], 'title_ref' => 'Synthetic REG-2026']];
    if ($number === 1) $owners = [['group_key' => 'SJM', 'kind' => 'marital_community', 'share' => ['num' => '1', 'den' => '1'], 'members' => [1, 2], 'title_ref' => 'Synthetic REG-2026']];
    if ($number === 2) {
        $owners = [];
        foreach (['1/2', '3/10', '1/5'] as $i => $fraction) {
            [$num, $den] = explode('/', $fraction);
            $owners[] = ['group_key' => 'P02' . $i, 'kind' => 'coownership_share', 'share' => compact('num', 'den'), 'members' => [$i + 3], 'title_ref' => 'Synthetic REG-2026'];
        }
    }
    $units[$code] = ['unit_no' => $code, 'kind' => $number === 12 ? 'garage' : 'apartment', 'active' => true,
        'attributes' => ['share' => ['num' => (string) ($number < 11 ? 90 : ($number === 11 ? 80 : 20)), 'den' => '1000']],
        'ownerships' => $owners,
        'roles' => $number === 7 ? [['client_id' => 13, 'role' => 'payer', 'scopes' => [], 'title_ref' => 'Synthetic third-party payment consent']] : [],
        'identifiers' => [['kind' => 'vs', 'value' => (string) $number, 'payment_scope' => 'supplier', 'source' => 'manual', 'title_ref' => 'Synthetic REG-2026']],
        'services' => $number === 12 ? [] : [1]];
}
return $units;

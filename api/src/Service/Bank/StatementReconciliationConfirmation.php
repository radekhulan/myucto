<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

final class StatementReconciliationConfirmation
{
    /** @return list<string> */
    public static function parse(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Neplatná potvrzení shod výpisu.');
        }
        foreach ($value as $key) {
            if (!is_string($key) || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
                throw new \InvalidArgumentException('Neplatná potvrzení shod výpisu.');
            }
        }
        if (count(array_unique($value, SORT_STRING)) !== count($value)) {
            throw new \InvalidArgumentException('Neplatná potvrzení shod výpisu.');
        }

        return $value;
    }
}

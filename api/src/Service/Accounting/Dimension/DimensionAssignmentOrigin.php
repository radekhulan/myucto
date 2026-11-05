<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

final class DimensionAssignmentOrigin
{
    public const ORIGINS = ['unknown', 'explicit', 'company_default', 'entity_default', 'rule_default', 'bank_rule_default', 'card_default', 'module'];

    /** @return array{origin:string,ref:?string} */
    public static function normalize(array $data = []): array
    {
        $origin = (string) ($data['origin'] ?? 'unknown');
        $ref = isset($data['ref']) ? (string) $data['ref'] : null;
        if (!in_array($origin, self::ORIGINS, true) || ($ref !== null && mb_strlen($ref) > 190)) {
            throw new \InvalidArgumentException('Neplatný původ přiřazení dimenze.');
        }
        return ['origin' => $origin, 'ref' => $ref];
    }
}

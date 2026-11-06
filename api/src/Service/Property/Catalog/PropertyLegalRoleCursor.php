<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Catalog;

use MyInvoice\Service\Property\PropertyException;

final class PropertyLegalRoleCursor
{
    public static function encode(int $supplierId, array $filters, int $afterId): string
    {
        if ($supplierId <= 0 || $afterId <= 0 || array_keys($filters) !== ['as_of', 'known_at']) self::invalid();
        $json = self::json(['after_id' => $afterId, 'filters' => $filters, 'supplier_id' => $supplierId, 'v' => 1]);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public static function decode(string $token, int $supplierId, array $filters): int
    {
        if ($token === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) self::invalid();
        $padding = (4 - strlen($token) % 4) % 4;
        $json = base64_decode(strtr($token . str_repeat('=', $padding), '-_', '+/'), true);
        if ($json === false) self::invalid();
        try { $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR); }
        catch (\JsonException) { self::invalid(); }
        if (!is_array($payload) || array_keys($payload) !== ['after_id', 'filters', 'supplier_id', 'v']
            || $payload['v'] !== 1 || $payload['supplier_id'] !== $supplierId || $payload['filters'] !== $filters
            || !is_int($payload['after_id']) || $payload['after_id'] <= 0
            || !hash_equals($token, self::encode($supplierId, $filters, $payload['after_id']))) self::invalid();
        return $payload['after_id'];
    }

    private static function json(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function invalid(): never
    {
        throw new PropertyException('property_legal_role_cursor_invalid', 'Kurzor právních rolí neodpovídá firmě a zadaným okamžikům.', 422);
    }
}

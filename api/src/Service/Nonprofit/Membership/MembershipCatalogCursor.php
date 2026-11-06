<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final class MembershipCatalogCursor
{
    public static function encode(string $scope, int $supplier, array $filters, int $afterId): string
    {
        if (!in_array($scope, ['roles', 'receivables'], true) || $supplier <= 0 || $afterId <= 0) self::invalid();
        $json = NonprofitTaxRules::canonicalJson(['v' => 1, 'scope' => $scope, 'supplier_id' => $supplier, 'filters' => $filters, 'after_id' => $afterId]);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public static function decode(string $token, string $scope, int $supplier, array $filters): int
    {
        if ($token === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) self::invalid();
        $padding = (4 - strlen($token) % 4) % 4;
        $json = base64_decode(strtr($token . str_repeat('=', $padding), '-_', '+/'), true);
        if ($json === false) self::invalid();
        try { $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR); }
        catch (\JsonException) { self::invalid(); }
        if (!is_array($payload) || array_keys($payload) !== ['after_id', 'filters', 'scope', 'supplier_id', 'v']
            || $payload['v'] !== 1 || $payload['scope'] !== $scope || $payload['supplier_id'] !== $supplier
            || $payload['filters'] !== $filters || !is_int($payload['after_id']) || $payload['after_id'] <= 0
            || !hash_equals($token, self::encode($scope, $supplier, $filters, $payload['after_id']))) self::invalid();
        return $payload['after_id'];
    }

    public static function decodeRole(string $token, int $supplier, string $asOf, ?string $requestedKnownAt): array
    {
        $payload = self::payload($token);
        $knownAt = $payload['filters']['known_at'] ?? null;
        if (!is_string($knownAt) || ($requestedKnownAt !== null && $requestedKnownAt !== $knownAt)) self::invalid();
        $filters = ['as_of' => $asOf, 'known_at' => $knownAt];
        return ['after_id' => self::decode($token, 'roles', $supplier, $filters), 'known_at' => $knownAt];
    }

    private static function payload(string $token): array
    {
        if ($token === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) self::invalid();
        $padding = (4 - strlen($token) % 4) % 4;
        $json = base64_decode(strtr($token . str_repeat('=', $padding), '-_', '+/'), true);
        if ($json === false) self::invalid();
        try { $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR); }
        catch (\JsonException) { self::invalid(); }
        if (!is_array($payload)) self::invalid();
        return $payload;
    }

    private static function invalid(): never
    {
        throw new MembershipException('nonprofit_membership_catalog_cursor_invalid', 'Kurzor katalogu neodpovídá firmě a zadaným filtrům.', 422);
    }
}

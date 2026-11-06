<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

final class Fx504CloneScrubber
{
    public static function payload(string $json): string
    {
        $payload = json_decode($json, true);
        if (!is_array($payload) || !array_key_exists('fx504', $payload)) return $json;
        unset($payload['fx504'], $payload['preview']);
        $payload['fx504_anonymized'] = true;
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

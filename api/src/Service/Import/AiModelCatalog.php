<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class AiModelCatalog
{
    public const INVALID_KEY = 'model_catalog_invalid_key';
    public const UNAVAILABLE = 'model_catalog_unavailable';
    public const EMPTY = 'model_catalog_empty';
    public const INCOMPLETE = 'model_catalog_incomplete';
    public const MAX_PAGES = 10;
    private const TOTAL_TIMEOUT = 15;

    public static function deadline(): float
    {
        return hrtime(true) / 1e9 + self::TOTAL_TIMEOUT;
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,string|int> $query
     * @return array{body:array|null, error:?string}
     */
    public static function fetchPage(Client $http, string $url, #[\SensitiveParameter] array $headers, array $query = [], ?float $deadline = null): array
    {
        $timeout = $deadline === null ? 5.0 : min(5.0, $deadline - hrtime(true) / 1e9);
        if ($timeout < 0.001) {
            return ['body' => null, 'error' => self::UNAVAILABLE];
        }
        try {
            $response = $http->get($url, [
                'headers' => $headers,
                'query' => $query,
                'timeout' => $timeout,
                'connect_timeout' => min(3.0, $timeout),
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (GuzzleException | \InvalidArgumentException) {
            return ['body' => null, 'error' => self::UNAVAILABLE];
        }

        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        if ($status === 200 && is_array($body)) {
            return ['body' => $body, 'error' => null];
        }
        $details = $body['error']['details'] ?? [];
        $invalidGeminiKey = $status === 400 && is_array($details)
            && in_array('API_KEY_INVALID', array_column($details, 'reason'), true);
        return [
            'body' => null,
            'error' => $invalidGeminiKey || in_array($status, [401, 403], true) ? self::INVALID_KEY : self::UNAVAILABLE,
        ];
    }

    /**
     * @param list<string> $ids
     * @param list<string> $recommended
     * @return array{models:list<string>, error:?string}
     */
    public static function result(array $ids, array $recommended, bool $incomplete = false): array
    {
        $remaining = array_values(array_diff(array_unique($ids), $recommended));
        rsort($remaining, SORT_NATURAL);
        return [
            'models' => [...array_values(array_intersect($recommended, $ids)), ...$remaining],
            'error' => $incomplete ? self::INCOMPLETE : ($ids === [] ? self::EMPTY : null),
        ];
    }
}

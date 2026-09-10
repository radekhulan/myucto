<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Import;

use MyInvoice\Service\Http\OutboundRequestException;
use MyInvoice\Service\Http\OutboundResponse;
use MyInvoice\Service\Http\OutboundUrlGuard;

final class GuardedCatalogMediaFetcher implements CatalogMediaFetcher
{
    public const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_REDIRECTS = 3;

    /** @var (\Closure(string):OutboundResponse)|null */
    private readonly ?\Closure $request;

    public function __construct(
        private readonly OutboundUrlGuard $guard,
        ?callable $request = null,
    ) {
        $this->request = $request === null ? null : \Closure::fromCallable($request);
    }

    public function assertSyntax(string $url): void
    {
        try {
            $this->guard->assertSyntax($url);
        } catch (OutboundRequestException) {
            throw new CatalogMediaFetchException('media_url_blocked');
        }
    }

    public function fetch(string $url): array
    {
        $this->assertSyntax($url);
        $current = trim($url);
        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            try {
                $response = $this->request !== null
                    ? ($this->request)($current)
                    : $this->guard->request('GET', $current, ['Accept' => 'image/*,application/pdf'], timeout: 8, maxBytes: self::MAX_BYTES);
            } catch (OutboundRequestException $e) {
                if ($e->reason === OutboundRequestException::TARGET_BLOCKED) {
                    throw new CatalogMediaFetchException('media_url_blocked');
                }
                if ($e->reason === OutboundRequestException::SIZE_LIMIT) {
                    throw new CatalogMediaFetchException('media_file_too_large');
                }
                $code = str_contains(mb_strtolower($e->getMessage()), 'velikost') ? 'media_file_too_large' : 'media_download_failed';
                throw new CatalogMediaFetchException($code, $code === 'media_download_failed');
            }
            if ($response->status >= 300 && $response->status < 400) {
                $location = $response->header('location');
                if ($location === null || $location === '' || $redirects === self::MAX_REDIRECTS) {
                    throw new CatalogMediaFetchException('media_redirect_invalid');
                }
                $current = self::redirectUrl($current, $location);
                $this->assertSyntax($current);
                continue;
            }
            if ($response->status === 429 || $response->status >= 500) {
                throw new CatalogMediaFetchException('media_remote_unavailable', true);
            }
            if ($response->status !== 200) {
                throw new CatalogMediaFetchException('media_download_rejected');
            }
            if ($response->body === '') {
                throw new CatalogMediaFetchException('media_empty_file');
            }
            if (strlen($response->body) > self::MAX_BYTES) {
                throw new CatalogMediaFetchException('media_file_too_large');
            }
            return ['body' => $response->body, 'original_name' => self::originalName($current)];
        }
        throw new CatalogMediaFetchException('media_redirect_invalid');
    }

    public static function redirectUrl(string $base, string $location): string
    {
        $location = trim($location);
        if ($location === '' || str_starts_with($location, '//')) {
            throw new CatalogMediaFetchException('media_redirect_invalid');
        }
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new CatalogMediaFetchException('media_redirect_invalid');
        }
        $authority = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $relative = parse_url($location);
        if (!is_array($relative) || isset($relative['fragment'])) {
            throw new CatalogMediaFetchException('media_redirect_invalid');
        }
        $basePath = (string) ($parts['path'] ?? '/');
        if ($basePath === '') {
            $basePath = '/';
        }
        $relativePath = (string) ($relative['path'] ?? '');
        if ($relativePath === '') {
            $path = $basePath;
        } elseif (str_starts_with($relativePath, '/')) {
            $path = $relativePath;
        } else {
            $dir = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
            $path = $dir . $relativePath;
        }
        $path = self::normalizePath($path);
        $query = array_key_exists('query', $relative) ? '?' . $relative['query'] : '';
        return $authority . $path . $query;
    }

    private static function normalizePath(string $path): string
    {
        $trailingSlash = str_ends_with($path, '/');
        $segments = explode('/', $path);
        $normalized = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($normalized);
                continue;
            }
            $normalized[] = $segment;
        }
        $result = '/' . implode('/', $normalized);
        if ($trailingSlash && $result !== '/') {
            $result .= '/';
        }
        return $result;
    }

    private static function originalName(string $url): string
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $name = trim(basename(str_replace('\\', '/', $path)));
        if ($name === '' || $name === '.' || $name === '..' || mb_strlen($name) > 255) {
            return 'imported-media';
        }
        return preg_replace('/[\x00-\x1F\x7F]/', '_', $name) ?: 'imported-media';
    }
}

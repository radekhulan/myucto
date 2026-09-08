<?php

declare(strict_types=1);

namespace MyInvoice\Tooling;

use Closure;
use DOMDocument;
use DOMXPath;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class JmhzXsdDownloader
{
    private const OFFICIAL_HOST = 'developers.mpsv.cz';
    private const MAX_REDIRECTS = 5;
    private const MAX_ARCHIVE_BYTES = 50 * 1024 * 1024;

    /**
     * @var array<string,array{
     *     target:string,
     *     version:string,
     *     url:string,
     *     sha256:string,
     *     xsd_count:int,
     *     entry_points:list<string>,
     *     xsd_root:?string
     * }>
     */
    private array $packages;
    private ?Closure $logger;

    /** @param array<mixed> $packages */
    public function __construct(array $packages, ?callable $logger = null)
    {
        $this->packages = $this->validateManifest($packages);
        $this->logger = $logger === null ? null : Closure::fromCallable($logger);
    }

    public function downloadAndInstall(string $targetRoot): void
    {
        $tempRoot = rtrim(sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR
            . 'myucto-jmhz-xsd-'
            . bin2hex(random_bytes(8));
        if (!mkdir($tempRoot, 0700, true)) {
            throw new RuntimeException("Cannot create temporary directory {$tempRoot}.");
        }

        try {
            $archives = [];
            foreach ($this->packages as $id => $package) {
                $archive = $tempRoot . DIRECTORY_SEPARATOR . $id . '.zip';
                $this->log("Downloading {$id} {$package['version']}...");
                $this->download($package['url'], $archive);
                $archives[$id] = $archive;
            }

            $this->installFromArchives($archives, $targetRoot);
        } finally {
            $this->removeDirectory($tempRoot);
        }
    }

    /** @param array<string,string> $archives */
    public function installFromArchives(array $archives, string $targetRoot): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to install JMHZ schemas.');
        }

        foreach ($this->packages as $id => $package) {
            $archive = $archives[$id] ?? null;
            if (!is_string($archive) || !is_file($archive)) {
                throw new RuntimeException("Archive for package {$id} is missing.");
            }
            $actualHash = hash_file('sha256', $archive);
            if (!is_string($actualHash) || !hash_equals($package['sha256'], strtolower($actualHash))) {
                throw new RuntimeException("SHA-256 mismatch for JMHZ package {$id}.");
            }
        }

        $parent = dirname($targetRoot);
        if (!is_dir($parent) && !mkdir($parent, 0777, true)) {
            throw new RuntimeException("Cannot create target parent {$parent}.");
        }
        if (file_exists($targetRoot) && !is_dir($targetRoot)) {
            throw new RuntimeException("JMHZ schema target {$targetRoot} is not a directory.");
        }

        $suffix = bin2hex(random_bytes(8));
        $stage = $parent . DIRECTORY_SEPARATOR . '.' . basename($targetRoot) . '.stage-' . $suffix;
        if (!mkdir($stage, 0777, true)) {
            throw new RuntimeException("Cannot create staging directory {$stage}.");
        }

        try {
            if (is_dir($targetRoot)) {
                $this->copyXsdTree($targetRoot, $stage);
            }

            foreach ($this->packages as $id => $package) {
                $versionTarget = $stage
                    . DIRECTORY_SEPARATOR
                    . $package['target'];
                if (is_dir($versionTarget)) {
                    $this->removeDirectory($versionTarget);
                }
                if (!mkdir($versionTarget, 0777, true)) {
                    throw new RuntimeException("Cannot create package target {$versionTarget}.");
                }

                $count = $this->extractXsd(
                    $archives[$id],
                    $versionTarget,
                    $package['xsd_root'],
                );
                $this->validatePackageTree($id, $package, $versionTarget, $count);
                $this->log("Prepared {$id} {$package['version']}: {$count} XSD file(s).");
            }

            $this->writeContentManifest($stage);
            $this->replaceDirectory($stage, $targetRoot);
            $this->log("JMHZ schemas installed in {$targetRoot}.");
        } finally {
            if (is_dir($stage)) {
                $this->removeDirectory($stage);
            }
        }
    }

    /**
     * @param array<mixed> $packages
     * @return array<string,array{
     *     target:string,
     *     version:string,
     *     url:string,
     *     sha256:string,
     *     xsd_count:int,
     *     entry_points:list<string>,
     *     xsd_root:?string
     * }>
     */
    private function validateManifest(array $packages): array
    {
        if ($packages === []) {
            throw new RuntimeException('The JMHZ package manifest must not be empty.');
        }

        $targets = [];
        $validated = [];
        foreach ($packages as $id => $package) {
            if (!is_string($id) || !is_array($package)) {
                throw new RuntimeException('Invalid JMHZ package manifest entry.');
            }
            $target = $package['target'] ?? null;
            $version = $package['version'] ?? null;
            $sha256 = $package['sha256'] ?? null;
            $url = $package['url'] ?? null;
            $xsdCount = $package['xsd_count'] ?? null;
            $entryPoints = $package['entry_points'] ?? null;
            $xsdRoot = $package['xsd_root'] ?? null;
            if (
                preg_match('/\A[a-z0-9][a-z0-9-]*\z/D', $id) !== 1
                || !is_string($target)
                || preg_match('/\A[a-z0-9][a-z0-9.-]*\z/D', $target) !== 1
                || !is_string($version)
                || preg_match('/\A[0-9]+(?:\.[0-9]+)*\z/D', $version) !== 1
                || !is_string($sha256)
                || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1
                || !is_string($url)
                || !is_int($xsdCount)
                || $xsdCount < 1
                || $xsdCount > 500
                || !is_array($entryPoints)
                || $entryPoints === []
                || ($xsdRoot !== null && !$this->isSafeRelativeDirectory($xsdRoot))
            ) {
                throw new RuntimeException("Invalid JMHZ package manifest entry {$id}.");
            }
            $this->assertOfficialUrl($url, $id);

            if (isset($targets[$target])) {
                throw new RuntimeException("Duplicate JMHZ package target {$target}.");
            }
            $validatedEntryPoints = [];
            foreach ($entryPoints as $entryPoint) {
                if (
                    !is_string($entryPoint)
                    || !$this->isSafeRelativeXsdPath($entryPoint)
                    || isset($validatedEntryPoints[strtolower($entryPoint)])
                ) {
                    throw new RuntimeException("Invalid JMHZ package entry point in {$id}.");
                }
                $validatedEntryPoints[strtolower($entryPoint)] = $entryPoint;
            }
            $targets[$target] = true;
            $validated[$id] = [
                'target' => $target,
                'version' => $version,
                'url' => $url,
                'sha256' => $sha256,
                'xsd_count' => $xsdCount,
                'entry_points' => array_values($validatedEntryPoints),
                'xsd_root' => $xsdRoot === null ? null : trim($xsdRoot, '/'),
            ];
        }

        return $validated;
    }

    private function download(string $url, string $target): void
    {
        $currentUrl = $url;
        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $this->assertOfficialUrl($currentUrl, 'download');
            $context = stream_context_create([
                'http' => [
                    'follow_location' => 0,
                    'ignore_errors' => true,
                    'timeout' => 60,
                    'user_agent' => 'MyUcto JMHZ XSD downloader',
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'SNI_enabled' => true,
                ],
            ]);
            $input = @fopen($currentUrl, 'rb', false, $context);
            if ($input === false) {
                throw new RuntimeException("Cannot download {$currentUrl}.");
            }

            try {
                $headers = $this->responseHeaders($input);
                $status = $this->responseStatus($headers);
                if ($status >= 300 && $status < 400) {
                    $location = $this->redirectLocation($headers);
                    if ($location === null || $redirects === self::MAX_REDIRECTS) {
                        throw new RuntimeException("Unsafe or excessive redirect while downloading {$currentUrl}.");
                    }
                    $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);
                    continue;
                }
                if ($status !== 200) {
                    throw new RuntimeException("JMHZ download {$currentUrl} returned HTTP {$status}.");
                }

                $this->writeLimitedArchive($input, $target, $currentUrl);

                return;
            } finally {
                fclose($input);
            }
        }

        throw new RuntimeException("Too many redirects while downloading {$url}.");
    }

    /**
     * @param resource $stream
     * @return list<string>
     */
    private function responseHeaders($stream): array
    {
        $metadata = stream_get_meta_data($stream);
        $headers = $metadata['wrapper_data'] ?? [];
        if (is_string($headers)) {
            return [$headers];
        }
        if (!is_array($headers)) {
            return [];
        }
        $result = [];
        foreach ($headers as $header) {
            if (is_string($header)) {
                $result[] = $header;
            }
        }

        return $result;
    }

    /** @param list<string> $headers */
    private function responseStatus(array $headers): int
    {
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('/\AHTTP\/\S+\s+([0-9]{3})(?:\s|$)/i', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }

    /** @param list<string> $headers */
    private function redirectLocation(array $headers): ?string
    {
        $locations = [];
        foreach ($headers as $header) {
            if (preg_match('/\ALocation:\s*(\S.*?)\s*\z/i', $header, $matches) === 1) {
                $locations[] = $matches[1];
            }
        }

        return count($locations) === 1 ? $locations[0] : null;
    }

    private function resolveRedirectUrl(string $currentUrl, string $location): string
    {
        if (str_starts_with($location, '//')) {
            throw new RuntimeException("Protocol-relative JMHZ redirect is not allowed: {$location}.");
        }
        $scheme = parse_url($location, PHP_URL_SCHEME);
        if (is_string($scheme)) {
            $this->assertOfficialUrl($location, 'redirect');

            return $location;
        }

        $host = parse_url($currentUrl, PHP_URL_HOST);
        $path = parse_url($currentUrl, PHP_URL_PATH);
        if (!is_string($host) || !is_string($path)) {
            throw new RuntimeException("Cannot resolve JMHZ redirect {$location}.");
        }
        $redirectPath = str_starts_with($location, '/')
            ? $location
            : rtrim(str_replace('\\', '/', dirname($path)), '/') . '/' . $location;
        $resolved = 'https://' . $host . $redirectPath;
        $this->assertOfficialUrl($resolved, 'redirect');

        return $resolved;
    }

    /** @param resource $input */
    private function writeLimitedArchive($input, string $target, string $url): void
    {
        $output = @fopen($target, 'xb');
        if ($output === false) {
            throw new RuntimeException("Cannot create temporary archive {$target}.");
        }
        $written = 0;
        $complete = false;

        try {
            while (!feof($input)) {
                $chunk = fread($input, 64 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException("Download failed for {$url}.");
                }
                if ($chunk === '') {
                    $metadata = stream_get_meta_data($input);
                    if ($metadata['timed_out']) {
                        throw new RuntimeException("Download timed out for {$url}.");
                    }
                    continue;
                }
                $written += strlen($chunk);
                if ($written > self::MAX_ARCHIVE_BYTES) {
                    throw new RuntimeException("JMHZ archive {$url} exceeds the download size limit.");
                }
                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException("Cannot write temporary JMHZ archive {$target}.");
                }
            }
            $complete = true;
        } finally {
            fclose($output);
            if (!$complete && is_file($target)) {
                unlink($target);
            }
        }

        $signature = file_get_contents($target, false, null, 0, 4);
        if ($written < 4 || $signature !== "PK\x03\x04") {
            unlink($target);
            throw new RuntimeException("Downloaded JMHZ package {$url} is not a non-empty ZIP archive.");
        }
    }

    private function assertOfficialUrl(string $url, string $packageId): void
    {
        $parts = parse_url($url);
        $path = is_array($parts) ? ($parts['path'] ?? null) : null;
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== self::OFFICIAL_HOST
            || isset($parts['port'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !is_string($path)
            || preg_match(
                '/\A\/assets\/documents\/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\/[^\/]+\.zip\z/Di',
                $path,
            ) !== 1
            || in_array('..', explode('/', rawurldecode($path)), true)
        ) {
            throw new RuntimeException("JMHZ package {$packageId} must use an approved MPSV archive URL.");
        }
    }

    private function extractXsd(string $archive, string $target, ?string $xsdRoot): int
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException("Cannot open ZIP archive {$archive}.");
        }

        try {
            $entries = [];
            $totalSize = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new RuntimeException("Cannot inspect ZIP entry in {$archive}.");
                }
                $name = str_replace('\\', '/', (string) $stat['name']);
                if (str_ends_with($name, '/') || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xsd') {
                    continue;
                }
                $this->assertSafeArchivePath($name);
                $totalSize += (int) $stat['size'];
                if ($totalSize > 50 * 1024 * 1024 || count($entries) >= 500) {
                    throw new RuntimeException("JMHZ archive {$archive} exceeds safe extraction limits.");
                }
                $entries[] = ['index' => $index, 'name' => $name];
            }

            if ($entries === []) {
                throw new RuntimeException("JMHZ archive {$archive} contains no XSD files.");
            }

            $prefix = $this->commonTopLevelDirectory(array_column($entries, 'name'));
            $scope = $xsdRoot === null ? '' : $xsdRoot . '/';
            $destinations = [];
            $extracted = 0;
            foreach ($entries as $entry) {
                $relative = $prefix === '' ? $entry['name'] : substr($entry['name'], strlen($prefix) + 1);
                if ($scope !== '') {
                    if (!str_starts_with($relative, $scope)) {
                        continue;
                    }
                    $relative = substr($relative, strlen($scope));
                }
                $key = strtolower($relative);
                if ($relative === '' || isset($destinations[$key])) {
                    throw new RuntimeException("Duplicate XSD path {$relative} in {$archive}.");
                }
                $destinations[$key] = true;

                $contents = $zip->getFromIndex($entry['index']);
                if (!is_string($contents) || trim($contents) === '') {
                    throw new RuntimeException("Cannot read XSD {$entry['name']} from {$archive}.");
                }
                if (!$this->isXsdDocument($contents)) {
                    throw new RuntimeException("ZIP entry {$entry['name']} is not an XSD document.");
                }

                $destination = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $directory = dirname($destination);
                if (!is_dir($directory) && !mkdir($directory, 0777, true)) {
                    throw new RuntimeException("Cannot create XSD directory {$directory}.");
                }
                if (file_put_contents($destination, $contents, LOCK_EX) !== strlen($contents)) {
                    throw new RuntimeException("Cannot write XSD file {$destination}.");
                }
                $extracted++;
            }

            if ($extracted === 0) {
                throw new RuntimeException(
                    "JMHZ archive {$archive} contains no XSD files under {$scope}.",
                );
            }

            return $extracted;
        } finally {
            $zip->close();
        }
    }

    /**
     * @param array{
     *     target:string,
     *     version:string,
     *     url:string,
     *     sha256:string,
     *     xsd_count:int,
     *     entry_points:list<string>,
     *     xsd_root:?string
     * } $package
     */
    private function validatePackageTree(string $id, array $package, string $target, int $extractedCount): void
    {
        if ($extractedCount !== $package['xsd_count']) {
            throw new RuntimeException(
                "JMHZ package {$id} contains {$extractedCount} XSD files; expected {$package['xsd_count']}.",
            );
        }

        $root = realpath($target);
        if ($root === false) {
            throw new RuntimeException("Cannot resolve extracted JMHZ package {$id}.");
        }
        foreach ($package['entry_points'] as $entryPoint) {
            $path = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entryPoint);
            if (!is_file($path)) {
                throw new RuntimeException("JMHZ package {$id} is missing entry point {$entryPoint}.");
            }
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
        );
        $validatedCount = 0;
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || $item->isLink() || !$item->isFile()) {
                throw new RuntimeException("JMHZ package {$id} contains an unsupported filesystem entry.");
            }
            if (strtolower($item->getExtension()) !== 'xsd') {
                throw new RuntimeException("JMHZ package {$id} contains a non-XSD file.");
            }
            $this->validateSchemaDocument($id, $item->getPathname(), $root);
            $validatedCount++;
        }
        if ($validatedCount !== $package['xsd_count']) {
            throw new RuntimeException("JMHZ package {$id} XSD count changed during validation.");
        }
    }

    private function validateSchemaDocument(string $id, string $path, string $packageRoot): void
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = false;
        $errors = [];
        try {
            $loaded = $document->load($path, LIBXML_NONET);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || $document->documentElement?->namespaceURI !== 'http://www.w3.org/2001/XMLSchema') {
            $detail = implode(
                '; ',
                array_map(static fn (\LibXMLError $error): string => trim($error->message), $errors),
            );
            throw new RuntimeException("Invalid XSD in JMHZ package {$id}: {$path}. {$detail}");
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
        $locations = $xpath->query(
            '//xs:include/@schemaLocation | //xs:import/@schemaLocation | //xs:redefine/@schemaLocation',
        );
        if ($locations === false) {
            throw new RuntimeException("Cannot inspect XSD dependencies in JMHZ package {$id}: {$path}.");
        }
        $rootPrefix = strtolower(rtrim($packageRoot, '/\\') . DIRECTORY_SEPARATOR);
        foreach ($locations as $location) {
            $relative = trim((string) $location->nodeValue);
            if (!$this->isSafeRelativeXsdPath($relative)) {
                throw new RuntimeException("JMHZ package {$id} has an unsafe XSD dependency {$relative}.");
            }
            $dependency = realpath(dirname($path) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
            if (
                $dependency === false
                || !is_file($dependency)
                || !str_starts_with(strtolower($dependency), $rootPrefix)
            ) {
                throw new RuntimeException("JMHZ package {$id} has a missing or external XSD dependency {$relative}.");
            }
        }
    }

    private function isSafeRelativeXsdPath(string $path): bool
    {
        if (
            $path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || parse_url($path, PHP_URL_SCHEME) !== null
            || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xsd'
        ) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($this->isUnsafePathSegment($segment)) {
                return false;
            }
        }

        return preg_match('/[\x00-\x1F\x7F]/', $path) !== 1;
    }

    /**
     * Podadresář archivu, ze kterého se schémata berou (`xsd_root`).
     *
     * Oficiální balíček JMHZ od verze 1.4.3.5 veze vedle vnějších schémat
     * i vnitřní (`interni_xsd`) a k tomu vygenerovanou HTML dokumentaci.
     * Vnitřní schémata se odkazují přes `../externi_xsd/…`, takže by je
     * kontrola závislostí odmítla — a připínat je nemá smysl, aplikace proti
     * nim nic nevaliduje. Připíná se proto jen podstrom s vnějšími schématy;
     * uložený tvar tím zůstane plochý jako u starších balíčků.
     *
     * Stejná pravidla jako u cest v archivu, jen bez povinné přípony.
     */
    private function isSafeRelativeDirectory(mixed $path): bool
    {
        if (
            !is_string($path)
            || $path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || str_ends_with($path, '/')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
        ) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($this->isUnsafePathSegment($segment)) {
                return false;
            }
        }

        return true;
    }

    private function isXsdDocument(string $contents): bool
    {
        if (str_starts_with($contents, "\xFF\xFE")) {
            $contents = mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($contents, "\xFE\xFF")) {
            $contents = mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16BE');
        }

        $start = substr(ltrim($contents, "\xEF\xBB\xBF \t\r\n"), 0, 4096);

        return preg_match('/<(?:(?:xs|xsd):)?schema(?:\s|>)/i', $start) === 1;
    }

    private function assertSafeArchivePath(string $path): void
    {
        if (
            $path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || preg_match('/\A[A-Za-z]:\//', $path) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
        ) {
            throw new RuntimeException("ZIP entry has an unsafe path: {$path}.");
        }
        foreach (explode('/', $path) as $segment) {
            if ($this->isUnsafePathSegment($segment)) {
                throw new RuntimeException(
                    "ZIP entry has an unsafe path: {$path}.",
                );
            }
        }
    }

    private function isUnsafePathSegment(string $segment): bool
    {
        return $segment === ''
            || $segment === '.'
            || $segment === '..'
            || str_contains($segment, ':')
            || str_ends_with($segment, '.')
            || str_ends_with($segment, ' ')
            || preg_match(
                '/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|\z)/i',
                $segment,
            ) === 1;
    }

    /** @param list<string> $paths */
    private function commonTopLevelDirectory(array $paths): string
    {
        $first = null;
        foreach ($paths as $path) {
            $parts = explode('/', $path);
            if (count($parts) < 2) {
                return '';
            }
            if ($first === null) {
                $first = $parts[0];
            } elseif ($first !== $parts[0]) {
                return '';
            }
        }

        return $first ?? '';
    }

    private function copyXsdTree(string $source, string $target): void
    {
        $items = new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                throw new RuntimeException("Cannot inspect JMHZ schema storage {$source}.");
            }
            if ($item->isLink()) {
                throw new RuntimeException("Symlinks are not allowed in JMHZ schema storage: {$item->getPathname()}.");
            }
            $destination = $target . DIRECTORY_SEPARATOR . $item->getFilename();
            if ($item->isDir()) {
                if (!mkdir($destination, 0777, true)) {
                    throw new RuntimeException("Cannot create staging directory {$destination}.");
                }
                $this->copyXsdTree($item->getPathname(), $destination);
            } elseif (
                in_array(strtolower($item->getExtension()), ['xsd', 'md'], true)
                && !copy($item->getPathname(), $destination)
            ) {
                throw new RuntimeException("Cannot copy existing schema metadata {$item->getPathname()}.");
            }
        }
    }

    private function writeContentManifest(string $root): void
    {
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false) {
            throw new RuntimeException("Cannot resolve JMHZ schema staging directory {$root}.");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        $rootPrefix = rtrim($resolvedRoot, '/\\') . DIRECTORY_SEPARATOR;
        $entries = [];
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                continue;
            }
            if ($item->isLink()) {
                throw new RuntimeException("Symlinks are not allowed in JMHZ schema storage: {$item->getPathname()}.");
            }
            if (strtolower($item->getExtension()) !== 'xsd') {
                continue;
            }

            $itemPath = $item->getRealPath();
            if (
                $itemPath === false
                || !str_starts_with(strtolower($itemPath), strtolower($rootPrefix))
            ) {
                throw new RuntimeException("Cannot resolve JMHZ schema path {$item->getPathname()}.");
            }
            $relative = substr($itemPath, strlen($rootPrefix));
            if ($relative === '') {
                throw new RuntimeException("Cannot determine relative JMHZ schema path for {$itemPath}.");
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $hash = hash_file('sha256', $itemPath);
            if (!is_string($hash)) {
                throw new RuntimeException("Cannot hash JMHZ schema {$itemPath}.");
            }
            $entries[$relative] = $hash;
        }
        if ($entries === []) {
            throw new RuntimeException('Cannot write an empty JMHZ schema content manifest.');
        }

        ksort($entries, SORT_STRING);
        $lines = [];
        foreach ($entries as $relative => $hash) {
            $lines[] = $hash . '  ' . $relative;
        }
        $contents = implode("\n", $lines) . "\n";
        $path = $root . DIRECTORY_SEPARATOR . 'SHA256SUMS';
        if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException("Cannot write JMHZ schema content manifest {$path}.");
        }
    }

    private function replaceDirectory(string $stage, string $target): void
    {
        $backup = dirname($target)
            . DIRECTORY_SEPARATOR
            . '.'
            . basename($target)
            . '.backup-'
            . bin2hex(random_bytes(8));
        $hadTarget = is_dir($target);

        if ($hadTarget && !@rename($target, $backup)) {
            throw new RuntimeException("Cannot move current JMHZ schemas to {$backup}.");
        }

        if (!@rename($stage, $target)) {
            if ($hadTarget && !@rename($backup, $target)) {
                throw new RuntimeException("Cannot install JMHZ schemas and rollback also failed; backup is {$backup}.");
            }
            throw new RuntimeException("Cannot atomically install JMHZ schemas in {$target}.");
        }

        if ($hadTarget) {
            $this->removeDirectory($backup);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                throw new RuntimeException("Cannot inspect temporary directory {$path}.");
            }
            if ($item->isDir() && !$item->isLink()) {
                $this->removeDirectory($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}

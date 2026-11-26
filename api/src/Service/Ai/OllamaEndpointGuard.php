<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use MyInvoice\Service\IpMatcher;

/**
 * SSRF guard pro adresu Ollamy, kterou zadává admin firmy. Volá se při uložení
 * i před každým requestem (DNS se mezitím může změnit):
 *
 *  - adresa smí být jen `http(s)://host[:port]` — cesty volá klient sám, pevně;
 *  - host se přeloží na všechny IP a žádná nesmí ležet v link-local / metadata /
 *    multicast / unspecified rozsahu;
 *  - volitelný allowlist `MYINVOICE_OLLAMA_ALLOWED_HOSTS` (hostname nebo CIDR); ve spravované
 *    instalaci je povinný, jinak by admin firmy mohl posílat requesty na loopback a interní
 *    síť hostitele, kde běží i ostatní instance;
 *  - region 'eu' jen když VŠECHNY adresy jsou loopback/privátní, jinak fail-closed 'us';
 *  - klient se připojí na ověřenou IP (CURLOPT_RESOLVE), takže DNS rebinding
 *    mezi kontrolou a requestem kontrolu neobejde.
 */
final class OllamaEndpointGuard
{
    public const ERR_INVALID     = 'ollama_endpoint_invalid';
    public const ERR_BLOCKED     = 'ollama_endpoint_blocked';
    public const ERR_UNREACHABLE = 'ollama_unreachable';
    public const ENV_ALLOWED_HOSTS = 'MYINVOICE_OLLAMA_ALLOWED_HOSTS';

    /**
     * Kontroluje se dřív než LOCAL. Metadata cloudu mimo link-local: AWS a GCP přes
     * IPv6 (`fd00:ec2::254`, `fd20:ce::254`) leží v ULA (fc00::/7), Alibaba
     * `100.100.100.200` v CGNAT a Azure WireServer `168.63.129.16` ve veřejném rozsahu.
     */
    private const BLOCKED = ['0.0.0.0/8', '169.254.0.0/16', '224.0.0.0/4', '240.0.0.0/4', '::/128', 'fe80::/10', 'ff00::/8', '64:ff9b::/96',
        'fd00:ec2::254/128', 'fd20:ce::254/128', '100.100.100.200/32', '168.63.129.16/32'];
    private const LOCAL   = ['127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '::1/128', 'fc00::/7'];

    /** @var callable(string): list<string> */
    private $resolver;
    private readonly IpMatcher $ipMatcher;

    /**
     * @param (callable(string): list<string>)|null $resolver test seam; null = systémové DNS
     * @param string|null $allowedHosts null = číst z env při každém resolve
     */
    public function __construct(
        ?callable $resolver = null,
        private readonly ?string $allowedHosts = null,
        private readonly bool $managed = false,
    )
    {
        $this->resolver  = $resolver ?? self::systemResolver(...);
        $this->ipMatcher = new IpMatcher();
    }

    /**
     * Striktní vlastní parser místo parse_url: parse_url a libcurl se na okrajových případech
     * (zpětné lomítko, `#@`, procenta, prázdný port, zkrácené IPv4) rozcházejí a guard by pak
     * ověřil jiný host, než ke kterému se curl připojí.
     */
    public function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7f-\xff\\\\%]/', $url) === 1 || substr_count($url, '@') > 1) {
            throw new OllamaEndpointException(self::ERR_INVALID);
        }
        if (preg_match('~^(https?)://(\[[0-9a-fA-F:.]+\]|[^/:\[\]@?#]+)(?::([0-9]+))?/?$~i', $url, $m) !== 1) {
            throw new OllamaEndpointException(self::ERR_INVALID);
        }
        $scheme = strtolower($m[1]);
        $host   = strtolower($m[2]);
        if ($host[0] === '[') {
            if (filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new OllamaEndpointException(self::ERR_INVALID);
            }
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            // Hostname; číselná koncovka (127.1, 2130706433) by curl vyložil jako IP.
            $label = substr($host, (int) strrpos($host, '.') + (str_contains($host, '.') ? 1 : 0));
            // curl (ipv4_normalize) bere za IP i hex/oktalové/číselné labely: 0x7f.0.0.0x1, 0x7f000001, 0177.0.0.1.
            $allNumeric = true;
            foreach (explode('.', $host) as $l) {
                $allNumeric = $allNumeric && preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', $l) === 1;
            }
            if (preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) !== 1
                || ctype_digit($label) || $allNumeric) {
                throw new OllamaEndpointException(self::ERR_INVALID);
            }
        }
        $port = '';
        if (isset($m[3]) && $m[3] !== '') {
            if ((int) $m[3] < 1 || (int) $m[3] > 65535 || strlen($m[3]) > 5) {
                throw new OllamaEndpointException(self::ERR_INVALID);
            }
            $port = ':' . (int) $m[3];
        }
        return $scheme . '://' . $host . $port;
    }

    public function normalizeForStorage(string $url): string
    {
        $base = $this->normalize($url);
        $host = trim((string) parse_url($base, PHP_URL_HOST), '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->resolve($base);
        }
        return $base;
    }

    public function resolve(string $url): OllamaEndpoint
    {
        $base  = $this->normalize($url);
        $parts = (array) parse_url($base);
        $host  = trim((string) $parts['host'], '[]');
        $port  = (int) ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $raw = [$host];
        } else {
            $raw = ($this->resolver)($host);
        }
        $ips = [];
        foreach ($raw as $ip) {
            $ip = self::unmapIpv4((string) $ip);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $ip;
            }
        }
        if ($ips === []) {
            throw new OllamaEndpointException(self::ERR_UNREACHABLE);
        }
        foreach ($ips as $ip) {
            if ($this->ipMatcher->matches($ip, self::BLOCKED)) {
                throw new OllamaEndpointException(self::ERR_BLOCKED);
            }
        }
        if (!$this->allowed($host, $ips)) {
            throw new OllamaEndpointException(self::ERR_BLOCKED);
        }
        $local = true;
        foreach ($ips as $ip) {
            $local = $local && $this->ipMatcher->matches($ip, self::LOCAL);
        }
        return new OllamaEndpoint($base, $host, $port, $ips[0], $local ? 'eu' : 'us');
    }

    /** @param list<string> $ips */
    private function allowed(string $host, array $ips): bool
    {
        $raw = $this->allowedHosts ?? (string) (getenv(self::ENV_ALLOWED_HOSTS) ?: '');
        $entries = array_values(array_filter(
            array_map(static fn (string $e): string => strtolower(trim($e)), explode(',', $raw)),
            static fn (string $e): bool => $e !== '',
        ));
        if ($entries === []) {
            return !$this->managed;
        }
        foreach ($entries as $entry) {
            if (!str_contains($entry, '/')) {
                if ($entry === $host) {
                    return true;
                }
                continue;
            }
            $all = true;
            foreach ($ips as $ip) {
                $all = $all && $this->ipMatcher->matches($ip, [$entry]);
            }
            if ($all) {
                return true;
            }
        }
        return false;
    }

    /** IPv4 schovaná v IPv6 zápisu: textově (::ffff:a.b.c.d, ::a.b.c.d) i hexadecimálně (::ffff:a9fe:a9fe, ::a9fe:a9fe). */
    private static function unmapIpv4(string $ip): string
    {
        // Nejdřív binárně přes inet_pton — pokryje všechny zápisy téže adresy.
        $binary = @inet_pton($ip);
        if (is_string($binary) && strlen($binary) === 16) {
            // Prvních 10 bajtů nulových = kandidát na IPv4 v IPv6.
            if (substr($binary, 0, 10) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00") {
                $bytes11_12 = substr($binary, 10, 2);
                $last4bytes = substr($binary, 12, 4);

                // IPv4-mapped: ::ffff:a.b.c.d
                if ($bytes11_12 === "\xff\xff") {
                    $v4 = @inet_ntop($last4bytes);
                    if ($v4 !== false) {
                        return $v4;
                    }
                }
                // IPv4-compatible: ::a.b.c.d (kromě :: a ::1)
                elseif ($bytes11_12 === "\x00\x00" && $last4bytes !== "\x00\x00\x00\x00" && $last4bytes !== "\x00\x00\x00\x01") {
                    $v4 = @inet_ntop($last4bytes);
                    if ($v4 !== false) {
                        return $v4;
                    }
                }
            }
        }

        // Záloha pro textové zápisy, které inet_pton nepřijme.
        $lower = strtolower($ip);
        if (str_starts_with($lower, '::ffff:')) {
            $v4 = substr($ip, 7);
            if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $v4;
            }
        } elseif (str_starts_with($lower, '::') && strlen($ip) > 2) {
            $v4 = substr($ip, 2);
            if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $v4;
            }
        }

        return $ip;
    }

    /** @return list<string> */
    private static function systemResolver(string $host): array
    {
        $ips  = @gethostbynamel($host) ?: [];
        $aaaa = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($aaaa) ? $aaaa : [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = (string) $record['ipv6'];
            }
        }
        return array_values(array_unique($ips));
    }
}

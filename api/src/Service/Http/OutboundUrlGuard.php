<?php

declare(strict_types=1);

namespace MyInvoice\Service\Http;

/**
 * Anti-SSRF guard pro odchozí HTTP — sdílený pro TSA razítka (SEC-04) i stahování
 * Fakturoid příloh (SEC-13). Filozofie je fail-closed: co není prokazatelně veřejný
 * HTTPS cíl, se neodešle.
 *
 * Co guard vynucuje:
 *   - jen schéma `https` (curl navíc omezen přes CURLOPT_PROTOCOLS_STR),
 *   - žádné userinfo (`user:pass@host`) ani fragment (`#…`) — obojí se používá
 *     k obcházení naivních host kontrol,
 *   - volitelný allowlist přesných hostů (TSA allowlist, Fakturoid download hosty),
 *   - všechny A i AAAA adresy hostu musí být veřejné; stačí jediná neveřejná
 *     adresa a požadavek se neodešle vůbec (round-robin nesmí být loterie),
 *   - spojení jde na ověřenou IP přes CURLOPT_RESOLVE → mezi kontrolou a spojením
 *     už DNS nerozhoduje (DNS rebinding),
 *   - redirecty jsou vypnuté; hop na jiný origin by obcházel allowlist i IP kontrolu,
 *   - TLS verifikace zůstává zapnutá (peer i host),
 *   - tělo se čte streamovaně přes write callback a nad limitem se přenos utne.
 */
final class OutboundUrlGuard
{
    /** Výchozí strop odpovědi (8 MiB) — příloha dokladu se do toho vejde s rezervou. */
    public const DEFAULT_MAX_BYTES = 8 * 1024 * 1024;

    private const DEFAULT_TIMEOUT = 10;
    private const CONNECT_TIMEOUT = 5;
    private const MAX_URL_LENGTH = 2048;

    /**
     * IPv4 rozsahy, na které se nikdy nepřipojíme.
     * 169.254.0.0/16 pokrývá cloud metadata (169.254.169.254 na AWS/Azure/GCP),
     * 100.64.0.0/10 pokrývá CGNAT včetně Alibaba metadata 100.100.100.200.
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',          // "this network"
        '10.0.0.0/8',         // RFC 1918
        '100.64.0.0/10',      // CGNAT (RFC 6598)
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local + cloud metadata
        '172.16.0.0/12',      // RFC 1918
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // TEST-NET-1
        '192.31.196.0/24',    // AS112
        '192.52.193.0/24',    // AMT
        '192.88.99.0/24',     // 6to4 relay anycast
        '192.168.0.0/16',     // RFC 1918
        '192.175.48.0/24',    // AS112 direct delegation
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // TEST-NET-2
        '203.0.113.0/24',     // TEST-NET-3
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved + 255.255.255.255 broadcast
    ];

    /**
     * IPv6 rozsahy. `fc00::/7` pokrývá ULA včetně AWS metadata `fd00:ec2::254`,
     * `::ffff:0:0/96` je pojistka — mapované adresy se normalizují na IPv4 dřív.
     */
    private const BLOCKED_V6 = [
        '::/128',             // unspecified
        '::1/128',            // loopback
        '::ffff:0:0/96',      // IPv4-mapped
        '64:ff9b::/96',       // NAT64
        '100::/64',           // discard-only
        '2001::/32',          // Teredo
        '2001:20::/28',       // ORCHIDv2
        '2001:db8::/32',      // dokumentace
        '2002::/16',          // 6to4
        'fc00::/7',           // ULA + cloud metadata
        'fe80::/10',          // link-local
        'ff00::/8',           // multicast
    ];

    /**
     * Ověří URL a vrátí cíl s rozřešenými IP adresami.
     *
     * @param list<string> $allowedHosts Prázdné = jen obecná pravidla; jinak musí host
     *                                   přesně (case-insensitive) odpovídat některé položce.
     * @throws OutboundRequestException Cokoli podezřelého → odmítnutí.
     */
    public function validate(string $url, array $allowedHosts = []): OutboundTarget
    {
        ['url' => $url, 'host' => $host, 'port' => $port, 'literal' => $isIpLiteral]
            = $this->parseAndCheck($url, $allowedHosts);

        $ips = $this->resolve($host);
        if ($ips === []) {
            throw new OutboundRequestException('Host se nepodařilo přeložit na IP adresu.', OutboundRequestException::DNS_UNAVAILABLE);
        }
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                // Nehlásíme konkrétní IP — nechceme z chybové hlášky udělat skener sítě.
                throw new OutboundRequestException('Cíl míří na neveřejnou nebo vyhrazenou adresu.', OutboundRequestException::TARGET_BLOCKED);
            }
        }

        return new OutboundTarget($url, $host, $port, $ips, $isIpLiteral);
    }

    /**
     * Kontrola bez DNS dotazu — pro validaci při ukládání konfigurace (TSA URL v profilu).
     * DNS se stejně musí ověřit až těsně před spojením (rebinding), takže tady jen
     * zachytíme zjevně vadný vstup a nezablokujeme uložení kvůli výpadku resolveru.
     *
     * @param list<string> $allowedHosts
     * @throws OutboundRequestException
     */
    public function assertSyntax(string $url, array $allowedHosts = []): void
    {
        $parsed = $this->parseAndCheck($url, $allowedHosts);
        // IP literál umíme posoudit i bez resolveru — rovnou odmítni loopback/private.
        if ($parsed['literal'] && !$this->isPublicIp($parsed['host'])) {
            throw new OutboundRequestException('Cíl míří na neveřejnou nebo vyhrazenou adresu.', OutboundRequestException::TARGET_BLOCKED);
        }
    }

    /**
     * Společná část validace bez síťového dotazu.
     *
     * @param list<string> $allowedHosts
     * @return array{url:string, host:string, port:int, literal:bool}
     */
    private function parseAndCheck(string $url, array $allowedHosts): array
    {
        $url = trim($url);
        if ($url === '') {
            throw new OutboundRequestException('URL je prázdná.');
        }
        if (strlen($url) > self::MAX_URL_LENGTH) {
            throw new OutboundRequestException('URL je příliš dlouhá.');
        }
        // Řídicí znaky a mezery umožňují request splitting i oklamání parseru.
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw new OutboundRequestException('URL obsahuje nepovolené znaky.');
        }
        // Fragment kontrolujeme na syrovém řetězci — parse_url() prázdný `#` zahodí.
        if (str_contains($url, '#')) {
            throw new OutboundRequestException('URL nesmí obsahovat fragment (#).');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new OutboundRequestException('URL není platná absolutní adresa.');
        }
        if (strtolower((string) $parts['scheme']) !== 'https') {
            throw new OutboundRequestException('Povoleno je pouze https://.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new OutboundRequestException('URL nesmí obsahovat přihlašovací údaje (user:pass@).');
        }

        $host = strtolower((string) $parts['host']);
        // IPv6 literál v URL je v hranatých závorkách — parse_url je občas ponechá.
        $host = trim($host, '[]');
        if ($host === '') {
            throw new OutboundRequestException('URL neobsahuje host.');
        }
        // Koncová tečka ("host." == "host") by rozbila porovnání s allowlistem.
        $host = rtrim($host, '.');

        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($port < 1 || $port > 65535) {
            throw new OutboundRequestException('Neplatný port.');
        }

        $isIpLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$isIpLiteral && preg_match('/^[a-z0-9]([a-z0-9\-.]{0,251}[a-z0-9])?$/', $host) !== 1) {
            throw new OutboundRequestException('Neplatné jméno hostu.');
        }

        if ($allowedHosts !== []) {
            $normalized = array_map(
                static fn (string $h): string => rtrim(strtolower(trim($h)), '.'),
                $allowedHosts
            );
            if (!in_array($host, $normalized, true)) {
                throw new OutboundRequestException('Host není na seznamu povolených cílů.');
            }
        }

        return ['url' => $url, 'host' => $host, 'port' => $port, 'literal' => $isIpLiteral];
    }

    /**
     * Provede požadavek na ověřený cíl. Tělo se sbírá streamovaně a nad `maxBytes`
     * se přenos utne (nikdy se do paměti nenačte víc, než dovolíme).
     *
     * @param array<string,string> $headers
     * @param list<string>         $allowedHosts
     * @param string|null          $basicAuth `user:heslo` — pošle se JEN na ověřený cíl.
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        array $allowedHosts = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
        ?string $basicAuth = null,
    ): OutboundResponse {
        $target = $this->validate($url, $allowedHosts);

        $ch = curl_init($target->url);
        if ($ch === false) {
            throw new OutboundRequestException('Nelze inicializovat HTTP klienta.');
        }

        $buffer = '';
        $overflow = false;
        /** @var array<string,string> $respHeaders */
        $respHeaders = [];

        $opts = [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => false, // tělo bereme výhradně přes write callback
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => false, // redirect by obešel allowlist i IP kontrolu
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT, $timeout),
            CURLOPT_MAXFILESIZE    => $maxBytes, // uřízne podle Content-Length, pokud ho server pošle
            CURLOPT_WRITEFUNCTION  => static function ($handle, string $chunk) use (&$buffer, &$overflow, $maxBytes): int {
                $len = strlen($chunk);
                if (strlen($buffer) + $len > $maxBytes) {
                    $overflow = true;
                    return 0; // != délka chunku → curl přenos přeruší
                }
                $buffer .= $chunk;
                return $len;
            },
            // Hlavičky sbíráme kvůli volajícím, kteří musí 3xx obsloužit sami
            // (guard redirecty zásadně nenásleduje — hop by obešel allowlist).
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$respHeaders): int {
                $len = strlen($line);
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $name = strtolower(trim(substr($line, 0, $colon)));
                    if ($name !== '') {
                        $respHeaders[$name] = trim(substr($line, $colon + 1));
                    }
                }
                return $len;
            },
        ];

        // Tvrdý zámek protokolu na úrovni libcurl (file://, gopher://, dict:// apod.).
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $opts[CURLOPT_PROTOCOLS_STR] = 'https';
            $opts[CURLOPT_REDIR_PROTOCOLS_STR] = 'https';
        } else {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        // Připoj se na už ověřené IP; hostname zůstává pro SNI i validaci certifikátu.
        if (!$target->hostIsIpLiteral) {
            $opts[CURLOPT_RESOLVE] = [$target->host . ':' . $target->port . ':' . implode(',', $target->ips)];
        }

        if ($headers !== []) {
            $lines = [];
            foreach ($headers as $name => $value) {
                $lines[] = $name . ': ' . $value;
            }
            $opts[CURLOPT_HTTPHEADER] = $lines;
        }
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        if ($basicAuth !== null && $basicAuth !== '') {
            $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $opts[CURLOPT_USERPWD] = $basicAuth;
        }

        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) (curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');
        curl_close($ch);

        $failure = self::transferFailureException($ok !== false, $overflow, $errno, $err);
        if ($failure !== null) {
            throw $failure;
        }

        return new OutboundResponse($status, $buffer, $contentType, $respHeaders);
    }

    /**
     * Vyhodnotí výsledek přenosu. Vytažené jako statická metoda, aby se dalo
     * otestovat bez skutečného síťového spojení.
     *
     * `$transferOk === false` (tj. `curl_exec()` vrátil false) znamená chybu KDYKOLI
     * během přenosu — včetně timeoutu nebo resetu UPROSTŘED těla, kdy už status kód
     * z hlaviček známe. Dřívější podmínka `$ok === false && $status === 0` proto
     * useknutou odpověď propustila jako platnou a volající (TSA token, PDF příloha)
     * dostal neúplná data. Fail-closed: jakákoli chyba přenosu = výjimka.
     *
     * @return string|null Chybová hláška, nebo null když je odpověď kompletní.
     */
    public static function transferFailureReason(bool $transferOk, bool $overflow, string $curlError): ?string
    {
        if ($overflow) {
            return 'Odpověď překročila povolenou velikost.';
        }
        if (!$transferOk) {
            return 'Spojení selhalo: ' . ($curlError !== '' ? $curlError : 'přenos byl přerušen');
        }
        return null;
    }

    public static function transferFailureException(
        bool $transferOk,
        bool $overflow,
        int $curlErrno,
        string $curlError,
    ): ?OutboundRequestException {
        $message = self::transferFailureReason($transferOk, $overflow, $curlError);
        if ($message === null) {
            return null;
        }
        if ($overflow || $curlErrno === CURLE_FILESIZE_EXCEEDED) {
            return new OutboundRequestException($message, OutboundRequestException::SIZE_LIMIT);
        }
        return new OutboundRequestException($message);
    }

    /**
     * Vrátí všechny A i AAAA adresy hostu. IP literál se vrací beze změny —
     * i tak projde kontrolou rozsahů v {@see validate()}.
     *
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
        if ($ips === []) {
            // Fallback na resolver systému (dns_get_record nečte hosts soubor).
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
        }

        return array_values(array_unique($ips));
    }

    /** True jen pro adresy mimo všechny blokované rozsahy. Neparsovatelná adresa = neveřejná. */
    public function isPublicIp(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        // IPv4-mapped (::ffff:1.2.3.4) posuzuj jako IPv4, jinak by 127.0.0.1 prošla.
        if (strlen($bin) === 16 && str_starts_with($bin, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
            $bin = substr($bin, 12);
        }

        $ranges = strlen($bin) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6;
        foreach ($ranges as $cidr) {
            if ($this->inCidr($bin, $cidr)) {
                return false;
            }
        }
        return true;
    }

    /** Porovná binární adresu (výstup inet_pton) s CIDR blokem stejné rodiny. */
    private function inCidr(string $bin, string $cidr): bool
    {
        [$net, $prefixStr] = explode('/', $cidr, 2);
        $netBin = @inet_pton($net);
        if ($netBin === false || strlen($netBin) !== strlen($bin)) {
            return false;
        }

        $bits = (int) $prefixStr;
        $fullBytes = intdiv($bits, 8);
        $restBits = $bits % 8;

        if ($fullBytes > 0 && strncmp($bin, $netBin, $fullBytes) !== 0) {
            return false;
        }
        if ($restBits === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $restBits)) & 0xFF);
        return ($bin[$fullBytes] & $mask) === ($netBin[$fullBytes] & $mask);
    }
}

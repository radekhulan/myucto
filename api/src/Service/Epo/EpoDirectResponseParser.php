<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

use MyInvoice\Infrastructure\Config\RuntimePaths;

final class EpoDirectResponseParser
{
    /** @var list<string> */
    private readonly array $signerFingerprints;

    /** @var list<string> */
    private readonly array $testSignerFingerprints;

    /**
     * @param list<string> $signerFingerprints
     * @param list<string> $testSignerFingerprints
     */
    public function __construct(
        private readonly ?string $caBundlePath = null,
        array $signerFingerprints = [],
        array $testSignerFingerprints = [],
    ) {
        $this->signerFingerprints = $this->normalizeFingerprints($signerFingerprints);
        $this->testSignerFingerprints = $this->normalizeFingerprints($testSignerFingerprints);
    }

    /** @param list<mixed> $fingerprints @return list<string> */
    private function normalizeFingerprints(array $fingerprints): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $fingerprint): string => strtolower(preg_replace(
                '/[^a-f0-9]/i',
                '',
                is_string($fingerprint) ? $fingerprint : '',
            ) ?? ''),
            $fingerprints,
        ), static fn (string $fingerprint): bool => strlen($fingerprint) === 64)));
    }

    /**
     * Typy zpráv EPO, které BRÁNÍ podání. `N` = nepropustná chyba; chyběla tu kdysi,
     * takže zkušební podání s nepropustnou vadou se hlásilo jako prošlé — přesně to,
     * co ostrý EPO nepřijme.
     *
     * Co tu ZÁMĚRNĚ NENÍ: `P` (propustná) a `I` (informativní). Ty podání nebrání —
     * např. chyby č. 58 (chybí kód státu) a č. 60 (chybí VAT ID) u KH oddílu A.2
     * s dodavatelem bez EU DIČ, které GFŘ výslovně označuje za propustné (issue #53).
     * Brát je jako odmítnutí by z plně legitimního podání udělalo blokované.
     */
    private const BLOCKING_MESSAGE_TYPES = ['S', 'K', 'E', 'N'];

    /**
     * Brání tahle zpráva EPO podání? Jediné místo, kde se závažnost vyhodnocuje —
     * zkušební i ostrá cesta se musí rozhodovat stejně.
     *
     * @param array<string,?string> $message
     */
    public static function isBlockingMessage(array $message): bool
    {
        if (strtoupper((string) ($message['code'] ?? '')) === 'TEST_REZIM') {
            return false;
        }
        return in_array(
            strtoupper((string) ($message['type'] ?? '')),
            self::BLOCKING_MESSAGE_TYPES,
            true,
        );
    }

    /** @param list<array<string,?string>> $messages */
    public static function hasBlockingMessage(array $messages): bool
    {
        foreach ($messages as $message) {
            if (self::isBlockingMessage($message)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{passed:bool,messages:list<array<string,?string>>,large_submission:bool}
     */
    public function testResult(string $body): array
    {
        $dom = $this->loadXml($body);
        if ($dom === null || strtolower((string) $dom->documentElement?->localName) !== 'chyby') {
            throw new EpoSubmissionException(
                'epo_invalid_response',
                'EPO v testovacím režimu vrátilo neočekávanou odpověď.',
                502,
            );
        }
        $messages = $this->errors($dom);
        $hasTestMarker = false;
        $blocking = false;
        $large = false;
        foreach ($messages as $message) {
            $code = strtoupper((string) ($message['code'] ?? ''));
            if ($code === 'TEST_REZIM') {
                $hasTestMarker = true;
                $large = str_contains(
                    mb_strtolower((string) ($message['text'] ?? '')),
                    'rozsáhl',
                );
                continue;
            }
            if (self::isBlockingMessage($message)) {
                $blocking = true;
            }
        }
        return [
            'passed' => $hasTestMarker && !$blocking,
            'messages' => $messages,
            'large_submission' => $large,
        ];
    }

    /**
     * @return array{
     *   kind:'errors'|'offline'|'confirmation',
     *   messages?:list<array<string,?string>>,blocking?:bool,
     *   transfer_id?:string,transfer_password?:string,confirmation?:string
     * }
     */
    public function submitEnvelope(string $body): array
    {
        $dom = $this->loadXml($body);
        if ($dom === null) {
            return ['kind' => 'confirmation', 'confirmation' => $body];
        }
        // Potvrzení se hledá DŘÍV než kořen `chyby`: podání s pouhými PROPUSTNÝMI
        // chybami EPO přijme, a přijít může obálka, která nese obojí. Původní pořadí
        // (kořen napřed) by takové potvrzení zahodilo a přijaté podání označilo za
        // odmítnuté — viz issue #53 (chyby č. 58/60 u KH oddílu A.2).
        $xpath = new \DOMXPath($dom);
        $confirmation = $xpath->query('//*[local-name()="Potvrzeni"]')->item(0);
        if ($confirmation instanceof \DOMElement) {
            $transferId = trim($confirmation->getAttribute('ID_predani'));
            $password = trim($confirmation->getAttribute('Heslo'));
            if ($transferId !== '' && $password !== '') {
                return [
                    'kind' => 'offline',
                    'transfer_id' => mb_substr($transferId, 0, 100),
                    'transfer_password' => mb_substr($password, 0, 500),
                ];
            }
        }
        $root = strtolower((string) $dom->documentElement?->localName);
        if ($root === 'chyby') {
            $messages = $this->errors($dom);
            return [
                'kind' => 'errors',
                'messages' => $messages,
                // `false` = EPO nevrátilo potvrzení, ale ani nic, co by podání bránilo.
                // Volající z toho NESMÍ udělat „odmítnuto“ — výsledek je nejistý.
                'blocking' => self::hasBlockingMessage($messages),
            ];
        }
        throw new EpoSubmissionException(
            'epo_invalid_response',
            'EPO vrátilo neznámý formát odpovědi.',
            502,
        );
    }

    /**
     * @return array{
     *   signature_valid:bool,chain_valid:bool,epo_signer_valid:bool,is_confirmation:bool,
     *   reference:?string,submitted_at:?string,state_password:?string,
     *   content_match:?bool,confirmation_xml_sha256:?string
     * }
     */
    public function confirmation(
        string $confirmationBytes,
        string $sentSignedData,
        string $environment = 'production',
    ): array {
        $environment = strtolower(trim($environment));
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException('Neplatné prostředí EPO.');
        }
        $empty = [
            'signature_valid' => false,
            'chain_valid' => false,
            'epo_signer_valid' => false,
            'is_confirmation' => false,
            'reference' => null,
            'submitted_at' => null,
            'state_password' => null,
            'content_match' => null,
            'confirmation_xml_sha256' => null,
        ];
        if (!function_exists('openssl_cms_verify')) {
            return $empty;
        }
        $input = $this->tempPath('response-');
        $content = $this->tempPath('content-');
        $certificates = $this->tempPath('certs-');
        try {
            file_put_contents($input, $confirmationBytes);
            $chainValid = $this->verifyCms($input, $content, $certificates, false);
            $signatureValid = $chainValid
                || $this->verifyCms($input, $content, $certificates, true);
            if (!$signatureValid || !is_file($content)) {
                return $empty;
            }
            $xml = (string) file_get_contents($content);
            $epoSignerValid = $this->epoSignerIdentityValid($certificates, $environment);
            $dom = $this->loadXml($xml);
            if ($dom === null) {
                return array_merge($empty, [
                    'signature_valid' => true,
                    'chain_valid' => $chainValid,
                    'epo_signer_valid' => $epoSignerValid,
                ]);
            }
            $xpath = new \DOMXPath($dom);
            $podani = $xpath->query('//*[local-name()="Podani"]')->item(0);
            if (!$podani instanceof \DOMElement) {
                return array_merge($empty, [
                    'signature_valid' => true,
                    'chain_valid' => $chainValid,
                    'epo_signer_valid' => $epoSignerValid,
                    'confirmation_xml_sha256' => hash('sha256', $xml),
                ]);
            }
            $reference = $this->attribute($podani, ['Cislo', 'cislo']);
            $submittedAt = $this->normalizeDate($this->attribute($podani, ['Datum', 'datum']));
            $statePassword = $this->attribute($podani, ['Heslo', 'heslo'], 500);
            $dataNode = $xpath->query('//*[local-name()="Data"]')->item(0);
            $embedded = $dataNode !== null
                ? $this->decodeHex((string) $dataNode->textContent)
                : null;
            // Vazba potvrzenky na odeslané podání se bere z kontrolního součtu
            // `Kontrola/Soubor/@KC`, což je MD5 odeslaného XML. Porovnávat
            // místo toho bajty vloženého `<Data>` NEMŮŽE vyjít nikdy: EPO
            // v potvrzence vrací REDUKOVANOU podobu podání — zahodí detailní
            // řádky, přepíše identifikaci software, přeformátuje čísla
            // i mezery a přidá vlastní blok `<Kontrola>`. Podání proto vždy
            // končilo jako `confirmation_content_mismatch`, přestože bylo
            // v pořádku. Ověřeno na skutečně přijatém KH v testovacím
            // prostředí: KC souhlasilo na znak.
            $sentXml = $this->extractXmlPayload($sentSignedData) ?? $sentSignedData;
            $checksum = $this->confirmationChecksum($xpath);
            $contentMatch = null;
            if ($checksum !== null) {
                $contentMatch = hash_equals($checksum, strtolower(md5($sentXml)));
            } elseif ($embedded !== null) {
                // Starší potvrzenky bez `@KC`: zůstává porovnání obsahu, které
                // vyjde jen u nezredukované podoby.
                $embeddedXml = $this->extractXmlPayload($embedded) ?? $embedded;
                $contentMatch = hash_equals(
                    hash('sha256', $sentXml),
                    hash('sha256', $embeddedXml),
                );
            }
            return [
                'signature_valid' => true,
                'chain_valid' => $chainValid,
                'epo_signer_valid' => $epoSignerValid,
                'is_confirmation' => $reference !== null
                    && $submittedAt !== null
                    && $statePassword !== null,
                'reference' => $reference,
                'submitted_at' => $submittedAt,
                'state_password' => $statePassword,
                'content_match' => $contentMatch,
                'confirmation_xml_sha256' => hash('sha256', $xml),
            ];
        } finally {
            @unlink($input);
            @unlink($content);
            @unlink($certificates);
        }
    }

    /** @return array<string,string> */
    public function status(string $body): array
    {
        $dom = $this->loadXml($body);
        if ($dom === null || strtolower((string) $dom->documentElement?->localName) !== 'stav') {
            throw new EpoSubmissionException(
                'epo_invalid_response',
                'EPO vrátilo neplatný stav podání.',
                502,
            );
        }
        $result = [];
        foreach ($dom->documentElement?->childNodes ?? [] as $node) {
            if ($node instanceof \DOMElement) {
                $result[(string) $node->localName] = mb_substr(trim($node->textContent), 0, 1000);
            }
        }
        return $result;
    }

    /** @return list<array<string,?string>> */
    private function errors(\DOMDocument $dom): array
    {
        $xpath = new \DOMXPath($dom);
        $messages = [];
        foreach ($xpath->query('//*[local-name()="Chyba"]') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $textNode = $xpath->query('./*[local-name()="Text"]', $node)->item(0);
            $text = trim((string) ($textNode?->textContent ?? $node->textContent));
            $messages[] = [
                'type' => $this->attribute($node, ['Typ', 'typ']),
                'code' => $this->attribute($node, ['Zkr', 'zkr']),
                'text' => mb_substr(preg_replace('/\s+/u', ' ', $text) ?: $text, 0, 1000),
                'field' => $this->attribute($node, ['Polozka', 'polozka']),
                'section' => $this->attribute($node, ['Oddil', 'oddil']),
                'line' => $this->attribute($node, ['Radek', 'radek']),
            ];
        }
        return $messages;
    }

    private function verifyCms(string $input, string $content, string $certs, bool $skipChain): bool
    {
        @unlink($content);
        @unlink($certs);
        $flags = OPENSSL_CMS_BINARY | ($skipChain ? OPENSSL_CMS_NOVERIFY : 0);
        $caInfo = $skipChain ? [] : $this->caInfo();
        if (!$skipChain && $caInfo === []) {
            return false;
        }
        try {
            return @openssl_cms_verify(
                $input,
                $flags,
                $certs,
                $caInfo,
                null,
                $content,
                null,
                null,
                OPENSSL_ENCODING_DER,
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Je nakonfigurovaný trust store nedostupný?
     *
     * `epo.ca_bundle_path` je záměrně fail-closed — nastavená, ale chybějící cesta NESMÍ
     * propadnout na jiný trust store. Jenže výsledek je pak k nerozeznání od skutečně
     * vadné potvrzenky: účetní vidí „potvrzení se nepodařilo bezpečně ověřit" u podání,
     * které správce daně bez potíží přijal, a hrozí, že ho ze strachu odešle podruhé.
     *
     * Volající proto tuhle příčinu rozliší a pojmenuje ji jako chybu KONFIGURACE.
     */
    public function trustStoreUnavailable(): bool
    {
        return $this->caBundlePath !== null
            && trim($this->caBundlePath) !== ''
            && $this->caInfo() === [];
    }

    /** @return list<string> */
    private function caInfo(): array
    {
        if ($this->caBundlePath !== null && trim($this->caBundlePath) !== '') {
            return is_file($this->caBundlePath) || is_dir($this->caBundlePath)
                ? [$this->caBundlePath]
                : [];
        }
        $candidates = [
            ini_get('openssl.cafile') ?: null,
            ini_get('curl.cainfo') ?: null,
        ];
        $locations = openssl_get_cert_locations();
        foreach (['ini_cafile', 'ini_capath', 'default_cert_file', 'default_cert_dir'] as $key) {
            $candidates[] = is_string($locations[$key] ?? null)
                ? $locations[$key]
                : null;
        }
        $result = [];
        foreach ($candidates as $candidate) {
            if (
                is_string($candidate)
                && $candidate !== ''
                && (is_file($candidate) || is_dir($candidate))
            ) {
                $result[] = $candidate;
            }
        }
        return array_values(array_unique($result));
    }

    private function epoSignerIdentityValid(
        string $certificatesPath,
        string $environment,
    ): bool
    {
        if (!is_file($certificatesPath)) {
            return false;
        }
        $pem = (string) file_get_contents($certificatesPath);
        if (!preg_match_all(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            $pem,
            $matches,
        ) || count($matches[0]) !== 1) {
            return false;
        }
        foreach ($matches[0] as $certificatePem) {
            $certificate = openssl_x509_read($certificatePem);
            if ($certificate === false) {
                continue;
            }
            $parsed = openssl_x509_parse($certificate, true);
            if (!is_array($parsed)) {
                continue;
            }
            $subject = is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
            $cn = $this->normalizedDnValue($subject['CN'] ?? null);
            $organization = $this->normalizedDnValue($subject['O'] ?? null);
            $country = strtoupper($this->normalizedDnValue($subject['C'] ?? null));
            $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
            $normalizedFingerprint = is_string($fingerprint)
                ? strtolower(str_replace(':', '', $fingerprint))
                : '';
            $expectedCommonNames = $environment === 'test'
                ? ['testovací zařízení - nelze učinit platné podání']
                : [
                    'spolecne technicke zarizeni spravcu dane',
                    'společné technické zařízení správců daně',
                ];
            $fingerprints = $environment === 'test'
                ? $this->testSignerFingerprints
                : $this->signerFingerprints;
            $fingerprintValid = $environment === 'test'
                ? $fingerprints !== [] && in_array($normalizedFingerprint, $fingerprints, true)
                : $fingerprints === [] || in_array($normalizedFingerprint, $fingerprints, true);
            if (
                in_array($cn, $expectedCommonNames, true)
                && str_contains($organization, 'generální finanční ředitelství')
                && $country === 'CZ'
                && $fingerprintValid
            ) {
                return true;
            }
        }
        return false;
    }

    private function normalizedDnValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        return mb_strtolower(trim(is_scalar($value) ? (string) $value : ''));
    }

    private function loadXml(string $xml): ?\DOMDocument
    {
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new \DOMDocument();
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        return $ok ? $dom : null;
    }

    private function extractXmlPayload(string $bytes): ?string
    {
        $payload = $this->extractXmlOrZipPayload($bytes);
        if ($payload !== null) {
            return $payload;
        }
        $input = $this->tempPath('embedded-');
        $content = $this->tempPath('embedded-content-');
        $certificates = $this->tempPath('embedded-certs-');
        try {
            file_put_contents($input, $bytes);
            if (
                !$this->verifyCms($input, $content, $certificates, true)
                || !is_file($content)
            ) {
                return null;
            }
            return $this->extractXmlOrZipPayload((string) file_get_contents($content));
        } finally {
            @unlink($input);
            @unlink($content);
            @unlink($certificates);
        }
    }

    private function extractXmlOrZipPayload(string $bytes): ?string
    {
        if ($this->loadXml($bytes) !== null) {
            return $bytes;
        }
        if (
            !class_exists(\ZipArchive::class)
            || !str_starts_with($bytes, "PK\x03\x04")
            || strlen($bytes) > 10 * 1024 * 1024
        ) {
            return null;
        }

        $path = $this->tempPath('embedded-zip-');
        $zip = new \ZipArchive();
        $opened = false;
        try {
            if (file_put_contents($path, $bytes) === false || $zip->open($path) !== true) {
                return null;
            }
            $opened = true;
            if ($zip->numFiles !== 1) {
                return null;
            }
            $stat = $zip->statIndex(0);
            if (
                !is_array($stat)
                || !isset($stat['name'], $stat['size'])
                || !preg_match('/\.xml$/i', basename((string) $stat['name']))
                || (int) $stat['size'] <= 0
                || (int) $stat['size'] > 10 * 1024 * 1024
            ) {
                return null;
            }
            $xml = $zip->getFromIndex(0);
            return is_string($xml) && $this->loadXml($xml) !== null ? $xml : null;
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($path);
        }
    }

    private function decodeHex(string $value): ?string
    {
        $compact = preg_replace('/\s+/', '', $value) ?? '';
        if ($compact === '' || strlen($compact) % 2 !== 0 || !ctype_xdigit($compact)) {
            return null;
        }
        $decoded = hex2bin($compact);
        return $decoded !== false ? $decoded : null;
    }

    /** @param list<string> $names */
    /**
     * Kontrolní součet odeslaného podání z potvrzenky EPO
     * (`Kontrola/Soubor/@KC`), normalizovaný na malá písmena. Je to MD5
     * odeslaného XML — jediná vazba potvrzenky na to, co jsme poslali, protože
     * samotný obsah vrací EPO zredukovaný.
     */
    private function confirmationChecksum(\DOMXPath $xpath): ?string
    {
        $node = $xpath->query('//*[local-name()="Soubor"]')->item(0);
        if (!$node instanceof \DOMElement) {
            return null;
        }
        $checksum = strtolower(trim($this->attribute($node, ['KC', 'kc'], 64) ?? ''));

        return preg_match('/^[0-9a-f]{32}$/D', $checksum) === 1 ? $checksum : null;
    }

    private function attribute(\DOMElement $element, array $names, int $limit = 100): ?string
    {
        foreach ($names as $name) {
            $value = trim($element->getAttribute($name));
            if ($value !== '') {
                return mb_substr($value, 0, $limit);
            }
        }
        return null;
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private function tempPath(string $prefix): string
    {
        $dir = RuntimePaths::storage('tmp/epo');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new EpoSubmissionException(
                'storage_not_writable',
                'Nelze vytvořit bezpečné dočasné úložiště.',
                500,
            );
        }
        $path = tempnam($dir, $prefix);
        if ($path === false) {
            throw new EpoSubmissionException(
                'storage_not_writable',
                'Nelze vytvořit dočasný soubor.',
                500,
            );
        }
        @chmod($path, 0600);
        return $path;
    }
}

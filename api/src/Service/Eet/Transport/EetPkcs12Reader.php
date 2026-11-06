<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Načtení PKCS#12 s pokladním certifikátem.
 *
 * Nejdřív zkusí `openssl_pkcs12_read()`. Když OpenSSL soubor neotevře (typicky
 * OpenSSL 3 bez legacy provideru a certifikát šifrovaný RC2-40, což je přesně
 * formát CA EET), rozebere soubor sám: ověří MAC heslem (RFC 7292), dešifruje
 * PKCS#12 PBE (SHA-1 + 3DES/RC2) nebo PBES2 (PBKDF2 + AES/3DES) a klíč předá
 * OpenSSL už jako nešifrovaný PKCS#8. Mezivýsledky žijí jen v paměti.
 */
final class EetPkcs12Reader
{
    private const OID_DATA = '1.2.840.113549.1.7.1';
    private const OID_ENCRYPTED_DATA = '1.2.840.113549.1.7.6';
    private const OID_KEY_BAG = '1.2.840.113549.1.12.10.1.1';
    private const OID_SHROUDED_KEY_BAG = '1.2.840.113549.1.12.10.1.2';
    private const OID_CERT_BAG = '1.2.840.113549.1.12.10.1.3';
    private const OID_X509_CERT = '1.2.840.113549.1.9.22.1';
    private const OID_PBES2 = '1.2.840.113549.1.5.13';
    private const OID_PBKDF2 = '1.2.840.113549.1.5.12';

    private const DIGESTS = [
        '1.3.14.3.2.26' => 'sha1',
        '2.16.840.1.101.3.4.2.1' => 'sha256',
        '2.16.840.1.101.3.4.2.2' => 'sha384',
        '2.16.840.1.101.3.4.2.3' => 'sha512',
    ];

    /** PKCS#12 PBE: OID => [šifra, délka klíče, efektivní bity RC2 nebo 0] */
    private const PKCS12_PBE = [
        '1.2.840.113549.1.12.1.3' => ['des-ede3-cbc', 24, 0],
        '1.2.840.113549.1.12.1.4' => ['des-ede-cbc', 16, 0],
        '1.2.840.113549.1.12.1.5' => ['rc2', 16, 128],
        '1.2.840.113549.1.12.1.6' => ['rc2', 5, 40],
    ];

    private const PBES2_CIPHERS = [
        '2.16.840.1.101.3.4.1.2' => ['aes-128-cbc', 16],
        '2.16.840.1.101.3.4.1.22' => ['aes-192-cbc', 24],
        '2.16.840.1.101.3.4.1.42' => ['aes-256-cbc', 32],
        '1.2.840.113549.3.7' => ['des-ede3-cbc', 24],
    ];

    private const PBKDF2_PRF = [
        '1.2.840.113549.2.7' => 'sha1',
        '1.2.840.113549.2.9' => 'sha256',
        '1.2.840.113549.2.10' => 'sha384',
        '1.2.840.113549.2.11' => 'sha512',
    ];

    /**
     * Meze proti souboru, který by server zaměstnal na minuty: iterace jednoho
     * odvození klíče, součet iterací za celý soubor (vážený počtem bloků výstupu)
     * a počet obsahů i položek. Soubory CA EET mají 2048 iterací a pár položek.
     */
    private const MAX_ITERATIONS = 100_000;
    private const MAX_TOTAL_ITERATIONS = 600_000;
    private const MAX_ENTRIES = 16;
    private const MAX_BYTES = 65_536;
    private const MAX_DEPTH = 24;

    /**
     * @return array{cert:string, pkey:\OpenSSLAsymmetricKey, extracerts:list<string>}
     */
    public static function read(string $pkcs12, #[\SensitiveParameter] string $password): array
    {
        self::preflight($pkcs12);
        self::clearOpensslErrors();
        $bundle = [];
        if (@openssl_pkcs12_read($pkcs12, $bundle, $password)) {
            self::clearOpensslErrors();
            $key = @openssl_pkey_get_private((string) ($bundle['pkey'] ?? ''));
            $bundle['pkey'] = '';
            if ($key === false || !isset($bundle['cert']) || !is_string($bundle['cert'])) {
                self::clearOpensslErrors();
                throw new EetCredentialException('private_key_missing', 'Soubor neobsahuje certifikát se soukromým klíčem.');
            }

            return [
                'cert' => $bundle['cert'],
                'pkey' => $key,
                'extracerts' => array_values(array_filter($bundle['extracerts'] ?? [], 'is_string')),
            ];
        }
        self::clearOpensslErrors();

        return self::decode($pkcs12, $password);
    }

    /**
     * Rozbor v čistém PHP (bez legacy provideru OpenSSL). Veřejné kvůli testům.
     *
     * @return array{cert:string, pkey:\OpenSSLAsymmetricKey, extracerts:list<string>}
     */
    public static function decode(string $pkcs12, #[\SensitiveParameter] string $password): array
    {
        self::preflight($pkcs12);
        try {
            [$parts, $authSafeBytes] = self::authSafe($pkcs12);
            $budget = 0;
            $bagCount = 0;
            if (isset($parts[2])) {
                self::verifyMac($parts[2], $authSafeBytes, $password, $budget);
            }
            $certificates = [];
            $keys = [];
            foreach (self::contentInfos($authSafeBytes) as $contentInfo) {
                $info = self::children($contentInfo, 0x30, 1);
                $type = self::oid($info[0]);
                if ($type === self::OID_DATA) {
                    $safeContents = self::octets(self::explicit($info[1] ?? null));
                } elseif ($type === self::OID_ENCRYPTED_DATA) {
                    $encryptedContentInfo = self::encryptedContentInfo($info);
                    $safeContents = self::decrypt(
                        $encryptedContentInfo[1],
                        self::octets($encryptedContentInfo[2]),
                        $password,
                        $budget,
                    );
                } else {
                    continue;
                }
                foreach (self::bags($safeContents, $bagCount) as $bag) {
                    self::collectBag($bag, $password, $certificates, $keys, $budget);
                }
            }
        } catch (EetCredentialException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new EetCredentialException('unreadable', 'Soubor s certifikátem není čitelný PKCS#12.');
        }
        if ($keys === []) {
            throw new EetCredentialException('private_key_missing', 'Soubor neobsahuje soukromý klíč.');
        }
        if ($certificates === []) {
            throw new EetCredentialException('unreadable', 'Soubor neobsahuje certifikát.');
        }
        foreach ($keys as $key) {
            foreach ($certificates as $index => $certificate) {
                if (@openssl_x509_check_private_key($certificate, $key)) {
                    unset($certificates[$index]);
                    self::clearOpensslErrors();

                    return ['cert' => $certificate, 'pkey' => $key, 'extracerts' => array_values($certificates)];
                }
            }
        }
        self::clearOpensslErrors();

        throw new EetCredentialException('key_mismatch', 'Soukromý klíč v souboru nepatří k žádnému z certifikátů.');
    }

    /**
     * Kontrola mezí dřív, než soubor dostane OpenSSL: iterace MAC, šifrovaných
     * obsahů a klíčů v nešifrovaných obsazích, počet obsahů a položek. Co leží
     * uvnitř šifrovaného obsahu, uvidí až {@see decode()} se stejným rozpočtem.
     */
    private static function preflight(string $pkcs12): void
    {
        if ($pkcs12 === '' || strlen($pkcs12) > self::MAX_BYTES) {
            throw new EetCredentialException('unreadable', 'Soubor s certifikátem je prázdný nebo příliš velký.');
        }
        try {
            [$parts, $authSafeBytes] = self::authSafe($pkcs12);
            $budget = 0;
            $bagCount = 0;
            if (isset($parts[2])) {
                self::charge($budget, self::macParameters($parts[2])['cost']);
            }
            foreach (self::contentInfos($authSafeBytes) as $contentInfo) {
                $info = self::children($contentInfo, 0x30, 1);
                $type = self::oid($info[0]);
                if ($type === self::OID_ENCRYPTED_DATA) {
                    self::charge($budget, self::algorithmCost(self::encryptedContentInfo($info)[1]));
                } elseif ($type === self::OID_DATA) {
                    foreach (self::bags(self::octets(self::explicit($info[1] ?? null)), $bagCount) as $bag) {
                        $items = self::children($bag, 0x30, 2);
                        if (self::oid($items[0]) === self::OID_SHROUDED_KEY_BAG) {
                            self::charge($budget, self::algorithmCost(self::children(self::explicit($items[1]), 0x30, 2)[0]));
                        }
                    }
                }
            }
        } catch (EetCredentialException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new EetCredentialException('unreadable', 'Soubor s certifikátem není čitelný PKCS#12.');
        }
    }

    /** @return array{0:list<array<string,mixed>>, 1:string} prvky PFX a bajty AuthenticatedSafe */
    private static function authSafe(string $pkcs12): array
    {
        $parts = self::children(self::single($pkcs12), 0x30, 2);
        $authSafe = self::children($parts[1], 0x30, 2);
        if (self::oid($authSafe[0]) !== self::OID_DATA) {
            throw new EetCredentialException('unreadable', 'Soubor PKCS#12 není podepsaný heslem (nepodporovaný tvar).');
        }

        return [$parts, self::octets(self::explicit($authSafe[1]))];
    }

    /** @return list<array<string,mixed>> */
    private static function contentInfos(string $authSafeBytes): array
    {
        $contentInfos = self::children(self::single($authSafeBytes), 0x30);
        if (count($contentInfos) > self::MAX_ENTRIES) {
            throw new EetCredentialException('unreadable', 'Soubor PKCS#12 má nepřípustný počet obsahů.');
        }

        return $contentInfos;
    }

    /** @return list<array<string,mixed>> */
    private static function bags(string $safeContents, int &$bagCount): array
    {
        $bags = self::children(self::single($safeContents), 0x30);
        $bagCount += count($bags);
        if ($bagCount > self::MAX_ENTRIES) {
            throw new EetCredentialException('unreadable', 'Soubor PKCS#12 má nepřípustný počet položek.');
        }

        return $bags;
    }

    /**
     * @param list<array<string,mixed>> $info
     * @return list<array<string,mixed>>
     */
    private static function encryptedContentInfo(array $info): array
    {
        $encryptedData = self::children(self::explicit($info[1] ?? null), 0x30, 2);

        return self::children($encryptedData[1], 0x30, 3);
    }

    /**
     * @param array<string,mixed> $bag
     * @param list<string> $certificates
     * @param list<\OpenSSLAsymmetricKey> $keys
     */
    private static function collectBag(array $bag, string $password, array &$certificates, array &$keys, int &$budget): void
    {
        $items = self::children($bag, 0x30, 2);
        $value = self::explicit($items[1]);
        switch (self::oid($items[0])) {
            case self::OID_CERT_BAG:
                $certBag = self::children($value, 0x30, 2);
                if (self::oid($certBag[0]) !== self::OID_X509_CERT) {
                    return;
                }
                $pem = self::pem('CERTIFICATE', self::octets(self::explicit($certBag[1])));
                if (@openssl_x509_read($pem) === false) {
                    throw new EetCredentialException('unreadable', 'Certifikát v souboru nelze načíst.');
                }
                $certificates[] = $pem;

                return;
            case self::OID_KEY_BAG:
                $keys[] = self::privateKey((string) $value['der']);

                return;
            case self::OID_SHROUDED_KEY_BAG:
                $encrypted = self::children($value, 0x30, 2);
                $keys[] = self::privateKey(self::decrypt($encrypted[0], self::octets($encrypted[1]), $password, $budget));

                return;
        }
    }

    private static function privateKey(string $der): \OpenSSLAsymmetricKey
    {
        $pem = self::pem('PRIVATE KEY', $der);
        $key = @openssl_pkey_get_private($pem);
        $pem = '';
        if ($key === false) {
            self::clearOpensslErrors();
            throw new EetCredentialException('unreadable', 'Soukromý klíč v souboru nelze načíst.');
        }

        return $key;
    }

    /** @param array<string,mixed> $macData */
    private static function verifyMac(array $macData, string $authSafeBytes, string $password, int &$budget): void
    {
        ['hash' => $hash, 'expected' => $expected, 'salt' => $salt, 'iterations' => $iterations, 'cost' => $cost] = self::macParameters($macData);
        self::charge($budget, $cost);
        $macKey = self::pkcs12Kdf($hash, self::bmpPassword($password), $salt, 3, $iterations, strlen(hash($hash, '', true)));
        if (!hash_equals($expected, hash_hmac($hash, $authSafeBytes, $macKey, true))) {
            throw new EetCredentialException('invalid_password', 'Heslo k certifikátu není správné.');
        }
    }

    /**
     * @param array<string,mixed> $macData
     * @return array{hash:string, expected:string, salt:string, iterations:int, cost:int}
     */
    private static function macParameters(array $macData): array
    {
        $items = self::children($macData, 0x30, 2);
        $digestInfo = self::children($items[0], 0x30, 2);
        $algorithm = self::children($digestInfo[0], 0x30, 1);
        $hash = self::DIGESTS[self::oid($algorithm[0])] ?? null;
        if ($hash === null) {
            throw new EetCredentialException('unreadable', 'Soubor PKCS#12 používá nepodporovaný algoritmus MAC.');
        }
        $iterations = isset($items[2]) ? self::integer($items[2]) : 1;
        self::assertIterations($iterations);

        return [
            'hash' => $hash,
            'expected' => self::octets($digestInfo[1]),
            'salt' => self::octets($items[1]),
            'iterations' => $iterations,
            'cost' => $iterations,
        ];
    }

    /**
     * Cena odvození klíče pro šifrovací algoritmus (iterace × bloky výstupu),
     * zjištěná jen z parametrů, bez dešifrování.
     *
     * @param array<string,mixed> $algorithmIdentifier
     */
    private static function algorithmCost(array $algorithmIdentifier): int
    {
        $algorithm = self::children($algorithmIdentifier, 0x30, 2);
        $oid = self::oid($algorithm[0]);
        if (isset(self::PKCS12_PBE[$oid])) {
            $iterations = self::integer(self::children($algorithm[1], 0x30, 2)[1]);
            self::assertIterations($iterations);

            return $iterations * (intdiv(self::PKCS12_PBE[$oid][1] + 19, 20) + 1);
        }
        if ($oid === self::OID_PBES2) {
            ['iterations' => $iterations, 'prf' => $prf, 'cipher' => $cipherSpec] = self::pbes2Parameters($algorithm[1]);
            $hashLength = strlen(hash($prf, '', true));

            return $iterations * intdiv($cipherSpec[1] + $hashLength - 1, $hashLength);
        }
        throw new EetCredentialException('unreadable', 'Soubor PKCS#12 používá nepodporovanou šifru.');
    }

    /**
     * @param array<string,mixed> $parameters
     * @return array{salt:string, iterations:int, prf:string, cipher:array{0:string,1:int}, iv:string}
     */
    private static function pbes2Parameters(array $parameters): array
    {
        $params = self::children($parameters, 0x30, 2);
        $kdf = self::children($params[0], 0x30, 2);
        if (self::oid($kdf[0]) !== self::OID_PBKDF2) {
            throw new EetCredentialException('unreadable', 'Soubor PKCS#12 používá nepodporované odvození klíče.');
        }
        $kdfParams = self::children($kdf[1], 0x30, 2);
        $iterations = self::integer($kdfParams[1]);
        $prf = 'sha1';
        foreach (array_slice($kdfParams, 2) as $node) {
            if ($node['tag'] === 0x30) {
                $prf = self::PBKDF2_PRF[self::oid(self::children($node, 0x30, 1)[0])] ?? '';
            }
        }
        $scheme = self::children($params[1], 0x30, 2);
        $cipherSpec = self::PBES2_CIPHERS[self::oid($scheme[0])] ?? null;
        if ($prf === '' || $cipherSpec === null) {
            throw new EetCredentialException('unreadable', 'Soubor PKCS#12 používá nepodporovanou šifru.');
        }
        self::assertIterations($iterations);

        return [
            'salt' => self::octets($kdfParams[0]),
            'iterations' => $iterations,
            'prf' => $prf,
            'cipher' => $cipherSpec,
            'iv' => self::octets($scheme[1]),
        ];
    }

    private static function charge(int &$budget, int $cost): void
    {
        $budget += $cost;
        if ($budget > self::MAX_TOTAL_ITERATIONS) {
            throw new EetCredentialException('unreadable', 'Soubor PKCS#12 vyžaduje nepřípustně mnoho iterací.');
        }
    }

    /** @param array<string,mixed> $algorithmIdentifier */
    private static function decrypt(array $algorithmIdentifier, string $ciphertext, string $password, int &$budget): string
    {
        self::charge($budget, self::algorithmCost($algorithmIdentifier));
        $algorithm = self::children($algorithmIdentifier, 0x30, 2);
        $oid = self::oid($algorithm[0]);
        if (isset(self::PKCS12_PBE[$oid])) {
            [$cipher, $keyLength, $rc2Bits] = self::PKCS12_PBE[$oid];
            $params = self::children($algorithm[1], 0x30, 2);
            $salt = self::octets($params[0]);
            $iterations = self::integer($params[1]);
            $bmp = self::bmpPassword($password);
            $key = self::pkcs12Kdf('sha1', $bmp, $salt, 1, $iterations, $keyLength);
            $iv = self::pkcs12Kdf('sha1', $bmp, $salt, 2, $iterations, 8);
            $plain = $rc2Bits > 0
                ? (new Rc2Cipher($key, $rc2Bits))->decryptCbc($ciphertext, $iv)
                : self::opensslDecrypt($ciphertext, $cipher, $key, $iv);
        } elseif ($oid === self::OID_PBES2) {
            $pbes2 = self::pbes2Parameters($algorithm[1]);
            $key = hash_pbkdf2($pbes2['prf'], $password, $pbes2['salt'], $pbes2['iterations'], $pbes2['cipher'][1], true);
            $plain = self::opensslDecrypt($ciphertext, $pbes2['cipher'][0], $key, $pbes2['iv']);
        } else {
            throw new EetCredentialException('unreadable', 'Soubor PKCS#12 používá nepodporovanou šifru.');
        }
        if ($plain === null) {
            throw new EetCredentialException('invalid_password', 'Heslo k certifikátu není správné.');
        }

        return $plain;
    }

    private static function opensslDecrypt(string $ciphertext, string $cipher, string $key, string $iv): ?string
    {
        $plain = @openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        self::clearOpensslErrors();

        return is_string($plain) ? $plain : null;
    }

    /** Odvození klíče podle RFC 7292, příloha B.2. */
    public static function pkcs12Kdf(string $hash, string $password, string $salt, int $id, int $iterations, int $length): string
    {
        self::assertIterations($iterations);
        $u = strlen(hash($hash, '', true));
        $v = in_array($hash, ['sha384', 'sha512'], true) ? 128 : 64;
        $diversifier = str_repeat(chr($id), $v);
        $stretch = static function (string $value) use ($v): string {
            if ($value === '') {
                return '';
            }
            $length = $v * intdiv(strlen($value) + $v - 1, $v);

            return substr(str_repeat($value, intdiv($length, strlen($value)) + 1), 0, $length);
        };
        $input = $stretch($salt) . $stretch($password);
        $output = '';
        while (true) {
            $block = hash($hash, $diversifier . $input, true);
            for ($i = 1; $i < $iterations; $i++) {
                $block = hash($hash, $block, true);
            }
            $output .= $block;
            if (strlen($output) >= $length) {
                return substr($output, 0, $length);
            }
            $b = substr(str_repeat($block, intdiv($v, $u) + 1), 0, $v);
            $next = '';
            for ($offset = 0, $total = strlen($input); $offset < $total; $offset += $v) {
                $chunk = substr($input, $offset, $v);
                $carry = 1;
                $bytes = [];
                for ($k = $v - 1; $k >= 0; $k--) {
                    $sum = ord($chunk[$k]) + ord($b[$k]) + $carry;
                    $bytes[$k] = $sum & 0xFF;
                    $carry = $sum >> 8;
                }
                ksort($bytes);
                $next .= pack('C*', ...$bytes);
            }
            $input = $next;
        }
    }

    private static function bmpPassword(string $password): string
    {
        return mb_convert_encoding($password, 'UTF-16BE', 'UTF-8') . "\0\0";
    }

    private static function assertIterations(int $iterations): void
    {
        if ($iterations < 1 || $iterations > self::MAX_ITERATIONS) {
            throw new EetCredentialException('unreadable', 'Soubor PKCS#12 má nepřípustný počet iterací.');
        }
    }

    // --- minimální BER/DER čtečka (jen to, co PKCS#12 potřebuje) ---

    /** @return array<string,mixed> */
    private static function single(string $data): array
    {
        $offset = 0;
        $node = self::parseOne($data, $offset, strlen($data), 0);
        if ($node === null) {
            throw new \UnexpectedValueException('ASN.1: prázdný vstup.');
        }

        return $node;
    }

    /** @return array<string,mixed>|null */
    private static function parseOne(string $data, int &$offset, int $end, int $depth): ?array
    {
        if ($depth > self::MAX_DEPTH || $offset + 2 > $end) {
            throw new \UnexpectedValueException('ASN.1: neplatná struktura.');
        }
        $start = $offset;
        $tag = ord($data[$offset++]);
        if (($tag & 0x1F) === 0x1F) {
            throw new \UnexpectedValueException('ASN.1: nepodporovaný tag.');
        }
        $first = ord($data[$offset++]);
        if ($tag === 0 && $first === 0) {
            return null;
        }
        $constructed = ($tag & 0x20) !== 0;
        if ($first === 0x80) {
            if (!$constructed) {
                throw new \UnexpectedValueException('ASN.1: neurčitá délka u primitivního typu.');
            }
            $children = [];
            while (($child = self::parseOne($data, $offset, $end, $depth + 1)) !== null) {
                $children[] = $child;
            }

            return ['tag' => $tag, 'raw' => null, 'children' => $children, 'der' => substr($data, $start, $offset - $start)];
        }
        if ($first < 0x80) {
            $length = $first;
        } else {
            $count = $first & 0x7F;
            if ($count > 4 || $offset + $count > $end) {
                throw new \UnexpectedValueException('ASN.1: neplatná délka.');
            }
            $length = 0;
            for ($i = 0; $i < $count; $i++) {
                $length = ($length << 8) | ord($data[$offset++]);
            }
        }
        if ($length > $end - $offset) {
            throw new \UnexpectedValueException('ASN.1: délka přesahuje data.');
        }
        $raw = substr($data, $offset, $length);
        $offset += $length;
        $node = ['tag' => $tag, 'raw' => $raw, 'children' => null, 'der' => substr($data, $start, $offset - $start)];
        if ($constructed) {
            $children = [];
            $inner = 0;
            while ($inner < $length) {
                $child = self::parseOne($raw, $inner, $length, $depth + 1);
                if ($child === null) {
                    throw new \UnexpectedValueException('ASN.1: neočekávaný konec obsahu.');
                }
                $children[] = $child;
            }
            $node['children'] = $children;
        }

        return $node;
    }

    /**
     * @param array<string,mixed>|null $node
     * @return list<array<string,mixed>>
     */
    private static function children(?array $node, int $tag, int $minimum = 0): array
    {
        if ($node === null || $node['tag'] !== $tag || !is_array($node['children']) || count($node['children']) < $minimum) {
            throw new \UnexpectedValueException('ASN.1: neočekávaná struktura.');
        }

        return $node['children'];
    }

    /**
     * Obsah explicitního tagu [0].
     *
     * @param array<string,mixed>|null $node
     * @return array<string,mixed>
     */
    private static function explicit(?array $node): array
    {
        return self::children($node, 0xA0, 1)[0];
    }

    /** @param array<string,mixed> $node */
    private static function octets(array $node): string
    {
        // OCTET STRING (0x04) nebo implicitní [0] (0x80/0xA0); BER smí obsah dělit na kusy.
        if (!in_array($node['tag'] & 0xDF, [0x04, 0x80], true)) {
            throw new \UnexpectedValueException('ASN.1: očekáván OCTET STRING.');
        }
        if (is_array($node['children'])) {
            return implode('', array_map(static fn (array $child): string => self::octets($child), $node['children']));
        }

        return (string) $node['raw'];
    }

    /** @param array<string,mixed> $node */
    private static function integer(array $node): int
    {
        $raw = (string) $node['raw'];
        if ($node['tag'] !== 0x02 || $raw === '' || strlen($raw) > 4 || (ord($raw[0]) & 0x80) !== 0) {
            throw new \UnexpectedValueException('ASN.1: očekáváno malé kladné celé číslo.');
        }

        return (int) hexdec(bin2hex($raw));
    }

    /** @param array<string,mixed> $node */
    private static function oid(array $node): string
    {
        $raw = (string) $node['raw'];
        if ($node['tag'] !== 0x06 || $raw === '') {
            throw new \UnexpectedValueException('ASN.1: očekáván OID.');
        }
        $first = ord($raw[0]);
        $parts = [intdiv($first, 40) > 2 ? 2 : intdiv($first, 40), $first >= 80 ? $first - 80 : $first % 40];
        $value = 0;
        for ($i = 1, $length = strlen($raw); $i < $length; $i++) {
            $byte = ord($raw[$i]);
            $value = ($value << 7) | ($byte & 0x7F);
            if (($byte & 0x80) === 0) {
                $parts[] = $value;
                $value = 0;
            }
        }

        return implode('.', $parts);
    }

    private static function pem(string $label, string $der): string
    {
        return "-----BEGIN {$label}-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END {$label}-----\n";
    }

    private static function clearOpensslErrors(): void
    {
        while (openssl_error_string() !== false) {
        }
    }
}

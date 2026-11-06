<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Ověření podpisu WS-Security nad `soapenv:Body` — sdílené pro odeslané zprávy
 * (kontrola vlastního výstupu a oficiálních vzorů FS) i pro potvrzení správce daně.
 *
 * Kontroly proti XML wrappingu: právě jeden `Body` v celém dokumentu a je přímým
 * potomkem kořene `Envelope`; právě jeden `ds:Signature`; reference míří na Id,
 * které se v dokumentu vyskytuje JEDNOU (v jakémkoli jmenném prostoru) a patří
 * právě tomu `Body`. Kdo pak čte obsah, musí číst z vráceného `body` — ne
 * hledat `Odpoved` znovu přes celý dokument.
 *
 * Ověřuje jen kryptografii a tvar; důvěryhodnost certifikátu řeší
 * {@see EetResponseVerifier}.
 */
final class EetXmlSignature
{
    private const ID_PATTERN = '/^[A-Za-z0-9_.\-]{1,128}$/D';

    /**
     * @return array{certificatePem:string, body:\DOMElement}
     */
    public static function verify(\DOMDocument $document): array
    {
        $xpath = EetXml::xpath($document);
        $envelope = $document->documentElement;
        if (!$envelope instanceof \DOMElement || $envelope->namespaceURI !== EetXml::NS_SOAP || $envelope->localName !== 'Envelope') {
            throw new EetSignatureException('envelope_missing', 'Zpráva nemá obálku SOAP 1.1.');
        }
        $body = self::uniqueBody($document, $envelope);
        $headers = EetXml::childElements($envelope, EetXml::NS_SOAP, 'Header');
        if (count($headers) !== 1) {
            throw new EetSignatureException('signature_missing', 'Zpráva nemá hlavičku SOAP s podpisem.');
        }
        $securities = EetXml::childElements($headers[0], EetXml::NS_WSSE, 'Security');
        if (count($securities) !== 1) {
            throw new EetSignatureException('signature_missing', 'Hlavička SOAP nemá právě jednu sekci WS-Security.');
        }
        $security = $securities[0];
        $allSignatures = $xpath->query('//ds:Signature');
        $signatures = EetXml::childElements($security, EetXml::NS_DS, 'Signature');
        if (count($signatures) !== 1 || $allSignatures === false || $allSignatures->length !== 1) {
            throw new EetSignatureException('signature_missing', 'Zpráva nemá právě jeden podpis.');
        }
        $signature = $signatures[0];
        $signedInfo = self::one($signature, EetXml::NS_DS, 'SignedInfo', 'signed_info_invalid');
        $canonicalization = self::one($signedInfo, EetXml::NS_DS, 'CanonicalizationMethod', 'signed_info_invalid');
        if ($canonicalization->getAttribute('Algorithm') !== EetXml::NS_EXC_C14N) {
            throw new EetSignatureException('algorithm_unsupported', 'Podpis nepoužívá Exclusive C14N.');
        }
        $method = self::one($signedInfo, EetXml::NS_DS, 'SignatureMethod', 'signed_info_invalid');
        if ($method->getAttribute('Algorithm') !== EetXml::ALG_RSA_SHA256) {
            throw new EetSignatureException('algorithm_unsupported', 'Podpis nepoužívá RSA-SHA256.');
        }
        $reference = self::one($signedInfo, EetXml::NS_DS, 'Reference', 'reference_invalid');
        $uri = $reference->getAttribute('URI');
        $id = str_starts_with($uri, '#') ? substr($uri, 1) : '';
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new EetSignatureException('reference_invalid', 'Podpis neodkazuje na element v téže zprávě.');
        }
        $targets = self::elementsWithId($xpath, $id);
        if (count($targets) !== 1) {
            throw new EetSignatureException('duplicate_id', 'Identifikátor podepsaného elementu není v dokumentu jedinečný.');
        }
        if (!$targets[0]->isSameNode($body) || $body->getAttributeNS(EetXml::NS_WSU, 'Id') !== $id) {
            throw new EetSignatureException('reference_not_body', 'Podpis nekryje tělo SOAP zprávy.');
        }
        $transforms = $xpath->query('./ds:Transforms/ds:Transform', $reference);
        if ($transforms === false || $transforms->length !== 1 || !$transforms->item(0) instanceof \DOMElement
            || $transforms->item(0)->getAttribute('Algorithm') !== EetXml::NS_EXC_C14N
        ) {
            throw new EetSignatureException('algorithm_unsupported', 'Podepsaná reference musí mít jedinou transformaci Exclusive C14N.');
        }
        $digestMethod = self::one($reference, EetXml::NS_DS, 'DigestMethod', 'reference_invalid');
        if ($digestMethod->getAttribute('Algorithm') !== EetXml::ALG_SHA256) {
            throw new EetSignatureException('algorithm_unsupported', 'Otisk podepsaného elementu nepoužívá SHA-256.');
        }
        $expectedDigest = base64_decode(preg_replace('/\s+/', '', self::one($reference, EetXml::NS_DS, 'DigestValue', 'reference_invalid')->textContent) ?? '', true);
        $digest = hash('sha256', self::canonicalize($body, self::prefixList($transforms->item(0))), true);
        if (!is_string($expectedDigest) || !hash_equals($expectedDigest, $digest)) {
            throw new EetSignatureException('digest_mismatch', 'Otisk těla zprávy nesouhlasí s podpisem; zpráva byla změněna.');
        }
        $certificatePem = self::certificate($xpath, $security, $signature);
        $signatureValue = base64_decode(preg_replace('/\s+/', '', self::one($signature, EetXml::NS_DS, 'SignatureValue', 'signature_invalid')->textContent) ?? '', true);
        $publicKey = @openssl_pkey_get_public($certificatePem);
        if ($publicKey === false || !is_string($signatureValue) || $signatureValue === '') {
            self::clearOpensslErrors();
            throw new EetSignatureException('signature_invalid', 'Hodnotu podpisu nebo certifikát nelze přečíst.');
        }
        $verified = openssl_verify(self::canonicalize($signedInfo, self::prefixList($canonicalization)), $signatureValue, $publicKey, OPENSSL_ALGO_SHA256);
        self::clearOpensslErrors();
        if ($verified !== 1) {
            throw new EetSignatureException('signature_invalid', 'Podpis zprávy neplatí.');
        }

        return ['certificatePem' => $certificatePem, 'body' => $body];
    }

    /**
     * Exclusive C14N elementu na místě v dokumentu (jmenné prostory předků se řídí PrefixList).
     *
     * @param list<string> $prefixes
     */
    public static function canonicalize(\DOMElement $element, array $prefixes): string
    {
        $canonical = $element->C14N(true, false, null, $prefixes === [] ? null : $prefixes);
        if (!is_string($canonical) || $canonical === '') {
            throw new EetSignatureException('canonicalization_failed', 'Kanonizace XML selhala.');
        }

        return $canonical;
    }

    /** @return list<string> */
    public static function prefixList(\DOMElement $method): array
    {
        foreach (EetXml::childElements($method, EetXml::NS_EXC_C14N, 'InclusiveNamespaces') as $inclusive) {
            return preg_split('/\s+/', trim($inclusive->getAttribute('PrefixList')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return [];
    }

    private static function uniqueBody(\DOMDocument $document, \DOMElement $envelope): \DOMElement
    {
        $all = $document->getElementsByTagNameNS(EetXml::NS_SOAP, 'Body');
        $direct = EetXml::childElements($envelope, EetXml::NS_SOAP, 'Body');
        if ($all->length !== 1 || count($direct) !== 1) {
            throw new EetSignatureException('body_ambiguous', 'Zpráva nemá právě jedno tělo SOAP.');
        }

        return $direct[0];
    }

    /** @return list<\DOMElement> */
    private static function elementsWithId(\DOMXPath $xpath, string $id): array
    {
        $nodes = $xpath->query(sprintf(
            '//*[@*[(local-name()="Id" or local-name()="ID" or local-name()="id") and .="%s"]]',
            $id,
        ));
        $result = [];
        foreach ($nodes ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $result[] = $node;
            }
        }

        return $result;
    }

    /**
     * Certifikát podepisujícího: přes `SecurityTokenReference` na `BinarySecurityToken`
     * v téže hlavičce, nebo přímo z `ds:X509Data`.
     */
    private static function certificate(\DOMXPath $xpath, \DOMElement $security, \DOMElement $signature): string
    {
        $base64 = null;
        $keyInfos = EetXml::childElements($signature, EetXml::NS_DS, 'KeyInfo');
        if (count($keyInfos) === 1) {
            $references = $xpath->query('./wsse:SecurityTokenReference/wsse:Reference', $keyInfos[0]);
            $x509 = $xpath->query('./ds:X509Data/ds:X509Certificate', $keyInfos[0]);
            if ($references !== false && $references->length === 1 && $references->item(0) instanceof \DOMElement) {
                $uri = $references->item(0)->getAttribute('URI');
                $id = str_starts_with($uri, '#') ? substr($uri, 1) : '';
                if (preg_match(self::ID_PATTERN, $id) !== 1) {
                    throw new EetSignatureException('certificate_missing', 'Odkaz na certifikát podepisujícího je neplatný.');
                }
                $targets = self::elementsWithId($xpath, $id);
                $token = $targets[0] ?? null;
                if (count($targets) !== 1 || $token->namespaceURI !== EetXml::NS_WSSE || $token->localName !== 'BinarySecurityToken'
                    || !$token->parentNode?->isSameNode($security)
                ) {
                    throw new EetSignatureException('certificate_missing', 'Certifikát podepisujícího není jednoznačně v hlavičce WS-Security.');
                }
                $base64 = $token->textContent;
            } elseif ($x509 !== false && $x509->length === 1) {
                $base64 = $x509->item(0)?->textContent;
            }
        } elseif ($keyInfos === []) {
            $tokens = EetXml::childElements($security, EetXml::NS_WSSE, 'BinarySecurityToken');
            if (count($tokens) === 1) {
                $base64 = $tokens[0]->textContent;
            }
        }
        $der = is_string($base64) ? base64_decode(preg_replace('/\s+/', '', $base64) ?? '', true) : false;
        if (!is_string($der) || $der === '') {
            throw new EetSignatureException('certificate_missing', 'Zpráva neobsahuje čitelný certifikát podepisujícího.');
        }
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
        if (@openssl_x509_read($pem) === false) {
            self::clearOpensslErrors();
            throw new EetSignatureException('certificate_missing', 'Certifikát podepisujícího nelze načíst.');
        }

        return $pem;
    }

    private static function one(\DOMElement $parent, string $namespace, string $localName, string $reason): \DOMElement
    {
        $elements = EetXml::childElements($parent, $namespace, $localName);
        if (count($elements) !== 1) {
            throw new EetSignatureException($reason, sprintf('Podpis nemá právě jeden element %s.', $localName));
        }

        return $elements[0];
    }

    private static function clearOpensslErrors(): void
    {
        while (openssl_error_string() !== false) {
        }
    }
}

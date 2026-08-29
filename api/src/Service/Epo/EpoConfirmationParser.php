<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

/**
 * Ověří ručně nahranou dodejku EPO a přečte, co v ní je.
 *
 * Vstup sem chodí z „Nahrát výstupy z EPO" u ASISTOVANÉHO podání, tedy ze souboru,
 * který si účetní stáhla z Daňového portálu. Bajtově je to táž potvrzenka, jakou
 * dostane přímý kanál (ZAREP) z API — a proto se kryptografie ani čtení metadat
 * NEIMPLEMENTUJE PODRUHÉ: obojí obstará {@see EpoDirectResponseParser::confirmation()}
 * a rozbalení {@see EpoConfirmationExtractor}. Dvě vlastní implementace téhož znamenaly,
 * že si obě cesty o téže potvrzence myslely něco jiného — ověřeně u shody obsahu
 * (viz níže) a u identity pečeti.
 *
 * Navíc oproti přímému kanálu se tu řeší jen to, co u ručního vstupu opravdu hrozí:
 * že účetní nahraje dodejku od JINÉHO podání. Proto se čte typ formuláře z echa
 * podání a porovnává se s tím, k čemu se soubor přikládá.
 *
 * HESLO pro dotaz na stav (`Podani/@Heslo`) se vrací pod klíčem `state_password`,
 * protože bez něj by se asistované podání nikdy nedostalo k `epo_stav` ani k opisu
 * na portálu. Volající ho MUSÍ před uložením do metadat artefaktu odstranit a nechat
 * si ho jen zašifrované ve `state_password_ciphertext`
 * (viz {@see \MyInvoice\Service\Epo\TaxSubmissionDocumentService::ingestArtifact()}).
 */
final class EpoConfirmationParser
{
    /** Nad tuhle mez už to není dodejka, ale pokus o zahlcení. */
    private const MAX_INPUT_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly EpoDirectResponseParser $responses,
        private readonly EpoConfirmationExtractor $extractor,
    ) {}

    /**
     * @return array{
     *   signature_valid:bool,chain_valid:bool,epo_signer_valid:bool,
     *   reference:?string,submitted_at:?string,state_password:?string,
     *   content_match:?bool,form_match:?bool,embedded_form_code:?string,
     *   confirmation_xml_sha256:?string,is_confirmation:bool,
     *   receipt:array<string,mixed>
     * }
     */
    public function parse(
        string $path,
        string $expectedXml,
        string $expectedFormCode,
        string $environment = 'production',
    ): array {
        $empty = [
            'signature_valid' => false,
            'chain_valid' => false,
            'epo_signer_valid' => false,
            'reference' => null,
            'submitted_at' => null,
            'state_password' => null,
            'content_match' => null,
            'form_match' => null,
            'embedded_form_code' => null,
            'confirmation_xml_sha256' => null,
            'is_confirmation' => false,
            'receipt' => [],
        ];
        if (!is_file($path) || !function_exists('openssl_cms_verify')) {
            return $empty;
        }
        $size = (int) filesize($path);
        if ($size <= 0 || $size > self::MAX_INPUT_BYTES) {
            return $empty;
        }
        $bytes = (string) file_get_contents($path);
        if ($bytes === '') {
            return $empty;
        }

        $core = $this->responses->confirmation($bytes, $expectedXml, $environment);
        if (!$core['signature_valid']) {
            return $empty;
        }
        $parts = $this->extractor->extract($bytes);

        $embeddedFormCode = $this->embeddedFormCode($parts['echo'] ?? null);
        $contentMatch = $core['content_match'];
        // Potvrzení kontrolního hlášení obsahuje ZÁMĚRNĚ redukovanou kopii. U starších
        // potvrzenek bez `Kontrola/Soubor/@KC` se proto nedá porovnat obsah, jen typ
        // formuláře — jinak by pravá dodejka skončila jako „neplatná".
        if (
            $contentMatch === false
            && strtolower($expectedFormCode) === 'dphkh1'
            && $embeddedFormCode === 'dphkh1'
        ) {
            $contentMatch = null;
        }

        return [
            'signature_valid' => true,
            'chain_valid' => $core['chain_valid'],
            'epo_signer_valid' => $core['epo_signer_valid'],
            'reference' => $core['reference'],
            'submitted_at' => $core['submitted_at'],
            'state_password' => $core['state_password'],
            'content_match' => $contentMatch,
            'form_match' => $embeddedFormCode !== null
                ? hash_equals(strtolower($expectedFormCode), $embeddedFormCode)
                : null,
            'embedded_form_code' => $embeddedFormCode,
            'confirmation_xml_sha256' => $core['confirmation_xml_sha256'],
            // Přímý kanál sem přidává i podmínku na heslo, protože bez něj by neuměl
            // dotaz na stav. Ručně nahraná dodejka je ale důkaz o přijetí i tehdy,
            // když se heslo přečíst nepodařilo — podací číslo a rozhodný čas stačí.
            'is_confirmation' => $core['reference'] !== null && $core['submitted_at'] !== null,
            'receipt' => is_array($parts['receipt'] ?? null) ? $parts['receipt'] : [],
        ];
    }

    /**
     * Kód formuláře z echa podání (`<Data>`), pokud je echo XML.
     *
     * ZIP obálku u rozsáhlých písemností schválně nerozbalujeme — typ formuláře je
     * doplňková kontrola a rozbalování archivu z ručně nahraného souboru kvůli ní
     * nestojí za rozšířenou plochu.
     *
     * @param array{bytes:string,suffix:string}|null $echo
     */
    private function embeddedFormCode(?array $echo): ?string
    {
        if ($echo === null || ($echo['suffix'] ?? '') !== 'xml') {
            return null;
        }
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $dom->loadXML($echo['bytes'], LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        if (!$loaded) {
            return null;
        }

        $known = [
            'dphdp3', 'dphkh1', 'dphshv', 'dpfdp5', 'dpfdp7', 'dppdp9', 'ossei1',
            'dpzmb1', 'dpzdb1',
        ];
        foreach ((new \DOMXPath($dom))->query('//*') ?: [] as $node) {
            $name = strtolower((string) $node->localName);
            if (in_array($name, $known, true)) {
                return $name;
            }
        }
        return null;
    }
}

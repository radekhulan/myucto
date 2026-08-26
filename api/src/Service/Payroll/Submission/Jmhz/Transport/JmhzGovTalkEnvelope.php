<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSchemaCatalog;

/**
 * Sestavení GovTalk obálky pro VREP/APEP kolem podání JMHZ.
 *
 * Doložený je jen tvar PŘÍCHOZÍ obálky (protokol ČSSZ): kořen `GovTalkMessage`
 * v jmenném prostoru GovTalk, `EnvelopeVersion` 2.0,
 * `Header/MessageDetails/{Class,Qualifier,Function}`, `GovTalkDetails` a `Body`
 * s ČSSZ obálkou `Message[@version][@eType]`, uvnitř `Header` (podpis) a `Body`
 * (užitečné zatížení). Tenhle skelet se staví přesně tak.
 *
 * Co doložené NENÍ — hodnoty `Qualifier` a `eType` pro požadavek a místo, kam
 * v obálce patří variabilní symbol — se nehádá: musí přijít jako explicitní
 * `JmhzGovTalkRequestShape`. Bez něj se obálka nepostaví vůbec.
 *
 * Identifikace software se do obálky nepřidává, protože doložené místo je
 * `VENDOR` přímo v datové větě; obálka ji jen ověřuje proti tělu. Stejně tak
 * variabilní symbol musí souhlasit s `hlavicka/variabilniSymbol` — nesoulad je
 * doložená chyba 63 a je levnější zachytit ho tady než odmítnutým podáním.
 *
 * Výstup je bajtově stabilní: nesahá na hodiny, negeneruje identifikátory
 * a tělo podání přebírá beze změny formátování.
 */
final readonly class JmhzGovTalkEnvelope
{
    public const NS_GOVTALK = 'http://www.govtalk.gov.uk/CM/envelope';
    public const NS_CSSZ_ENVELOPE = 'http://www.cssz.cz/XMLSchema/envelope';
    public const ENVELOPE_VERSION = '2.0';

    /** Doložené hodnoty `Class` z oficiálního katalogu obálek ČSSZ. */
    private const CLASSES = ['CSSZ_JMHZ', 'CSSZ_REGZEC', 'CSSZ_PREZEC'];
    private const ENVIRONMENTS = ['test', 'production'];
    private const PAYLOAD_PLACEHOLDER = 'JMHZ-PAYLOAD-SLOT-2f0a1c';

    /** SHA-2 varianta časové značky VREP; `ggdsig` je zděděná SHA-1. */
    private const TIMESTAMP_VERSION = 'xmldsig';

    /**
     * ČSSZ obálka označuje base64 obsah zděděným microsoftím jmenným prostorem
     * `dt`. Není to překlep ani ozdoba — je tak ve zveřejněném vzorku i v reálné
     * odpovědi, takže se posílá doslova.
     */
    private const NS_MS_DATATYPES = 'urn:schemas-microsoft-com:datatypes';

    public function __construct(private ?JmhzGovTalkRequestShape $shape = null) {}

    public function build(
        string $bodyXml,
        string $variableSymbol,
        string $submissionClass,
        string $environment,
        JmhzSoftwareIdentification $software,
    ): JmhzGovTalkDocument {
        $shape = $this->requireShape();
        $class = $this->assertClass($submissionClass);
        $symbol = $this->assertVariableSymbol($variableSymbol);
        $environment = $this->assertEnvironment($environment);
        $payload = $this->parsePayload($bodyXml);
        if ($class === 'CSSZ_JMHZ') {
            $this->assertJmhzPayload($payload, $symbol, $software);
        }

        $dom = $this->skeleton($class, $shape->submitQualifier, $shape, $symbol);
        // Protokol: „CorrelationID … při odeslání musí být prázdné." Přiděluje
        // ho až VREP a je to identifikátor, pod kterým se pak podání dotazuje.
        $this->requireElement($dom, 'MessageDetails', self::NS_GOVTALK)->appendChild(
            $dom->createElementNS(self::NS_GOVTALK, 'CorrelationID'),
        );
        $body = $this->requireElement($dom, 'Body', self::NS_GOVTALK);
        $body->appendChild($this->csszMessage($dom, $shape));

        return new JmhzGovTalkDocument(
            str_replace(
                self::PAYLOAD_PLACEHOLDER,
                $this->payloadXml($payload),
                $this->serialize($dom),
            ),
            $environment,
            $class,
            $symbol,
        );
    }

    /**
     * Tvar poll požadavku není doložený vůbec — ví se jen, že celkový výsledek
     * je u POX endpointu v `Qualifier` odpovědi a že se podání na APEP páruje
     * přes CorrelationID. Skelet se proto staví jen z toho, co je doložené,
     * a zbytek zase drží prohlášený tvar.
     */
    public function pollRequest(
        string $correlationId,
        string $variableSymbol,
        string $submissionClass,
        bool $close = false,
    ): string {
        $shape = $this->requireShape();
        $class = $this->assertClass($submissionClass);
        $symbol = $this->assertVariableSymbol($variableSymbol);
        $correlation = $this->assertCorrelationId($correlationId);

        // Uzavření transakce se liší FUNKCÍ, ne kvalifikátorem. Ověřeno pokusem:
        // `Qualifier=delete` vrátí „Invalid qualifier". Protokol podání přitom
        // uzavření vyžaduje — aplikace, které transakce neuzavírají, porušují
        // pravidla provozu.
        $dom = $this->skeleton(
            $class,
            $shape->pollQualifier,
            $shape,
            $symbol,
            $close ? $shape->closeFunction : $shape->function,
        );
        $details = $this->requireElement($dom, 'MessageDetails', self::NS_GOVTALK);
        $details->appendChild(
            $dom->createElementNS(self::NS_GOVTALK, 'CorrelationID', $correlation),
        );

        return $this->serialize($dom);
    }

    private function requireShape(): JmhzGovTalkRequestShape
    {
        if ($this->shape === null) {
            throw new JmhzTransportException(
                'jmhz_govtalk_shape_unverified',
                'Tvar odesílané GovTalk obálky není v podkladech ČSSZ doložený '
                    . 'a nesmí se odhadovat.',
            );
        }

        return $this->shape;
    }

    private function skeleton(
        string $class,
        string $qualifier,
        JmhzGovTalkRequestShape $shape,
        string $variableSymbol,
        ?string $function = null,
    ): DOMDocument {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $root = $dom->createElementNS(self::NS_GOVTALK, 'GovTalkMessage');
        $dom->appendChild($root);
        $root->appendChild($dom->createElementNS(
            self::NS_GOVTALK,
            'EnvelopeVersion',
            self::ENVELOPE_VERSION,
        ));

        $header = $dom->createElementNS(self::NS_GOVTALK, 'Header');
        $root->appendChild($header);
        $details = $dom->createElementNS(self::NS_GOVTALK, 'MessageDetails');
        $header->appendChild($details);
        $details->appendChild(
            $dom->createElementNS(self::NS_GOVTALK, 'Class', $class),
        );
        $details->appendChild(
            $dom->createElementNS(self::NS_GOVTALK, 'Qualifier', $qualifier),
        );
        $details->appendChild(
            $dom->createElementNS(self::NS_GOVTALK, 'Function', $function ?? $shape->function),
        );

        $govTalkDetails = $dom->createElementNS(self::NS_GOVTALK, 'GovTalkDetails');
        $root->appendChild($govTalkDetails);
        $keys = $dom->createElementNS(self::NS_GOVTALK, 'Keys');
        $govTalkDetails->appendChild($keys);
        $key = $dom->createElementNS(self::NS_GOVTALK, 'Key', $variableSymbol);
        $key->setAttribute('Type', $shape->variableSymbolKeyType);
        $keys->appendChild($key);

        // Volba algoritmu časové značky VREP. `xmldsig` znamená SHA-2,
        // `ggdsig` je zděděná varianta na SHA-1 — u nového podání nemá co dělat.
        $additions = $dom->createElementNS(self::NS_GOVTALK, 'GatewayAdditions');
        $govTalkDetails->appendChild($additions);
        $flags = $dom->createElementNS(self::NS_GOVTALK, 'Flags');
        $additions->appendChild($flags);
        $flags->appendChild($dom->createElementNS(
            self::NS_GOVTALK,
            'TimestampVersion',
            self::TIMESTAMP_VERSION,
        ));

        $root->appendChild($dom->createElementNS(self::NS_GOVTALK, 'Body'));

        return $dom;
    }

    private function csszMessage(
        DOMDocument $dom,
        JmhzGovTalkRequestShape $shape,
    ): DOMElement {
        $message = $dom->createElementNS(self::NS_CSSZ_ENVELOPE, 'Message');
        $message->setAttribute('version', $shape->bodyEnvelopeVersion);
        $message->setAttribute('eType', $shape->bodyEnvelopeType);
        // Prázdná hlavička je slot pro podpis. Vyplní ho `seal()`; kostra sem
        // nic nedoplňuje, aby nevznikl dojem, že je obálka hotová.
        $message->appendChild(
            $dom->createElementNS(self::NS_CSSZ_ENVELOPE, 'Header'),
        );
        $body = $dom->createElementNS(self::NS_CSSZ_ENVELOPE, 'Body');
        $message->appendChild($body);
        $body->appendChild($dom->createTextNode(self::PAYLOAD_PLACEHOLDER));

        return $message;
    }

    /**
     * Hotová obálka k odeslání: podepsaná odpojeným podpisem nad PŮVODNÍMI daty
     * a s tělem zkomprimovaným, zašifrovaným na certifikát ČSSZ a zakódovaným
     * do base64 — přesně v tomhle pořadí, jak předepisuje podací protokol.
     *
     * Podpis i šifra se počítají ze stejného vstupu, jaký prošel dry-runem;
     * obálka se kolem nich jen obalí, takže se nemůže rozejít to, co se
     * podepsalo, s tím, co se odeslalo.
     */
    public function seal(
        string $bodyXml,
        string $variableSymbol,
        string $submissionClass,
        string $environment,
        JmhzSoftwareIdentification $software,
        JmhzDetachedSigner $signer,
        string $pfxBytes,
        string $password,
        ?JmhzCsszEncryption $encryption = null,
    ): JmhzGovTalkDocument {
        $shape = $this->requireShape();
        $class = $this->assertClass($submissionClass);
        $symbol = $this->assertVariableSymbol($variableSymbol);
        $environment = $this->assertEnvironment($environment);
        $payload = $this->parsePayload($bodyXml);
        if ($class === 'CSSZ_JMHZ') {
            $this->assertJmhzPayload($payload, $symbol, $software);
        }
        // Podepisují a šifrují se přesně archivované bajty. Znovunačtení přes
        // DOM slouží jen ke kontrole tvaru; jeho saveXML() by zahodilo XML
        // deklaraci a sjednotilo konce řádků, takže by změnilo SHA-256 podání.
        $exact = $bodyXml;

        $signature = $signer->sign($exact, $pfxBytes, $password);
        $sealedBody = ($encryption ?? new JmhzCsszEncryption())->seal($exact);

        $dom = $this->skeleton($class, $shape->submitQualifier, $shape, $symbol);
        $this->requireElement($dom, 'MessageDetails', self::NS_GOVTALK)->appendChild(
            $dom->createElementNS(self::NS_GOVTALK, 'CorrelationID'),
        );
        $govTalkBody = $this->requireElement($dom, 'Body', self::NS_GOVTALK);

        $message = $dom->createElementNS(self::NS_CSSZ_ENVELOPE, 'Message');
        $message->setAttribute('version', $shape->bodyEnvelopeVersion);
        $message->setAttribute('eType', $shape->bodyEnvelopeType);
        $govTalkBody->appendChild($message);

        $header = $dom->createElementNS(self::NS_CSSZ_ENVELOPE, 'Header');
        $message->appendChild($header);
        $signatureNode = $dom->createElementNS(
            self::NS_CSSZ_ENVELOPE,
            'Signature',
            base64_encode($signature),
        );
        $signatureNode->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:dt',
            self::NS_MS_DATATYPES,
        );
        $signatureNode->setAttributeNS(self::NS_MS_DATATYPES, 'dt:dt', 'bin.base64');
        $header->appendChild($signatureNode);
        $vendor = $dom->createElementNS(self::NS_CSSZ_ENVELOPE, 'Vendor');
        $vendor->setAttribute('productName', $software->productName);
        $vendor->setAttribute('version', $software->productVersion);
        $header->appendChild($vendor);

        $body = $dom->createElementNS(self::NS_CSSZ_ENVELOPE, 'Body', $sealedBody);
        $body->setAttribute('encrypted', 'yes');
        $body->setAttribute('contentEncoding', 'gzip');
        $body->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:dt',
            self::NS_MS_DATATYPES,
        );
        $body->setAttributeNS(self::NS_MS_DATATYPES, 'dt:dt', 'bin.base64');
        $message->appendChild($body);

        return new JmhzGovTalkDocument(
            $this->serialize($dom),
            $environment,
            $class,
            $symbol,
            sealed: true,
        );
    }

    /**
     * Tělo podání se do obálky vkládá jako text, ne přes `importNode()`.
     * Import by kvůli výchozímu jmennému prostoru ČSSZ obálky celé datové větě
     * dopsal prefixy — XML by zůstalo platné, ale bajty už by neodpovídaly
     * tomu, co prošlo XSD dry-runem a co se bude podepisovat.
     */
    private function payloadXml(DOMDocument $payload): string
    {
        $root = $payload->documentElement;
        if ($root === null) {
            throw new JmhzTransportException(
                'jmhz_govtalk_payload_invalid',
                'Tělo podání neobsahuje kořenový element.',
            );
        }
        $xml = $payload->saveXML($root);
        if ($xml === false) {
            throw new JmhzTransportException(
                'jmhz_govtalk_payload_invalid',
                'Tělo podání nelze serializovat zpět.',
            );
        }

        return $xml;
    }

    private function parsePayload(string $bodyXml): DOMDocument
    {
        if (trim($bodyXml) === '') {
            throw new JmhzTransportException(
                'jmhz_govtalk_payload_invalid',
                'Tělo podání nesmí být prázdné.',
            );
        }
        if (str_contains($bodyXml, self::PAYLOAD_PLACEHOLDER)) {
            throw new JmhzTransportException(
                'jmhz_govtalk_payload_invalid',
                'Tělo podání obsahuje vyhrazený zástupný text obálky.',
            );
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $dom->loadXML($bodyXml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $dom->documentElement === null) {
            throw new JmhzTransportException(
                'jmhz_govtalk_payload_invalid',
                'Tělo podání není platné XML.',
            );
        }

        return $dom;
    }

    private function assertJmhzPayload(
        DOMDocument $payload,
        string $variableSymbol,
        JmhzSoftwareIdentification $software,
    ): void {
        $root = $payload->documentElement;
        if ($root === null
            || $root->namespaceURI !== JmhzSchemaCatalog::NS_PODANI
            || $root->localName !== 'jmhz'
        ) {
            throw new JmhzTransportException(
                'jmhz_govtalk_payload_invalid',
                'Tělo podání JMHZ musí mít kořen `jmhz` v jmenném prostoru podání.',
            );
        }

        $xpath = new DOMXPath($payload);
        $xpath->registerNamespace('p', JmhzSchemaCatalog::NS_PODANI);
        $vendor = $xpath->query('/p:jmhz/p:VENDOR')->item(0);
        if (!$vendor instanceof DOMElement
            || $vendor->getAttribute('productName') !== $software->productName
            || $vendor->getAttribute('productVersion') !== $software->productVersion
        ) {
            throw new JmhzTransportException(
                'jmhz_govtalk_vendor_mismatch',
                'Identifikace software v obálce neodpovídá elementu VENDOR v podání.',
            );
        }

        $symbol = $xpath->query('/p:jmhz/p:hlavicka/p:variabilniSymbol')->item(0);
        if ($symbol === null || $symbol->textContent !== $variableSymbol) {
            throw new JmhzTransportException(
                'jmhz_govtalk_variable_symbol_mismatch',
                'Variabilní symbol obálky neodpovídá variabilnímu symbolu v hlavičce podání.',
            );
        }
    }

    private function assertClass(string $submissionClass): string
    {
        if (!in_array($submissionClass, self::CLASSES, true)) {
            throw new JmhzTransportException(
                'jmhz_govtalk_class_unknown',
                'Druh podání není mezi doloženými hodnotami GovTalk `Class`.',
            );
        }

        return $submissionClass;
    }

    private function assertVariableSymbol(string $variableSymbol): string
    {
        if (preg_match('/^[0-9]{10}$/D', $variableSymbol) !== 1) {
            throw new JmhzTransportException(
                'jmhz_govtalk_variable_symbol_invalid',
                'Variabilní symbol zaměstnavatele musí mít přesně deset číslic.',
            );
        }

        return $variableSymbol;
    }

    private function assertEnvironment(string $environment): string
    {
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new JmhzTransportException(
                'jmhz_govtalk_environment_unknown',
                'Prostředí podání musí být `test` nebo `production`.',
            );
        }

        return $environment;
    }

    private function assertCorrelationId(string $correlationId): string
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $correlationId) !== 1) {
            throw new JmhzTransportException(
                'jmhz_vrep_correlation_invalid',
                'CorrelationID podání není v přípustném tvaru.',
            );
        }

        return $correlationId;
    }

    private function requireElement(
        DOMDocument $dom,
        string $localName,
        string $namespace,
    ): DOMElement {
        $node = $dom->getElementsByTagNameNS($namespace, $localName)->item(0);
        if (!$node instanceof DOMElement) {
            throw new JmhzTransportException(
                'jmhz_govtalk_envelope_incomplete',
                "V sestavené obálce chybí element `{$localName}`.",
            );
        }

        return $node;
    }

    private function serialize(DOMDocument $dom): string
    {
        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new JmhzTransportException(
                'jmhz_govtalk_serialization_failed',
                'GovTalk obálku nelze serializovat.',
            );
        }

        return rtrim($xml, "\r\n");
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ares;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * CRPDPH lookup — registr plátců DPH (MFČR), operace `getStatusNespolehlivyPlatce`.
 *
 * Pro tuzemské DIČ vrací:
 *  - seznam ZVEŘEJNĚNÝCH bankovních účtů (standardní `předčíslí-číslo/kódBanky`
 *    nebo nestandardní IBAN),
 *  - příznak „nespolehlivý plátce" (ANO/NE/NENALEZEN).
 *
 * SOAP 1.1, document/literal. Místo `\SoapClient` (runtime fetch WSDL + křehké
 * mapování atributů na vlastnosti) posíláme ručně sestavenou obálku přes Guzzle
 * a odpověď parsujeme přes `local-name()` XPath — odolné vůči namespace prefixům
 * i tomu, zda jsou data atributy nebo elementy. Parser je čistě testovatelný
 * (viz CrpDphClientTest) bez síťového volání.
 *
 * Cache: crpdph_cache 24h (klíč = DIČ bez „CZ", jen číslice). Funguje jen pro CZ
 * plátce a jen pro účty, které plátce sám zveřejnil → best-effort předvyplnění.
 */
final class CrpDphClient
{
    private const NS_REQ = 'http://adis.mfcr.cz/rozhraniCRPDPH/';

    public function __construct(
        private readonly Config $config,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {}

    /** Skupinové české DIČ podle již normalizované číselné části bez prefixu CZ. */
    public static function isGroupRegistration(string $number): bool
    {
        return preg_match('/^699\d{6}$/D', $number) === 1;
    }

    /**
     * @return array{found:bool, unreliable:?bool, accounts:list<array{prefix:string, number:string, bank_code:string, iban:?string, display:string}>, fu_code:string, source:'cache'|'fresh'|'error'}
     */
    public function lookup(string $dic): array
    {
        $dic = $this->normalizeDic($dic);
        if ($dic === null) {
            return ['found' => false, 'unreliable' => null, 'accounts' => [], 'fu_code' => '', 'source' => 'error'];
        }

        $cached = $this->fromCache($dic);
        if ($cached !== null) {
            $cached['source'] = 'cache';
            return $cached;
        }

        $endpoint = (string) $this->config->get('crpdph.endpoint', '');
        if ($endpoint === '') {
            $this->logger->warning('CRPDPH endpoint není nakonfigurovaný — lookup přeskočen', ['dic' => $dic]);
            return ['found' => false, 'unreliable' => null, 'accounts' => [], 'fu_code' => '', 'source' => 'error'];
        }
        $timeout = (int) $this->config->get('crpdph.timeout', 8);

        try {
            $client = new Client(['timeout' => $timeout, 'connect_timeout' => $timeout]);
            $resp = $client->post($endpoint, [
                'http_errors' => false,
                'headers' => [
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'SOAPAction'   => '',
                ],
                'body' => $this->buildEnvelope($dic),
            ]);
            if ($resp->getStatusCode() !== 200) {
                $this->logger->warning('CRPDPH vrátilo neočekávaný status', ['dic' => $dic, 'status' => $resp->getStatusCode()]);
                return ['found' => false, 'unreliable' => null, 'accounts' => [], 'fu_code' => '', 'source' => 'error'];
            }

            $parsed = self::parseResponse((string) $resp->getBody(), $dic);
            $parsed['source'] = 'fresh';
            $this->cache($dic, $parsed);
            return $parsed;
        } catch (GuzzleException $e) {
            $this->logger->warning('CRPDPH služba nedostupná: ' . $e->getMessage(), ['dic' => $dic]);
            return ['found' => false, 'unreliable' => null, 'accounts' => [], 'fu_code' => '', 'source' => 'error'];
        }
    }

    /**
     * Vstup může být „CZ12345678" i „12345678". Služba chce jen číslice (CZ plátci
     * mají 8–10 číslic). Non-CZ DIČ tento registr neeviduje → null (přeskočíme).
     */
    private function normalizeDic(string $dic): ?string
    {
        $digits = preg_replace('/\D/', '', $dic) ?? '';
        return preg_match('/^\d{8,10}$/', $digits) ? $digits : null;
    }

    private function buildEnvelope(string $dic): string
    {
        $dicEsc = htmlspecialchars($dic, ENT_XML1);
        $ns = self::NS_REQ;
        return <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:r="{$ns}">
              <soapenv:Header/>
              <soapenv:Body>
                <r:StatusNespolehlivyPlatceRequest>
                  <r:dic>{$dicEsc}</r:dic>
                </r:StatusNespolehlivyPlatceRequest>
              </soapenv:Body>
            </soapenv:Envelope>
            XML;
    }

    /**
     * Robustní parser odpovědi. `statusPlatceDPH` nese atributy
     * `nespolehlivyPlatce` (ANO/NE/NENALEZEN), `dic`, `cisloFu`. Účty jsou ve
     * `zverejneneUcty/ucet`, každý buď `standardniUcet` (predcisli/cislo/kodBanky)
     * nebo `nestandardniUcet` (IBAN). Bereme atributy i fallback na child elementy.
     *
     * @return array{found:bool, unreliable:?bool, accounts:list<array{prefix:string, number:string, bank_code:string, iban:?string, display:string}>, fu_code:string}
     */
    public static function parseResponse(string $xml, string $dic): array
    {
        $empty = ['found' => false, 'unreliable' => null, 'accounts' => [], 'fu_code' => ''];
        if (trim($xml) === '') {
            return $empty;
        }

        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        // LIBXML_NONET — zakázat síťový přístup při parsování (defenzivní parita
        // s ISDOC/Pohoda parsery; externí entity jsou na PHP 8 default off, host je pinned).
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            return $empty;
        }

        $xp = new \DOMXPath($dom);
        $statuses = $xp->query("//*[local-name()='statusPlatceDPH']");
        if ($statuses === false || $statuses->length === 0) {
            return $empty;
        }

        // Vyber uzel odpovídající dotázanému DIČ; fallback na první.
        $node = null;
        foreach ($statuses as $s) {
            if ($s instanceof \DOMElement && self::attrOrChild($xp, $s, 'dic') === $dic) {
                $node = $s;
                break;
            }
        }
        if ($node === null && $statuses->item(0) instanceof \DOMElement) {
            $node = $statuses->item(0);
        }
        if (!$node instanceof \DOMElement) {
            return $empty;
        }

        // Kód finančního úřadu (EPO c_ufo, systém 451–465) — autoritativní pro přiznání.
        $fuCode = self::attrOrChild($xp, $node, 'cisloFu');
        $status = strtoupper(trim(self::attrOrChild($xp, $node, 'nespolehlivyPlatce')));
        $unreliable = match ($status) {
            'ANO' => true,
            'NE'  => false,
            default => null, // NENALEZEN / prázdné
        };
        $found = $status !== '' && $status !== 'NENALEZEN';

        $accounts = [];
        $ucty = $xp->query(".//*[local-name()='ucet']", $node);
        if ($ucty !== false) {
            foreach ($ucty as $ucet) {
                if (!$ucet instanceof \DOMElement) {
                    continue;
                }
                $std = $xp->query("./*[local-name()='standardniUcet']", $ucet);
                $nonStd = $xp->query("./*[local-name()='nestandardniUcet']", $ucet);

                if ($std !== false && $std->item(0) instanceof \DOMElement) {
                    $s = $std->item(0);
                    $prefix = self::attrOrChild($xp, $s, 'predcisli');
                    $number = self::attrOrChild($xp, $s, 'cislo');
                    $bankCode = self::attrOrChild($xp, $s, 'kodBanky');
                    if ($number === '' && $bankCode === '') {
                        continue;
                    }
                    $display = ($prefix !== '' ? $prefix . '-' : '') . $number . '/' . $bankCode;
                    $accounts[] = [
                        'prefix'    => $prefix,
                        'number'    => $number,
                        'bank_code' => $bankCode,
                        'iban'      => null,
                        'display'   => $display,
                    ];
                } elseif ($nonStd !== false && $nonStd->item(0) instanceof \DOMElement) {
                    $n = $nonStd->item(0);
                    // MFČR reálně vrací IBAN v atributu `cislo` (ne `IBAN`/`iban`);
                    // držíme i fallbacky pro případ změny schématu.
                    $iban = self::attrOrChild($xp, $n, 'cislo');
                    if ($iban === '') {
                        $iban = self::attrOrChild($xp, $n, 'IBAN');
                    }
                    if ($iban === '') {
                        $iban = self::attrOrChild($xp, $n, 'iban');
                    }
                    if ($iban === '') {
                        continue;
                    }
                    $accounts[] = [
                        'prefix'    => '',
                        'number'    => '',
                        'bank_code' => '',
                        'iban'      => $iban,
                        'display'   => $iban,
                    ];
                }
            }
        }

        return ['found' => $found, 'unreliable' => $unreliable, 'accounts' => $accounts, 'fu_code' => $fuCode];
    }

    /**
     * Hodnota dat může přijít jako atribut nebo jako child element — zkus obojí.
     */
    private static function attrOrChild(\DOMXPath $xp, \DOMElement $el, string $name): string
    {
        if ($el->hasAttribute($name)) {
            return trim($el->getAttribute($name));
        }
        $child = $xp->query("./*[local-name()='{$name}']", $el);
        if ($child !== false && $child->item(0) !== null) {
            return trim((string) $child->item(0)->textContent);
        }
        return '';
    }

    /**
     * @return array{found:bool, unreliable:?bool, accounts:list<array<string,mixed>>, source:string}|null
     */
    private function fromCache(string $dic): ?array
    {
        $ttl = (int) $this->config->get('crpdph.cache_ttl', 86400);
        $stmt = $this->db->pdo()->prepare(
            'SELECT payload FROM crpdph_cache WHERE dic = ? AND fetched_at > NOW() - INTERVAL ? SECOND'
        );
        $stmt->execute([$dic, $ttl]);
        $row = $stmt->fetchColumn();
        if ($row === false) {
            return null;
        }
        $data = json_decode((string) $row, true);
        return is_array($data) ? $data : null;
    }

    private function cache(string $dic, array $payload): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO crpdph_cache (dic, payload) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()'
        );
        $stmt->execute([$dic, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }
}

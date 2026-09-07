<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use DOMDocument;
use DOMElement;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

final class CsobBusinessConnectorClient
{
    private const SOAP_NS = 'http://schemas.xmlsoap.org/soap/envelope/';
    private const SERVICE_NS = 'http://ceb-bc.csob.cz/CEBBCWS/';
    private const MAX_XML_BYTES = 2 * 1024 * 1024;
    private const MAX_FILE_BYTES = 10 * 1024 * 1024;
    private const MAX_ABO_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly ClientInterface $http, private readonly bool $sandbox = false) {}

    /**
     * @param array{contract_number:string,curl_options:array<int,mixed>} $credentials
     * @param array{prev_query_timestamp?:string,file_types?:list<string>,file_formats?:list<string>,file_name?:string,created_after?:string,created_before?:string,client_app_guid?:string} $criteria
     * @return array{query_timestamp:string,ticket_id:string,files:list<array{filename:string,type:string,format:?string,creation_date_time:string,size:int,status:string,url:?string,upload_file_hash:?string}>}
     */
    public function listFiles(#[\SensitiveParameter] array $credentials, array $criteria = []): array
    {
        $data = ['ContractNumber' => $this->contractNumber($credentials)];
        $allowed = ['prev_query_timestamp', 'file_types', 'file_formats', 'file_name', 'created_after', 'created_before', 'client_app_guid'];
        if (array_diff(array_keys($criteria), $allowed) !== []) {
            throw $this->invalidInput();
        }
        if (isset($criteria['prev_query_timestamp'])) {
            $data['PrevQueryTimestamp'] = $this->timestamp($criteria['prev_query_timestamp'], true);
        }
        $filter = [];
        foreach (['file_types' => ['FileTypes', 'FileType'], 'file_formats' => ['FileFormats', 'FileFormat']] as $key => [$group, $item]) {
            if (!isset($criteria[$key])) continue;
            if (!is_array($criteria[$key]) || !array_is_list($criteria[$key]) || count($criteria[$key]) < 1 || count($criteria[$key]) > 20) {
                throw $this->invalidInput();
            }
            foreach ($criteria[$key] as $value) {
                if (!is_string($value) || preg_match('/^[A-Z0-9_ ]{1,35}$/D', $value) !== 1) throw $this->invalidInput();
            }
            $filter[$group] = [$item => array_values(array_unique($criteria[$key]))];
        }
        if (isset($criteria['file_name'])) {
            if (!is_string($criteria['file_name']) || !$this->validFilename($criteria['file_name'])) throw $this->invalidInput();
            $filter['FileName'] = $criteria['file_name'];
        }
        foreach (['created_after' => 'CreatedAfter', 'created_before' => 'CreatedBefore'] as $key => $field) {
            if (isset($criteria[$key])) $filter[$field] = $this->timestamp($criteria[$key], true);
        }
        if (isset($criteria['created_after'], $criteria['created_before'])
            && new \DateTimeImmutable($criteria['created_after']) > new \DateTimeImmutable($criteria['created_before'])) {
            throw $this->invalidInput();
        }
        if (isset($criteria['client_app_guid'])) $filter['ClientAppGuid'] = $this->guid($criteria['client_app_guid']);
        if ($filter !== []) $data['Filter'] = $filter;

        $root = $this->soap($credentials, 'GetDownloadFileList_v4', $data);
        $files = [];
        $list = $this->child($root, 'FileList', false);
        foreach ($list === null ? [] : $this->children($list, 'FileDetail') as $file) {
            if (count($files) >= 5000) throw $this->invalidResponse();
            $status = $this->text($file, 'Status');
            $filename = $this->text($file, 'Filename');
            $url = $this->text($file, 'Url', false);
            $size = $this->text($file, 'Size');
            $hash = $this->text($file, 'UploadFileHash', false);
            if (!in_array($status, ['R', 'D', 'F'], true) || !$this->validFilename($filename)
                || preg_match('/^\d{1,10}$/D', $size) !== 1
                || ($hash !== null && preg_match('/^[a-fA-F0-9]{64}$/D', $hash) !== 1)) throw $this->invalidResponse();
            if ($status === 'D' && $url === null) throw $this->invalidResponse();
            if ($url !== null) $this->assertTransferUrl($url, false);
            $files[] = [
                'filename' => $filename, 'type' => $this->text($file, 'Type'),
                'format' => $this->text($file, 'Format', false),
                'creation_date_time' => $this->timestamp($this->text($file, 'CreationDateTime')),
                'size' => (int) $size, 'status' => $status, 'url' => $url, 'upload_file_hash' => $hash,
            ];
        }
        return [
            'query_timestamp' => $this->timestamp($this->text($root, 'QueryTimestamp')),
            'ticket_id' => $this->text($root, 'TicketId'), 'files' => $files,
        ];
    }

    /** @param array{contract_number:string,curl_options:array<int,mixed>} $credentials */
    public function downloadFile(#[\SensitiveParameter] array $credentials, #[\SensitiveParameter] array $file): string
    {
        if (($file['status'] ?? null) !== 'D' || !is_string($file['url'] ?? null)
            || !is_int($file['size'] ?? null) || $file['size'] < 0 || $file['size'] > self::MAX_FILE_BYTES) {
            throw $this->invalidInput();
        }
        $this->assertTransferUrl($file['url'], false);
        $response = $this->request($credentials, 'GET', $file['url'], ['headers' => ['Accept' => 'application/octet-stream']], false);
        $body = $this->readBody($response, self::MAX_FILE_BYTES);
        if (strlen($body) !== $file['size']) throw $this->invalidResponse();
        return $body;
    }

    /**
     * @param array{contract_number:string,curl_options:array<int,mixed>} $credentials
     * @return array{status:string,reference:string,upload_file_hash:string,client_app_guid:string}
     */
    public function submitUnsignedAbo(#[\SensitiveParameter] array $credentials, #[\SensitiveParameter] string $abo, string $clientGuid): array
    {
        $contract = $this->contractNumber($credentials);
        $this->certificateOptions($credentials);
        $guid = $this->guid($clientGuid);
        if ($abo === '' || strlen($abo) > self::MAX_ABO_BYTES || str_contains($abo, "\0")
            || !str_starts_with($abo, 'UHL1') || !str_ends_with($abo, "3 +\r\n5 +\r\n")
            || preg_match('/(?<!\r)\n|\r(?!\n)/', $abo) === 1 || substr_count($abo, "\r\n") < 6) {
            throw new BankConnectorException(BankConnectorException::INVALID_PAYMENT_ORDER, 'Platební příkaz ABO není platný.');
        }
        $hash = hash('sha256', $abo);
        $filename = 'myucto-' . substr($hash, 0, 32) . '.abo';
        try {
            $start = $this->soap($credentials, 'StartUploadFileList_v3', [
                'ContractNumber' => $contract, 'ClientAppGuid' => $guid,
                'FileList' => ['ImportFileDetail' => [[
                    'Filename' => $filename, 'Hash' => $hash, 'Size' => strlen($abo), 'Format' => 'ABO',
                    'Mode' => 'AllOrNothing', 'SkipCheckDuplicates' => 'false',
                ]]],
            ]);
            $file = $this->uploadResult($start, 'FileUrl', $filename, $hash);
            $status = $this->text($file, 'Status');
            if ($status === 'R') return $this->result('rejected', $start, $hash, $guid);
            if ($status !== 'U') throw $this->invalidResponse();
            $url = $this->text($file, 'Url');
            $this->assertTransferUrl($url, true);
            $response = $this->request($credentials, 'POST', $url, [
                'headers' => ['Accept' => 'application/json'],
                'multipart' => [[
                    'name' => 'fileupload', 'contents' => $abo, 'filename' => $filename,
                    'headers' => ['Content-Type' => 'application/octet-stream'],
                ]],
            ], false);
            $decoded = json_decode($this->readBody($response, self::MAX_XML_BYTES), true);
            if (!is_array($decoded) || !in_array((string) ($decoded['Status'] ?? ''), ['200', '201'], true)
                || !is_string($decoded['NewFileId'] ?? null) || strlen($decoded['NewFileId']) > 2048
                || preg_match('/^[a-zA-Z0-9_%-]+={0,2}$/D', $decoded['NewFileId']) !== 1) throw $this->invalidResponse();
            $finish = $this->soap($credentials, 'FinishUploadFileList_v2', [
                'ContractNumber' => $contract, 'ClientAppGuid' => $guid,
                'FileList' => ['FileId' => [['Filename' => $filename, 'Hash' => $hash, 'NewFileId' => $decoded['NewFileId']]]],
            ]);
            $finished = $this->uploadResult($finish, 'FileStatus', $filename, $hash);
            return match ($this->text($finished, 'Status')) {
                'I' => $this->result('import_started', $finish, $hash, $guid),
                'R' => $this->result('rejected', $finish, $hash, $guid),
                default => throw $this->invalidResponse(),
            };
        } catch (BankConnectorException $e) {
            throw new BankConnectorException($e->errorCode, $e->getMessage(), true, $e->remoteHttpStatus);
        }
    }

    private function uploadResult(DOMElement $root, string $element, string $filename, string $hash): DOMElement
    {
        $rows = $this->children($this->child($root, 'FileList'), $element);
        if (count($rows) !== 1 || $this->text($rows[0], 'Filename') !== $filename
            || strtolower($this->text($rows[0], 'Hash')) !== $hash) throw $this->invalidResponse();
        return $rows[0];
    }

    private function result(string $status, DOMElement $root, string $hash, string $guid): array
    {
        return ['status' => $status, 'reference' => $this->text($root, 'TicketId'), 'upload_file_hash' => $hash, 'client_app_guid' => $guid];
    }

    private function soap(#[\SensitiveParameter] array $credentials, string $operation, #[\SensitiveParameter] array $data): DOMElement
    {
        $namespace = self::SERVICE_NS . $operation;
        $document = new DOMDocument('1.0', 'UTF-8');
        $envelope = $document->appendChild($document->createElementNS(self::SOAP_NS, 's:Envelope'));
        $body = $envelope->appendChild($document->createElementNS(self::SOAP_NS, 's:Body'));
        $request = $body->appendChild($document->createElementNS($namespace, 'b:' . str_replace('_v', 'Request_v', $operation)));
        $this->appendFields($request, $namespace, $data);
        $response = $this->request($credentials, 'POST', 'https://' . $this->host() . '/cebbc/api', [
            'headers' => ['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => '"' . $operation . '"', 'Accept' => 'text/xml'],
            'body' => $document->saveXML(),
        ], true);
        $xml = $this->readBody($response, self::MAX_XML_BYTES);
        if ($xml === '' || str_contains($xml, "\0") || preg_match('/<!\s*(DOCTYPE|ENTITY)\b/i', $xml) === 1) throw $this->invalidResponse();
        $parsed = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$parsed->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) || $parsed->doctype !== null) throw $this->invalidResponse();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $parsed->documentElement;
        if ($root === null || $root->namespaceURI !== self::SOAP_NS || $root->localName !== 'Envelope') throw $this->invalidResponse();
        $soapBody = $this->child($root, 'Body');
        $elements = array_values(array_filter(iterator_to_array($soapBody->childNodes), static fn ($node): bool => $node instanceof DOMElement));
        if (count($elements) !== 1) throw $this->invalidResponse();
        $result = $elements[0];
        if ($result->namespaceURI === self::SOAP_NS && $result->localName === 'Fault') {
            $codes = $result->getElementsByTagNameNS(self::SERVICE_NS . 'CEBBCError_v2', 'Code');
            $code = $codes->length === 1 ? trim($codes->item(0)->textContent) : '';
            throw new BankConnectorException(
                $code === '1101' ? BankConnectorException::RATE_LIMITED : 'csob_soap_fault',
                $code === '1101' ? 'ČSOB dočasně omezila počet požadavků.' : 'ČSOB požadavek odmítla. Ověřte oprávnění certifikátu a smlouvy.',
            );
        }
        if ($response->getStatusCode() >= 300 || $result->namespaceURI !== $namespace
            || $result->localName !== str_replace('_v', 'Response_v', $operation)) throw $this->invalidResponse();
        return $result;
    }

    private function appendFields(DOMElement $parent, string $namespace, array $data): void
    {
        foreach ($data as $name => $value) {
            foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $item) {
                $element = $parent->appendChild($parent->ownerDocument->createElementNS($namespace, 'b:' . $name));
                if (is_array($item)) $this->appendFields($element, $namespace, $item);
                else $element->appendChild($parent->ownerDocument->createTextNode((string) $item));
            }
        }
    }

    private function request(#[\SensitiveParameter] array $credentials, string $method, #[\SensitiveParameter] string $url, #[\SensitiveParameter] array $options, bool $soap): ResponseInterface
    {
        $this->contractNumber($credentials);
        $limit = !$soap && $method === 'GET' ? self::MAX_FILE_BYTES : self::MAX_XML_BYTES;
        $memory = Utils::streamFor(Utils::tryFopen('php://memory', 'w+'));
        $bytes = 0;
        $tooLarge = false;
        $curlError = 0;
        $rejectLarge = static function () use (&$tooLarge): never {
            $tooLarge = true;
            throw new BankConnectorException(BankConnectorException::RESPONSE_TOO_LARGE, 'Odpověď ČSOB překročila povolenou velikost.');
        };
        $sink = FnStream::decorate($memory, ['write' => static function (string $data) use ($memory, $limit, &$bytes, $rejectLarge): int {
            if (strlen($data) > $limit - $bytes) $rejectLarge();
            $bytes += strlen($data);
            return $memory->write($data);
        }]);
        $options += [
            'curl' => $this->certificateOptions($credentials), 'allow_redirects' => false,
            'connect_timeout' => 5.0, 'timeout' => 30.0, 'version' => '1.1', 'verify' => true, 'http_errors' => false, 'stream' => false, 'debug' => false,
            'sink' => $sink,
            'on_stats' => static function (\GuzzleHttp\TransferStats $stats) use (&$curlError): void {
                $value = $stats->getHandlerErrorData();
                $curlError = is_int($value) ? $value : 0;
            },
            'on_headers' => static function (ResponseInterface $response) use ($limit, $rejectLarge): void {
                $length = $response->getHeaderLine('Content-Length');
                if ($length !== '' && (!ctype_digit($length) || (float) $length > $limit)) $rejectLarge();
            },
            'progress' => static function (int|float $total, int|float $downloaded, int|float $uploadTotal, int|float $uploaded) use ($limit, $rejectLarge): void {
                if ($total > $limit || $downloaded > $limit) $rejectLarge();
            },
        ];
        try {
            $response = $this->http->request($method, $url, $options);
        } catch (\Throwable) {
            if ($tooLarge) $rejectLarge();
            $code = match ($curlError) {
                6 => 'bank_dns_failed',
                7 => 'bank_connect_failed',
                28 => 'bank_timeout',
                35 => 'bank_tls_handshake_failed',
                58 => 'bank_client_certificate_failed',
                60 => 'bank_server_certificate_failed',
                77 => 'bank_ca_configuration_failed',
                default => BankConnectorException::REMOTE_UNAVAILABLE,
            };
            throw new BankConnectorException($code, 'Spojení s ČSOB se nepodařilo dokončit.');
        }
        $status = $response->getStatusCode();
        if (($status < 200 || $status >= 300) && !($soap && $status === 500)) {
            try { $response->getBody()->close(); } catch (\Throwable) {}
            throw new BankConnectorException(BankConnectorException::REMOTE_HTTP_ERROR, 'ČSOB vrátila neúspěšnou HTTP odpověď.', false, $status);
        }
        return $response;
    }

    private function readBody(#[\SensitiveParameter] ResponseInterface $response, int $limit): string
    {
        $stream = $response->getBody();
        try {
            if ($stream->isSeekable()) $stream->rewind();
            $size = $stream->getSize();
            if ($size !== null && $size > $limit) throw new BankConnectorException(BankConnectorException::RESPONSE_TOO_LARGE, 'Odpověď ČSOB překročila povolenou velikost.');
            $result = '';
            while (!$stream->eof()) {
                $chunk = $stream->read(min(8192, $limit + 1 - strlen($result)));
                if ($chunk === '' && !$stream->eof()) throw $this->invalidResponse();
                $result .= $chunk;
                if (strlen($result) > $limit) throw new BankConnectorException(BankConnectorException::RESPONSE_TOO_LARGE, 'Odpověď ČSOB překročila povolenou velikost.');
            }
            return $result;
        } catch (BankConnectorException $e) {
            throw $e;
        } catch (\Throwable) {
            throw $this->invalidResponse();
        } finally {
            try { $stream->close(); } catch (\Throwable) {}
        }
    }

    private function host(): string
    {
        return $this->sandbox ? 'testceb-bc.csob.cz' : 'ceb-bc.csob.cz';
    }

    private function assertTransferUrl(#[\SensitiveParameter] string $url, bool $upload): void
    {
        $parts = parse_url($url);
        $path = $this->sandbox ? '/ceb-mock/' . ($upload ? 'upload' : 'download') : ($upload ? '/ExtFileHubUp/v2/upload' : '/ExtFileHubDown/v2/download');
        if (!is_array($parts) || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1
            || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== $this->host()
            || ($parts['path'] ?? '') !== $path || ($parts['port'] ?? 443) !== 443
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) throw $this->invalidResponse();
        parse_str($parts['query'] ?? '', $query);
        if (array_keys($query) !== ['id'] || !is_string($query['id']) || $query['id'] === '' || strlen($query['id']) > 2048
            || preg_match('/[\x00-\x1f\x7f]/', $query['id']) === 1) throw $this->invalidResponse();
    }

    private function contractNumber(#[\SensitiveParameter] array $credentials): string
    {
        $value = $credentials['contract_number'] ?? null;
        if (!is_string($value) || preg_match('/^[0-9]{1,18}$/D', $value) !== 1 || (int) $value <= 0) throw $this->invalidInput();
        return $value;
    }

    private function certificateOptions(#[\SensitiveParameter] array $credentials): array
    {
        if (!defined('CURLOPT_SSLCERT_BLOB') || !defined('CURLOPT_SSLKEY_BLOB')) throw $this->invalidInput();
        $options = $credentials['curl_options'] ?? null;
        $keys = [CURLOPT_SSLCERTTYPE, CURLOPT_SSLKEYTYPE, CURLOPT_SSLCERT_BLOB, CURLOPT_SSLKEY_BLOB];
        if (!is_array($options) || array_diff(array_keys($options), $keys) !== [] || count($options) !== 4
            || ($options[CURLOPT_SSLCERTTYPE] ?? '') !== 'PEM' || ($options[CURLOPT_SSLKEYTYPE] ?? '') !== 'PEM') throw $this->invalidInput();
        foreach ([CURLOPT_SSLCERT_BLOB, CURLOPT_SSLKEY_BLOB] as $key) {
            if (!is_string($options[$key] ?? null) || $options[$key] === '' || strlen($options[$key]) > 128 * 1024) throw $this->invalidInput();
        }
        return $options + [CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2];
    }

    private function guid(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-fA-F0-9]{8}(?:-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12}$/D', $value) !== 1) throw $this->invalidInput();
        return $value;
    }

    private function timestamp(mixed $value, bool $input = false): string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(Z|[+-](\d{2}):(\d{2}))$/D', $value, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59
            || (isset($m[8]) && ((int) $m[8] > 14 || (int) $m[9] > 59 || ((int) $m[8] === 14 && (int) $m[9] !== 0)))) {
            throw $input ? $this->invalidInput() : $this->invalidResponse();
        }
        return $value;
    }

    private function validFilename(string $filename): bool
    {
        return $filename !== '' && strlen($filename) <= 250 && preg_match('/[\x00-\x1f\x7f\/\\\\]/', $filename) !== 1;
    }

    private function children(DOMElement $parent, string $name): array
    {
        $result = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name && $node->namespaceURI === $parent->namespaceURI) $result[] = $node;
        }
        return $result;
    }

    private function child(DOMElement $parent, string $name, bool $required = true): ?DOMElement
    {
        $nodes = $this->children($parent, $name);
        if (count($nodes) > 1 || ($required && count($nodes) !== 1)) throw $this->invalidResponse();
        return $nodes[0] ?? null;
    }

    private function text(DOMElement $parent, string $name, bool $required = true): ?string
    {
        $node = $this->child($parent, $name, $required);
        if ($node === null) return null;
        foreach ($node->childNodes as $child) if ($child instanceof DOMElement) throw $this->invalidResponse();
        $value = trim($node->textContent);
        if ($value === '' || strlen($value) > 4096) throw $this->invalidResponse();
        return $value;
    }

    private function invalidInput(): BankConnectorException
    {
        return new BankConnectorException('csob_invalid_input', 'Nastavení nebo parametry ČSOB Business Connector nejsou platné.');
    }

    private function invalidResponse(): BankConnectorException
    {
        return new BankConnectorException(BankConnectorException::INVALID_RESPONSE, 'ČSOB vrátila neplatnou nebo neočekávanou odpověď.');
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Fio API přes token. Pohyby se stahují ve formátu Fio XML, ne GPC: GPC u zahraničních
 * plateb ořízne IBAN protistrany a nenese zprávu pro příjemce (#222). Výpis se ukládá
 * jako kompaktní JSON ({@see FioTransactionParser}) a importuje jako `bank_api`.
 */
final class FioBankConnector implements StructuredBankConnector
{
    private const BASE_URL = 'https://fioapi.fio.cz/v1/rest/';
    private const MAX_STATEMENT_BYTES = 10 * 1024 * 1024;
    private const MAX_IMPORT_RESPONSE_BYTES = 512 * 1024;
    private const MAX_ABO_BYTES = 2 * 1024 * 1024;
    private const MAX_EMPTY_READS = 200;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly ?LoggerInterface $logger = null,
        private readonly FioTransactionParser $parser = new FioTransactionParser(),
    )
    {
    }

    public function provider(): string
    {
        return 'fio';
    }

    public function statementFormat(): string
    {
        return 'json';
    }

    /**
     * Fio přihlašuje jen tokenem; uložený zůstává holý token, takže dřívější napojení
     * platí beze změny.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $account
     */
    public function credentials(#[\SensitiveParameter] array $input, array $account, #[\SensitiveParameter] ?string $existing): string
    {
        if ($input !== []) {
            throw new BankConnectorOperationException('credential_format_invalid');
        }
        $token = trim((string) $existing);
        if ($token === '') {
            throw new BankConnectorOperationException('token_required');
        }
        if (preg_match('/^[A-Za-z0-9]{64}$/D', $token) !== 1) {
            throw new BankConnectorOperationException('token_invalid');
        }

        return $token;
    }

    public function downloadStatement(#[\SensitiveParameter] string $token, string $from, string $to): string
    {
        $this->validateToken($token);
        $fromDate = $this->validateDate($from);
        $toDate = $this->validateDate($to);
        if ($fromDate > $toDate) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_DATE_RANGE,
                'Počáteční datum bankovního výpisu musí předcházet koncovému datu.',
            );
        }

        $url = self::BASE_URL . 'periods/' . rawurlencode($token)
            . '/' . $from . '/' . $to . '/transactions.xml';
        $stage = 'request';
        $reason = null;
        $response = null;
        $body = null;
        $readDiagnostic = [];
        try {
            $response = $this->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/xml, text/xml',
                    'User-Agent' => 'MyUcto-Bank-Connector/1.0',
                ],
            ], false);
            $stage = 'read';
            $body = $this->readResponse($response, self::MAX_STATEMENT_BYTES, false, $readDiagnostic);
            $stage = 'xml';
            try {
                $rows = $this->parser->rows($body);
                $this->parser->parse($rows);
            } catch (BankConnectorException $e) {
                $reason = preg_match('/\(([a-z_]+)\)\.$/', $e->getMessage(), $m) === 1 ? $m[1] : 'xml_invalid';
                throw new BankConnectorException(
                    BankConnectorException::INVALID_RESPONSE,
                    'Banka vrátila neplatný výpis Fio XML.',
                    false,
                    $response->getStatusCode(),
                );
            }

            return json_encode(['provider' => $this->provider(), 'from' => $from, 'to' => $to] + $rows,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (BankConnectorException $e) {
            $this->logger?->warning('fio_statement_failed', [
                'stage' => $stage,
                'reason' => $reason ?? $readDiagnostic['reason'] ?? null,
                'code' => $e->errorCode,
                'http_status' => $e->remoteHttpStatus ?? $response?->getStatusCode(),
                'response_bytes' => $body !== null ? strlen($body) : ($readDiagnostic['bytes_read'] ?? null),
                'empty_reads' => $readDiagnostic['empty_reads'] ?? null,
                'stream_error' => $readDiagnostic['stream_error'] ?? null,
            ] + ($response !== null ? $this->transferHeaders($response) : []));
            throw $e;
        }
    }

    /** @return array{header:array<string,mixed>,transactions:list<array<string,mixed>>} */
    public function parseStatement(#[\SensitiveParameter] string $content): array
    {
        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        if (!is_array($data) || ($data['provider'] ?? null) !== $this->provider()
            || !is_array($data['info'] ?? null) || !is_array($data['transactions'] ?? null) || !array_is_list($data['transactions'])) {
            throw new BankConnectorException(BankConnectorException::INVALID_RESPONSE, 'Neplatný výpis Fio.');
        }

        return $this->parser->parse(['info' => $data['info'], 'transactions' => $data['transactions']]);
    }

    public function submitPaymentOrder(
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $abo,
    ): array
    {
        $this->validateToken($token);
        $this->validateAbo($abo);

        $response = $this->request('POST', self::BASE_URL . 'import/', [
            'headers' => [
                'Accept' => 'application/xml, text/xml',
                'User-Agent' => 'MyUcto-Bank-Connector/1.0',
            ],
            'multipart' => [
                ['name' => 'type', 'contents' => 'abo'],
                ['name' => 'token', 'contents' => $token],
                [
                    'name' => 'file',
                    'contents' => $abo,
                    'filename' => 'payment-order.abo',
                    'headers' => ['Content-Type' => 'application/octet-stream'],
                ],
            ],
        ], true);
        $body = $this->readResponse($response, self::MAX_IMPORT_RESPONSE_BYTES, true);

        return $this->parseImportResponse($body, $response->getStatusCode());
    }

    /**
     * @param array<string,mixed> $options
     */
    private function request(
        string $method,
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] array $options,
        bool $payment,
    ): \Psr\Http\Message\ResponseInterface
    {
        $options += [
            'allow_redirects' => false,
            'connect_timeout' => 5.0,
            'timeout' => 30.0,
            'verify' => true,
            'http_errors' => false,
            'stream' => true,
            'debug' => false,
        ];

        try {
            $response = $this->http->request($method, $url, $options);
        } catch (GuzzleException) {
            throw new BankConnectorException(
                BankConnectorException::REMOTE_UNAVAILABLE,
                'Bankovní služba je dočasně nedostupná.',
                $payment,
            );
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return $response;
        }

        throw $this->httpException($status, $payment);
    }

    private function httpException(int $status, bool $payment): BankConnectorException
    {
        if (!$payment) {
            return match ($status) {
                409 => new BankConnectorException(
                    BankConnectorException::RATE_LIMITED,
                    'Bankovní služba dovoluje dotaz na stejný token nejvýše jednou za 30 sekund.',
                    false,
                    $status,
                ),
                413 => new BankConnectorException(
                    BankConnectorException::STATEMENT_TOO_LARGE,
                    'Požadované období obsahuje příliš mnoho bankovních pohybů.',
                    false,
                    $status,
                ),
                422 => new BankConnectorException(
                    BankConnectorException::HISTORY_LOCKED,
                    'Přístup ke starší bankovní historii není autorizován.',
                    false,
                    $status,
                ),
                500 => new BankConnectorException(
                    BankConnectorException::INVALID_TOKEN,
                    'Bankovní token není aktivní nebo jej banka nerozpoznala.',
                    false,
                    $status,
                ),
                default => new BankConnectorException(
                    $status >= 500
                        ? BankConnectorException::REMOTE_UNAVAILABLE
                        : BankConnectorException::REMOTE_HTTP_ERROR,
                    'Banka odmítla požadavek na výpis.',
                    false,
                    $status,
                ),
            };
        }

        return new BankConnectorException(
            $status >= 500
                ? BankConnectorException::REMOTE_UNAVAILABLE
                : BankConnectorException::REMOTE_HTTP_ERROR,
            'Banka nepotvrdila přijetí platebního příkazu.',
            $status >= 500,
            $status,
        );
    }

    /**
     * Prázdné čtení před koncem streamu je u síťového (TLS, chunked) streamu běžné a samo
     * o sobě chybu neznamená; selháním je až série prázdných čtení bez posunu.
     *
     * @param array<string,int|string|bool>|null $diagnostic
     * @param-out array<string,int|string|bool> $diagnostic
     */
    private function readResponse(
        #[\SensitiveParameter] \Psr\Http\Message\ResponseInterface $response,
        int $limit,
        bool $payment,
        ?array &$diagnostic = null,
    ): string {
        $body = '';
        $emptyReads = 0;
        $emptyStreak = 0;
        $diagnostic = [];
        try {
            $stream = $response->getBody();
            while (strlen($body) <= $limit) {
                if ($stream->eof()) {
                    break;
                }
                $chunk = $stream->read(min(8192, $limit + 1 - strlen($body)));
                if ($chunk === '') {
                    ++$emptyReads;
                    if (++$emptyStreak > self::MAX_EMPTY_READS) {
                        $diagnostic = ['reason' => 'stream_stalled', 'bytes_read' => strlen($body), 'empty_reads' => $emptyReads];
                        throw new \RuntimeException('Response stream made no progress.');
                    }
                    usleep(10_000);
                    continue;
                }
                $emptyStreak = 0;
                $body .= $chunk;
            }
        } catch (\RuntimeException $e) {
            $diagnostic += [
                'reason' => 'stream_error',
                'bytes_read' => strlen($body),
                'empty_reads' => $emptyReads,
                'stream_error' => $this->safeStreamError($e),
            ];
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Odpověď banky se nepodařilo bezpečně přečíst.',
                $payment,
                $response->getStatusCode(),
            );
        }

        $diagnostic = ['bytes_read' => strlen($body), 'empty_reads' => $emptyReads];
        if (strlen($body) > $limit) {
            $diagnostic['reason'] = 'too_large';
            throw new BankConnectorException(
                BankConnectorException::RESPONSE_TOO_LARGE,
                'Odpověď banky překročila povolenou velikost.',
                $payment,
                $response->getStatusCode(),
            );
        }
        if ($body === '') {
            $diagnostic['reason'] = 'empty_body';
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Banka vrátila prázdnou odpověď.',
                $payment,
                $response->getStatusCode(),
            );
        }

        return $body;
    }

    /** @return array<string,string|int> */
    private function transferHeaders(\Psr\Http\Message\ResponseInterface $response): array
    {
        $result = [];
        $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0]));
        if (preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~D', $type) === 1) {
            $result['content_type'] = $type;
        }
        $charset = strtolower($response->getHeaderLine('Content-Type'));
        if (preg_match('/charset=["\']?([a-z0-9._-]{1,40})/', $charset, $m) === 1) {
            $result['content_charset'] = $m[1];
        }
        $length = $response->getHeaderLine('Content-Length');
        if (preg_match('/^\d{1,12}$/D', $length) === 1) {
            $result['content_length'] = (int) $length;
        }
        foreach (['Transfer-Encoding' => 'transfer_encoding', 'Content-Encoding' => 'content_encoding'] as $header => $key) {
            $value = strtolower($response->getHeaderLine($header));
            if (preg_match('/^[a-z0-9, -]{1,40}$/D', $value) === 1) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private function safeStreamError(\RuntimeException $e): string
    {
        $message = strtolower($e->getMessage());
        foreach (['made no progress' => 'no_progress', 'unable to read' => 'unable_to_read', 'detached' => 'detached', 'non-readable' => 'not_readable', 'timed out' => 'timed_out'] as $needle => $code) {
            if (str_contains($message, $needle)) {
                return $code;
            }
        }
        return 'other:' . (new \ReflectionClass($e))->getShortName();
    }

    private function validateToken(#[\SensitiveParameter] string $token): void
    {
        if (preg_match('/^[A-Za-z0-9]{64}$/D', $token) !== 1) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_TOKEN,
                'Bankovní token nemá platný formát.',
            );
        }
    }

    private function validateDate(string $date): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_DATE,
                'Datum bankovního výpisu nemá platný formát.',
            );
        }

        return $parsed;
    }

    private function validateAbo(#[\SensitiveParameter] string $abo): void
    {
        if ($abo === '' || strlen($abo) > self::MAX_ABO_BYTES || str_contains($abo, "\0")) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_PAYMENT_ORDER,
                'Platební příkaz ABO nemá platný formát nebo velikost.',
            );
        }
        if (!str_ends_with($abo, "\r\n") || preg_match('/(?<!\r)\n|\r(?!\n)/', $abo) === 1) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_PAYMENT_ORDER,
                'Platební příkaz ABO musí používat řádkování CRLF.',
            );
        }

        $records = explode("\r\n", substr($abo, 0, -2));
        $last = count($records) - 1;
        if (
            count($records) < 6
            || preg_match('/^UHL1\d{6}.{20}\d{10}(?:001999|000999000000000000)$/sD', $records[0]) !== 1
            || preg_match('/^1 (?:1501|1502) \d{6} (?:2010|8330)$/D', $records[1]) !== 1
            || !str_starts_with($records[2], '2 ')
            || $records[$last - 1] !== '3 +'
            || $records[$last] !== '5 +'
        ) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_PAYMENT_ORDER,
                'Platební příkaz ABO nemá platnou strukturu.',
            );
        }
    }

    /**
     * @return array{accepted:true,reference:string}
     */
    private function parseImportResponse(#[\SensitiveParameter] string $body, int $httpStatus): array
    {
        if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Banka vrátila nepovolenou XML odpověď.',
                true,
                $httpStatus,
            );
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($body, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $dom->documentElement?->localName !== 'responseImport') {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Banka vrátila nečitelnou odpověď na platební příkaz.',
                true,
                $httpStatus,
            );
        }

        $xpath = new \DOMXPath($dom);
        $results = $xpath->query('/*[local-name()="responseImport"]/*[local-name()="result"]');
        if ($results === false || $results->length !== 1) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Odpověď banky neobsahuje výsledek platebního příkazu.',
                true,
                $httpStatus,
            );
        }

        $result = $results->item(0);
        if (!$result instanceof \DOMNode) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Odpověď banky neobsahuje platný výsledek platebního příkazu.',
                true,
                $httpStatus,
            );
        }
        $errorCode = $this->childText($xpath, $result, 'errorCode');
        $status = strtolower($this->childText($xpath, $result, 'status'));
        if (preg_match('/^\d+$/D', $errorCode) !== 1 || !in_array($status, ['ok', 'warning', 'error', 'fatal'], true)) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Odpověď banky obsahuje neplatný stav platebního příkazu.',
                true,
                $httpStatus,
            );
        }

        $details = $xpath->query('/*[local-name()="responseImport"]/*[local-name()="ordersDetails"]/*[local-name()="detail"]');
        $acceptedCount = 0;
        $rejectedCount = 0;
        $hasDetailErrors = false;
        $invalidDetailStatus = false;
        if ($details !== false) {
            foreach ($details as $detail) {
                if (!$detail instanceof \DOMNode) {
                    $invalidDetailStatus = true;
                    continue;
                }
                $messages = $xpath->query('./*[local-name()="messages"]/*[local-name()="message"]', $detail);
                $rejected = false;
                if ($messages !== false) {
                    foreach ($messages as $message) {
                        if (!$message instanceof \DOMNode) {
                            $invalidDetailStatus = true;
                            continue;
                        }
                        $messageStatus = strtolower(trim((string) $message->attributes?->getNamedItem('status')?->nodeValue));
                        if ($messageStatus === 'error' || $messageStatus === 'fatal') {
                            $rejected = true;
                            $hasDetailErrors = true;
                        } elseif ($messageStatus !== '' && !in_array($messageStatus, ['ok', 'warning'], true)) {
                            $invalidDetailStatus = true;
                        }
                    }
                }
                if ($rejected) {
                    ++$rejectedCount;
                } else {
                    ++$acceptedCount;
                }
            }
        }

        if ($invalidDetailStatus) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Odpověď banky obsahuje neplatný stav položky platebního příkazu.',
                true,
                $httpStatus,
            );
        }

        $acceptedStatus = ($errorCode === '0' && $status === 'ok')
            || ($errorCode === '2' && $status === 'warning');
        if (!$acceptedStatus || $hasDetailErrors) {
            throw new BankConnectorException(
                BankConnectorException::PAYMENT_REJECTED,
                'Banka platební příkaz nepřijala celý.',
                $acceptedCount > 0,
                $httpStatus,
                $details !== false && $details->length > 0 ? $acceptedCount : null,
                $details !== false && $details->length > 0 ? $rejectedCount : null,
            );
        }

        $referenceNodes = $xpath->query('./*[local-name()="idInstruction"]', $result);
        $references = [];
        if ($referenceNodes !== false) {
            foreach ($referenceNodes as $referenceNode) {
                if (!$referenceNode instanceof \DOMNode) {
                    continue;
                }
                $reference = trim((string) $referenceNode->textContent);
                if (preg_match('/^\d+$/D', $reference) === 1) {
                    $references[] = $reference;
                }
            }
        }
        if ($references === []) {
            throw new BankConnectorException(
                BankConnectorException::INVALID_RESPONSE,
                'Banka nepotvrdila referenci platebního příkazu.',
                true,
                $httpStatus,
            );
        }

        return ['accepted' => true, 'reference' => implode(',', $references)];
    }

    private function childText(\DOMXPath $xpath, \DOMNode $parent, string $name): string
    {
        $nodes = $xpath->query('./*[local-name()="' . $name . '"]', $parent);
        if ($nodes === false || $nodes->length !== 1) {
            return '';
        }
        $node = $nodes->item(0);
        return $node instanceof \DOMNode ? trim((string) $node->textContent) : '';
    }
}

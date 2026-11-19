<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

use MyInvoice\Action\Accounting\DraftPostingPreviewAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Volání akce jednoho záznamu (úprava faktury, přijaté faktury) z operace hromadné
 * akce za uživatele, který úlohu spustil.
 *
 * Úlohu spouští jen člověk v aplikaci ({@see \MyInvoice\Security\RequestAuthorization::canBulkChange()}
 * token odmítne) a spuštění si to zapíše do parametrů (`origin = session`). Požadavek
 * úlohy pak nese tutéž metodu ověření jako relace, ze které úloha vznikla, takže úkony
 * vyhrazené relaci ({@see \MyInvoice\Http\SessionOnlyAccountingAct}, např. úprava vystavené
 * faktury) projdou stejně jako na detailu záznamu. Úloha bez záznamu o relaci zůstane
 * požadavkem na pozadí a tyto úkony akce odmítne.
 *
 * Běh nanečisto uloží záznam v transakci, která se odvolá, a s příznakem
 * {@see DraftPostingPreviewAction::ATTR}: akce pak vynechá zásahy mimo databázi
 * (zahození PDF), stejně jako náhled zápisu v editoru.
 */
final class BulkRecordCall
{
    public function __construct(private readonly Connection $db) {}

    public static function request(BulkContext $ctx): Request
    {
        $request = $ctx->request();
        return ($ctx->params['origin'] ?? null) === 'session'
            ? RequestAuthorization::asSessionStartedJob($request)
            : $request;
    }

    /**
     * @param callable(Request):Response $call
     * @return array{ok:bool, code:?string, message:?string}
     */
    public function run(Request $request, callable $call, bool $dryRun): array
    {
        if (!$dryRun) {
            return self::outcome($call($request));
        }
        $request = $request->withAttribute(DraftPostingPreviewAction::ATTR, true);
        return self::outcome(BulkTransaction::run($this->db->pdo(), static fn (): Response => $call($request), true));
    }

    /** @return array{ok:bool, code:?string, message:?string} */
    public static function outcome(Response $response): array
    {
        if ($response->getStatusCode() < 300) {
            return ['ok' => true, 'code' => null, 'message' => null];
        }
        $body = json_decode((string) $response->getBody(), true);
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
        $message = isset($error['message']) ? (string) $error['message'] : null;
        if ($message !== null && is_array($error['fields'] ?? null)) {
            $message .= ': ' . implode('; ', array_map(
                static fn (mixed $v, mixed $k): string => $k . ' ' . (is_scalar($v) ? (string) $v : json_encode($v)),
                $error['fields'],
                array_keys($error['fields']),
            ));
        }
        return [
            'ok' => false,
            'code' => mb_substr((string) ($error['code'] ?? 'failed'), 0, 80),
            'message' => $message === null ? null : mb_substr($message, 0, 500),
        ];
    }
}

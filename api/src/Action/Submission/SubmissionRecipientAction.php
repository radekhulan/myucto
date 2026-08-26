<?php

declare(strict_types=1);

namespace MyInvoice\Action\Submission;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Číselník datových schránek institucí.
 *
 * Formát ID schránky hlídá API i DB. Odkaz na zdroj je volitelný auditní údaj;
 * firemní číselník lze uložit i bez něj.
 */
final class SubmissionRecipientAction
{
    private const KINDS = ['tax_office', 'cssz', 'health_insurer', 'other'];

    public function __construct(private readonly SubmissionRecipientRepository $recipients) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $params = $request->getQueryParams();
        $kind = isset($params['kind']) && $params['kind'] !== '' ? (string) $params['kind'] : null;

        return Json::ok($response, [
            'items' => $this->recipients->listVisible(SupplierGuard::currentId($request), $kind),
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);

        $code = strtolower(trim((string) ($body['code'] ?? '')));
        if (preg_match('/^[a-z][a-z0-9_]{1,47}$/', $code) !== 1) {
            return Json::error($response, 'invalid_code', 'Kód smí obsahovat jen malá písmena, číslice a podtržítka.', 400);
        }
        $kind = (string) ($body['kind'] ?? 'other');
        if (!in_array($kind, self::KINDS, true)) {
            return Json::error($response, 'invalid_kind', 'Neznámý druh instituce.', 400);
        }
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return Json::error($response, 'name_required', 'Vyplňte název instituce.', 400);
        }

        $boxId = trim((string) ($body['isds_box_id'] ?? ''));
        $boxId = $boxId !== '' ? strtolower($boxId) : null;
        $businessId = preg_replace('/\s+/', '', trim((string) ($body['business_id'] ?? '')));
        $businessId = $businessId !== '' ? $businessId : null;
        if ($businessId !== null && preg_match('/^[0-9]{8}$/D', $businessId) !== 1) {
            return Json::error(
                $response,
                'invalid_business_id',
                'IČ instituce musí mít přesně 8 číslic.',
                400,
            );
        }
        $address = trim((string) ($body['address'] ?? ''));
        $address = $address !== '' ? mb_substr($address, 0, 500) : null;
        $sourceUrl = trim((string) ($body['source_url'] ?? ''));
        $sourceUrl = $sourceUrl !== '' ? $sourceUrl : null;

        if ($boxId !== null && preg_match('/^[a-z0-9]{7}$/', $boxId) !== 1) {
            return Json::error(
                $response,
                'invalid_box_id',
                'ID datové schránky má přesně 7 znaků (písmena a číslice).',
                400,
            );
        }
        try {
            $id = $this->recipients->upsertForSupplier(SupplierGuard::currentId($request), [
                'code' => $code,
                'name' => mb_substr($name, 0, 190),
                'business_id' => $businessId,
                'address' => $address,
                'kind' => $kind,
                'isds_box_id' => $boxId,
                'source_url' => $sourceUrl !== null ? mb_substr($sourceUrl, 0, 500) : null,
                'source_note' => isset($body['source_note']) && trim((string) $body['source_note']) !== ''
                    ? mb_substr(trim((string) $body['source_note']), 0, 500)
                    : null,
                'is_active' => (bool) ($body['is_active'] ?? true),
            ], $this->userId($request));
        } catch (PDOException $e) {
            return Json::error($response, 'recipient_rejected', 'Záznam se nepodařilo uložit: ' . $e->getMessage(), 400);
        }

        return Json::ok($response, ['id' => $id]);
    }

    /** @param array<string,string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $deleted = $this->recipients->deleteOwn(SupplierGuard::currentId($request), (int) ($args['id'] ?? 0));
        if (!$deleted) {
            return Json::error(
                $response,
                'not_found',
                'Záznam nebyl nalezen, nebo jde o systémový záznam, který smazat nelze.',
                404,
            );
        }

        return Json::ok($response, ['deleted' => true]);
    }

    private function guard(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::allows($request, 'settings.signing', $level)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        if ($this->userId($request) <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        return null;
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        return (int) ($user['id'] ?? 0);
    }
}

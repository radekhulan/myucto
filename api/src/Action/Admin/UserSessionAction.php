<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\UserSessionRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SessionManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UserSessionAction
{
    public function __construct(
        private readonly UserSessionRepository $sessions,
        private readonly SessionManager $manager,
        private readonly ActivityLogger $audit,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) return Json::sessionRequired($response);
        if (!RequestAuthorization::canManageInstance($request)) return $this->forbidden($response);
        $query = $request->getQueryParams();
        $cursor = $query['cursor'] ?? '';
        if (!is_string($cursor)) return Json::error($response, 'validation_failed', 'Neplatný kurzor.', 400);
        $beforeSeen = '4294967295';
        $beforeFamily = str_repeat('f', 64);
        if ($cursor !== '') {
            $decoded = base64_decode($cursor, true);
            if (!is_string($decoded) || preg_match('/^([0-9]{1,10}(?:\.[0-9]{1,6})?):([a-f0-9]{64})$/D', $decoded, $parts) !== 1) {
                return Json::error($response, 'validation_failed', 'Neplatný kurzor.', 400);
            }
            $beforeSeen = $parts[1];
            $beforeFamily = $parts[2];
        }
        $current = $this->currentFamily($request);
        $own = $this->sessions->current($current);
        $limit = $own === null ? 50 : 49;
        $rows = $this->sessions->page($beforeSeen, $beforeFamily, $limit, $current);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $canTerminate = RequestAuthorization::canTerminateUserSessions($request);
        $normalize = static function (array $row) use ($canTerminate, $current): array {
            $row['user_id'] = (int) $row['user_id'];
            $row['is_current'] = hash_equals($current, $row['id']);
            $row['recent_contact'] = (bool) $row['recent_contact'];
            // Technická role potřebuje přítomnost uživatelů před údržbou, ne jejich IP a zařízení.
            if (!$canTerminate) foreach (['email', 'ip', 'user_agent'] as $field) $row[$field] = null;
            foreach (['created_at', 'last_seen', 'expires_at'] as $field) $row[$field] = gmdate('Y-m-d\\TH:i:s\\Z', (int) $row[$field]);
            foreach (['locked_at'] as $field) {
                $row[$field] = $row[$field] === null ? null : str_replace(' ', 'T', $row[$field]) . 'Z';
            }
            return $row;
        };
        $data = array_map($normalize, $rows);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        return Json::ok($response, [
            'data' => $data, 'current_session' => $own === null ? null : $normalize($own), 'can_terminate' => $canTerminate,
            'next_cursor' => $more && $last !== null ? base64_encode($last['last_seen'] . ':' . $last['id']) : null,
        ])->withHeader('Cache-Control', 'no-store');
    }

    public function terminate(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) return Json::sessionRequired($response);
        if (!RequestAuthorization::canTerminateUserSessions($request)) return $this->forbidden($response);
        $family = strtolower((string) ($args['id'] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/D', $family) !== 1) {
            return Json::error($response, 'validation_failed', 'Neplatný identifikátor relace.', 400);
        }
        $target = $this->sessions->revocable($family);
        if ($target === null) return Json::error($response, 'not_found', 'Relace již není aktivní.', 404);
        // destroy() odvolá celou family i při souběžné rotaci jejího tokenu.
        $actor = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->manager->destroy($target['token'], fn () => $this->audit->log(
            'auth.session_revoked', (int) ($actor['id'] ?? 0), 'user', (int) $target['user_id'],
        ));
        return Json::ok($response, ['current_session_revoked' => hash_equals($this->currentFamily($request), $family)]);
    }

    public function terminateSelected(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) return Json::sessionRequired($response);
        if (!RequestAuthorization::canTerminateUserSessions($request)) return $this->forbidden($response);
        $body = (array) ($request->getParsedBody() ?? []);
        $ids = $body['ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || count($ids) < 1 || count($ids) > 50) {
            return Json::error($response, 'validation_failed', 'Vyberte 1 až 50 relací.', 400);
        }
        foreach ($ids as $id) {
            if (!is_string($id) || preg_match('/^[a-f0-9]{64}$/Di', $id) !== 1) {
                return Json::error($response, 'validation_failed', 'Neplatný identifikátor relace.', 400);
            }
        }
        $ids = array_values(array_unique(array_map(strtolower(...), $ids)));
        $targets = $this->sessions->revocableMany($ids);
        if (count($targets) !== count($ids)) {
            return Json::error($response, 'session_selection_stale', 'Některá relace již není aktivní. Obnovte přehled.', 409);
        }
        $actor = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->manager->destroyMany(array_column($targets, 'token'), fn () => $this->audit->log(
            'auth.sessions_revoked', (int) ($actor['id'] ?? 0), 'user', null,
            ['count' => count($targets), 'user_ids' => array_values(array_unique(array_map(intval(...), array_column($targets, 'user_id'))))],
        ));
        return Json::ok($response, [
            'terminated' => count($targets),
            'current_session_revoked' => in_array($this->currentFamily($request), $ids, true),
        ]);
    }

    private function currentFamily(Request $request): string
    {
        $session = (array) $request->getAttribute(AuthMiddleware::ATTR_SESSION, []);
        return strtolower((string) ($session['session_family_id'] ?? ''));
    }

    private function forbidden(Response $response): Response
    {
        return Json::error($response, 'forbidden_permission', 'Pro tuto akci nemáš oprávnění.', 403);
    }
}

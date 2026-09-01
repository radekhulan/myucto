<?php

declare(strict_types=1);

namespace MyInvoice\Action\UserSettings;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Per-user preference UI — globální per uživatel, BEZ supplier scope (R3).
 *
 *   GET    /api/user/preferences[?keys=table.invoices,table.journal]  — mapa pref_key → payload
 *   PUT    /api/user/preferences/{key}                                — upsert, vrací uložený payload
 *   DELETE /api/user/preferences/{key}                               — reset klíče, {"deleted": true}
 *
 * Povolené jsou `table.*`, `nav.order`, `keyboard.shortcuts` a klíče průvodců
 * (`onboarding.guide`, `payroll.guide`). Payload je opaque, omezený velikostí
 * a hloubkou; vlastnictví vždy určuje přihlášený uživatel.
 */
final class UserPreferenceAction
{
    private const PREFIX = 'table.';

    /** §10: pořadí sidebar menu — globální per user, mimo table.* whitelist. */
    private const NAV_ORDER_KEY = 'nav.order';

    /** Nastavitelné klávesové zkratky — globální per user. */
    private const KEYBOARD_SHORTCUTS_KEY = 'keyboard.shortcuts';

    /**
     * Průvodce prvním nastavením na Přehledu — ručně odškrtnuté kroky a skrytí
     * průvodce. Per uživatel (ne per firma): je to stav ČTENÍ návodu, ne stav dat.
     */
    private const ONBOARDING_KEY = 'onboarding.guide';

    /**
     * Průvodce prvním nastavením MEZD na přehledu mzdové sekce — sesterský klíč
     * k `onboarding.guide`, ale vlastní: mzdy si uživatel odškrtává zvlášť od
     * rozjezdu firmy a skrýt smí jen jednoho z nich.
     */
    private const PAYROLL_GUIDE_KEY = 'payroll.guide';

    /** Nav order a klávesové zkratky jsou o úroveň hlubší než ploché table.* prefy. */
    private const MAX_DEPTH = 4;

    public function __construct(private readonly Connection $db) {}

    public function list(Request $request, Response $response): Response
    {
        $userId = $this->userId($request);
        $pdo = $this->db->pdo();

        $keysParam = $request->getQueryParams()['keys'] ?? null;
        if ($keysParam !== null) {
            $keys = array_values(array_filter(array_map('trim', explode(',', (string) $keysParam)), static fn ($k) => $k !== ''));
            // Průnik s validními klíči — mj. ohraničuje počet IN() placeholderů.
            $valid = array_map(static fn (string $p) => self::PREFIX . $p, SavedFilterAction::PAGE_KEYS);
            $valid[] = self::NAV_ORDER_KEY;
            $valid[] = self::KEYBOARD_SHORTCUTS_KEY;
            $valid[] = self::ONBOARDING_KEY;
            $valid[] = self::PAYROLL_GUIDE_KEY;
            $keys = array_values(array_unique(array_intersect($keys, $valid)));
            if ($keys === []) {
                return Json::ok($response, (object) []);
            }
            $placeholders = implode(', ', array_fill(0, count($keys), '?'));
            $stmt = $pdo->prepare(
                "SELECT pref_key, payload FROM user_preferences WHERE user_id = ? AND pref_key IN ($placeholders)"
            );
            $stmt->execute(array_merge([$userId], $keys));
        } else {
            $stmt = $pdo->prepare('SELECT pref_key, payload FROM user_preferences WHERE user_id = ?');
            $stmt->execute([$userId]);
        }

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['pref_key']] = json_decode((string) $row['payload'], true);
        }

        return Json::ok($response, (object) $map);
    }

    public function put(Request $request, Response $response, array $args): Response
    {
        $userId = $this->userId($request);
        $key = (string) ($args['key'] ?? '');
        if (!$this->validPrefKey($key)) {
            return Json::error($response, 'invalid_pref_key', 'Neplatný klíč preference.', 422);
        }

        try {
            $raw = json_encode($request->getParsedBody(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $raw = ''; // validate('') → validation_failed 422 místo TypeError/500
        }
        if (($err = JsonPayloadValidator::validate($raw, self::MAX_DEPTH)) !== null) {
            return Json::error($response, $err, 'Neplatný payload preference.', 422);
        }
        $payload = JsonPayloadValidator::canonicalize($raw, self::MAX_DEPTH);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO user_preferences (user_id, pref_key, payload) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload)'
        );
        $stmt->execute([$userId, $key, $payload]);

        return Json::ok($response, json_decode($payload, true));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $userId = $this->userId($request);
        $key = (string) ($args['key'] ?? '');
        if (!$this->validPrefKey($key)) {
            return Json::error($response, 'invalid_pref_key', 'Neplatný klíč preference.', 422);
        }

        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM user_preferences WHERE user_id = ? AND pref_key = ?'
        );
        $stmt->execute([$userId, $key]);
        if ($stmt->rowCount() === 0) {
            return Json::error($response, 'not_found', 'Preference nenalezena.', 404);
        }

        return Json::ok($response, ['deleted' => true]);
    }

    private function validPrefKey(string $key): bool
    {
        if (
            $key === self::NAV_ORDER_KEY
            || $key === self::KEYBOARD_SHORTCUTS_KEY
            || $key === self::ONBOARDING_KEY
            || $key === self::PAYROLL_GUIDE_KEY
        ) {
            return true;
        }
        if (!str_starts_with($key, self::PREFIX)) {
            return false;
        }
        return in_array(substr($key, strlen(self::PREFIX)), SavedFilterAction::PAGE_KEYS, true);
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0);
    }
}

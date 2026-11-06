<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Repository\UserSessionRepository;
use MyInvoice\Service\ActivityLogger;

/** Serverový přístup nahrazuje přihlášení do aplikace; žádné tokeny neopouštějí backend. */
final class UserSessionCli
{
    public function __construct(
        private readonly UserSessionRepository $sessions,
        private readonly SessionManager $manager,
        private readonly ActivityLogger $audit,
    ) {}

    public static function parse(array $arguments): array
    {
        $command = array_shift($arguments);
        if (!in_array($command, ['list', 'terminate'], true)) throw new \InvalidArgumentException('Použijte podpříkaz list nebo terminate.');
        $options = ['command' => $command, 'id' => null, 'email' => null, 'cursor' => null, 'all' => false, 'yes' => false, 'json' => false];
        $seen = [];
        while ($arguments !== []) {
            $arg = array_shift($arguments);
            [$name, $value] = array_pad(explode('=', $arg, 2), 2, null);
            $key = str_starts_with($name, '--') ? substr($name, 2) : '';
            if (!in_array($key, ['id', 'email', 'cursor', 'all', 'yes', 'json'], true) || isset($seen[$key])) {
                throw new \InvalidArgumentException('Neznámý nebo opakovaný přepínač.');
            }
            $seen[$key] = true;
            if (in_array($key, ['all', 'yes', 'json'], true)) {
                if ($value !== null) throw new \InvalidArgumentException('Tento přepínač nemá hodnotu.');
                $options[$key] = true;
            } else {
                $value ??= array_shift($arguments);
                if (!is_string($value) || $value === '' || str_starts_with($value, '--')) throw new \InvalidArgumentException('Chybí hodnota přepínače.');
                $options[$key] = $value;
            }
        }
        if ($options['email'] !== null && (strlen($options['email']) > 190 || !filter_var($options['email'], FILTER_VALIDATE_EMAIL))) {
            throw new \InvalidArgumentException('Neplatný e-mail.');
        }
        if ($options['id'] !== null) {
            if (!preg_match('/^[a-f0-9]{64}$/Di', $options['id'])) throw new \InvalidArgumentException('Použijte celé ID rodiny relace z výpisu, nikoli přihlašovací token.');
            $options['id'] = strtolower($options['id']);
        }
        if ($command === 'list' && ($options['id'] !== null || $options['all'] || $options['yes'])) {
            throw new \InvalidArgumentException('List podporuje pouze --email, --cursor a --json.');
        }
        if ($command === 'terminate' && ($options['cursor'] !== null || (int) $options['all'] + (int) ($options['email'] !== null) + (int) ($options['id'] !== null) !== 1)) {
            throw new \InvalidArgumentException('Terminate vyžaduje právě jeden výběr: --id, --email nebo --all; nepřijímá --cursor.');
        }
        self::cursor($options['cursor']);
        return $options;
    }

    private static function cursor(?string $cursor): array
    {
        if ($cursor === null) return ['4294967295', str_repeat('f', 64)];
        $decoded = strlen($cursor) <= 128 ? base64_decode($cursor, true) : false;
        if (!is_string($decoded) || !preg_match('/^([0-9]{1,10}(?:\.[0-9]{1,6})?):([a-f0-9]{64})$/D', $decoded, $match)) {
            throw new \InvalidArgumentException('Neplatný kurzor.');
        }
        return [$match[1], $match[2]];
    }

    public function run(array $options): array
    {
        $userId = $options['email'] === null ? null : $this->sessions->userIdByEmail($options['email']);
        if ($options['email'] !== null && $userId === null) throw new \DomainException('Uživatel nebyl nalezen.');
        [$seen, $family] = self::cursor($options['cursor']);
        if ($options['command'] === 'list') {
            $rows = $this->sessions->page($seen, $family, 50, '', $userId, true);
            $more = count($rows) > 50;
            $rows = array_slice($rows, 0, 50);
            $last = $rows === [] ? null : $rows[array_key_last($rows)];
            return ['data' => array_map(self::normalize(...), $rows), 'next_cursor' => $more ? base64_encode($last['last_seen'] . ':' . $last['id']) : null];
        }
        $matched = 0;
        $terminated = 0;
        do {
            if ($options['id'] !== null) {
                $row = $this->sessions->current($options['id'], true);
                if ($row === null) throw new \DomainException('Relace již není aktivní.');
                $rows = [$row];
            } else {
                $rows = array_slice($this->sessions->page($seen, $family, 50, '', $userId, true), 0, 50);
            }
            if ($rows === []) break;
            $matched += count($rows);
            $last = $rows[array_key_last($rows)];
            [$seen, $family] = [(string) $last['last_seen'], $last['id']];
            if ($options['yes']) {
                $targets = $this->sessions->revocableMany(array_column($rows, 'id'), true);
                try {
                    $terminated += $this->manager->destroyMany(array_column($targets, 'token'), fn (int $count) => $this->audit->log(
                        'auth.sessions_revoked', null, 'user', $userId,
                        ['via' => 'cli:user-sessions', 'operator_os_user' => self::operator(), 'operator_host' => gethostname() ?: null,
                            'count' => $count, 'selection' => $options['all'] ? 'all' : ($userId !== null ? 'email' : 'id'),
                            'user_ids' => array_values(array_unique(array_map(intval(...), array_column($targets, 'user_id')))),
                            'session_families' => array_column($targets, 'family')],
                    ));
                } catch (\Throwable $e) {
                    // SessionManager už vlastní transakci vrátil. Předchozí dávky zůstávají dokončené.
                    return ['matched' => $matched, 'terminated' => $terminated, 'executed' => true,
                        'error' => 'Ukončení selhalo. Dříve dokončené dávky zůstávají auditované; příkaz lze zopakovat.'];
                }
            }
        } while ($options['id'] === null && count($rows) === 50);
        return ['matched' => $matched, 'terminated' => $terminated, 'executed' => $options['yes']];
    }

    private static function operator(): ?string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());
            if (is_array($user)) return (string) $user['name'];
        }
        foreach (['USERNAME', 'USER', 'LOGNAME'] as $key) if (($value = getenv($key)) !== false && $value !== '') return $value;
        return null;
    }

    private static function normalize(array $row): array
    {
        $row['user_id'] = (int) $row['user_id'];
        $row['recent_contact'] = (bool) $row['recent_contact'];
        foreach (['created_at', 'last_seen', 'expires_at'] as $key) $row[$key] = gmdate('Y-m-d\\TH:i:s\\Z', (int) $row[$key]);
        return $row;
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\InstanceAdmin;

/** Neměnný SMTP cíl schválený superadminem včetně identity a příjemce testu. */
final class SmtpTarget
{
    /** @return array{name:string,host:string,port:int,encryption:string,username:string,from_email:string,test_recipient:string} */
    public static function fromInput(array $input): array
    {
        self::onlyKeys($input, ['name', 'host', 'port', 'encryption', 'username', 'from_email', 'test_recipient']);
        $name = self::text($input, 'name', 100);
        $host = strtolower(self::text($input, 'host', 253));
        if (strpbrk($host, '/\\@?#%:') !== false
            || (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
            throw new \InvalidArgumentException('Neplatný hostitel SMTP.');
        }
        $port = filter_var($input['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) throw new \InvalidArgumentException('Neplatný port SMTP.');
        $encryption = $input['encryption'] ?? null;
        if (!in_array($encryption, ['tls', 'ssl'], true)) throw new \InvalidArgumentException('SMTP vyžaduje ověřované TLS.');
        $username = self::text($input, 'username', 190);
        $from = self::text($input, 'from_email', 190);
        $recipient = self::text($input, 'test_recipient', 190);
        if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Neplatná e-mailová adresa.');
        }
        return ['name' => $name, 'host' => $host, 'port' => $port, 'encryption' => $encryption,
            'username' => $username, 'from_email' => $from, 'test_recipient' => $recipient];
    }

    public static function onlyKeys(array $input, array $keys): void
    {
        if (array_diff(array_keys($input), $keys) !== []) {
            throw new \InvalidArgumentException('Požadavek obsahuje nepovolené pole.');
        }
    }

    private static function text(array $input, string $key, int $max): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new \InvalidArgumentException('Neplatné technické nastavení SMTP.');
        }
        return trim($value);
    }
}

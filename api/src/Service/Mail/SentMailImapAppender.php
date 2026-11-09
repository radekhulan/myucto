<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Service\Mail\Imap\ImapAuthentication;
use MyInvoice\Service\Mail\Imap\ImapAuthSettings;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;

final class SentMailImapAppender implements SentMailAppenderInterface
{
    private ?\Closure $clientFactory;

    public function __construct(
        ?callable $clientFactory = null,
        private readonly ?ImapAuthentication $authentication = null,
    ) {
        $this->clientFactory = $clientFactory !== null ? \Closure::fromCallable($clientFactory) : null;
    }

    /**
     * @param array<string,mixed>|null $profile
     * @return array{status:'skipped'|'saved'|'failed',folder:?string,error:?string}
     */
    public function appendIfEnabled(?array $profile, string $rawMessage): array
    {
        if ($profile === null || !($profile['imap_sent_enabled'] ?? false)) {
            return $this->skipped();
        }

        try {
            $settings = $this->settings($profile);
            $client = $this->client($settings, $profile);
            $client->connect();
            try {
                $folder = $this->folder($client, $settings);
                $folder->appendMessage($rawMessage, $this->appendFlags($settings), date('d-M-Y H:i:s O'));
            } finally {
                $client->disconnect();
            }

            return [
                'status' => 'saved',
                'folder' => $settings['folder'],
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'folder' => isset($settings) ? $settings['folder'] : $this->stringOrNull($profile['imap_folder'] ?? null),
                'error' => $this->sanitizedErrorMessage($e, $settings ?? null, $profile),
            ];
        }
    }

    /**
     * @param array<string,mixed>|null $profile
     * @return array{ok:bool,message:string,folders?:list<array<string,mixed>>}
     */
    public function test(?array $profile): array
    {
        if ($profile === null || !($profile['imap_sent_enabled'] ?? false)) {
            return ['ok' => true, 'message' => 'Ukládání do IMAP složky je vypnuté.'];
        }

        try {
            $settings = $this->settings($profile);
            $client = $this->client($settings, $profile);
            $client->connect();
            try {
                $folder = $this->folder($client, $settings);
                $folder->appendMessage($this->probeMessage(), $this->appendFlags($settings), date('d-M-Y H:i:s O'));
                $folders = $this->folderMetadata($client);
            } finally {
                $client->disconnect();
            }

            return ['ok' => true, 'message' => 'IMAP připojení, cílová složka a zápis testovací zprávy jsou funkční.', 'folders' => $folders];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->sanitizedErrorMessage($e, $settings ?? null, $profile)];
        }
    }

    /**
     * @param array<string,mixed>|null $profile
     * @return array{ok:bool,message:string,folders?:list<array<string,mixed>>}
     */
    public function folders(?array $profile): array
    {
        if ($profile === null || !($profile['imap_sent_enabled'] ?? false)) {
            return ['ok' => true, 'message' => 'Ukládání do IMAP složky je vypnuté.', 'folders' => []];
        }

        try {
            $settings = $this->settings($profile);
            $client = $this->client($settings, $profile);
            $client->connect();
            try {
                $folders = $this->folderMetadata($client);
            } finally {
                $client->disconnect();
            }

            return ['ok' => true, 'message' => 'IMAP složky byly načteny.', 'folders' => $folders];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->sanitizedErrorMessage($e, $settings ?? null, $profile)];
        }
    }

    /**
     * @param array<string,mixed> $profile
     * @return array{host:string,port:int,encryption:'none'|'tls'|'ssl',validate_cert:bool,username:string,password:string,folder:string,create_folder:bool,mark_seen:bool,timeout:int,on_failure:'log_only'|'fail_send'}
     */
    private function settings(array $profile): array
    {
        $host = $this->requiredString($profile['imap_host'] ?? null, 'imap_host');
        $username = $this->requiredString($profile['imap_username'] ?? null, 'imap_username');
        $password = ($profile['imap_auth_mode'] ?? null) === ImapAuthSettings::MICROSOFT
            ? ''
            : $this->requiredString($profile['imap_password'] ?? null, 'imap_password');
        $folder = $this->requiredString($profile['imap_folder'] ?? 'Sent', 'imap_folder');
        $encryption = (string) ($profile['imap_encryption'] ?? 'ssl');
        if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            $encryption = 'ssl';
        }

        return [
            'host' => $host,
            'port' => max(1, min(65535, (int) ($profile['imap_port'] ?? 993))),
            'encryption' => $encryption,
            'validate_cert' => (bool) ($profile['imap_validate_cert'] ?? true),
            'username' => $username,
            'password' => $password,
            'folder' => $folder,
            'create_folder' => (bool) ($profile['imap_create_folder'] ?? false),
            'mark_seen' => (bool) ($profile['imap_mark_seen'] ?? true),
            'timeout' => max(1, min(300, (int) ($profile['imap_timeout'] ?? 30))),
            'on_failure' => ((string) ($profile['imap_on_failure'] ?? 'log_only')) === 'fail_send' ? 'fail_send' : 'log_only',
        ];
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function client(array $settings, array $profile): Client
    {
        $auth = [];
        if (($profile['imap_auth_mode'] ?? null) === ImapAuthSettings::MICROSOFT) {
            $auth = ($this->authentication ?? throw new \LogicException('IMAP OAuth není nakonfigurován.'))
                ->options($profile, ImapAuthSettings::SENT, 'imap_');
        }
        if ($this->clientFactory !== null) {
            $client = ($this->clientFactory)($auth + $settings);
            if (!$client instanceof Client) {
                throw new \RuntimeException('IMAP client factory vrátila neplatný klient.');
            }

            return $client;
        }

        if (!class_exists(ClientManager::class)) {
            throw new \RuntimeException('Knihovna webklex/php-imap není nainstalovaná.');
        }

        $manager = new ClientManager([
            'default' => 'default',
            'accounts' => [],
            'options' => [
                'fetch' => \Webklex\PHPIMAP\IMAP::FT_PEEK,
                'sequence' => \Webklex\PHPIMAP\IMAP::ST_UID,
                'rfc822' => true,
                'soft_fail' => true,
                'debug' => false,
            ],
        ]);

        return $manager->make($auth + [
            'host' => $settings['host'],
            'port' => $settings['port'],
            'protocol' => 'imap',
            'encryption' => $settings['encryption'] === 'none' ? false : $settings['encryption'],
            'validate_cert' => $settings['validate_cert'],
            'username' => $settings['username'],
            'password' => $settings['password'],
            'authentication' => null,
            'rfc' => 'RFC822',
            'timeout' => $settings['timeout'],
            'extensions' => [],
        ]);
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function folder(Client $client, array $settings): Folder
    {
        $path = (string) $settings['folder'];
        $folder = $client->getFolderByPath($path, false, true);
        if ($folder !== null) {
            return $folder;
        }

        foreach ($client->getFolders(false, null, true) as $candidate) {
            if ((string) $candidate->full_name === $path || (string) $candidate->name === $path) {
                return $candidate;
            }
        }

        if ($settings['create_folder'] === true) {
            return $client->createFolder($path);
        }

        throw new \RuntimeException('IMAP složka nebyla nalezena: ' . $path);
    }

    /**
     * @return list<array{path:string,full_name:string,name:string,delimiter:string,writable:bool,system:bool,sent:bool,no_select:bool,has_children:bool}>
     */
    private function folderMetadata(Client $client): array
    {
        $folders = [];
        foreach ($client->getFolders(false, null, true) as $folder) {
            $path = trim((string) $folder->path);
            if ($path !== '') {
                $fullName = trim((string) $folder->full_name);
                $name = trim((string) $folder->name);
                $folders[] = [
                    'path' => $path,
                    'full_name' => $fullName !== '' ? $fullName : $path,
                    'name' => $name !== '' ? $name : $path,
                    'delimiter' => (string) $folder->delimiter,
                    'writable' => !$folder->no_select,
                    'system' => $this->isSystemFolder($folder),
                    'sent' => $this->isSentFolder($folder),
                    'no_select' => $folder->no_select,
                    'has_children' => $folder->has_children,
                ];
            }
        }
        usort(
            $folders,
            static fn (array $a, array $b): int => strnatcasecmp((string) $a['path'], (string) $b['path']),
        );

        return $folders;
    }

    /**
     * @param array<string,mixed> $settings
     * @return list<string>|null
     */
    private function appendFlags(array $settings): ?array
    {
        return ($settings['mark_seen'] ?? true) ? ['\Seen'] : null;
    }

    private function probeMessage(): string
    {
        $id = bin2hex(random_bytes(12)) . '@myinvoice.local';
        $now = date(DATE_RFC2822);

        return implode("\r\n", [
            'From: MyInvoice IMAP Test <no-reply@myinvoice.local>',
            'To: MyInvoice IMAP Test <no-reply@myinvoice.local>',
            'Subject: MyInvoice IMAP append test',
            'Message-ID: <' . $id . '>',
            'Date: ' . $now,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-MyInvoice-IMAP-Test: 1',
            '',
            'Tato zprava overuje zapis do IMAP slozky odeslane posty.',
            'Muze byt bezpecne smazana.',
            '',
        ]);
    }

    private function isSystemFolder(Folder $folder): bool
    {
        $text = $this->normalizedFolderText($folder);
        foreach (['inbox', 'sent', 'draft', 'trash', 'junk', 'spam', 'archive'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isSentFolder(Folder $folder): bool
    {
        $text = $this->normalizedFolderText($folder);
        foreach (['sent', 'sent items', 'sent messages', 'odeslana', 'odeslane'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizedFolderText(Folder $folder): string
    {
        $text = implode(' ', [
            (string) $folder->path,
            (string) $folder->full_name,
            (string) $folder->name,
        ]);
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;

        return strtolower($text);
    }

    /**
     * @return array{status:'skipped',folder:null,error:null}
     */
    private function skipped(): array
    {
        return ['status' => 'skipped', 'folder' => null, 'error' => null];
    }

    private function requiredString(mixed $value, string $field): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            throw new \InvalidArgumentException("Pole '{$field}' je povinné.");
        }

        return $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string,mixed>|null $settings
     * @param array<string,mixed>|null $profile
     */
    private function sanitizedErrorMessage(\Throwable $e, ?array $settings = null, ?array $profile = null): string
    {
        if (($profile['imap_auth_mode'] ?? null) === ImapAuthSettings::MICROSOFT) {
            return ImapAuthentication::failureMessage($e);
        }
        $message = trim($e->getMessage());
        if ($message === '') {
            $message = get_class($e);
        }

        foreach ($this->sensitiveValues($settings, $profile) as $value) {
            $message = str_replace($value, '[redacted]', $message);
        }

        $message = preg_replace(
            '~([a-z][a-z0-9+.-]*://)(?:[^/@\s:]+(?::[^/@\s]*)?@)~i',
            '$1[redacted]@',
            $message,
        ) ?? $message;
        $message = preg_replace(
            '~\b(password|passwd|pass|pwd|secret|token|username|user|login)\s*([=:])\s*([^\s,;]+)~i',
            '$1$2[redacted]',
            $message,
        ) ?? $message;
        $message = preg_replace(
            "~(?<![\\w.-])(?:[A-Za-z]:[\\\\/]|/(?:home|var|tmp|etc|usr|opt|srv|mnt|run|private|Users)/)[^\\s<>\"']+~u",
            '[path]',
            $message,
        ) ?? $message;

        return mb_substr(trim($message), 0, 500);
    }

    /**
     * @param array<string,mixed>|null $settings
     * @param array<string,mixed>|null $profile
     * @return list<string>
     */
    private function sensitiveValues(?array $settings, ?array $profile): array
    {
        $values = [];
        foreach ([$settings, $profile] as $source) {
            if ($source === null) {
                continue;
            }
            foreach (['password', 'imap_password', 'username', 'imap_username'] as $key) {
                $value = trim((string) ($source[$key] ?? ''));
                if (mb_strlen($value) < 3) {
                    continue;
                }
                $values[] = $value;
                $values[] = rawurlencode($value);
                $values[] = urlencode($value);
            }
        }

        $values = array_values(array_unique($values));
        usort($values, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $values;
    }
}

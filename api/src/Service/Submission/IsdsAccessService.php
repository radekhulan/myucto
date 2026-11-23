<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Repository\EpoSigningCredentialRepository;
use MyInvoice\Repository\Submission\IsdsAccessProfileRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\Isds\DirectIsdsInboxTransport;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use Psr\Http\Message\ServerRequestInterface as Request;
use SensitiveParameter;

final readonly class IsdsAccessService
{
    public function __construct(
        private IsdsAccessProfileRepository $profiles,
        private EpoSigningCredentialRepository $vault,
        private SharedCertificateResolver $certificates,
        private SecretEncryption $crypto,
        private DirectIsdsInboxTransport $transport,
        private ActivityLogger $logger,
    ) {}

    public static function environment(mixed $value): string
    {
        if ($value !== 'production' && $value !== 'test') {
            throw new SubmissionChannelException('invalid_environment', 'Neznámé prostředí datové schránky.', 400);
        }
        return $value;
    }

    public static function operation(mixed $value): string
    {
        if (!in_array($value, ['send', 'receive', 'receipts'], true)) {
            throw new SubmissionChannelException('invalid_isds_operation', 'Neznámá operace datové schránky.', 400);
        }
        return $value;
    }

    public function choices(int $supplier, int $actor, string $environment, string $operation): array
    {
        return array_map(self::choice(...), $this->profiles->choices($supplier, $actor, $environment, $operation));
    }

    public function adminItems(int $owner, array $allowedSuppliers): array
    {
        $rows = $this->profiles->summaries($owner);
        $byProfile = $this->profiles->grantsByProfile(array_column($rows, 'id'));
        return array_map(
            static fn (array $row): array => self::summary($row) + [
                'grants' => array_values(array_filter(
                    $byProfile[(int) $row['id']] ?? [],
                    static fn (array $grant): bool => in_array($grant['supplier_id'], $allowedSuppliers, true),
                )),
            ],
            $rows,
        );
    }

    public function certificates(int $owner, int $currentSupplier): array
    {
        return array_values(array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'valid_to' => $row['valid_to'],
            ],
            array_filter(
                $this->vault->listOwnedForSupplier($owner, $currentSupplier),
                static fn (array $row): bool => ($row['valid_now'] ?? false) === true,
            ),
        ));
    }

    public function remember(int $supplier, int $actor, string $environment, string $operation, #[SensitiveParameter] array $body): array
    {
        $this->assertEncryptionReady();
        [$username, $password] = self::validatePassword($body['username'] ?? null, $body['password'] ?? null);
        $context = new ChannelContext($supplier, $environment, new ChannelCredentials(
            boxId: '',
            authMode: 'password',
            username: SensitiveValue::fromProducer(static fn (): string => $username),
            password: SensitiveValue::fromProducer(static fn (): string => $password),
        ));
        $boxId = $this->transport->authenticatedBoxId($context);
        $row = $this->encryptedRow($actor, $supplier, 'personal', $environment, 'password', $boxId, $boxId, null, $username, $password);
        $id = $this->profiles->save($row, null, [[
            'supplier_id' => $supplier, 'send' => true, 'receipts' => true, 'receive' => true,
        ]], receiveRequired: $operation === 'receive');
        $this->audit('isds_access_saved', $actor, $id, $supplier, $boxId, $environment);
        return self::choice(['id' => $id] + $row);
    }

    public function saveShared(int $actor, int $currentSupplier, ?int $id, array $allowedSuppliers, #[SensitiveParameter] array $body): array
    {
        $existing = $id !== null ? $this->profiles->owned($id, $actor, 'shared') : null;
        if ($id !== null && $existing === null) {
            throw new SubmissionChannelException('isds_access_not_found', 'Uložený přístup neexistuje.', 404);
        }
        if ($id !== null) {
            $this->assertAllGrantsVisible($id, $allowedSuppliers);
        }
        $environment = self::environment($body['environment'] ?? $existing['environment'] ?? null);
        if ($existing !== null && $environment !== $existing['environment']) {
            throw new SubmissionChannelException('isds_access_environment_immutable', 'Pro jiné prostředí založte nový přístup.', 422);
        }
        $label = is_string($body['label'] ?? null) ? trim($body['label']) : '';
        if ($label === '' || mb_strlen($label) > 120 || preg_match('/[\x00-\x1f\x7f]/', $label) === 1) {
            throw new SubmissionChannelException('isds_access_invalid_label', 'Zadejte název přístupu o nejvýše 120 znacích.', 422);
        }

        $method = $body['method'] ?? $existing['method'] ?? null;
        if (!in_array($method, ['password', 'certificate', 'user_certificate'], true)) {
            throw new SubmissionChannelException('isds_access_invalid_method', 'Zvolte platný způsob přihlášení.', 422);
        }
        $grants = $this->validateGrants($body['grants'] ?? null, $allowedSuppliers);
        $certificateId = $method === 'password' ? null : self::positiveId($body['certificate_id'] ?? $existing['certificate_id'] ?? null);
        if ($method !== 'password' && ($certificateId === null || $this->vault->findOwned($certificateId, $actor) === null)) {
            throw new SubmissionChannelException('isds_access_invalid_certificate', 'Vyberte vlastní certifikát z trezoru.', 422);
        }

        $requiresPassword = $method !== 'certificate';
        $hasPassword = array_key_exists('username', $body) || array_key_exists('password', $body);
        $replace = $existing === null || $method !== $existing['method']
            || $certificateId !== self::positiveId($existing['certificate_id']) || $hasPassword;
        if (!$replace) {
            $row = array_replace($existing, ['label' => $label]);
        } else {
            $this->assertEncryptionReady();
            $username = null;
            $password = null;
            if ($requiresPassword) {
                [$username, $password] = self::validatePassword($body['username'] ?? null, $body['password'] ?? null);
            } elseif ($hasPassword) {
                throw new SubmissionChannelException('isds_access_invalid_method', 'Systémový certifikát nepoužívá uživatelské jméno a heslo.', 422);
            }
            $credentials = $this->credentials($method, '', $certificateId, $username, $password);
            $boxId = $this->transport->authenticatedBoxId(new ChannelContext($currentSupplier, $environment, $credentials));
            if ($existing !== null && $boxId !== $existing['box_id']) {
                throw new SubmissionChannelException('isds_access_box_mismatch', 'Nové údaje patří jiné schránce. Založte pro ni nový přístup.', 422);
            }
            $row = $this->encryptedRow($actor, null, 'shared', $environment, $method, $boxId, $label, $certificateId, $username, $password);
        }
        $id = $this->profiles->save($row, $id, $grants, receiveRequired: true, expectedContext: $existing['secret_context'] ?? null);
        $this->audit('isds_access_shared_saved', $actor, $id, $currentSupplier, $row['box_id'], $environment, [
            'grants' => $grants,
        ]);
        return self::summary(['id' => $id] + $row) + ['grants' => $grants];
    }

    public function forget(int $id, int $actor, string $scope, int $currentSupplier, array $allowedSuppliers = []): void
    {
        $row = $this->profiles->owned($id, $actor, $scope);
        if ($scope === 'shared' && $row !== null) {
            $this->assertAllGrantsVisible($id, $allowedSuppliers);
        }
        if ($row === null || !$this->profiles->delete($id, $actor, $scope, $scope === 'personal' ? $currentSupplier : null)) {
            throw new SubmissionChannelException('isds_access_not_found', 'Uložený přístup neexistuje.', 404);
        }
        $this->audit('isds_access_removed', $actor, $id, $currentSupplier, $row['box_id'], $row['environment']);
    }

    /** @return array{context:ChannelContext,can_receipts:bool} */
    public function use(int $id, int $supplier, int $actor, string $environment, string $operation): array
    {
        $row = $this->profiles->usable($id, $supplier, $actor, $environment, $operation);
        if ($row === null) {
            throw new SubmissionChannelException('isds_access_unavailable', 'Uložený přístup pro tuto firmu a akci není dostupný.', 403);
        }
        $this->assertEncryptionReady();
        $username = null;
        $password = null;
        if ($row['method'] !== 'certificate') {
            try {
                $username = $this->decode($row, 'username');
                $password = $this->decode($row, 'password');
            } catch (\RuntimeException) {
                throw new SubmissionChannelException('isds_access_decryption_failed', 'Uložené přihlášení nelze otevřít. Uložte je znovu.', 409);
            }
        }
        $context = new ChannelContext(
            $supplier,
            $environment,
            $this->credentials($row['method'], $row['box_id'], self::positiveId($row['certificate_id']), $username, $password),
        );

        if ($this->transport->authenticatedBoxId($context) !== $row['box_id']) {
            throw new SubmissionChannelException('isds_access_box_mismatch', 'Přístup už neodpovídá ověřené datové schránce.', 409);
        }
        $this->audit('isds_access_used', $actor, $id, $supplier, $row['box_id'], $environment, ['operation' => $operation]);
        return [
            'context' => new ChannelContext($supplier, $environment, $context->credentials, $row['box_id']),
            'can_receipts' => (bool) $row['can_receipts'],
        ];
    }

    /** @return array{context:ChannelContext,can_receipts:bool} */
    public function useSelected(Request $request, int $supplier, int $actor, string $operation, #[SensitiveParameter] array $body): array
    {
        if ($actor <= 0 || !RequestAuthorization::isSessionAuth($request)) {
            throw new SubmissionChannelException('session_required', 'Použijte přihlášené webové rozhraní.', 403);
        }
        if (($body['authorized'] ?? null) !== true) {
            throw new SubmissionChannelException('isds_access_confirmation_required', 'Potvrďte použití uloženého přístupu.', 400);
        }
        return $this->use(
            self::accessId($body), $supplier, $actor, self::environment($body['environment'] ?? 'production'), $operation,
        );
    }

    private static function accessId(#[SensitiveParameter] array $body): int
    {
        $id = self::positiveId($body['access_id'] ?? null);
        if ($id === null || array_key_exists('username', $body) || array_key_exists('password', $body)) {
            throw new SubmissionChannelException('isds_access_invalid_selection', 'Zvolte jediný uložený přístup.', 400);
        }
        return $id;
    }

    private static function positiveId(mixed $value): ?int
    {
        return (is_int($value) || is_string($value))
            ? (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null)
            : null;
    }

    private static function validatePassword(#[SensitiveParameter] mixed $username, #[SensitiveParameter] mixed $password): array
    {
        if (!is_string($username) || ($username = trim($username)) === '' || strlen($username) > 128
            || preg_match('/[\x00-\x20:\x7f]/', $username) === 1) {
            throw new SubmissionChannelException('isds_username_invalid', 'Vyplňte platné uživatelské jméno k datové schránce.', 400);
        }
        if (!is_string($password) || $password === '' || strlen($password) > 512
            || preg_match('/[\x00-\x1f\x7f]/', $password) === 1) {
            throw new SubmissionChannelException('isds_password_invalid', 'Vyplňte platné heslo k datové schránce.', 400);
        }
        return [$username, $password];
    }

    private function credentials(string $method, string $box, ?int $certificateId, #[SensitiveParameter] ?string $username, #[SensitiveParameter] ?string $password): ChannelCredentials
    {
        $certificate = $certificateId !== null ? $this->certificates->resolve($certificateId, null) : null;
        if ($certificate !== null && $certificate->isExpired()) {
            throw new SubmissionChannelException('isds_certificate_expired', 'Certifikátu skončila platnost.', 422);
        }
        return new ChannelCredentials(
            boxId: $box,
            authMode: $method,
            certificate: $certificate?->certificate,
            certificatePassphrase: $certificate?->passphrase,
            username: $username !== null ? SensitiveValue::fromProducer(static fn (): string => $username) : null,
            password: $password !== null ? SensitiveValue::fromProducer(static fn (): string => $password) : null,
        );
    }

    private function encryptedRow(
        int $owner, ?int $supplier, string $scope, string $environment, string $method, string $box, string $label,
        ?int $certificate, #[SensitiveParameter] ?string $username, #[SensitiveParameter] ?string $password,
    ): array {
        $row = [
            'owner_user_id' => $owner, 'personal_supplier_id' => $supplier, 'scope' => $scope,
            'environment' => $environment, 'box_id' => $box, 'label' => $label, 'method' => $method,
            'certificate_id' => $certificate, 'secret_context' => bin2hex(random_bytes(16)),
        ];
        return $row + [
            'username_ciphertext' => $username === null ? null : $this->crypto->encryptFor($username, self::context($row, 'username')),
            'password_ciphertext' => $password === null ? null : $this->crypto->encryptFor($password, self::context($row, 'password')),
        ];
    }

    private static function context(array $row, string $field): string
    {
        return implode(':', ['isds', 'access', $row['scope'], $row['owner_user_id'], $row['personal_supplier_id'] ?? '-',
            $row['environment'], $row['box_id'], $row['secret_context'], $field]);
    }

    private function decode(#[SensitiveParameter] array $row, string $field): string
    {
        $ciphertext = $row[$field . '_ciphertext'] ?? '';
        if (!is_string($ciphertext) || !str_starts_with($ciphertext, 'enc:v2:')) {
            throw new \RuntimeException('Uložený přístup nemá platný šifrovaný údaj.');
        }
        return $this->crypto->decryptFor($ciphertext, self::context($row, $field));
    }

    private function assertEncryptionReady(): void
    {
        if ($this->crypto->validateKey() !== null) {
            throw new SubmissionChannelException('encryption_key_required', 'Pro uložené přihlášení nastavte samostatný šifrovací klíč aplikace.', 503);
        }
    }

    private function validateGrants(mixed $input, array $allowed): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) > count($allowed)) {
            throw new SubmissionChannelException('isds_access_invalid_grants', 'Vyberte firmy a jejich oprávnění.', 422);
        }
        $grants = [];
        $seen = [];
        $receiving = false;
        foreach ($input as $row) {
            $supplier = is_array($row) ? self::positiveId($row['supplier_id'] ?? null) : null;
            if ($supplier === null || !in_array($supplier, $allowed, true) || isset($seen[$supplier])) {
                throw new SubmissionChannelException('isds_access_invalid_grants', 'Některá firma pro sdílení není dostupná.', 422);
            }
            $seen[$supplier] = true;
            $send = ($row['send'] ?? false) === true;
            $receive = ($row['receive'] ?? false) === true;
            $receipts = ($row['receipts'] ?? false) === true;
            if ($receive && $receiving) {
                throw new SubmissionChannelException('isds_inbox_assigned_elsewhere', 'Příjem z jedné schránky lze povolit jen v jedné firmě.', 422);
            }
            if ($send || $receive || $receipts) {
                $grants[$supplier] = ['supplier_id' => $supplier, 'send' => $send, 'receive' => $receive, 'receipts' => $receipts];
            }
            $receiving = $receiving || $receive;
        }
        return array_values($grants);
    }

    private function assertAllGrantsVisible(int $id, array $allowedSuppliers): void
    {
        foreach ($this->profiles->grantsByProfile([$id])[$id] ?? [] as $grant) {
            if (!in_array($grant['supplier_id'], $allowedSuppliers, true)) {
                throw new SubmissionChannelException('isds_access_grants_not_editable', 'Přístup spravujte z kontextu, ve kterém vidíte všechny jeho firmy.', 403);
            }
        }
    }

    private static function choice(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'label' => (string) $row['label'], 'box_id' => (string) $row['box_id'],
            'method' => (string) $row['method'], 'scope' => (string) $row['scope'],
        ];
    }

    private static function summary(array $row): array
    {
        return self::choice($row) + [
            'environment' => (string) $row['environment'],
            'certificate_id' => self::positiveId($row['certificate_id']),
        ];
    }

    private function audit(string $action, int $actor, int $id, int $supplier, string $box, string $environment, array $extra = []): void
    {
        $this->logger->log($action, $actor, 'isds_access', $id, [
            'box_id' => $box, 'environment' => $environment,
        ] + $extra, null, null, $supplier);
    }
}

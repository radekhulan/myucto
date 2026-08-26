<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Repository\Submission\IsdsMobileCredentialRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/** Osobní, tenantově oddělený trezor vstupů pro Mobilní klíč eGovernmentu. */
final readonly class IsdsMobileCredentialService
{
    public function __construct(
        private IsdsMobileCredentialRepository $repository,
        private SecretEncryption $crypto,
    ) {}

    /** @return array{id:int,saved:bool,username:string,environment:string}|array{saved:false,username:null,environment:string} */
    public function profile(int $supplierId, int $userId, string $environment): array
    {
        $this->assertEncryptionReady();
        $this->assertScope($supplierId, $userId, $environment);
        $row = $this->repository->findWithSecrets($supplierId, $userId, $environment);
        if ($row === null) {
            return ['saved' => false, 'username' => null, 'environment' => $environment];
        }
        try {
            $username = $this->crypto->decryptFor(
                (string) $row['username_ciphertext'],
                $this->context('username', $supplierId, $userId, $environment),
            );
        } catch (\RuntimeException) {
            throw new SubmissionChannelException(
                'isds_mobile_credential_decryption_failed',
                'Uložené přihlášení Mobilním klíčem nelze rozšifrovat. Uložte ho znovu.',
                500,
            );
        }
        return [
            'id' => (int) $row['id'],
            'saved' => true,
            'username' => $username,
            'environment' => $environment,
        ];
    }

    /** @return array{id:int,saved:true,username:string,environment:string} */
    public function save(
        int $supplierId,
        int $userId,
        string $environment,
        string $username,
        string $communicationCode,
    ): array {
        $this->assertEncryptionReady();
        $this->assertScope($supplierId, $userId, $environment);
        $username = $this->validateUsername($username);
        $this->validateCommunicationCode($communicationCode);
        $this->repository->save(
            $supplierId,
            $userId,
            $environment,
            $this->crypto->encryptFor($username, $this->context('username', $supplierId, $userId, $environment)),
            $this->crypto->encryptFor($communicationCode, $this->context('communication-code', $supplierId, $userId, $environment)),
        );
        $profile = $this->profile($supplierId, $userId, $environment);
        if (($profile['saved'] ?? false) !== true || !isset($profile['id']) || !is_int($profile['id'])) {
            throw new SubmissionChannelException('isds_mobile_credential_store_failed', 'Přihlášení se nepodařilo uložit.', 500);
        }
        /** @var array{id:int,saved:true,username:string,environment:string} $profile */
        return $profile;
    }

    public function unlock(int $supplierId, int $userId, string $environment): ChannelCredentials
    {
        $this->assertEncryptionReady();
        $this->assertScope($supplierId, $userId, $environment);
        $row = $this->repository->findWithSecrets($supplierId, $userId, $environment);
        if ($row === null) {
            throw new SubmissionChannelException(
                'isds_mobile_credentials_missing',
                'Pro tuto firmu a Váš účet není přihlášení Mobilním klíčem uložené.',
                409,
            );
        }
        $crypto = $this->crypto;
        $usernameCiphertext = (string) $row['username_ciphertext'];
        $codeCiphertext = (string) $row['communication_code_ciphertext'];
        $usernameContext = $this->context('username', $supplierId, $userId, $environment);
        $codeContext = $this->context('communication-code', $supplierId, $userId, $environment);
        return new ChannelCredentials(
            boxId: '',
            authMode: 'mobile_key',
            username: SensitiveValue::fromProducer(static fn (): string => $crypto->decryptFor($usernameCiphertext, $usernameContext)),
            password: SensitiveValue::fromProducer(static fn (): string => $crypto->decryptFor($codeCiphertext, $codeContext)),
        );
    }

    public function delete(int $supplierId, int $userId, string $environment): bool
    {
        $this->assertScope($supplierId, $userId, $environment);
        return $this->repository->delete($supplierId, $userId, $environment);
    }

    private function validateUsername(string $username): string
    {
        $username = trim($username);
        if ($username === '' || strlen($username) > 128 || preg_match('/[\x00-\x20:\x7f]/', $username) === 1) {
            throw new SubmissionChannelException('isds_mobile_username_invalid', 'Vyplňte platné uživatelské jméno k datové schránce.', 400);
        }
        return $username;
    }

    private function validateCommunicationCode(string $code): void
    {
        if ($code === '' || strlen($code) > 256 || preg_match('/[\x00-\x1f\x7f]/', $code) === 1) {
            throw new SubmissionChannelException('isds_mobile_code_invalid', 'Vyplňte komunikační kód pro Mobilní klíč.', 400);
        }
    }

    private function assertScope(int $supplierId, int $userId, string $environment): void
    {
        if ($supplierId <= 0 || $userId <= 0) {
            throw new SubmissionChannelException('invalid_scope', 'Chybí firma nebo přihlášený uživatel.', 400);
        }
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new SubmissionChannelException('invalid_environment', 'Neznámé prostředí.', 400);
        }
    }

    private function assertEncryptionReady(): void
    {
        if ($this->crypto->validateKey() !== null) {
            throw new SubmissionChannelException('encryption_key_required', 'Pro uložení přihlášení musí být nastavený šifrovací klíč aplikace.', 503);
        }
    }

    private function context(string $field, int $supplierId, int $userId, string $environment): string
    {
        return "isds:mobile-credential:{$field}:supplier:{$supplierId}:user:{$userId}:environment:{$environment}";
    }
}

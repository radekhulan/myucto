<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaStepUpProof;
use MyInvoice\Service\Auth\MfaStepUpProofStore;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Service\Auth\StoredPasskeyCredential;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\TrustPath\EmptyTrustPath;

#[AllowMockObjectsWithoutExpectations]
final class MfaStepUpServiceTest extends TestCase
{
    public function testUnknownOperationIsRejectedBeforeProofIssuance(): void
    {
        $proofs = $this->createMock(MfaStepUpProofStore::class);
        $proofs->expects(self::never())->method('issue');
        $service = new MfaStepUpService(
            $proofs,
            $this->createMock(MfaPolicyService::class),
            $this->createMock(PasskeyCredentialRepository::class),
        );

        $this->expectException(StepUpOperationException::class);
        $service->issue(17, 'session', 'admin.anything', 'totp');
    }

    public function testPhasedOutTotpCanAuthorizePasskeyEnrollmentOnly(): void
    {
        $proofs = $this->createMock(MfaStepUpProofStore::class);
        $proofs->expects(self::once())
            ->method('issue')
            ->with(17, 'session', MfaStepUpService::OPERATION_PASSKEY_REGISTER, 'totp', null)
            ->willReturn('proof-token');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::exactly(2))
            ->method('isMethodAllowed')
            ->willReturnMap([
                ['totp', false],
                ['passkey', true],
            ]);
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())
            ->method('countActiveForUser')
            ->with(17)
            ->willReturn(0);
        $service = new MfaStepUpService(
            $proofs,
            $policy,
            $credentials,
        );

        self::assertSame(
            'proof-token',
            $service->issue(
                17,
                'session',
                MfaStepUpService::OPERATION_PASSKEY_REGISTER,
                'totp',
            ),
        );
    }

    public function testPhasedOutTotpCannotAuthorizeAnotherPasskeyEnrollment(): void
    {
        $proofs = $this->createMock(MfaStepUpProofStore::class);
        $proofs->expects(self::never())->method('issue');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::exactly(2))
            ->method('isMethodAllowed')
            ->willReturnMap([
                ['totp', false],
                ['passkey', true],
            ]);
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())
            ->method('countActiveForUser')
            ->with(17)
            ->willReturn(1);
        $service = new MfaStepUpService($proofs, $policy, $credentials);

        $this->expectException(StepUpOperationException::class);
        $service->issue(
            17,
            'session',
            MfaStepUpService::OPERATION_PASSKEY_REGISTER,
            'totp',
        );
    }

    public function testTotpCannotAuthorizeEnrollmentWhenPasskeysAreNotAllowed(): void
    {
        $proofs = $this->createMock(MfaStepUpProofStore::class);
        $proofs->expects(self::never())->method('issue');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::exactly(2))
            ->method('isMethodAllowed')
            ->willReturnMap([
                ['totp', true],
                ['passkey', false],
            ]);
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::never())->method('countActiveForUser');
        $service = new MfaStepUpService($proofs, $policy, $credentials);

        $this->expectException(StepUpOperationException::class);
        $service->issue(
            17,
            'session',
            MfaStepUpService::OPERATION_PASSKEY_REGISTER,
            'totp',
        );
    }

    public function testDisallowedMethodCannotAuthorizeApiTokenCreation(): void
    {
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::once())->method('isMethodAllowed')->with('totp')->willReturn(false);
        $service = new MfaStepUpService(
            $this->createMock(MfaStepUpProofStore::class),
            $policy,
            $this->createMock(PasskeyCredentialRepository::class),
        );

        $this->expectException(StepUpOperationException::class);
        $service->assertAllowed(17, MfaStepUpService::OPERATION_API_TOKEN_CREATE, 'totp');
    }

    public function testRevokeOperationMustReferenceOwnedActiveCredential(): void
    {
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())
            ->method('findActiveForUserById')
            ->with(17, 42)
            ->willReturn(null);
        $service = new MfaStepUpService(
            $this->createMock(MfaStepUpProofStore::class),
            $this->createMock(MfaPolicyService::class),
            $credentials,
        );

        $this->expectException(StepUpOperationException::class);
        $service->assertAllowed(17, 'passkey.revoke:42', 'passkey');
    }

    public function testConsumedPasskeyProofRequiresAuthenticationCredentialToRemainActive(): void
    {
        $proofs = $this->createMock(MfaStepUpProofStore::class);
        $proofs->expects(self::once())
            ->method('consume')
            ->with('proof', 17, 'session', MfaStepUpService::OPERATION_API_TOKEN_CREATE)
            ->willReturn(new MfaStepUpProof(
                17,
                MfaStepUpService::OPERATION_API_TOKEN_CREATE,
                'passkey',
                42,
            ));
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::once())->method('isMethodAllowed')->with('passkey')->willReturn(true);
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())
            ->method('findActiveForUserById')
            ->with(17, 42)
            ->willReturn(null);
        $service = new MfaStepUpService($proofs, $policy, $credentials);

        $this->expectException(StepUpOperationException::class);
        $service->consume(
            'proof',
            17,
            'session',
            MfaStepUpService::OPERATION_API_TOKEN_CREATE,
        );
    }
    /**
     * Záložní kód smí odebrat ztracený klíč — to je hlavní důvod, proč existuje.
     * Bez toho by se uživatel sice přihlásil, ale mrtvý passkey by ze seznamu
     * neodstranil a zůstal by na něm viset jako platný faktor.
     */
    public function testRecoveryCodeCanAuthorizePasskeyRevocation(): void
    {
        $proofs = $this->createMock(MfaStepUpProofStore::class);
        $proofs->expects(self::once())
            ->method('issue')
            ->with(17, 'session', 'passkey.revoke:42', 'recovery', null)
            ->willReturn('proof-token');
        $policy = $this->createMock(MfaPolicyService::class);
        // Politika `allowed_mfa_methods` se na break-glass kód schválně neptá —
        // kdyby ji musel splnit, byl by k nepotřebě právě v konfiguraci, která
        // uživatele zamkla ven.
        $policy->expects(self::never())->method('isMethodAllowed');
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())
            ->method('findActiveForUserById')
            ->with(17, 42)
            ->willReturn(self::storedCredential());

        $service = new MfaStepUpService($proofs, $policy, $credentials);

        self::assertSame('proof-token', $service->issue(17, 'session', 'passkey.revoke:42', 'recovery'));
    }

    /** Registrace nového klíče je druhá polovina obnovy — taky povolená. */
    public function testRecoveryCodeCanAuthorizePasskeyEnrollment(): void
    {
        $proofs = $this->createMock(MfaStepUpProofStore::class);
        $proofs->method('issue')->willReturn('proof-token');
        $service = new MfaStepUpService(
            $proofs,
            $this->createMock(MfaPolicyService::class),
            $this->createMock(PasskeyCredentialRepository::class),
        );

        self::assertSame(
            'proof-token',
            $service->issue(17, 'session', MfaStepUpService::OPERATION_PASSKEY_REGISTER, 'recovery'),
        );
    }

    /**
     * Papírovým kódem nelze vyrobit novou sadu papírových kódů — jinak by se
     * break-glass recykloval donekonečna a jednorázovost by nic neznamenala.
     */
    public function testRecoveryCodeCannotMintNewRecoveryCodes(): void
    {
        $proofs = $this->createMock(MfaStepUpProofStore::class);
        $proofs->expects(self::never())->method('issue');
        $service = new MfaStepUpService(
            $proofs,
            $this->createMock(MfaPolicyService::class),
            $this->createMock(PasskeyCredentialRepository::class),
        );

        $this->expectException(StepUpOperationException::class);
        $service->issue(17, 'session', MfaStepUpService::OPERATION_RECOVERY_CODES, 'recovery');
    }

    /**
     * Ani na vydání API tokenu nebo na podpisový certifikát pro daňová podání.
     * Kód prokazuje jen znalost tajemství z papíru, ne držení zařízení.
     */
    public function testRecoveryCodeCannotAuthorizeHighValueOperations(): void
    {
        foreach ([
            MfaStepUpService::OPERATION_API_TOKEN_CREATE,
            MfaStepUpService::OPERATION_EPO_CERTIFICATE,
            MfaStepUpService::OPERATION_TENANT_TRANSFER_GRANT_CREATE,
        ] as $operation) {
            $proofs = $this->createMock(MfaStepUpProofStore::class);
            $proofs->expects(self::never())->method('issue');
            $service = new MfaStepUpService(
                $proofs,
                $this->createMock(MfaPolicyService::class),
                $this->createMock(PasskeyCredentialRepository::class),
            );

            try {
                $service->issue(17, 'session', $operation, 'recovery');
                self::fail("Operace $operation nesmí jít potvrdit záložním kódem.");
            } catch (StepUpOperationException) {
                self::assertTrue(true);
            }
        }
    }

    /** Minimální aktivní passkey — step-up si ověřuje jen její existenci. */
    private static function storedCredential(): StoredPasskeyCredential
    {
        $record = CredentialRecord::create(
            random_bytes(32),
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            ['internal'],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromBinary(str_repeat("\0", 16)),
            random_bytes(77),
            random_bytes(32),
            0,
            null,
            true,
            false,
            true,
        );

        return new StoredPasskeyCredential(42, 17, 'Pixel 9', $record, null, null, null);
    }
}

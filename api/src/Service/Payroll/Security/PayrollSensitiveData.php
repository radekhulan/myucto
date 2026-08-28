<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Security;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;

final class PayrollSensitiveData
{
    public function __construct(
        private readonly SecretEncryption $encryption,
        private readonly Config $config,
    ) {}

    public function seal(
        string $plaintext,
        PayrollSensitiveField $field,
        int $supplierId,
        int $entityId,
    ): SealedPayrollValue {
        $plaintext = $this->validatePlaintext($plaintext, $field);
        $normalized = $this->normalize($plaintext, $field);
        $context = $this->context($field, $supplierId, $entityId);

        return new SealedPayrollValue(
            $this->encryption->encryptFor($plaintext, $context),
            $this->lookupHashNormalized($normalized, $field, $supplierId),
            $this->maskNormalized($normalized, $field),
        );
    }

    public function reveal(
        string $ciphertext,
        PayrollSensitiveField $field,
        int $supplierId,
        int $entityId,
    ): string {
        if (!str_starts_with($ciphertext, 'enc:v2:')) {
            throw new \RuntimeException('Mzdová hodnota není kontextově šifrovaná.');
        }

        return $this->encryption->decryptFor(
            $ciphertext,
            $this->context($field, $supplierId, $entityId),
        );
    }

    public function lookupHash(
        string $plaintext,
        PayrollSensitiveField $field,
        int $supplierId,
    ): string {
        return $this->lookupHashNormalized(
            $this->normalize($plaintext, $field),
            $field,
            $supplierId,
        );
    }

    public function keyedFingerprint(
        string $canonicalValue,
        string $purpose,
        int $supplierId,
    ): string {
        if ($canonicalValue === '' || strlen($canonicalValue) > 10_000_000) {
            throw new \InvalidArgumentException('Kanonická hodnota pro otisk není platná.');
        }
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('supplier_id musí být kladné.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,95}$/D', $purpose) !== 1) {
            throw new \InvalidArgumentException('Účel citlivého otisku není platný.');
        }

        return hash_hmac(
            'sha256',
            "payroll-fingerprint-v1\0{$purpose}\0{$supplierId}\0{$canonicalValue}",
            $this->hashKey(),
        );
    }

    public function mask(string $plaintext, PayrollSensitiveField $field): string
    {
        return $this->maskNormalized($this->normalize($plaintext, $field), $field);
    }

    private function normalize(string $plaintext, PayrollSensitiveField $field): string
    {
        $plaintext = $this->validatePlaintext($plaintext, $field);
        if ($field === PayrollSensitiveField::REGISTRATION_A1_PROFILE) {
            return $plaintext;
        }
        $value = match ($field) {
            PayrollSensitiveField::CONTACT_EMAIL =>
                mb_strtolower($plaintext, 'UTF-8'),
            default => mb_strtoupper($plaintext, 'UTF-8'),
        };
        $value = match ($field) {
            PayrollSensitiveField::PERSONAL_IDENTIFIER,
            PayrollSensitiveField::FOREIGN_TAX_IDENTIFIER =>
                preg_replace('/[\s\/.\-]+/u', '', $value),
            PayrollSensitiveField::PERSON_EXTERNAL_IDENTIFIER,
            PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER =>
                preg_replace('/\s+/u', '', $value),
            PayrollSensitiveField::BANK_ACCOUNT =>
                preg_replace('/\s+/u', '', $value),
            PayrollSensitiveField::CONTACT_EMAIL => $value,
            PayrollSensitiveField::CONTACT_PHONE =>
                preg_replace('/[\s()\/.\-]+/u', '', $value),
            PayrollSensitiveField::REGISTRATION_A1_PROFILE => $value,
        };
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException('Citlivou hodnotu nelze normalizovat.');
        }

        return $value;
    }

    private function validatePlaintext(
        string $plaintext,
        ?PayrollSensitiveField $field = null,
    ): string
    {
        $value = trim($plaintext);
        $maximumLength = $field === PayrollSensitiveField::REGISTRATION_A1_PROFILE
            ? 10_000_000
            : 191;
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maximumLength) {
            throw new \InvalidArgumentException('Citlivá hodnota je prázdná nebo příliš dlouhá.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new \InvalidArgumentException('Citlivá hodnota obsahuje řídicí znak.');
        }

        return $value;
    }

    private function lookupHashNormalized(
        string $normalized,
        PayrollSensitiveField $field,
        int $supplierId,
    ): string {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('supplier_id musí být kladné.');
        }

        return hash_hmac(
            'sha256',
            $field->value . "\0" . $supplierId . "\0" . $normalized,
            $this->hashKey(),
            true,
        );
    }

    private function context(PayrollSensitiveField $field, int $supplierId, int $entityId): string
    {
        if ($supplierId <= 0 || $entityId <= 0) {
            throw new \InvalidArgumentException('Tenant i entita musí mít kladné ID.');
        }

        return sprintf('payroll:%d:%d:%s', $supplierId, $entityId, $field->value);
    }

    private function hashKey(): string
    {
        $configuredValue = $this->config->get('app.payroll_hash_key', '');
        if (!is_string($configuredValue)) {
            throw new \RuntimeException('cfg.app.payroll_hash_key musí být řetězec.');
        }
        $configured = trim($configuredValue);
        if ($configured !== '') {
            $decoded = base64_decode($configured, true);
            if ($decoded === false || strlen($decoded) !== 32) {
                throw new \RuntimeException('cfg.app.payroll_hash_key musí být base64 klíč o délce 32B.');
            }

            return $decoded;
        }

        $pepper = $this->config->get('app.pepper', '');
        if (!is_string($pepper)) {
            throw new \RuntimeException('cfg.app.pepper musí být řetězec.');
        }
        if ($pepper === '') {
            throw new \RuntimeException('Pro mzdové keyed hashe chybí payroll_hash_key i app.pepper.');
        }

        return hash_hkdf('sha256', $pepper, 32, 'payroll-sensitive-hash-v1');
    }

    private function maskNormalized(string $normalized, PayrollSensitiveField $field): string
    {
        if ($field === PayrollSensitiveField::REGISTRATION_A1_PROFILE) {
            return '••••••••';
        }
        $length = mb_strlen($normalized, 'UTF-8');
        if ($field === PayrollSensitiveField::CONTACT_EMAIL) {
            [$local, $domain] = array_pad(explode('@', $normalized, 2), 2, '');
            return mb_substr($local, 0, 1, 'UTF-8')
                . str_repeat('•', max(3, mb_strlen($local, 'UTF-8') - 1))
                . ($domain === '' ? '' : '@' . $domain);
        }
        $maximumVisible = match ($field) {
            PayrollSensitiveField::BANK_ACCOUNT => min(6, $length),
            PayrollSensitiveField::CONTACT_PHONE => min(4, $length),
            default => min(4, $length),
        };
        $visible = min($maximumVisible, max(0, $length - 4));
        $suffix = $visible > 0
            ? mb_substr($normalized, -$visible, null, 'UTF-8')
            : '';

        return str_repeat('•', max(4, $length - $visible))
            . $suffix;
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Compatibility;

final readonly class CompatibilityIssue
{
    public function __construct(
        public string $code,
        public string $field,
        public string $message,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) !== 1) {
            throw new \InvalidArgumentException('Kód kompatibilitní chyby nemá bezpečný identifikátor.');
        }
        if (preg_match('/^[a-z][a-z0-9_.]{0,127}$/D', $field) !== 1) {
            throw new \InvalidArgumentException('Pole kompatibilitní chyby nemá bezpečný identifikátor.');
        }
        if ($message === '' || preg_match('/[\x00-\x1F\x7F]/', $message) === 1) {
            throw new \InvalidArgumentException('Zpráva kompatibilitní chyby není bezpečná.');
        }
    }

    /** @return array{code:string,field:string,message:string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'field' => $this->field,
            'message' => $this->message,
        ];
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ares;

use MyInvoice\Repository\ClientBankAccountRepository;

final class ClientBankAccountRegistrySynchronizer
{
    public function __construct(
        private readonly CrpDphClient $crpdph,
        private readonly ClientBankAccountRepository $accounts,
    ) {}

    /** @return array{found:bool,source:string,synced:int} */
    public function sync(int $clientId, int $supplierId, ?string $dic): array
    {
        $raw = strtoupper(trim((string) $dic));
        if ($raw === '' || (preg_match('/^[A-Z]{2}/', $raw) === 1 && !str_starts_with($raw, 'CZ'))) {
            return ['found' => false, 'source' => 'unsupported', 'synced' => 0];
        }
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (!preg_match('/^\d{8,10}$/', $digits)) {
            return ['found' => false, 'source' => 'invalid', 'synced' => 0];
        }
        // Registr zveřejňuje účty celé skupiny bez vazby na jednotlivého člena.
        // Nelze je bezpečně připsat jeho kartě ani použít jako účet pro vratku.
        if (CrpDphClient::isGroupRegistration($digits)) {
            return ['found' => false, 'source' => 'group_registration', 'synced' => 0];
        }
        $result = $this->crpdph->lookup($digits);
        $synced = $this->accounts->syncVatRegistry(
            $clientId,
            $supplierId,
            is_array($result['accounts'] ?? null) ? $result['accounts'] : [],
        );
        return [
            'found' => (bool) ($result['found'] ?? false),
            'source' => (string) ($result['source'] ?? 'error'),
            'synced' => $synced,
        ];
    }
}

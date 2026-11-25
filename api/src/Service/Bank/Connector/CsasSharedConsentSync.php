<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Repository\CsasOAuthRepository;
use MyInvoice\Service\Auth\SecretEncryption;

/**
 * Napojuje účty ČS firem instalace přes souhlas jiné firmy, který je pokrývá ({@see CsasSharedConsent}).
 *
 * Volá ho cron bankovních spojení po synchronizaci: nový účet ČS v kterékoli firmě se tak napojí
 * sám a účet, jehož nosná firma souhlas odpojila nebo udělila znovu, převezme jiný souhlas.
 * Souhlasy se zkoušejí v pořadí, v jakém byly připojené, takže nosnou se stává firma připojená
 * dřív. Banku volá jen tehdy, když některý účet na propojení čeká, a seznam účtů bere přednostně
 * z paměti konektoru, kam ho právě uložila synchronizace účtů téhož souhlasu.
 */
final class CsasSharedConsentSync
{
    public function __construct(private readonly CsasSharedConsent $shared, private readonly CsasOAuthRepository $oauth,
        private readonly CsasCredentialVault $vault, private readonly SecretEncryption $secrets,
        private readonly CsasBankConnector $connector, private readonly CsasSiblingAccountLinker $linker) {}

    /**
     * Propojí čekající účty. null = sdílení je vypnuté; [] = nic nečeká (banka se nevolá).
     * `$force` projde všechny souhlasy i bez čekajících účtů (výpis stavu z CLI).
     *
     * @return list<array{owner_supplier_id:int,supplier_id:int,currency_id:int,status:string,connection_id?:int}>|null
     */
    public function linkPending(bool $apply = true, bool $force = false): ?array
    {
        if ($this->shared->disabled()) return null;
        $results = [];
        $claimed = [];
        foreach ($this->carrierConsents() as [$currencyId, $primary]) {
            if (!$force && !$this->hasPending()) break;
            try {
                $access = $this->vault->access($this->vault->encode($primary));
                $remote = $this->connector->consentAccounts($access);
                $own = array_map(static fn (array $result): array => ['supplier_id' => $primary['supplier_id']] + $result,
                    $this->linker->link($currencyId, $access, $remote, null, $apply));
                $linked = [...$own, ...$this->linker->linkShared($access, $remote, null, $apply)];
            } catch (BankConnectorException $e) {
                // Kvóta banky platí pro celou aplikaci; další souhlasy zkusí příští běh.
                if ($e->errorCode === BankConnectorException::RATE_LIMITED) break;
                continue;
            }
            foreach ($linked as $result) {
                // Účet, který napojil dřívější souhlas, už další souhlasy nehlásí (i nanečisto).
                $key = $result['supplier_id'] . ':' . $result['currency_id'];
                if (isset($claimed[$key])) continue;
                if (in_array($result['status'], [CsasSiblingAccountLinker::LINKED, CsasSiblingAccountLinker::RELINKED,
                    CsasSiblingAccountLinker::ALREADY_LINKED], true)) $claimed[$key] = true;
                $results[] = ['owner_supplier_id' => $primary['supplier_id']] + $result;
            }
        }
        return $results;
    }

    /**
     * Vlastní (ne převzaté) souhlasy instalace, které sdílení povoluje, jeden přístup na souhlas,
     * v pořadí připojení.
     *
     * @return list<array{0:int,1:array<string,mixed>}> účet (měna) napojení a jeho přístup
     */
    private function carrierConsents(): array
    {
        $rows = $this->oauth->allConnectedCredentials();
        usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        $consents = [];
        foreach ($rows as $row) {
            if (!$this->shared->allows($row['supplier_id'])) continue;
            $data = $this->stored($row['supplier_id'], $row['id'], $row['token_ciphertext']);
            if ($data === null || CsasCredentialVault::isSharedCopy($data)) continue;
            foreach ($consents as [, $seen]) {
                if (CsasCredentialVault::sameConsent($seen, $data)) continue 2;
            }
            $consents[] = [$row['currency_id'], $data];
        }
        return $consents;
    }

    /** Čeká účet bez napojení (a neodpojený uživatelem), nebo převzaté napojení, jehož souhlas už neplatí? */
    private function hasPending(): bool
    {
        // Firma 0 neexistuje: účty ČS všech firem instalace.
        foreach ($this->oauth->otherSuppliersAccounts(0) as $local) {
            if ($local['token_ciphertext'] === null) {
                if (!$local['disconnected']) return true;
                continue;
            }
            if ($local['provider'] !== CsasOnboardingService::PROVIDER || $local['connection_id'] === null) continue;
            $current = $this->stored($local['supplier_id'], $local['connection_id'], $local['token_ciphertext']);
            if ($current !== null && CsasCredentialVault::isSharedCopy($current) && !$this->vault->sharedConsentAlive($current)) return true;
        }
        return false;
    }

    private function stored(int $supplierId, int $connectionId, #[\SensitiveParameter] string $ciphertext): ?array
    {
        try {
            $data = $this->vault->decode($this->secrets->decryptFor($ciphertext, CsasCredentialVault::context($supplierId, $connectionId)));
        } catch (\Throwable) {
            return null;
        }
        return $data['supplier_id'] === $supplierId && $data['connection_id'] === $connectionId ? $data : null;
    }
}

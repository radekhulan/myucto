<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

final class BankConnectorRegistry
{
    /** @var array<string,BankConnector> */
    private array $connectors = [];

    /** @param list<BankConnector> $connectors */
    public function __construct(array $connectors)
    {
        foreach ($connectors as $connector) {
            $this->connectors[$connector->provider()] = $connector;
        }
    }

    /** @return list<array<string,mixed>> */
    public function catalog(): array
    {
        return [
            [
                'code' => 'csas',
                'label' => 'Česká spořitelna Premium API',
                'implemented' => isset($this->connectors['csas']),
                'bank_codes' => ['0800'],
                'capabilities' => ['statement_import' => true, 'payment_order_submission' => false],
            ],
            [
                'code' => 'fio',
                'label' => 'Fio banka',
                'implemented' => isset($this->connectors['fio']),
                'bank_codes' => ['2010', '8330'],
                'capabilities' => ['statement_import' => true, 'payment_order_submission' => true],
            ],
            [
                'code' => 'kb_plus',
                'label' => 'KB Business',
                'implemented' => isset($this->connectors['kb_plus']),
                'bank_codes' => ['0100'],
                'capabilities' => ['statement_import' => true, 'payment_order_submission' => true],
            ],
            [
                'code' => 'raiffeisenbank',
                'label' => 'Raiffeisenbank Premium API',
                'implemented' => isset($this->connectors['raiffeisenbank']),
                'bank_codes' => ['5500'],
                'capabilities' => ['statement_import' => true, 'payment_order_submission' => true],
            ],
            [
                'code' => 'csob',
                'label' => 'ČSOB CEB Business Connector',
                'implemented' => isset($this->connectors['csob']),
                'bank_codes' => ['0300'],
                'capabilities' => ['statement_import' => true, 'payment_order_submission' => true],
            ],
            [
                'code' => 'creditas',
                'label' => 'Banka CREDITAS',
                'implemented' => isset($this->connectors['creditas']),
                'bank_codes' => ['2250'],
                'capabilities' => ['statement_import' => true, 'payment_order_submission' => true],
            ],
        ];
    }

    public function get(string $provider): BankConnector
    {
        $connector = $this->connectors[$provider] ?? null;
        if ($connector === null) {
            throw new BankConnectorOperationException('provider_not_implemented');
        }
        return $connector;
    }

    public function supportsBankCode(string $provider, string $bankCode): bool
    {
        foreach ($this->catalog() as $item) {
            if ($item['code'] === $provider) {
                return $item['implemented'] === true
                    && in_array($bankCode, $item['bank_codes'], true);
            }
        }
        return false;
    }
}

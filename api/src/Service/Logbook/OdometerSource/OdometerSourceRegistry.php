<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\OdometerSource;

/** Zdroje stavu tachometru registrované v DI (vzor {@see \MyInvoice\Service\Bank\Connector\BankConnectorRegistry}). */
final class OdometerSourceRegistry
{
    /** @var array<string,OdometerSourceProvider> */
    private array $providers = [];

    /** @param iterable<OdometerSourceProvider> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->code()] = $provider;
        }
    }

    /** @return list<array{code:string, label:string, fields:list<array<string,string>>, daily_call_limit:?int}> */
    public function catalog(): array
    {
        return array_values(array_map(static fn (OdometerSourceProvider $p): array => $p->definition()->toArray(), $this->providers));
    }

    public function has(string $code): bool
    {
        return isset($this->providers[$code]);
    }

    /** Platná hodnota `odometer_readings.source`: ruční zápis nebo kód registrovaného zdroje. */
    public function isReadingSource(string $source): bool
    {
        return $source === 'manual' || $this->has($source);
    }

    public function get(string $code): OdometerSourceProvider
    {
        return $this->providers[$code]
            ?? throw new OdometerSourceException(OdometerSourceException::UNKNOWN_PROVIDER, 'Neznámý zdroj stavu tachometru.');
    }
}

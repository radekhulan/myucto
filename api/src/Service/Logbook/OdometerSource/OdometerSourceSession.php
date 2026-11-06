<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\OdometerSource;

/**
 * Rozšifrované přístupy jednoho napojení (účtu u zdroje) po dobu jedné operace.
 *
 * Propadlý token zdroj obnoví přes {@see refresh()}: jádro přístupy pod zámkem napojení
 * načte znovu a obnovené hned šifrovaně uloží ({@see \MyInvoice\Service\OAuth\OAuthClientFlow::rotate()}).
 * Jinou změnu přístupů (kontejner, …) zdroj předá {@see update()}. Služby s rotujícím
 * refresh tokenem (BMW) by jinak po prvním obnovení napojení ztratily.
 *
 * Před každým voláním služby, které se počítá do jejího denního limitu, zavolá zdroj
 * {@see spendCalls()}. Jádro limit hlídá za celý účet, ne za auto.
 */
final class OdometerSourceSession
{
    /** @var \Closure(array<string,string|int>):void */
    private readonly \Closure $persist;
    /** @var \Closure(int):void */
    private readonly \Closure $spend;
    /** @var \Closure(callable, callable):array<string,string|int>|null */
    private readonly ?\Closure $rotate;

    /**
     * @param array<string,string|int>                 $credentials
     * @param callable(array<string,string|int>):void $persist
     * @param (callable(int):void)|null               $spend  vyhodí rate_limited, je-li denní limit vyčerpaný
     * @param (callable(callable, callable):array<string,string|int>)|null $rotate obnova pod zámkem napojení
     */
    public function __construct(#[\SensitiveParameter] private array $credentials, callable $persist, ?callable $spend = null,
        ?callable $rotate = null)
    {
        $this->persist = \Closure::fromCallable($persist);
        $this->spend = \Closure::fromCallable($spend ?? static function (int $calls): void {});
        $this->rotate = $rotate !== null ? \Closure::fromCallable($rotate) : null;
    }

    /**
     * Platné přístupy: když `$isFresh` neplatí, obnoví je přes `$refresh` a uloží dřív,
     * než je vrátí.
     *
     * @param callable(array<string,string|int>):bool                     $isFresh
     * @param callable(array<string,string|int>):array<string,string|int> $refresh
     * @return array<string,string|int>
     */
    public function refresh(callable $isFresh, callable $refresh): array
    {
        if ($isFresh($this->credentials)) return $this->credentials;
        if ($this->rotate !== null) {
            return $this->credentials = ($this->rotate)($isFresh, $refresh);
        }
        $this->update($refresh($this->credentials));
        return $this->credentials;
    }

    /** @return array<string,string|int> */
    public function credentials(): array
    {
        return $this->credentials;
    }

    /** @param array<string,string|int> $credentials */
    public function update(#[\SensitiveParameter] array $credentials): void
    {
        ($this->persist)($credentials);
        $this->credentials = $credentials;
    }

    public function spendCalls(int $calls = 1): void
    {
        ($this->spend)($calls);
    }
}

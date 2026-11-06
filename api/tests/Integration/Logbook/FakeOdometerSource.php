<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use MyInvoice\Service\Logbook\OdometerSource\ConnectContext;
use MyInvoice\Service\Logbook\OdometerSource\ConnectStep;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSample;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceDefinition;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceException;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceProvider;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceSession;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceVehicle;

/** Falešný zdroj pro test jádra: každé volání vehicles()/readOdometer() je jedno volání služby. */
final class FakeOdometerSource implements OdometerSourceProvider
{
    public const CODE = 'fake_source';

    public bool $approved = false;
    public bool $slowDown = false;
    public bool $denied = false;
    /** Přihlášení přesměrováním na službu (OAuth) místo device code. */
    public bool $redirect = false;
    public ?string $rotateTo = null;
    public int $reads = 0;
    public ?int $dailyLimit = null;
    /** @var list<ConnectContext> */
    public array $contexts = [];
    /** @var list<array<string,string|int>> přístupy, se kterými se četlo */
    public array $credentialsSeen = [];
    /** @var list<array<string,string|int>> */
    public array $pendingSeen = [];
    /** @var list<array<string,string>> vstup dalšího kroku přihlášení (včetně code a code_verifier od jádra) */
    public array $inputSeen = [];
    /** @var list<OdometerSourceVehicle> */
    public array $vehicles;
    /** @var array<string,OdometerSample> */
    public array $samples = [];
    /** @var array<string,string> */
    public array $failFor = [];
    /** Neočekávaná chyba při čtení (např. z HTTP klienta). */
    public ?\Throwable $crash = null;
    /** Zavolá se při každém dotazu na dokončení přihlášení (kontrola zámku). */
    public ?\Closure $onContinue = null;
    /** Před čtením obnovit propadlý token `synthetic-token-1` přes OdometerSourceSession::refresh(). */
    public bool $refreshOnRead = false;
    /** Přístupy vydané po přihlášení (výchozí synthetic-token-1). */
    public ?array $connectedCredentials = null;
    /** Kód chyby, kterou vyhodí příští dotaz na dokončení přihlášení (jednorázově). */
    public ?string $failNextContinue = null;
    public int $refreshes = 0;
    /** Zavolá se před obnovou tokenu (souběžný požadavek mezitím token obnovil). */
    public ?\Closure $beforeRefresh = null;

    public function __construct()
    {
        $this->vehicles = [new OdometerSourceVehicle('WBA00000000000001', 'WBA00000000000001', null, true)];
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function definition(): OdometerSourceDefinition
    {
        return new OdometerSourceDefinition(self::CODE, 'Fake', [['key' => 'key']], $this->dailyLimit);
    }

    public function startConnect(array $input, ConnectContext $context): ConnectStep
    {
        $this->contexts[] = $context;
        if ($this->redirect) {
            return ConnectStep::redirect('https://login.example.test/authorize?state=' . $context->state, 300, ['key' => $input['key']]);
        }
        return ConnectStep::deviceCode('ABCD1234', 'https://login.example.test/link', 5, 300,
            ['key' => $input['key'], 'device' => 'synthetic-device']);
    }

    public function continueConnect(array $pending, array $input): ConnectStep
    {
        $this->pendingSeen[] = $pending;
        $this->inputSeen[] = $input;
        if ($this->onContinue !== null) ($this->onContinue)();
        if ($this->failNextContinue !== null) {
            $code = $this->failNextContinue;
            $this->failNextContinue = null;
            throw new OdometerSourceException($code);
        }
        if ($this->denied) throw new OdometerSourceException(OdometerSourceException::ACCESS_DENIED);
        if ($this->slowDown) {
            $this->slowDown = false;
            return ConnectStep::waiting(10, ['device' => 'slow'] + $pending);
        }
        return $this->approved ? ConnectStep::connected($this->connectedCredentials ?? ['token' => 'synthetic-token-1']) : ConnectStep::waiting(5);
    }

    public function vehicles(OdometerSourceSession $session): array
    {
        $session->spendCalls();
        return $this->vehicles;
    }

    public function readOdometer(OdometerSourceSession $session, string $vehicleRef): ?OdometerSample
    {
        $session->spendCalls();
        $this->reads++;
        if ($this->refreshOnRead) {
            if ($this->beforeRefresh !== null) ($this->beforeRefresh)();
            $session->refresh(static fn (array $c): bool => ($c['token'] ?? '') !== 'synthetic-token-1', function (array $c): array {
                $this->refreshes++;
                return ['token' => 'synthetic-token-refreshed'];
            });
        }
        $this->credentialsSeen[] = $session->credentials();
        if ($this->crash !== null) throw $this->crash;
        if (isset($this->failFor[$vehicleRef])) throw new OdometerSourceException($this->failFor[$vehicleRef]);
        if ($this->rotateTo !== null) {
            $session->update(['token' => $this->rotateTo]);
            $this->rotateTo = null;
        }
        return $this->samples[$vehicleRef] ?? null;
    }
}

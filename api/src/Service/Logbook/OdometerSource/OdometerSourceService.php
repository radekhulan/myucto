<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\OdometerSource;

use MyInvoice\Repository\CarRepository;
use MyInvoice\Repository\OdometerReadingRepository;
use MyInvoice\Repository\OdometerSourceAccountRepository;
use MyInvoice\Repository\OdometerSourceCarRepository;
use MyInvoice\Service\OAuth\OAuthCallback;
use MyInvoice\Service\OAuth\OAuthClientFlow;
use MyInvoice\Service\OAuth\OAuthException;
use MyInvoice\Service\OAuth\OAuthSession;

/**
 * Automatický stav tachometru: napojení na účet u zdroje, přiřazení aut a denní načtení
 * stavu do odometer_readings (zdroj = kód provideru). Neevidované km mezi stavy pak Kniha
 * jízd nabídne k doplnění ({@see \MyInvoice\Service\Logbook\OdometerGapCalculator}).
 *
 * Napojení je účet u zdroje, ne auto: jedno přihlášení obvykle pokryje víc aut a jeden
 * řetěz rotujících refresh tokenů nesmí mít dvě kopie. Auta účtu se přiřazují k autům
 * knihy jízd podle VIN.
 *
 * Jádro je pro všechny zdroje stejné; konkrétní služba je jen {@see OdometerSourceProvider}.
 * Připojení: {@see startConnect()} vrátí krok pro UI ({@see ConnectStep}), {@see continueConnect()}
 * se ptá, jestli uživatel přihlášení u služby dokončil. State, PKCE a rozpracované přihlášení
 * drží společná vrstva OAuth klienta ({@see OAuthClientFlow}, doména {@see OAUTH_DOMAIN});
 * návrat přesměrováním přijme {@see receiveCallback()}. Vše, co používá přístupy účtu,
 * běží pod zámkem účtu.
 */
final class OdometerSourceService
{
    public const OAUTH_DOMAIN = 'odometer';
    private const LOCK_SCOPE = 'odometer-source';
    private const REDIRECT_POLL_INTERVAL = 5;

    public function __construct(
        private readonly OdometerSourceRegistry $providers,
        private readonly OdometerSourceVault $vault,
        private readonly OAuthClientFlow $flow,
        private readonly OdometerSourceAccountRepository $accounts,
        private readonly OdometerSourceCarRepository $assignments,
        private readonly CarRepository $cars,
        private readonly OdometerReadingRepository $readings,
    ) {}

    /** @return list<array<string,mixed>> */
    public function catalog(): array
    {
        return $this->providers->catalog();
    }

    /**
     * Stav auta pro dialog: přiřazení, jeho účet a připojené účty firmy k výběru.
     *
     * @return array{car:?array<string,mixed>, account:?array<string,mixed>, accounts:list<array<string,mixed>>}
     */
    public function status(int $supplierId, int $carId): array
    {
        $accounts = $this->accounts->listConnected($supplierId);
        $cars = $this->assignments->listForAccounts($supplierId, array_column($accounts, 'id'));
        $accounts = array_map(fn (array $a): array => $this->publicAccount($a, $cars[$a['id']] ?? []), $accounts);
        $car = $this->assignments->findForCar($supplierId, $carId);
        $account = null;
        foreach ($accounts as $a) {
            if ($car !== null && $a['id'] === $car['account_id']) $account = $a;
        }
        return ['car' => $account !== null ? $car : null, 'account' => $account, 'accounts' => $accounts];
    }

    /**
     * Zahájí přihlášení k novému účtu, nebo znovu k existujícímu (`$accountId`, propadlý přístup).
     *
     * @param array<string,mixed> $input hodnoty formuláře zdroje
     * @return array<string,mixed> krok pro UI: {state, …display}
     */
    public function startConnect(int $supplierId, int $carId, string $providerCode, #[\SensitiveParameter] array $input,
        ?int $userId, ?int $accountId = null): array
    {
        $this->requireCar($supplierId, $carId);
        if ($accountId !== null) {
            $providerCode = $this->requireAccount($supplierId, $accountId)['provider'];
        }
        $provider = $this->providers->get($providerCode);
        $this->vault->assertKeyAvailable();

        $authorization = $this->flow->authorize();
        $step = $provider->startConnect(self::stringInput($input),
            new ConnectContext($authorization->state, $this->redirectUri(), $authorization->codeVerifier));
        $expiresAt = date('Y-m-d H:i:s', time() + ($step->expiresIn ?? 60));
        $this->accounts->deleteStalePending($supplierId, $carId);
        if ($accountId !== null) {
            $this->accounts->beginReauth($supplierId, $accountId, $carId, $authorization->stateHash(), $expiresAt);
        } else {
            $accountId = $this->accounts->createPending($supplierId, $provider->code(), $carId, $authorization->stateHash(), $expiresAt, $userId);
        }

        if ($step->type === ConnectStep::CONNECTED && $step->credentials !== null) {
            // Zdroj bez interaktivního přihlášení (např. API klíč): rovnou aktivovat.
            $credentials = $step->credentials;
            return $this->withLock($accountId, fn (): array =>
                $this->finishConnect($supplierId, $carId, $accountId, $provider, $credentials, $userId));
        }
        if ($step->pending === null || $step->expiresIn === null || !in_array($step->type, [ConnectStep::DEVICE_CODE, ConnectStep::REDIRECT], true)) {
            $this->accounts->clearPending($supplierId, $accountId);
            throw new OdometerSourceException(OdometerSourceException::INVALID_RESPONSE, 'Zdroj nevrátil postup připojení.');
        }
        if ($userId === null) {
            $this->accounts->clearPending($supplierId, $accountId);
            throw new OdometerSourceException(OdometerSourceException::INVALID_INPUT, 'Přihlášení ke službě zahajuje přihlášený uživatel.');
        }
        try {
            $this->flow->store($authorization, $supplierId, $userId, self::OAUTH_DOMAIN, $provider->code(), $accountId,
                $step->type === ConnectStep::REDIRECT ? OAuthClientFlow::FLOW_REDIRECT : OAuthClientFlow::FLOW_DEVICE_CODE,
                ['pending' => $step->pending], $step->expiresIn);
        } catch (OAuthException $e) {
            $this->accounts->clearPending($supplierId, $accountId);
            throw self::fromOAuth($e);
        }
        return ['state' => $step->type] + $step->display;
    }

    /**
     * Návrat ze služby na společný callback: kód (nebo odmítnutí) uloží k rozpracovanému
     * přihlášení, vymění ho až {@see continueConnect()} pod zámkem účtu. Callback přijde
     * v jiné záložce; dialog, který přihlášení zahájil, se mezitím dál ptá.
     *
     * @return int|null auto, ze kterého se připojení zahájilo
     */
    public function receiveCallback(OAuthCallback $callback): ?int
    {
        $supplierId = $callback->supplierId();
        $accountId = (int) $callback->session['subject_id'];
        return $this->withLock($accountId, function () use ($callback, $supplierId, $accountId): ?int {
            $session = $this->flow->claim($callback->state, $supplierId, $callback->userId(), self::OAUTH_DOMAIN,
                (string) $callback->session['provider'])
                ?? throw new OdometerSourceException(OdometerSourceException::EXPIRED, 'Přihlášení vypršelo, začněte znovu.');
            $row = $this->accounts->findWithSecrets($supplierId, $accountId);
            if ($row === null || $row['pending_state_hash'] !== $session->stateHash || $session->flow !== OAuthClientFlow::FLOW_REDIRECT) {
                $this->flow->finish($session, false, 'no_pending_connect');
                throw new OdometerSourceException('no_pending_connect', 'Připojení nebylo zahájeno.');
            }
            $code = $callback->code;
            $valid = $callback->error === null && $code !== '' && strlen($code) <= 4096 && preg_match('/[\x00-\x20\x7f]/', $code) !== 1;
            $this->flow->update($session, ($valid ? ['code' => $code] : ['error' => $callback->error ?? 'invalid_request']) + $session->secret);
            return $row['pending_car_id'];
        });
    }

    /**
     * Jeden dotaz, jestli uživatel přihlášení u služby dokončil.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed> {state:'waiting', interval} | {state:'select_vehicle', account_id, vehicles} | {state:'connected', …status}
     */
    public function continueConnect(int $supplierId, int $carId, #[\SensitiveParameter] array $input = [], ?int $userId = null): array
    {
        $accountId = $this->accounts->findPendingForCar($supplierId, $carId)
            ?? throw new OdometerSourceException('no_pending_connect', 'Připojení nebylo zahájeno.');
        // Pod zámkem účtu: dva souběžné dotazy by jinak oba vyměnily tentýž jednorázový kód za tokeny.
        return $this->withLock($accountId, fn (): array => $this->continueConnectLocked($supplierId, $carId, $accountId, $input, $userId));
    }

    /** @param array<string,mixed> $input */
    private function continueConnectLocked(int $supplierId, int $carId, int $accountId, #[\SensitiveParameter] array $input, ?int $userId): array
    {
        // Rozpracované přihlášení znovu pod zámkem — souběžný dotaz ho mohl mezitím dokončit.
        $row = $this->accounts->findPendingForCar($supplierId, $carId) === $accountId
            ? $this->accounts->findWithSecrets($supplierId, $accountId) : null;
        if ($row === null || $row['pending_state_hash'] === null) {
            throw new OdometerSourceException('no_pending_connect', 'Připojení nebylo zahájeno.');
        }
        if ($row['pending_expires_at'] !== null && strtotime($row['pending_expires_at']) < time()) {
            $this->accounts->clearPending($supplierId, $row['id']);
            throw new OdometerSourceException(OdometerSourceException::EXPIRED, 'Přihlášení vypršelo, začněte znovu.');
        }
        $provider = $this->providers->get($row['provider']);
        try {
            $session = $this->flow->open($row['pending_state_hash'], $supplierId, (int) $userId, self::OAUTH_DOMAIN, $provider->code(), $row['id']);
        } catch (OAuthException) {
            // Nečitelné rozpracované přihlášení už nikdo nedokončí.
            $this->accounts->clearPending($supplierId, $row['id']);
            throw new OdometerSourceException('no_pending_connect', 'Připojení nebylo zahájeno.');
        }
        // Přihlášení jiného uživatele (nebo už ukončené) se nedotkne: dokončit ho smí jen ten, kdo ho zahájil.
        if ($session === null) throw new OdometerSourceException('no_pending_connect', 'Připojení nebylo zahájeno.');
        $secret = $session->secret;
        // Přístupy, které služba už vydala, ale jejich uložení minule selhalo: znovu se o ně nežádá.
        if (is_array($secret['credentials'] ?? null)) {
            return $this->finishConnect($supplierId, $carId, $row['id'], $provider, $secret['credentials'], $userId, $session);
        }
        if (isset($secret['error'])) {
            $this->abandon($supplierId, $row['id'], $session, OdometerSourceException::ACCESS_DENIED);
            throw new OdometerSourceException(OdometerSourceException::ACCESS_DENIED, 'Přihlášení u služby nebylo povoleno.');
        }
        $code = is_string($secret['code'] ?? null) ? $secret['code'] : null;
        if ($session->flow === OAuthClientFlow::FLOW_REDIRECT && $code === null) {
            if (isset($secret['code_used'])) {
                // Kód je jednorázový a minulá výměna se nepovedla: přihlášení je třeba zopakovat.
                $this->abandon($supplierId, $row['id'], $session, OdometerSourceException::EXPIRED);
                throw new OdometerSourceException(OdometerSourceException::EXPIRED, 'Přihlášení vypršelo, začněte znovu.');
            }
            return ['state' => ConnectStep::WAITING, 'interval' => self::REDIRECT_POLL_INTERVAL];
        }
        if ($code !== null) {
            // Kód z tajemství zmizí před výměnou, aby se po přechodné chybě neposílal znovu.
            unset($secret['code']);
            $session = $this->flow->update($session, ['code_used' => 1] + $secret);
            $secret = $session->secret;
        }
        // Kód a PKCE verifier dodává jen jádro, vstup z dialogu je nepřebije.
        $input = array_filter(['code' => $code, 'code_verifier' => $session->codeVerifier], static fn (?string $v): bool => $v !== null)
            + array_diff_key(self::stringInput($input), ['code' => true, 'code_verifier' => true]);
        try {
            $step = $provider->continueConnect(is_array($secret['pending'] ?? null) ? $secret['pending'] : [], $input);
        } catch (OdometerSourceException $e) {
            if (in_array($e->errorCode, [OdometerSourceException::ACCESS_DENIED, OdometerSourceException::EXPIRED,
                OdometerSourceException::INVALID_INPUT], true)) {
                $this->abandon($supplierId, $row['id'], $session, $e->errorCode);
            }
            throw $e;
        }
        if ($step->type === ConnectStep::WAITING) {
            if ($step->pending !== null) {
                $this->flow->update($session, ['pending' => $step->pending] + $secret);
            }
            return ['state' => ConnectStep::WAITING, 'interval' => (int) ($step->display['interval'] ?? 5)];
        }
        if ($step->type !== ConnectStep::CONNECTED || $step->credentials === null) {
            throw new OdometerSourceException(OdometerSourceException::INVALID_RESPONSE, 'Zdroj vrátil neočekávaný krok připojení.');
        }
        // Vydané přístupy se nejdřív uloží k rozpracovanému přihlášení: služba je už nevydá
        // znovu (device code i kód jsou spotřebované), takže nesmí zmizet, kdyby uložení k účtu selhalo.
        $session = $this->flow->update($session, ['credentials' => $step->credentials] + $secret);
        return $this->finishConnect($supplierId, $carId, $row['id'], $provider, $step->credentials, $userId, $session);
    }

    private function abandon(int $supplierId, int $accountId, OAuthSession $session, string $errorCode): void
    {
        $this->flow->finish($session, false, $errorCode);
        $this->accounts->clearPending($supplierId, $accountId);
    }

    /** Společný callback pro zdroje s přesměrováním; bez platného app.url ho nabídnout nejde. */
    private function redirectUri(): ?string
    {
        try {
            return $this->flow->callbackUrl();
        } catch (OAuthException) {
            return null;
        }
    }

    private static function fromOAuth(OAuthException $e): OdometerSourceException
    {
        return new OdometerSourceException($e->errorCode, $e->getMessage());
    }

    /** Přiřadí auto k už připojenému účtu firmy (stejné přihlášení, žádné nové tokeny). */
    public function attach(int $supplierId, int $carId, int $accountId, ?int $userId): array
    {
        $this->requireCar($supplierId, $carId);
        return $this->withLock($accountId, fn (): array =>
            $this->assignFromAccount($supplierId, $carId, $this->activeAccount($supplierId, $accountId), $userId));
    }

    /** Výběr auta účtu, když spárování podle VIN není jednoznačné. */
    public function selectVehicle(int $supplierId, int $carId, int $accountId, string $vehicleRef, ?int $userId): array
    {
        $vehicleRef = trim($vehicleRef);
        $this->requireCar($supplierId, $carId);
        return $this->withLock($accountId, function () use ($supplierId, $carId, $accountId, $vehicleRef, $userId): array {
            $row = $this->activeAccount($supplierId, $accountId);
            $session = $this->session($row);
            $refs = array_map(static fn (OdometerSourceVehicle $v): string => $v->ref,
                $this->providers->get($row['provider'])->vehicles($session));
            if ($vehicleRef === '' || !in_array($vehicleRef, $refs, true)) {
                throw new OdometerSourceException(OdometerSourceException::VEHICLE_NOT_PERMITTED, 'Toto auto k účtu nepatří.');
            }
            $this->assign($supplierId, $carId, $row['id'], $vehicleRef, $userId);
            $this->firstSync($row, $session, $carId);
            return ['state' => ConnectStep::CONNECTED] + $this->status($supplierId, $carId);
        });
    }

    /**
     * Načte aktuální stav tachometru auta a zapíše ho jako stav dne, kdy ho auto odeslalo.
     *
     * @return array{odometer:int, reading_date:string, reading_time:string}
     */
    public function sync(int $supplierId, int $carId): array
    {
        $car = $this->assignments->findForCar($supplierId, $carId)
            ?? throw new OdometerSourceException('not_connected', 'Auto není připojené ke zdroji stavu tachometru.');
        return $this->withLock($car['account_id'], function () use ($supplierId, $carId): array {
            // Přiřazení znovu pod zámkem — mezitím mohlo být auto odpojené.
            $car = $this->assignments->findForCar($supplierId, $carId)
                ?? throw new OdometerSourceException('not_connected', 'Auto není připojené ke zdroji stavu tachometru.');
            $row = $this->activeAccount($supplierId, $car['account_id']);
            try {
                $reading = $this->syncCar($row, $this->session($row), $car);
            } catch (OdometerSourceException $e) {
                if (!$e->concernsVehicleOnly()) $this->accounts->recordSyncError($supplierId, $row['id'], $e->errorCode);
                throw $e;
            }
            $this->accounts->recordSyncSuccess($supplierId, $row['id']);
            return $reading;
        });
    }

    /**
     * Denní běh přes všechny účty. Chyba účtu (přístup, limit) se zapíše k účtu a jeho další
     * auta se nezkoušejí; chyba auta neovlivní ostatní auta. Přechodný stav auta (zatím bez
     * dat, nenavazující stav) je varování u auta, ne chyba
     * ({@see OdometerSourceException::isVehicleWarning()}).
     *
     * @return array{processed:int, succeeded:int, warnings:int, errors:int, details:list<array{account_id:int, car_id:?int, error_code:string, error_class?:string}>}
     */
    public function syncAll(): array
    {
        $summary = ['processed' => 0, 'succeeded' => 0, 'warnings' => 0, 'errors' => 0, 'details' => []];
        foreach ($this->accounts->listSyncable() as $account) {
            try {
                $this->withLock($account['id'], function () use ($account, &$summary): void {
                    $row = $this->activeAccount($account['supplier_id'], $account['id']);
                    $session = $this->session($row);
                    foreach ($this->assignments->listForAccount($account['supplier_id'], $account['id']) as $car) {
                        $summary['processed']++;
                        try {
                            $this->syncCar($row, $session, $car);
                            $summary['succeeded']++;
                        } catch (OdometerSourceException $e) {
                            if (!$e->concernsVehicleOnly()) throw $e;
                            $summary[$e->isVehicleWarning() ? 'warnings' : 'errors']++;
                            $summary['details'][] = ['account_id' => $account['id'], 'car_id' => $car['car_id'], 'error_code' => $e->errorCode];
                        }
                    }
                    $this->accounts->recordSyncSuccess($account['supplier_id'], $account['id']);
                });
            } catch (\Throwable $e) {
                $code = $e instanceof OdometerSourceException ? $e->errorCode : 'internal_error';
                if ($code !== 'connection_busy') $this->accounts->recordSyncError($account['supplier_id'], $account['id'], $code);
                $summary['errors']++;
                $detail = ['account_id' => $account['id'], 'car_id' => null, 'error_code' => $code];
                // Neočekávaná chyba: jen třída, zpráva může nést odpověď služby.
                if ($code === 'internal_error') $detail['error_class'] = $e::class;
                $summary['details'][] = $detail;
            }
        }
        return $summary;
    }

    /** Odpojí auto; poslední auto účtu odpojí i účet (smaže přístupy). Stavy tachometru zůstávají. */
    public function disconnect(int $supplierId, int $carId): bool
    {
        $car = $this->assignments->findForCar($supplierId, $carId);
        if ($car === null) return false;
        return $this->withLock($car['account_id'], function () use ($supplierId, $carId, $car): bool {
            if (!$this->assignments->unassign($supplierId, $carId)) return false;
            if ($this->assignments->listForAccount($supplierId, $car['account_id']) === []) {
                $this->accounts->disconnect($supplierId, $car['account_id']);
            }
            return true;
        });
    }

    /**
     * Aktivuje účet a přiřadí auto; volá se pod zámkem účtu.
     *
     * Aktivace a přiřazení záměrně neběží v jedné transakci: přiřazení se ptá služby na auta
     * účtu a hned načte první stav (HTTP volání, která v transakci nemají co dělat), a nově
     * vydané přístupy se musí uložit hned, protože služba při výměně kódu nebo rotaci tokenu
     * ty předchozí zneplatní a návrat zpět by účet odřízl. Účet bez aut je platný stav
     * (dialog ho nabídne k přiřazení), selhání přiřazení proto aktivaci neruší.
     *
     * Rozpracované přihlášení (`$session`) se uzavře až po uložení přístupů k účtu.
     *
     * @param array<string,string|int> $credentials
     */
    private function finishConnect(int $supplierId, int $carId, int $accountId, OdometerSourceProvider $provider,
        #[\SensitiveParameter] array $credentials, ?int $userId, ?OAuthSession $session = null): array
    {
        $this->accounts->activate($supplierId, $accountId, $this->vault->sealCredentials($supplierId, $accountId, $provider->code(), $credentials));
        if ($session !== null) $this->flow->finish($session, true);
        try {
            return $this->assignFromAccount($supplierId, $carId, $this->activeAccount($supplierId, $accountId), $userId);
        } catch (OdometerSourceException $e) {
            // Druhé přihlášení k už připojenému účtu: auta patří prvnímu napojení, nové bez aut zahodit.
            if ($e->errorCode === 'vehicle_taken' && $this->assignments->listForAccount($supplierId, $accountId) === []) {
                $this->accounts->disconnect($supplierId, $accountId);
            }
            throw $e;
        }
    }

    /**
     * Přiřadí auto podle aut účtu; ostatní auta firmy se shodným VIN přiřadí rovnou.
     * Volá se pod zámkem účtu.
     */
    private function assignFromAccount(int $supplierId, int $carId, array $row, ?int $userId): array
    {
        $session = $this->session($row);
        $vehicles = $this->providers->get($row['provider'])->vehicles($session);
        $current = $this->assignments->findForCar($supplierId, $carId);
        $taken = $this->assignments->takenVehicles($supplierId, $row['provider']);
        $free = array_values(array_filter($vehicles, static fn (OdometerSourceVehicle $v): bool =>
            !isset($taken[$v->ref]) || $taken[$v->ref] === $carId));

        $refs = array_map(static fn (OdometerSourceVehicle $v): string => $v->ref, $vehicles);
        $vehicleRef = $current !== null && $current['account_id'] === $row['id'] && in_array($current['vehicle_ref'], $refs, true)
            ? $current['vehicle_ref']
            : $this->pickVehicle($supplierId, $carId, $free);
        if ($vehicleRef === null && $free === []) {
            throw new OdometerSourceException('vehicle_taken', 'Auta tohoto účtu už jsou přiřazená k jiným autům.');
        }
        $this->assignOtherCarsByVin($supplierId, $carId, $row['id'], $free, $vehicleRef, $userId);
        if ($vehicleRef === null) {
            return ['state' => 'select_vehicle', 'account_id' => $row['id'],
                'vehicles' => array_map(static fn (OdometerSourceVehicle $v): array => $v->toArray(), $free)];
        }
        $this->assign($supplierId, $carId, $row['id'], $vehicleRef, $userId);
        $this->firstSync($row, $session, $carId);
        return ['state' => ConnectStep::CONNECTED] + $this->status($supplierId, $carId);
    }

    /** První načtení hned po přiřazení: chyba se uloží ke stavu, přiřazení ale platí. */
    private function firstSync(array $row, OdometerSourceSession $session, int $carId): void
    {
        try {
            $this->syncCar($row, $session, (array) $this->assignments->findForCar($row['supplier_id'], $carId));
            $this->accounts->recordSyncSuccess($row['supplier_id'], $row['id']);
        } catch (OdometerSourceException $e) {
            if (!$e->concernsVehicleOnly()) $this->accounts->recordSyncError($row['supplier_id'], $row['id'], $e->errorCode);
        }
    }

    /** @param list<OdometerSourceVehicle> $free */
    private function assignOtherCarsByVin(int $supplierId, int $carId, int $accountId, array $free, ?string $usedRef, ?int $userId): void
    {
        $byVin = [];
        foreach ($free as $vehicle) {
            if ($vehicle->vin !== null && $vehicle->ref !== $usedRef) $byVin[self::normalizeVin($vehicle->vin)] = $vehicle->ref;
        }
        if ($byVin === []) return;
        $assigned = array_flip($this->assignments->assignedCarIds($supplierId));
        foreach ($this->cars->listForTenant($supplierId) as $car) {
            $vin = self::normalizeVin((string) ($car['vin'] ?? ''));
            if ((int) $car['id'] === $carId || isset($assigned[(int) $car['id']]) || $vin === '' || !isset($byVin[$vin])) continue;
            $this->assignments->assign($supplierId, $accountId, (int) $car['id'], $byVin[$vin], $userId);
        }
    }

    private function assign(int $supplierId, int $carId, int $accountId, string $vehicleRef, ?int $userId): void
    {
        $previous = $this->assignments->findForCar($supplierId, $carId);
        if (!$this->assignments->assign($supplierId, $accountId, $carId, $vehicleRef, $userId)) {
            throw new OdometerSourceException('vehicle_taken', 'Toto auto účtu už je přiřazené k jinému autu.');
        }
        // Auto přešlo na jiný účet: původní účet bez aut se odpojí.
        if ($previous !== null && $previous['account_id'] !== $accountId
            && $this->assignments->listForAccount($supplierId, $previous['account_id']) === []) {
            $this->accounts->disconnect($supplierId, $previous['account_id']);
        }
    }

    /** Načte a zapíše stav jednoho auta; volá se pod zámkem účtu. */
    private function syncCar(array $account, OdometerSourceSession $session, array $car): array
    {
        try {
            $sample = $this->providers->get($account['provider'])->readOdometer($session, $car['vehicle_ref']);
            if ($sample === null) throw new OdometerSourceException(OdometerSourceException::NO_DATA, 'Auto zatím stav tachometru neodeslalo.');
            return $this->store($account, $car, $sample);
        } catch (OdometerSourceException $e) {
            if ($e->concernsVehicleOnly()) {
                $this->assignments->recordProblem($account['supplier_id'], $car['id'],
                    $e->isVehicleWarning() ? 'warning' : 'error', $e->errorCode);
            }
            throw $e;
        }
    }

    /** Převod na km a místní datum, kontrola návaznosti a zápis stavu dne. */
    private function store(array $account, array $car, OdometerSample $sample): array
    {
        $supplierId = $account['supplier_id'];
        $km = $sample->km();
        $at = $sample->at->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $date = $at->format('Y-m-d');
        $bounds = $this->readings->bounds($supplierId, $car['car_id'], $date);
        if (($bounds['before'] !== null && $km < $bounds['before']) || ($bounds['after'] !== null && $km > $bounds['after'])) {
            throw new OdometerSourceException(OdometerSourceException::REGRESSION,'Stav tachometru z auta nenavazuje na zapsané stavy a jízdy.');
        }
        $this->readings->upsert($supplierId, ['car_id' => $car['car_id'], 'reading_date' => $date,
            'reading_time' => $at->format('H:i'), 'odometer' => $km, 'source' => $account['provider']], null);
        $this->assignments->recordSuccess($supplierId, $car['id'], $km, $at->format('Y-m-d H:i:s'));
        return ['odometer' => $km, 'reading_date' => $date, 'reading_time' => $at->format('H:i')];
    }

    /**
     * Session nad uloženými přístupy účtu: změněné přístupy se hned uloží, obnova tokenu
     * běží přes {@see OAuthClientFlow::rotate()} pod zámkem účtu, volání služby se
     * započítají do denního limitu účtu.
     */
    private function session(array $row): OdometerSourceSession
    {
        $supplierId = $row['supplier_id'];
        $accountId = $row['id'];
        $provider = $row['provider'];
        $limit = $this->providers->get($provider)->definition()->dailyCallLimit;
        $persist = function (array $credentials) use ($supplierId, $accountId, $provider): bool {
            return $this->accounts->rotateCredentials($supplierId, $accountId,
                $this->vault->sealCredentials($supplierId, $accountId, $provider, $credentials));
        };
        return new OdometerSourceSession(
            $this->vault->openCredentials($supplierId, $accountId, $provider, (string) $row['credentials_ciphertext']),
            function (array $credentials) use ($persist): void {
                if (!$persist($credentials)) {
                    throw new OdometerSourceException('credential_rotation_failed', 'Obnovený přístup ke zdroji nelze uložit.');
                }
            },
            function (int $calls) use ($supplierId, $accountId, $limit): void {
                if (!$this->accounts->spendCalls($supplierId, $accountId, max(1, $calls), $limit, gmdate('Y-m-d'))) {
                    throw new OdometerSourceException(OdometerSourceException::RATE_LIMITED, 'Denní limit dotazů služby je vyčerpaný.');
                }
            },
            function (callable $isFresh, callable $refresh) use ($supplierId, $accountId, $provider, $persist): array {
                try {
                    return $this->flow->rotate(self::LOCK_SCOPE, $accountId,
                        fn (): array => $this->vault->openCredentials($supplierId, $accountId, $provider,
                            (string) ($this->accounts->findWithSecrets($supplierId, $accountId)['credentials_ciphertext'] ?? '')),
                        $isFresh, $refresh, $persist, self::busy(...));
                } catch (OAuthException $e) {
                    throw self::fromOAuth($e);
                }
            },
        );
    }

    /**
     * Auto ke spárování: shoda VIN s kartou auta; bez VIN v kartě jediné primární auto,
     * případně jediné auto účtu.
     *
     * @param list<OdometerSourceVehicle> $vehicles
     */
    private function pickVehicle(int $supplierId, int $carId, array $vehicles): ?string
    {
        $carVin = self::normalizeVin((string) ($this->requireCar($supplierId, $carId)['vin'] ?? ''));
        foreach ($vehicles as $vehicle) {
            if ($carVin !== '' && $vehicle->vin !== null && self::normalizeVin($vehicle->vin) === $carVin) return $vehicle->ref;
        }
        if ($carVin !== '') return null;
        $primary = array_values(array_filter($vehicles, static fn (OdometerSourceVehicle $v): bool => $v->primary));
        if (count($primary) === 1) return $primary[0]->ref;
        return count($vehicles) === 1 ? $vehicles[0]->ref : null;
    }

    private function publicAccount(array $account, array $cars): array
    {
        $definition = $this->providers->has($account['provider']) ? $this->providers->get($account['provider'])->definition() : null;
        return [
            'id'                   => $account['id'],
            'provider'             => $account['provider'],
            'status'               => $account['status'],
            'last_sync_at'         => $account['last_sync_at'],
            'last_sync_error_code' => $account['last_sync_error_code'],
            'calls_today'          => $account['calls_today'],
            'daily_call_limit'     => $definition?->dailyCallLimit,
            'cars'                 => array_map(static fn (array $c): array => ['car_id' => $c['car_id'],
                'registration' => $c['registration'], 'name' => $c['name']], $cars),
        ];
    }

    private function activeAccount(int $supplierId, int $accountId): array
    {
        $row = $this->accounts->findWithSecrets($supplierId, $accountId);
        if ($row === null || $row['credentials_ciphertext'] === null || !in_array($row['status'], ['active', 'error'], true)) {
            throw new OdometerSourceException('not_connected', 'Účet není připojený ke zdroji stavu tachometru.');
        }
        return $row;
    }

    private function requireAccount(int $supplierId, int $accountId): array
    {
        return $this->accounts->find($supplierId, $accountId)
            ?? throw new OdometerSourceException('not_connected', 'Účet není připojený ke zdroji stavu tachometru.');
    }

    private function requireCar(int $supplierId, int $carId): array
    {
        return $this->cars->find($carId, $supplierId) ?? throw new OdometerSourceException('car_not_found', 'Auto nenalezeno.');
    }

    private static function normalizeVin(string $vin): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $vin));
    }

    /** @return array<string,string> */
    private static function stringInput(#[\SensitiveParameter] array $input): array
    {
        return array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $input);
    }

    private function withLock(int $accountId, callable $callback): mixed
    {
        return $this->flow->withConnectionLock(self::LOCK_SCOPE, $accountId, $callback, self::busy(...));
    }

    private static function busy(): OdometerSourceException
    {
        return new OdometerSourceException('connection_busy', 'Načítání stavu tachometru už probíhá.');
    }
}

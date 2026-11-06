<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\OtherItemKindRepository;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;

/**
 * Druhy ostatních pohledávek a závazků (migrace 2228) a jejich číselné řady.
 *
 * Vestavěné druhy (nájem, půjčka…) i vlastní druhy firmy se chovají stejně: každý jde
 * skrýt a každý může číslovat vlastní řadou v `accounting_document_series`
 * (`register_id` = id řádku druhu), stejně jako pokladna s vlastní řadou. Vestavěný druh
 * má řádek, až když se jeho nastavení liší od výchozího. „Ostatní“ je výchozí druh
 * a číslo ze společné řady OP/OZ dostává vždy (i položka ze zůstatku zápisu).
 *
 * Vlastní řadu jde zapnout i později: vydaná čísla zůstávají a řada platí od dalšího
 * potvrzeného dokladu. Vypnutím se řada nemaže a po opětovném zapnutí čísluje dál.
 * Prefix se volí při prvním zapnutí, další úpravy prefixu, šablony a čítače jdou přes
 * Nástroje → Číselné řady.
 */
final class OtherItemKindService
{
    /** Vestavěné druhy, které jde nastavit; `other` je výchozí a nastavit nejde. */
    public const BUILTIN_CODES = ['rent', 'loan', 'deposit', 'insurance', 'fee', 'claim'];
    private const SIDES = ['receivable', 'payable'];
    private const NAME_MAX_LENGTH = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly OtherItemKindRepository $kinds,
        private readonly DocumentSeriesService $series,
    ) {}

    /**
     * Všechny druhy firmy: za každou stranu vestavěné v pořadí BUILTIN_CODES (i bez řádku,
     * ve výchozím stavu s `id` null), za nimi vlastní podle názvu.
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId): array
    {
        $rows = $this->kinds->list($supplierId);
        $builtin = [];
        foreach ($rows as $row) {
            if ($row['builtin']) $builtin[$row['side'] . ':' . $row['builtin_code']] = $row;
        }
        $out = [];
        foreach (self::SIDES as $side) {
            foreach (self::BUILTIN_CODES as $code) {
                $out[] = $builtin[$side . ':' . $code] ?? self::builtinDefault($side, $code);
            }
            foreach ($rows as $row) {
                if (!$row['builtin'] && $row['side'] === $side) $out[] = $row;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function get(int $supplierId, int $id): array
    {
        $kind = $this->kinds->find($supplierId, $id)
            ?? throw new OtherItemException('kind_not_found', 'Druh nebyl nalezen.', 404);
        $kind['series_prefix'] = $this->kinds->seriesPrefix($supplierId, $id, self::seriesCode((string) $kind['side']));
        return $kind;
    }

    /**
     * Nastavení vestavěného druhu; bez řádku výchozí stav.
     *
     * @return array<string,mixed>
     */
    public function getBuiltin(int $supplierId, string $side, string $code): array
    {
        self::assertBuiltinCode($side, $code);
        $kind = $this->kinds->findBuiltin($supplierId, $side, $code);
        return $kind === null ? self::builtinDefault($side, $code) : $this->get($supplierId, (int) $kind['id']);
    }

    /**
     * @param array<string,mixed> $input side, name, own_series, prefix (povinný s own_series)
     * @return array<string,mixed>
     */
    public function create(int $supplierId, array $input, ?int $userId): array
    {
        $side = (string) ($input['side'] ?? '');
        if (!in_array($side, self::SIDES, true)) {
            throw new OtherItemException('invalid_side', 'Vyberte pohledávku nebo závazek.');
        }
        $name = self::name($input['name'] ?? null);
        $ownSeries = self::bool($input['own_series'] ?? false, 'own_series');
        $id = $this->inTransaction(function () use ($supplierId, $side, $name, $ownSeries, $input, $userId): int {
            try {
                $id = $this->kinds->insert($supplierId, $side, $name, false, $userId);
            } catch (\PDOException $e) {
                throw self::duplicateName($e);
            }
            if ($ownSeries) {
                $this->enableSeries($supplierId, $id, $side, $input['prefix'] ?? null);
                $this->kinds->update($supplierId, $id, $name, true, true);
            }
            return $id;
        });
        return $this->get($supplierId, $id);
    }

    /**
     * Úprava druhu: název (jen vlastní druh), skrytí a vlastní řada. Strana se nemění —
     * položky druhu by jinak skončily na špatné straně. Skrytý druh se nenabízí novým
     * konceptům; opakování, jehož šablona ho nese, z něj dál vydává.
     *
     * @param array<string,mixed> $input name, is_active, own_series, prefix (při prvním zapnutí řady)
     * @return array<string,mixed>
     */
    public function update(int $supplierId, int $id, array $input): array
    {
        $this->inTransaction(function () use ($supplierId, $id, $input): int {
            $kind = $this->kinds->find($supplierId, $id, true)
                ?? throw new OtherItemException('kind_not_found', 'Druh nebyl nalezen.', 404);
            $this->apply($supplierId, $kind, $input);
            return $id;
        });
        return $this->get($supplierId, $id);
    }

    /**
     * Úprava vestavěného druhu jedné strany (is_active, own_series, prefix). Řádek
     * nastavení vznikne při první změně.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function updateBuiltin(int $supplierId, string $side, string $code, array $input, ?int $userId): array
    {
        self::assertBuiltinCode($side, $code);
        $id = $this->inTransaction(function () use ($supplierId, $side, $code, $input, $userId): int {
            $this->kinds->ensureBuiltin($supplierId, $side, $code, $userId);
            $kind = $this->kinds->findBuiltin($supplierId, $side, $code, true)
                ?? throw new \LogicException('Nastavení vestavěného druhu se nezaložilo.');
            $this->apply($supplierId, $kind, $input);
            return (int) $kind['id'];
        });
        return $this->get($supplierId, $id);
    }

    /**
     * Mění úprava vlastní řadu druhu? Akce podle toho vyžaduje právo k číselným řadám.
     *
     * @param array<string,mixed> $kind
     * @param array<string,mixed> $input
     */
    public static function changesSeries(array $kind, array $input): bool
    {
        return array_key_exists('own_series', $input)
            && filter_var($input['own_series'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== (bool) $kind['own_series'];
    }

    /** Smazat lze jen nepoužitý vlastní druh; použitý se skryje. Jeho prázdné řady jdou s ním. */
    public function delete(int $supplierId, int $id): void
    {
        $this->inTransaction(function () use ($supplierId, $id): int {
            $kind = $this->kinds->find($supplierId, $id, true)
                ?? throw new OtherItemException('kind_not_found', 'Druh nebyl nalezen.', 404);
            if ($kind['builtin']) {
                throw new OtherItemException('kind_builtin', 'Vestavěný druh nejde smazat, jen skrýt.', 409);
            }
            if ($this->kinds->hasItems($supplierId, $id) || $this->kinds->hasSchedule($supplierId, $id)) {
                throw new OtherItemException('kind_in_use', 'Druh s doklady nebo opakováním nelze smazat. Skryjte ho.', 409);
            }
            $this->series->deleteOwnSeries($supplierId, self::seriesCode((string) $kind['side']), $id);
            $this->kinds->delete($supplierId, $id);
            return $id;
        });
    }

    /**
     * Ověří vlastní druh pro položku dané strany. Nový nebo upravený koncept smí dostat
     * jen nabízený druh; koncept nebo šablona, která skrytý druh už nese, si ho ponechá.
     */
    public function assertUsable(int $supplierId, int $kindId, string $side, bool $allowInactive = false): void
    {
        $kind = $this->kinds->find($supplierId, $kindId);
        if ($kind === null || $kind['builtin']) {
            throw new OtherItemException('invalid_kind', 'Druh dokladu nebyl nalezen.', 404);
        }
        if ($kind['side'] !== $side) {
            throw new OtherItemException('kind_side', 'Druh patří k jiné straně (pohledávka × závazek).');
        }
        if (!$kind['is_active'] && !$allowInactive) {
            throw new OtherItemException('kind_inactive', 'Druh je skrytý.');
        }
    }

    /**
     * Skryté vestavěné druhy firmy jako `strana:kód` (pro návrhy AI asistenta).
     *
     * @return list<string>
     */
    public function hiddenBuiltinKinds(int $supplierId): array
    {
        return array_values(array_map(
            static fn (array $kind): string => $kind['side'] . ':' . $kind['builtin_code'],
            array_filter($this->kinds->list($supplierId), static fn (array $kind): bool => $kind['builtin'] && !$kind['is_active']),
        ));
    }

    /** Totéž pro vestavěný druh: skrytý nedostane nový ani jinak upravený koncept. */
    public function assertBuiltinUsable(int $supplierId, string $code, string $side, bool $allowInactive = false): void
    {
        if ($allowInactive || !in_array($code, self::BUILTIN_CODES, true)) {
            return;
        }
        $kind = $this->kinds->findBuiltin($supplierId, $side, $code);
        if ($kind !== null && !$kind['is_active']) {
            throw new OtherItemException('kind_inactive', 'Druh je skrytý.');
        }
    }

    /**
     * Řada, ze které položka druhu čerpá číslo: id řádku druhu s vlastní řadou, jinak 0
     * (společná řada OP/OZ). Řádek pro rok založí stejně jako u pokladny
     * ({@see DocumentSeriesService::ensureOwnSeriesYear}). Volá se uvnitř transakce výdeje čísla.
     */
    public function seriesScope(int $supplierId, ?int $kindId, string $kindCode, string $side, int $fiscalYear): int
    {
        $kind = match (true) {
            $kindId !== null => $this->kinds->find($supplierId, $kindId),
            in_array($kindCode, self::BUILTIN_CODES, true) => $this->kinds->findBuiltin($supplierId, $side, $kindCode),
            default => null,
        };
        if ($kind === null || !$kind['own_series']) {
            return 0;
        }
        $scope = (int) $kind['id'];
        if (!$this->series->ensureOwnSeriesYear($supplierId, self::seriesCode($side), $scope, $fiscalYear)) {
            throw new OtherItemException('kind_series_missing', 'Vlastní řada druhu nebyla nalezena. Nastavte ji v Nástrojích → Číselné řady.', 409);
        }
        return $scope;
    }

    public static function seriesCode(string $side): string
    {
        return $side === 'receivable' ? 'other_receivable' : 'other_payable';
    }

    /**
     * @param array<string,mixed> $kind zamčený řádek druhu
     * @param array<string,mixed> $input
     */
    private function apply(int $supplierId, array $kind, array $input): void
    {
        $id = (int) $kind['id'];
        if (array_key_exists('side', $input) && (string) $input['side'] !== $kind['side']) {
            throw new OtherItemException('kind_locked', 'Stranu druhu nelze po založení změnit. Založte nový druh.', 409);
        }
        if ($kind['builtin'] && array_key_exists('name', $input)) {
            throw new OtherItemException('kind_locked', 'Vestavěný druh nejde přejmenovat.', 409);
        }
        $name = $kind['builtin'] ? null
            : (array_key_exists('name', $input) ? self::name($input['name']) : (string) $kind['name']);
        $active = array_key_exists('is_active', $input) ? self::bool($input['is_active'], 'is_active') : (bool) $kind['is_active'];
        $ownSeries = array_key_exists('own_series', $input) ? self::bool($input['own_series'], 'own_series') : (bool) $kind['own_series'];
        if ($ownSeries && !$kind['own_series']) {
            $this->enableSeries($supplierId, $id, (string) $kind['side'], $input['prefix'] ?? null);
        }
        try {
            $this->kinds->update($supplierId, $id, $name, $active, $ownSeries);
        } catch (\PDOException $e) {
            throw self::duplicateName($e);
        }
    }

    /**
     * Zapne vlastní řadu druhu pro letošní rok. Řada, kterou druh už měl (vypnutá a znovu
     * zapnutá), čísluje dál se svým prefixem; nová potřebuje prefix, který nenese žádné
     * vydané číslo ostatních položek ani jiná řada stejné unikátnosti.
     */
    private function enableSeries(int $supplierId, int $id, string $side, mixed $prefix): void
    {
        $seriesCode = self::seriesCode($side);
        $year = (int) date('Y');
        if ($this->series->ensureOwnSeriesYear($supplierId, $seriesCode, $id, $year)) {
            return;
        }
        $prefix = strtoupper(trim((string) ($prefix ?? '')));
        if ($prefix === '') {
            throw new OtherItemException('prefix_required', 'Vlastní řada potřebuje prefix.');
        }
        // Řada mohla být v Nástrojích přejmenovaná nebo čísla převzatá z jiného programu,
        // jejich doklady ale prefix dál nesou (stejně hlídá CashRegisterService::freeSeriesPrefix).
        if ($this->kinds->hasDocumentNumberPrefix($supplierId, $prefix)) {
            throw new OtherItemException('series_prefix_taken',
                'Prefix "' . $prefix . '" už nesou čísla existujících ostatních položek — vlastní řada potřebuje jiný.');
        }
        // Chybný nebo obsazený prefix je validační chyba druhu (ClosingException
        // z řady by v akci ostatních položek skončila jako jiný kód chyby).
        try {
            $this->series->ensureSeriesRow($supplierId, $seriesCode, $year, $prefix, null, $id);
        } catch (ClosingException $e) {
            throw new OtherItemException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    /** @param callable():int $work */
    private function inTransaction(callable $work): int
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $id = $work();
            if ($ownTx) $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    private static function builtinDefault(string $side, string $code): array
    {
        return [
            'id' => null, 'side' => $side, 'builtin_code' => $code, 'builtin' => true, 'name' => null,
            'own_series' => false, 'is_active' => true, 'series_prefix' => null, 'created_at' => null, 'updated_at' => null,
        ];
    }

    private static function assertBuiltinCode(string $side, string $code): void
    {
        if (!in_array($side, self::SIDES, true) || !in_array($code, self::BUILTIN_CODES, true)) {
            throw new OtherItemException('kind_not_found', 'Druh nebyl nalezen.', 404);
        }
    }

    private static function duplicateName(\PDOException $e): \Throwable
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062
            ? new OtherItemException('kind_name_taken', 'Druh se stejným názvem už existuje.', 409)
            : $e;
    }

    private static function bool(mixed $value, string $field): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            ?? throw new OtherItemException('validation_failed', $field . ' musí být true nebo false.');
    }

    private static function name(mixed $value): string
    {
        $name = trim((string) ($value ?? ''));
        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new OtherItemException('invalid_kind_name', 'Zadejte název druhu do ' . self::NAME_MAX_LENGTH . ' znaků.');
        }
        return $name;
    }
}

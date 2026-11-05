<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\OtherItemKindRepository;
use MyInvoice\Repository\OtherItemPostingProfileRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;

/**
 * Správa účetních profilů druhů a vlastní kontace úhrad položky (migrace 2238).
 *
 * Profil se ukládá po událostech; chybějící klíč událost nemění, `null` profil události
 * smaže. Účty se ověřují proti osnově firmy při uložení, ne až při zaúčtování: aktivní
 * účet, u vzniku rozvahový účet položky podle strany druhu a protiúčet bez saldokonta,
 * u úhrady prázdná strana položky (úhrada vždy jde proti účtu položky, jinak by minula
 * saldokonto) a peněžní účet banky 221 nebo pokladny 211.
 *
 * Návrhy kontace pro vestavěné druhy jsou jen nabídka v nastavení druhu, aplikace je
 * sama nikdy neuloží: účty a analytiky se mezi firmami liší.
 */
final class OtherItemPostingProfileService
{
    /**
     * Nezávazné návrhy kontace vzniku vestavěných druhů: [účet položky, protiúčet].
     * Peníze půjčky nebo kauce jdou přes 261 (peníze na cestě), protože pohyb na účtu
     * zaúčtuje banka zvlášť (výplata 261/221, příjem 221/261).
     */
    public const SUGGESTIONS = [
        'receivable' => [
            'rent' => ['315', '602'],
            'loan' => ['378', '261'],
            'deposit' => ['378', '261'],
            'insurance' => ['378', '648'],
            'fee' => ['315', '648'],
            'claim' => ['378', '648'],
        ],
        'payable' => [
            'rent' => ['325', '518'],
            'loan' => ['461', '261'],
            'deposit' => ['379', '261'],
            'insurance' => ['325', '548'],
            'fee' => ['325', '538'],
            'claim' => ['379', '548'],
        ],
    ];

    /** Rámec 504 (nevýdělečné subjekty): 548 jsou manka, 648 zúčtování fondů, úvěry 951. */
    private const SUGGESTIONS_504 = ['548' => '549', '648' => '649', '461' => '951'];

    private const MONEY_PREFIX =['payment_bank' => '221', 'payment_cash' => '211'];
    private const MAX_DIMENSIONS = 20;

    public function __construct(
        private readonly Connection $db,
        private readonly OtherItemPostingProfileRepository $profiles,
        private readonly OtherItemKindRepository $kinds,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly DimensionService $dimensions,
        private readonly OtherItemPostingResolver $resolver,
    ) {}

    /**
     * Přidá k druhům z {@see OtherItemKindService::list} jejich profily a návrh kontace.
     * Daňová evidence profily nemá, seznam zůstane beze změny.
     *
     * @param list<array<string,mixed>> $kinds
     * @return list<array<string,mixed>>
     */
    public function attachToKinds(int $supplierId, array $kinds): array
    {
        if (!$this->resolver->isDoubleEntry($supplierId)) {
            return $kinds;
        }
        unset($this->postable[$supplierId]);
        $bySupplier = $this->profiles->profilesBySupplier($supplierId);
        foreach ($kinds as $i => $kind) {
            $kinds[$i]['posting_profiles'] = self::byEvent($kind['id'] !== null ? ($bySupplier[(int) $kind['id']] ?? []) : []);
            $kinds[$i]['posting_suggestion'] = $kind['builtin']
                ? $this->suggestion($supplierId, (string) $kind['side'], (string) $kind['builtin_code']) : null;
        }
        return $kinds;
    }

    /** @return array<string,array<string,mixed>|null> */
    public function profilesForKind(int $supplierId, int $kindId): array
    {
        $this->assertDoubleEntry($supplierId);
        $this->kinds->find($supplierId, $kindId)
            ?? throw new OtherItemException('kind_not_found', 'Druh nebyl nalezen.', 404);
        return self::byEvent($this->profiles->profilesForKind($supplierId, $kindId));
    }

    /**
     * Uloží profily druhu. Vestavěnému druhu založí řádek nastavení.
     *
     * @param array<string,mixed> $input událost => pravidlo | null; nebo `profiles` => totéž
     * @return array{kind_id:int,before:array<string,mixed>,after:array<string,mixed>}
     */
    public function saveForKind(int $supplierId, ?int $kindId, ?string $builtinSide, ?string $builtinCode, array $input, ?int $userId): array
    {
        $this->assertDoubleEntry($supplierId);
        $input = is_array($input['profiles'] ?? null) ? $input['profiles'] : $input;
        $events = $this->eventsFromInput($input);
        return $this->inTransaction(function () use ($supplierId, $kindId, $builtinSide, $builtinCode, $input, $events, $userId): array {
            if ($kindId === null) {
                if (!in_array($builtinSide, ['receivable', 'payable'], true)
                    || !in_array($builtinCode, OtherItemKindService::BUILTIN_CODES, true)) {
                    throw new OtherItemException('kind_not_found', 'Druh nebyl nalezen.', 404);
                }
                $this->kinds->ensureBuiltin($supplierId, $builtinSide, $builtinCode, $userId);
                $kind = $this->kinds->findBuiltin($supplierId, $builtinSide, $builtinCode, true);
            } else {
                $kind = $this->kinds->find($supplierId, $kindId, true);
            }
            if ($kind === null) {
                throw new OtherItemException('kind_not_found', 'Druh nebyl nalezen.', 404);
            }
            $id = (int) $kind['id'];
            $before = self::byEvent($this->profiles->profilesForKind($supplierId, $id));
            foreach ($events as $event) {
                $raw = $input[$event->value];
                if ($raw === null) {
                    $this->profiles->deleteProfile($supplierId, $id, $event->value);
                    continue;
                }
                if (!is_array($raw)) {
                    throw new OtherItemException('invalid_posting_profile', 'Neplatný účetní profil.');
                }
                $rule = $this->normalizeRule($supplierId, (string) $kind['side'], $event, $raw, true);
                if (self::isEmpty($rule)) {
                    $this->profiles->deleteProfile($supplierId, $id, $event->value);
                    continue;
                }
                $active = array_key_exists('is_active', $raw)
                    ? (filter_var($raw['is_active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                        ?? throw new OtherItemException('validation_failed', 'is_active musí být true nebo false.'))
                    : true;
                $this->profiles->upsertProfile($supplierId, $id, $event->value, $rule, $active, $userId);
            }
            return ['kind_id' => $id, 'before' => $before, 'after' => self::byEvent($this->profiles->profilesForKind($supplierId, $id))];
        });
    }

    /** @return array<string,array<string,mixed>> vlastní kontace úhrad položky */
    public function overridesForItem(int $supplierId, int $itemId): array
    {
        return $this->profiles->overridesForItem($supplierId, $itemId);
    }

    /**
     * Uloží vlastní kontaci úhrad položky (událost => pravidlo | null). Vznik položka nese
     * ve vlastních sloupcích, sem nepatří. Volá se uvnitř transakce úpravy položky.
     *
     * @param array<string,mixed> $item zamčená položka (side, id)
     * @param mixed $input
     * @return array{before:array<string,mixed>,after:array<string,mixed>}
     */
    public function saveItemOverrides(int $supplierId, array $item, mixed $input, ?int $userId): array
    {
        if (!$this->resolver->isDoubleEntry($supplierId)) {
            return ['before' => [], 'after' => []];
        }
        if (!is_array($input) || array_is_list($input) && $input !== []) {
            throw new OtherItemException('invalid_posting_override', 'Vlastní kontace úhrad musí být objekt podle událostí.');
        }
        $itemId = (int) $item['id'];
        $before = $this->profiles->overridesForItem($supplierId, $itemId);
        foreach ($this->eventsFromInput($input) as $event) {
            if (!$event->isPayment()) {
                throw new OtherItemException('invalid_posting_override',
                    'Vlastní kontaci vzniku nesou účet a protiúčet položky.');
            }
            $raw = $input[$event->value];
            if ($raw === null) {
                $this->profiles->deleteOverride($supplierId, $itemId, $event->value);
                continue;
            }
            if (!is_array($raw)) {
                throw new OtherItemException('invalid_posting_override', 'Neplatná vlastní kontace úhrady.');
            }
            $rule = $this->normalizeRule($supplierId, (string) $item['side'], $event, $raw, false);
            if (self::isEmpty($rule)) {
                $this->profiles->deleteOverride($supplierId, $itemId, $event->value);
                continue;
            }
            $this->profiles->upsertOverride($supplierId, $itemId, $event->value, $rule, $userId);
        }
        return ['before' => $before, 'after' => $this->profiles->overridesForItem($supplierId, $itemId)];
    }

    /**
     * Koncept změnil stranu: peněžní účet vlastní kontace úhrady přejde na druhou stranu
     * zápisu, strana položky zůstane prázdná.
     */
    public function flipItemOverrides(int $supplierId, int $itemId, ?int $userId): void
    {
        foreach ($this->profiles->overridesForItem($supplierId, $itemId) as $event => $rule) {
            $this->profiles->upsertOverride($supplierId, $itemId, (string) $event, [
                'debit_account_code' => $rule['credit_account_code'],
                'credit_account_code' => $rule['debit_account_code'],
                'description_template' => $rule['description_template'],
                'dimensions' => $rule['dimensions'],
            ], $userId);
        }
    }

    /**
     * Návrh kontace vzniku vestavěného druhu (MD/D podle strany druhu) s příznakem, zda
     * jsou oba účty v osnově firmy a dají se účtovat bez volby analytiky. Rámec 504 má
     * jiné účty pro ostatní náklady, výnosy a dlouhodobé úvěry ({@see SUGGESTIONS_504}).
     *
     * @return array{debit_account_code:string,credit_account_code:string,available:bool}|null
     */
    public function suggestion(int $supplierId, string $side, string $code): ?array
    {
        $pair = self::SUGGESTIONS[$side][$code] ?? null;
        if ($pair === null) return null;
        if ($this->framework($supplierId) === AccountingFrameworkService::CZ_504) {
            $pair = array_map(static fn (string $account): string => self::SUGGESTIONS_504[$account] ?? $account, $pair);
        }
        [$item, $counter] = $pair;
        $receivable = $side === 'receivable';
        return [
            'debit_account_code' => $receivable ? $item : $counter,
            'credit_account_code' => $receivable ? $counter : $item,
            'available' => $this->usable($supplierId, $item) && $this->usable($supplierId, $counter),
        ];
    }

    /** @var array<int,array<string,int>> firma => kód aktivního účtu => počet aktivních analytik */
    private array $postable = [];

    /** Aktivní účet bez víc analytik; osnova se pro seznam druhů načte jednou. */
    private function usable(int $supplierId, string $code): bool
    {
        if (!isset($this->postable[$supplierId])) {
            $accounts = $this->accounts->listForTenant($supplierId);
            $children = [];
            foreach ($accounts as $account) {
                if ($account['parent_id'] !== null) $children[$account['parent_id']] = ($children[$account['parent_id']] ?? 0) + 1;
            }
            $this->postable[$supplierId] = [];
            foreach ($accounts as $account) {
                $this->postable[$supplierId][(string) $account['account_code']] = $children[$account['id']] ?? 0;
            }
        }
        return isset($this->postable[$supplierId][$code]) && $this->postable[$supplierId][$code] <= 1;
    }

    private function framework(int $supplierId): string
    {
        return (new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db)))
            ->forDate($supplierId, date('Y-m-d'));
    }

    /**
     * Ověří a znormalizuje pravidlo jedné události.
     *
     * @param array<string,mixed> $raw debit_account_code, credit_account_code, description_template, dimensions
     * @return array{debit_account_code:?string,credit_account_code:?string,description_template:?string,
     *   dimensions:list<array{type_id:int,value_id:int}>}
     */
    public function normalizeRule(int $supplierId, string $side, OtherItemPostingEvent $event, array $raw, bool $forKind): array
    {
        $codes = [];
        foreach (['debit_account_code', 'credit_account_code'] as $column) {
            $value = $raw[$column] ?? null;
            if ($value !== null && !is_string($value)) {
                throw new OtherItemException('invalid_account', 'Neplatný kód účtu.');
            }
            $code = trim((string) $value);
            if (strlen($code) > 20) {
                throw new OtherItemException('invalid_account', 'Neplatný kód účtu.');
            }
            $codes[$column] = $code === '' ? null : $code;
        }
        $itemColumn = OtherItemPostingResolver::itemColumn($event, $side);
        $otherColumn = OtherItemPostingResolver::otherColumn($event, $side);
        if ($event === OtherItemPostingEvent::Origin) {
            if ($codes[$itemColumn] !== null) {
                $this->assertItemAccount($supplierId, $codes[$itemColumn], $side);
            }
            if ($codes[$otherColumn] !== null) {
                $counter = $codes[$otherColumn];
                if ($counter === $codes[$itemColumn] || preg_match('/^(315|325)(?:$|[.\-])/', $counter)) {
                    throw new OtherItemException('same_accounts', 'Saldokontní účet smí být pouze na straně pohledávky nebo závazku.');
                }
                $this->assertPostingAccount($supplierId, $counter);
            }
        } else {
            if ($codes[$itemColumn] !== null) {
                throw new OtherItemException('profile_breaks_saldo',
                    'Úhrada jde vždy proti účtu položky. Stranu položky nechte prázdnou.');
            }
            $money = $codes[$otherColumn];
            if ($money !== null) {
                $prefix = self::MONEY_PREFIX[$event->value];
                if (!preg_match('/^' . $prefix . '(?:$|[.\-]|\d)/', $money)) {
                    throw new OtherItemException('invalid_payment_account', $event === OtherItemPostingEvent::PaymentBank
                        ? 'Úhrada z banky jde na účet 221 nebo jeho analytiku.'
                        : 'Úhrada z pokladny jde na účet 211 nebo jeho analytiku.');
                }
                // Syntetika 221/211 je místo pro analytiku banky nebo pokladny z kontextu
                // dokladu, proto se u ní analytická úroveň nevyžaduje.
                $this->assertPostingAccount($supplierId, $money, $money === $prefix);
            }
        }
        $template = $raw['description_template'] ?? null;
        if ($template !== null && !is_string($template)) {
            throw new OtherItemException('invalid_description_template', 'Neplatná šablona popisu.');
        }
        $template = trim((string) $template);
        if (mb_strlen($template) > 255) {
            throw new OtherItemException('invalid_description_template', 'Šablona popisu smí mít nejvýše 255 znaků.');
        }
        if (preg_match_all('/\{([^}]*)\}/u', $template, $found)) {
            foreach ($found[1] as $name) {
                if (!in_array($name, OtherItemPostingResolver::PLACEHOLDERS, true)) {
                    throw new OtherItemException('invalid_description_template',
                        'Neznámý zástupný znak {' . $name . '}. Použít jde {title}, {document_no}, {partner} a {variable_symbol}.');
                }
            }
        }
        $dimensions = $this->normalizeDimensions($supplierId, $raw['dimensions'] ?? [], $event, $forKind);
        return [
            'debit_account_code' => $codes['debit_account_code'],
            'credit_account_code' => $codes['credit_account_code'],
            'description_template' => $template === '' ? null : $template,
            'dimensions' => $dimensions,
        ];
    }

    /** @return list<OtherItemPostingEvent> */
    private function eventsFromInput(array $input): array
    {
        $events = [];
        foreach (array_keys($input) as $key) {
            $events[] = OtherItemPostingEvent::fromInput((string) $key);
        }
        return $events;
    }

    /** @return list<array{type_id:int,value_id:int}> */
    private function normalizeDimensions(int $supplierId, mixed $raw, OtherItemPostingEvent $event, bool $forKind): array
    {
        if ($raw === null || $raw === []) return [];
        if (!$forKind || $event !== OtherItemPostingEvent::Origin) {
            throw new OtherItemException('invalid_dimensions', 'Výchozí dimenze nese jen profil vzniku druhu; úhrada přebírá dimenze položky.');
        }
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::MAX_DIMENSIONS) {
            throw new OtherItemException('invalid_dimensions', 'Dimenze zadejte jako seznam typů a hodnot.');
        }
        $map = [];
        foreach ($raw as $row) {
            $type = is_array($row) ? filter_var($row['type_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            $value = is_array($row) ? filter_var($row['value_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($type === false || $value === false || isset($map[$type])) {
                throw new OtherItemException('invalid_dimensions', 'Každá dimenze potřebuje jeden typ a jednu hodnotu.');
            }
            $map[$type] = $value;
        }
        try {
            $normalized = $this->dimensions->normalize($supplierId, $map);
        } catch (DimensionException $e) {
            throw new OtherItemException('invalid_dimensions', $e->getMessage());
        }
        $out = [];
        foreach ($normalized as $type => $value) {
            $out[] = ['type_id' => (int) $type, 'value_id' => (int) $value];
        }
        return $out;
    }

    private function assertItemAccount(int $supplierId, string $code, string $side): void
    {
        $account = $this->accounts->findByCode($supplierId, $code);
        if ($account === null || !$account['is_active']
            || $account['account_type'] !== ($side === 'receivable' ? 'asset' : 'liability')) {
            throw new OtherItemException('invalid_account_side',
                'Účet položky musí být aktivní rozvahový účet ' . ($side === 'receivable' ? 'aktiv (pohledávka).' : 'pasiv (závazek).'));
        }
        $this->assertPostingLevel($supplierId, $account);
    }

    private function assertPostingAccount(int $supplierId, string $code, bool $contextAnalytic = false): void
    {
        $account = $this->accounts->findByCode($supplierId, $code);
        if ($account === null || !$account['is_active']) {
            throw new OtherItemException('invalid_account', 'Účet ' . $code . ' není aktivní v účtové osnově.');
        }
        if (!$contextAnalytic) {
            $this->assertPostingLevel($supplierId, $account);
        }
    }

    /**
     * Syntetika s víc analytikami se účtovat nedá (součet analytik by neseděl); jediná
     * analytika se dosadí sama ({@see PostingService}), proto ji připouštíme.
     *
     * @param array<string,mixed> $account
     */
    private function assertPostingLevel(int $supplierId, array $account): void
    {
        $children = array_filter($this->accounts->childrenOf($supplierId, (int) $account['id']),
            static fn (array $child): bool => (bool) $child['is_active']);
        if (count($children) > 1) {
            throw new OtherItemException('account_not_posting_level',
                'Účet ' . $account['account_code'] . ' má analytiky. Vyberte analytický účet.');
        }
    }

    private function assertDoubleEntry(int $supplierId): void
    {
        if (!$this->resolver->isDoubleEntry($supplierId)) {
            throw new OtherItemException('not_double_entry', 'Účetní profily má jen podvojné účetnictví.', 409);
        }
    }

    /** @param array<string,mixed> $rule */
    private static function isEmpty(array $rule): bool
    {
        return $rule['debit_account_code'] === null && $rule['credit_account_code'] === null
            && $rule['description_template'] === null && $rule['dimensions'] === [];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,array<string,mixed>|null>
     */
    private static function byEvent(array $rows): array
    {
        $out = [];
        foreach (OtherItemPostingEvent::implementedCases() as $event) {
            $out[$event->value] = null;
        }
        foreach ($rows as $row) {
            $out[(string) $row['event']] = $row;
        }
        return $out;
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function inTransaction(callable $work): mixed
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $result = $work();
            if ($ownTx) $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

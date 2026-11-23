<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Bank\BankTransactionSymbolException;
use MyInvoice\Service\Bank\BankTransactionSymbolService;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hromadná oprava variabilního symbolu bankovních pohybů ({@see BankTransactionSymbolService}).
 *
 * Nový symbol je buď stálá hodnota (`vs_mode = constant`), nebo vzor (`vs_mode = pattern`)
 * z číslic a značek, které se doplní z pohybu:
 *   {ss}     specifický symbol,
 *   {ks}     konstantní symbol,
 *   {vs}     dosavadní variabilní symbol,
 *   {zprava} první skupina 2 až 10 číslic ve zprávě pro příjemce (popisu pohybu).
 * Výsledek musí být platný VS (jen číslice, nejvýš 10), jinak se pohyb přeskočí.
 *
 * Změní se jen pohyb, který ještě nic nevyřídil; po změně se pohyb znovu nabídne
 * automatickému párování. Vrácení obnoví dosavadní symbol, jen když pohyb zůstal
 * nespárovaný a symbol od hromadné změny nikdo nezměnil.
 *
 * Variabilní symbol faktur hromadná akce nemění: u vydané faktury je to číslo dokladu
 * (po vystavení ho úprava nemění) a u přijaté interní číslo dokladu z číselné řady.
 */
final class SetVariableSymbolOperation implements BulkOperation
{
    public const KEY = 'set_variable_symbol';
    public const TOKENS = ['{ss}', '{ks}', '{vs}', '{zprava}'];
    private const MAX_PATTERN = 40;

    public function __construct(private readonly BankTransactionSymbolService $symbols) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function supportsRollback(): bool
    {
        return true;
    }

    public function unavailableReason(Request $request, int $supplierId, string $target): ?string
    {
        if (!RequestAuthorization::canBulkChange($request)) {
            return 'Hromadné změny smí správce firmy nebo role s právem Hromadné změny dokladů a zápisů.';
        }
        // Symbol rozhoduje o párování: smí ho měnit, kdo smí pohyby párovat.
        if (!RequestAuthorization::allows($request, 'bank.match', AccessLevel::WRITE)) {
            return 'Chybí oprávnění párovat bankovní pohyby.';
        }
        return null;
    }

    public function normalizeParams(int $supplierId, string $target, array $raw): array
    {
        $mode = (string) ($raw['vs_mode'] ?? '');
        if ($mode === 'constant') {
            $value = is_scalar($raw['vs_value'] ?? null) ? trim((string) $raw['vs_value']) : '';
            if (!BankTransactionSymbolService::isValid($value)) {
                throw new BulkActionException('validation_failed', 'Variabilní symbol smí mít jen číslice, nejvýš ' . BankTransactionSymbolService::MAX_LENGTH . '.', 422);
            }
            return ['vs_mode' => 'constant', 'vs_value' => $value, 'vs_pattern' => null];
        }
        if ($mode === 'pattern') {
            $pattern = is_scalar($raw['vs_pattern'] ?? null) ? trim((string) $raw['vs_pattern']) : '';
            $rest = str_replace(self::TOKENS, '', $pattern);
            if ($pattern === '' || mb_strlen($pattern) > self::MAX_PATTERN || $rest === $pattern || preg_match('/^[0-9]*$/D', $rest) !== 1) {
                throw new BulkActionException('validation_failed', 'Vzor smí obsahovat číslice a značky {ss}, {ks}, {vs} a {zprava}, alespoň jednu značku.', 422);
            }
            return ['vs_mode' => 'pattern', 'vs_value' => null, 'vs_pattern' => $pattern];
        }
        throw new BulkActionException('validation_failed', 'Zvolte stálou hodnotu, nebo vzor variabilního symbolu.', 422);
    }

    public function evaluate(BulkContext $ctx, array $row): array
    {
        $item = BulkSelection::itemBase($row);
        $state = $this->symbols->state($ctx->supplierId, (int) $row['source_id']);
        if ($state === null) {
            return ['status' => 'skipped', 'reason_code' => 'not_found'] + $item;
        }
        $item['before'] = self::view($state);
        $new = $this->resolve($ctx, $state);
        $item['after'] = ['variable_symbol' => $new] + $item['before'];
        if ($new === $state['variable_symbol']) {
            return ['status' => 'unchanged'] + $item;
        }
        if (!BankTransactionSymbolService::isValid($new)) {
            return ['status' => 'skipped', 'reason_code' => 'invalid_variable_symbol',
                'message' => $new === '' ? 'Vzor z pohybu nedal žádný symbol.' : 'Symbol „' . mb_substr($new, 0, 30) . '" není platný VS (jen číslice, nejvýš 10).'] + $item;
        }
        if ($state['blocker'] !== null) {
            return ['status' => 'skipped', 'reason_code' => $state['blocker'][0], 'message' => $state['blocker'][1]] + $item;
        }
        return ['status' => 'would_change', 'fingerprint' => $state['fingerprint']] + $item;
    }

    public function apply(BulkContext $ctx, array $dry): array
    {
        $item = BulkSelection::copyItem($dry);
        $symbol = (string) (((array) $dry['after'])['variable_symbol'] ?? '');
        try {
            $result = $this->symbols->change($ctx->supplierId, (int) $dry['source_id'], $symbol, (string) $dry['fingerprint'], $ctx->userId ?: null, true);
        } catch (BankTransactionSymbolException $e) {
            return ['status' => 'skipped', 'reason_code' => $e->errorCode === 'changed' ? 'changed_after_dry_run' : $e->errorCode,
                'message' => $e->getMessage()] + $item;
        }
        return ['status' => 'applied', 'reason_code' => $result['rematch_error'] === null ? null : 'rematch_failed',
            'message' => $result['rematch_error'], 'after' => self::view($result['state']),
            'fingerprint' => $result['state']['fingerprint']] + $item;
    }

    public function rollback(BulkContext $ctx, array $applied): array
    {
        $item = ['before' => $applied['after'], 'after' => $applied['before']] + BulkSelection::copyItem($applied);
        $previous = ((array) $applied['before'])['variable_symbol'] ?? null;
        $state = $this->symbols->state($ctx->supplierId, (int) $applied['source_id']);
        if ($state === null) {
            return ['status' => 'failed', 'reason_code' => 'not_found'] + $item;
        }
        if ($state['blocker'] !== null) {
            return ['status' => 'failed', 'reason_code' => $state['blocker'][0], 'message' => $state['blocker'][1]] + $item;
        }
        try {
            $result = $this->symbols->change($ctx->supplierId, (int) $applied['source_id'], $previous === null ? null : (string) $previous,
                (string) $applied['fingerprint'], $ctx->userId ?: null, false);
        } catch (BankTransactionSymbolException $e) {
            return ['status' => 'failed', 'reason_code' => $e->errorCode === 'changed' ? 'changed_after_apply' : $e->errorCode,
                'message' => $e->getMessage()] + $item;
        }
        return ['status' => 'applied', 'reason_code' => null, 'message' => null,
            'after' => self::view($result['state']), 'fingerprint' => $result['state']['fingerprint']] + $item;
    }

    /** @param array<string,mixed> $state */
    private function resolve(BulkContext $ctx, array $state): string
    {
        if ($ctx->params['vs_mode'] === 'constant') {
            return (string) $ctx->params['vs_value'];
        }
        $digits = preg_match('/(?<![0-9])[0-9]{2,10}(?![0-9])/', (string) $state['description'], $m) === 1 ? $m[0] : '';
        return str_replace(self::TOKENS, [
            preg_replace('/\D/', '', (string) $state['specific_symbol']),
            preg_replace('/\D/', '', (string) $state['constant_symbol']),
            (string) $state['variable_symbol'],
            $digits,
        ], (string) $ctx->params['vs_pattern']);
    }

    /**
     * @param array<string,mixed> $state
     * @return array{variable_symbol:?string, match_status:string}
     */
    private static function view(array $state): array
    {
        return ['variable_symbol' => $state['variable_symbol'], 'match_status' => (string) $state['match_status']];
    }
}

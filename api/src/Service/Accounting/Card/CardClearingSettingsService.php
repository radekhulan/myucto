<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Card;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CardClearingSettingsRepository;
use MyInvoice\Repository\PaymentCardRepository;
use MyInvoice\Service\Accounting\PostingException;
use PDO;

/**
 * Nastavení účtování plateb kartou (záložka „Nastavení účtování" na stránce Platební karty)
 * a ruční výběr analytiky karty.
 *
 * Změna nastavení platí jen pro budoucí zápisy — nic se nepřeúčtovává. Zaúčtovaný pohyb
 * drží mezičlen, na který se zaúčtoval (viz lepivost režimu v BankPostingService), takže
 * i změna syntetiky nebo analytiky karty se projeví jen u nových plateb. Nese-li stará
 * analytika zůstatek, uložení chce výslovné potvrzení (vrací 409 `confirm_required`
 * s částkou), ať účetní ví, že zůstatek na starém účtu zůstane a vypořádá ho ručně.
 */
final class CardClearingSettingsService
{
    /** Povolený prefix účtu pro jednotlivá pole (třída / syntetika). */
    private const ACCOUNT_RULES = [
        'writeoff_account_id'      => ['5'],
        'holder_account_id'        => ['335'],
        'fx_loss_account_id'       => ['5'],
        'fx_gain_account_id'       => ['6'],
        'rounding_loss_account_id' => ['5'],
        'rounding_gain_account_id' => ['6'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly CardClearingSettingsRepository $repo,
        private readonly CardClearingAccounts $accounts,
        private readonly CardClearingRegime $regime,
        private readonly PaymentCardRepository $cards,
    ) {}

    /** @return array<string,mixed> */
    public function settings(int $supplierId): array
    {
        $settings = $this->repo->find($supplierId);
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, account_code, name, account_type, is_synthetic, parent_id, tax_deductibility
               FROM chart_of_accounts
              WHERE supplier_id = ? AND is_active = 1
                AND (account_code LIKE '5%' OR account_code LIKE '6%' OR account_code LIKE '335%'
                     OR account_code IN ('378', '261', '395'))
              ORDER BY account_code"
        );
        $stmt->execute([$supplierId]);
        $options = array_map(static fn (array $a): array => [
            'id'                => (int) $a['id'],
            'account_code'      => (string) $a['account_code'],
            'name'              => (string) $a['name'],
            'account_type'      => (string) $a['account_type'],
            'is_synthetic'      => (bool) $a['is_synthetic'],
            'parent_id'         => $a['parent_id'] !== null ? (int) $a['parent_id'] : null,
            'non_deductible'    => (string) $a['tax_deductibility'] === 'non_deductible',
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $syntheticOptions = [];
        foreach (CardClearingSettingsRepository::SYNTHETICS as $code) {
            $syntheticOptions[] = [
                'account_code' => $code,
                'available'    => $this->accounts->chartHas($supplierId, $code),
                'balance'      => $this->clearingBalance($supplierId, $code),
            ];
        }

        return [
            'configured'             => $settings['configured'],
            'double_entry'           => $this->isDoubleEntry($supplierId),
            'settings'               => $settings,
            'defaults'               => [
                'writeoff_account_code'      => $this->defaultWriteoffCode($supplierId),
                'holder_account_code'        => CardClearingSettingsRepository::DEFAULT_CODES['holder'],
                'fx_loss_account_code'       => '563',
                'fx_gain_account_code'       => '663',
                'rounding_loss_account_code' => CardClearingSettingsRepository::DEFAULT_CODES['rounding_loss'],
                'rounding_gain_account_code' => CardClearingSettingsRepository::DEFAULT_CODES['rounding_gain'],
                'effective_from'             => $this->defaultEffectiveFrom($supplierId),
            ],
            'synthetic_options'      => $syntheticOptions,
            'account_options'        => $options,
            'unverified_cards'       => $this->unverifiedCount($supplierId),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> aktuální nastavení po uložení
     */
    public function saveSettings(int $supplierId, array $input, ?int $userId): array
    {
        $current = $this->repo->find($supplierId);
        $enabled = !empty($input['enabled']);
        if ($enabled && !$this->isDoubleEntry($supplierId)) {
            throw new PostingException('not_double_entry', 'Účtování plateb kartou přes mezičlen je jen pro podvojné účetnictví.', 422);
        }

        $synthetic = (string) ($input['clearing_synthetic'] ?? $current['clearing_synthetic']);
        if (!in_array($synthetic, CardClearingSettingsRepository::SYNTHETICS, true)) {
            throw new PostingException('invalid_synthetic', 'Mezičlen může být jen 378, 261 nebo 395.', 422, ['field' => 'clearing_synthetic']);
        }
        if (!$this->accounts->chartHas($supplierId, $synthetic)) {
            throw new PostingException('synthetic_missing', 'Účet ' . $synthetic . ' v účtové osnově firmy chybí.', 422, ['field' => 'clearing_synthetic']);
        }

        $effectiveFrom = $input['effective_from'] ?? $current['effective_from'];
        $effectiveFrom = is_string($effectiveFrom) && trim($effectiveFrom) !== '' ? trim($effectiveFrom) : null;
        if ($effectiveFrom !== null && !self::isDate($effectiveFrom)) {
            throw new PostingException('invalid_date', 'Datum účinnosti není platné datum.', 422, ['field' => 'effective_from']);
        }
        if ($enabled && $effectiveFrom === null) {
            $effectiveFrom = $this->defaultEffectiveFrom($supplierId);
            if ($effectiveFrom === null) {
                throw new PostingException('invalid_date', 'Zadejte datum účinnosti — firma nemá otevřené účetní období.', 422, ['field' => 'effective_from']);
            }
        }

        $days = (int) ($input['unmatched_alert_days'] ?? $current['unmatched_alert_days']);
        if ($days < 1 || $days > 365) {
            throw new PostingException('invalid_alert_days', 'Počet dní pro upozornění musí být 1 až 365.', 422, ['field' => 'unmatched_alert_days']);
        }

        $data = [
            'enabled'              => $enabled,
            'effective_from'       => $effectiveFrom,
            'clearing_synthetic'   => $synthetic,
            'auto_create_cards'    => array_key_exists('auto_create_cards', $input) ? !empty($input['auto_create_cards']) : $current['auto_create_cards'],
            'unmatched_alert_days' => $days,
        ];
        foreach (self::ACCOUNT_RULES as $field => $prefixes) {
            $raw = array_key_exists($field, $input) ? $input[$field] : $current[$field];
            $id = $raw === null || $raw === '' ? null : (int) $raw;
            if ($id !== null && $id > 0) {
                $this->assertAccount($supplierId, $id, $prefixes, $field);
                $data[$field] = $id;
            } else {
                $data[$field] = null;
            }
        }

        if ($synthetic !== $current['clearing_synthetic'] && empty($input['confirm'])) {
            $balance = $this->clearingBalance($supplierId, (string) $current['clearing_synthetic']);
            if (abs($balance) >= 0.005) {
                throw new PostingException('confirm_required', sprintf(
                    'Analytiky karet na %s mají zůstatek %s Kč. Zůstane tam a vypořádáte ho ručně — změna platí jen pro nové platby.',
                    $current['clearing_synthetic'],
                    number_format($balance, 2, ',', ' '),
                ), 409, ['balance' => $balance, 'account_code' => $current['clearing_synthetic']]);
            }
        }

        $this->repo->save($supplierId, $data, $userId);
        $this->regime->forget($supplierId);
        return $this->settings($supplierId);
    }

    /**
     * Ruční výběr analytiky mezičlenu u karty. `null` = vrátit na automatické přidělení
     * (nová analytika vznikne u další platby).
     *
     * @return array<string,mixed> karta
     */
    public function setCardAnalytic(int $supplierId, int $cardId, ?string $accountCode, bool $confirm): array
    {
        $card = $this->cards->find($supplierId, $cardId);
        if ($card === null) {
            throw new PostingException('not_found', 'Platební karta nenalezena.', 404);
        }
        $synthetic = (string) $this->regime->settings($supplierId)['clearing_synthetic'];
        $suffix = null;
        if ($accountCode !== null && $accountCode !== '') {
            $prefix = $synthetic . '.';
            $suffix = str_starts_with($accountCode, $prefix) ? substr($accountCode, strlen($prefix)) : '';
            if ($suffix === '' || preg_match('/^[0-9]{1,6}$/', $suffix) !== 1 || $suffix === CardClearingAccounts::FALLBACK_SUFFIX) {
                throw new PostingException('invalid_analytic', 'Analytika musí být analytikou mezičlenu ' . $synthetic . '.', 422, ['field' => 'account_code']);
            }
            if (!$this->accounts->chartHas($supplierId, $accountCode)) {
                throw new PostingException('invalid_analytic', 'Účet ' . $accountCode . ' v osnově firmy není.', 422, ['field' => 'account_code']);
            }
            $owner = $this->cards->usedSuffixes($supplierId)[$suffix] ?? null;
            if ($owner !== null && $owner !== $cardId) {
                throw new PostingException('analytic_taken', 'Analytiku ' . $accountCode . ' už používá jiná karta.', 409, ['field' => 'account_code']);
            }
        }
        $old = $card['analytic_suffix'] ?? null;
        if (is_string($old) && $old !== '' && $old !== $suffix && !$confirm) {
            $oldCode = CardClearingAccounts::codeFor($synthetic, $old);
            $balance = $this->accounts->balance($supplierId, $oldCode);
            if (abs($balance) >= 0.005) {
                throw new PostingException('confirm_required', sprintf(
                    'Analytika %s má zůstatek %s Kč. Zůstane tam — nová analytika platí jen pro nové platby.',
                    $oldCode,
                    number_format($balance, 2, ',', ' '),
                ), 409, ['balance' => $balance, 'account_code' => $oldCode]);
            }
        }
        $this->cards->setAnalyticSuffix($supplierId, $cardId, $suffix);
        return (array) $this->cards->find($supplierId, $cardId);
    }

    /**
     * Mezičlen karty pro detail: kód analytiky, id účtu (proklik na obraty) a zůstatek
     * (= nedoložené platby kartou).
     *
     * @param array<string,mixed> $card
     * @return array{account_code:?string, account_id:?int, balance:float, synthetic:string, enabled:bool, options:list<array<string,mixed>>}
     */
    public function cardClearing(int $supplierId, array $card): array
    {
        $settings = $this->regime->settings($supplierId);
        $synthetic = (string) $settings['clearing_synthetic'];
        $base = [
            'synthetic' => $synthetic,
            'enabled'   => (bool) $settings['enabled'],
            // Nabídka ručního výběru: existující analytiky mezičlenu, které nedrží jiná karta.
            'options'   => array_values(array_filter(
                $this->accounts->analyticOptions($supplierId, $synthetic),
                static fn (array $o): bool => $o['card_id'] === null || $o['card_id'] === (int) $card['id'],
            )),
        ];
        $suffix = $card['analytic_suffix'] ?? null;
        if (!is_string($suffix) || $suffix === '') {
            return ['account_code' => null, 'account_id' => null, 'balance' => 0.0] + $base;
        }
        $code = CardClearingAccounts::codeFor($synthetic, $suffix);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        return [
            'account_code' => $code,
            'account_id'   => $id !== false ? (int) $id : null,
            'balance'      => $id !== false ? $this->accounts->balance($supplierId, $code) : 0.0,
        ] + $base;
    }

    /** Začátek prvního otevřeného účetního období firmy (výchozí datum účinnosti). */
    public function defaultEffectiveFrom(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT MIN(starts_on) FROM accounting_periods WHERE supplier_id = ? AND status = 'open'"
        );
        $stmt->execute([$supplierId]);
        $v = $stmt->fetchColumn();
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Výchozí účet uzavření bez dokladu: nedaňová analytika 548, jinak 548. */
    public function defaultWriteoffCode(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT account_code FROM chart_of_accounts
              WHERE supplier_id = ? AND is_active = 1 AND account_code LIKE '548.%'
                AND tax_deductibility = 'non_deductible'
              ORDER BY account_code LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $v = $stmt->fetchColumn();
        return is_string($v) && $v !== '' ? $v : CardClearingSettingsRepository::DEFAULT_CODES['writeoff'];
    }

    /** Součet zůstatků analytik karet pod syntetikou. */
    public function clearingBalance(int $supplierId, string $synthetic): float
    {
        $total = 0.0;
        foreach (array_keys($this->cards->usedSuffixes($supplierId)) as $suffix) {
            $total += $this->accounts->balance($supplierId, CardClearingAccounts::codeFor($synthetic, (string) $suffix));
        }
        $total += $this->accounts->balance($supplierId, CardClearingAccounts::codeFor($synthetic, CardClearingAccounts::FALLBACK_SUFFIX));
        return round($total, 2);
    }

    private function unverifiedCount(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payment_cards WHERE supplier_id = ? AND is_verified = 0 AND archived_at IS NULL'
        );
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param list<string> $prefixes */
    private function assertAccount(int $supplierId, int $id, array $prefixes, string $field): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT account_code FROM chart_of_accounts WHERE id = ? AND supplier_id = ? AND is_active = 1'
        );
        $stmt->execute([$id, $supplierId]);
        $code = $stmt->fetchColumn();
        if (!is_string($code)) {
            throw new PostingException('invalid_account', 'Účet nepatří do účtové osnovy firmy.', 422, ['field' => $field]);
        }
        foreach ($prefixes as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return;
            }
        }
        throw new PostingException('invalid_account', 'Účet ' . $code . ' se pro toto pole nehodí.', 422, ['field' => $field]);
    }

    private function isDoubleEntry(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (string) $stmt->fetchColumn() === 'double_entry';
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}

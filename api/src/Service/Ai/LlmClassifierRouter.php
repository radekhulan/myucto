<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Psr\Log\LoggerInterface;

final class LlmClassifierRouter implements LlmClassifierInterface
{
    private const FORBIDDEN_PREFIXES = ['311', '321', '314', '324', '325', '33', '34'];
    private const FORBIDDEN_PURCHASE_PREFIXES = ['211', '221'];
    private const OPERATION_TYPES = ['fee', 'interest', 'tax', 'insurance', 'rent', 'subscription', 'purchase', 'income', 'other'];

    /**
     * Tvar kódu účtu, který smí model vrátit: syntetika (3–6 číslic) volitelně s analytikou
     * za tečkou (`221.100`, `518.990`) — stejný tvar, jaký osnova a PostingRuleChartAlignmentService
     * berou jako analytiku. Dřívější `^[0-9]{3,6}$` zahazoval každou analytiku s tečkou, přestože
     * ji model dostal v allowed_accounts: bankovní účet namapovaný na `221.100` tak končil vždy
     * `invalid_accounts` (a eskalací stál dvě volání). Rozhoduje stejně přesná shoda s rozvrhem.
     */
    private const ACCOUNT_CODE_PATTERN = '^[0-9]{3,6}(?:[.][0-9]{1,6})?$';

    // Minimální použitelná jistota (po škálování ×0.40): raw model confidence < 0.3 → pod
    // touto hranicí je návrh nepoužitelný a bank_tx kontace se jednorázově eskaluje na Sonnet.
    private const ESCALATION_MIN_CONFIDENCE = 0.12;

    public function __construct(
        private readonly Connection $db,
        private readonly AiProviderHttpClient $client,
        private readonly LoggerInterface $logger,
    ) {}

    public function classifyBankTransaction(int $supplierId, array $sanitizedTx, array $fewShot): array
    {
        $accounts = $this->allowedAccounts($supplierId);
        $codes = $this->codesOf($accounts);
        $schema = $this->bankSchema();
        $result = $this->classifyBankOnce($supplierId, $sanitizedTx, $fewShot, $accounts, $codes, $schema, null);
        if ($this->bankResultUsable($result)) {
            return $result;
        }
        // §12b — Haiku vrátil prázdný/nevalidní/nízkojistý návrh: jednorázově (žádná smyčka)
        // eskaluj na silnější model a použij jeho odpověď. Scoped jen na anthropic haiku.
        $stronger = $this->client->bankEscalationModel($supplierId);
        if ($stronger === null) {
            return $result;
        }
        $this->logger->info('AI bank_tx kontace — auto-escalace Haiku→Sonnet', [
            'supplier_id' => $supplierId,
            'to_model' => $stronger,
            'reason' => (string) ($result['error'] ?? 'low_confidence'),
        ]);
        $escalated = $this->classifyBankOnce($supplierId, $sanitizedTx, $fewShot, $accounts, $codes, $schema, $stronger);
        $escalated['escalated'] = true;
        return $escalated;
    }

    /**
     * @param list<array{code:string,name:string,type:string}> $accounts
     * @param list<string> $codes
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    private function classifyBankOnce(int $supplierId, array $sanitizedTx, array $fewShot, array $accounts, array $codes, array $schema, ?string $modelOverride): array
    {
        $result = $this->client->completeJson(
            $supplierId,
            'Jste účetní asistent pro podvojné účetnictví v ČR. Pouze navrhujete kontaci, nic neúčtujete.'
                . ' Použijte výhradně účty z allowed_accounts.'
                . ' NÁZVY účtů v allowed_accounts jsou ZÁVAZNÉ a mají přednost před vaší obecnou znalostí směrné'
                . ' účtové osnovy — rozvrh je u každé firmy jiný, takže totéž číslo může znamenat něco jiného,'
                . ' než jste zvyklí. Nikdy nepoužijte účet, jehož NÁZEV neodpovídá povaze operace, ani kdyby'
                . ' vám jeho číslo připadalo obvyklé. Náklad patří na účet s type=expense, výnos na type=revenue.'
                . ' Směr transaction.direction je autoritativní: pro in musí být bankovní účet 221 na MD, pro out na D.'
                . ' Vlastní převod účtujte přes 261; pro příchozí nohu MD 221 / D 261, pro odchozí MD 261 / D 221.'
                . ' Pokud si nejste jistí, vraťte confidence pod 0.3.',
            json_encode(['transaction' => $sanitizedTx, 'examples' => array_slice($fewShot, 0, 5), 'allowed_accounts' => $accounts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            $schema,
            $modelOverride,
        );
        if (($result['ok'] ?? false) !== true || !is_array($result['data'] ?? null)) {
            return $result;
        }
        $data = $result['data'];
        $debit = trim((string) ($data['debit_account'] ?? ''));
        $credit = trim((string) ($data['credit_account'] ?? ''));
        $direction = (string) ($sanitizedTx['direction'] ?? '');
        $oriented = BankAccountPairNormalizer::orient($direction, $debit, $credit);
        $debit = $oriented['debit'];
        $credit = $oriented['credit'];
        if (!$this->validAccountPair($codes, $debit, $credit)
            || ($direction === 'in' && !str_starts_with($debit, '221'))
            || ($direction === 'out' && !str_starts_with($credit, '221'))) {
            return ['ok' => false, 'error' => 'invalid_accounts'];
        }
        $operation = (string) ($data['operation_type'] ?? 'other');
        if (!in_array($operation, self::OPERATION_TYPES, true)) {
            $operation = 'other';
        }
        // Druhá, nezávislá vrstva: u operací, které jsou z definice nákladem, vynuť účet typu
        // expense. Chytí halucinaci názvu i tehdy, když model rozvrh v promptu ignoruje —
        // 065 „Dluhové cenné papíry" je type=asset, takže by pojistné neprošlo.
        if (!$this->expenseTypeMatches($accounts, $operation, $direction, $debit)) {
            return ['ok' => false, 'error' => 'invalid_accounts'];
        }
        return [
            'ok' => true,
            'debit' => $debit,
            'credit' => $credit,
            'operation_type' => $operation,
            'confidence' => round(0.40 * max(0.0, min(1.0, (float) ($data['confidence'] ?? 0))), 2),
            'reasoning' => mb_substr(trim((string) ($data['reasoning'] ?? '')), 0, 300),
            'model' => $result['model'] ?? null,
            'provider' => $result['provider'] ?? null,
            'region' => $result['region'] ?? null,
            'usage' => $result['usage'] ?? null,
        ];
    }

    /** @param array<string,mixed> $result */
    private function bankResultUsable(array $result): bool
    {
        return ($result['ok'] ?? false) === true
            && (float) ($result['confidence'] ?? 0) >= self::ESCALATION_MIN_CONFIDENCE;
    }

    public function suggestPurchasePosting(int $supplierId, array $sanitizedPf, array $fewShot, array $tenantCategories): array
    {
        $accounts = $this->allowedAccounts($supplierId);
        $codes = $this->codesOf($accounts);
        $result = $this->client->completeJson(
            $supplierId,
            'Jste účetní asistent pro přijaté faktury v podvojném účetnictví v ČR. Pouze navrhujete nákladový'
                . ' nebo majetkový účet. NÁZVY účtů v allowed_accounts jsou ZÁVAZNÉ a mají přednost před vaší'
                . ' obecnou znalostí směrné účtové osnovy — rozvrh je u každé firmy jiný, takže totéž číslo může'
                . ' znamenat něco jiného. Nikdy nepoužijte účet, jehož NÁZEV neodpovídá povaze plnění.'
                . ' Pro chybějící kategorii vraťte expense_category_id 0. Pokud si nejste jistí, vraťte confidence pod 0.3.',
            json_encode(['invoice' => $sanitizedPf, 'examples' => array_slice($fewShot, 0, 5), 'allowed_accounts' => $accounts, 'expense_categories' => $tenantCategories], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            $this->purchaseSchema(),
        );
        if (($result['ok'] ?? false) !== true || !is_array($result['data'] ?? null)) {
            return $result;
        }
        $data = $result['data'];
        $debit = trim((string) ($data['debit_account'] ?? ''));
        if (!in_array($debit, $codes, true) || $this->forbiddenPurchase($debit)) {
            return ['ok' => false, 'error' => 'invalid_accounts'];
        }
        $categoryId = isset($data['expense_category_id']) && is_numeric($data['expense_category_id'])
            ? (int) $data['expense_category_id'] : null;
        if ($categoryId !== null && ($categoryId <= 0 || !array_filter($tenantCategories, static fn (array $c): bool => (int) ($c['id'] ?? 0) === $categoryId))) {
            $categoryId = null;
        }
        return [
            'ok' => true, 'debit' => $debit, 'expense_category_id' => $categoryId,
            'confidence' => round(0.40 * max(0.0, min(1.0, (float) ($data['confidence'] ?? 0))), 2),
            'reasoning' => mb_substr(trim((string) ($data['reasoning'] ?? '')), 0, 300),
            'model' => $result['model'] ?? null, 'provider' => $result['provider'] ?? null,
            'region' => $result['region'] ?? null, 'usage' => $result['usage'] ?? null,
        ];
    }

    /**
     * Účtový rozvrh firmy pro prompt — kód, NÁZEV a typ. Samotné kódy nestačí: účtová osnova je
     * per-tenant a číslo samo o sobě nenese význam. Model si pak dosadí obecnou směrnou osnovu
     * a vymyslí si název (nález: u dotazu na pojištění telefonu vrátil „065 (Pojistné)", zatímco
     * 065 je v rozvrhu „Dluhové cenné papíry držené do splatnosti" → pojistné by se zaúčtovalo
     * jako finanční majetek). Validace to nechytí — účet v rozvrhu existuje a směr sedí.
     *
     * @return list<array{code:string, name:string, type:string}>
     */
    private function allowedAccounts(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT account_code, name, account_type FROM chart_of_accounts
              WHERE supplier_id = ? AND is_active = 1 ORDER BY account_code'
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = (string) $row['account_code'];
            if ($this->forbidden($code)) {
                continue;
            }
            $out[] = ['code' => $code, 'name' => (string) $row['name'], 'type' => (string) $row['account_type']];
        }
        return $out;
    }

    /**
     * @param list<array{code:string, name:string, type:string}> $accounts
     * @return list<string>
     */
    private function codesOf(array $accounts): array
    {
        return array_map(static fn (array $a): string => $a['code'], $accounts);
    }

    /**
     * Operace, které jsou z podstaty nákladem — u odchozí platby musí protistrana banky být
     * účet type=expense. Záměrně NEobsahuje 'purchase' (pořízení majetku legitimně míří na
     * aktivum) ani 'tax'/'other' (odvod jde na závazkový účet, jehož prefix stejně vylučuje
     * FORBIDDEN_PREFIXES).
     */
    private const EXPENSE_OPERATIONS = ['fee', 'insurance', 'rent', 'subscription'];

    /** @param list<array{code:string, name:string, type:string}> $accounts */
    private function expenseTypeMatches(array $accounts, string $operation, string $direction, string $debit): bool
    {
        if ($direction !== 'out' || !in_array($operation, self::EXPENSE_OPERATIONS, true)) {
            return true;
        }
        foreach ($accounts as $a) {
            if ($a['code'] === $debit) {
                return $a['type'] === 'expense';
            }
        }
        return false;
    }

    /** @param list<string> $allowed */
    private function validAccountPair(array $allowed, string $debit, string $credit): bool
    {
        return preg_match('/' . self::ACCOUNT_CODE_PATTERN . '/D', $debit) === 1
            && preg_match('/' . self::ACCOUNT_CODE_PATTERN . '/D', $credit) === 1
            && BankAccountPairNormalizer::hasExactlyOneBankSide($debit, $credit)
            && in_array($debit, $allowed, true) && in_array($credit, $allowed, true)
            && !$this->forbidden($debit) && !$this->forbidden($credit);
    }

    private function forbidden(string $code): bool
    {
        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function forbiddenPurchase(string $code): bool
    {
        if ($this->forbidden($code)) {
            return true;
        }
        foreach (self::FORBIDDEN_PURCHASE_PREFIXES as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function bankSchema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['debit_account', 'credit_account', 'operation_type', 'confidence', 'reasoning'],
            'properties' => [
                'debit_account' => ['type' => 'string', 'pattern' => self::ACCOUNT_CODE_PATTERN],
                'credit_account' => ['type' => 'string', 'pattern' => self::ACCOUNT_CODE_PATTERN],
                'operation_type' => ['type' => 'string', 'enum' => self::OPERATION_TYPES],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'reasoning' => ['type' => 'string', 'maxLength' => 300],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function purchaseSchema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['debit_account', 'expense_category_id', 'confidence', 'reasoning'],
            'properties' => [
                'debit_account' => ['type' => 'string', 'pattern' => self::ACCOUNT_CODE_PATTERN],
                'expense_category_id' => ['type' => 'integer', 'minimum' => 0],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'reasoning' => ['type' => 'string', 'maxLength' => 300],
            ],
        ];
    }
}

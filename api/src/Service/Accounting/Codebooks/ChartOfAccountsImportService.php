<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Codebooks;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\ChartOfAccounts504Policy;
use MyInvoice\Service\Accounting\ChartOfAccountsTemplate504;

/**
 * Import účtové osnovy z XLSX/CSV (Epic F5 §4.4). Identita řádku = account_code
 * per firma. Update mění jen name + is_active (shodně s ChartOfAccountsAction::update);
 * account_type/normal_side/parent jsou importem neměnné (nesoulad = error). Analytiky
 * dědí typ/stranu z rodiče (rodič v DB nebo výše v souboru). Deaktivace referencovaného
 * účtu projde s warningem (R12). Import NIKDY nemaže.
 */
final class ChartOfAccountsImportService extends AbstractCodebookImportService
{
    private ?string $accountingDate = null;
    private ?int $metadataActor = null;

    /** normalizovaný alias => kanonický account_type */
    private const TYPE_ALIASES = [
        'asset' => 'asset', 'aktiva' => 'asset',
        'liability' => 'liability', 'pasiva' => 'liability', 'zavazky' => 'liability',
        'equity' => 'equity', 'vlastni kapital' => 'equity',
        'revenue' => 'revenue', 'vynosy' => 'revenue',
        'expense' => 'expense', 'naklady' => 'expense',
        'offbalance' => 'offbalance', 'podrozvaha' => 'offbalance',
        // 'closing' přibyl schématem po F4 (uzávěrkové účty tř. 7) — spec §4.2 ho
        // nevyjmenoval, ale round-trip osnovy ho musí přijmout.
        'closing' => 'closing', 'uzaverkove' => 'closing', 'zaverkove' => 'closing',
    ];

    /** normalizovaný alias => kanonický normal_side */
    private const SIDE_ALIASES = [
        'debit' => 'debit', 'md' => 'debit',
        'credit' => 'credit', 'd' => 'credit', 'dal' => 'credit',
    ];

    public function __construct(
        private readonly ChartOfAccountsRepository $accounts,
        private readonly Connection $db,
    ) {}

    public function import(int $supplierId, ?int $userId, string $content, string $filename, bool $dryRun, ?string $date = null): array
    {
        $previousDate = $this->accountingDate;
        $previousActor = $this->metadataActor;
        $this->metadataActor = $userId;
        $this->accountingDate = $date;
        try {
            return parent::import($supplierId, $userId, $content, $filename, $dryRun);
        } catch (\MyInvoice\Service\Accounting\AccountDefinitionException $e) {
            throw new CodebookImportException($e->errorCode,$e->getMessage(),$e->httpStatus);
        } finally {
            $this->accountingDate = $previousDate;
            $this->metadataActor = $previousActor;
        }
    }

    public static function columns(): array
    {
        return [
            'code'   => ['header' => 'ucet', 'aliases' => ['account_code', 'kod', 'code', 'účet'],
                         'required' => 'ano', 'note' => 'max 10 znaků; identita řádku'],
            'name'   => ['header' => 'nazev', 'aliases' => ['name', 'název'],
                         'required' => 'nový účet: ano', 'note' => 'max 190'],
            'type'   => ['header' => 'typ', 'aliases' => ['account_type', 'type'],
                         'required' => 'nový syntetický: ano',
                         'note' => 'asset|liability|equity|revenue|expense|offbalance (+ CZ: aktiva, pasiva/zavazky, vlastni kapital, vynosy, naklady, podrozvaha); u existujícího jen kontrola'],
            'side'   => ['header' => 'strana', 'aliases' => ['normal_side', 'side'],
                         'required' => 'ne', 'note' => 'debit|credit|prázdné (+ md/d/dal); u existujícího jen kontrola'],
            'parent' => ['header' => 'nadrizeny_ucet', 'aliases' => ['parent_code', 'parent', 'nadřízený'],
                         'required' => 'analytika: ano', 'note' => 'přítomen ⇒ analytika; rodič musí být syntetický (v DB nebo výše v souboru)'],
            'active' => ['header' => 'aktivni', 'aliases' => ['is_active', 'active'],
                         'required' => 'ne (default 1)', 'note' => '1/0, ano/ne, yes/no, true/false'],
        ];
    }

    protected function requiredHeaderKeys(): array
    {
        return ['code'];
    }

    protected function process(int $supplierId, array $map, array $rows, bool $dryRun): array
    {
        $pdo = $this->db->pdo();
        $framework = (new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db)))
            ->forDate($supplierId, $this->accountingDate ?? date('Y-m-d'));

        $physicalCodes=$this->accounts->physicalCodeMap($supplierId);
        $dbByCode = [];
        $dbById = [];
        foreach ($this->accounts->listForTenant($supplierId, true, $this->accountingDate) as $acc) {
            $dbByCode[(string) $acc['account_code']] = $acc;
            $dbById[(int) $acc['id']] = $acc;
        }

        // Pass 1 — parse + standalone validace.
        $parsed = [];
        foreach ($rows as $line => $cols) {
            $parsed[$line] = $this->parseRow($cols, $map);
        }

        // Pass 2 — parent vazby + create/update/skip, v pořadí souboru (parent „výše").
        $reportRows = [];
        $writers = [];
        $seen = [];                 // code => true (duplicity v souboru)
        $availableSynthetic = [];   // code => account_type/side pro rodiče vzniklé výše v souboru
        foreach ($dbByCode as $code => $acc) {
            if ($acc['is_synthetic']) {
                $availableSynthetic[$code] = ['type' => (string) $acc['account_type'], 'side' => $acc['normal_side']];
            }
        }
        $idResolver = [];
        foreach ($dbByCode as $code => $acc) {
            $idResolver[$code] = (int) $acc['id'];
        }

        ksort($parsed);
        foreach ($parsed as $line => $p) {
            $code = $p['code'];
            $row = ['line' => $line, 'key' => $code, 'status' => 'skip'];
            if(isset($physicalCodes[$code])&&!isset($dbByCode[$code])){$reportRows[$line]=$this->err($row,'Účet existuje až od pozdějšího data. Zvolte odpovídající datum importu.');continue;}

            if ($code === '') {
                $reportRows[$line] = $this->err($row, 'Chybí kód účtu.');
                continue;
            }
            if (mb_strlen($code) > 10) {
                $reportRows[$line] = $this->err($row, 'Kód účtu „' . $code . '" je delší než 10 znaků.');
                continue;
            }
            if (isset($seen[$code])) {
                $reportRows[$line] = $this->err($row, 'Účet „' . $code . '" je v souboru vícekrát.');
                continue;
            }
            $seen[$code] = true;

            if ($p['error'] !== null) {
                $reportRows[$line] = $this->err($row, $p['error']);
                continue;
            }

            $existing = $dbByCode[$code] ?? null;
            $isAnalytic = $p['parent'] !== '';
            if ($framework === AccountingFrameworkService::CZ_504) {
                $parent = $isAnalytic ? ($availableSynthetic[$p['parent']] ?? null) : null;
                $type = $isAnalytic ? ($parent['type'] ?? $p['type']) : ($p['type'] ?? $existing['account_type'] ?? null);
                $default = ChartOfAccounts504Policy::defaultAccount($isAnalytic ? $p['parent'] : $code);
                $side = $isAnalytic ? ($parent['side'] ?? null)
                    : ($p['sideRaw'] !== '' ? $p['side'] : ($existing !== null ? $existing['normal_side'] : ($default['normal_side'] ?? null)));
                $error = ChartOfAccounts504Policy::validationError($code, $type, $side, $isAnalytic ? $p['parent'] : null);
                if ($error !== null) {
                    $reportRows[$line] = $this->err($row, $error);
                    continue;
                }
                if (!$isAnalytic && $existing === null && $p['sideRaw'] === '') {
                    $p['side'] = $side;
                }
                $p['tax_deductibility'] = ChartOfAccountsTemplate504::taxDeductibility($code);
            }

            if (!$isAnalytic) {
                $reportRows[$line] = $this->handleSynthetic($supplierId, $pdo, $row, $p, $existing, $writers, $availableSynthetic, $idResolver);
            } else {
                $reportRows[$line] = $this->handleAnalytic($supplierId, $pdo, $row, $p, $existing, $dbById, $writers, $availableSynthetic, $idResolver);
            }
        }

        return $this->summarize($dryRun, $reportRows, $writers, $pdo);
    }

    /**
     * @param array<string,int> $map
     * @param list<string> $cols
     * @return array{code:string, name:string, typeRaw:string, type:?string, sideRaw:string, side:?string,
     *               parent:string, active:?bool, error:?string}
     */
    private function parseRow(array $cols, array $map): array
    {
        $code = $this->col($cols, $map, 'code');
        $name = $this->col($cols, $map, 'name');
        $typeRaw = $this->col($cols, $map, 'type');
        $sideRaw = $this->col($cols, $map, 'side');
        $parent = $this->col($cols, $map, 'parent');
        $activeRaw = $this->col($cols, $map, 'active');

        $error = null;
        $type = null;
        if ($typeRaw !== '') {
            $type = self::parseEnum($typeRaw, self::TYPE_ALIASES);
            if ($type === null) {
                $error = 'Neznámý typ účtu „' . $typeRaw . '".';
            }
        }
        $side = null;
        if ($error === null && $sideRaw !== '') {
            $side = self::parseEnum($sideRaw, self::SIDE_ALIASES);
            if ($side === null) {
                $error = 'Neznámá strana účtu „' . $sideRaw . '" (debit|credit|md|d|dal).';
            }
        }
        $active = null;
        if ($error === null && $activeRaw !== '') {
            $active = self::parseBool($activeRaw);
            if ($active === null) {
                $error = 'Neplatná hodnota „aktivni": „' . $activeRaw . '".';
            }
        }

        return compact('code', 'name', 'typeRaw', 'type', 'sideRaw', 'side', 'parent', 'active', 'error');
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $p
     * @param array<string,mixed>|null $existing
     * @param list<callable():void> $writers
     * @param array<string,array{type:string,side:?string}> $availableSynthetic
     * @param array<string,int> $idResolver
     */
    private function handleSynthetic(int $supplierId, \PDO $pdo, array $row, array $p, ?array $existing, array &$writers, array &$availableSynthetic, array &$idResolver): array
    {
        $code = $p['code'];

        if ($existing !== null) {
            if (!$existing['is_synthetic']) {
                return $this->err($row, 'Účet „' . $code . '" je analytika — nadřízený účet nelze importem odebrat.');
            }
            if ($p['typeRaw'] !== '' && (string) $existing['account_type'] !== $p['type']) {
                return $this->err($row, 'Účet ' . $code . ' existuje s typem ' . $existing['account_type']
                    . ', soubor uvádí ' . $p['type'] . ' — typ nelze měnit importem.');
            }
            if ($p['sideRaw'] !== '' && (string) ($existing['normal_side'] ?? '') !== (string) ($p['side'] ?? '')) {
                return $this->err($row, 'Účet ' . $code . ' má stranu ' . ($existing['normal_side'] ?? 'prázdnou')
                    . ', soubor uvádí ' . ($p['side'] ?? 'prázdnou') . ' — stranu nelze měnit importem.');
            }
            return $this->applyUpdate($supplierId, $pdo, $row, $p, $existing, $writers);
        }

        // create syntetického
        if ($p['type'] === null) {
            return $this->err($row, 'Typ účtu je povinný pro nový syntetický účet ' . $code . '.');
        }
        if ($p['name'] === '') {
            return $this->err($row, 'Název je povinný pro nový účet ' . $code . '.');
        }
        $data = [
            'account_code' => $code,
            'name'         => $p['name'],
            'account_type' => $p['type'],
            'normal_side'  => $p['side'],
            'is_synthetic' => true,
            'parent_id'    => null,
            'is_active'    => $p['active'] ?? true,
            'tax_deductibility' => $p['tax_deductibility'] ?? 'deductible',
        ];
        $availableSynthetic[$code] = ['type' => $p['type'], 'side' => $p['side']];
        $writers[] = function () use ($supplierId, $data, $code, &$idResolver): void {
            $idResolver[$code] = $this->accounts->insert($supplierId, $data, $this->accountingDate, $this->metadataActor);
        };
        $row['status'] = 'create';
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $p
     * @param array<string,mixed>|null $existing
     * @param array<int,array<string,mixed>> $dbById
     * @param list<callable():void> $writers
     * @param array<string,array{type:string,side:?string}> $availableSynthetic
     * @param array<string,int> $idResolver
     */
    private function handleAnalytic(int $supplierId, \PDO $pdo, array $row, array $p, ?array $existing, array $dbById, array &$writers, array &$availableSynthetic, array &$idResolver): array
    {
        $code = $p['code'];
        $parentCode = $p['parent'];

        if (!isset($availableSynthetic[$parentCode])) {
            return $this->err($row, 'Nadřízený účet „' . $parentCode . '" pro ' . $code
                . ' neexistuje nebo není syntetický (musí být v DB nebo výše v souboru).');
        }
        $parentType = $availableSynthetic[$parentCode]['type'];
        $parentSide = $availableSynthetic[$parentCode]['side'];

        $message = null;
        if ($p['typeRaw'] !== '' && $p['type'] !== $parentType) {
            $message = 'Typ analytiky se dědí z rodiče (' . $parentType . '); hodnota ze souboru byla ignorována.';
        }

        if ($existing !== null) {
            if ($existing['is_synthetic']) {
                return $this->err($row, 'Účet ' . $code . ' existuje jako syntetický — nelze jej importem změnit na analytiku.');
            }
            $existingParentCode = $existing['parent_id'] !== null && isset($dbById[(int) $existing['parent_id']])
                ? (string) $dbById[(int) $existing['parent_id']]['account_code']
                : '';
            if ($existingParentCode !== $parentCode) {
                return $this->err($row, 'Nadřízený účet u ' . $code . ' nelze měnit importem.');
            }
            $res = $this->applyUpdate($supplierId, $pdo, $row, $p, $existing, $writers);
            if ($message !== null && $res['status'] !== 'error') {
                $res['message'] = isset($res['message']) ? $res['message'] . ' ' . $message : $message;
            }
            return $res;
        }

        if ($p['name'] === '') {
            return $this->err($row, 'Název je povinný pro nový účet ' . $code . '.');
        }
        $data = [
            'account_code' => $code,
            'name'         => $p['name'],
            'account_type' => $parentType,
            'normal_side'  => $parentSide,
            'is_synthetic' => false,
            'is_active'    => $p['active'] ?? true,
            'tax_deductibility' => $p['tax_deductibility'] ?? 'deductible',
        ];
        $writers[] = function () use ($supplierId, $data, $parentCode, &$idResolver): void {
            $d = $data;
            $d['parent_id'] = $idResolver[$parentCode] ?? null;
            $this->accounts->insert($supplierId, $d, $this->accountingDate, $this->metadataActor);
        };
        $row['status'] = 'create';
        if ($message !== null) {
            $row['message'] = $message;
        }
        return $row;
    }

    /**
     * Update existujícího účtu — mění jen name + is_active. Warning při deaktivaci
     * referencovaného účtu (R12).
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $p
     * @param array<string,mixed> $existing
     * @param list<callable():void> $writers
     */
    private function applyUpdate(int $supplierId, \PDO $pdo, array $row, array $p, array $existing, array &$writers): array
    {
        $changes = [];
        $data = [];

        if ($p['name'] !== '' && $p['name'] !== (string) $existing['name']) {
            $changes['name'] = ['from' => (string) $existing['name'], 'to' => $p['name']];
            $data['name'] = $p['name'];
        }
        $activeTarget = $p['active'] ?? (bool) $existing['is_active'];
        if ($activeTarget !== (bool) $existing['is_active']) {
            $changes['is_active'] = ['from' => (bool) $existing['is_active'], 'to' => $activeTarget];
            $data['is_active'] = $activeTarget;
        }

        if ($data === []) {
            $row['status'] = 'skip';
            return $row;
        }

        $row['status'] = 'update';
        $row['changes'] = $changes;

        if ($activeTarget === false && (bool) $existing['is_active'] === true
            && $this->isReferenced($pdo, $supplierId, (string) $existing['account_code'])) {
            $row['message'] = 'Účet je referencován aktivní kontací nebo nevyřazeným majetkem — deaktivace může rozbít zaúčtování.';
        }

        $id = (int) $existing['id'];
        $writers[] = function () use ($supplierId, $id, $data): void {
            $this->accounts->update($supplierId, $id, $data, $this->accountingDate, null, $this->metadataActor);
        };
        return $row;
    }

    private function isReferenced(\PDO $pdo, int $supplierId, string $code): bool
    {
        $r = $pdo->prepare(
            'SELECT 1 FROM posting_rules
              WHERE (supplier_id = ? OR supplier_id IS NULL) AND is_active = 1
                AND (debit_account_code = ? OR credit_account_code = ?) LIMIT 1'
        );
        $r->execute([$supplierId, $code, $code]);
        if ($r->fetchColumn() !== false) {
            return true;
        }
        $a = $pdo->prepare(
            "SELECT 1 FROM assets
              WHERE supplier_id = ? AND status <> 'disposed'
                AND (asset_account_code = ? OR accumulated_account_code = ? OR acquisition_account_code = ?) LIMIT 1"
        );
        $a->execute([$supplierId, $code, $code, $code]);
        return $a->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $row */
    private function err(array $row, string $message): array
    {
        $row['status'] = 'error';
        $row['message'] = $message;
        unset($row['changes']);
        return $row;
    }
}

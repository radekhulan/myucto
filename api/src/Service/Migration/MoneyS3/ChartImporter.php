<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;

/**
 * Účtová osnova: z Money se přenáší jen to, na co se v deníku účtovalo.
 *
 * Firma má osnovu ze standardní šablony MyÚčta; analytiky z Money se pod ni doplní
 * (`042000` → `042.000` pod syntetikou `042`) s názvem z `UcOsnova.DAT`. Existující účet
 * se stejným kódem se použije tak, jak je. Syntetika, kterou šablona nemá, se založí
 * s typem převzatým od sourozence ze stejné skupiny (první dvě číslice) — bez sourozence
 * typ účtu (aktivum/pasivum/náklad) odhadovat nejde a převod skončí chybou.
 */
final class ChartImporter
{
    public const STEP = 'chart';

    public function __construct(
        private readonly ChartOfAccountsRepository $accounts,
        private readonly ChartOfAccountsSeeder $seeder,
    ) {}

    public function run(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        if ($this->accounts->count($ctx->supplierId) === 0) {
            $seeded = $this->seeder->seedForSupplier($ctx->supplierId);
            $p->setCount(self::STEP, 'seeded', $seeded);
        }

        $names = [];
        foreach ($ctx->backup->rowsAcrossYears('UcOsnova') as $r) {
            $code = trim((string) ($r['Ucet'] ?? ''));
            $name = trim((string) ($r['Nazev'] ?? ''));
            if ($code !== '' && ctype_digit($code) && $name !== '') {
                $names[$code] = $name;
            }
        }

        $used = [];
        foreach ($ctx->backup->rowsAcrossYears('UcDenik') as $r) {
            foreach (['UcMD', 'UcD'] as $k) {
                $code = trim((string) ($r[$k] ?? ''));
                if ($code !== '') {
                    $used[$code] = true;
                }
            }
        }

        $map = $this->accounts->codeToIdMap($ctx->supplierId);
        $ctx->accountIds = [];
        foreach ($map as $code => $row) {
            $ctx->accountIds[(string) $code] = $row['id'];
        }

        foreach (array_keys($used) as $raw) {
            $moneyCode = (string) $raw;
            $target = AccountCode::fromMoney($moneyCode);
            if ($target === null) {
                $p->error(self::STEP, 'invalid_account', "Deník Money účtuje na účet „{$moneyCode}\", který není číselný kód účtu.", ['account' => $moneyCode]);
                continue;
            }
            if (isset($ctx->accountIds[$target])) {
                $p->count(self::STEP, 'existing');
                continue;
            }
            $synthetic = substr($target, 0, 3);
            $parent = $this->accounts->findByCode($ctx->supplierId, $synthetic);
            if ($parent === null) {
                $parent = $this->createSynthetic($ctx, $synthetic, $names);
                if ($parent === null) {
                    continue;
                }
            }
            $id = $this->accounts->insert($ctx->supplierId, [
                'account_code' => $target,
                'name' => mb_substr($names[$moneyCode] ?? ($names[str_replace('.', '', $target)] ?? ('Analytika ' . $moneyCode)), 0, 190),
                'account_type' => (string) $parent['account_type'],
                'normal_side' => $parent['normal_side'] ?? null,
                'is_synthetic' => false,
                'parent_id' => (int) $parent['id'],
                'is_active' => true,
            ]);
            $ctx->accountIds[$target] = $id;
            $p->count(self::STEP, 'created');
        }
        $p->finish(self::STEP);
    }

    /**
     * @param array<string,string> $names
     * @return array<string,mixed>|null
     */
    private function createSynthetic(ImportContext $ctx, string $synthetic, array $names): ?array
    {
        $sibling = null;
        foreach ($this->accounts->listForTenant($ctx->supplierId, true) as $row) {
            if (!empty($row['is_synthetic']) && str_starts_with((string) $row['account_code'], substr($synthetic, 0, 2))) {
                $sibling = $row;
                break;
            }
        }
        if ($sibling === null) {
            $ctx->protocol->error(
                self::STEP,
                'unknown_synthetic',
                "Syntetický účet {$synthetic} v osnově chybí a nelze odvodit jeho typ. Založte ho v Účetní osnově a spusťte převod znovu.",
                ['account' => $synthetic],
            );
            return null;
        }
        $id = $this->accounts->insert($ctx->supplierId, [
            'account_code' => $synthetic,
            'name' => mb_substr($names[$synthetic . '000'] ?? ('Účet ' . $synthetic), 0, 190),
            'account_type' => (string) $sibling['account_type'],
            'normal_side' => $sibling['normal_side'] ?? null,
            'is_synthetic' => true,
            'parent_id' => null,
            'is_active' => true,
        ]);
        $ctx->accountIds[$synthetic] = $id;
        $ctx->protocol->warn(
            self::STEP,
            'synthetic_created',
            "Syntetický účet {$synthetic} v osnově chyběl, založen s typem podle účtu {$sibling['account_code']}. Zkontrolujte jeho zařazení do výkazů.",
            ['account' => $synthetic, 'type_from' => $sibling['account_code']],
        );
        return $this->accounts->findById($ctx->supplierId, $id);
    }
}

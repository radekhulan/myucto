<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Migration\Shared\ChartAccountCreator;

/**
 * Účtová osnova: z Money se přenáší jen to, na co se v deníku účtovalo.
 *
 * Firma má osnovu ze standardní šablony MyÚčta; analytiky z Money se pod ni doplní
 * (`042000` → `042.000` pod syntetikou `042`) s názvem z `UcOsnova.DAT`. Existující účet
 * se stejným kódem se použije tak, jak je. Syntetika, kterou šablona nemá, se založí
 * s typem převzatým od sourozence ze stejné skupiny (první dvě číslice), jinak ze stejné
 * třídy — bez sourozence typ účtu (aktivum/pasivum/náklad) odhadovat nejde a převod
 * skončí chybou.
 */
final class ChartImporter
{
    public const STEP = 'chart';

    public function __construct(
        private readonly ChartOfAccountsRepository $accounts,
        private readonly ChartOfAccountsSeeder $seeder,
        private readonly MoneyS3ImportRepository $map,
    ) {}

    public function run(ImportContext $ctx, ?string $seedDate = null): void
    {
        $p = $ctx->protocol;
        if ($this->accounts->count($ctx->supplierId) === 0) {
            $seeded = $this->seeder->seedForSupplier($ctx->supplierId, $seedDate);
            $p->setCount(self::STEP, 'seeded', $seeded);
        }

        $names = [];
        $deductibility = [];
        foreach ($ctx->backup->rowsAcrossYears('UcOsnova') as $r) {
            $code = trim((string) ($r['Ucet'] ?? ''));
            $name = trim((string) ($r['Nazev'] ?? ''));
            if ($code !== '' && ctype_digit($code) && $name !== '') {
                $names[$code] = $name;
            }
            $flag = self::deductibility($code, $r);
            if ($flag !== null) {
                $deductibility[$code] = $flag;
            }
        }

        $used = [];
        foreach ($ctx->backup->rowsAcrossYears('UcDenik', ['Zdroj', 'UcMD', 'UcD']) as $r) {
            if (Ms3Journal::isYearEndClosing($r)) {
                continue;
            }
            foreach (['UcMD', 'UcD'] as $k) {
                $code = trim((string) ($r[$k] ?? ''));
                if ($code !== '') {
                    $used[$code] = true;
                }
            }
        }

        // Firma převedená dřív se účtuje dál v MyÚčtu: daňovou uznatelnost účtů, které už má,
        // mohla účetní upravit (v Money bývá „nedaňový" typ i u běžného nákladu), opakovaný
        // převod ji proto nepřepisuje. Nové účty ji dostanou z Money.
        $alreadyConverted = $this->map->hasAny($ctx->supplierId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY);
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
                // Opravný ani opakovaný převod nastavení účtů, které mohla účetní mezitím změnit, nepřepisuje.
                if (!$ctx->options->relinkOnly && !$alreadyConverted) {
                    $this->alignDeductibility($ctx, $target, $deductibility[$moneyCode] ?? null);
                }
                continue;
            }
            if ($ctx->skipsNew(self::STEP)) {
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
            $id = (new ChartAccountCreator($this->accounts))->createAnalytic(
                $ctx->supplierId, $target, $names[$moneyCode] ?? ($names[str_replace('.', '', $target)] ?? ('Analytika ' . $moneyCode)), $parent,
            );
            $ctx->accountIds[$target] = $id;
            $p->count(self::STEP, 'created');
            $this->alignDeductibility($ctx, $target, $deductibility[$moneyCode] ?? null);
        }
        $nonDeductible = [];
        foreach (array_keys($used) as $raw) {
            if (($deductibility[(string) $raw] ?? null) === 'non_deductible') {
                $nonDeductible[] = AccountCode::fromMoney((string) $raw) . ' ' . ($names[(string) $raw] ?? '');
            }
        }
        if ($nonDeductible !== []) {
            sort($nonDeductible);
            // Účetní v Money občas nechá „nedaňový" typ i u běžného nákladu — v DPPO ř. 40
            // by se to projevilo celým obratem, proto seznam na očích.
            $p->info(self::STEP, 'non_deductible_accounts', 'Daňově neuznatelné náklady podle osnovy Money (DPPO ř. 40): '
                . implode(', ', array_map('trim', $nonDeductible)) . '. Ověřte proti podanému přiznání.', ['accounts' => $nonDeductible]);
        }
        $p->finish(self::STEP);
    }

    /**
     * Daňová uznatelnost nákladového účtu podle osnovy Money: `Typ` 1 = daňový, 0 = nedaňový
     * (513, 543, 545, „nedaňové" analytiky…). Bez ní by DPPO ř. 40 nevidělo nic. Účty 59x
     * (daň z příjmů, převody) jsou v Money „nedaňové" také, ale do základu daně nevstupují
     * vůbec — výpočet DPPO je řeší sám. Starší záloha bez sloupce `Typ` = nic nepřebírat.
     *
     * @param array<string,mixed> $row
     */
    public static function deductibility(string $moneyCode, array $row): ?string
    {
        if (!ctype_digit($moneyCode) || $moneyCode[0] !== '5' || str_starts_with($moneyCode, '59') || !array_key_exists('Typ', $row)) {
            return null;
        }
        return (int) $row['Typ'] === 0 ? 'non_deductible' : 'deductible';
    }

    /** Money rozhoduje i u účtu, který už je ve firmě (ze šablony nebo z dřívějšího převodu). */
    private function alignDeductibility(ImportContext $ctx, string $target, ?string $flag): void
    {
        if ($flag === null) {
            return;
        }
        $row = $this->accounts->findById($ctx->supplierId, $ctx->accountIds[$target]);
        if ($row !== null && (string) ($row['tax_deductibility'] ?? 'deductible') !== $flag) {
            $this->accounts->setTaxDeductibility($ctx->supplierId, $ctx->accountIds[$target], $flag);
        }
        if ($flag === 'non_deductible') {
            $ctx->protocol->count(self::STEP, 'non_deductible');
        }
    }

    /**
     * @param array<string,string> $names
     * @return array<string,mixed>|null
     */
    private function createSynthetic(ImportContext $ctx, string $synthetic, array $names): ?array
    {
        $created = (new ChartAccountCreator($this->accounts))
            ->createSynthetic($ctx->supplierId, $synthetic, $names[$synthetic . '000'] ?? ('Účet ' . $synthetic), $ctx->protocol, self::STEP, true);
        if ($created === null) {
            return null;
        }
        $ctx->accountIds[$synthetic] = $created['id'];
        return $this->accounts->findById($ctx->supplierId, $created['id']);
    }
}

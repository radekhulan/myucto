<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;

/**
 * Doplnění osnovy o účty převzatého deníku: firma má osnovu ze šablony MyÚčta, převod
 * pod ni doplní analytiky zdroje a chybějící syntetiky.
 *
 * Syntetika, kterou šablona nemá, dostane typ od sourozence ze stejné skupiny (první dvě
 * číslice), jinak ze stejné třídy: skupiny zrušené osnovou (61x změna stavu zásob) ve
 * starých letech zůstávají a šablona je nemá, přitom třída typ účtu určuje. Bez
 * sourozence typ (aktivum/pasivum/náklad) odhadovat nejde a převod hlásí chybu.
 */
final class ChartAccountCreator
{
    public function __construct(private readonly ChartOfAccountsRepository $accounts) {}

    /**
     * Založí chybějící syntetický účet s typem podle sourozence.
     *
     * @param bool $typeFromInContext přidat do kontextu varování `type_from` (Money S3)
     * @param string|null $sourceAccountType ověřený typ ze zdroje místo odhadu ze sourozence
     * @param bool $useSourceNormalSide použít i null ze zdroje místo strany sourozence
     * @return array{id:int,sibling:string}|null null = sourozenec není, chyba je v protokolu
     */
    public function createSynthetic(int $supplierId, string $synthetic, string $name, ImportProtocol $protocol, string $step, bool $typeFromInContext = false, ?string $sourceAccountType = null, ?string $sourceNormalSide = null, bool $useSourceNormalSide = false, ?string $taxDeductibility = null): ?array
    {
        $sibling = null;
        if ($sourceAccountType === null) {
            foreach ([2, 1] as $prefix) {
                foreach ($this->accounts->listForTenant($supplierId, true) as $row) {
                    if (!empty($row['is_synthetic']) && str_starts_with((string) $row['account_code'], substr($synthetic, 0, $prefix))) {
                        $sibling = $row;
                        break 2;
                    }
                }
            }
            if ($sibling === null) {
                $protocol->error($step, 'unknown_synthetic', "Syntetický účet {$synthetic} v osnově chybí a nelze odvodit jeho typ. Založte ho v Účetní osnově a spusťte převod znovu.", ['account' => $synthetic]);
                return null;
            }
        }
        $id = $this->accounts->insert($supplierId, [
            'account_code' => $synthetic,
            'name' => mb_substr($name, 0, 190),
            'account_type' => $sourceAccountType ?? (string) $sibling['account_type'],
            'normal_side' => $useSourceNormalSide ? $sourceNormalSide : ($sibling['normal_side'] ?? null),
            'is_synthetic' => true,
            'parent_id' => null,
            'is_active' => true,
            'tax_deductibility' => $taxDeductibility ?? 'deductible',
        ]);
        if ($sibling !== null) {
            $protocol->warn($step, 'synthetic_created', "Syntetický účet {$synthetic} v osnově chyběl, založen s typem podle účtu {$sibling['account_code']}. Zkontrolujte jeho zařazení do výkazů.",
                $typeFromInContext ? ['account' => $synthetic, 'type_from' => $sibling['account_code']] : ['account' => $synthetic]);
        }
        return ['id' => $id, 'sibling' => (string) ($sibling['account_code'] ?? $synthetic)];
    }

    /**
     * Analytika pod syntetikou `$parent` (typ a strana účtu od ní).
     *
     * @param array<string,mixed> $parent řádek syntetického účtu
     * @param bool $useSourceNormalSide použít i null ze zdroje místo strany rodiče
     */
    public function createAnalytic(int $supplierId, string $code, string $name, array $parent, ?string $sourceNormalSide = null, bool $useSourceNormalSide = false, ?string $taxDeductibility = null): int
    {
        return $this->accounts->insert($supplierId, [
            'account_code' => $code,
            'name' => mb_substr($name, 0, 190),
            'account_type' => (string) $parent['account_type'],
            'normal_side' => $useSourceNormalSide ? $sourceNormalSide : ($parent['normal_side'] ?? null),
            'is_synthetic' => false,
            'parent_id' => (int) $parent['id'],
            'is_active' => true,
            'tax_deductibility' => $taxDeductibility ?? 'deductible',
        ]);
    }
}

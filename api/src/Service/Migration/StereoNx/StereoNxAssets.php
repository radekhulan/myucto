<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\SmallAsset\SmallAssetService;
use MyInvoice\Service\Migration\Shared\MigratedDepreciation;
use MyInvoice\Service\Migration\Shared\ImportedAccountCode;
use MyInvoice\Service\Migration\Shared\SmallAssetCard;

/**
 * Karty majetku Stereo NX bez opakovaného účtování historických pohybů.
 *
 * Uplatněné odpisy přecházejí do počátečních oprávek karty. Řádky odpisů ani
 * zařazení se znovu nematerializují, protože je převádí účetní deník. Počet
 * uplatněných daňových odpisů se počítá po zdaňovacích obdobích firmy
 * (kalendářní, nebo hospodářský rok), stejně jako je čte evidence majetku.
 */
final class StereoNxAssets
{
    private const ASSET_KIND = 'asset';
    private const SMALL_ASSET_KIND = 'small_asset';

    public function __construct(
        private readonly StereoNxImportMap $map,
        private readonly AssetService $assets,
        private readonly SmallAssetService $smallAssets,
        private readonly Connection $db,
    ) {}

    /** @return array<string,mixed> */
    public function prepare(StereoNxBackup $backup, ?FiscalCalendar $calendar = null): array
    {
        $tables = [];
        foreach (['JMajetek', 'JDrobMaj', 'JDanOdpisy', 'JUcOdpisy', 'JTechZhod'] as $table) {
            $tables[$table] = in_array($table, $backup->tableNames(), true)
                ? iterator_to_array($backup->rows($table), false)
                : [];
        }
        if (!in_array('JMajetek', $backup->tableNames(), true)
            || !in_array('JDrobMaj', $backup->tableNames(), true)
        ) {
            throw new StereoNxException('asset_tables_missing', 'V záloze chybí evidence dlouhodobého nebo drobného majetku.');
        }

        return self::fromTables($tables, $backup->companyIdentity(), $backup->companyIndex(), $calendar);
    }

    /**
     * Čistý převod zdrojových tabulek, veřejný kvůli syntetickým regresním testům.
     *
     * @param array<string,list<array<string,mixed>>> $tables
     * @param array{ico:string,dic?:string,name?:string,vat_payer?:bool} $identity
     * @return array<string,mixed>
     */
    public static function fromTables(array $tables, array $identity, int $companyIndex, ?FiscalCalendar $calendar = null): array
    {
        $calendar ??= FiscalCalendar::calendar();
        $ico = trim((string) ($identity['ico'] ?? ''));
        if ($ico === '' || $companyIndex < 0) {
            throw new StereoNxException('asset_source_identity_invalid', 'Majetek nemá jednoznačnou identitu zdrojové firmy.');
        }

        $warnings = [];
        $counts = [
            'assets_source' => count($tables['JMajetek'] ?? []),
            'assets_ready' => 0,
            'assets_skipped' => 0,
            'small_assets_source' => count($tables['JDrobMaj'] ?? []),
            'small_assets_ready' => 0,
            'small_assets_skipped' => 0,
            'tax_depreciation_applied' => 0,
            'accounting_depreciation_applied' => 0,
            'technical_improvements_skipped' => count($tables['JTechZhod'] ?? []),
        ];

        $tax = self::depreciationByCard($tables['JDanOdpisy'] ?? [], 'tax', $calendar, $warnings, $counts);
        $accounting = self::depreciationByCard($tables['JUcOdpisy'] ?? [], 'accounting', $calendar, $warnings, $counts);
        $taxSource = self::sourceRowsByCard($tables['JDanOdpisy'] ?? []);
        $accountingSource = self::sourceRowsByCard($tables['JUcOdpisy'] ?? []);
        $assets = [];
        $assetKeys = [];
        foreach ($tables['JMajetek'] ?? [] as $row) {
            $number = self::text($row['InvCislo'] ?? null);
            if ($number === '') {
                self::skip($counts, $warnings, 'assets_skipped', 'asset_identity_missing', 'Karta dlouhodobého majetku bez inventárního čísla nebyla převedena.');
                continue;
            }
            if (isset($assetKeys[$number])) {
                throw new StereoNxException('asset_identity_duplicate', 'Evidence dlouhodobého majetku obsahuje duplicitní inventární číslo.');
            }
            $assetKeys[$number] = true;
            $exact = null;
            if (self::text($row['ZpusobDanOdepisovani'] ?? null) === 'M') {
                $hasImprovements = array_any($tables['JTechZhod'] ?? [],
                    static fn (array $change): bool => in_array(self::text($change['InvCislo'] ?? null), ['', $number], true));
                try {
                    $exact = StereoNxAssetDepreciationPlan::build($row,
                        [...($taxSource[$number] ?? []), ...($taxSource[''] ?? [])],
                        [...($accountingSource[$number] ?? []), ...($accountingSource[''] ?? [])], $calendar, $hasImprovements);
                } catch (StereoNxException $e) {
                    self::skip($counts, $warnings, 'assets_skipped', $e->errorCode,
                        "Karta dlouhodobého majetku {$number} nebyla převedena: " . $e->getMessage());
                    continue;
                }
            }
            $built = self::longTermCard($row, $tax[$number] ?? [], $accounting[$number] ?? [], $warnings, $exact);
            if ($built === null) {
                $counts['assets_skipped']++;
                continue;
            }
            $record = ['source_key' => $number, 'card' => $built];
            if ($exact !== null) {
                $record['imported_depreciation_plan'] = $exact['future'];
                $record['source_depreciation_plan'] = $exact['source'];
            }
            $record['source_hash'] = StereoNxImportMap::fingerprint($record);
            $assets[] = $record;
            $counts['assets_ready']++;
        }
        self::warnOrphanDepreciation($tax, $assetKeys, 'tax', $warnings);
        self::warnOrphanDepreciation($accounting, $assetKeys, 'accounting', $warnings);

        $small = [];
        $smallKeys = [];
        foreach ($tables['JDrobMaj'] ?? [] as $row) {
            $number = self::text($row['InvCislo'] ?? null);
            if ($number === '') {
                self::skip($counts, $warnings, 'small_assets_skipped', 'small_asset_identity_missing', 'Karta drobného majetku bez inventárního čísla nebyla převedena.');
                continue;
            }
            if (isset($smallKeys[$number])) {
                throw new StereoNxException('small_asset_identity_duplicate', 'Evidence drobného majetku obsahuje duplicitní inventární číslo.');
            }
            $smallKeys[$number] = true;
            $card = self::smallCard($row, $warnings);
            if ($card === null) {
                $counts['small_assets_skipped']++;
                continue;
            }
            $record = ['source_key' => $number, 'card' => $card];
            $record['source_hash'] = StereoNxImportMap::fingerprint($record);
            $small[] = $record;
            $counts['small_assets_ready']++;
        }

        if ($counts['technical_improvements_skipped'] > 0) {
            $warnings[] = self::warning('warning', 'asset_improvements_not_imported',
                'Nelze ověřit, zda technické zhodnocení již zahrnuje převzatá vstupní cena; samostatným přičtením by se mohlo zdvojit. '
                . 'Zkontrolujte ručně; počet záznamů: ' . $counts['technical_improvements_skipped'] . '.');
        }

        return [
            'identity' => $identity,
            'company_index' => $companyIndex,
            'counts' => $counts,
            'warnings' => $warnings,
            'records' => ['assets' => $assets, 'small_assets' => $small],
        ];
    }

    /** @param array<string,mixed> $plan @return array<string,int> */
    public function write(array $plan, int $supplierId, int $userId): array
    {
        $ico = trim((string) ($plan['identity']['ico'] ?? ''));
        $companyIndex = (int) ($plan['company_index'] ?? -1);
        if ($supplierId <= 0 || $ico === '' || $companyIndex < 0) {
            throw new StereoNxException('asset_write_context_invalid', 'Chybí kontext firmy pro zápis majetku.');
        }
        $written = ['assets_created' => 0, 'assets_existing' => 0,
            'small_assets_created' => 0, 'small_assets_existing' => 0];
        $accountCodes = $this->map->accountCodes($supplierId, $ico, $companyIndex);

        foreach ((array) ($plan['records']['assets'] ?? []) as $record) {
            if ($this->alreadyImported($supplierId, $ico, $companyIndex, self::ASSET_KIND, $record)) {
                $written['assets_existing']++;
                continue;
            }
            try {
                $meta = ['user_id' => $userId > 0 ? $userId : null];
                $card = (array) $record['card'];
                foreach (['asset_account_code', 'acquisition_account_code', 'accumulated_account_code'] as $field) {
                    if (is_string($card[$field] ?? null)) {
                        $card[$field] = ImportedAccountCode::target($card[$field], $accountCodes);
                    }
                }
                $created = isset($record['imported_depreciation_plan'])
                    ? $this->assets->createImported($supplierId, $card, (array) $record['imported_depreciation_plan'], $meta)
                    : $this->assets->create($supplierId, $card, $meta);
            } catch (AssetException $e) {
                throw new StereoNxException('asset_write_rejected', 'Kartu dlouhodobého majetku cílová evidence odmítla: ' . $e->getMessage());
            }
            $id = (int) ($created['asset']['id'] ?? 0);
            $this->map->put($supplierId, $ico, $companyIndex, self::ASSET_KIND,
                (string) $record['source_key'], (string) $record['source_hash'], $id);
            $written['assets_created']++;
        }

        foreach ((array) ($plan['records']['small_assets'] ?? []) as $record) {
            if ($this->alreadyImported($supplierId, $ico, $companyIndex, self::SMALL_ASSET_KIND, $record)) {
                $written['small_assets_existing']++;
                continue;
            }
            try {
                $id = $this->smallAssets->create($supplierId, (array) $record['card'], $userId > 0 ? $userId : null);
            } catch (PostingException $e) {
                throw new StereoNxException('small_asset_write_rejected', 'Kartu drobného majetku cílová evidence odmítla: ' . $e->getMessage());
            }
            $this->map->put($supplierId, $ico, $companyIndex, self::SMALL_ASSET_KIND,
                (string) $record['source_key'], (string) $record['source_hash'], $id);
            $written['small_assets_created']++;
        }
        return $written;
    }

    /** @param array<string,mixed> $record */
    private function alreadyImported(int $supplierId, string $ico, int $companyIndex, string $kind, array $record): bool
    {
        $existing = $this->map->get($supplierId, $ico, $companyIndex, $kind, (string) ($record['source_key'] ?? ''));
        if ($existing === null) return false;
        if (!hash_equals($existing['source_hash'], (string) ($record['source_hash'] ?? ''))) {
            throw new StereoNxException('asset_source_changed', 'Zdrojová karta majetku se od předchozího převodu změnila.');
        }
        $table = $kind === self::ASSET_KIND ? 'assets' : 'small_assets';
        $stmt = $this->db->pdo()->prepare("SELECT 1 FROM {$table} WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$existing['target_id'], $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new StereoNxException('asset_target_missing', 'Dříve převedená karta majetku už v cílové firmě neexistuje.');
        }
        return true;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $tax @param array<string,mixed> $accounting */
    private static function longTermCard(array $row, array $tax, array $accounting, array &$warnings, ?array $exact = null): ?array
    {
        $number = self::text($row['InvCislo'] ?? null);
        $name = self::text($row['Nazev'] ?? null);
        $kind = self::assetKind($row['Typ'] ?? null);
        $acquired = self::date($row['DatumPorizeni'] ?? null);
        $inUse = self::date($row['DatumZarazeni'] ?? null);
        $price = self::positiveMoney($row['CenaPorizovaci'] ?? null);
        $assetAccount = self::account($row['UcetMDZarazeni'] ?? null);
        $acquisitionAccount = self::account($row['UcetDalZarazeni'] ?? null);
        $accumulatedAccount = self::account($row['UcetDalOdpisu'] ?? null);
        if ($name === '' || $kind === null || $acquired === null || $price === null
            || $assetAccount === null || $acquisitionAccount === null
        ) {
            $warnings[] = self::warning('warning', 'asset_required_value_unsupported',
                "Karta dlouhodobého majetku {$number} nebyla převedena: chybí nebo není podporován povinný údaj.");
            return null;
        }
        if ($inUse !== null && $inUse < $acquired) {
            $warnings[] = self::warning('warning', 'asset_date_order_invalid',
                "Karta dlouhodobého majetku {$number} nebyla převedena: datum zařazení předchází pořízení.");
            return null;
        }

        $sourceTaxMethod = self::text($row['ZpusobDanOdepisovani'] ?? null);
        $taxMethod = match ($sourceTaxMethod) {
            'Z' => $kind === 'tangible' ? 'accelerated' : null,
            'R' => $kind === 'tangible' ? 'straight' : null,
            'N' => 'none',
            'M' => $exact !== null ? 'by_accounting' : null,
            default => null,
        };
        $taxGroup = filter_var($row['OdpisovaSkupina'] ?? null, FILTER_VALIDATE_INT);
        if ($taxMethod === null || (in_array($taxMethod, ['straight', 'accelerated'], true)
            && (!is_int($taxGroup) || $taxGroup < 1 || $taxGroup > 6))) {
            $warnings[] = self::warning('warning', 'asset_tax_method_unsupported',
                "Karta dlouhodobého majetku {$number} nebyla převedena: nepodporovaný způsob nebo skupina daňového odpisování.");
            return null;
        }

        $taxOpening = self::nonNegativeMoney($row['DanoveOdepsano'] ?? null);
        $accOpening = self::nonNegativeMoney($row['UcetneOdepsano'] ?? null);
        if ($taxOpening === null || $accOpening === null || $taxOpening > $price || $accOpening > $price
            || abs(($tax['applied_sum'] ?? 0.0) - $taxOpening) >= 0.01
            || abs(($accounting['applied_sum'] ?? 0.0) - $accOpening) >= 0.01
        ) {
            $warnings[] = self::warning('warning', 'asset_depreciation_not_reconciled',
                "Karta dlouhodobého majetku {$number} nebyla převedena: součet uplatněných odpisů nesouhlasí s kartou.");
            return null;
        }

        $review = [];
        $taxSchedule = $tax['schedule'] ?? [];
        $accountingSchedule = $accounting['schedule'] ?? [];
        $samePlan = $taxSchedule !== [] && $taxSchedule === $accountingSchedule;
        $emptyPlan = $taxSchedule === [] && $accountingSchedule === [];
        $accMethod = $exact !== null ? 'straight_line' : ($samePlan || $emptyPlan ? 'by_tax' : 'straight_line');
        $usefulLife = $exact['life_months'] ?? null;
        if ($exact === null && $accumulatedAccount !== null && $emptyPlan) {
            $review[] = 've Stereo chybí účetní i daňový odpisový plán';
            $warnings[] = self::warning('warning', 'asset_depreciation_plan_missing',
                "Karta dlouhodobého majetku {$number} byla převedena jako koncept: ve Stereo chybí odpisový plán.");
        } elseif ($exact === null && $accumulatedAccount !== null && !$samePlan) {
            $last = $accounting['last_date'] ?? null;
            if ($inUse === null || $last === null || $last < $inUse) {
                $warnings[] = self::warning('warning', 'asset_accounting_plan_unsupported',
                    "Karta dlouhodobého majetku {$number} nebyla převedena: účetní plán nelze bezpečně převést.");
                return null;
            }
            $usefulLife = max(1, MigratedDepreciation::monthsBetween($inUse, $last));
            $review[] = 'budoucí účetní odpisový plán Stereo není shodný s daňovým plánem';
        }
        if ($accumulatedAccount === null && ($accOpening > 0.0 || $taxMethod !== 'none')) {
            $warnings[] = self::warning('warning', 'asset_accumulated_account_missing',
                "Karta dlouhodobého majetku {$number} nebyla převedena: chybí účet oprávek.");
            return null;
        }
        if ($inUse === null) $review[] = 've Stereo chybí datum zařazení';
        $disposed = self::date($row['DatumVyrazeni'] ?? null);
        if (self::text($row['DatumVyrazeni'] ?? null) !== '' && $disposed === null) {
            $review[] = 've Stereo je neplatné datum vyřazení';
        } elseif ($disposed !== null) {
            $review[] = 'karta je ve Stereo vyřazená ' . $disposed . '; vyřazení proveďte po kontrole ručně';
        }
        $increase = (int) ($row['ZvyseniSazProc'] ?? 0);
        $firstIncrease = match ($increase) { 0 => 'none', 10 => 'p10', 15 => 'p15', 20 => 'p20', default => null };
        if ($firstIncrease === null) {
            $warnings[] = self::warning('warning', 'asset_first_year_increase_unsupported',
                "Karta dlouhodobého majetku {$number} nebyla převedena: nepodporované zvýšení odpisu v prvním roce.");
            return null;
        }

        $notes = array_filter([
            'Převzato ze Stereo NX.',
            self::text($row['Poznamka'] ?? null),
            self::text($row['VyrobniCislo'] ?? null) !== '' ? 'Výrobní číslo: ' . self::text($row['VyrobniCislo']) . '.' : null,
            $review !== [] ? 'Ke kontrole: ' . implode('; ', $review) . '.' : null,
        ]);
        return [
            'inventory_number' => mb_substr($number, 0, 30),
            'name' => mb_substr($name, 0, 255),
            'description' => implode(' ', $notes),
            'kind' => $kind,
            'asset_account_code' => $assetAccount,
            'acquisition_account_code' => $acquisitionAccount,
            'accumulated_account_code' => $accumulatedAccount,
            'input_price' => $price,
            'acquisition_date' => $acquired,
            'put_into_use_date' => $inUse,
            'status' => $review === [] ? 'in_use' : 'draft',
            'tax_method' => $taxMethod,
            'tax_group' => in_array($taxMethod, ['straight', 'accelerated'], true) ? $taxGroup : null,
            'tax_first_year_increase' => $firstIncrease,
            'is_first_owner' => $firstIncrease !== 'none',
            'opening_tax_years' => $exact['opening_years'] ?? (int) ($tax['applied_count'] ?? 0),
            'opening_tax_amount' => $taxOpening,
            'opening_acc_months' => $exact['opening_months'] ?? ($inUse !== null && ($accounting['last_applied'] ?? null) !== null
                ? max(0, MigratedDepreciation::monthsBetween($inUse, (string) $accounting['last_applied'])) : 0),
            'opening_acc_amount' => $accOpening,
            'acc_method' => $accMethod,
            'acc_useful_life_months' => $usefulLife,
            'acc_residual_value' => 0.0,
        ];
    }

    /** @param array<string,mixed> $row */
    private static function smallCard(array $row, array &$warnings): ?array
    {
        $number = self::text($row['InvCislo'] ?? null);
        $name = self::text($row['Nazev'] ?? null);
        $kind = self::assetKind($row['Typ'] ?? null);
        $acquired = self::date($row['DatumPorizeni'] ?? null);
        $inUse = self::date($row['DatumZarazeni'] ?? null);
        $unitPrice = self::positiveMoney($row['JednCena'] ?? null);
        $quantity = is_numeric($row['Mnozstvi'] ?? null) ? round((float) $row['Mnozstvi'], 3) : 0.0;
        if ($name === '' || $kind === null || $acquired === null || $unitPrice === null || $quantity <= 0.0
            || ($inUse !== null && $inUse < $acquired)) {
            $warnings[] = self::warning('warning', 'small_asset_required_value_unsupported',
                "Karta drobného majetku {$number} nebyla převedena: chybí nebo není podporován povinný údaj.");
            return null;
        }
        $disposed = self::date($row['DatumVyrazeni'] ?? null);
        if (self::text($row['DatumVyrazeni'] ?? null) !== '' && ($disposed === null || $disposed < $acquired)) {
            $warnings[] = self::warning('warning', 'small_asset_disposal_invalid',
                "Karta drobného majetku {$number} nebyla převedena: neplatné datum vyřazení.");
            return null;
        }
        $notes = array_filter([
            'Převzato ze Stereo NX.', self::text($row['Poznamka'] ?? null),
            self::text($row['VyrobniCislo'] ?? null) !== '' ? 'Výrobní číslo: ' . self::text($row['VyrobniCislo']) . '.' : null,
        ]);
        $reason = implode(' ', array_filter([
            self::text($row['ZpusobVyrazeni'] ?? null), self::text($row['DruhVyrazeni'] ?? null),
            self::text($row['DokladVyrazeni'] ?? null) !== '' ? 'Doklad ' . self::text($row['DokladVyrazeni']) : null,
        ]));
        return SmallAssetCard::payload(
            $kind,
            $name,
            SmallAssetCard::inventoryNumber($number, false),
            $acquired,
            $inUse,
            $quantity,
            $unitPrice,
            round($unitPrice * $quantity, 2),
            self::nullableShort($row['Pracoviste'] ?? null, 160),
            $disposed,
            $disposed === null ? null : mb_substr($reason !== '' ? $reason : 'Vyřazeno ve Stereo NX', 0, 255),
            implode(' ', $notes),
            [
                'document_ref' => self::nullableShort($row['DokladPorizeni'] ?? null, 60),
                'vendor_name' => self::nullableShort($row['Firma'] ?? null, 255),
                'responsible_person' => self::nullableShort($row['Pracovnik'] ?? null, 160),
            ],
        );
    }

    /** @param list<array<string,mixed>> $rows @param list<array{level:string,code:string,message:string}> $warnings @param array<string,int> $counts */
    private static function depreciationByCard(array $rows, string $kind, FiscalCalendar $calendar, array &$warnings, array &$counts): array
    {
        $out = [];
        foreach ($rows as $row) {
            $number = self::text($row['InvCislo'] ?? null);
            $date = self::date($row['Datum'] ?? null);
            $amount = self::nonNegativeMoney($row['Castka'] ?? null);
            if ($number === '' || $date === null || $amount === null) {
                $warnings[] = self::warning('warning', 'asset_depreciation_row_invalid', 'Řádek odpisu s neplatnou identitou, datem nebo částkou nebyl použit.');
                continue;
            }
            $appliedRaw = self::text($row['DatumUplatneni'] ?? null);
            $applied = $appliedRaw !== '';
            if ($applied && self::date($appliedRaw) === null) {
                $warnings[] = self::warning('warning', 'asset_depreciation_applied_date_invalid', 'Řádek odpisu s neplatným datem uplatnění nebyl použit.');
                continue;
            }
            $out[$number]['schedule'][$date] = $amount;
            $out[$number]['last_date'] = max((string) ($out[$number]['last_date'] ?? ''), $date);
            if ($applied) {
                $out[$number]['applied_sum'] = round((float) ($out[$number]['applied_sum'] ?? 0.0) + $amount, 2);
                $out[$number]['applied_years'][$calendar->fiscalYearOfDate($date)] = true;
                $out[$number]['last_applied'] = max((string) ($out[$number]['last_applied'] ?? ''), $date);
                $counts[$kind . '_depreciation_applied']++;
            }
        }
        foreach ($out as &$item) {
            $item['applied_sum'] ??= 0.0;
            $item['applied_count'] = count($item['applied_years'] ?? []);
            unset($item['applied_years']);
            ksort($item['schedule']);
        }
        unset($item);
        return $out;
    }

    private static function warnOrphanDepreciation(array $plans, array $cardKeys, string $kind, array &$warnings): void
    {
        $count = count(array_diff_key($plans, $cardKeys));
        if ($count > 0) {
            $warnings[] = self::warning('warning', 'asset_' . $kind . '_depreciation_orphan',
                'Odpisové řádky bez odpovídající karty nebyly převedeny; počet karet: ' . $count . '.');
        }
    }

    /** @param list<array<string,mixed>> $rows @return array<string,list<array<string,mixed>>> */
    private static function sourceRowsByCard(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) $out[self::text($row['InvCislo'] ?? null)][] = $row;
        return $out;
    }

    private static function assetKind(mixed $value): ?string
    {
        return match (strtoupper(self::text($value))) { 'H' => 'tangible', 'N' => 'intangible', default => null };
    }

    private static function account(mixed $value): ?string
    {
        $value = self::text($value);
        return $value !== '' && strlen($value) <= 10 && preg_match('/^[0-9A-Za-z._-]+$/D', $value) === 1 ? $value : null;
    }

    private static function text(mixed $value): string { return is_scalar($value) ? trim((string) $value) : ''; }

    private static function date(mixed $value): ?string
    {
        $value = self::text($value);
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private static function positiveMoney(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (($v = round((float) $value, 2)) > 0.0 ? $v : null) : null;
    }

    private static function nonNegativeMoney(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (($v = round((float) $value, 2)) >= 0.0 ? $v : null) : null;
    }

    private static function nullableShort(mixed $value, int $length): ?string
    {
        $value = self::text($value);
        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    /** @return array{level:string,code:string,message:string} */
    private static function warning(string $level, string $code, string $message): array
    {
        return ['level' => $level, 'code' => $code, 'message' => $message];
    }

    /** @param array<string,int> $counts @param list<array{level:string,code:string,message:string}> $warnings */
    private static function skip(array &$counts, array &$warnings, string $count, string $code, string $message): void
    {
        $counts[$count]++;
        $warnings[] = self::warning('warning', $code, $message);
    }
}

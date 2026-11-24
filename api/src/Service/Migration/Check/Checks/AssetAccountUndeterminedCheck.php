<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check\Checks;

use MyInvoice\Service\Migration\Check\CheckItem;
use MyInvoice\Service\Migration\Check\CheckResult;
use MyInvoice\Service\Migration\Check\FixableMigrationDataCheck;
use MyInvoice\Service\Migration\Check\FixPreview;
use MyInvoice\Service\Migration\Check\FixResult;
use MyInvoice\Service\Migration\Check\MigrationSources;
use MyInvoice\Service\Migration\Check\SourceScopedCheck;
use MyInvoice\Service\Migration\Pohoda\PohodaAssetAccountRepair;

final class AssetAccountUndeterminedCheck extends SourceScopedCheck implements FixableMigrationDataCheck
{
    public const KEY = 'asset_account_undetermined';

    public function __construct(MigrationSources $sources, private readonly PohodaAssetAccountRepair $repair)
    {
        parent::__construct($sources);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return 'Neurčený účet majetku nebo oprávek na kartě z POHODY';
    }

    public function manual(): string
    {
        return self::MANUAL_CHAPTER . '#12614-neurceny-majetkovy-ucet-karty-z-pohody';
    }

    protected function sources(): array
    {
        return [MigrationSources::POHODA];
    }

    public function options(): array
    {
        return [];
    }

    public function detect(int $supplierId): CheckResult
    {
        return $this->result($this->repair->preview($supplierId));
    }

    public function preview(int $supplierId, array $options = []): FixPreview
    {
        return FixPreview::fromResult($this->detect($supplierId));
    }

    public function fix(int $supplierId, ?int $userId, array $options = [], ?string $fingerprint = null): FixResult
    {
        $plan = $this->repair->repair($supplierId, $userId, function (array $plan) use ($fingerprint): void {
            FixPreview::fromResult($this->result($plan))->assertConfirmed($fingerprint);
        });
        $preview = FixPreview::fromResult($this->result($plan));
        return new FixResult($preview->changes, $preview->skipped);
    }

    /** @param array{reason:?string,cards:list<array<string,mixed>>} $plan */
    private function result(array $plan): CheckResult
    {
        $items = [];
        foreach ($plan['cards'] as $card) {
            $account = $card['new_account'];
            $accumulated = $card['new_accumulated_account'];
            $changes = [];
            if ($account !== null) {
                $changes[] = sprintf('Majetkový účet %s → %s.', $card['asset_account_code'], $account);
            }
            if ($accumulated !== null) {
                $changes[] = sprintf('Účet oprávek %s → %s.', $card['accumulated_account_code'], $accumulated);
            }
            $items[] = new CheckItem('asset', $card['id'], $card['inventory_number'] . ' ' . $card['name'],
                null, $card['opening_price'], $changes === [] ? CheckItem::REVIEW : CheckItem::FIXABLE,
                $card['reason'] ?? implode(' ', $changes),
                details: [
                    'old_account' => $card['asset_account_code'],
                    'new_account' => $account,
                    'old_accumulated_account' => $card['accumulated_account_code'],
                    'new_accumulated_account' => $accumulated,
                    'description_hash' => $card['description'] === null ? null : hash('sha256', $card['description']),
                    'reason_key' => $card['reason_key'] ?? null,
                ] + ($card['reason_details'] ?? []));
        }
        return new CheckResult($items, $plan['reason'] === null ? [] : [$plan['reason']]);
    }
}

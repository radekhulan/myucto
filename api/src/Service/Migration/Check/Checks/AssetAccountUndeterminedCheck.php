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
        return 'Neurčený majetkový účet karty z POHODY';
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
            $items[] = new CheckItem('asset', $card['id'], $card['inventory_number'] . ' ' . $card['name'],
                null, $card['opening_price'], $account === null ? CheckItem::REVIEW : CheckItem::FIXABLE,
                $card['reason'] ?? sprintf('Na kartě se nastaví účet %s (nyní %s).', $account, $card['asset_account_code']),
                details: [
                    'old_account' => $card['asset_account_code'],
                    'new_account' => $account,
                    'description_hash' => hash('sha256', $card['description']),
                ]);
        }
        return new CheckResult($items, $plan['reason'] === null ? [] : [$plan['reason']]);
    }
}

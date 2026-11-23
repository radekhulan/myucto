<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check\Checks;

use MyInvoice\Service\Migration\Check\CheckItem;
use MyInvoice\Service\Migration\Check\CheckResult;
use MyInvoice\Service\Migration\Check\FixableMigrationDataCheck;
use MyInvoice\Service\Migration\Check\FixPreview;
use MyInvoice\Service\Migration\Check\FixResult;
use MyInvoice\Service\Migration\Check\MigrationDataCheckException;
use MyInvoice\Service\Migration\Check\MigrationSources;
use MyInvoice\Service\Migration\Check\SourceScopedCheck;
use MyInvoice\Service\Migration\Pohoda\PohodaForeignBankRepair;

/**
 * Kontrola 2: bankovní účet v cizí měně převedený z POHODY v Kč a výpisy nahrané po převodu
 * v jiné měně, než má účet.
 *
 * Výběr, převod účtu i pojistky (uzavřené a zamčené období, společná analytika, pohyby mimo
 * převod) jsou v opravě {@see PohodaForeignBankRepair}; kontrola její výsledek převede na
 * nálezy. Uzavřené období chrání plně: převod mění částky pohybů a měnové řádky deníku
 * analytiky 221 včetně otevíracího zápisu. Kontrola, náhled ani oprava kurzy ČNB nestahují
 * (uložení kurzu by přepočítalo ceníky i jiným firmám), pracují jen s uloženými kurzy;
 * chybějící kurz je nález s postupem doplnění. Výpisy nahrané po převodu přepne oprava jen
 * s volbou {@see OPTION_UPLOADED}.
 */
final class ForeignBankInCzkCheck extends SourceScopedCheck implements FixableMigrationDataCheck
{
    public const KEY = 'foreign_bank_in_czk';
    public const OPTION_UPLOADED = 'include_uploaded';

    public function __construct(
        MigrationSources $sources,
        private readonly PohodaForeignBankRepair $repair,
    ) {
        parent::__construct($sources);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return 'Bankovní účet v cizí měně převedený v Kč';
    }

    public function manual(): string
    {
        return self::MANUAL_CHAPTER . '#1264-bankovni-ucet-v-cizi-mene-prevedeny-v-kc';
    }

    protected function sources(): array
    {
        return [MigrationSources::POHODA];
    }

    public function options(): array
    {
        return [self::OPTION_UPLOADED => 'Přepnout na měnu účtu i výpisy nahrané po převodu, které k účtu podle souboru jednoznačně patří.'];
    }

    public function detect(int $supplierId): CheckResult
    {
        return $this->result($this->repair->run([$supplierId], false, false));
    }

    /** @param array<string,bool> $options */
    public function preview(int $supplierId, array $options = []): FixPreview
    {
        $preview = FixPreview::fromResult($this->result($this->repair->run([$supplierId], false, false)));
        if (empty($options[self::OPTION_UPLOADED])) {
            return $this->withoutUploaded($preview);
        }
        return $preview;
    }

    /** @param array<string,bool> $options */
    public function fix(int $supplierId, ?int $userId, array $options = [], ?string $fingerprint = null): FixResult
    {
        $uploaded = !empty($options[self::OPTION_UPLOADED]);
        $plan = $this->preview($supplierId, $options);
        $plan->assertConfirmed($fingerprint);
        $rows = $this->repair->run([$supplierId], true, false, $userId, $uploaded);
        $fixed = [];
        $skipped = [];
        $notes = [];
        foreach ($this->items($rows) as $item) {
            if (in_array($item->details['outcome'] ?? '', ['converted', 'rechained', 'switched'], true)) {
                $fixed[] = $item;
            } else {
                $skipped[] = $item;
            }
        }
        // Oprava účtu jede po účtech ve vlastním savepointu: selhání jednoho účtu nebo jiný
        // výsledek, než ukázal náhled, vrátí celou opravu (transakci drží volající).
        $planned = array_map(static fn (CheckItem $i): string => $i->entityType . ':' . $i->entityId, $plan->changes);
        $done = array_map(static fn (CheckItem $i): string => $i->entityType . ':' . $i->entityId, $fixed);
        sort($planned);
        sort($done);
        foreach ($rows as $r) {
            if ($r['status'] === 'failed') {
                throw new \RuntimeException(sprintf('Účet %s se neopravil: %s', $r['code'], (string) $r['reason']));
            }
            foreach ($r['uploaded'] ?? [] as $u) {
                if (($u['status'] ?? '') === 'failed') {
                    throw new \RuntimeException(sprintf('Výpis #%d se nepřepnul: %s', (int) $u['id'], (string) ($u['advice'] ?? '')));
                }
            }
        }
        if ($planned !== $done) {
            throw new MigrationDataCheckException('stale_preview', 'Oprava by změnila jiné účty, než ukázal náhled. Otevřete náhled opravy znovu.', 409);
        }
        return new FixResult($fixed, $skipped, $notes);
    }

    /** @param list<array<string,mixed>> $rows */
    private function result(array $rows): CheckResult
    {
        return new CheckResult($this->items($rows));
    }

    /**
     * @param list<array<string,mixed>> $rows řádky {@see PohodaForeignBankRepair::run()}
     * @return list<CheckItem>
     */
    private function items(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $label = sprintf('%s %s/%s', $r['code'], $r['account_number'], $r['bank_code']);
            $statementId = isset($r['statement_ids'][0]) ? (int) $r['statement_ids'][0] : null;
            $details = ['account' => (string) $r['code'], 'currency' => (string) $r['currency'], 'statements' => (int) $r['statements'],
                'transactions' => (int) $r['transactions'], 'match_pct' => isset($r['match_pct']) ? (float) $r['match_pct'] : null, 'outcome' => (string) $r['status']];
            $item = match ($r['status']) {
                'convert', 'converted' => new CheckItem('bank_statement', $statementId, $label, null, null, CheckItem::FIXABLE,
                    sprintf('Účet v %s převedený v Kč (shoda pohybů s kurzem ČNB %s %%). Oprava převede výpisy, pohyby, párování a deník analytiky na měnu účtu.',
                        $r['currency'], number_format((float) ($r['match_pct'] ?? 0), 1, ',', '')), null, (string) $r['currency'], $details),
                'rechain', 'rechained' => new CheckItem('bank_statement', $statementId, $label, null, null, CheckItem::FIXABLE,
                    'Korunový účet se stejným číslem jako účet v měně: stavy převzatých výpisů posunuté dorovnáním se spočtou znovu od deníku.', null, 'CZK', $details),
                'ambiguous', 'failed' => new CheckItem('bank_statement', $statementId, $label, null, null,
                    ($r['reason_code'] ?? null) === 'locked' ? CheckItem::LOCKED : CheckItem::REVIEW,
                    trim(ucfirst((string) $r['reason']) . '. ' . (string) ($r['advice'] ?? '')), null, (string) $r['currency'], $details),
                'czk' => ($r['reason_code'] ?? null) === 'hint_without_rates'
                    ? new CheckItem('bank_statement', $statementId, $label, null, null, CheckItem::REVIEW,
                        trim(ucfirst((string) $r['reason']) . '. ' . (string) ($r['advice'] ?? '')), null, 'CZK', $details)
                    : null,
                default => null,
            };
            if ($item !== null) {
                $out[] = $item;
            }
            foreach ($r['uploaded'] ?? [] as $u) {
                $state = match ($u['status']) {
                    'switch', 'switched' => CheckItem::FIXABLE,
                    'ok' => null,
                    default => CheckItem::REVIEW,
                };
                if ($state === null) {
                    continue;
                }
                $out[] = new CheckItem('bank_statement', (int) $u['id'],
                    sprintf('%s (%s)', $u['file_name'] !== '' ? $u['file_name'] : '#' . $u['id'], $u['statement_number'] ?? '-'),
                    substr((string) $u['statement_date'], 0, 10), null, $state,
                    $state === CheckItem::FIXABLE
                        ? sprintf('Nahraný výpis v %s patří k účtu v %s. Přepne se, potvrdíte-li volbu výpisů nahraných po převodu.', $u['currency'], $u['expected'] ?? '?')
                        : (string) ($u['advice'] ?? 'Ověřte výpis podle výpisu banky.'),
                    null, (string) $u['currency'], ['account' => (string) $r['code'], 'uploaded' => true, 'outcome' => (string) $u['status'], 'expected' => $u['expected'] ?? null]);
            }
        }
        return $out;
    }

    private function withoutUploaded(FixPreview $preview): FixPreview
    {
        $changes = [];
        $skipped = $preview->skipped;
        foreach ($preview->changes as $c) {
            if (!empty($c->details['uploaded'])) {
                $skipped[] = $c->withState(CheckItem::REVIEW, 'Přepne se jen s volbou výpisů nahraných po převodu.');
            } else {
                $changes[] = $c;
            }
        }
        return new FixPreview($changes, $skipped, $preview->notes, $preview->blocked);
    }
}

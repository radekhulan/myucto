<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

/** Mapování účtové osnovy a původních kontací ABRA Flexi. */
final class AbraJournalMapper
{
    /** @return array<string,mixed> */
    public function mapAccount(array $row): array
    {
        $code = AbraSource::account($row['kod'] ?? $row['ucet'] ?? $row['id'] ?? null);
        $sourceType = mb_strtolower(AbraSource::reference($row['typUctuK'] ?? $row['typUctu'] ?? $row['typ'] ?? null));
        [$type, $side] = $this->accountType($sourceType, $code);
        // Rozvahový účet nese stranu v druhu účtu (`druhUctu.aktivni` / `pasivni`). Bez něj
        // by se strana odvodila jen z třídy a pohledávky třídy 3 (311, 314, 378) by vyšly
        // jako závazky. Smíšené druhy (aktivně-pasivní) zůstávají podle třídy.
        $kind = (string) preg_replace('/^.*\./', '', mb_strtolower(AbraSource::reference($row['druhUctuK'] ?? null)));
        if ($type !== null && in_array($type, ['asset', 'liability', 'equity'], true)) {
            if ($kind === 'aktivni') {
                [$type, $side] = ['asset', 'debit'];
            } elseif ($kind === 'pasivni') {
                [$type, $side] = str_starts_with($code, '4') ? ['equity', 'credit'] : ['liability', 'credit'];
            }
        }
        return [
            'source_key' => AbraSource::sourceKey($row, $code),
            'source_hash' => AbraSource::hash($row),
            'code' => $code,
            'name' => mb_substr(trim((string) ($row['nazev'] ?? $row['popis'] ?? ('Účet ' . $code))), 0, 190),
            'account_type' => $type,
            'normal_side' => $side,
            'is_synthetic' => strlen($code) <= 3,
            'active' => AbraSource::bool($row['neaktivni'] ?? null) !== true,
            'blockers' => $code === '' || $type === null ? ['chart_account_invalid'] : [],
        ];
    }

    /** @return array<string,mixed> */
    public function mapEntry(array $row): array
    {
        $key = AbraSource::reference($row['idUcetniDenik'] ?? null) ?: AbraSource::sourceKey($row);
        $date = AbraSource::date($row['datUcto'] ?? null);
        $debit = AbraSource::account($row['mdUcet'] ?? $row['zklMdUcet'] ?? null);
        $credit = AbraSource::account($row['dalUcet'] ?? $row['zklDalUcet'] ?? null);
        $amount = AbraSource::number($row['sumTuz'] ?? null);
        $zeroAmount = $amount !== null && abs($amount) < 0.005;
        $blockers = [];
        if ($key === '') {
            $blockers[] = 'journal_identity_missing';
        }
        if ($date === null) {
            $blockers[] = 'journal_date_invalid';
        }
        if ($debit === '' || $credit === '') {
            $blockers[] = 'journal_account_missing';
        }
        if ($amount === null) {
            $blockers[] = 'journal_amount_invalid';
            $amount = 0.0;
        }
        if (AbraSource::bool($row['accountsSwapped'] ?? null) === true) {
            [$debit, $credit] = [$credit, $debit];
        }
        $currency = AbraSource::currency($row['mena'] ?? null);
        $foreignAmount = AbraSource::number($row['sumMen'] ?? null);
        $debitBank = str_starts_with($debit, '211') || str_starts_with($debit, '221');
        $creditBank = str_starts_with($credit, '211') || str_starts_with($credit, '221');
        $fxSide = $currency !== 'CZK' && $debitBank !== $creditBank
            ? ($debitBank ? 'debit' : 'credit') : null;
        if ($currency !== 'CZK' && $debitBank && $creditBank) {
            $blockers[] = 'journal_foreign_bank_transfer_ambiguous';
        }
        if ($fxSide !== null && ($foreignAmount === null || abs($foreignAmount) < 0.005)) {
            $blockers[] = 'journal_foreign_amount_missing';
        }
        $dimension = $this->dimension($row['dimens'] ?? null);
        $documentPath = trim((string) ($row['idDokl@evidencePath'] ?? ''));
        $documentId = AbraSource::reference($row['idDokl'] ?? null);
        $documentRelation = $documentPath !== '' && $documentId !== ''
            && !str_ends_with(rtrim($documentPath, '/'), '/' . $documentId)
                ? AbraSource::relation(rtrim($documentPath, '/') . '/' . $documentId)
                : AbraSource::relation($documentPath !== '' ? $documentPath : ($row['idDokl'] ?? null));
        $module = mb_strtolower(AbraSource::reference($row['modulK'] ?? $row['modul'] ?? null));
        $opening = str_contains($module, 'pocat') || str_contains($module, 'opening');
        return [
            'source_key' => $key,
            'source_hash' => AbraSource::hash($row),
            'date' => $date,
            'year' => $date !== null ? (int) substr($date, 0, 4) : 0,
            'debit' => $debit,
            'credit' => $credit,
            'amount' => round(abs($amount), 2),
            'fx_bank_side' => $fxSide,
            'fx_currency' => $fxSide !== null ? $currency : null,
            'fx_amount_foreign' => $fxSide !== null && $foreignAmount !== null
                ? number_format(abs($foreignAmount), 2, '.', '') : null,
            'fx_rate' => $fxSide !== null && $foreignAmount !== null && abs($foreignAmount) >= 0.005
                ? number_format(abs($amount) / abs($foreignAmount), 6, '.', '') : null,
            'zero_amount' => $zeroAmount,
            'is_red_storno' => $amount < 0 || AbraSource::bool($row['storno'] ?? null) === true,
            'document_no' => mb_substr(trim((string) ($row['doklad'] ?? $row['cisDokl'] ?? ($documentRelation['key'] ?? ''))), 0, 50),
            'description' => mb_substr(trim((string) ($row['popis'] ?? $row['text'] ?? 'Účetní zápis ABRA Flexi')), 0, 255),
            'source_type' => $opening ? 'opening' : 'manual',
            'document_relation' => $documentRelation,
            'cost_center' => $dimension,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => AbraSource::bool($row['zuctovano'] ?? true) === false ? ['journal_source_not_posted'] : [],
        ];
    }

    /** @return array{0:?string,1:?string} */
    private function accountType(string $source, string $code): array
    {
        foreach ([
            'naklad' => ['expense', 'debit'],
            'vynos' => ['revenue', 'credit'],
            'aktiv' => ['asset', 'debit'],
            'pasiv' => ['liability', 'credit'],
            'podrozvah' => ['offbalance', null],
            'offbalance' => ['offbalance', null],
            'equity' => ['equity', 'credit'],
        ] as $needle => $mapped) {
            if (str_contains($source, $needle)) {
                return $mapped;
            }
        }
        if ($code === '') {
            return [null, null];
        }
        return match ($code[0]) {
            '5' => ['expense', 'debit'],
            '6' => ['revenue', 'credit'],
            '7', '8' => ['offbalance', null],
            '0', '1', '2' => ['asset', 'debit'],
            '3' => ['liability', 'credit'],
            '4' => ['equity', 'credit'],
            default => [null, null],
        };
    }

    private function dimension(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                $text = AbraSource::reference($item);
                if ($text !== '') {
                    $parts[] = is_string($key) ? $key . ':' . $text : $text;
                }
            }
            $value = implode(', ', $parts);
        }
        return mb_substr(trim((string) $value), 0, 50) ?: null;
    }
}

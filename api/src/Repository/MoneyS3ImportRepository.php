<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;

/**
 * Převody z Money S3: běhy průvodce s protokolem (`money_s3_imports`) a mapa „co už
 * z agendy v MyÚčtu vzniklo" (`money_s3_import_map`, migrace 1806).
 *
 * Mapa, běhy i zámek převodu firmy jsou společné s ostatními převody
 * ({@see AbstractMigrationImportRepository}).
 */
final class MoneyS3ImportRepository extends AbstractMigrationImportRepository
{
    public const KIND_PERIOD = 'period';
    public const KIND_JOURNAL_ENTRY = 'journal_entry';
    public const KIND_CLIENT = 'client';
    public const KIND_POSTING_RULE = 'posting_rule';
    public const KIND_PURCHASE_INVOICE = 'purchase_invoice';
    public const KIND_INVOICE = 'invoice';
    public const KIND_CASH_REGISTER = 'cash_register';
    public const KIND_CASH_DOCUMENT = 'cash_document';
    public const KIND_BANK_STATEMENT = 'bank_statement';
    public const KIND_BANK_TRANSACTION = 'bank_transaction';
    public const KIND_PAYMENT = 'payment';
    public const KIND_COST_CENTER = 'cost_center';
    public const KIND_DIMENSION_VALUE = 'dimension_value';
    public const KIND_ASSET = 'asset';
    public const KIND_SMALL_ASSET = 'small_asset';
    /** Daňová evidence: úhrada z peněžního deníku, zápočet, zařazení pohybu, ostatní položka, podání DPH. */
    public const KIND_TAX_PAYMENT = 'tax_payment';
    public const KIND_TAX_OFFSET = 'tax_offset';
    public const KIND_TAX_CLASSIFICATION = 'tax_classification';
    public const KIND_OTHER_ITEM = 'other_item';
    public const KIND_VAT_FILING = 'vat_filing';

    protected function runsTable(): string
    {
        return 'money_s3_imports';
    }

    protected function mapTable(): string
    {
        return 'money_s3_import_map';
    }

    protected function keyColumn(): string
    {
        return 'money_key';
    }

    protected function lockPrefix(): string
    {
        return 'money_s3';
    }

    protected function runMeta(array $meta): array
    {
        return [
            'agenda_ico' => self::str($meta['agenda_ico'] ?? null, 20),
            'agenda_name' => self::str($meta['agenda_name'] ?? null, 190),
            'money_version' => self::str($meta['money_version'] ?? null, 20),
            'backup_sha256' => self::str($meta['backup_sha256'] ?? null, 64),
        ];
    }

    protected function mapConflict(string $kind, string $key): \RuntimeException
    {
        return new MoneyS3Exception('map_conflict', "Záznam {$kind} {$key} z Money už v MyÚčtu převedený je — převod se zastavil, aby nic nezdvojil.");
    }
}

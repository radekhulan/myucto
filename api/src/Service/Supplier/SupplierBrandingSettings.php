<?php

declare(strict_types=1);

namespace MyInvoice\Service\Supplier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\WorkReport\WorkReportDisplayOptions;

/** Sdílená pravidla vzhledu pro běžnou správu firmy i delegované nastavení. */
final class SupplierBrandingSettings
{
    /** @var list<string> */
    public const FIELDS = [
        'email_branding_enabled',
        'email_accent_color',
        'pdf_logo_show_name',
        'invoice_pdf_monochrome',
        'branding_profiles_enabled',
        'invoice_pdf_compact_items',
        'invoice_pdf_show_issued_by',
        'invoice_pdf_show_line_discount',
        'invoice_pdf_show_base_qty',
        'invoice_pdf_bank_in_footer',
        'work_report_display_options',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly WorkReportRepository $workReports,
    ) {}

    /** Ověří zaslaná pole a převede je na hodnoty pro uložení do supplier. */
    public static function validate(array $body, bool $blankColorUsesDefault = false): array
    {
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $body)) continue;
            $value = $body[$field];
            if ($field === 'work_report_display_options') {
                $options = WorkReportDisplayOptions::validate($value);
                // Firma nemá odkud dědit: výchozí podoba se ukládá jako NULL.
                $body[$field] = WorkReportDisplayOptions::resolve($options) === WorkReportDisplayOptions::DEFAULTS
                    ? null : json_encode((object) $options, JSON_THROW_ON_ERROR);
            } elseif ($field === 'email_accent_color') {
                if (!is_string($value) && $value !== null) {
                    throw new \InvalidArgumentException('Barva musí mít formát #RRGGBB.');
                }
                $color = strtoupper(trim($value ?? ''));
                if ($color === '' && $blankColorUsesDefault) $color = '#3B2D83';
                if (preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) {
                    throw new \InvalidArgumentException('Barva musí mít formát #RRGGBB.');
                }
                $body[$field] = $color;
            } else {
                if (!is_bool($value) && !in_array($value, [0, 1], true)) {
                    throw new \InvalidArgumentException("$field musí mít logickou hodnotu.");
                }
                $body[$field] = (int) (bool) $value;
            }
        }
        return $body;
    }

    /** Porovnává validovaný vstup s DB, u JSON podle výsledného zobrazení. */
    public static function sameValue(string $field, mixed $value, mixed $stored): bool
    {
        return match ($field) {
            'work_report_display_options' => WorkReportDisplayOptions::resolve($value) === WorkReportDisplayOptions::resolve($stored),
            'email_accent_color' => strtoupper((string) $value) === strtoupper((string) $stored),
            default => (bool) $value === (bool) $stored,
        };
    }

    /** Obě odpovědi API vracejí stejné typy i výchozí hodnoty. */
    public static function normalizeResponse(array $row): array
    {
        foreach (self::FIELDS as $field) {
            $row[$field] = match ($field) {
                'work_report_display_options' => WorkReportDisplayOptions::resolve($row[$field] ?? null),
                'email_accent_color' => ((string) ($row[$field] ?? '')) ?: '#3B2D83',
                'invoice_pdf_show_base_qty' => (bool) ($row[$field] ?? true),
                default => (bool) ($row[$field] ?? false),
            };
        }
        return $row;
    }

    /**
     * Připnutí vzhledu vystavených výkazů a změna firemního výchozího nastavení
     * tvoří jeden zápis. Zámek firmy brání dvěma souběžným změnám přepsat historii.
     */
    public function persist(int $supplierId, array $body, callable $persist): bool
    {
        if (!array_key_exists('work_report_display_options', $body)) return $persist();

        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT work_report_display_options FROM supplier WHERE id = ? FOR UPDATE');
            $stmt->execute([$supplierId]);
            $current = $stmt->fetchColumn();
            if ($current !== false && !self::sameValue('work_report_display_options', $body['work_report_display_options'], $current)) {
                $this->workReports->pinIssuedDisplayOptions($supplierId, $current);
            }
            $result = $persist();
            if ($ownsTransaction) $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

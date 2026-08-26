<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Export;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use ZipArchive;

final class PayrollPeriodExportArchiveBuilder
{
    public const FORMAT = 'myucto-payroll-period-export';

    public const VERSION = 1;

    private const ZIP_TIMESTAMP = 315532800;

    /**
     * @param array<string,mixed> $payrollData
     * @param list<PayrollPeriodExportEntry> $binaryEntries
     * @return array{
     *   bytes:string,file_sha256:string,size_bytes:int,
     *   source_manifest_hash:string,suggested_filename:string,
     *   manifest:array<string,mixed>
     * }
     */
    public function build(array $payrollData, array $binaryEntries): array
    {
        $scope = $payrollData['scope'] ?? null;
        $periodStart = $payrollData['period_start'] ?? null;
        $periodEnd = $payrollData['period_end'] ?? null;
        $revisions = $payrollData['revisions'] ?? null;
        $scopeValue = is_string($scope)
            ? PayrollPeriodExportScope::tryFrom($scope)
            : null;
        if ($scopeValue === null
            || !is_string($periodStart)
            || !is_string($periodEnd)
            || !$this->validDate($periodStart)
            || !$this->validDate($periodEnd)
            || $periodEnd < $periodStart
            || !is_array($revisions)
            || $revisions === []
        ) {
            throw new \InvalidArgumentException(
                'Rozsah exportu mezd nebo schválené revize nejsou platné.',
            );
        }
        $this->assertNoSecrets($payrollData);

        usort(
            $binaryEntries,
            static fn (
                PayrollPeriodExportEntry $left,
                PayrollPeriodExportEntry $right,
            ): int => strcmp($left->name, $right->name),
        );
        $entries = [];
        $archiveEntries = [
            'data/payroll.json' => CanonicalJson::encode($payrollData),
        ];
        foreach ($binaryEntries as $entry) {
            if (isset($archiveEntries[$entry->name])) {
                throw new \InvalidArgumentException(
                    'Export mezd obsahuje duplicitní název položky.',
                );
            }
            $archiveEntries[$entry->name] = $entry->bytes;
            $entries[$entry->name] = [
                'category' => $entry->category,
                'source_id' => $entry->sourceId,
                'mime_type' => $entry->mimeType,
                'size_bytes' => strlen($entry->bytes),
                'sha256' => hash('sha256', $entry->bytes),
            ];
        }
        $payrollJson = $archiveEntries['data/payroll.json'];
        $entries = [
            'data/payroll.json' => [
                'category' => 'payroll_data',
                'source_id' => 0,
                'mime_type' => 'application/json',
                'size_bytes' => strlen($payrollJson),
                'sha256' => hash('sha256', $payrollJson),
            ],
            ...$entries,
        ];
        $manifest = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'scope' => $scope,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'source_revision_ids' => $this->sourceRevisionIds($revisions),
            'entries' => $entries,
            'excluded' => [
                'credentials',
                'private_keys',
                'certificates',
                'manual_submission_attachments',
                'superseded_monthly_bundle_archives',
            ],
        ];
        $manifestJson = CanonicalJson::encode($manifest);
        $sourceManifestHash = hash('sha256', $manifestJson);
        $checksums = '';
        foreach ($entries as $name => $metadata) {
            $checksums .= $metadata['sha256'] . '  ' . $name . "\n";
        }
        $checksums .= hash('sha256', $manifestJson)
            . "  manifest.json\n";
        $readme = implode("\n", [
            'EXPORT MEZD MYUCTO.CZ',
            '',
            'Balicek obsahuje pouze schvalene nemenne mzdove zdroje a jiz archivovane dokumenty a protokoly.',
            'Spravnost polozek overte podle manifest.json a CHECKSUMS.txt.',
            'Balicek neobsahuje prihlasovaci udaje, soukrome klice, certifikaty ani libovolne rucni prilohy podani.',
            '',
        ]);
        $archiveEntries['manifest.json'] = $manifestJson;
        $archiveEntries['CHECKSUMS.txt'] = $checksums;
        $archiveEntries['CTI-MNE.txt'] = $readme;

        $bytes = $this->zip($archiveEntries);
        $hash = hash('sha256', $bytes);
        $period = $scopeValue === PayrollPeriodExportScope::Monthly
            ? substr($periodStart, 0, 7)
            : substr($periodStart, 0, 4);

        return [
            'bytes' => $bytes,
            'file_sha256' => $hash,
            'size_bytes' => strlen($bytes),
            'source_manifest_hash' => $sourceManifestHash,
            'suggested_filename' => sprintf(
                'mzdy-%s-%s.zip',
                $period,
                substr($hash, 0, 12),
            ),
            'manifest' => $manifest,
        ];
    }

    /** @param array<string,string> $entries */
    private function zip(array $entries): string
    {
        $directory = RuntimePaths::storage('tmp/payroll-period-exports');
        if (!is_dir($directory)
            && !@mkdir($directory, 0750, true)
            && !is_dir($directory)
        ) {
            throw new \RuntimeException(
                'Dočasné úložiště exportu mezd není dostupné.',
            );
        }
        $path = $directory . '/export-' . bin2hex(random_bytes(12)) . '.zip';
        $zip = new ZipArchive();
        $opened = false;
        try {
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new \RuntimeException('Export mezd nelze vytvořit.');
            }
            $opened = true;
            if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($path, 0600)) {
                throw new \RuntimeException(
                    'Dočasný export mezd nelze bezpečně zabezpečit.',
                );
            }
            foreach ($entries as $name => $bytes) {
                if (!$zip->addFromString($name, $bytes)
                    || !$zip->setMtimeName($name, self::ZIP_TIMESTAMP)
                    || !$zip->setCompressionName($name, ZipArchive::CM_DEFLATE, 9)
                    || !$zip->setExternalAttributesName(
                        $name,
                        ZipArchive::OPSYS_UNIX,
                        0100640 << 16,
                    )
                ) {
                    throw new \RuntimeException(
                        'Položku exportu mezd nelze bezpečně zapsat.',
                    );
                }
            }
            if (!$zip->close()) {
                throw new \RuntimeException('Export mezd nelze dokončit.');
            }
            $opened = false;
            $bytes = file_get_contents($path);
            if (!is_string($bytes) || $bytes === '') {
                throw new \RuntimeException('Export mezd nelze načíst.');
            }

            return $bytes;
        } finally {
            if ($opened) {
                @$zip->close();
            }
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * @param array<array-key,mixed> $revisions
     * @return list<int>
     */
    private function sourceRevisionIds(array $revisions): array
    {
        $ids = [];
        foreach ($revisions as $revision) {
            if (!is_array($revision) || array_is_list($revision)) {
                throw new \InvalidArgumentException(
                    'Zdrojová revize exportu mezd není platná.',
                );
            }
            $value = $revision['id'] ?? null;
            if (!is_int($value) && !is_string($value)) {
                throw new \InvalidArgumentException(
                    'Zdrojová revize exportu mezd nemá platné ID.',
                );
            }
            $id = filter_var(
                $value,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );
            if (!is_int($id)) {
                throw new \InvalidArgumentException(
                    'Zdrojová revize exportu mezd nemá platné ID.',
                );
            }
            $ids[] = $id;
        }

        sort($ids, SORT_NUMERIC);
        if (count($ids) !== count(array_unique($ids))) {
            throw new \InvalidArgumentException(
                'Zdrojové revize exportu mezd obsahují duplicitní ID.',
            );
        }

        return $ids;
    }

    /** @param array<array-key,mixed> $value */
    private function assertNoSecrets(array $value): void
    {
        $forbidden = [
            'api_key',
            'application_password',
            'certificate_password',
            'certificate_pfx',
            'client_secret',
            'communication_code',
            'credentials',
            'password',
            'password_hash',
            'private_key',
            'private_key_pem',
            'secret',
            'token',
            'totp_secret',
            'access_token',
            'refresh_token',
        ];
        foreach ($value as $key => $item) {
            if (is_string($key)
                && in_array(strtolower($key), $forbidden, true)
            ) {
                throw new \InvalidArgumentException(
                    'Zdroj exportu mezd obsahuje zakázané citlivé pole.',
                );
            }
            if (is_array($item)) {
                $this->assertNoSecrets($item);
            }
        }
    }
}

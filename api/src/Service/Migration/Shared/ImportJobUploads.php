<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchJobService;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchUploads;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3ImportJobService;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Uploads;
use MyInvoice\Service\Migration\Pohoda\PohodaImportJobService;
use MyInvoice\Service\Migration\Pohoda\PohodaUploads;
use MyInvoice\Service\Migration\Premier\PremierImportJobService;
use MyInvoice\Service\Migration\Premier\PremierUploads;

/**
 * Nahrané soubory, na které odkazují joby převodu (`params.token`, u dávky
 * `params.items[].token`). Rozbalený export nebo záloha má stovky megabajtů až gigabajty;
 * smazáním posledního jobu, který na ně odkazuje, je uživatel už nepotřebuje.
 */
final class ImportJobUploads
{
    private const REFERENCE_SCAN_LIMIT = 1000;

    public static function store(string $source): ?ChunkedUploadStore
    {
        return match ($source) {
            PohodaImportJobService::SOURCE => PohodaUploads::store(),
            MoneyS3ImportJobService::SOURCE => MoneyS3Uploads::store(),
            MoneyS3BatchJobService::SOURCE => MoneyS3BatchUploads::store(),
            PremierImportJobService::SOURCE => PremierUploads::store(),
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $job řádek import_jobs
     * @return list<string>
     */
    public static function tokens(array $job): array
    {
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $tokens = [(string) ($params['token'] ?? '')];
        foreach ((array) ($params['items'] ?? []) as $item) {
            $tokens[] = is_array($item) ? (string) ($item['token'] ?? '') : '';
        }
        return array_values(array_unique(array_filter($tokens, static fn (string $t): bool => $t !== '')));
    }

    /**
     * Po smazání jobu odstraní jeho report a nahrané soubory, na které už neodkazuje žádný jiný job
     * téhož zdroje a se kterými zrovna nepracuje běžící job.
     *
     * @param array<string,mixed> $deletedJob řádek smazaného jobu
     * @return int kolik nahraných souborů se smazalo
     */
    public static function purgeOrphaned(ImportJobRepository $jobs, array $deletedJob): int
    {
        ImportJobReportFiles::purge($deletedJob);
        $source = (string) ($deletedJob['source'] ?? '');
        $supplierId = (int) ($deletedJob['supplier_id'] ?? 0);
        $store = self::store($source);
        $tokens = self::tokens($deletedJob);
        if ($store === null || $supplierId <= 0 || $tokens === []) {
            return 0;
        }
        $referenced = [];
        foreach ($jobs->listForTenant($supplierId, $source, self::REFERENCE_SCAN_LIMIT) as $job) {
            if ((int) $job['id'] !== (int) ($deletedJob['id'] ?? 0)) {
                foreach (self::tokens($job) as $token) {
                    $referenced[$token] = true;
                }
            }
        }
        $removed = 0;
        foreach ($tokens as $token) {
            if (!isset($referenced[$token]) && $store->purgeIdle($supplierId, $token)) {
                $removed++;
            }
        }
        return $removed;
    }
}

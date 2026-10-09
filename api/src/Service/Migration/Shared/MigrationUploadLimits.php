<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/**
 * Limity nahrávání do průvodců převodem na jednom místě, hodnoty po zdrojích.
 */
final class MigrationUploadLimits
{
    /**
     * Část souboru: pod `upload_max_filesize`, IIS `maxAllowedContentLength` i nginx
     * `client_max_body_size`, včetně výchozího 1 MB u nginx bez nastavení (cizí reverzní proxy).
     */
    public const CHUNK_BYTES = 768 * 1024;

    /** Nahraných souborů firmy najednou (nové nahrání smaže nejstarší nečinný). */
    public const MAX_ACTIVE_UPLOADS = 3;

    /** Záloha agendy Money S3 (.lz). */
    public const MONEY_S3_MAX_BYTES = 4 * 1024 * 1024 * 1024;
    /** Sestava z Money k rekonciliaci (CSV obratové předvahy). */
    public const MONEY_S3_MAX_REPORT_BYTES = 5 * 1024 * 1024;
    /** ZIP s XML exportem z POHODY. */
    public const POHODA_MAX_BYTES = 2 * 1024 * 1024 * 1024;
    /** Záloha dat PREMIER (.izip/.icab). */
    public const PREMIER_MAX_BYTES = 2 * 1024 * 1024 * 1024;
    /** Kompletní export MyÚčta (ZIP); vybraná data mají samostatný paměťový limit. */
    public const MYUCTO_MAX_BYTES = 2 * 1024 * 1024 * 1024;
    /** Šifrovaná záloha Stereo NX (ZIP). */
    public const STEREO_NX_MAX_BYTES = 2 * 1024 * 1024 * 1024;
}

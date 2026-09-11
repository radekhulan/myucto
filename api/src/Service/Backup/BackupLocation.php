<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Kde leží zálohovací ZIPy — jediné místo, které to rozhoduje.
 *
 * Tahle čtyřstupňová kaskáda byla rozkopírovaná ve všech čtyřech
 * `api/bin/cron-backup*.php`. Dokud ji četly jen ony, dalo se s tím žít; jakmile
 * se do stejného adresáře dívá i web (stránka „Stažení záloh"), znamenala by pátá
 * kopie tichý rozchod: cron by psal do jednoho adresáře a UI by nabízelo obsah
 * jiného — bez chyby, jen s prázdným seznamem.
 *
 * Pořadí (zachované beze změny):
 *   1) `cron.backup.output_dir`  — explicitní override provozovatele
 *   2) `storage.backup_dir`      — sdílená dokumentovaná cesta
 *   3) `MYINVOICE_DATA_DIR/storage/backup` — PaaS/Docker s ephemeral filesystémem
 *   4) `rootDir/storage/backup`  — klasický VPS / dev
 *
 * Kroky 3 a 4 jsou přesně to, co dělá {@see RuntimePaths::storage()}. Bez nich
 * (issue #34) končil ZIP v ephemeral filesystému image (Fly.io / Railway /
 * Render) a mizel s každým deployem, i když provozovatel měl správně nastavený
 * `MYINVOICE_DATA_DIR=/data`.
 *
 * Adresář se tu ZÁMĚRNĚ nevytváří: čtecí cesty (web) nemají zakládat úložiště,
 * jen se do něj dívat. `mkdir` zůstává v cron skriptech, které do něj zapisují.
 */
final class BackupLocation
{
    public static function resolve(Config $config): string
    {
        $dir = (string) $config->get('cron.backup.output_dir', '');
        if ($dir === '') {
            $dir = (string) $config->get('storage.backup_dir', '');
        }
        if ($dir === '') {
            $dir = RuntimePaths::storage('backup');
        }

        return $dir;
    }
}

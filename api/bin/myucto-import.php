<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Cron\BackupEncryption;
use MyInvoice\Service\Migration\Myucto\MyuctoExportReader;
use MyInvoice\Service\Migration\Myucto\MyuctoImporter;
use MyInvoice\Service\Migration\Myucto\MyuctoImportSealedValues;

$help = <<<'HELP'
Import jedné firmy z existujícího Kompletního exportu dat MyÚčta (CLI správce).
php api/bin/myucto-import.php --file=export.zip --supplier=ID --actor=ID --source=NAZEV [--source-keys=SOUBOR] [--apply]
Bez --apply provede stejné zápisy a celou transakci vrátí zpět.
Export vytvořte bez omezení období s volbou „Úplný obnovitelný archiv“.
Zdroj musí mít stabilní jedinečný název. Cílová firma musí již existovat,
mít shodné IČO, účetní režim a plátcovství a nemít vlastní obchodní doklady.
Heslo ZIPu lze zadat proměnnou MYUCTO_IMPORT_PASSWORD; jinak se použije
cron.backup.password cílové konfigurace. Heslo nepatří do argumentů příkazu.
Přenesené šablony pravidelné fakturace jsou pozastavené.
Mzdové osobní údaje jsou v exportu zašifrované klíčem zdrojové instalace a vázané
na zdrojovou firmu; obnova je přešifruje pro cílovou firmu. Z jiné instalace zadejte
--source-keys=SOUBOR (JSON: secret_encryption_key, secret_encryption_previous_keys,
payroll_hash_key, pepper zdrojové instalace); soubor po obnově smažte.
Protokol uvádí i nepřenesené tabulky mimo první importní profil.
HELP;
try {
    $options = []; $apply = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') { fwrite(STDOUT, $help . PHP_EOL); exit(0); }
        if ($arg === '--apply') { if ($apply) throw new InvalidArgumentException('Duplicitní --apply.'); $apply = true; continue; }
        if (!preg_match('/\A--(file|supplier|actor|source|source-keys)=(.+)\z/D', $arg, $m) || isset($options[$m[1]])) throw new InvalidArgumentException($help);
        $options[$m[1]] = $m[2];
    }
    foreach (['file', 'supplier', 'actor', 'source'] as $key) if (!isset($options[$key])) throw new InvalidArgumentException($help);
    $supplier = MyuctoExportReader::id($options['supplier']); $actor = MyuctoExportReader::id($options['actor']);
    $config = Config::load(Bootstrap::rootDir());
    $password = getenv('MYUCTO_IMPORT_PASSWORD');
    if ($password === false) $password = BackupEncryption::passwordFromConfig($config);
    $sourceKeys = isset($options['source-keys']) ? MyuctoImportSealedValues::readKeyFile($options['source-keys']) : null;
    $package = (new MyuctoExportReader())->read($options['file'], $password);
    $sealed = MyuctoImportSealedValues::create($config, $sourceKeys);
    $report = Bootstrap::buildContainer()->get(MyuctoImporter::class)->import($package, $supplier, $actor, $options['source'], !$apply, null, $sealed);
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }

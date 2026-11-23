<?php

declare(strict_types=1);

/**
 * Propojí další účty firem u České spořitelny ze souhlasů, které už jsou připojené.
 *
 * Nové přihlášení propojí další účty souhlasu samo ({@see CsasSiblingAccountLinker}); skript
 * slouží pro účty připojené dřív. Bez --apply jen vypíše, co by propojil. Na banku volá jen
 * obnovu tokenu a seznam účtů, jednou za souhlas.
 *
 * Použití: php api/bin/bank-link-sibling-accounts.php [--supplier=ID] [--apply]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require dirname(__DIR__) . '/vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\Connector\CsasApiClient;
use MyInvoice\Service\Bank\Connector\CsasCredentialVault;
use MyInvoice\Service\Bank\Connector\CsasSiblingAccountLinker;

$apply = in_array('--apply', $argv, true);
$supplierFilter = null;
foreach ($argv as $arg) {
    if (preg_match('/^--supplier=(\d+)$/D', $arg, $m)) $supplierFilter = (int) $m[1];
}

$container = Bootstrap::buildContainer();
$pdo = $container->get(Connection::class)->pdo();
$secrets = $container->get(SecretEncryption::class);
$vault = $container->get(CsasCredentialVault::class);
$api = $container->get(CsasApiClient::class);
$linker = $container->get(CsasSiblingAccountLinker::class);

if ($secrets->validateKey() !== null) {
    fwrite(STDERR, "Šifrovací klíč aplikace není dostupný.\n");
    exit(1);
}

$sql = "SELECT id, supplier_id, currency_id, token_ciphertext FROM bank_connections
    WHERE provider = 'csas' AND enabled = 1 AND token_ciphertext IS NOT NULL"
    . ($supplierFilter !== null ? ' AND supplier_id = ?' : '') . ' ORDER BY supplier_id, id';
$stmt = $pdo->prepare($sql);
$stmt->execute($supplierFilter !== null ? [$supplierFilter] : []);

echo $apply ? "Propojení účtů\n" : "Zkouška nanečisto (bez --apply se nic neukládá)\n";
$processed = [];
$summary = ['consents' => 0, 'linked' => 0, 'failed' => 0];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $supplierId = (int) $row['supplier_id'];
    $connectionId = (int) $row['id'];
    try {
        $stored = $vault->decode($secrets->decryptFor((string) $row['token_ciphertext'], CsasCredentialVault::context($supplierId, $connectionId)));
    } catch (Throwable) {
        echo "firma {$supplierId}, napojení {$connectionId}: přístup nelze přečíst, přeskočeno\n";
        continue;
    }
    foreach ($processed as $seen) {
        if (CsasCredentialVault::sameConsent($seen, $stored)) continue 2;
    }
    $processed[] = $stored;
    $summary['consents']++;
    try {
        $primary = $vault->access(json_encode($stored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $results = $linker->link((int) $row['currency_id'], $primary, $api->accounts($primary), null, $apply);
    } catch (Throwable) {
        echo "firma {$supplierId}, napojení {$connectionId}: banka seznam účtů nevrátila, přeskočeno\n";
        $summary['failed']++;
        continue;
    }
    echo "firma {$supplierId}, napojení {$connectionId}:\n";
    if ($results === []) echo "  žádné další účty u ČS\n";
    foreach ($results as $result) {
        echo "  účet {$result['currency_id']}: {$result['status']}"
            . (isset($result['connection_id']) ? " (napojení {$result['connection_id']})" : '') . "\n";
        if (in_array($result['status'], [CsasSiblingAccountLinker::LINKED, CsasSiblingAccountLinker::RELINKED], true)) $summary['linked']++;
        if ($result['status'] === CsasSiblingAccountLinker::FAILED) $summary['failed']++;
    }
}
echo json_encode(['apply' => $apply] + $summary, JSON_THROW_ON_ERROR) . "\n";
exit($summary['failed'] > 0 ? 1 : 0);

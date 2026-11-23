<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Update;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tlačítko Aktualizovat v Dockeru s připnutou verzí image.
 *
 * Manuál doporučuje v produkci připnout v compose konkrétní verzi. Watcher ale
 * cílovou verzi z tlačítka do `docker-update.(sh|ps1)` nepředával a `docker compose
 * pull` stáhl zase tutéž verzi, takže aktualizace nic nezměnila a ohlásila úspěch.
 * `cmd/lib/image-tag.(sh|ps1)` rozhoduje, kdy připnutou verzi přepsat na cílovou.
 * Plovoucí značka (`latest`) ani nižší či neplatná cílová verze nesmí compose změnit.
 */
final class ImageTagScriptTest extends TestCase
{
    private string $root;
    private string $tmp;

    protected function setUp(): void
    {
        $this->root = str_replace('\\', '/', dirname(__DIR__, 4));
        $this->tmp  = str_replace('\\', '/', sys_get_temp_dir()) . '/myucto-image-tag-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0775, true);
        foreach (['image-tag.sh', 'image-tag.ps1'] as $file) {
            copy($this->root . '/cmd/lib/' . $file, $this->tmp . '/' . $file);
        }
        $production = (string) file_get_contents($this->root . '/docker-compose.production.yml');
        file_put_contents($this->tmp . '/latest.yml', $production);
        file_put_contents($this->tmp . '/pinned.yml', str_replace('myucto:latest', 'myucto:6.70.1', $production));
        file_put_contents($this->tmp . '/suffix.yml', "services:\n  app:\n    image: ghcr.io/radekhulan/myucto:6.70.1-rc1\n");
        file_put_contents($this->tmp . '/quoted.yml', "services:\n  app:\n    image: \"ghcr.io/radekhulan/myucto:6.9.0\"  # pin\n");
        file_put_contents($this->tmp . '/multi.yml', <<<'YAML'
services:
  worker:
    image: ghcr.io/radekhulan/myucto:6.70.1
  "app": # web
    environment:
      image: ghcr.io/radekhulan/myucto:6.70.1
    image: 'ghcr.io/radekhulan/myucto:6.70.1' # pin
  job:
    image: ghcr.io/radekhulan/myucto:6.70.1
YAML . "\n");
        file_put_contents($this->tmp . '/worker-pinned.yml', str_replace(
            "    image: 'ghcr.io/radekhulan/myucto:6.70.1' # pin",
            '    image: ghcr.io/radekhulan/myucto:latest',
            (string) file_get_contents($this->tmp . '/multi.yml'),
        ));
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        @rmdir($this->tmp);
    }

    /** @return array<string, array{string, string, string}> */
    public static function bumpProvider(): array
    {
        return [
            'připnutá nižší verze'        => ['pinned.yml', '6.71.0', 'ghcr.io/radekhulan/myucto:6.70.1 ghcr.io/radekhulan/myucto:6.71.0'],
            'cílová verze s předponou v'  => ['pinned.yml', 'v6.71.0', 'ghcr.io/radekhulan/myucto:6.70.1 ghcr.io/radekhulan/myucto:6.71.0'],
            'číselné, ne textové pořadí'  => ['pinned.yml', '6.70.10', 'ghcr.io/radekhulan/myucto:6.70.1 ghcr.io/radekhulan/myucto:6.70.10'],
            'v uvozovkách'                => ['quoted.yml', '6.10.0', 'ghcr.io/radekhulan/myucto:6.9.0 ghcr.io/radekhulan/myucto:6.10.0'],
            'připnutý je jen worker'    => ['worker-pinned.yml', '6.71.0', ''],
            'plovoucí latest'             => ['latest.yml', '6.71.0', ''],
            'stejná verze'                => ['pinned.yml', '6.70.1', ''],
            'nižší cílová verze'          => ['pinned.yml', '6.69.9', ''],
            'cíl latest z watcheru'       => ['pinned.yml', 'latest', ''],
            'neúplná cílová verze'        => ['pinned.yml', '6.71', ''],
            'cizí text v cílové verzi'    => ['pinned.yml', '6.71.0;rm -rf /', ''],
            'chybějící compose'           => ['missing.yml', '6.71.0', ''],
            'zalomení řádku v cílové verzi' => ['pinned.yml', "6.71.0\n", ''],
            'příliš dlouhá část verze'    => ['pinned.yml', '6.71.99999999999', ''],
            'značka s příponou'           => ['suffix.yml', '6.71.0', ''],
        ];
    }

    #[DataProvider('bumpProvider')]
    public function testShellDecidesWhenToBumpPinnedTag(string $compose, string $target, string $expected): void
    {
        $this->bashOrSkip();
        [$out, $err, $rc] = $this->runProcess('bash image-tag.sh --bump ' . $compose . ' ' . escapeshellarg($target));

        self::assertSame(0, $rc, 'stderr: ' . $err);
        self::assertSame($expected, trim($out));
    }

    #[DataProvider('bumpProvider')]
    public function testPowerShellMatchesShell(string $compose, string $target, string $expected): void
    {
        $this->pwshOrSkip();
        [$out, $err, $rc] = $this->runProcess('pwsh -NoProfile -File image-tag.ps1 -Bump ' . $compose . ' ' . escapeshellarg($target));

        self::assertSame(0, $rc, 'stderr: ' . $err);
        self::assertSame($expected, trim($out));
    }

    public function testShellRewritesOnlyTheAppImageLine(): void
    {
        $this->bashOrSkip();
        $before = (string) file_get_contents($this->tmp . '/multi.yml');

        [, $err, $rc] = $this->runProcess('bash image-tag.sh --write multi.yml ghcr.io/radekhulan/myucto:6.70.1 ghcr.io/radekhulan/myucto:6.71.0');

        self::assertSame(0, $rc, 'stderr: ' . $err);
        self::assertSame(
            str_replace(
                "    image: 'ghcr.io/radekhulan/myucto:6.70.1' # pin",
                "    image: 'ghcr.io/radekhulan/myucto:6.71.0' # pin",
                $before,
            ),
            file_get_contents($this->tmp . '/multi.yml'),
        );
    }

    public function testPowerShellRewritesOnlyTheAppImageLine(): void
    {
        $this->pwshOrSkip();
        $before = (string) file_get_contents($this->tmp . '/multi.yml');

        [, $err, $rc] = $this->runProcess('pwsh -NoProfile -File image-tag.ps1 -Write multi.yml ghcr.io/radekhulan/myucto:6.70.1 ghcr.io/radekhulan/myucto:6.71.0');

        self::assertSame(0, $rc, 'stderr: ' . $err);
        self::assertSame(
            str_replace(
                "    image: 'ghcr.io/radekhulan/myucto:6.70.1' # pin",
                "    image: 'ghcr.io/radekhulan/myucto:6.71.0' # pin",
                $before,
            ),
            file_get_contents($this->tmp . '/multi.yml'),
        );
    }

    public static function failedUpdateProvider(): array
    {
        return [
            'bash: obsazený port' => ['sh', 'port'],
            'PowerShell: obsazený port' => ['ps1', 'port'],
            'bash: pád před spuštěním app' => ['sh', 'db'],
            'PowerShell: pád před spuštěním app' => ['ps1', 'db'],
            'bash: rozpor konfigurace app' => ['sh', 'config'],
            'PowerShell: rozpor konfigurace app' => ['ps1', 'config'],
        ];
    }

    #[DataProvider('failedUpdateProvider')]
    public function testUpdateLeavesPinnedTagUntouchedBeforeStartingApp(string $script, string $failure): void
    {
        if ($script === 'sh') {
            $this->bashOrSkip();
        } else {
            $this->pwshOrSkip();
        }
        [$out, $err, $rc] = $this->runUpdaterFixture($script, $failure);

        self::assertNotSame(0, $rc, $out . $err);
        self::assertStringContainsString($failure === 'port' ? '58120' : 'Restarting database', $out . $err);
        self::assertFileEquals($this->tmp . '/multi.yml', $this->tmp . '/docker-compose.production.yml', $out . $err);
        self::assertFileDoesNotExist($this->tmp . '/app-started');
    }

    public static function switchedVersionProvider(): array
    {
        return [
            'bash: shoda verze' => ['sh', '6.71.0', 0],
            'PowerShell: shoda verze' => ['ps1', '6.71.0', 0],
            'bash: běží původní verze' => ['sh', '6.70.1', 1],
            'PowerShell: běží původní verze' => ['ps1', '6.70.1', 1],
        ];
    }

    #[DataProvider('switchedVersionProvider')]
    public function testChecksAppVersionAfterRestart(string $script, string $actualVersion, int $expectedExit): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Zástupný curl.exe je spustitelný skript pro Unix.');
        }
        if ($script === 'sh') {
            $this->bashOrSkip();
        } else {
            $this->pwshOrSkip();
        }

        foreach (['curl', 'curl.exe'] as $curl) {
            file_put_contents($this->tmp . '/' . $curl, "#!/usr/bin/env bash\nexit 0\n");
            chmod($this->tmp . '/' . $curl, 0755);
        }
        [$out, $err, $rc] = $this->runUpdaterFixture($script, $actualVersion);

        self::assertSame($expectedExit, $rc, $out . $err);
        self::assertStringContainsString($expectedExit === 0 ? 'Update complete' : $actualVersion, $out . $err);
        $before = (string) file_get_contents($this->tmp . '/multi.yml');
        self::assertSame(
            str_replace("myucto:6.70.1' # pin", "myucto:6.71.0' # pin", $before),
            file_get_contents($this->tmp . '/app-started'),
        );
    }

    /** @return array{0:string,1:string,2:int} */
    private function runUpdaterFixture(string $script, string $scenario): array
    {
        $compose = $this->tmp . '/docker-compose.production.yml';
        copy($this->tmp . '/multi.yml', $compose);
        file_put_contents($this->tmp . '/.env', "APP_PORT=58120\nDB_PORT=58121\n");
        mkdir($this->tmp . '/cmd/lib', 0775, true);
        copy($this->root . '/cmd/docker-update.' . $script, $this->tmp . '/cmd/docker-update.' . $script);
        foreach (['env-load', 'image-tag'] as $lib) {
            copy($this->root . '/cmd/lib/' . $lib . '.' . $script, $this->tmp . '/cmd/lib/' . $lib . '.' . $script);
        }

        $stub = $this->tmp . '/docker-stub.php';
        file_put_contents($stub, <<<'PHP'
<?php
[, $command] = $argv;
if ($command === 'compose') {
    $file = $argv[2] === '-f' ? $argv[3] : 'docker-compose.production.yml';
    $op = $argv[2] === '-f' ? $argv[4] : $argv[2];
    if ($op === 'config') {
        readfile(getenv('UPDATE_FAKE_FAILURE') === 'config' ? 'docker-compose.production.yml' : $file);
    } elseif ($op === 'up' && end($argv) === 'db' && getenv('UPDATE_FAKE_FAILURE') === 'db') {
        exit(12);
    } elseif ($op === 'ps' && end($argv) === 'db') {
        echo '{"Health":"healthy"}';
    } elseif ($op === 'up' && end($argv) === 'app') {
        copy($file, 'app-started');
        if (in_array(getenv('UPDATE_FAKE_FAILURE'), ['port', 'db', 'config'], true)) {
            exit(13);
        }
    } elseif ($op === 'exec') {
        echo getenv('UPDATE_FAKE_FAILURE'), PHP_EOL;
    }
} elseif ($command === 'ps' && $argv[2] === '--format' && getenv('UPDATE_FAKE_FAILURE') === 'port') {
    $project = str_contains($argv[3], 'com.docker.compose.project') ? 'fixture|' : '';
    echo 'nginx|nginx:latest|' . $project . '0.0.0.0:58120->80/tcp', PHP_EOL;
} elseif ($command === 'volume') {
    exit(1);
}
PHP);
        $php = str_replace('\\', '/', PHP_BINARY);
        file_put_contents($this->tmp . '/docker', "#!/usr/bin/env bash\nexec \"$php\" \"\$(dirname \"\$0\")/docker-stub.php\" \"\$@\"\n");
        chmod($this->tmp . '/docker', 0755);
        file_put_contents($this->tmp . '/docker.cmd', "@echo off\r\n\"$php\" \"%~dp0docker-stub.php\" %*\r\n");

        $env = getenv();
        if (PHP_OS_FAMILY === 'Windows') {
            $env = array_change_key_case($env, CASE_UPPER);
        }
        $env['PATH'] = $this->tmp . PATH_SEPARATOR . getenv('PATH');
        $env['APP_PORT'] = '58120';
        $env['DB_PORT'] = '58121';
        $env['MYINVOICE_UPDATE_MODE'] = 'registry';
        $env['MYINVOICE_TARGET_VERSION'] = '6.71.0';
        $env['UPDATE_FAKE_FAILURE'] = $scenario;
        $lookup = $script === 'sh' ? 'bash -c "command -v docker"' : 'pwsh -NoProfile -Command "(Get-Command docker).Source"';
        [$docker, $err, $rc] = $this->runProcess($lookup, $env);
        self::assertSame(0, $rc, $err);
        self::assertStringStartsWith('myucto-image-tag-', basename(dirname(trim(str_replace('\\', '/', $docker)))));
        $command = $script === 'sh' ? 'bash cmd/docker-update.sh' : 'pwsh -NoProfile -File cmd/docker-update.ps1';

        return $this->runProcess($command, $env);
    }

    /** Watcher musí cílovou verzi opravdu předat, jinak knihovna nemá z čeho rozhodnout. */
    public function testWatchersPassTargetVersionToUpdateScript(): void
    {
        self::assertMatchesRegularExpression(
            '/MYINVOICE_TARGET_VERSION="\$TARGET" bash "\$PROJECT_ROOT\/cmd\/docker-update\.sh"/',
            (string) file_get_contents($this->root . '/cmd/docker-update-watcher.sh'),
        );
        self::assertStringContainsString(
            '$env:MYINVOICE_TARGET_VERSION = $target',
            (string) file_get_contents($this->root . '/cmd/docker-update-watcher.ps1'),
        );
        foreach (['docker-update.sh' => 'image_tag_bump', 'docker-update.ps1' => 'Get-ImageTagBump'] as $script => $call) {
            self::assertStringContainsString($call, (string) file_get_contents($this->root . '/cmd/' . $script));
        }
    }

    /** @return array{0:string,1:string,2:int} */
    private function runProcess(string $command, ?array $env = null): array
    {
        $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->tmp, $env);
        self::assertIsResource($proc, 'proc_open selhal pro: ' . $command);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [$stdout, $stderr, proc_close($proc)];
    }

    private function bashOrSkip(): void
    {
        if ($this->runProcess('bash -c "exit 0"')[2] !== 0) {
            self::markTestSkipped('bash není v PATH (na Linuxu i v git-bash na Windows být má).');
        }
    }

    private function pwshOrSkip(): void
    {
        if ($this->runProcess('pwsh -NoProfile -Command "exit 0"')[2] !== 0) {
            self::markTestSkipped('pwsh není v PATH — PowerShell parita se testuje jen tam, kde PS 7 je.');
        }
    }
}

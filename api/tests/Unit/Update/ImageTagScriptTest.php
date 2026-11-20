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
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
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
        $before = (string) file_get_contents($this->tmp . '/pinned.yml');

        [, $err, $rc] = $this->runProcess('bash image-tag.sh --write pinned.yml ghcr.io/radekhulan/myucto:6.70.1 ghcr.io/radekhulan/myucto:6.71.0');

        self::assertSame(0, $rc, 'stderr: ' . $err);
        self::assertSame(
            str_replace('myucto:6.70.1', 'myucto:6.71.0', $before),
            file_get_contents($this->tmp . '/pinned.yml'),
        );
    }

    public function testPowerShellRewritesOnlyTheAppImageLine(): void
    {
        $this->pwshOrSkip();
        $before = (string) file_get_contents($this->tmp . '/pinned.yml');

        [, $err, $rc] = $this->runProcess('pwsh -NoProfile -File image-tag.ps1 -Write pinned.yml ghcr.io/radekhulan/myucto:6.70.1 ghcr.io/radekhulan/myucto:6.71.0');

        self::assertSame(0, $rc, 'stderr: ' . $err);
        self::assertSame(
            str_replace('myucto:6.70.1', 'myucto:6.71.0', $before),
            file_get_contents($this->tmp . '/pinned.yml'),
        );
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
    private function runProcess(string $command): array
    {
        $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->tmp);
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

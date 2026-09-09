<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\SourceCorpus;
use PHPUnit\Framework\TestCase;

final class SourceCorpusTest extends TestCase
{
    public function testInventoryAndContentsAreAnImmutableSharedView(): void
    {
        $root = str_replace('\\', '/', sys_get_temp_dir()) . '/source-corpus-' . bin2hex(random_bytes(8));
        mkdir($root . '/nested', 0777, true);
        mkdir($root . '/nested-other');
        $fixtures = [
            '/Z.php' => '<?php echo 1;',
            '/nested/A.php' => '<?php echo 2;',
            '/nested/BTest.php' => '<?php echo 3;',
            '/nested-other/C.php' => '<?php echo 4;',
            '/ignored.txt' => 'plain text',
        ];
        try {
            foreach ($fixtures as $path => $content) {
                file_put_contents($root . $path, $content);
            }
            self::assertSame([
                $root . '/Z.php',
                $root . '/nested-other/C.php',
                $root . '/nested/A.php',
                $root . '/nested/BTest.php',
            ], SourceCorpus::files($root));
            self::assertSame([
                'A.php' => '<?php echo 2;',
                'BTest.php' => '<?php echo 3;',
            ], SourceCorpus::sources($root . '/nested'));
            self::assertSame([$root . '/nested/BTest.php'], SourceCorpus::files($root, 'Test.php'));
            file_put_contents($root . '/nested/A.php', '<?php echo 99;');
            $copy = SourceCorpus::sources($root . '/nested');
            $copy['A.php'] = 'changed caller copy';
            self::assertSame('<?php echo 2;', SourceCorpus::read($root . '/nested/A.php'));
            self::assertSame('<?php echo 2;', SourceCorpus::sources($root . '/nested')['A.php']);
        } finally {
            foreach (array_keys($fixtures) as $path) {
                if (is_file($root . $path)) {
                    unlink($root . $path);
                }
            }
            rmdir($root . '/nested');
            rmdir($root . '/nested-other');
            rmdir($root);
        }
    }
}

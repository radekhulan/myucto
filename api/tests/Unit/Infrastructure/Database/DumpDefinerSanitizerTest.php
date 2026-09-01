<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Database;

use MyInvoice\Infrastructure\Database\DumpDefinerSanitizer;
use PHPUnit\Framework\TestCase;

final class DumpDefinerSanitizerTest extends TestCase
{
    public function testRemovesVersionedAndDirectObjectDefiners(): void
    {
        $sql = <<<'SQL'
/*!50003 CREATE*/ /*!50017 DEFINER=`legacy_user`@`legacy_host`*/ /*!50003 TRIGGER `sample_trigger` BEFORE INSERT ON `sample` FOR EACH ROW SET NEW.`value` = 1 */;;
/*!50001 CREATE ALGORITHM=UNDEFINED */ /*!50013 DEFINER=`versioned_view_user`@`versioned_view_host` SQL SECURITY DEFINER */ /*!50001 VIEW `versioned_view` AS SELECT 1 */;
CREATE DEFINER='routine_user'@'routine_host' PROCEDURE `sample_procedure`() SELECT 1;
CREATE ALGORITHM=UNDEFINED DEFINER=`view_user`@`view_host` SQL SECURITY DEFINER VIEW `sample_view` AS SELECT 1;
SQL;

        $sanitized = DumpDefinerSanitizer::sanitize($sql);

        self::assertStringNotContainsString('legacy_user', $sanitized);
        self::assertStringNotContainsString('versioned_view_user', $sanitized);
        self::assertStringNotContainsString('routine_user', $sanitized);
        self::assertStringNotContainsString('view_user', $sanitized);
        self::assertStringContainsString('TRIGGER `sample_trigger`', $sanitized);
        self::assertStringContainsString('CREATE PROCEDURE `sample_procedure`', $sanitized);
        self::assertStringContainsString('CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW', $sanitized);
    }

    public function testLeavesDefinerTextInsideRoutineBodyUntouched(): void
    {
        $sql = "CREATE PROCEDURE `sample`() SELECT 'DEFINER=`text`@`only`';\n";

        self::assertSame($sql, DumpDefinerSanitizer::sanitize($sql));
    }

    public function testSanitizeFileReplacesDumpInPlace(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dump-definer-test-');
        self::assertIsString($path);
        file_put_contents($path, "CREATE DEFINER=`owner`@`host` FUNCTION `sample`() RETURNS INT RETURN 1;\n");

        try {
            DumpDefinerSanitizer::sanitizeFile($path);
            $contents = (string) file_get_contents($path);

            self::assertStringNotContainsString('owner', $contents);
            self::assertStringContainsString('CREATE FUNCTION `sample`', $contents);
        } finally {
            @unlink($path);
        }
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Volání `$this->metoda()` v akci musí mířit na existující metodu třídy nebo jejích
 * traitů. Chybějící helper (mapError v zápočtu i v příloze závěrky) se projevil až
 * v provozu: místo validační hlášky spadl požadavek na Error a uživatel dostal 500.
 */
final class ActionMethodCallsExistTest extends TestCase
{
    public function testCalledMethodsExist(): void
    {
        $root = dirname(__DIR__, 2) . '/src/Action';
        $missing = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1
                || preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $cls) !== 1) {
                continue;
            }
            $class = $ns[1] . '\\' . $cls[1];
            if (!class_exists($class)) {
                continue;
            }
            $ref = new \ReflectionClass($class);
            preg_match_all('/\$this->(\w+)\(/', $source, $calls);
            foreach (array_unique($calls[1]) as $method) {
                if ($ref->hasMethod($method) || $ref->hasProperty($method)) {
                    continue;
                }
                $missing[] = $class . '::' . $method . '()';
            }
        }

        self::assertSame([], $missing, 'Akce volají neexistující metody.');
    }
}

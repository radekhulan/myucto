<?php

declare(strict_types=1);

namespace MyUctoRegression;

final class Instrumentation
{
    private const SOURCES = [
        'Connection' => ['b7d1044875fad222611fc313bde683c8aeb7a4fddb5e15e46022de64a17750fb', '6c506158421c0e773763b63fed899b18aa62fe8ccdc8eee457785c2183b1bca7', '5d9c84b68ad7692b6bb909a75ccaba904fe63b77049210bea42223cafadb0f28', 'bfd98e0b98ef45238b9b6f5ad9649bfdd22b5143433c314336c78b072e862351'],
        'LoggingPdo' => ['d10edbbbab05bdf41e29675ed131e9a64c5d61258bf16990472f69f06ea5cd81', '2cfa564ed60e90271b63c9d694a7c4c9b7934abea77913d8b97ada3d4bab7903', '63e08f8bf35a7047338850ce22284fb474ea1bd37fca867e3477cf23bcc8c6e8', '53209d6d4cfe4adeb5ec75c567ae02ac573ad5b32300f6037c5a170c4e9e282e', 'adb5f86504032ed2514606490fc5f612bf05b3ccc33dba27482fa6811bdb904a', '88ef1cb8a43890fcd8f5cf65b5cd22ea15e36871c2328a5aad9e9b33d046e9c4'],
        'LoggingPdoStatement' => ['73585baf5a9f597f445e93ee9a0ffc1796e0cfc128722549318c6b3f674b904e', '8fb76dcc1168b0356412c72fe1ed5db2b870baf2fac1071ef4baebe85523d042', '17efb22fe5a396953aa9c3abaa2777e1e75341c55bb432a54b83e12414afbc96'],
    ];

    public static function register(string $codeRoot, bool $preserveConfig = false): void
    {
        $sources = [];
        foreach (self::SOURCES as $name => $hash) {
            $class = 'MyInvoice\\Infrastructure\\Database\\' . $name;
            if (class_exists($class, false)) {
                throw new \RuntimeException('instrumentation_loaded_too_late');
            }
            $source = str_replace("\r\n", "\n", (string) file_get_contents($codeRoot . '/api/src/Infrastructure/Database/' . $name . '.php'));
            if (!in_array(hash('sha256', $source), (array) $hash, true)) {
                throw new \RuntimeException('unsupported_database_source_signature');
            }
            $sources[$class] = self::transform($name, $source);
        }
        if ($preserveConfig) {
            if (class_exists('MyInvoice\\Bootstrap', false)) {
                throw new \RuntimeException('instrumentation_loaded_too_late');
            }
            $source = str_replace("\r\n", "\n", (string) file_get_contents($codeRoot . '/api/src/Bootstrap.php'));
            if (!hash_equals('05ee1762e9e867c9ea721c5975edfbcb9eab6c3bfdf498dc4b74dd8dd64332f1', hash('sha256', $source))) {
                throw new \RuntimeException('unsupported_bootstrap_source_signature');
            }
            $source = self::replace($source, '$config  = Config::load($rootDir);', '$config  = \\MyUctoRegression\\ConfigSnapshot::get();', 1);
            $sources['MyInvoice\\Bootstrap'] = self::replace($source, 'return dirname(__DIR__, 2);', 'return ' . var_export($codeRoot, true) . ';', 1);
        }
        spl_autoload_register(static function (string $class) use ($sources): void {
            if (isset($sources[$class])) {
                eval(substr($sources[$class], 5));
            }
        }, true, true);
    }

    private static function transform(string $name, string $source): string
    {
        $guard = '\\MyUctoRegression\\Tripwire::';
        if ($name === 'Connection') {
            $start = strpos($source, '    public function pdo(): PDO');
            $end = strpos($source, "\n    /**", $start);
            $method = substr($source, $start, $end - $start);
            $method = self::replace($method, "public function pdo(): PDO\n    {", "public function pdo(): PDO\n    {\n        " . $guard . 'verifyConfig($this->config);', 1);
            $method = self::replace($method, 'return $this->pdo;', $guard . 'activate($this->pdo); return $this->pdo;', 2);
            $method = self::replace($method, 'return $this->pdo = $pdo;', $guard . 'activate($pdo); return $this->pdo = $pdo;', 1);
            return substr($source, 0, $start) . $method . substr($source, $end);
        }
        $signatures = $name === 'LoggingPdo' ? [
            'public function prepare(string $query, array $options = []): PDOStatement|false' => '$query',
            'public function exec(string $statement): int|false' => '$statement',
            'public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false' => '$query',
        ] : ['public function execute(?array $params = null): bool' => '(string) $this->queryString'];
        foreach ($signatures as $signature => $argument) {
            $source = self::replace($source, $signature . "\n    {", $signature . "\n    {\n        " . $guard . 'sql(' . $argument . ');', 1);
        }
        $source = self::replace($source, 'catch (PDOException $e) {', 'catch (PDOException $e) { ' . $guard . 'databaseError();', $name === 'LoggingPdo' ? 3 : 1);
        return $source;
    }

    private static function replace(string $source, string $find, string $replacement, int $expected): string
    {
        $result = str_replace($find, $replacement, $source, $count);
        if ($count !== $expected) {
            throw new \RuntimeException('unsupported_database_method_signature');
        }
        return $result;
    }
}

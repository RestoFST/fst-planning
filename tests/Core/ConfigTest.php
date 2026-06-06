<?php

namespace Tests\Core;

use App\Core\Config;
use Dotenv\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(Config::class)]
class ConfigTest extends TestCase
{
    private array $backupEnv = [];

    protected function setUp(): void
    {
        $this->backupEnv = $_ENV;
        $this->resetConfigSingleton();
    }

    protected function tearDown(): void
    {
        $this->resetConfigSingleton();
        $_ENV = $this->backupEnv;
    }

    public function testGetInstanceReturnsSameSingletonInstance(): void
    {
        $first = Config::getInstance();
        $second = Config::getInstance();

        $this->assertSame($first, $second);
    }

    public function testGetReturnsEnvValueOrDefault(): void
    {
        $_ENV['TEST_CONFIG_KEY'] = 'test-value';

        $config = Config::getInstance();

        $this->assertSame('test-value', $config->get('TEST_CONFIG_KEY'));
        $this->assertSame('fallback', $config->get('UNKNOWN_KEY', 'fallback'));
    }

    public function testAllReturnsCurrentEnvironmentVariables(): void
    {
        $_ENV['APP_FEATURE_FLAG'] = '1';

        $config = Config::getInstance();

        $this->assertArrayHasKey('APP_FEATURE_FLAG', $config->all());
        $this->assertSame('1', $config->all()['APP_FEATURE_FLAG']);
    }

    public function testHasDetectsExistingEnvironmentKeys(): void
    {
        $_ENV['EXISTING_KEY'] = 'yes';

        $config = Config::getInstance();

        $this->assertTrue($config->has('EXISTING_KEY'));
        $this->assertFalse($config->has('MISSING_KEY'));
    }

    public function testRequireReturnsValidatorForExistingKeys(): void
    {
        $_ENV['REQUIRED_KEY'] = 'present';

        $config = Config::getInstance();
        $validator = $config->require('REQUIRED_KEY');

        $this->assertInstanceOf(Validator::class, $validator);
    }

    private function resetConfigSingleton(): void
    {
        $reflection = new ReflectionClass(Config::class);
        $property = $reflection->getProperty('instance');
        $property->setValue(null);
    }
}
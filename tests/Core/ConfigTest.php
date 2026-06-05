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
    public function testRequireReturnsValidatorForExistingKeys(): void
    {
        $_ENV['REQUIRED_KEY'] = 'present';

        $config = new Config();
        $validator = $config->require('REQUIRED_KEY');

        $this->assertInstanceOf(Validator::class, $validator);
    }
}
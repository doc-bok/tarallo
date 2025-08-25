<?php

declare(strict_types=1);
require_once __DIR__ . '/../source/vendor/autoload.php';

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /**
     * Setup: Ensure dotenv is loaded and config instance is reset.
     */
    public function setUp(): void {

        // Load test environment file.
        $dotenv = Dotenv::createImmutable(
            __DIR__ . '/../source',
            [
                '.env.test'
            ]);

        $dotenv->safeLoad();

        // Ensure new instance on every run.
        $reflection = new ReflectionClass(Config::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null);
    }

    /**
     * Test that instance returns the same instance each time.
     */
    public function testInstanceReturnsSameObject()
    {
        $config1 = Config::getInstance();
        $config2 = Config::getInstance();
        $this->assertSame($config1, $config2, "Instance method should always return the same object");
    }

    /**
     * Test that direct instantiation is prevented.
     */
    public function testConstructorIsPrivate()
    {
        $reflection = new ReflectionClass(Config::class);
        $constructor = $reflection->getConstructor();
        $this->assertTrue($constructor->isPrivate(), "Constructor should be private to enforce singleton");
    }

    /**
     * Test that known default config keys are available and have expected types or defaults.
     */
    public function testDefaultSettingsExist()
    {
        $config = Config::getInstance();
        $this->assertTrue($config->has('APP_ENV'));
        $this->assertIsString($config->get('APP_ENV'));
        $this->assertEquals('test', $config->get('APP_ENV'));  // Assuming no env override
    }

    /**
     * Test that get() returns defined values and null if key missing.
     */
    public function testGetReturnsValueOrNull()
    {
        $config = Config::getInstance();
        $this->assertNull($config->get('NON_EXISTENT_KEY'));
    }

    /**
     * Test that missing required keys throw exception during construction or validation.
     * @throws ReflectionException if reflection changes fail.
     */
    public function testValidateThrowsExceptionIfRequiredKeysMissing()
    {
        $_ENV['TARALLO_DB_DSN'] = '';
        $_ENV['TARALLO_DB_USERNAME'] = '';
        $_ENV['TARALLO_DB_PASSWORD'] = '';

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Missing required config key');

        // Create new instance forcibly for test purpose (if constructor private, use Reflection)
        $reflection = new ReflectionClass(Config::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        // Set broken settings manually
        $property = $reflection->getProperty('settings');
        $property->setValue($instance, [
            'DB_DSN' => '',
            'DB_USERNAME' => '',
            'DB_PASSWORD' => ''
        ]);

        // Call validate manually
        $method = $reflection->getMethod('validate');
        $method->invoke($instance);
    }

    /**
     * Test that settings reflect overridden environment variables.
     */
    public function testLoadRespectsEnvOverrides()
    {
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['TARALLO_DB_DSN'] = 'sqlite::memory:';
        $_ENV['TARALLO_DB_USERNAME'] = 'user';
        $_ENV['TARALLO_DB_PASSWORD'] = 'secret';

        $config = Config::getInstance();

        $this->assertEquals('testing', $config->get('APP_ENV'));
        $this->assertEquals('sqlite::memory:', $config->get('DB_DSN'));
        $this->assertEquals('user', $config->get('DB_USERNAME'));
        $this->assertEquals('secret', $config->get('DB_PASSWORD'));
    }
}

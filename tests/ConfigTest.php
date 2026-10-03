<?php

declare(strict_types=1);

namespace Studbook\Tests;

use PHPUnit\Framework\TestCase;
use Studbook\Config;
use Studbook\ConfigException;

final class ConfigTest extends TestCase
{
    private const VALID = [
        'APP_URL' => 'https://studbook.example.com',
        'APP_ENV' => 'production',
        'DB_HOST' => 'localhost',
        'DB_NAME' => 'studbook',
        'DB_USER' => 'studbook',
        'DB_PASSWORD' => '',
    ];

    public function testParsesCommentsQuotesAndInlineComments(): void
    {
        $values = Config::parse(<<<'ENV'
            # comment
            APP_ENV=production            # production | development
            QUOTED="a # not a comment"
            SINGLE='x y'
            EMPTY=
            export EXPORTED=1
            HASH=abc#def
            ENV);

        self::assertSame('production', $values['APP_ENV']);
        self::assertSame('a # not a comment', $values['QUOTED']);
        self::assertSame('x y', $values['SINGLE']);
        self::assertSame('', $values['EMPTY']);
        self::assertSame('1', $values['EXPORTED']);
        self::assertSame('abc#def', $values['HASH']);
    }

    public function testExampleFileParsesAndValidates(): void
    {
        $values = Config::parse((string) file_get_contents(dirname(__DIR__) . '/.env.example'));
        $config = new Config($values, '/app');
        $config->validate();

        self::assertSame('production', $config->get('APP_ENV'));
        self::assertSame(24, $config->int('IMPORT_MIN_INTERVAL_HOURS'));
        self::assertFalse($config->bool('APP_DEBUG'));
    }

    public function testEveryKeyReadByTheAppIsDocumentedInEnvExample(): void
    {
        $documented = array_keys(Config::parse((string) file_get_contents(dirname(__DIR__) . '/.env.example')));
        $used = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src'));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $code = (string) file_get_contents($file->getPathname());
                preg_match_all('/->(?:get|bool|int|path)\(\'([A-Z][A-Z0-9_]+)\'/', $code, $m);
                $used = array_merge($used, $m[1]);
            }
        }
        $used = array_merge($used, Config::REQUIRED, Config::REQUIRED_MAY_BE_EMPTY);

        self::assertSame([], array_values(array_diff(array_unique($used), $documented)));
    }

    public function testMissingRequiredKeysAreReported(): void
    {
        $values = self::VALID;
        unset($values['DB_NAME'], $values['DB_PASSWORD']);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('DB_NAME, DB_PASSWORD');
        (new Config($values, '/app'))->validate();
    }

    public function testEmptyRequiredKeyIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        (new Config(['APP_URL' => ''] + self::VALID, '/app'))->validate();
    }

    public function testInvalidEnvironmentIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        (new Config(['APP_ENV' => 'staging'] + self::VALID, '/app'))->validate();
    }

    public function testLoadFailsWithoutEnvFile(): void
    {
        $this->expectException(ConfigException::class);
        Config::load('/nonexistent-studbook-root');
    }

    public function testDebugIsNeverOnInProduction(): void
    {
        $prod = new Config(['APP_DEBUG' => 'true'] + self::VALID, '/app');
        $dev = new Config(['APP_DEBUG' => 'true', 'APP_ENV' => 'development'] + self::VALID, '/app');

        self::assertFalse($prod->isDebug());
        self::assertTrue($dev->isDebug());
    }

    public function testPathsAndBasePath(): void
    {
        $config = new Config(
            ['APP_URL' => 'https://example.com/studbook/', 'STORAGE_PATH' => 'storage', 'ABS' => '/var/x']
                + self::VALID,
            '/app'
        );

        self::assertSame('/app/storage', $config->path('STORAGE_PATH'));
        self::assertSame('/var/x', $config->path('ABS'));
        self::assertSame('/studbook', $config->basePath());
        self::assertTrue($config->isHttps());
    }
}

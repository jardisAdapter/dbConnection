<?php

declare(strict_types=1);

namespace JardisAdapter\DbConnection\Tests\Unit\Data;

use JardisAdapter\DbConnection\Data\ExternalConfig;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Unit tests for ExternalConfig
 */
final class ExternalConfigTest extends TestCase
{
    public function testConstructorSetsPdo(): void
    {
        $pdo = $this->createMock(PDO::class);
        $config = new ExternalConfig($pdo);

        $this->assertSame($pdo, $config->pdo);
    }

    public function testGetDriverNameReturnsDriverFromPdo(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())
            ->method('getAttribute')
            ->with(PDO::ATTR_DRIVER_NAME)
            ->willReturn('mysql');

        $config = new ExternalConfig($pdo);

        $this->assertSame('mysql', $config->getDriverName());
    }

    public function testSupportsMultipleDriverTypes(): void
    {
        $drivers = ['mysql', 'pgsql', 'sqlite'];

        foreach ($drivers as $driver) {
            $pdo = $this->createMock(PDO::class);
            $pdo->method('getAttribute')
                ->with(PDO::ATTR_DRIVER_NAME)
                ->willReturn($driver);

            $config = new ExternalConfig($pdo);
            $this->assertSame($driver, $config->getDriverName());
        }
    }
}

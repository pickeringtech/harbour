<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Installation\PhpExtensionGuide;

final class PhpExtensionGuideTest extends TestCase
{
    public function test_it_provides_arch_specific_package_and_enablement_guidance(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'harbour-os-');
        self::assertIsString($path);
        file_put_contents($path, "NAME=Arch Linux\nID=arch\n");

        $resolution = (new PhpExtensionGuide($path))->resolution('pdo_pgsql');

        self::assertStringContainsString('sudo pacman -S --needed php-pgsql', $resolution);
        self::assertStringContainsString('extension=pdo_pgsql', $resolution);
        self::assertStringContainsString('php --ini', $resolution);
        unlink($path);
    }

    public function test_it_uses_id_like_for_omarchy_and_explains_the_phpredis_dependency_order(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'harbour-os-');
        self::assertIsString($path);
        file_put_contents($path, "ID=omarchy\nID_LIKE=arch\n");

        $resolution = (new PhpExtensionGuide($path))->resolution('redis');

        self::assertStringContainsString('sudo pacman -S --needed php-igbinary php-redis', $resolution);
        self::assertStringContainsString('extension=igbinary before extension=redis', $resolution);
        unlink($path);
    }

    public function test_it_provides_debian_package_guidance_and_a_safe_generic_fallback(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'harbour-os-');
        self::assertIsString($path);
        file_put_contents($path, "ID=ubuntu\n");

        self::assertStringContainsString('sudo apt-get install php-redis', (new PhpExtensionGuide($path))->resolution('redis'));
        self::assertStringContainsString('Install and enable unknown_extension', (new PhpExtensionGuide($path))->resolution('unknown_extension'));
        unlink($path);
    }

    #[DataProvider('platformPackages')]
    public function test_it_maps_common_linux_families_to_their_php_packages(string $id, string $extension, string $expected): void
    {
        $path = tempnam(sys_get_temp_dir(), 'harbour-os-');
        self::assertIsString($path);
        file_put_contents($path, "ID={$id}\n");

        self::assertStringContainsString($expected, (new PhpExtensionGuide($path))->resolution($extension));
        unlink($path);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function platformPackages(): iterable
    {
        yield 'Arch MongoDB' => ['arch', 'mongodb', 'php-mongodb'];
        yield 'Ubuntu MySQL' => ['ubuntu', 'pdo_mysql', 'php-mysql'];
        yield 'Fedora Redis' => ['fedora', 'redis', 'php-pecl-redis'];
        yield 'Alpine SQLite' => ['alpine', 'pdo_sqlite', 'pdo_sqlite'];
    }
}

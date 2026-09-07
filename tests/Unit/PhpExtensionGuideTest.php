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
        yield 'Arch fallback' => ['arch', 'unknown', 'Install and enable unknown'];
        yield 'Arch MongoDB' => ['arch', 'mongodb', 'php-mongodb'];
        yield 'Arch Memcached' => ['manjaro', 'memcached', 'php-memcached'];
        yield 'Ubuntu PostgreSQL' => ['debian', 'pdo_pgsql', 'php-pgsql'];
        yield 'Ubuntu MySQL' => ['ubuntu', 'pdo_mysql', 'php-mysql'];
        yield 'Ubuntu SQLite' => ['linuxmint', 'pdo_sqlite', 'php-sqlite3'];
        yield 'Ubuntu MongoDB' => ['pop', 'mongodb', 'php-mongodb'];
        yield 'Ubuntu Memcached' => ['debian', 'memcached', 'php-memcached'];
        yield 'Fedora PostgreSQL' => ['rhel', 'pdo_pgsql', 'php-pgsql'];
        yield 'Fedora MySQL' => ['centos', 'pdo_mysql', 'php-mysqlnd'];
        yield 'Fedora SQLite' => ['rocky', 'pdo_sqlite', 'php-pdo'];
        yield 'Fedora Redis' => ['fedora', 'redis', 'php-pecl-redis'];
        yield 'Fedora MongoDB' => ['almalinux', 'mongodb', 'php-pecl-mongodb'];
        yield 'Fedora Memcached' => ['rhel', 'memcached', 'php-pecl-memcached'];
        yield 'Alpine PostgreSQL' => ['alpine', 'pdo_pgsql', 'pdo_pgsql'];
        yield 'Alpine MySQL' => ['alpine', 'pdo_mysql', 'pdo_mysql'];
        yield 'Alpine SQLite' => ['alpine', 'pdo_sqlite', 'pdo_sqlite'];
        yield 'Alpine Redis' => ['alpine', 'redis', 'pecl-redis'];
        yield 'Alpine MongoDB' => ['alpine', 'mongodb', 'pecl-mongodb'];
        yield 'Alpine Memcached' => ['alpine', 'memcached', 'pecl-memcached'];
    }

    public function test_unknown_and_non_linux_families_use_safe_generic_guidance(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'harbour-os-');
        self::assertIsString($path);
        file_put_contents($path, "ID=custom\nID_LIKE=ubuntu\n");
        self::assertStringContainsString('sudo apt-get install php-pgsql', (new PhpExtensionGuide($path))->resolution('pdo_pgsql'));

        file_put_contents($path, "ID=custom\n");
        self::assertStringContainsString('Install and enable pdo_pgsql', (new PhpExtensionGuide($path))->resolution('pdo_pgsql'));
        self::assertStringContainsString('Install and enable redis', (new PhpExtensionGuide($path, 'Darwin'))->resolution('redis'));
        unlink($path);
    }

    public function test_an_unreadable_os_release_falls_back_to_linux(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'harbour-os-');
        self::assertIsString($path);
        chmod($path, 0000);

        self::assertStringContainsString('Install and enable redis', (new PhpExtensionGuide($path))->resolution('redis'));

        chmod($path, 0600);
        unlink($path);
    }
}

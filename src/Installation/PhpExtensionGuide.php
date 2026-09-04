<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

final readonly class PhpExtensionGuide
{
    public function __construct(private string $osRelease = '/etc/os-release') {}

    public function resolution(string $extension): string
    {
        $id = $this->linuxFamily();
        $package = $this->package($id, $extension);

        if ($package === null) {
            return 'Install and enable '.$extension.' for the PHP CLI at '.PHP_BINARY;
        }

        $command = match ($id) {
            'arch', 'manjaro' => 'sudo pacman -S --needed '.$package,
            'debian', 'ubuntu', 'linuxmint', 'pop' => 'sudo apt-get install '.$package,
            'fedora', 'rhel', 'centos', 'rocky', 'almalinux' => 'sudo dnf install '.$package,
            'alpine' => 'sudo apk add '.$package,
            default => null,
        };
        if ($command === null) {
            return 'Install package '.$package.' and enable '.$extension.' for the PHP CLI at '.PHP_BINARY;
        }

        $enable = '';
        if (in_array($id, ['arch', 'manjaro'], true)) {
            $extensions = match ($extension) {
                'pdo_pgsql' => ['pdo_pgsql', 'pgsql'],
                'redis' => ['igbinary', 'redis'],
                default => [$extension],
            };
            $enable = ' Then enable '.implode(' before ', array_map(
                static fn (string $name): string => 'extension='.$name,
                $extensions,
            )).' in the CLI php.ini reported by php --ini.';
        }

        return 'Run `'.$command.'`.'.$enable;
    }

    private function linuxFamily(): string
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! is_file($this->osRelease) || is_link($this->osRelease)) {
            return strtolower(PHP_OS_FAMILY);
        }

        $contents = file_get_contents($this->osRelease);
        if (! is_string($contents)) {
            return 'linux';
        }

        $ids = [];
        foreach (['ID', 'ID_LIKE'] as $key) {
            if (preg_match('/^'.$key.'=["\']?([^"\'\r\n]+)["\']?$/m', $contents, $match) !== 1) {
                continue;
            }
            $ids = [...$ids, ...preg_split('/\s+/', strtolower(trim($match[1]))) ?: []];
        }

        $supported = [
            'arch', 'manjaro',
            'debian', 'ubuntu', 'linuxmint', 'pop',
            'fedora', 'rhel', 'centos', 'rocky', 'almalinux',
            'alpine',
        ];

        foreach ($ids as $id) {
            if (in_array($id, $supported, true)) {
                return $id;
            }
        }

        return $ids[0] ?? 'linux';
    }

    private function package(string $id, string $extension): ?string
    {
        if (in_array($id, ['arch', 'manjaro'], true)) {
            return match ($extension) {
                'pdo_pgsql' => 'php-pgsql',
                'redis' => 'php-igbinary php-redis',
                'mongodb' => 'php-mongodb',
                'memcached' => 'php-memcached',
                default => null,
            };
        }

        if (in_array($id, ['debian', 'ubuntu', 'linuxmint', 'pop'], true)) {
            return match ($extension) {
                'pdo_pgsql' => 'php-pgsql',
                'pdo_mysql' => 'php-mysql',
                'pdo_sqlite' => 'php-sqlite3',
                'redis' => 'php-redis',
                'mongodb' => 'php-mongodb',
                'memcached' => 'php-memcached',
                default => null,
            };
        }

        if (in_array($id, ['fedora', 'rhel', 'centos', 'rocky', 'almalinux'], true)) {
            return match ($extension) {
                'pdo_pgsql' => 'php-pgsql',
                'pdo_mysql' => 'php-mysqlnd',
                'pdo_sqlite' => 'php-pdo',
                'redis' => 'php-pecl-redis',
                'mongodb' => 'php-pecl-mongodb',
                'memcached' => 'php-pecl-memcached',
                default => null,
            };
        }

        if ($id === 'alpine') {
            $prefix = 'php'.PHP_MAJOR_VERSION.PHP_MINOR_VERSION;

            return match ($extension) {
                'pdo_pgsql' => $prefix.'-pdo_pgsql',
                'pdo_mysql' => $prefix.'-pdo_mysql',
                'pdo_sqlite' => $prefix.'-pdo_sqlite',
                'redis' => $prefix.'-pecl-redis',
                'mongodb' => $prefix.'-pecl-mongodb',
                'memcached' => $prefix.'-pecl-memcached',
                default => null,
            };
        }

        return null;
    }
}

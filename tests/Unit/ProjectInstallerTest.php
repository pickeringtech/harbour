<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Exceptions\HarbourException;
use PickeringTech\Harbour\Installation\InstallationSelection;
use PickeringTech\Harbour\Installation\ProjectInstaller;

final class ProjectInstallerTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/harbour-installer-'.bin2hex(random_bytes(6));
        mkdir($this->workspace, 0700, true);
        file_put_contents($this->workspace.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);
    }

    public function test_it_prepares_an_idempotent_project_installation(): void
    {
        $installer = new ProjectInstaller($this->workspace);
        $selection = self::selection();
        $first = $installer->install($selection);

        self::assertSame(['.env.harbour', 'config/harbour.php'], $first->created);
        self::assertSame(['.gitignore', 'composer.json'], $first->updated);
        self::assertSame([], $first->conflicts);
        self::assertFileExists($this->workspace.'/.env.harbour');
        self::assertFileExists($this->workspace.'/config/harbour.php');
        self::assertStringContainsString('DB_CONNECTION=sqlite', (string) file_get_contents($this->workspace.'/.env.harbour'));
        self::assertSame($selection->toArray(), $first->selection->toArray());
        $configuration = require $this->workspace.'/config/harbour.php';
        self::assertIsArray($configuration);
        self::assertSame([
            ...$selection->toArray(),
            'discovery' => ['detected' => false, 'sources' => []],
        ], $configuration['installation']);
        $database = $configuration['database'] ?? null;
        self::assertIsArray($database);
        self::assertSame('sqlite', $database['connection']);
        self::assertStringContainsString("/.harbour.json\n/.harbour/\n", (string) file_get_contents($this->workspace.'/.gitignore'));

        $scripts = $this->composerScripts();
        self::assertSame(['@php artisan workspace:setup'], $scripts['workspace:setup']);
        self::assertSame(['@php artisan workspace:status'], $scripts['workspace:status']);
        self::assertSame(['@php artisan workspace:teardown'], $scripts['workspace:teardown']);

        $second = $installer->install($selection);
        self::assertSame([], $second->created);
        self::assertSame([], $second->updated);
        self::assertEqualsCanonicalizing(['.env.harbour', 'config/harbour.php', '.gitignore', 'composer.json'], $second->unchanged);
        self::assertSame(['.env.harbour', 'config/harbour.php'], $second->reconfigure);
    }

    public function test_it_preserves_existing_files_and_composer_scripts(): void
    {
        mkdir($this->workspace.'/config');
        file_put_contents($this->workspace.'/.env.harbour', "CUSTOM=yes\n");
        file_put_contents($this->workspace.'/config/harbour.php', "<?php return ['custom' => true];\n");
        file_put_contents($this->workspace.'/.gitignore', "/.harbour.json\n/.harbour/\n");
        file_put_contents($this->workspace.'/composer.json', json_encode([
            'name' => 'acme/app',
            'scripts' => ['workspace:setup' => ['custom setup']],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $result = (new ProjectInstaller($this->workspace))->install(self::selection());

        self::assertSame("CUSTOM=yes\n", file_get_contents($this->workspace.'/.env.harbour'));
        self::assertSame(['composer.json scripts.workspace:setup'], $result->conflicts);
        $scripts = $this->composerScripts();
        self::assertSame(['custom setup'], $scripts['workspace:setup']);
        self::assertSame(['@php artisan workspace:status'], $scripts['workspace:status']);
    }

    public function test_it_generates_an_idempotent_compose_policy_for_managed_services(): void
    {
        $installer = new ProjectInstaller($this->workspace);
        $selection = InstallationSelection::fromOptions('pgsql', 'redis', 'mailpit', 'meilisearch,minio', 'compose');

        $first = $installer->install($selection);

        self::assertContains('docker-compose.harbour.yml', $first->created);
        self::assertFileExists($this->workspace.'/docker-compose.harbour.yml');
        self::assertStringContainsString('127.0.0.1:${DB_PORT}:5432', (string) file_get_contents($this->workspace.'/docker-compose.harbour.yml'));
        self::assertStringContainsString('DB_PORT=${DB_PORT}', (string) file_get_contents($this->workspace.'/.env.harbour'));

        $configuration = require $this->workspace.'/config/harbour.php';
        self::assertIsArray($configuration);
        $installation = $configuration['installation'] ?? null;
        $compose = $configuration['compose'] ?? null;
        self::assertIsArray($installation);
        self::assertIsArray($compose);
        $services = $compose['services'] ?? null;
        self::assertIsArray($services);
        $ports = $services['ports'] ?? null;
        self::assertIsArray($ports);
        self::assertSame('compose', $installation['provider'] ?? null);
        self::assertSame('docker-compose.harbour.yml', $services['file'] ?? null);
        self::assertArrayHasKey('DB_PORT', $ports);
        self::assertSame([], $configuration['services'] ?? null);

        $second = $installer->install($selection);
        self::assertContains('docker-compose.harbour.yml', $second->unchanged);
    }

    public function test_it_never_overwrites_an_existing_compose_file(): void
    {
        file_put_contents($this->workspace.'/docker-compose.harbour.yml', "services:\n  custom: {}\n");

        $result = (new ProjectInstaller($this->workspace))->install(
            InstallationSelection::fromOptions('pgsql', 'none', 'none', 'none', 'compose'),
        );

        self::assertSame("services:\n  custom: {}\n", file_get_contents($this->workspace.'/docker-compose.harbour.yml'));
        self::assertContains('docker-compose.harbour.yml', $result->unchanged);
    }

    public function test_it_rejects_an_unsafe_installation_target(): void
    {
        symlink('/tmp', $this->workspace.'/.env.harbour');

        $this->expectException(HarbourException::class);
        (new ProjectInstaller($this->workspace))->install(self::selection());
    }

    public function test_it_appends_only_missing_ignore_entries_after_an_unterminated_file(): void
    {
        file_put_contents($this->workspace.'/.gitignore', '/.harbour.json');

        (new ProjectInstaller($this->workspace))->install(self::selection());

        self::assertSame("/.harbour.json\n\n# Harbour workspace state\n/.harbour/\n", file_get_contents($this->workspace.'/.gitignore'));
    }

    public function test_it_rejects_a_missing_composer_manifest(): void
    {
        unlink($this->workspace.'/composer.json');

        $this->expectException(HarbourException::class);
        $this->expectExceptionMessage('containing composer.json');
        (new ProjectInstaller($this->workspace))->install(self::selection());
    }

    public function test_it_rejects_invalid_composer_json(): void
    {
        file_put_contents($this->workspace.'/composer.json', '{invalid');

        $this->expectException(HarbourException::class);
        $this->expectExceptionMessage('invalid JSON');
        (new ProjectInstaller($this->workspace))->install(self::selection());
    }

    public function test_it_rejects_a_non_object_composer_manifest(): void
    {
        file_put_contents($this->workspace.'/composer.json', '[]');

        $this->expectException(HarbourException::class);
        $this->expectExceptionMessage('JSON object');
        (new ProjectInstaller($this->workspace))->install(self::selection());
    }

    public function test_it_rejects_non_object_composer_scripts(): void
    {
        file_put_contents($this->workspace.'/composer.json', '{"scripts":"invalid"}');

        $this->expectException(HarbourException::class);
        $this->expectExceptionMessage('scripts must be a JSON object');
        (new ProjectInstaller($this->workspace))->install(self::selection());
    }

    public function test_it_inserts_scripts_without_reformatting_the_composer_manifest(): void
    {
        $manifest = "{\r\n\t\"name\": \"acme/app\",\r\n\t\"scripts\": {\r\n\t\t\"test\": \"phpunit\"\r\n\t},\r\n\t\"extra\": {\"kept\":true}\r\n}\r\n";
        file_put_contents($this->workspace.'/composer.json', $manifest);

        (new ProjectInstaller($this->workspace))->install(self::selection());

        $updated = (string) file_get_contents($this->workspace.'/composer.json');
        self::assertStringContainsString("\t\"name\": \"acme/app\"", $updated);
        self::assertStringContainsString("\t\"extra\": {\"kept\":true}", $updated);
        self::assertStringContainsString("\t\t\"test\": \"phpunit\",", $updated);
        self::assertStringNotContainsString("\n    \"name\"", $updated);
        self::assertSame(0, preg_match('/(?<!\r)\n/', $updated));
    }

    public function test_it_preserves_compact_json_and_finds_only_the_top_level_scripts_property(): void
    {
        $manifest = json_encode([
            'extra' => ['scripts' => ['nested' => 'keep}']],
            'scripts' => ['test' => 'say "{ok}"'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($this->workspace.'/composer.json', $manifest);

        (new ProjectInstaller($this->workspace))->install(self::selection());

        $updated = (string) file_get_contents($this->workspace.'/composer.json');
        self::assertStringStartsWith('{"extra":{"scripts":{"nested":"keep}"}},"scripts":{"test":"say \\"{ok}\\"",', $updated);
        self::assertStringNotContainsString("\n", $updated);
        $decoded = json_decode($updated, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $extra = $decoded['extra'] ?? null;
        self::assertIsArray($extra);
        $nestedScripts = $extra['scripts'] ?? null;
        self::assertIsArray($nestedScripts);
        self::assertSame('keep}', $nestedScripts['nested'] ?? null);
    }

    /** @return array<string, mixed> */
    private function composerScripts(): array
    {
        $manifest = json_decode((string) file_get_contents($this->workspace.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $scripts = $manifest['scripts'] ?? null;
        self::assertIsArray($scripts);

        $result = [];
        foreach ($scripts as $name => $commands) {
            self::assertIsString($name);
            $result[$name] = $commands;
        }

        return $result;
    }

    private static function selection(): InstallationSelection
    {
        return new InstallationSelection('sqlite', 'file', 'log');
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            is_dir($child) && ! is_link($child) ? $this->removeDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }
}

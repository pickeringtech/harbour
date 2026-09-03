<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Installation\InstallationFileRenderer;
use PickeringTech\Harbour\Installation\InstallationSelection;
use PickeringTech\Harbour\Installation\ProjectConfigurationDetector;

final class InstallationDiscoveryPropertyTest extends TestCase
{
    use TestTrait;

    public function test_arbitrary_compose_text_cannot_escape_the_installation_catalogue(): void
    {
        $this->forAll(Generators::string())->then(function (string $yaml): void {
            $workspace = $this->workspace();

            try {
                file_put_contents($workspace.'/compose.yaml', $yaml);
                $selection = (new ProjectConfigurationDetector($workspace))->discover()->selection;

                self::assertContains($selection->database, InstallationSelection::DATABASES);
                self::assertContains($selection->cache, InstallationSelection::CACHES);
                self::assertContains($selection->mail, InstallationSelection::MAILERS);
                foreach ($selection->services() as $service) {
                    self::assertContains($service, InstallationSelection::SAIL_SERVICES);
                }
            } finally {
                $this->removeDirectory($workspace);
            }
        });
    }

    public function test_arbitrary_existing_credentials_are_referenced_but_never_copied(): void
    {
        $this->forAll(Generators::string())->then(function (string $input): void {
            $workspace = $this->workspace();
            $credential = 'credential_'.hash('sha256', $input);

            try {
                file_put_contents($workspace.'/.env', "DB_CONNECTION=pgsql\nDB_PASSWORD={$credential}\nCACHE_STORE=file\nMAIL_MAILER=log\n");
                $discovery = (new ProjectConfigurationDetector($workspace))->discover();
                $rendered = (new InstallationFileRenderer)->environment($discovery);

                self::assertStringContainsString('DB_PASSWORD=${DB_PASSWORD}', $rendered);
                self::assertStringNotContainsString($credential, $rendered);
                self::assertStringNotContainsString($credential, json_encode($discovery->metadata(), JSON_THROW_ON_ERROR));
            } finally {
                $this->removeDirectory($workspace);
            }
        });
    }

    private function workspace(): string
    {
        $workspace = sys_get_temp_dir().'/harbour-discovery-property-'.bin2hex(random_bytes(6));
        mkdir($workspace, 0700, true);
        file_put_contents($workspace.'/composer.json', "{\n    \"name\": \"acme/app\"\n}\n");

        return $workspace;
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

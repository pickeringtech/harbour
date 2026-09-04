<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Installation\InstallationComposeRenderer;
use PickeringTech\Harbour\Installation\InstallationSelection;
use PickeringTech\Harbour\Installation\InstallationServiceCatalog;

final class InstallationComposeRendererTest extends TestCase
{
    public function test_it_renders_a_readable_workspace_compose_stack_with_allocated_loopback_ports(): void
    {
        $selection = InstallationSelection::fromOptions(
            'pgsql',
            'redis',
            'mailpit',
            'meilisearch,minio,selenium',
            'compose',
        );

        $compose = (new InstallationComposeRenderer)->render($selection);

        self::assertStringContainsString("services:\n", $compose);
        self::assertStringContainsString("  pgsql:\n", $compose);
        self::assertStringContainsString("  redis:\n", $compose);
        self::assertStringContainsString("  mailpit:\n", $compose);
        self::assertStringContainsString("  meilisearch:\n", $compose);
        self::assertStringContainsString("  minio:\n", $compose);
        self::assertStringContainsString("  selenium:\n", $compose);
        self::assertStringContainsString('127.0.0.1:${DB_PORT}:5432', $compose);
        self::assertStringContainsString('127.0.0.1:${REDIS_PORT}:6379', $compose);
        self::assertStringContainsString('127.0.0.1:${MAIL_PORT}:1025', $compose);
        self::assertStringContainsString("healthcheck:\n", $compose);
        self::assertStringContainsString("volumes:\n", $compose);
        self::assertStringNotContainsString('laravel.test', $compose);
    }

    public function test_every_supported_service_has_compose_and_port_metadata(): void
    {
        $catalog = new InstallationServiceCatalog;
        $renderer = new InstallationComposeRenderer($catalog);

        foreach (InstallationSelection::SAIL_SERVICES as $service) {
            $selection = InstallationSelection::fromOptions(null, null, null, $service, 'compose');
            $compose = $renderer->render($selection);

            self::assertStringContainsString("  {$service}:\n", $compose, $service);
            self::assertNotSame([], $catalog->portsFor($service), $service);
        }
    }

    public function test_port_definitions_are_named_unique_and_valid(): void
    {
        $catalog = new InstallationServiceCatalog;
        $definitions = $catalog->portDefinitions(InstallationSelection::SAIL_SERVICES);

        self::assertArrayHasKey('DB_PORT', $definitions);
        self::assertArrayHasKey('REDIS_PORT', $definitions);
        self::assertArrayHasKey('MAIL_PORT', $definitions);
        self::assertArrayHasKey('SELENIUM_PORT', $definitions);

        foreach ($definitions as $variable => $definition) {
            self::assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]*$/', $variable);
            self::assertCount(2, $definition['range']);
            self::assertGreaterThanOrEqual(11000, $definition['range'][0]);
            self::assertLessThanOrEqual(65535, $definition['range'][1]);
            self::assertLessThanOrEqual($definition['range'][1], $definition['range'][0]);
        }
    }
}

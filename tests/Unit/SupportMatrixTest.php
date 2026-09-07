<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use JsonException;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Installation\InstallationSelection;

final class SupportMatrixTest extends TestCase
{
    public function test_every_installation_selection_has_an_evidence_classification(): void
    {
        $matrix = $this->matrix();
        $selections = $matrix['selections'] ?? null;
        self::assertIsArray($selections);

        $expected = [
            'database' => InstallationSelection::databases(),
            'cache' => InstallationSelection::caches(),
            'mail' => InstallationSelection::mailers(),
            'additional' => InstallationSelection::additionalServices(),
        ];

        foreach ($expected as $group => $names) {
            $documented = $selections[$group] ?? null;
            self::assertIsArray($documented, "Support matrix group [{$group}] is missing.");
            self::assertSame($names, array_keys($documented), "Support matrix group [{$group}] has drifted from the installer.");
        }
    }

    public function test_every_entry_uses_a_documented_level_and_explains_its_evidence(): void
    {
        $matrix = $this->matrix();
        $levels = $matrix['levels'] ?? null;
        $selections = $matrix['selections'] ?? null;
        $platforms = $matrix['platforms'] ?? null;
        $worktreeIntegrations = $matrix['worktree_integrations'] ?? null;
        self::assertSame(1, $matrix['schema'] ?? null);
        self::assertIsArray($levels);
        self::assertIsArray($selections);
        self::assertIsArray($platforms);
        self::assertIsArray($worktreeIntegrations);
        self::assertSame(['worktrunk'], array_keys($worktreeIntegrations));
        self::assertSame(['linux', 'macos', 'windows'], array_keys($platforms));

        foreach ([...$selections, 'worktree_integrations' => $worktreeIntegrations, 'platforms' => $platforms] as $group => $entries) {
            self::assertIsArray($entries);
            foreach ($entries as $name => $entry) {
                self::assertIsArray($entry);
                $level = $entry['level'] ?? null;
                $evidence = $entry['evidence'] ?? null;
                self::assertIsString($level, "[{$group}.{$name}] needs an evidence level.");
                self::assertArrayHasKey($level, $levels, "[{$group}.{$name}] uses an undocumented evidence level.");
                self::assertIsString($evidence, "[{$group}.{$name}] needs an evidence explanation.");
                self::assertNotSame('', trim($evidence), "[{$group}.{$name}] needs an evidence explanation.");
            }
        }
    }

    /** @return array<string, mixed> */
    private function matrix(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/docs/support-matrix.json');
        self::assertIsString($contents);

        try {
            $matrix = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::fail('Support matrix contains invalid JSON: '.$exception->getMessage());
        }
        self::assertIsArray($matrix);

        $result = [];
        foreach ($matrix as $name => $value) {
            self::assertIsString($name);
            $result[$name] = $value;
        }

        return $result;
    }
}

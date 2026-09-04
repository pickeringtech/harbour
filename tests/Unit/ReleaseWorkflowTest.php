<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ReleaseWorkflowTest extends TestCase
{
    public function test_reconciliation_is_gated_by_successful_ci_on_the_exact_intent_commit(): void
    {
        $workflow = $this->workflow('release-reconciliation.yml');

        self::assertStringContainsString("workflow_run:\n    workflows: [CI]", $workflow);
        self::assertStringContainsString("github.event.workflow_run.conclusion == 'success'", $workflow);
        self::assertStringContainsString('needs.detect.outputs.target == github.event.workflow_run.head_sha', $workflow);
        self::assertStringContainsString("github.event.workflow_run.head_sha || 'main'", $workflow);
        self::assertStringContainsString('permission-contents: write', $workflow);
        self::assertStringContainsString('persist-credentials: false', $workflow);
        self::assertStringNotContainsString('pull_request_target', $workflow);
        self::assertStringNotContainsString('force', $workflow);
    }

    public function test_pull_requests_validate_intent_but_cannot_append_the_ledger(): void
    {
        $workflow = $this->workflow('release-validation.yml');

        self::assertStringContainsString("- 'release-intent.json'", $workflow);
        self::assertStringContainsString('php tools/release.php validate-pr', $workflow);
        self::assertStringNotContainsString('contents: write', $workflow);
        self::assertStringNotContainsString('pull_request_target', $workflow);
    }

    private function workflow(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/'.$name);
        self::assertIsString($contents);

        return $contents;
    }
}

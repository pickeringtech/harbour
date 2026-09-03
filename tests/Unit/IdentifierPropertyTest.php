<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Identity\ContextIdentifier;
use PickeringTech\Harbour\Identity\WorkspaceIdentity;

final class IdentifierPropertyTest extends TestCase
{
    use TestTrait;

    public function test_arbitrary_branch_text_never_escapes_target_grammars(): void
    {
        $this->forAll(Generators::string())->then(function (string $branch): void {
            $hash = hash('sha256', $branch);
            $identity = new WorkspaceIdentity('ws_'.$hash, $branch === '' ? 'workspace' : $branch, $hash, $branch);
            $identifiers = new ContextIdentifier;

            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,62}$/', $identifiers->database($identity, $branch));
            self::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9_.-]{0,62}$/', $identifiers->docker($identity, $branch));
            self::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9_-]{0,62}$/', $identifiers->compose($identity, $branch));
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,128}$/', $identifiers->cookie($identity, $branch));
        });
    }
}

<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PickeringTech\Harbour\Release\GitTagPublisher;
use PickeringTech\Harbour\Release\ReleaseEntry;
use PickeringTech\Harbour\Release\ReleaseException;
use Symfony\Component\Process\Process;

final class ReleaseGitTagPublisherTest extends TestCase
{
    private string $directory;

    private string $remote;

    private string $privateKey;

    private string $commit;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir().'/harbour-release-tag-'.bin2hex(random_bytes(6));
        $this->directory = $root.'/work';
        $this->remote = $root.'/remote.git';
        mkdir($this->directory, 0700, true);
        $this->git(['init', '--initial-branch=main']);
        $this->git(['config', 'user.name', 'Test Author']);
        $this->git(['config', 'user.email', 'test@example.test']);
        file_put_contents($this->directory.'/README.md', "proof\n");
        $this->git(['add', 'README.md']);
        $this->git(['commit', '-m', 'proof']);
        $this->commit = trim($this->git(['rev-parse', 'HEAD']));

        $this->process(['git', 'init', '--bare', $this->remote]);
        $keyPath = $root.'/signing-key';
        $this->process(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'rpickz release signing', '-f', $keyPath]);
        $privateKey = file_get_contents($keyPath);
        self::assertIsString($privateKey);
        $this->privateKey = $privateKey;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory(dirname($this->directory));
    }

    public function test_it_creates_and_pushes_an_ssh_signed_annotated_tag_without_force(): void
    {
        $publisher = $this->publisher();
        $entry = new ReleaseEntry('v1.2.3', $this->commit);

        $tag = $publisher->createTagObject($entry);

        self::assertTrue($tag->annotated);
        self::assertTrue($tag->verified);
        self::assertSame('signed-locally', $tag->verificationReason);
        self::assertSame($entry->commit, $tag->commit);
        $object = $this->git(['cat-file', '-p', $tag->objectSha]);
        self::assertStringContainsString("object {$entry->commit}\n", $object);
        self::assertStringContainsString("tag {$entry->version}\n", $object);
        self::assertStringContainsString('tagger rpickz <31162594+rpickz@users.noreply.github.com>', $object);
        self::assertStringContainsString('-----BEGIN SSH SIGNATURE-----', $object);
        self::assertStringNotContainsString($this->privateKey, $object);

        self::assertTrue($publisher->createTagReference($tag));
        self::assertSame(
            $entry->commit,
            trim($this->process(['git', '--git-dir='.$this->remote, 'rev-parse', 'refs/tags/'.$entry->version.'^{}'])),
        );
        self::assertFalse($publisher->createTagReference($tag));
    }

    public function test_it_reuses_only_an_exact_existing_local_tag(): void
    {
        $publisher = $this->publisher();
        $entry = new ReleaseEntry('v1.2.3', $this->commit);
        $first = $publisher->createTagObject($entry);

        $second = $publisher->createTagObject($entry);

        self::assertSame($first->objectSha, $second->objectSha);

        file_put_contents($this->directory.'/README.md', "different\n");
        $this->git(['add', 'README.md']);
        $this->git(['commit', '-m', 'different']);
        $different = trim($this->git(['rev-parse', 'HEAD']));

        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage('will not be moved');
        $publisher->createTagObject(new ReleaseEntry('v1.2.3', $different));
    }

    public function test_it_rejects_invalid_signing_configuration(): void
    {
        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage('OpenSSH private key');

        new GitTagPublisher(
            $this->directory,
            'pickeringtech/harbour',
            'token',
            'not a key',
            'rpickz',
            '31162594+rpickz@users.noreply.github.com',
            'file://'.$this->remote,
        );
    }

    private function publisher(): GitTagPublisher
    {
        return new GitTagPublisher(
            $this->directory,
            'pickeringtech/harbour',
            'token',
            $this->privateKey,
            'rpickz',
            '31162594+rpickz@users.noreply.github.com',
            'file://'.$this->remote,
        );
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        return $this->process(['git', ...$arguments], $this->directory);
    }

    /** @param list<string> $command */
    private function process(array $command, ?string $directory = null): string
    {
        $process = new Process($command, $directory);
        $process->mustRun();

        return $process->getOutput();
    }

    private function removeDirectory(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            is_dir($child) ? $this->removeDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }
}

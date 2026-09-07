<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

final readonly class InstallationResult
{
    /**
     * @param  list<string>  $created
     * @param  list<string>  $updated
     * @param  list<string>  $unchanged
     * @param  list<string>  $conflicts
     */
    public function __construct(
        public array $created,
        public array $updated,
        public array $unchanged,
        public array $conflicts,
        public InstallationSelection $selection,
        /** @var array{detected: bool, sources: list<string>} */
        public array $discovery = ['detected' => false, 'sources' => []],
        /** @var list<string> */
        public array $reconfigure = [],
        /** @var list<string> */
        public array $worktreeHooks = [],
    ) {}

    public function withProjectFile(string $path, string $change, string $integration): self
    {
        $created = $this->created;
        $updated = $this->updated;
        $unchanged = $this->unchanged;
        match ($change) {
            'created' => $created[] = $path,
            'updated' => $updated[] = $path,
            default => $unchanged[] = $path,
        };

        return new self(
            $created,
            $updated,
            $unchanged,
            $this->conflicts,
            $this->selection,
            $this->discovery,
            $this->reconfigure,
            [...$this->worktreeHooks, $integration],
        );
    }

    /** @return array{created: list<string>, updated: list<string>, unchanged: list<string>, conflicts: list<string>, selection: array{database: string, cache: string, mail: string, services: list<string>, provider: string, redis_client: string}, discovery: array{detected: bool, sources: list<string>}, reconfigure: list<string>, worktree_hooks: list<string>} */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'conflicts' => $this->conflicts,
            'selection' => $this->selection->toArray(),
            'discovery' => $this->discovery,
            'reconfigure' => $this->reconfigure,
            'worktree_hooks' => $this->worktreeHooks,
        ];
    }
}

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
    ) {}

    /** @return array{created: list<string>, updated: list<string>, unchanged: list<string>, conflicts: list<string>, selection: array{database: string, cache: string, mail: string, services: list<string>, provider: string}, discovery: array{detected: bool, sources: list<string>}, reconfigure: list<string>} */
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
        ];
    }
}

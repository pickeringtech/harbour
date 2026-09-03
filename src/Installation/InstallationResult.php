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
    ) {}

    /** @return array{created: list<string>, updated: list<string>, unchanged: list<string>, conflicts: list<string>} */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'conflicts' => $this->conflicts,
        ];
    }
}

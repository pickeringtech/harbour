<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Installation;

final readonly class UninstallationResult
{
    /**
     * @param  list<string>  $removed
     * @param  list<string>  $absent
     * @param  list<string>  $retained
     */
    public function __construct(
        public array $removed,
        public array $absent,
        public array $retained,
    ) {}

    /** @return array{removed: list<string>, absent: list<string>, retained: list<string>} */
    public function toArray(): array
    {
        return [
            'removed' => $this->removed,
            'absent' => $this->absent,
            'retained' => $this->retained,
        ];
    }
}

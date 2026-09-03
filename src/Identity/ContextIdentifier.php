<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Identity;

final class ContextIdentifier
{
    public function database(WorkspaceIdentity $identity, string $project): string
    {
        return $this->bounded($project.'_'.$identity->slug(), '_', 63, true);
    }

    public function docker(WorkspaceIdentity $identity, string $service): string
    {
        return $this->bounded($service.'-'.$identity->slug(), '-', 63, false, true);
    }

    public function compose(WorkspaceIdentity $identity, string $stack = 'workspace'): string
    {
        return $this->bounded($stack.'-'.$identity->slug(), '-', 63);
    }

    public function cookie(WorkspaceIdentity $identity, string $project): string
    {
        return $this->bounded($project.'_'.$identity->slug(), '_', 128);
    }

    public function redis(WorkspaceIdentity $identity, string $project): string
    {
        return $this->bounded($project.'_'.$identity->slug(), '_', 96).':';
    }

    public function filesystem(WorkspaceIdentity $identity, string $name): string
    {
        return $this->bounded($name.'-'.$identity->slug(), '-', 128);
    }

    private function bounded(string $value, string $separator, int $maximum, bool $letterFirst = false, bool $allowDots = false): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $ascii = strtolower($transliterated === false ? '' : $transliterated);
        $pattern = $allowDots ? '/[^a-z0-9_.-]+/' : '/[^a-z0-9]+/';
        $safe = trim((string) preg_replace($pattern, $separator, $ascii), '._-');

        if ($safe === '' || ($letterFirst && ! preg_match('/^[a-z]/', $safe))) {
            $safe = 'w_'.$safe;
        }

        $suffix = substr(hash('sha256', $value), 0, 8);
        $prefixLength = $maximum - strlen($suffix) - 1;

        return rtrim(substr($safe, 0, $prefixLength), '._-').$separator.$suffix;
    }
}

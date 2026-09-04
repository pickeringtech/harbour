<?php

declare(strict_types=1);

namespace PickeringTech\Harbour\Process;

final class ProcessFailure
{
    private const STDERR_LIMIT = 4096;

    /**
     * @param  array<string, scalar|null>  $environment
     * @return array<string, int|string>
     */
    public static function context(ProcessResult $result, array $environment = []): array
    {
        $context = ['exit_code' => $result->exitCode];
        $stderr = self::stderrTail($result->errorOutput, $environment);

        if ($stderr !== '') {
            $context['stderr'] = $stderr;
        }

        return $context;
    }

    /** @param array<string, scalar|null> $environment */
    public static function stderrTail(string $stderr, array $environment = []): string
    {
        $redacted = str_replace("\0", '', $stderr);
        $redacted = preg_replace('/\x1B(?:[@-Z\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $redacted) ?? $redacted;

        foreach ($environment as $name => $value) {
            if (! preg_match('/(?:APP_KEY|PASSWORD|PASSWD|TOKEN|SECRET|PRIVATE_KEY|API_KEY|CREDENTIAL)/i', $name)) {
                continue;
            }
            $secret = (string) $value;
            if ($secret !== '') {
                $redacted = str_replace($secret, '[REDACTED]', $redacted);
            }
        }

        $redacted = preg_replace(
            '/\b(password|passwd|token|secret|api[_-]?key|access[_-]?key|private[_-]?key|credential)(\s*[=:]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/i',
            '$1$2[REDACTED]',
            $redacted,
        ) ?? $redacted;
        $redacted = preg_replace('~(://[^:/\s]+:)[^@\s]+(@)~', '$1[REDACTED]$2', $redacted) ?? $redacted;
        $redacted = trim($redacted);

        if (strlen($redacted) <= self::STDERR_LIMIT) {
            return $redacted;
        }

        return '…'.substr($redacted, -self::STDERR_LIMIT);
    }
}

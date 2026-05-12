<?php

namespace App\Services;

use App\Models\CommandLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class TerminalService
{
    public function __construct(
        private readonly SetupConfigurationService $setupConfiguration,
        private readonly RemoteServerService $remoteServer,
    ) {
    }

    public function run(string $command, ?string $cwd, User $user, Request $request): CommandLog
    {
        $started = microtime(true);
        $command = trim($command);
        $setup = $this->setupConfiguration->current();
        $remoteMode = $this->setupConfiguration->isRemoteConfigured($setup);
        $cwd = $remoteMode
            ? $this->remoteServer->normalizeRemoteCwd($setup, $cwd)
            : $this->resolveCwd($cwd ?: base_path());
        $output = '';
        $exitCode = null;
        $status = 'ok';

        try {
            $this->assertEnabled();
            $tokens = $this->tokenizeAllowedCommand($command);

            if ($remoteMode) {
                $result = $this->remoteServer->runTerminal(
                    $setup,
                    $tokens,
                    $cwd,
                    (int) config('winmap_admin.terminal.timeout', 12)
                );

                $exitCode = (int) ($result['exit_code'] ?? 0);
                $output = ($result['stdout'] ?? '').($result['stderr'] ?? '');
                $status = $exitCode === 0 ? 'ok' : 'failed';
            } else {
                $process = new Process($tokens, $cwd, null, null, (float) config('winmap_admin.terminal.timeout', 12));
                $process->run();

                $exitCode = $process->getExitCode();
                $output = $process->getOutput().$process->getErrorOutput();
                $status = $process->isSuccessful() ? 'ok' : 'failed';
            }
        } catch (Throwable $e) {
            $status = 'blocked';
            $output = $e->getMessage();
        }

        $maxOutput = (int) config('winmap_admin.terminal.max_output_bytes', 60000);
        if (strlen($output) > $maxOutput) {
            $output = substr($output, 0, $maxOutput)."\n...[output truncated]";
        }

        return CommandLog::create([
            'user_id' => $user->id,
            'status' => $status,
            'cwd' => $cwd,
            'command' => $command,
            'exit_code' => $exitCode,
            'output' => $output,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);
    }

    private function assertEnabled(): void
    {
        if (! config('winmap_admin.terminal.enabled', true)) {
            throw new \RuntimeException('Terminal is disabled by TERMINAL_ENABLED=false.');
        }
    }

    /**
     * Converts one safe command line into argv.
     *
     * Shell control operators are intentionally blocked. This terminal is for
     * audited admin operations, not arbitrary shell scripting from a browser.
     *
     * @return array<int, string>
     */
    private function tokenizeAllowedCommand(string $command): array
    {
        if ($command === '') {
            throw new \InvalidArgumentException('Command is required.');
        }

        if (preg_match('/[;&|<>`$\\n\\r]/', $command)) {
            throw new \RuntimeException('Shell control operators are not allowed.');
        }

        $tokens = array_values(array_filter(str_getcsv($command, ' ', '"', '\\'), fn ($token) => $token !== ''));
        $binary = $tokens[0] ?? '';
        $allowed = config('winmap_admin.terminal.allowed_commands', []);

        if (! in_array($binary, $allowed, true)) {
            throw new \RuntimeException('Command is not allowed: '.$binary);
        }

        return $tokens;
    }

    private function resolveCwd(string $cwd): string
    {
        $realCwd = realpath($cwd);
        if ($realCwd === false || ! is_dir($realCwd)) {
            throw new \InvalidArgumentException('Working directory does not exist.');
        }

        foreach (config('winmap_admin.terminal.allowed_roots', []) as $root) {
            $realRoot = realpath($root);
            if ($realRoot && ($realCwd === $realRoot || Str::startsWith($realCwd, rtrim($realRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))) {
                return $realCwd;
            }
        }

        throw new \RuntimeException('Working directory is outside allowed roots.');
    }
}

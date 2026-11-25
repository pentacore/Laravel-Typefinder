<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Services;

use Composer\Autoload\ClassLoader;
use Illuminate\Database\Connection;
use Pentacore\Typefinder\Protocol\ProtocolCodec;
use Pentacore\Typefinder\Protocol\ProtocolException;
use Pentacore\Typefinder\Support\ThrowableMessage;
use ReflectionClass;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

use function Illuminate\Support\php_binary;

/**
 * Answers the regen requests of `typefinder:watch`.
 *
 * PHP cannot reload a class: a process that has loaded one keeps its enum
 * cases, `rules()`, `$casts` and attributes as they were, whatever the file
 * says now (#124). So the watcher itself never extracts. Each request is
 * answered by a process that has not loaded the application's classes yet:
 *
 * - a fork of the watcher, which is booted already and loads the classes
 *   it needs from the files as they are now;
 * - a new `typefinder:watch --in-process` process when it cannot fork
 *   (no pcntl) or when a class the watcher loaded while booting has changed
 *   since, which a fork would inherit.
 *
 * Both run {@see self::inProcess()}, so they answer alike.
 */
final class RegenWorker
{
    /**
     * Files of the classes this process had loaded when it was created,
     * with a hash of what they contained.
     *
     * @var array<string, string|false>
     */
    private readonly array $loaded;

    /** @var array<string, true> */
    private array $noticed = [];

    private ?string $changed = null;

    /**
     * The command line of a watcher that answers in its own process.
     *
     * @var list<string>
     */
    private readonly array $command;

    /** Seconds a worker may take to answer; 0 for no limit. */
    private readonly float $timeout;

    /**
     * @param  resource  $stderr  told why requests are answered in a new process (once), and why a request failed
     * @param  ?list<string>  $command  defaults to the script this process was started with
     * @param  ?float  $timeout  seconds a worker may take to answer, 0 for no limit; defaults to `typefinder.watch.worker_timeout`
     */
    public function __construct(
        private readonly Generator $generator,
        private $stderr = STDERR,
        ?array $command = null,
        ?float $timeout = null,
    ) {
        $this->command = $command ?? [php_binary(), $_SERVER['argv'][0] ?? 'artisan', 'typefinder:watch', '--in-process'];
        $this->timeout = max(0.0, $timeout ?? (float) config('typefinder.watch.worker_timeout', 300));
        $this->loaded = $this->loadedClassFiles();
    }

    /**
     * Answer a request in a process of its own.
     *
     * @param  list<string>  $paths  absolute paths; empty for a full run
     * @return array<string, mixed> the `regen.done` or `regen.error` message
     */
    public function isolated(?string $id, array $paths): array
    {
        $reason = $this->whyNotAFork();

        if ($reason !== null) {
            $this->noticeOnce($reason.', so every regeneration starts a new PHP process');

            return $this->spawn($id, $paths);
        }

        return $this->fork($id, $paths) ?? $this->spawn($id, $paths);
    }

    /**
     * Answer a request in this process. Right only while this process has
     * not loaded the classes the request is about.
     *
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    public function inProcess(?string $id, array $paths): array
    {
        try {
            $result = $this->generator->generatePaths($paths);

            return [
                'type' => 'regen.done',
                'id' => $id,
                'duration_ms' => $result->durationMs,
                'changed' => $result->changed,
                'warnings' => $result->warnings,
                'failed' => $result->failed,
                'removed' => $result->removed,
            ];
        } catch (Throwable $throwable) {
            $message = ThrowableMessage::describe($throwable);
            $this->notice($message);

            return $this->error($id, $message);
        }
    }

    /**
     * Why a fork of this process would not answer right, or null when it would.
     */
    private function whyNotAFork(): ?string
    {
        if (! function_exists('pcntl_fork')) {
            return 'the pcntl extension is not available';
        }

        $changed = $this->changedSinceLoaded();

        // A fork inherits the class as this process loaded it.
        return $changed === null ? null : $changed.' changed since the watcher started (restart the dev server to make it fast again)';
    }

    /**
     * @param  list<string>  $paths
     * @return ?array<string, mixed> null when this process could not fork
     */
    private function fork(?string $id, array $paths): ?array
    {
        $this->disconnectDatabases();

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = $sockets === false ? -1 : pcntl_fork();

        if ($pid === -1) {
            return null;
        }

        [$fromWorker, $toWatcher] = $sockets;

        if ($pid === 0) {
            // The worker. It ends here, so the watcher's loop never runs in it.
            fwrite($toWatcher, ProtocolCodec::encode($this->inProcess($id, $paths)));
            exit(0);
        }

        // The worker holds the other end; closing it here lets its exit end the read.
        fclose($toWatcher);
        $answer = $this->readUntilClosed($fromWorker);
        fclose($fromWorker);

        if ($answer === null) {
            // Hung: stop it, so it does not hold up every later request (#209).
            if (function_exists('posix_kill')) {
                posix_kill($pid, SIGKILL);
            } else {
                new Process(['kill', '-9', (string) $pid])->run();
            }
            pcntl_waitpid($pid, $status);

            return $this->timedOut($id);
        }

        pcntl_waitpid($pid, $status);

        return $this->decode($answer)
            ?? $this->error($id, sprintf('the regen worker stopped without an answer (%s)', $this->describeExit($status)));
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    private function spawn(?string $id, array $paths): array
    {
        $process = new Process($this->command);
        $process->setTimeout($this->timeout > 0 ? $this->timeout : null);
        $process->setInput(ProtocolCodec::encode(['type' => 'regen', 'id' => $id, 'paths' => $paths]));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            fwrite($this->stderr, $process->getErrorOutput());

            return $this->timedOut($id);
        }

        fwrite($this->stderr, $process->getErrorOutput());

        // The handshake comes first; the answer is the line after it.
        foreach (explode("\n", $process->getOutput()) as $line) {
            $answer = $this->decode($line);
            if ($answer !== null) {
                return $answer;
            }
        }

        return $this->error($id, sprintf('the regen process stopped without an answer (exit code %s)', $process->getExitCode() ?? 'unknown'));
    }

    /**
     * Everything the worker writes until it closes its end.
     *
     * @param  resource  $stream
     * @return ?string null when the worker did not finish within the timeout
     */
    private function readUntilClosed($stream): ?string
    {
        $deadline = $this->timeout > 0 ? microtime(true) + $this->timeout : null;
        $answer = '';

        while (! feof($stream)) {
            $wait = $deadline === null ? null : $deadline - microtime(true);
            if ($wait !== null && $wait <= 0) {
                return null;
            }

            $read = [$stream];
            $write = [];
            $except = [];
            $ready = @stream_select($read, $write, $except, $wait === null ? null : (int) $wait, $wait === null ? null : (int) (fmod($wait, 1) * 1_000_000));

            // false: interrupted by a signal; 0: the timeout passed, checked above.
            if ($ready === false || $ready === 0) {
                continue;
            }

            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                break;
            }

            $answer .= $chunk;
        }

        return $answer;
    }

    /**
     * @return array{type: 'regen.error', id: ?string, message: string}
     */
    private function timedOut(?string $id): array
    {
        $message = sprintf('the regen worker did not answer within %s seconds and was stopped (typefinder.watch.worker_timeout)', rtrim(rtrim(sprintf('%.3F', $this->timeout), '0'), '.'));
        $this->notice($message);

        return $this->error($id, $message);
    }

    /**
     * @return ?array<string, mixed> the answer in a line, or null when the line is none
     */
    private function decode(string $line): ?array
    {
        try {
            $message = ProtocolCodec::decode($line);
        } catch (ProtocolException) {
            return null;
        }

        return in_array($message['type'], ['regen.done', 'regen.error'], true) ? $message : null;
    }

    /**
     * @return array{type: 'regen.error', id: ?string, message: string}
     */
    private function error(?string $id, string $message): array
    {
        return ['type' => 'regen.error', 'id' => $id, 'message' => $message];
    }

    private function describeExit(int $status): string
    {
        if (pcntl_wifsignaled($status)) {
            return 'signal '.pcntl_wtermsig($status);
        }

        return 'exit code '.pcntl_wexitstatus($status);
    }

    /**
     * Close the database connections of this process. A fork would share
     * them, and the first worker to exit would close them under the next.
     * Each worker opens its own instead.
     */
    private function disconnectDatabases(): void
    {
        /** @var array<string, Connection> $connections */
        $connections = app()->resolved('db') ? app('db')->getConnections() : [];

        foreach ($connections as $connection) {
            // An in-memory database lives in this process and is copied to the fork.
            if ($connection->getConfig('database') !== ':memory:') {
                $connection->disconnect();
            }
        }
    }

    /**
     * The file of a class this process loaded before its first request that
     * no longer has the content it was loaded with, if any.
     */
    private function changedSinceLoaded(): ?string
    {
        if ($this->changed !== null) {
            return $this->changed;
        }

        // The content, not the modification time: that only counts seconds,
        // so an edit right after the class was loaded would go unseen.
        foreach ($this->loaded as $file => $hash) {
            if (@sha1_file($file) !== $hash) {
                return $this->changed = $file;
            }
        }

        return null;
    }

    /**
     * The files that declare the classes, interfaces, traits and enums this
     * process has loaded, less the Composer packages: those do not change
     * while the watcher runs.
     *
     * @return array<string, string|false>
     */
    private function loadedClassFiles(): array
    {
        $vendor = dirname((string) new ReflectionClass(ClassLoader::class)->getFileName(), 2).DIRECTORY_SEPARATOR;
        $files = [];

        foreach ([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()] as $name) {
            $file = new ReflectionClass($name)->getFileName();

            if ($file === false || isset($files[$file]) || str_starts_with($file, $vendor) || ! is_file($file)) {
                continue;
            }

            $files[$file] = @sha1_file($file);
        }

        return $files;
    }

    private function noticeOnce(string $message): void
    {
        if (isset($this->noticed[$message])) {
            return;
        }

        $this->noticed[$message] = true;
        $this->notice($message);
    }

    private function notice(string $message): void
    {
        fwrite($this->stderr, '[typefinder] '.$message."\n");
    }
}

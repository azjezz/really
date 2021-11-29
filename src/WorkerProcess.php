<?php

declare(strict_types=1);

namespace Really;

use Closure;
use Psl;
use Psl\Dict;
use Psl\Env;
use Psl\IO\Stream;
use Psl\Network;
use Psl\Async;
use Psl\Str;

use function proc_open;
use function proc_terminate;

use const PHP_BINARY;
use const PHP_OS_FAMILY;

final class WorkerProcess
{
    public function __construct(
        private int $id,
        /**
         * @var resource|null $process
         */
        private mixed                           $process,
        private Stream\CloseReadHandleInterface $stdout,
        private Stream\CloseReadHandleInterface $stderr,
    )
    {
    }


    public static function spawn(Network\ServerInterface $server, string $script, int $id, int $concurrency_level): self
    {
        $commandline = Str\join([PHP_BINARY, $script], ' ');
        $options = [];
        if (PHP_OS_FAMILY === 'Windows') {
            $commandline = 'cmd /V:ON /E:ON /D /C (' . $commandline . ')';
            $options = [
                'bypass_shell' => true,
                'blocking_pipes' => false,
            ];
        }

        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $environment = Dict\merge(Env\get_vars(), [
            'REALLY_SERVER' => $server->getLocalAddress()->toString(),
            'REALLY_IDENTIFIER' => $id,
            'REALLY_CONCURRENCY_LEVEL' => $concurrency_level,
        ]);

        /** @var resource $process */
        $process = proc_open($commandline, $descriptor, $pipes, Env\current_dir(), $environment, $options);

        $stdout = new Stream\CloseReadHandle($pipes[1]);
        $stderr = new Stream\CloseReadHandle($pipes[2]);

        return new self($id, $process, $stdout, $stderr);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getStdout(): Stream\CloseReadHandleInterface
    {
        Psl\invariant($this->process !== null, 'Worker has been killed.');

        return $this->stdout;
    }

    public function getStderr(): Stream\CloseReadHandleInterface
    {
        Psl\invariant($this->process !== null, 'Worker has been killed.');

        return $this->stderr;
    }

    public function kill(): void
    {
        if (null === $this->process) {
            return;
        }

        $this->stdout->close();
        $this->stderr->close();

        $process = $this->process;
        $this->process = null;
        proc_terminate($process);
    }
}


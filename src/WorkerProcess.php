<?php

declare(strict_types=1);

namespace Really;

use Psl;
use Psl\Dict;
use Psl\Env;
use Psl\IO;
use Psl\Network;
use Psl\Str;

use function proc_close;
use function proc_open;
use function proc_terminate;

use const SIGTERM;
use const PHP_BINARY;
use const PHP_OS_FAMILY;

final class WorkerProcess
{
    public function __construct(
        private int                             $id,
        /**
         * @var resource|null $process
         */
        private mixed                           $process,
        private IO\CloseReadHandleInterface $stdout,
        private IO\CloseReadHandleInterface $stderr,
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
        } else {
            $commandline = 'exec ' . $commandline;
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

        $stdout = new IO\CloseReadStreamHandle($pipes[1]);
        $stderr = new IO\CloseReadStreamHandle($pipes[2]);

        return new self($id, $process, $stdout, $stderr);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getStdout(): IO\CloseReadHandleInterface
    {
        Psl\invariant($this->process !== null, 'Worker has been killed.');

        return $this->stdout;
    }

    public function getStderr(): IO\CloseReadHandleInterface
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

        unset($this->stdout, $this->stderr);

        $process = $this->process;
        $this->process = null;

        proc_terminate($process, SIGTERM);
        proc_close($process);
    }
}


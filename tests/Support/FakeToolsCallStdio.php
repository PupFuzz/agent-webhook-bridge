<?php

namespace Tests\Support;

use App\Bridge\Tools\ToolsCallStdio;
use Tests\Feature\AgentTools\ToolsCallCommandTest;

/**
 * Captures the three streams for the in-process `bridge:tools-call` test — the seam
 * that lets a test read the REAL fd-1 bytes the command wrote, which is what the ssh
 * channel returns to the caller.
 *
 * Hoisted out of {@see ToolsCallCommandTest} at its SECOND
 * caller (card#9155): the cross-door equivalence test drives BOTH doors in one method,
 * so it needs this seam too, and a class declared inside another test FILE is only
 * loaded when that file is — a `--filter` run of one class would fatal on it.
 */
class FakeToolsCallStdio extends ToolsCallStdio
{
    /** @var resource */
    private $inStream;

    /** @var resource */
    private $outStream;

    /** @var resource */
    private $errStream;

    public function __construct(string $stdin)
    {
        $this->inStream = fopen('php://memory', 'r+');
        fwrite($this->inStream, $stdin);
        rewind($this->inStream);
        $this->outStream = fopen('php://memory', 'r+');
        $this->errStream = fopen('php://memory', 'r+');
    }

    public function in()
    {
        return $this->inStream;
    }

    public function out()
    {
        return $this->outStream;
    }

    public function err()
    {
        return $this->errStream;
    }

    public function capturedOut(): string
    {
        rewind($this->outStream);

        return (string) stream_get_contents($this->outStream);
    }

    public function capturedErr(): string
    {
        rewind($this->errStream);

        return (string) stream_get_contents($this->errStream);
    }
}

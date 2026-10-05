<?php

namespace Covey\Laravel;

use Psy\Output\PassthruPager;
use Psy\Output\ShellOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

// psysh writes through its own output class, and that class writes to the
// process's stdout — fine in a terminal, useless in a request. This one keeps
// everything the shell says in memory, paged or not, so that it can be the
// response body.
final class TinkerOutput extends ShellOutput
{
    /** @var resource */
    private $sink;

    public function __construct()
    {
        $this->sink = fopen('php://memory', 'w+');
        parent::__construct(OutputInterface::VERBOSITY_NORMAL, false, null, new PassthruPager(new StreamOutput($this->sink)));
    }

    public function doWrite($message, $newline): void
    {
        fwrite($this->sink, $message.($newline ? PHP_EOL : ''));
    }

    public function fetch(): string
    {
        rewind($this->sink);
        $text = stream_get_contents($this->sink);
        ftruncate($this->sink, 0);
        rewind($this->sink);

        return $text === false ? '' : $text;
    }
}

<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Bridge\BridgeCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\QueryException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class BridgeCommandDbGuardTest extends TestCase
{
    private function command(BufferedOutput $buffer): BridgeCommand
    {
        $cmd = new class extends BridgeCommand
        {
            protected $signature = 'test:db-guard';

            public function exposeGuard(\Closure $body): int
            {
                return $this->guardDatabase($body);
            }
        };
        $cmd->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        return $cmd;
    }

    public function test_passes_through_a_successful_body(): void
    {
        $cmd = $this->command(new BufferedOutput);

        $this->assertSame(0, $cmd->exposeGuard(fn (): int => 0));
    }

    public function test_turns_a_query_exception_into_a_clean_failure(): void
    {
        $cmd = $this->command($buffer = new BufferedOutput);

        $result = $cmd->exposeGuard(fn (): int => throw new QueryException(
            'sqlite', 'select 1', [], new \PDOException('SQLSTATE[HY000] [2002] Connection refused'),
        ));

        $this->assertSame(BridgeCommand::FAILURE, $result);
        $out = $buffer->fetch();
        $this->assertStringContainsString('database unreachable', $out);
        $this->assertStringNotContainsString('Stack trace', $out);   // no raw trace dumped (#2056)
    }

    public function test_non_db_exceptions_propagate(): void
    {
        $cmd = $this->command(new BufferedOutput);

        $this->expectException(\RuntimeException::class);
        $cmd->exposeGuard(fn (): int => throw new \RuntimeException('not a db error'));
    }
}

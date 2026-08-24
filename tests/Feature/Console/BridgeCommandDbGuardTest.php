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

    public function test_a_query_failure_on_an_unreachable_server_names_the_host(): void
    {
        // The connection genuinely cannot be opened, so the credentials/host advice is the
        // right advice — and the probe has to actually run for that to be a measurement.
        config(['database.connections.dead-probe' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1,
            'database' => 'nothing', 'username' => 'nobody', 'password' => '',
        ]]);
        $cmd = $this->command($buffer = new BufferedOutput);

        $result = $cmd->exposeGuard(fn (): int => throw new QueryException(
            'dead-probe', 'select 1', [], new \PDOException('SQLSTATE[HY000] [2002] Connection refused'),
        ));

        $this->assertSame(BridgeCommand::FAILURE, $result);
        $out = $buffer->fetch();
        $this->assertStringContainsString('database unreachable', $out);
        $this->assertStringNotContainsString('Stack trace', $out);   // no raw trace dumped (#2056)
    }

    public function test_a_query_failure_on_a_server_that_answers_does_not_blame_connectivity(): void
    {
        // The other half of the same variable, and the reason it is now measured: an install
        // that upgraded without running `php artisan migrate` hits this branch, and the old
        // single message sent its operator to check DB_HOST — which is the one thing the probe
        // has just proved is fine. Driver-independent by construction: this is the SQLite leg,
        // where a missing table is SQLSTATE HY000 rather than MariaDB's 42S02, and no arm here
        // reads the code at all.
        $cmd = $this->command($buffer = new BufferedOutput);

        $result = $cmd->exposeGuard(fn (): int => throw new QueryException(
            config('database.default'), 'select * from nope', [], new \PDOException('no such table: nope'),
        ));

        $this->assertSame(BridgeCommand::FAILURE, $result);
        $out = $buffer->fetch();
        $this->assertStringContainsString('the server ANSWERED', $out);
        $this->assertStringContainsString('php artisan migrate', $out);
        $this->assertStringNotContainsString('database unreachable', $out);
    }

    public function test_non_db_exceptions_propagate(): void
    {
        $cmd = $this->command(new BufferedOutput);

        $this->expectException(\RuntimeException::class);
        $cmd->exposeGuard(fn (): int => throw new \RuntimeException('not a db error'));
    }
}

<?php

namespace Tests\Feature\Support;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\RedactedErrorText;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\Fixtures\DualTargetClassifier;
use Tests\Fixtures\RecordingHandler;
use Tests\Support\StraddlingRequestException;
use Tests\TestCase;

/**
 * ⛔ A `RequestException` that escapes the bridge reaches the FRAMEWORK's exception handler,
 * and every sink it owns is exercised here through the real one (card#9486, DL-389). A durable
 * writeback handler rethrows a transient 5xx on purpose, so kanban re-delivers the webhook, and
 * nothing between that rethrow and the handler catches it — not `DispatchService`, not
 * `WebhookController`, not `bridge:replay`.
 *
 * Each sink is asserted on BOTH halves: the fragment Laravel's own message truncation leaves
 * (`svc:canary`) is ABSENT, and the redacted status and body are PRESENT. A sink that printed
 * nothing at all would pass the first half alone.
 */
class RequestExceptionReportingTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/bridge-c9486-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        $this->log = $this->dir.'/laravel.log';
        config([
            'logging.default' => 'single',
            'logging.channels.single.path' => $this->log,
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
        ]);
        app('log')->forgetChannel('single');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_reported_request_exception_is_logged_redacted_with_its_class_status_and_stack(): void
    {
        $e = StraddlingRequestException::make();
        $response = $e->response;

        app(ExceptionHandler::class)->report($e);

        // What a `catch (RequestException)`, a status split or a retry decision reads is untouched.
        $this->assertSame(422, $e->getCode());
        $this->assertSame($response, $e->response);
        $this->assertStringContainsString('StraddlingRequestException.php', $e->getFile());
        $written = (string) file_get_contents($this->log);

        $this->assertStringNotContainsString(StraddlingRequestException::STEM, $written);
        $this->assertStringContainsString('ERROR: HTTP request returned status code 422: ', $written);
        $this->assertStringContainsString('https:\\\\/\\\\/***@bridge.example.com', $written, 'the redacted body is in the line');
        $this->assertStringContainsString('"exception":"'.str_replace('\\', '\\\\', RequestException::class).'"', $written);
        $this->assertStringContainsString('"status":422', $written);
        $this->assertStringContainsString('StraddlingRequestException.php:', $written, 'the throw site');
        $this->assertStringContainsString('#0 ', $written, 'the stack');
    }

    public function test_an_uncaught_request_exception_renders_redacted_on_the_console(): void
    {
        Artisan::command('c9486:throws', fn () => throw StraddlingRequestException::make());
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        $status = app(ConsoleKernel::class)->handle(new ArrayInput(['command' => 'c9486:throws']), $output);
        $rendered = $output->fetch();

        $this->assertSame(1, $status);
        $this->assertStringNotContainsString(StraddlingRequestException::STEM, $rendered);
        $this->assertStringContainsString('HTTP request returned status code 422', $rendered);
        $this->assertStringNotContainsString(StraddlingRequestException::STEM, (string) file_get_contents($this->log));
    }

    /**
     * The route the review measured: a durable handler's rethrow, out of `DispatchService`, out of
     * `bridge:replay`, into the console kernel's report-then-render.
     */
    public function test_bridge_replay_renders_a_durable_handlers_rethrown_request_exception_redacted(): void
    {
        File::put($this->dir.'/prod-agent.yml', "subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."classifier:\n  class: '".DualTargetClassifier::class."'\n");
        $registry = new HandlerRegistry;
        $registry->register('be', new RecordingHandler('be'));
        $registry->register('dur', new class implements DurableReaction, Handler
        {
            public function handle(ReactionTarget $target, AgentConfig $agent): void
            {
                throw StraddlingRequestException::make();
            }
        });
        $this->app->instance(HandlerRegistry::class, $registry);
        $event = WebhookEvent::create([
            'delivery_id' => 'evt-c9486', 'provider' => 'kanban', 'scope_id' => '5',
            'event_type' => 'task.created', 'actor_id' => '999', 'payload' => ['subject_id' => 42, 'board_id' => 5],
        ]);
        $output = new BufferedOutput;

        $status = app(ConsoleKernel::class)->handle(new ArrayInput(['command' => 'bridge:replay', 'id' => (string) $event->id]), $output);
        $rendered = $output->fetch();

        $this->assertSame(1, $status, $rendered);
        $this->assertStringNotContainsString(StraddlingRequestException::STEM, $rendered);
        $this->assertStringContainsString('HTTP request returned status code 422', $rendered);
        $written = (string) file_get_contents($this->log);
        $this->assertStringNotContainsString(StraddlingRequestException::STEM, $written);
        $this->assertStringContainsString('HTTP request returned status code 422', $written);
    }

    /**
     * A wrapper that is NOT a `RequestException` carries one as its `previous`: the log formatter
     * and the console renderer both print the whole chain's messages, so every `RequestException`
     * in it is rewritten, not only a top-level one. The chain here nests the fixture two deep.
     */
    public function test_a_request_exception_wrapped_as_a_previous_is_redacted_in_the_log_and_on_the_console(): void
    {
        $wrapped = static fn (): \RuntimeException => new \RuntimeException('dispatch failed', 0, new \LogicException('inner', 0, StraddlingRequestException::make()));

        app(ExceptionHandler::class)->report($wrapped());
        $written = (string) file_get_contents($this->log);
        $this->assertStringNotContainsString(StraddlingRequestException::STEM, $written);
        $this->assertStringContainsString('dispatch failed', $written, 'the wrapper is still reported by the default line');
        $this->assertStringContainsString('HTTP request returned status code 422: ', $written, 'the previous chain is still printed, redacted');

        Artisan::command('c9486:wraps', fn () => throw $wrapped());
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $this->assertSame(1, app(ConsoleKernel::class)->handle(new ArrayInput(['command' => 'c9486:wraps']), $output));
        $rendered = $output->fetch();
        $this->assertStringNotContainsString(StraddlingRequestException::STEM, $rendered);
        $this->assertStringContainsString('HTTP request returned status code 422', $rendered);
    }

    /** Reporting one object twice, or one a caught arm already rendered, yields one stable text. */
    public function test_the_rewrite_is_idempotent_across_repeated_reports(): void
    {
        $e = StraddlingRequestException::make();
        $viaCatch = RedactedErrorText::of($e);

        app(ExceptionHandler::class)->report(new \RuntimeException('first', 0, $e));
        $first = $e->getMessage();
        app(ExceptionHandler::class)->report($e);

        $this->assertSame($viaCatch, $first);
        $this->assertSame($first, $e->getMessage());
        $this->assertSame(RedactedErrorText::of($e), $e->getMessage());
    }

    public function test_an_uncaught_request_exception_renders_redacted_over_http_and_stays_a_5xx(): void
    {
        config(['app.debug' => true]);
        Route::post('/c9486-throws', fn () => throw StraddlingRequestException::make());

        $response = $this->postJson('/c9486-throws');

        $response->assertStatus(500);
        $this->assertStringNotContainsString(StraddlingRequestException::STEM, (string) $response->getContent());
        $this->assertStringContainsString('HTTP request returned status code 422', (string) $response->getContent());
    }
}

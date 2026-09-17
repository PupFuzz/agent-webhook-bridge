<?php

namespace Tests\Fixtures\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * A `bridge:` command that exists only under `OutputChokeTest`: it puts one payload through
 * each console write API the output choke must cover, including the ones no production
 * command uses today (`section()`, `setErrorOutput()`), so the choke is measured on the API
 * rather than on whichever producer happens to call it.
 *
 * Registered into the REAL `artisan` entry by `register-choke-fixture.php` (an
 * `auto_prepend_file`), so the run goes through `bootstrap/app.php`, `handleCommand()` and
 * the process's real fd 1 / fd 2 exactly as an operator's does.
 */
class ChokeFixtureCommand extends Command
{
    protected $signature = 'bridge:choke-fixture {arm} {--payload-file=}';

    protected $description = 'Test fixture: write a payload through one console API';

    public function handle(): int
    {
        $payload = (string) file_get_contents((string) $this->option('payload-file'));
        $output = $this->output->getOutput();

        switch ($this->argument('arm')) {
            case 'line':
                $this->line($payload);
                $this->info($payload);
                $this->error($payload);
                $this->warn($payload);
                $this->output->write($payload, true, OutputInterface::OUTPUT_RAW);
                $this->output->writeln($payload, OutputInterface::OUTPUT_PLAIN);
                break;
            case 'table':
                $this->table(['column'], [[$payload]]);
                break;
            case 'components':
                $this->components->info($payload);
                $this->components->error($payload);
                $this->components->twoColumnDetail($payload, $payload);
                $this->components->bulletList([$payload]);
                break;
            case 'exception':
                throw new RuntimeException($payload);
            case 'section':
                $section = $this->console($output)->section();
                $section->writeln($payload);
                $section->overwrite($payload);
                break;
            case 'stderr':
                $this->console($output)->getErrorOutput()->writeln($payload);
                break;
            case 'set-error-output':
                $console = $this->console($output);
                $console->setErrorOutput(new StreamOutput(STDERR));
                $console->getErrorOutput()->writeln($payload);
                break;
            case 'own-style':
                $this->info('OK');
                $this->error('BAD');
                break;
            case 'force-decoration':
                $output->setDecorated(true);
                $output->setFormatter(new OutputFormatter(true));
                $this->info('OK');
                $this->error('BAD');
                break;
            default:
                throw new RuntimeException('unknown arm');
        }

        return self::SUCCESS;
    }

    private function console(OutputInterface $output): ConsoleOutputInterface
    {
        if (! $output instanceof ConsoleOutputInterface) {
            throw new RuntimeException('this arm needs a console output, got '.$output::class);
        }

        return $output;
    }
}

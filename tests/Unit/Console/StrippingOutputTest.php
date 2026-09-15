<?php

namespace Tests\Unit\Console;

use App\Bridge\Console\StrippingOutput;
use App\Bridge\Console\StrippingSectionOutput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Question\Question;

class StrippingOutputTest extends TestCase
{
    public function test_a_multibyte_character_split_across_two_writes_reaches_the_stream_whole(): void
    {
        $stream = fopen('php://memory', 'w+');
        $output = StrippingOutput::wrap(new StreamOutput($stream));

        $output->write("caf\xC3");
        $output->write("\xA9 \xE2\x80");
        $output->write("\x94 end", true);

        rewind($stream);
        $this->assertSame("caf\u{00E9} \u{2014} end\n", stream_get_contents($stream));
    }

    public function test_an_answer_the_question_helper_records_into_a_section_reaches_the_stream_stripped(): void
    {
        $stream = fopen('php://memory', 'w+');
        $sections = [];
        $section = new StrippingSectionOutput($stream, $sections, OutputInterface::VERBOSITY_NORMAL, new OutputFormatter);

        $answer = fopen('php://memory', 'w+');
        fwrite($answer, "ok\e[8mhidden\u{202E}x\n");
        rewind($answer);
        $input = new ArrayInput([]);
        $input->setStream($answer);
        $input->setInteractive(true);

        (new QuestionHelper)->ask($input, $section, new Question('Name? '));
        $section->setMaxHeight(5);

        rewind($stream);
        $bytes = (string) stream_get_contents($stream);
        $this->assertStringContainsString('ok[8mhiddenx', $bytes);
        $this->assertStringNotContainsString("\e[8m", $bytes);
        $this->assertStringNotContainsString("\u{202E}", $bytes);
    }
}

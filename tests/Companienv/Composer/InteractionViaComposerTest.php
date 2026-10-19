<?php

declare(strict_types=1);

namespace Companienv\Composer;

use Companienv\IO\UnansweredQuestionException;
use Composer\IO\ConsoleIO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

final class InteractionViaComposerTest extends TestCase
{
    /**
     * @dataProvider interactionDataProvider
     *
     * @param callable(InteractionViaComposer): (bool|string|null) $act
     * @param bool|string|array<string, string>|null $expectedResult
     */
    public function testInteraction(
        bool $interactive,
        string $answers,
        callable $act,
        $expectedResult,
        string $expectedOutput
    ): void {
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, $answers);
        rewind($inputStream);
        $input = new ArrayInput([]);
        $input->setInteractive($interactive);
        $input->setStream($inputStream);
        $output = new StreamOutput(fopen('php://memory', 'r+'), OutputInterface::VERBOSITY_NORMAL, false);

        $io = new ConsoleIO($input, $output, new HelperSet([new QuestionHelper()]));

        try {
            $result = $act(new InteractionViaComposer($io));
        } catch (RuntimeException $exception) {
            $result = [get_class($exception) => $exception->getMessage()];
        }

        rewind($output->getStream());
        $this->assertSame(
            ['result' => $expectedResult, 'output' => $expectedOutput],
            ['result' => $result, 'output' => stream_get_contents($output->getStream())]
        );
    }

    /**
     * @return array<string, array{
     *     0: bool,
     *     1: string,
     *     2: callable(InteractionViaComposer): (bool|string|null),
     *     3: bool|string|array<string, string>|null,
     *     4: string
     * }>
     */
    public static function interactionDataProvider(): array
    {
        $confirm = static function (InteractionViaComposer $interaction) {
            return $interaction->askConfirmation("Let's fix this? (y) ");
        };
        $ask = static function (InteractionViaComposer $interaction) {
            return $interaction->ask('MY_VARIABLE ? ', 'default-value');
        };
        $askWithoutDefault = static function (InteractionViaComposer $interaction) {
            return $interaction->ask('<comment>MY_VARIABLE</comment> ? ');
        };
        $askWithEmptyDefault = static function (InteractionViaComposer $interaction) {
            return $interaction->ask('MY_VARIABLE ? ', '');
        };

        return [
            'interactive confirmation accepted' => [true, "y\n", $confirm, true, "Let's fix this? (y) "],
            'interactive confirmation declined' => [true, "n\n", $confirm, false, "Let's fix this? (y) "],
            'non-interactive confirmation' => [
                false,
                '',
                $confirm,
                true,
                "Automatically confirmed in non-interactive mode\n",
            ],
            'interactive answer' => [true, "my-value\n", $ask, 'my-value', 'MY_VARIABLE ? '],
            'interactive empty answer' => [true, "\n", $ask, 'default-value', 'MY_VARIABLE ? '],
            'interactive empty answer to an empty default' => [true, "\n", $askWithEmptyDefault, '', 'MY_VARIABLE ? '],
            'interactive empty answer, then an answer, without a default' => [
                true,
                "\nmy-value\n",
                $askWithoutDefault,
                'my-value',
                'MY_VARIABLE ? MY_VARIABLE ? ',
            ],
            'non-interactive answer' => [
                false,
                '',
                $ask,
                'default-value',
                "Automatically returned \"default-value\" in non-interactive mode\n",
            ],
            'non-interactive answer without a default' => [
                false,
                '',
                $askWithoutDefault,
                [
                    UnansweredQuestionException::class => 'Cannot answer "MY_VARIABLE ?" in non-interactive mode: '
                        . 'the question has no default.',
                ],
                '',
            ],
            'non-interactive empty default' => [
                false,
                '',
                $askWithEmptyDefault,
                '',
                "Automatically returned \"\" in non-interactive mode\n",
            ],
            'one line written' => [
                false,
                '',
                static function (InteractionViaComposer $interaction) {
                    return $interaction->writeln('<info>A message</info>');
                },
                null,
                "A message\n",
            ],
            'several lines written' => [
                false,
                '',
                static function (InteractionViaComposer $interaction) {
                    return $interaction->writeln(['', 'A message']);
                },
                null,
                "\nA message\n",
            ],
        ];
    }
}

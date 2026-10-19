<?php

declare(strict_types=1);

namespace Companienv\IO;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

final class InputOutputInteractionTest extends TestCase
{
    /**
     * @dataProvider interactionDataProvider
     *
     * @param callable(InputOutputInteraction): (bool|string|null) $act
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

        try {
            $result = $act(new InputOutputInteraction($input, $output));
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
     *     2: callable(InputOutputInteraction): (bool|string|null),
     *     3: bool|string|array<string, string>|null,
     *     4: string
     * }>
     */
    public static function interactionDataProvider(): array
    {
        $confirm = static function (InputOutputInteraction $interaction) {
            return $interaction->askConfirmation("Let's fix this? (y) ");
        };
        $ask = static function (InputOutputInteraction $interaction) {
            return $interaction->ask('MY_VARIABLE ? ', 'default-value');
        };
        $askFalsy = static function (InputOutputInteraction $interaction) {
            return $interaction->ask('COUNT ? ', '0');
        };
        $askWithoutDefault = static function (InputOutputInteraction $interaction) {
            return $interaction->ask('<comment>MY_VARIABLE</comment> ? ');
        };
        $askWithEmptyDefault = static function (InputOutputInteraction $interaction) {
            return $interaction->ask('MY_VARIABLE ? ', '');
        };

        return [
            'non-interactive answer' => [false, '', $ask, 'default-value', ''],
            'non-interactive falsy answer' => [false, '', $askFalsy, '0', ''],
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
            'non-interactive empty default' => [false, '', $askWithEmptyDefault, '', ''],
            'interactive answer' => [true, "my-value\n", $ask, 'my-value', 'MY_VARIABLE ? '],
            'interactive empty answer' => [true, "\n", $ask, 'default-value', 'MY_VARIABLE ? '],
            'interactive empty answer to an empty default' => [true, "\n", $askWithEmptyDefault, '', 'MY_VARIABLE ? '],
            'interactive answer after an empty one without a default' => [
                true,
                "\nmy-value\n",
                $askWithoutDefault,
                'my-value',
                'MY_VARIABLE ? MY_VARIABLE ? ',
            ],
            'non-interactive confirmation' => [false, '', $confirm, true, ''],
            'interactive confirmation with y' => [true, "y\n", $confirm, true, "Let's fix this? (y) "],
            'interactive confirmation with YES' => [true, "YES\n", $confirm, true, "Let's fix this? (y) "],
            'interactive confirmation with an empty answer' => [true, "\n", $confirm, true, "Let's fix this? (y) "],
            'interactive confirmation with n' => [true, "n\n", $confirm, false, "Let's fix this? (y) "],
            'one line written' => [
                false,
                '',
                static function (InputOutputInteraction $interaction) {
                    return $interaction->writeln('<info>A message</info>');
                },
                null,
                "A message\n",
            ],
            'several lines written' => [
                false,
                '',
                static function (InputOutputInteraction $interaction) {
                    return $interaction->writeln(['', 'A message']);
                },
                null,
                "\nA message\n",
            ],
        ];
    }
}

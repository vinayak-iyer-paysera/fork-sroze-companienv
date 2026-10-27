<?php

declare(strict_types=1);

namespace Companienv\IO;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InMemoryInteractionTest extends TestCase
{
    /**
     * @dataProvider answersDataProvider
     *
     * @param array<string, string|list<string>> $answers
     * @param list<string|array<string, string>> $expectedResults
     */
    public function testAsk(array $answers, int $questions, array $expectedResults, ?string $default = null): void
    {
        $interaction = new InMemoryInteraction($answers);

        $results = [];
        for ($i = 0; $i < $questions; $i++) {
            try {
                $results[] = $interaction->ask('<comment>Question</comment>? ', $default);
            } catch (RuntimeException $exception) {
                $results[] = [get_class($exception) => $exception->getMessage()];
            }
        }

        $this->assertSame($expectedResults, $results);
    }

    /**
     * @return array<string, array{
     *     0: array<string, string|list<string>>,
     *     1: int,
     *     2: list<string|array<string, string>>,
     *     3?: string
     * }>
     */
    public static function answersDataProvider(): array
    {
        $noAnswer = [
            UnansweredQuestionException::class => 'Cannot answer "Question?" in non-interactive mode: '
                . 'the question has no default.',
        ];

        return [
            'one answer, asked twice' => [['Question?' => 'yes'], 2, ['yes', 'yes']],
            'a list of answers, served in order' => [['Question?' => ['first', 'second']], 2, ['first', 'second']],
            'a list of answers that runs out' => [['Question?' => ['first']], 2, ['first', $noAnswer]],
            'no answer' => [[], 1, [$noAnswer]],
            'no answer, the default' => [[], 1, ['default'], 'default'],
            'an answer over the default' => [['Question?' => 'yes'], 1, ['yes'], 'default'],
        ];
    }
}

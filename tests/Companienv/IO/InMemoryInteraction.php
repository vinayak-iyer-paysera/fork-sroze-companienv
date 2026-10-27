<?php

namespace Companienv\IO;

use RuntimeException;

class InMemoryInteraction implements Interaction
{
    /**
     * A question-answer mapping. A list of answers is served in order, one each time the question is asked.
     *
     * @var array
     */
    private $answers;

    private $interactive;

    private $buffer = '';

    /**
     * Without interaction, a question with no answer left gets its default, as in the real interactions; with
     * interaction, it fails, so that a test notices a question it did not expect.
     */
    public function __construct(array $answers = [], bool $interactive = true)
    {
        $this->answers = $answers;
        $this->interactive = $interactive;
    }

    public function askConfirmation(string $question): bool
    {
        return (bool) $this->ask($question);
    }

    public function ask(string $question, ?string $default = null): string
    {
        $normalizedKey = trim(strip_tags($question));
        $this->buffer .= trim(strip_tags($question))."\n";

        if (is_array($this->answers[$normalizedKey] ?? null)) {
            $answer = array_shift($this->answers[$normalizedKey]);
        } else {
            $answer = $this->answers[$normalizedKey] ?? null;
        }

        if (null !== $answer) {
            return $answer;
        }

        if ($this->interactive) {
            throw new RuntimeException(sprintf(
                'No answer for question "%s"',
                $normalizedKey
            ));
        }

        if (null !== $default) {
            return $default;
        }

        throw new UnansweredQuestionException($question);
    }

    public function writeln($messageOrMessages)
    {
        if (!is_array($messageOrMessages)) {
            $messageOrMessages = [$messageOrMessages];
        }

        $this->buffer .= implode("\n", $messageOrMessages)."\n";
    }

    public function getBuffer(): string
    {
        return $this->buffer;
    }
}

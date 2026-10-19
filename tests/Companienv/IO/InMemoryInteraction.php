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

    private $buffer = '';

    public function __construct(array $answers = [])
    {
        $this->answers = $answers;
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

        throw new RuntimeException(sprintf(
            'No answer for question "%s"',
            $normalizedKey
        ));
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

<?php

namespace Companienv\IO;

interface Interaction
{
    public function askConfirmation(string $question) : bool;

    /**
     * The answer, or the default when the answer is empty. A question with no default is asked again until answered.
     *
     * @throws UnansweredQuestionException When the question has no default and cannot be asked, without interaction
     */
    public function ask(string $question, ?string $default = null) : string;

    public function writeln($messageOrMessages);
}

<?php

namespace Companienv\DotEnv;

use LogicException;
use Symfony\Component\Dotenv\Dotenv;

class Variable
{
    private $name;
    private $value;

    public function __construct(string $name, ?string $value = null)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function hasValue(): bool
    {
        return !empty($this->value);
    }

    public function getValue()
    {
        return $this->value;
    }

    public function getDotenvValue(string $precedingLines = ''): ?string
    {
        try {
            // Dotenv 4.4.0 and 5.0.0 raise a notice for an undefined ${VAR}, which Composer turns into an exception
            return @(new Dotenv())->parse($precedingLines . 'VALUE=' . $this->value . "\n")['VALUE'];
        } catch (LogicException $exception) {
            // A FormatException, or a $(…) that Dotenv cannot run, as on Windows
            return null;
        }
    }
}

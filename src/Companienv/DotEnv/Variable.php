<?php

namespace Companienv\DotEnv;

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Dotenv\Exception\FormatException;

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
            return @(new Dotenv())->parse($precedingLines . 'VALUE=' . $this->value . "\n")['VALUE'];
        } catch (FormatException $exception) {
            return null;
        }
    }
}

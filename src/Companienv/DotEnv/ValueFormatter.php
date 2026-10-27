<?php
namespace Companienv\DotEnv;

use Jackiedo\DotenvEditor\DotenvFormatter;

class ValueFormatter extends DotenvFormatter
{
    private $referenceValue;

    public function __construct(?string $referenceValue = null)
    {
        $this->referenceValue = $referenceValue;
    }

    public function formatValue($value, $forceQuotes = false)
    {
        if (!$forceQuotes && ($this->isReadableReferenceValue($value) || !$this->requiresQuotes($value))) {
            return $value;
        }

        if (false === strpos($value, "'")) {
            return "'{$value}'";
        }

        if (!preg_match('/[\\\\"$]/', $value)) {
            return "\"{$value}\"";
        }

        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }

    private function isReadableReferenceValue(string $value): bool
    {
        // A default with a $(…) is kept as the file has it, as on 0.0.12, instead of running the command to find out
        return $value === $this->referenceValue
            && (false !== strpos($value, '$(') || null !== (new Variable('VALUE', $value))->getDotenvValue());
    }

    private function requiresQuotes(string $value): bool
    {
        return 1 === preg_match('/[\s#"\'\\\\$]/', $value);
    }
}

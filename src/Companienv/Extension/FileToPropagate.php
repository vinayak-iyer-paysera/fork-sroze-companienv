<?php

namespace Companienv\Extension;

use Companienv\Companion;
use Companienv\DotEnv\Block;
use Companienv\DotEnv\Variable;
use Companienv\Extension;
use Companienv\IO\UnansweredQuestionException;
use InvalidArgumentException;
use RuntimeException;

class FileToPropagate implements Extension
{
    /**
     * {@inheritdoc}
     */
    public function getVariableValue(Companion $companion, Block $block, Variable $variable)
    {
        if (null === ($attribute = $block->getAttribute('file-to-propagate', $variable))) {
            return null;
        }

        $currentValue = $companion->getDefinedVariablesHash()[$variable->getName()] ?? null;
        $fileSystem = $companion->getFileSystem();
        $filename = $this->getFilePath($companion, $variable, $currentValue);
        if ('' === $filename) {
            return null;
        }

        // A path set in .env stays; otherwise .env gets the .env.dist one as written
        $value = $currentValue ?: $variable->getValue();
        if ($fileSystem->exists($filename)) {
            return $value;
        }

        try {
            $downloadedFilePath = $companion->ask(
                '<comment>'.$variable->getName().'</comment>: What is the path of your downloaded file? '
            );
        } catch (UnansweredQuestionException $exception) {
            return $value;
        }

        if (!$fileSystem->exists($downloadedFilePath, false)) {
            throw new InvalidArgumentException(sprintf('The file "%s" does not exist', $downloadedFilePath));
        }

        if (false === $fileSystem->write($filename, $fileSystem->getContents($downloadedFilePath, false))) {
            throw new RuntimeException(sprintf(
                'Unable to write into "%s"',
                $filename
            ));
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function isVariableRequiringValue(
        Companion $companion,
        Block $block,
        Variable $variable,
        ?string $currentValue = null
    ) : int {
        if (null === ($attribute = $block->getAttribute('file-to-propagate', $variable))) {
            return Extension::ABSTAIN;
        }

        $filename = $this->getFilePath($companion, $variable, $currentValue);

        return '' === $filename || $companion->getFileSystem()->exists($filename)
             ? Extension::ABSTAIN
             : Extension::VARIABLE_REQUIRED;
    }

    private function getFilePath(Companion $companion, Variable $variable, ?string $currentValue) : string
    {
        return (string) ($currentValue ?: $companion->resolveDistValue($variable));
    }
}

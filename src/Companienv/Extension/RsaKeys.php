<?php

namespace Companienv\Extension;

use Companienv\Companion;
use Companienv\DotEnv\Attribute;
use Companienv\DotEnv\Block;
use Companienv\DotEnv\Variable;
use Companienv\Extension;
use RuntimeException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

class RsaKeys implements Extension
{
    private const MINIMUM_PASS_PHRASE_LENGTH = 4;
    private const MAXIMUM_PASS_PHRASE_LENGTH = 1023;

    private $populatedVariables = [];

    /**
     * {@inheritdoc}
     */
    public function getVariableValue(Companion $companion, Block $block, Variable $variable)
    {
        if (null === ($attribute = $block->getAttribute('rsa-pair', $variable))) {
            return null;
        }

        if (array_key_exists($variable->getName(), $this->populatedVariables)) {
            return $this->populatedVariables[$variable->getName()];
        }

        // An existing pair is kept, and a declined one not generated, for the whole run: its variables are asked like
        // any other, so that a path typed for one file does not make the pair look missing
        if ($this->filesExist($companion, $block, $attribute) || !$companion->askConfirmation(sprintf(
            'Variables %s represents an RSA public/private key. Do you want to automatically generate them? (y) ',
            implode(' and ', array_map(function ($variable) {
                return '<comment>'.$variable.'</comment>';
            }, $attribute->getVariableNames()))
        ))) {
            foreach ($attribute->getVariableNames() as $variableName) {
                $this->populatedVariables[$variableName] = null;
            }

            return null;
        }

        $fileSystem = $companion->getFileSystem();
        $passPhrase = $this->askPassPhrase($companion);
        list($privateKeyPath, $publicKeyPath) = $this->getPaths($companion, $block, $attribute);

        try {
            (new Process(
                [
                    'openssl', 'genrsa',
                    '-out', $fileSystem->realpath($privateKeyPath),
                    '-aes256',
                    '-passout', 'stdin',
                    '4096',
                ],
                null,
                null,
                $passPhrase
            ))->mustRun();
            (new Process(
                [
                    'openssl', 'rsa', '-pubout',
                    '-in', $fileSystem->realpath($privateKeyPath),
                    '-out', $fileSystem->realpath($publicKeyPath),
                    '-passin', 'stdin',
                ],
                null,
                null,
                $passPhrase
            ))->mustRun();
        } catch (ProcessRuntimeException $exception) {
            throw new RuntimeException(
                'Could not have generated the RSA public/private key: '.$exception->getMessage(),
                $exception->getCode()
            );
        }

        // A path set in .env stays; otherwise .env gets the .env.dist one as written
        $definedVariables = $companion->getDefinedVariablesHash();
        foreach (array_slice($attribute->getVariableNames(), 0, 2) as $pathName) {
            $this->populatedVariables[$pathName] = ($definedVariables[$pathName] ?? '')
                ?: $block->getVariable($pathName)->getValue();
        }
        $this->populatedVariables[$attribute->getVariableNames()[2]] = $passPhrase;

        return $this->populatedVariables[$variable->getName()];
    }

    private function askPassPhrase(Companion $companion): string
    {
        $passPhrase = $companion->ask('Enter pass phrase to protect the keys: ');
        while (
            strlen($passPhrase) < self::MINIMUM_PASS_PHRASE_LENGTH
            || strlen($passPhrase) > self::MAXIMUM_PASS_PHRASE_LENGTH
        ) {
            $passPhrase = $companion->ask(sprintf(
                'The pass phrase must be %d to %d bytes long. Enter pass phrase to protect the keys: ',
                self::MINIMUM_PASS_PHRASE_LENGTH,
                self::MAXIMUM_PASS_PHRASE_LENGTH
            ));
        }

        return $passPhrase;
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
        if (null === ($attribute = $block->getAttribute('rsa-pair', $variable))) {
            return Extension::ABSTAIN;
        }

        return $this->filesExist($companion, $block, $attribute) ? Extension::ABSTAIN : Extension::VARIABLE_REQUIRED;
    }

    private function filesExist(Companion $companion, Block $block, Attribute $attribute) : bool
    {
        list($privateKeyPath, $publicKeyPath) = $this->getPaths($companion, $block, $attribute);

        return $companion->getFileSystem()->exists($privateKeyPath)
            && $companion->getFileSystem()->exists($publicKeyPath);
    }

    /**
     * @return string[]
     */
    private function getPaths(Companion $companion, Block $block, Attribute $attribute) : array
    {
        return array_map(function (string $name) use ($companion, $block) : string {
            return $companion->getVariablePath($block->getVariable($name));
        }, array_slice($attribute->getVariableNames(), 0, 2));
    }
}

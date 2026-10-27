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

class SslCertificate implements Extension
{
    private $populatedVariables = [];

    /**
     * {@inheritdoc}
     */
    public function getVariableValue(Companion $companion, Block $block, Variable $variable)
    {
        if (null === ($attribute = $block->getAttribute('ssl-certificate', $variable))) {
            return null;
        }

        if (array_key_exists($variable->getName(), $this->populatedVariables)) {
            return $this->populatedVariables[$variable->getName()];
        }

        // An existing key and certificate are kept, and declined ones not generated, for the whole run: their
        // variables are asked like any other, so that a path typed for one file does not make them look missing
        if ($this->filesExist($companion, $block, $attribute) || !$companion->askConfirmation(sprintf(
            'Variables %s represents an SSL certificate. Do you want to automatically generate them? (y) ',
            implode(' and ', array_map(function ($variable) {
                return '<comment>'.$variable.'</comment>';
            }, $attribute->getVariableNames()))
        ))) {
            foreach ($attribute->getVariableNames() as $variableName) {
                $this->populatedVariables[$variableName] = null;
            }

            return null;
        }

        $domainName = $companion->ask('Enter the domain name for which to generate the self-signed SSL certificate: ');
        list($privateKeyPath, $certificatePath) = $this->getPaths($companion, $block, $attribute);

        try {
            (new Process([
                'openssl', 'req', '-x509', '-nodes', '-days', '3650', '-newkey', 'rsa:2048',
                '-keyout', $companion->getFileSystem()->realpath($privateKeyPath),
                '-out', $companion->getFileSystem()->realpath($certificatePath),
                '-subj', '/C=SS/ST=SS/L=SelfSignedCity/O=SelfSignedOrg/CN='.addcslashes($domainName, '\\/+'),
            ]))->mustRun();
        } catch (ProcessRuntimeException $exception) {
            throw new RuntimeException(
                'Could not have generated the SSL certificate: '.$exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        }

        // A path set in .env stays; otherwise .env gets the .env.dist one as written
        $definedVariables = $companion->getDefinedVariablesHash();
        foreach (array_slice($attribute->getVariableNames(), 0, 2) as $pathName) {
            $this->populatedVariables[$pathName] = ($definedVariables[$pathName] ?? '')
                ?: $block->getVariable($pathName)->getValue();
        }
        $this->populatedVariables[$attribute->getVariableNames()[2]] = $domainName;

        return $this->populatedVariables[$variable->getName()];
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
        if (null === ($attribute = $block->getAttribute('ssl-certificate', $variable))) {
            return Extension::ABSTAIN;
        }

        return $this->filesExist($companion, $block, $attribute) ? Extension::ABSTAIN : Extension::VARIABLE_REQUIRED;
    }

    private function filesExist(Companion $companion, Block $block, Attribute $attribute) : bool
    {
        list($privateKeyPath, $certificatePath) = $this->getPaths($companion, $block, $attribute);

        return $companion->getFileSystem()->exists($privateKeyPath)
            && $companion->getFileSystem()->exists($certificatePath);
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

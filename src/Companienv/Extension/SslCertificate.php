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

        // Keep an existing key and certificate: their variables are asked like any other
        if ($this->filesExist($companion, $block, $attribute)) {
            return null;
        }

        if (!$companion->askConfirmation(sprintf(
            'Variables %s represents an SSL certificate. Do you want to automatically generate them? (y) ',
            implode(' and ', array_map(function ($variable) {
                return '<comment>'.$variable.'</comment>';
            }, $attribute->getVariableNames()))
        ))) {
            // Ensure we don't ask anymore for this variable pair
            foreach ($attribute->getVariableNames() as $variableName) {
                $this->populatedVariables[$variableName] = null;
            }

            return null;
        }

        $domainName = $companion->ask('Enter the domain name for which to generate the self-signed SSL certificate: ');
        $privateKeyVariable = $block->getVariable($attribute->getVariableNames()[0]);
        $certificateVariable = $block->getVariable($attribute->getVariableNames()[1]);

        try {
            (new Process([
                'openssl', 'req', '-x509', '-nodes', '-days', '3650', '-newkey', 'rsa:2048',
                '-keyout', $companion->getFileSystem()->realpath($companion->resolveDistValue($privateKeyVariable)),
                '-out', $companion->getFileSystem()->realpath($companion->resolveDistValue($certificateVariable)),
                '-subj', '/C=SS/ST=SS/L=SelfSignedCity/O=SelfSignedOrg/CN='.addcslashes($domainName, '\\/+'),
            ]))->mustRun();
        } catch (ProcessRuntimeException $exception) {
            throw new RuntimeException(
                'Could not have generated the SSL certificate: '.$exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        }

        $this->populatedVariables[$privateKeyVariable->getName()] = $privateKeyVariable->getValue();
        $this->populatedVariables[$certificateVariable->getName()] = $certificateVariable->getValue();
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
        $fileSystem = $companion->getFileSystem();
        $variableNames = $attribute->getVariableNames();

        return $fileSystem->exists($companion->resolveDistValue($block->getVariable($variableNames[0])))
            && $fileSystem->exists($companion->resolveDistValue($block->getVariable($variableNames[1])));
    }
}

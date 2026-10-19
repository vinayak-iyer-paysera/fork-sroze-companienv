<?php

namespace Companienv\Extension;

use Companienv\Companion;
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
        $privateKeyPath = $block->getVariable($privateKeyVariableName = $attribute->getVariableNames()[0])->getValue();
        $certificateKeyPath = $block->getVariable($certificateVariableName = $attribute->getVariableNames()[1])->getValue();

        try {
            (new Process([
                'openssl', 'req', '-x509', '-nodes', '-days', '3650', '-newkey', 'rsa:2048',
                '-keyout', $companion->getFileSystem()->realpath($privateKeyPath),
                '-out', $companion->getFileSystem()->realpath($certificateKeyPath),
                '-subj', '/C=SS/ST=SS/L=SelfSignedCity/O=SelfSignedOrg/CN='.addcslashes($domainName, '\\/+'),
            ]))->mustRun();
        } catch (ProcessRuntimeException $exception) {
            throw new RuntimeException(
                'Could not have generated the SSL certificate: '.$exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        }

        $this->populatedVariables[$privateKeyVariableName] = $privateKeyPath;
        $this->populatedVariables[$certificateVariableName] = $certificateKeyPath;
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

        $fileSystem = $companion->getFileSystem();

        return (
            !$fileSystem->exists($block->getVariable($privateKeyVariableName = $attribute->getVariableNames()[0])->getValue())
            || !$fileSystem->exists($block->getVariable($attribute->getVariableNames()[1])->getValue())
        ) ? Extension::VARIABLE_REQUIRED
            : Extension::ABSTAIN;
    }
}

<?php

declare(strict_types=1);

namespace Companienv;

use Companienv\DotEnv\Block;
use Companienv\DotEnv\Variable;
use Companienv\Extension\AbstractExtension;
use Companienv\Interaction\AskVariableValues;
use Companienv\IO\UnansweredQuestionException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class ApplicationTest extends TestCase
{
    use TemporaryDirectory;

    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    /**
     * @dataProvider extensionsDataProvider
     *
     * @param list<Extension>|null $extensions
     * @param list<Extension> $registeredExtensions
     */
    public function testRun(?array $extensions, array $registeredExtensions, string $expectedEnv): void
    {
        file_put_contents($this->temporaryDirectory . '/app.dist', "## Something\nMY_VARIABLE=default-value\n");
        $application = new Application($this->temporaryDirectory, $extensions);
        foreach ($registeredExtensions as $extension) {
            $application->registerExtension($extension);
        }
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(
            new ArrayInput(['--file' => 'app.env', '--dist-file' => 'app.dist', '--no-interaction' => true]),
            $output
        );

        $this->assertSame(
            [
                'exit code' => 0,
                'files' => ['app.dist' => "## Something\nMY_VARIABLE=default-value\n", 'app.env' => $expectedEnv],
                'output' => "It looks like you are missing some configuration (1 variables). "
                    . "I will help you to sort this out.\n"
                    . "\nSomething\n\n",
            ],
            ['exit code' => $exitCode, 'files' => $this->readTemporaryDirectory(), 'output' => $output->fetch()]
        );
    }

    public function testCommands(): void
    {
        $application = new Application($this->temporaryDirectory);

        $this->assertSame(
            ['companion' => true, 'help' => true, 'list' => true],
            [
                'companion' => $application->has('companion'),
                'help' => $application->has('help'),
                'list' => $application->has('list'),
            ]
        );
    }

    public function testVersion(): void
    {
        $application = new Application($this->temporaryDirectory);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(new ArrayInput(['--version' => true]), $output);

        $this->assertSame(
            ['exit code' => 0, 'output' => "Companienv 0.1.x-dev\n"],
            ['exit code' => $exitCode, 'output' => $output->fetch()]
        );
    }

    public function testNamingAnotherCommandIsRefused(): void
    {
        file_put_contents($this->temporaryDirectory . '/.env.dist', "MY_VARIABLE=default-value\n");
        $application = new Application($this->temporaryDirectory);
        $application->setAutoExit(false);

        $exitCode = $application->run(
            new ArrayInput(['command' => 'list', '--no-interaction' => true]),
            new BufferedOutput()
        );

        $this->assertSame(
            ['exit code' => 1, 'files' => ['.env.dist' => "MY_VARIABLE=default-value\n"]],
            ['exit code' => $exitCode, 'files' => $this->readTemporaryDirectory()]
        );
    }

    /**
     * @dataProvider withoutInteractionDataProvider
     *
     * @param array<string, string> $expectedError
     * @param array<string, string> $expectedFiles
     */
    public function testRunWithoutInteraction(string $distFile, array $expectedError, array $expectedFiles): void
    {
        file_put_contents($this->temporaryDirectory . '/.env.dist', $distFile);
        $application = new Application($this->temporaryDirectory);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        $error = [];
        try {
            $application->run(new ArrayInput(['--no-interaction' => true]), new BufferedOutput());
        } catch (RuntimeException $exception) {
            $error = [get_class($exception) => $exception->getMessage()];
        }

        $this->assertSame(
            ['error' => $expectedError, 'files' => $expectedFiles],
            ['error' => $error, 'files' => $this->readTemporaryDirectory()]
        );
    }

    /**
     * @return array<string, array{0: list<Extension>|null, 1: list<Extension>, 2: string}>
     */
    public static function extensionsDataProvider(): array
    {
        $extension = new class() extends AbstractExtension {
            public function getVariableValue(Companion $companion, Block $block, Variable $variable)
            {
                return 'from-extension';
            }
        };

        return [
            'default extensions' => [null, [], "MY_VARIABLE=default-value\n"],
            'registered extension asked first' => [null, [$extension], "MY_VARIABLE=from-extension\n"],
            'extensions given to the constructor' => [
                [$extension, new AskVariableValues()],
                [],
                "MY_VARIABLE=from-extension\n",
            ],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2: array<string, string>}>
     */
    public static function withoutInteractionDataProvider(): array
    {
        $rsaPair = "## Keys\n#+rsa-pair(KEY_PATH PUB_PATH KEY_PASS)\n"
            . "KEY_PATH=private.pem\nPUB_PATH=public.pem\nKEY_PASS=\n";
        $sslCertificate = "## Certificate\n#+ssl-certificate(CERT_KEY_PATH CERT_PATH CERT_DOMAIN)\n"
            . "CERT_KEY_PATH=key.pem\nCERT_PATH=cert.pem\nCERT_DOMAIN=\n";
        $fileToPropagate = "## Keys\n#+file-to-propagate(KEY_PATH)\nKEY_PATH=key.pem\n";

        return [
            'rsa-pair' => [
                $rsaPair,
                [
                    UnansweredQuestionException::class => 'Cannot answer "Enter pass phrase to protect the keys:" '
                        . 'in non-interactive mode: the question has no default.',
                ],
                ['.env.dist' => $rsaPair],
            ],
            'ssl-certificate' => [
                $sslCertificate,
                [
                    UnansweredQuestionException::class => 'Cannot answer "Enter the domain name for which to generate '
                        . 'the self-signed SSL certificate:" in non-interactive mode: the question has no default.',
                ],
                ['.env.dist' => $sslCertificate],
            ],
            'file-to-propagate' => [
                $fileToPropagate,
                [],
                ['.env' => "KEY_PATH=key.pem\n", '.env.dist' => $fileToPropagate],
            ],
        ];
    }
}

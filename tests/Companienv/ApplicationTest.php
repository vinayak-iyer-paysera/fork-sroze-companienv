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
                'output' => 'It looks like you are missing some configuration (1 variables). '
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
     * @param array<string, string> $files
     */
    public function testRunWithoutInteraction(
        string $distFile,
        array $expectedError,
        array $expectedFiles,
        array $files = []
    ): void {
        file_put_contents($this->temporaryDirectory . '/.env.dist', $distFile);
        foreach ($files as $name => $contents) {
            file_put_contents($this->temporaryDirectory . '/' . $name, $contents);
        }
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
     * @return array<string, list<string|array<string, string>>>
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
            'rsa-pair whose files exist' => [
                $rsaPair,
                [],
                [
                    '.env' => "KEY_PATH=private.pem\nPUB_PATH=public.pem\nKEY_PASS=\n",
                    '.env.dist' => $rsaPair,
                    'private.pem' => 'KEY',
                    'public.pem' => 'KEY',
                ],
                ['private.pem' => 'KEY', 'public.pem' => 'KEY'],
            ],
            'rsa-pair whose files exist, with a .env.dist pass phrase' => [
                $rsaPairWithPassPhrase = str_replace('KEY_PASS=', 'KEY_PASS=dev-phrase', $rsaPair),
                [],
                [
                    '.env' => "KEY_PATH=private.pem\nPUB_PATH=public.pem\nKEY_PASS=dev-phrase\n",
                    '.env.dist' => $rsaPairWithPassPhrase,
                    'private.pem' => 'KEY',
                    'public.pem' => 'KEY',
                ],
                ['private.pem' => 'KEY', 'public.pem' => 'KEY'],
            ],
            'ssl-certificate whose files exist' => [
                $sslCertificate,
                [],
                [
                    '.env' => "CERT_KEY_PATH=key.pem\nCERT_PATH=cert.pem\nCERT_DOMAIN=\n",
                    '.env.dist' => $sslCertificate,
                    'cert.pem' => 'CERTIFICATE',
                    'key.pem' => 'KEY',
                ],
                ['cert.pem' => 'CERTIFICATE', 'key.pem' => 'KEY'],
            ],
            'file-to-propagate with a variable in its .env.dist path' => [
                "## Keys\n#+file-to-propagate(KEY_PATH)\nKEY_PATH=\${KEY_DIR}/key.pem\n",
                [],
                [
                    '.env' => "KEY_DIR=keys\nKEY_PATH=\${KEY_DIR}/key.pem\n",
                    '.env.dist' => "## Keys\n#+file-to-propagate(KEY_PATH)\nKEY_PATH=\${KEY_DIR}/key.pem\n",
                ],
                ['.env' => "KEY_DIR=keys\n"],
            ],
            'rsa-pair at the paths set in .env' => [
                $rsaPair,
                [],
                [
                    '.env' => "KEY_PATH=my-private.pem\nPUB_PATH=my-public.pem\nKEY_PASS=stored-phrase\n",
                    '.env.dist' => $rsaPair,
                    'my-private.pem' => 'KEY',
                    'my-public.pem' => 'KEY',
                ],
                [
                    '.env' => "KEY_PATH=my-private.pem\nPUB_PATH=my-public.pem\nKEY_PASS=stored-phrase\n",
                    'my-private.pem' => 'KEY',
                    'my-public.pem' => 'KEY',
                ],
            ],
            'ssl-certificate at the paths set in .env' => [
                $sslCertificate,
                [],
                [
                    '.env' => "CERT_KEY_PATH=my-key.pem\nCERT_PATH=my-cert.pem\nCERT_DOMAIN=example.com\n",
                    '.env.dist' => $sslCertificate,
                    'my-cert.pem' => 'CERTIFICATE',
                    'my-key.pem' => 'KEY',
                ],
                [
                    '.env' => "CERT_KEY_PATH=my-key.pem\nCERT_PATH=my-cert.pem\nCERT_DOMAIN=example.com\n",
                    'my-cert.pem' => 'CERTIFICATE',
                    'my-key.pem' => 'KEY',
                ],
            ],
            'file-to-propagate' => [
                $fileToPropagate,
                [],
                ['.env' => "KEY_PATH=key.pem\n", '.env.dist' => $fileToPropagate],
            ],
        ];
    }
}

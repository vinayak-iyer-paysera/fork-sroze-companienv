<?php

declare(strict_types=1);

namespace Companienv\Composer;

use Companienv\TemporaryDirectory;
use Composer\Composer;
use Composer\IO\BufferIO;
use Composer\Package\RootPackage;
use Composer\Script\Event;
use PHPUnit\Framework\TestCase;

final class ScriptHandlerTest extends TestCase
{
    use TemporaryDirectory;

    private $workingDirectory;

    protected function setUp(): void
    {
        $this->workingDirectory = getcwd();
        chdir($this->createTemporaryDirectory());
    }

    protected function tearDown(): void
    {
        chdir($this->workingDirectory);
        $this->removeTemporaryDirectory();
    }

    /**
     * @dataProvider extraDataProvider
     *
     * @param array<string, mixed> $extra
     * @param array<string, string> $files
     * @param array<string, string> $expectedFiles
     */
    public function testRun(array $extra, array $files, array $expectedFiles, string $expectedOutput): void
    {
        foreach ($files as $name => $contents) {
            file_put_contents($name, $contents);
        }
        $package = new RootPackage('acme/application', '1.0.0.0', '1.0.0');
        $package->setExtra($extra);
        $composer = new Composer();
        $composer->setPackage($package);
        $io = new BufferIO();

        ScriptHandler::run(new Event('post-install-cmd', $composer, $io));

        $this->assertSame(
            ['files' => $expectedFiles, 'output' => $expectedOutput],
            ['files' => $this->readTemporaryDirectory(), 'output' => $io->getOutput()]
        );
    }

    /**
     * @return array<string, array{
     *     0: array<string, mixed>,
     *     1: array<string, string>,
     *     2: array<string, string>,
     *     3: string
     * }>
     */
    public static function extraDataProvider(): array
    {
        $distFile = "## Database\nDATABASE_HOST=localhost\nDATABASE_PORT=3306\n";
        $output = "It looks like you are missing some configuration (2 variables). I will help you to sort this out.\n"
            . "Automatically confirmed in non-interactive mode\n\nDatabase\n\n"
            . "Automatically returned \"localhost\" in non-interactive mode\n"
            . "Automatically returned \"3306\" in non-interactive mode\n";
        $propagatedDistFile = "## Keys\n#+file-to-propagate(KEY_PATH)\nKEY_PATH=key.pem\n";
        $propagatedOutput = "It looks like you are missing some configuration (1 variables). "
            . "I will help you to sort this out.\nAutomatically confirmed in non-interactive mode\n\nKeys\n\n"
            . "Automatically returned \"\" in non-interactive mode\n";

        return [
            'one pair of files' => [
                ['companienv-parameters' => [['file' => '.env', 'dist-file' => '.dist.env']]],
                ['.dist.env' => $distFile],
                ['.dist.env' => $distFile, '.env' => "DATABASE_HOST=localhost\nDATABASE_PORT=3306\n"],
                $output,
            ],
            'two pairs of files' => [
                ['companienv-parameters' => [
                    ['file' => '.env', 'dist-file' => '.dist.env'],
                    ['file' => '.env.test', 'dist-file' => '.dist.env.test'],
                ]],
                ['.dist.env' => $distFile, '.dist.env.test' => "APP_ENV=test\n"],
                [
                    '.dist.env' => $distFile,
                    '.dist.env.test' => "APP_ENV=test\n",
                    '.env' => "DATABASE_HOST=localhost\nDATABASE_PORT=3306\n",
                    '.env.test' => "APP_ENV=test\n",
                ],
                $output
                . "It looks like you are missing some configuration (1 variables). I will help you to sort this out.\n"
                . "Automatically confirmed in non-interactive mode\n\n"
                . "Automatically returned \"test\" in non-interactive mode\n",
            ],
            'no parameters: .env from .env.dist' => [
                [],
                ['.env.dist' => $distFile],
                ['.env' => "DATABASE_HOST=localhost\nDATABASE_PORT=3306\n", '.env.dist' => $distFile],
                $output,
            ],
            'file to propagate missing, its variable not set: the reference path is written' => [
                [],
                ['.env.dist' => $propagatedDistFile],
                ['.env' => "KEY_PATH=key.pem\n", '.env.dist' => $propagatedDistFile],
                $propagatedOutput,
            ],
            'file to propagate missing at the path set in .env: .env is left as it is' => [
                [],
                ['.env.dist' => $propagatedDistFile, '.env' => "KEY_PATH=custom.pem # from the vault\n"],
                ['.env' => "KEY_PATH=custom.pem # from the vault\n", '.env.dist' => $propagatedDistFile],
                $propagatedOutput,
            ],
        ];
    }
}

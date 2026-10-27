<?php

declare(strict_types=1);

namespace Companienv\Extension;

use Companienv\Companion;
use Companienv\DotEnv\Attribute;
use Companienv\DotEnv\Block;
use Companienv\DotEnv\Variable;
use Companienv\Extension;
use Companienv\IO\FileSystem\NativePhpFileSystem;
use Companienv\IO\InMemoryFileSystem;
use Companienv\IO\InMemoryInteraction;
use Companienv\TemporaryDirectory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FileToPropagateTest extends TestCase
{
    use TemporaryDirectory;

    private const QUESTION = 'KEY_PATH: What is the path of your downloaded file? (leave empty to skip)';

    protected function tearDown(): void
    {
        if (null !== $this->temporaryDirectory) {
            $this->removeTemporaryDirectory();
        }
    }

    /**
     * @dataProvider propagationDataProvider
     *
     * @param array<string, string> $files
     * @param array<string, string> $answers
     * @param array<string, string|null> $expectedFiles
     */
    public function testGetVariableValue(
        Block $block,
        array $files,
        array $answers,
        ?string $expectedValue,
        string $expectedQuestions,
        array $expectedFiles,
        bool $interactive = true
    ): void {
        $fileSystem = $this->fileSystem($files);
        $interaction = new InMemoryInteraction($answers, $interactive);

        $value = (new FileToPropagate())->getVariableValue(
            new Companion($fileSystem, $interaction, new Chained()),
            $block,
            new Variable('KEY_PATH', 'target.pem')
        );

        $this->assertSame(
            ['value' => $expectedValue, 'questions' => $expectedQuestions, 'files' => $expectedFiles],
            [
                'value' => $value,
                'questions' => $interaction->getBuffer(),
                'files' => [
                    'target.pem' => $fileSystem->exists('target.pem') ? $fileSystem->getContents('target.pem') : null,
                    'custom.pem' => $fileSystem->exists('custom.pem') ? $fileSystem->getContents('custom.pem') : null,
                ],
            ]
        );
    }

    /**
     * @return array<string, array{
     *     0: Block,
     *     1: array<string, string>,
     *     2: array<string, string>,
     *     3: string|null,
     *     4: string,
     *     5: array<string, string|null>,
     *     6?: bool,
     * }>
     */
    public static function propagationDataProvider(): array
    {
        return [
            'no file-to-propagate attribute' => [
                new Block('Keys'),
                [],
                [],
                null,
                '',
                ['target.pem' => null, 'custom.pem' => null],
            ],
            'file present at the path set in .env: kept' => [
                self::block(),
                ['custom.pem' => 'CURRENT', '.env' => "KEY_PATH=custom.pem\n"],
                [],
                'custom.pem',
                '',
                ['target.pem' => null, 'custom.pem' => 'CURRENT'],
            ],
            'file present at the reference path, nothing set in .env: kept' => [
                self::block(),
                ['target.pem' => 'CURRENT'],
                [],
                'target.pem',
                '',
                ['target.pem' => 'CURRENT', 'custom.pem' => null],
            ],
            'copied from the downloaded file' => [
                self::block(),
                ['/downloads/key.pem' => 'DOWNLOADED'],
                [self::QUESTION => '/downloads/key.pem'],
                'target.pem',
                self::QUESTION . "\n",
                ['target.pem' => 'DOWNLOADED', 'custom.pem' => null],
            ],
            'copied to the path set in .env' => [
                self::block(),
                ['/downloads/key.pem' => 'DOWNLOADED', 'target.pem' => 'CURRENT', '.env' => "KEY_PATH=custom.pem\n"],
                [self::QUESTION => '/downloads/key.pem'],
                'custom.pem',
                self::QUESTION . "\n",
                ['target.pem' => 'CURRENT', 'custom.pem' => 'DOWNLOADED'],
            ],
            'empty answer: the file stays missing' => [
                self::block(),
                ['.env' => "KEY_PATH=custom.pem\n"],
                [self::QUESTION => ''],
                'custom.pem',
                self::QUESTION . "\n",
                ['target.pem' => null, 'custom.pem' => null],
            ],
            'without interaction: the file stays missing' => [
                self::block(),
                [],
                [],
                'target.pem',
                self::QUESTION . "\n",
                ['target.pem' => null, 'custom.pem' => null],
                false,
            ],
            'empty in .env, file present at the reference path: the reference path is written' => [
                self::block(),
                ['target.pem' => 'CURRENT', '.env' => "KEY_PATH=\n"],
                [],
                'target.pem',
                '',
                ['target.pem' => 'CURRENT', 'custom.pem' => null],
            ],
            'empty in .env, file missing, empty answer: the reference path is written' => [
                self::block(),
                ['.env' => "KEY_PATH=\n"],
                [self::QUESTION => ''],
                'target.pem',
                self::QUESTION . "\n",
                ['target.pem' => null, 'custom.pem' => null],
            ],
        ];
    }

    public function testGetVariableValueRejectsAMissingDownloadedFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The file "/downloads/missing.pem" does not exist');

        (new FileToPropagate())->getVariableValue(
            new Companion(
                $this->fileSystem([]),
                new InMemoryInteraction([self::QUESTION => '/downloads/missing.pem']),
                new Chained()
            ),
            self::block(),
            new Variable('KEY_PATH', 'target.pem')
        );
    }

    /**
     * @dataProvider requirementDataProvider
     *
     * @param array<string, string> $files
     */
    public function testIsVariableRequiringValue(Block $block, array $files, ?string $currentValue, int $expected): void
    {
        if (null !== $currentValue) {
            $files['.env'] = "KEY_PATH={$currentValue}\n";
        }

        $this->assertSame(
            $expected,
            (new FileToPropagate())->isVariableRequiringValue(
                new Companion($this->fileSystem($files), new InMemoryInteraction(), new Chained()),
                $block,
                new Variable('KEY_PATH', 'target.pem'),
                $currentValue
            )
        );
    }

    public function testIsVariableRequiringValueReadsAVariableSetInTheEnvFile(): void
    {
        $fileSystem = $this->fileSystem(['.env' => "KEY_DIR=keys\n", 'keys/target.pem' => 'CURRENT']);

        $this->assertSame(
            Extension::ABSTAIN,
            (new FileToPropagate())->isVariableRequiringValue(
                new Companion($fileSystem, new InMemoryInteraction(), new Chained()),
                self::block(),
                new Variable('KEY_PATH', '${KEY_DIR}/target.pem')
            )
        );
    }

    public function testGetVariableValueKeepsTheDistPathAsWritten(): void
    {
        $fileSystem = $this->fileSystem(['.env' => "KEY_DIR=keys\n", 'keys/target.pem' => 'CURRENT']);

        $this->assertSame(
            '${KEY_DIR}/target.pem',
            (new FileToPropagate())->getVariableValue(
                new Companion($fileSystem, new InMemoryInteraction(), new Chained()),
                self::block(),
                new Variable('KEY_PATH', '${KEY_DIR}/target.pem')
            )
        );
    }

    /**
     * @return array<string, array{0: Block, 1: array<string, string>, 2: string|null, 3: int}>
     */
    public static function requirementDataProvider(): array
    {
        return [
            'no file-to-propagate attribute' => [new Block('Keys'), [], null, Extension::ABSTAIN],
            'target file absent' => [self::block(), [], null, Extension::VARIABLE_REQUIRED],
            'target file present' => [self::block(), ['target.pem' => 'CURRENT'], null, Extension::ABSTAIN],
            'file present at the path set in .env' => [
                self::block(),
                ['custom.pem' => 'CURRENT'],
                'custom.pem',
                Extension::ABSTAIN,
            ],
            'file absent at the path set in .env' => [
                self::block(),
                ['target.pem' => 'CURRENT'],
                'custom.pem',
                Extension::VARIABLE_REQUIRED,
            ],
            'empty value in .env: the reference path' => [
                self::block(),
                ['target.pem' => 'CURRENT'],
                '',
                Extension::ABSTAIN,
            ],
            'empty value in .env, file absent at the reference path' => [
                self::block(),
                [],
                '',
                Extension::VARIABLE_REQUIRED,
            ],
        ];
    }

    /**
     * @dataProvider emptyPathDataProvider
     *
     * @param array<string, string> $files
     */
    public function testEmptyPathLeavesTheVariableToTheOtherExtensions(array $files, ?string $currentValue): void
    {
        $directory = $this->createTemporaryDirectory();
        foreach (['.env.dist' => ''] + $files as $name => $contents) {
            file_put_contents($directory . '/' . $name, $contents);
        }
        $interaction = new InMemoryInteraction();
        $companion = new Companion(new NativePhpFileSystem($directory), $interaction, new Chained());
        $variable = new Variable('KEY_PATH', '');
        $extension = new FileToPropagate();

        $this->assertSame(
            ['vote' => Extension::ABSTAIN, 'value' => null, 'questions' => ''],
            [
                'vote' => $extension->isVariableRequiringValue($companion, self::block(), $variable, $currentValue),
                'value' => $extension->getVariableValue($companion, self::block(), $variable),
                'questions' => $interaction->getBuffer(),
            ]
        );
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string|null}>
     */
    public static function emptyPathDataProvider(): array
    {
        return [
            'nothing set in .env' => [[], null],
            'empty value in .env' => [['.env' => "KEY_PATH=\n"], ''],
        ];
    }

    private static function block(): Block
    {
        return new Block('Keys', '', [], [new Attribute('file-to-propagate', ['KEY_PATH'], [])]);
    }

    /**
     * @param array<string, string> $files
     */
    private function fileSystem(array $files): InMemoryFileSystem
    {
        $fileSystem = new InMemoryFileSystem();
        foreach (['.env.dist' => ''] + $files as $path => $contents) {
            $fileSystem->write($path, $contents);
        }

        return $fileSystem;
    }
}

<?php

declare(strict_types=1);

namespace Companienv\Extension;

use Companienv\Companion;
use Companienv\DotEnv\Attribute;
use Companienv\DotEnv\Block;
use Companienv\DotEnv\Variable;
use Companienv\Extension;
use Companienv\IO\FileSystem\NativePhpFileSystem;
use Companienv\IO\InMemoryInteraction;
use Companienv\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RsaKeysTest extends TestCase
{
    use TemporaryDirectory;

    private const CONFIRMATION = 'Variables KEY_PATH and PUB_PATH and KEY_PASS represents an RSA public/private key. '
        . 'Do you want to automatically generate them? (y)';
    private const PASS_PHRASE_QUESTION = 'Enter pass phrase to protect the keys:';
    private const REPEATED_PASS_PHRASE_QUESTION = 'The pass phrase must be 4 to 1023 bytes long. '
        . self::PASS_PHRASE_QUESTION;
    private const EXISTING_PASS_PHRASE_QUESTION = 'KEY_PASS: What is the pass phrase of the existing keys?';

    protected function setUp(): void
    {
        file_put_contents($this->createTemporaryDirectory() . '/.env.dist', '');
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    /**
     * @dataProvider nothingGeneratedDataProvider
     *
     * @param array<string, string> $answers
     * @param list<string> $variableNames
     */
    public function testNothingGenerated(
        Block $block,
        array $answers,
        array $variableNames,
        string $expectedQuestions
    ): void {
        $interaction = new InMemoryInteraction($answers);
        $companion = $this->companion($interaction);
        $extension = new RsaKeys();

        $values = [];
        foreach ($variableNames as $name) {
            $values[$name] = $extension->getVariableValue($companion, $block, $block->getVariable($name));
        }

        $this->assertSame(
            [
                'values' => array_fill_keys($variableNames, null),
                'questions' => $expectedQuestions,
                'files' => ['.env.dist'],
            ],
            [
                'values' => $values,
                'questions' => $interaction->getBuffer(),
                'files' => array_keys($this->readTemporaryDirectory()),
            ]
        );
    }

    /**
     * @return array<string, array{0: Block, 1: array<string, string>, 2: list<string>, 3: string}>
     */
    public static function nothingGeneratedDataProvider(): array
    {
        return [
            'no rsa-pair attribute' => [new Block('Keys', '', self::variables()), [], ['KEY_PATH'], ''],
            'generation declined' => [
                self::block(),
                [self::CONFIRMATION => ''],
                ['KEY_PATH'],
                self::CONFIRMATION . "\n",
            ],
            'generation declined, then the rest of the pair' => [
                self::block(),
                [self::CONFIRMATION => ''],
                ['KEY_PATH', 'PUB_PATH', 'KEY_PASS'],
                self::CONFIRMATION . "\n",
            ],
        ];
    }

    /**
     * @dataProvider passPhraseDataProvider
     *
     * @param array<string, string|list<string>> $passPhraseAnswers
     */
    public function testGeneratedKeyPair(array $passPhraseAnswers, string $passPhrase, string $expectedQuestions): void
    {
        $interaction = new InMemoryInteraction([self::CONFIRMATION => 'y'] + $passPhraseAnswers);
        $companion = $this->companion($interaction);
        $extension = new RsaKeys();

        $values = [];
        foreach (self::variables() as $variable) {
            $values[$variable->getName()] = $extension->getVariableValue($companion, self::block(), $variable);
        }

        $privateKeyPem = file_get_contents($this->temporaryDirectory . '/private.pem');
        $privateKey = openssl_pkey_get_private($privateKeyPem, $passPhrase);
        $this->assertSame(
            [
                'values' => ['KEY_PATH' => 'private.pem', 'PUB_PATH' => 'public.pem', 'KEY_PASS' => $passPhrase],
                'questions' => self::CONFIRMATION . "\n" . $expectedQuestions,
                'files' => ['.env.dist', 'private.pem', 'public.pem'],
                'opens with a wrong pass phrase' => false,
                'bits' => 4096,
                'public key' => openssl_pkey_get_details($privateKey)['key'],
            ],
            [
                'values' => $values,
                'questions' => $interaction->getBuffer(),
                'files' => array_keys($this->readTemporaryDirectory()),
                'opens with a wrong pass phrase' => openssl_pkey_get_private($privateKeyPem, 'wrong-phrase') !== false,
                'bits' => openssl_pkey_get_details($privateKey)['bits'],
                'public key' => file_get_contents($this->temporaryDirectory . '/public.pem'),
            ]
        );
    }

    /**
     * @return array<string, array{0: array<string, string|list<string>>, 1: string, 2: string}>
     */
    public static function passPhraseDataProvider(): array
    {
        return [
            'plain pass phrase' => [
                [self::PASS_PHRASE_QUESTION => 'secret-phrase'],
                'secret-phrase',
                self::PASS_PHRASE_QUESTION . "\n",
            ],
            'pass phrase with spaces and quotes' => [
                [self::PASS_PHRASE_QUESTION => 'it\'s my "secret" phrase'],
                'it\'s my "secret" phrase',
                self::PASS_PHRASE_QUESTION . "\n",
            ],
            'pass phrase of 4 bytes' => [
                [self::PASS_PHRASE_QUESTION => 'abcd'],
                'abcd',
                self::PASS_PHRASE_QUESTION . "\n",
            ],
            'pass phrase of 1023 bytes' => [
                [self::PASS_PHRASE_QUESTION => str_repeat('a', 1023)],
                str_repeat('a', 1023),
                self::PASS_PHRASE_QUESTION . "\n",
            ],
            'pass phrases shorter than 4 bytes: asked again until one is long enough' => [
                [self::PASS_PHRASE_QUESTION => 'abc', self::REPEATED_PASS_PHRASE_QUESTION => ['ab', 'abcd']],
                'abcd',
                self::PASS_PHRASE_QUESTION . "\n" . str_repeat(self::REPEATED_PASS_PHRASE_QUESTION . "\n", 2),
            ],
            'pass phrase longer than 1023 bytes: asked again' => [
                [self::PASS_PHRASE_QUESTION => str_repeat('a', 1024), self::REPEATED_PASS_PHRASE_QUESTION => ['abcd']],
                'abcd',
                self::PASS_PHRASE_QUESTION . "\n" . self::REPEATED_PASS_PHRASE_QUESTION . "\n",
            ],
        ];
    }

    /**
     * @dataProvider dotenvPathDataProvider
     *
     * @param array<string, string> $files
     */
    public function testGeneratedKeyPairAtThePathsDotenvReads(
        string $privateKeyPath,
        string $publicKeyPath,
        array $files,
        string $expectedPrivateKeyFile,
        string $expectedPublicKeyFile
    ): void {
        foreach ($files as $name => $contents) {
            file_put_contents($this->temporaryDirectory . '/' . $name, $contents);
        }
        $expectedFiles = array_keys(
            ['.env.dist' => ''] + $files + [$expectedPrivateKeyFile => '', $expectedPublicKeyFile => '']
        );
        sort($expectedFiles, SORT_STRING);
        $interaction = new InMemoryInteraction([
            self::CONFIRMATION => 'y',
            self::PASS_PHRASE_QUESTION => 'secret-phrase',
        ]);
        $companion = $this->companion($interaction);
        $extension = new RsaKeys();
        $block = self::block($privateKeyPath, $publicKeyPath);

        $values = [];
        foreach ($block->getVariables() as $variable) {
            $values[$variable->getName()] = $extension->getVariableValue($companion, $block, $variable);
        }

        $generatedFiles = $this->readTemporaryDirectory();
        $privateKey = openssl_pkey_get_private($generatedFiles[$expectedPrivateKeyFile] ?? '', 'secret-phrase');
        $publicKey = $generatedFiles[$expectedPublicKeyFile] ?? null;
        $this->assertSame(
            [
                'values' => [
                    'KEY_PATH' => $privateKeyPath,
                    'PUB_PATH' => $publicKeyPath,
                    'KEY_PASS' => 'secret-phrase',
                ],
                'files' => $expectedFiles,
                'public key of the private key' => true,
            ],
            [
                'values' => $values,
                'files' => array_keys($generatedFiles),
                'public key of the private key' => $privateKey !== false
                    && openssl_pkey_get_details($privateKey)['key'] === $publicKey,
            ]
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, string>, 3: string, 4: string}>
     */
    public static function dotenvPathDataProvider(): array
    {
        $pair = "## Keys\n#+rsa-pair(KEY_PATH PUB_PATH KEY_PASS)\n";

        return [
            'paths in double quotes' => ['"private.pem"', '"public.pem"', [], 'private.pem', 'public.pem'],
            'paths in single quotes' => ["'private.pem'", "'public.pem'", [], 'private.pem', 'public.pem'],
            'paths followed by a comment' => [
                'private.pem # the private key',
                'public.pem # the public key',
                [],
                'private.pem',
                'public.pem',
            ],
            'empty paths in .env' => [
                'private.pem',
                'public.pem',
                ['.env' => "KEY_PATH=\nPUB_PATH=\n"],
                'private.pem',
                'public.pem',
            ],
            'paths with a variable set in .env' => [
                '${KEY_PREFIX}private.pem',
                '${KEY_PREFIX}public.pem',
                ['.env' => "KEY_PREFIX=my-\n"],
                'my-private.pem',
                'my-public.pem',
            ],
            'paths with a variable set earlier in .env.dist' => [
                '${KEY_PREFIX}private.pem',
                '${KEY_PREFIX}public.pem',
                [
                    '.env.dist' => "KEY_PREFIX=my-\n" . $pair
                        . "KEY_PATH=\${KEY_PREFIX}private.pem\nPUB_PATH=\${KEY_PREFIX}public.pem\nKEY_PASS=\n",
                ],
                'my-private.pem',
                'my-public.pem',
            ],
            'public key path made of the private key path' => [
                'private.pem',
                '${KEY_PATH}.pub',
                ['.env.dist' => $pair . "KEY_PATH=private.pem\nPUB_PATH=\${KEY_PATH}.pub\nKEY_PASS=\n"],
                'private.pem',
                'private.pem.pub',
            ],
        ];
    }

    /**
     * @dataProvider existingKeyPairDataProvider
     *
     * @param array<string, string> $files
     */
    public function testExistingKeyPairIsKept(Block $block, array $files): void
    {
        foreach ($files as $name => $contents) {
            file_put_contents($this->temporaryDirectory . '/' . $name, $contents);
        }
        $expectedFiles = ['.env.dist' => ''] + $files;
        ksort($expectedFiles, SORT_STRING);
        $interaction = new InMemoryInteraction([self::EXISTING_PASS_PHRASE_QUESTION => 'current-phrase']);
        $companion = $this->companion($interaction);
        $extension = new RsaKeys();

        $values = [];
        foreach ($block->getVariables() as $variable) {
            $values[$variable->getName()] = $extension->getVariableValue($companion, $block, $variable);
        }

        $this->assertSame(
            [
                'values' => ['KEY_PATH' => null, 'PUB_PATH' => null, 'KEY_PASS' => 'current-phrase'],
                'questions' => self::EXISTING_PASS_PHRASE_QUESTION . "\n",
                'files' => $expectedFiles,
            ],
            [
                'values' => $values,
                'questions' => $interaction->getBuffer(),
                'files' => $this->readTemporaryDirectory(),
            ]
        );
    }

    /**
     * @return array<string, array{0: Block, 1: array<string, string>}>
     */
    public static function existingKeyPairDataProvider(): array
    {
        $keyFiles = ['private.pem' => 'CURRENT PRIVATE KEY', 'public.pem' => 'CURRENT PUBLIC KEY'];

        return [
            'paths as written' => [self::block(), $keyFiles],
            'paths in quotes and followed by a comment' => [
                self::block('"private.pem"', 'public.pem # the public key'),
                $keyFiles,
            ],
            'paths with a variable set in .env' => [
                self::block('${KEY_PREFIX}private.pem', '${KEY_PREFIX}public.pem'),
                [
                    '.env' => "KEY_PREFIX=my-\n",
                    'my-private.pem' => 'CURRENT PRIVATE KEY',
                    'my-public.pem' => 'CURRENT PUBLIC KEY',
                ],
            ],
            'paths set in .env' => [
                self::block(),
                [
                    '.env' => "KEY_PATH=my-private.pem\nPUB_PATH=my-public.pem\n",
                    'my-private.pem' => 'CURRENT PRIVATE KEY',
                    'my-public.pem' => 'CURRENT PUBLIC KEY',
                ],
            ],
        ];
    }

    public function testGeneratedKeyPairAtThePathsSetInTheEnvFile(): void
    {
        file_put_contents(
            $this->temporaryDirectory . '/.env',
            "KEY_PATH=my-private.pem\nPUB_PATH=my-public.pem\nKEY_PASS=former-phrase\n"
        );
        $interaction = new InMemoryInteraction([
            self::CONFIRMATION => 'y',
            self::PASS_PHRASE_QUESTION => 'secret-phrase',
        ]);
        $companion = $this->companion($interaction);
        $extension = new RsaKeys();
        $block = self::block();

        $values = [];
        foreach ($block->getVariables() as $variable) {
            $values[$variable->getName()] = $extension->getVariableValue($companion, $block, $variable);
        }

        $generatedFiles = $this->readTemporaryDirectory();
        $generatedKey = openssl_pkey_get_private($generatedFiles['my-private.pem'] ?? '', 'secret-phrase');
        $this->assertSame(
            [
                'values' => [
                    'KEY_PATH' => 'my-private.pem',
                    'PUB_PATH' => 'my-public.pem',
                    'KEY_PASS' => 'secret-phrase',
                ],
                'files' => ['.env', '.env.dist', 'my-private.pem', 'my-public.pem'],
                'public key of the private key' => true,
            ],
            [
                'values' => $values,
                'files' => array_keys($generatedFiles),
                'public key of the private key' => $generatedKey !== false
                    && openssl_pkey_get_details($generatedKey)['key'] === ($generatedFiles['my-public.pem'] ?? null),
            ]
        );
    }

    public function testExistingKeyPairKeepsThePassPhraseSetInTheEnvFile(): void
    {
        file_put_contents($this->temporaryDirectory . '/.env', "KEY_PASS=stored-phrase\n");
        file_put_contents($this->temporaryDirectory . '/private.pem', 'CURRENT PRIVATE KEY');
        file_put_contents($this->temporaryDirectory . '/public.pem', 'CURRENT PUBLIC KEY');
        $interaction = new InMemoryInteraction();

        $value = (new RsaKeys())->getVariableValue(
            $this->companion($interaction),
            self::block(),
            new Variable('KEY_PASS', '')
        );

        $this->assertSame(
            ['value' => 'stored-phrase', 'questions' => ''],
            ['value' => $value, 'questions' => $interaction->getBuffer()]
        );
    }

    public function testGenerationFailureKeepsThePassPhraseOutOfTheErrors(): void
    {
        $variables = [
            new Variable('KEY_PATH', 'missing/private.pem'),
            new Variable('PUB_PATH', 'missing/public.pem'),
            new Variable('KEY_PASS', ''),
        ];
        $block = new Block('Keys', '', $variables, [
            new Attribute('rsa-pair', ['KEY_PATH', 'PUB_PATH', 'KEY_PASS'], []),
        ]);
        $interaction = new InMemoryInteraction([
            self::CONFIRMATION => 'y',
            self::PASS_PHRASE_QUESTION => 'secret-phrase',
        ]);

        $messages = [];
        $errorsHoldingTheProcess = 0;
        try {
            (new RsaKeys())->getVariableValue($this->companion($interaction), $block, $variables[0]);
        } catch (RuntimeException $exception) {
            for ($error = $exception; $error !== null; $error = $error->getPrevious()) {
                $messages[] = $error->getMessage();
                $errorsHoldingTheProcess += (int) method_exists($error, 'getProcess');
            }
        }

        $prefix = 'Could not have generated the RSA public/private key: ';
        $this->assertSame(
            [
                'message start' => $prefix,
                'exit code in the message' => true,
                'errors' => 1,
                'errors holding the process' => 0,
                'pass phrase in a message' => false,
            ],
            [
                'message start' => substr($messages[0] ?? '', 0, strlen($prefix)),
                'exit code in the message' => preg_match('/Exit Code: \\d/', $messages[0] ?? '') === 1,
                'errors' => count($messages),
                'errors holding the process' => $errorsHoldingTheProcess,
                'pass phrase in a message' => preg_grep('/secret-phrase/', $messages) !== [],
            ]
        );
    }

    /**
     * @dataProvider existingFilesDataProvider
     *
     * @param list<string> $existingFiles
     */
    public function testIsVariableRequiringValue(
        Block $block,
        array $existingFiles,
        int $expected,
        string $env = ''
    ): void {
        foreach ($existingFiles as $name) {
            touch($this->temporaryDirectory . '/' . $name);
        }
        if ('' !== $env) {
            file_put_contents($this->temporaryDirectory . '/.env', $env);
        }

        $this->assertSame(
            $expected,
            (new RsaKeys())->isVariableRequiringValue(
                $this->companion(new InMemoryInteraction()),
                $block,
                new Variable('KEY_PATH', 'private.pem')
            )
        );
    }

    /**
     * @return array<string, array{0: Block, 1: list<string>, 2: int, 3?: string}>
     */
    public static function existingFilesDataProvider(): array
    {
        return [
            'no rsa-pair attribute' => [new Block('Keys', '', self::variables()), [], Extension::ABSTAIN],
            'no key file' => [self::block(), [], Extension::VARIABLE_REQUIRED],
            'only the private key' => [self::block(), ['private.pem'], Extension::VARIABLE_REQUIRED],
            'only the public key' => [self::block(), ['public.pem'], Extension::VARIABLE_REQUIRED],
            'both key files' => [self::block(), ['private.pem', 'public.pem'], Extension::ABSTAIN],
            'both key files, at the paths Dotenv reads' => [
                self::block('"private.pem"', 'public.pem # the public key'),
                ['private.pem', 'public.pem'],
                Extension::ABSTAIN,
            ],
            'both key files, at the paths set in .env' => [
                self::block(),
                ['my-private.pem', 'my-public.pem'],
                Extension::ABSTAIN,
                "KEY_PATH=my-private.pem\nPUB_PATH=my-public.pem\n",
            ],
            'both key files at the .env.dist paths only, .env sets others' => [
                self::block(),
                ['private.pem', 'public.pem'],
                Extension::VARIABLE_REQUIRED,
                "KEY_PATH=my-private.pem\nPUB_PATH=my-public.pem\n",
            ],
            'empty paths in .env: the .env.dist paths' => [
                self::block(),
                ['private.pem', 'public.pem'],
                Extension::ABSTAIN,
                "KEY_PATH=\nPUB_PATH=\n",
            ],
            'empty paths in .env, no key file at the .env.dist paths' => [
                self::block(),
                [],
                Extension::VARIABLE_REQUIRED,
                "KEY_PATH=\nPUB_PATH=\n",
            ],
        ];
    }

    /**
     * @return list<Variable>
     */
    private static function variables(
        string $privateKeyPath = 'private.pem',
        string $publicKeyPath = 'public.pem'
    ): array {
        return [
            new Variable('KEY_PATH', $privateKeyPath),
            new Variable('PUB_PATH', $publicKeyPath),
            new Variable('KEY_PASS', ''),
        ];
    }

    private static function block(string $privateKeyPath = 'private.pem', string $publicKeyPath = 'public.pem'): Block
    {
        return new Block('Keys', '', self::variables($privateKeyPath, $publicKeyPath), [
            new Attribute('rsa-pair', ['KEY_PATH', 'PUB_PATH', 'KEY_PASS'], []),
        ]);
    }

    private function companion(InMemoryInteraction $interaction): Companion
    {
        return new Companion(new NativePhpFileSystem($this->temporaryDirectory), $interaction, new Chained());
    }
}

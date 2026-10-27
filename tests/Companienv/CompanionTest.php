<?php

declare(strict_types=1);

namespace Companienv;

use Companienv\DotEnv\Variable;
use Companienv\Extension\Chained;
use Companienv\IO\InMemoryFileSystem;
use Companienv\IO\InMemoryInteraction;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;

final class CompanionTest extends TestCase
{
    private const FILE_QUESTION = 'KEY_PATH: What is the path of your downloaded file? (leave empty to skip)';

    /**
     * @dataProvider fillGapsDataProvider
     *
     * @param array<string, string> $files
     * @param array<string, string> $answers
     */
    public function testFillGaps(array $files, array $answers, ?string $expectedEnv, string $expectedOutput): void
    {
        $fileSystem = new InMemoryFileSystem();
        foreach ($files as $path => $contents) {
            $fileSystem->write($path, $contents);
        }
        $interaction = new InMemoryInteraction($answers);

        (new Companion($fileSystem, $interaction, new Chained(Application::defaultExtensions())))->fillGaps();

        $this->assertSame(
            ['.env' => $expectedEnv, 'output' => $expectedOutput],
            [
                '.env' => $fileSystem->exists('.env') ? $fileSystem->getContents('.env') : null,
                'output' => $interaction->getBuffer(),
            ]
        );
    }

    /**
     * @dataProvider writtenValueDataProvider
     *
     * @param array<string, string> $answers
     * @param array<string, string> $expectedValues
     */
    public function testFillGapsWritesValuesThatReadBack(
        string $distFile,
        array $answers,
        string $expectedEnv,
        array $expectedValues
    ): void {
        $fileSystem = new InMemoryFileSystem();
        $fileSystem->write('.env.dist', $distFile);
        $companion = new Companion(
            $fileSystem,
            new InMemoryInteraction(["Let's fix this? (y)" => 'y'] + $answers),
            new Chained(Application::defaultExtensions())
        );

        $companion->fillGaps();

        $this->assertSame(
            ['.env' => $expectedEnv, 'values' => $expectedValues],
            ['.env' => $fileSystem->getContents('.env'), 'values' => $companion->getDefinedVariablesHash()]
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2: string, 3: array<string, string>}>
     */
    public static function writtenValueDataProvider(): array
    {
        return [
            'plain value' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => 'plain'],
                "MY_VARIABLE=plain\n",
                ['MY_VARIABLE' => 'plain'],
            ],
            'value with spaces' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => 'my site'],
                "MY_VARIABLE='my site'\n",
                ['MY_VARIABLE' => 'my site'],
            ],
            'value with quotes' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => 'it\'s my "secret" phrase'],
                "MY_VARIABLE='it'\"'\"'s my \"secret\" phrase'\n",
                ['MY_VARIABLE' => 'it\'s my "secret" phrase'],
            ],
            'value with a single quote' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => "it's my site"],
                "MY_VARIABLE=\"it's my site\"\n",
                ['MY_VARIABLE' => "it's my site"],
            ],
            'value with a dollar' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => 'pa$word'],
                "MY_VARIABLE='pa\$word'\n",
                ['MY_VARIABLE' => 'pa$word'],
            ],
            'value with a single quote, a dollar and a backslash' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => 'it\'s $HOME C:\\new'],
                "MY_VARIABLE='it'\"'\"'s \$HOME C:\\new'\n",
                ['MY_VARIABLE' => 'it\'s $HOME C:\\new'],
            ],
            'value with a single quote and a backslash before n' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => 'it\'s C:\\new'],
                "MY_VARIABLE='it'\"'\"'s C:\\new'\n",
                ['MY_VARIABLE' => 'it\'s C:\\new'],
            ],
            'value with a backslash before r' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => 'C:\\run'],
                "MY_VARIABLE='C:\\run'\n",
                ['MY_VARIABLE' => 'C:\\run'],
            ],
            'value with a trailing escaped double quote' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => 'x\\"'],
                "MY_VARIABLE='x\\\"'\n",
                ['MY_VARIABLE' => 'x\\"'],
            ],
            'value with a single quote and a backslash before r' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => 'it\'s C:\\run'],
                "MY_VARIABLE='it'\"'\"'s C:\\run'\n",
                ['MY_VARIABLE' => 'it\'s C:\\run'],
            ],
            'value with a single quote and a variable reference' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => 'it\'s $HOME'],
                "MY_VARIABLE='it'\"'\"'s \$HOME'\n",
                ['MY_VARIABLE' => 'it\'s $HOME'],
            ],
            'value with a single quote followed by a dollar' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => 'P@ss\'w$rd'],
                "MY_VARIABLE='P@ss'\"'\"'w\$rd'\n",
                ['MY_VARIABLE' => 'P@ss\'w$rd'],
            ],
            'value with a single quote and a trailing escaped double quote' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => 'x\'\\"'],
                "MY_VARIABLE='x'\"'\"'\\\"'\n",
                ['MY_VARIABLE' => 'x\'\\"'],
            ],
            'value with a hash' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => 'a #b'],
                "MY_VARIABLE='a #b'\n",
                ['MY_VARIABLE' => 'a #b'],
            ],
            'value with backslashes' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => 'C:\\new\\\\x'],
                "MY_VARIABLE='C:\\new\\\\x'\n",
                ['MY_VARIABLE' => 'C:\\new\\\\x'],
            ],
            'typed value in single quotes' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => "'abc'"],
                "MY_VARIABLE=\"'abc'\"\n",
                ['MY_VARIABLE' => "'abc'"],
            ],
            'typed value in double quotes' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => '"abc"'],
                "MY_VARIABLE='\"abc\"'\n",
                ['MY_VARIABLE' => '"abc"'],
            ],
            'typed value with a command' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => '$(pwd)'],
                "MY_VARIABLE='\$(pwd)'\n",
                ['MY_VARIABLE' => '$(pwd)'],
            ],
            'typed value with a command in double quotes' => [
                "MY_VARIABLE=\n",
                ['MY_VARIABLE ?' => '"$(echo injected)"'],
                "MY_VARIABLE='\"\$(echo injected)\"'\n",
                ['MY_VARIABLE' => '"$(echo injected)"'],
            ],
            'default in quotes from the dist file' => [
                "MY_VARIABLE=\"My App\"\n",
                ['MY_VARIABLE ? ("My App")' => '"My App"'],
                "MY_VARIABLE=\"My App\"\n",
                ['MY_VARIABLE' => 'My App'],
            ],
            'default with a space from the dist file' => [
                "MY_VARIABLE=My App\n",
                ['MY_VARIABLE ? (My App)' => 'My App'],
                "MY_VARIABLE='My App'\n",
                ['MY_VARIABLE' => 'My App'],
            ],
            'default ending in an unmatched quote from the dist file' => [
                "MY_VARIABLE=27\"\n",
                ['MY_VARIABLE ? (27")' => '27"'],
                "MY_VARIABLE='27\"'\n",
                ['MY_VARIABLE' => '27"'],
            ],
            'default with an inline comment from the dist file' => [
                "MY_VARIABLE=dev # dev or prod\n",
                ['MY_VARIABLE ? (dev # dev or prod)' => 'dev # dev or prod'],
                "MY_VARIABLE=dev # dev or prod\n",
                ['MY_VARIABLE' => 'dev'],
            ],
            'default referencing another variable from the dist file' => [
                "DB_USER=app\nDB_URL=mysql://\${DB_USER}@db\n",
                ['DB_USER ? (app)' => 'app', 'DB_URL ? (mysql://${DB_USER}@db)' => 'mysql://${DB_USER}@db'],
                "DB_USER=app\nDB_URL=mysql://\${DB_USER}@db\n",
                ['DB_USER' => 'app', 'DB_URL' => 'mysql://app@db'],
            ],
        ];
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: array<string, string>, 2: string|null, 3: string}>
     */
    public static function fillGapsDataProvider(): array
    {
        $missingOne = 'It looks like you are missing some configuration (1 variables). '
            . "I will help you to sort this out.\n"
            . "Let's fix this? (y)\n";

        return [
            'no env file: every variable is asked and written' => [
                ['.env.dist' => "## Something\n# With more details\nMY_VARIABLE=default-value\nOTHER_VARIABLE=\n"],
                [
                    "Let's fix this? (y)" => 'y',
                    'MY_VARIABLE ? (default-value)' => 'my-value',
                    'OTHER_VARIABLE ?' => 'other-value',
                ],
                "MY_VARIABLE=my-value\nOTHER_VARIABLE=other-value\n",
                "It looks like you are missing some configuration (2 variables). I will help you to sort this out.\n"
                . "Let's fix this? (y)\n\n<info>Something</info>\nWith more details\n\n"
                . "MY_VARIABLE ? (default-value)\nOTHER_VARIABLE ?\n",
            ],
            'env file: only the missing variable is asked and appended' => [
                [
                    '.env.dist' => "## Something\nMY_VARIABLE=default-value\nA_NEW_VARIABLE=\n",
                    '.env' => "MY_VARIABLE=something-else\n",
                ],
                ["Let's fix this? (y)" => 'y', 'A_NEW_VARIABLE ?' => 'value'],
                "MY_VARIABLE=something-else\nA_NEW_VARIABLE=value\n",
                $missingOne . "\n<info>Something</info>\n\nA_NEW_VARIABLE ?\n",
            ],
            'block without missing variables: skipped' => [
                [
                    '.env.dist' => "## Complete\nMY_VARIABLE=default-value\n## Incomplete\nA_NEW_VARIABLE=\n",
                    '.env' => "MY_VARIABLE=something-else\n",
                ],
                ["Let's fix this? (y)" => 'y', 'A_NEW_VARIABLE ?' => 'value'],
                "MY_VARIABLE=something-else\nA_NEW_VARIABLE=value\n",
                $missingOne . "\n<info>Incomplete</info>\n\nA_NEW_VARIABLE ?\n",
            ],
            'empty value with a non-empty reference: asked again and updated in place' => [
                [
                    '.env.dist' => "MY_VARIABLE=default-value\nOTHER_VARIABLE=kept\n",
                    '.env' => "MY_VARIABLE=\nOTHER_VARIABLE=kept\n",
                ],
                ["Let's fix this? (y)" => 'y', 'MY_VARIABLE ? (default-value)' => 'my-value'],
                "MY_VARIABLE=my-value\nOTHER_VARIABLE=kept\n",
                $missingOne . "\nMY_VARIABLE ? (default-value)\n",
            ],
            'empty value updated with a value containing a dollar and a digit' => [
                ['.env.dist' => "DB_PASSWORD=secret\n", '.env' => "DB_PASSWORD=\n"],
                ["Let's fix this? (y)" => 'y', 'DB_PASSWORD ? (secret)' => 'pa$1ss'],
                "DB_PASSWORD='pa\$1ss'\n",
                $missingOne . "\nDB_PASSWORD ? (secret)\n",
            ],
            'empty value updated with a value containing backslashes and a digit' => [
                ['.env.dist' => "DB_PASSWORD=secret\n", '.env' => "DB_PASSWORD=\n"],
                ["Let's fix this? (y)" => 'y', 'DB_PASSWORD ? (secret)' => 'pa\\1\\\\ss'],
                "DB_PASSWORD='pa\\1\\\\ss'\n",
                $missingOne . "\nDB_PASSWORD ? (secret)\n",
            ],
            'empty value with an empty reference: nothing is asked' => [
                ['.env.dist' => "EMPTY_VARIABLE=\n", '.env' => "EMPTY_VARIABLE=\n"],
                [],
                "EMPTY_VARIABLE=\n",
                '',
            ],
            'falsy value: kept' => [
                ['.env.dist' => "MY_VARIABLE=default-value\n", '.env' => "MY_VARIABLE=0\n"],
                [],
                "MY_VARIABLE=0\n",
                '',
            ],
            'value containing an equals sign' => [
                ['.env.dist' => "A_BASE64_VALUE=abc123=\n", '.env' => "A_BASE64_VALUE=\n"],
                ["Let's fix this? (y)" => 'y', 'A_BASE64_VALUE ? (abc123=)' => 'abc123='],
                "A_BASE64_VALUE=abc123=\n",
                $missingOne . "\nA_BASE64_VALUE ? (abc123=)\n",
            ],
            'file to propagate present and its variable set: nothing is asked' => [
                [
                    '.env.dist' => "## Keys\n#+file-to-propagate(KEY_PATH)\nKEY_PATH=target.pem\n",
                    '.env' => "KEY_PATH=target.pem\n",
                    'target.pem' => 'CURRENT',
                ],
                [],
                "KEY_PATH=target.pem\n",
                '',
            ],
            'file to propagate missing while its variable is set: the file is asked for' => [
                [
                    '.env.dist' => "## Keys\n#+file-to-propagate(KEY_PATH)\nKEY_PATH=target.pem\n",
                    '.env' => "KEY_PATH=target.pem\n",
                    '/downloads/key.pem' => 'DOWNLOADED',
                ],
                [
                    "Let's fix this? (y)" => 'y',
                    self::FILE_QUESTION => '/downloads/key.pem',
                ],
                "KEY_PATH=target.pem\n",
                $missingOne . "\n<info>Keys</info>\n\n" . self::FILE_QUESTION . "\n",
            ],
            'file to propagate at the path set in .env: nothing is asked' => [
                [
                    '.env.dist' => "## Keys\n#+file-to-propagate(KEY_PATH)\nKEY_PATH=target.pem\n",
                    '.env' => "KEY_PATH=custom.pem\n",
                    'custom.pem' => 'CURRENT',
                ],
                [],
                "KEY_PATH=custom.pem\n",
                '',
            ],
            'file to propagate missing at the path set in .env: copied there, .env left as it is' => [
                [
                    '.env.dist' => "## Keys\n#+file-to-propagate(KEY_PATH)\nKEY_PATH=target.pem\n",
                    '.env' => "KEY_PATH=custom.pem # from the vault\n",
                    '/downloads/key.pem' => 'DOWNLOADED',
                ],
                [
                    "Let's fix this? (y)" => 'y',
                    self::FILE_QUESTION => '/downloads/key.pem',
                ],
                "KEY_PATH=custom.pem # from the vault\n",
                $missingOne . "\n<info>Keys</info>\n\n" . self::FILE_QUESTION . "\n",
            ],
            'file to propagate present at the path its .env.dist default names' => [
                [
                    '.env.dist' => "## Keys\n#+file-to-propagate(KEY_PATH)\nKEY_PATH=target.pem # the deploy key\n",
                    'target.pem' => 'CURRENT',
                ],
                ["Let's fix this? (y)" => 'y'],
                "KEY_PATH=target.pem # the deploy key\n",
                $missingOne . "\n<info>Keys</info>\n\n",
            ],
            'file to propagate missing: copied to the path its .env.dist default names' => [
                [
                    '.env.dist' => "## Keys\n#+file-to-propagate(KEY_PATH)\nKEY_PATH=target.pem # the deploy key\n",
                    '/downloads/key.pem' => 'DOWNLOADED',
                ],
                [
                    "Let's fix this? (y)" => 'y',
                    self::FILE_QUESTION => '/downloads/key.pem',
                ],
                "KEY_PATH=target.pem # the deploy key\n",
                $missingOne . "\n<info>Keys</info>\n\n" . self::FILE_QUESTION . "\n",
            ],
            'file to propagate without a path: the variable is asked like any other' => [
                ['.env.dist' => "## Keys\n#+file-to-propagate(KEY_PATH)\nKEY_PATH=\n"],
                ["Let's fix this? (y)" => 'y', 'KEY_PATH ?' => 'keys/key.pem'],
                "KEY_PATH=keys/key.pem\n",
                $missingOne . "\n<info>Keys</info>\n\nKEY_PATH ?\n",
            ],
            'file to propagate without a path, empty in .env: nothing is asked' => [
                ['.env.dist' => "## Keys\n#+file-to-propagate(KEY_PATH)\nKEY_PATH=\n", '.env' => "KEY_PATH=\n"],
                [],
                "KEY_PATH=\n",
                '',
            ],
            'confirmation declined: nothing is written' => [
                ['.env.dist' => "MY_VARIABLE=default-value\n"],
                ["Let's fix this? (y)" => ''],
                null,
                $missingOne . "\n<comment>I let you think about it then. "
                    . "Re-run the command to get started again.</comment>\n\n",
            ],
        ];
    }

    /**
     * @dataProvider existingFileToPropagateDataProvider
     *
     * @param array<string, string> $files
     */
    public function testFillGapsKeepsAnExistingFileToPropagate(array $files, string $expectedEnv): void
    {
        $fileSystem = new InMemoryFileSystem();
        $fileSystem->write('.env.dist', "## Keys\n#+file-to-propagate(KEY_PATH)\nKEY_PATH=target.pem\n");
        $fileSystem->write('target.pem', 'CURRENT');
        $fileSystem->write('/downloads/key.pem', 'DOWNLOADED');
        foreach ($files as $path => $contents) {
            $fileSystem->write($path, $contents);
        }
        $interaction = new InMemoryInteraction([
            "Let's fix this? (y)" => 'y',
            self::FILE_QUESTION => '/downloads/key.pem',
        ]);

        (new Companion($fileSystem, $interaction, new Chained(Application::defaultExtensions())))->fillGaps();

        $this->assertSame(
            ['.env' => $expectedEnv, 'target.pem' => 'CURRENT'],
            ['.env' => $fileSystem->getContents('.env'), 'target.pem' => $fileSystem->getContents('target.pem')]
        );
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function existingFileToPropagateDataProvider(): array
    {
        return [
            'no .env file' => [[], "KEY_PATH=target.pem\n"],
            '.env file without the variable' => [
                ['.env' => "OTHER_VARIABLE=1\n"],
                "OTHER_VARIABLE=1\nKEY_PATH=target.pem\n",
            ],
        ];
    }

    /**
     * @dataProvider existingKeyPairDataProvider
     *
     * @param array<string, string> $answers
     */
    public function testFillGapsKeepsAnExistingKeyPair(string $env, array $answers, string $expectedEnv): void
    {
        $fileSystem = new InMemoryFileSystem();
        $fileSystem->write(
            '.env.dist',
            "## Keys\n#+rsa-pair(KEY_PATH PUB_PATH KEY_PASS)\nKEY_PATH=private.pem\nPUB_PATH=public.pem\nKEY_PASS=\n"
        );
        $fileSystem->write('.env', $env);
        $fileSystem->write('private.pem', 'CURRENT PRIVATE KEY');
        $fileSystem->write('public.pem', 'CURRENT PUBLIC KEY');
        $interaction = new InMemoryInteraction(["Let's fix this? (y)" => 'y'] + $answers);

        (new Companion($fileSystem, $interaction, new Chained(Application::defaultExtensions())))->fillGaps();

        $this->assertSame(
            [
                '.env' => $expectedEnv,
                'private.pem' => 'CURRENT PRIVATE KEY',
                'public.pem' => 'CURRENT PUBLIC KEY',
            ],
            [
                '.env' => $fileSystem->getContents('.env'),
                'private.pem' => $fileSystem->getContents('private.pem'),
                'public.pem' => $fileSystem->getContents('public.pem'),
            ]
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2: string}>
     */
    public static function existingKeyPairDataProvider(): array
    {
        return [
            '.env without a key path' => [
                "KEY_PATH=private.pem\nKEY_PASS=current-phrase\n",
                ['PUB_PATH ? (public.pem)' => 'public.pem'],
                "KEY_PATH=private.pem\nKEY_PASS=current-phrase\nPUB_PATH=public.pem\n",
            ],
            '.env without the pass phrase' => [
                "KEY_PATH=private.pem\nPUB_PATH=public.pem\n",
                ['KEY_PASS ?' => 'current-phrase'],
                "KEY_PATH=private.pem\nPUB_PATH=public.pem\nKEY_PASS=current-phrase\n",
            ],
            'empty .env, another path typed for the private key' => [
                '',
                [
                    'KEY_PATH ? (private.pem)' => 'elsewhere.pem',
                    'PUB_PATH ? (public.pem)' => 'public.pem',
                    'KEY_PASS ?' => 'current-phrase',
                ],
                "KEY_PATH=elsewhere.pem\nPUB_PATH=public.pem\nKEY_PASS=current-phrase\n",
            ],
        ];
    }

    public function testFillGapsPropagatesAFileToAPathWithAVariableSetInTheEnvFile(): void
    {
        $fileSystem = new InMemoryFileSystem();
        $fileSystem->write('.env.dist', "## Keys\n#+file-to-propagate(KEY_PATH)\nKEY_PATH=\${KEY_DIR}/target.pem\n");
        $fileSystem->write('.env', "KEY_DIR=keys\n");
        $fileSystem->write('/downloads/key.pem', 'DOWNLOADED');
        $interaction = new InMemoryInteraction([
            "Let's fix this? (y)" => 'y',
            self::FILE_QUESTION => '/downloads/key.pem',
        ]);

        (new Companion($fileSystem, $interaction, new Chained(Application::defaultExtensions())))->fillGaps();

        $this->assertSame(
            ['.env' => "KEY_DIR=keys\nKEY_PATH=\${KEY_DIR}/target.pem\n", 'keys/target.pem' => 'DOWNLOADED'],
            [
                '.env' => $fileSystem->getContents('.env'),
                'keys/target.pem' => $fileSystem->exists('keys/target.pem')
                    ? $fileSystem->getContents('keys/target.pem')
                    : null,
            ]
        );
    }

    /**
     * @dataProvider distValueDataProvider
     */
    public function testResolveDistValueRunsOnlyTheCommandsTheValueReads(
        string $value,
        string $expectedValue,
        int $expectedRuns
    ): void {
        $log = (string) tempnam(sys_get_temp_dir(), 'companienv-');
        $fileSystem = new InMemoryFileSystem();
        $fileSystem->write('.env.dist', str_replace('{log}', $log, "RUN=\"\$(echo x >> {log}; echo run)\"\n"
            . "LITERAL='\$(echo x >> {log})'\nBASE=dist\nKEY_DIR=\${BASE}/rsa\nA=\${RUN}a\nB=\${RUN}b\n"
            . "KEY_PATH={$value}\n"));
        $fileSystem->write('.env', "BASE=custom\n");
        $companion = new Companion($fileSystem, new InMemoryInteraction(), new Chained());

        try {
            $values = [
                $companion->resolveDistValue(new Variable('KEY_PATH', $value)),
                $companion->resolveDistValue(new Variable('KEY_PATH', $value)),
            ];
            $runs = substr_count((string) file_get_contents($log), 'x');
        } finally {
            unlink($log);
        }

        $expectedValue = str_replace('{log}', $log, $expectedValue);
        $this->assertSame(
            ['values' => [$expectedValue, $expectedValue], 'runs' => $expectedRuns],
            ['values' => $values, 'runs' => $runs]
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function distValueDataProvider(): array
    {
        return [
            'no reference' => ['keys/private.pem', 'keys/private.pem', 0],
            'chain of references' => ['${KEY_DIR}/private.pem # the key', 'custom/rsa/private.pem', 0],
            'reference in single quotes' => ["'\${RUN}/private.pem'", '${RUN}/private.pem', 0],
            'reference in a comment' => ['keys/private.pem # $RUN', 'keys/private.pem', 0],
            'reference to a command' => ['${RUN}/private.pem', 'run/private.pem', 1],
            'two references to a command' => ['${A}/${B}/private.pem', 'runa/runb/private.pem', 1],
            'command naming no variable' => ['"$(echo keys)/private.pem"', 'keys/private.pem', 0],
            'command with a reference' => ['"$(echo ${KEY_DIR})/private.pem"', 'custom/rsa/private.pem', 0],
            'command reading a .env value' => ['"$(printenv BASE)/private.pem"', 'custom/private.pem', 0],
            'command reading a .env value it does not name' => [
                '"$(env | grep -c =custom)/private.pem"',
                '1/private.pem',
                0,
            ],
            'command next to the name of a default' => ['"$(echo keys)/RUN/private.pem"', 'keys/RUN/private.pem', 0],
            'command with the name of a default in a comment' => [
                '"$(echo keys)/private.pem" # RUN',
                'keys/private.pem',
                0,
            ],
            'command with a reference to a command' => ['"$(echo ${A})/private.pem"', 'runa/private.pem', 1],
        ];
    }

    public function testFillGapsResolvesADistPathAgainstTheValuesWrittenBeforeIt(): void
    {
        $fileSystem = new InMemoryFileSystem();
        $fileSystem->write(
            '.env.dist',
            "KEY_DIR=keys\n## Keys\n#+file-to-propagate(KEY_PATH)\nKEY_PATH=\${KEY_DIR}/target.pem\n"
        );
        $fileSystem->write('/downloads/key.pem', 'DOWNLOADED');
        $interaction = new InMemoryInteraction([
            "Let's fix this? (y)" => 'y',
            'KEY_DIR ? (keys)' => 'custom',
            self::FILE_QUESTION => '/downloads/key.pem',
        ]);

        (new Companion($fileSystem, $interaction, new Chained(Application::defaultExtensions())))->fillGaps();

        $this->assertSame(
            [
                '.env' => "KEY_DIR=custom\nKEY_PATH=\${KEY_DIR}/target.pem\n",
                'custom/target.pem' => 'DOWNLOADED',
                'keys/target.pem' => false,
            ],
            [
                '.env' => $fileSystem->getContents('.env'),
                'custom/target.pem' => $fileSystem->exists('custom/target.pem')
                    ? $fileSystem->getContents('custom/target.pem')
                    : null,
                'keys/target.pem' => $fileSystem->exists('keys/target.pem'),
            ]
        );
    }

    public function testResolveDistValueReadsAReferenceToAQuotedCommandAsDotenvDoes(): void
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'companienv-');
        $distFile = "LITERAL='\$(echo x >> {$log})'\nKEY_PATH=\${LITERAL}/private.pem\n";
        $fileSystem = new InMemoryFileSystem();
        $fileSystem->write('.env.dist', $distFile);
        $companion = new Companion($fileSystem, new InMemoryInteraction(), new Chained());

        try {
            $value = $companion->resolveDistValue(new Variable('KEY_PATH', '${LITERAL}/private.pem'));
            $runs = substr_count((string) file_get_contents($log), 'x');
            $dotenvValue = (new Dotenv())->parse($distFile)['KEY_PATH'];
            $dotenvRuns = substr_count((string) file_get_contents($log), 'x') - $runs;
        } finally {
            unlink($log);
        }

        $this->assertSame(['value' => $dotenvValue, 'runs' => $dotenvRuns], ['value' => $value, 'runs' => $runs]);
    }

    /**
     * @dataProvider envLineDataProvider
     */
    public function testResolveDistValueReadsTheEnvValuesAboveTheLineItIsWrittenOver(
        string $env,
        string $expectedValue
    ): void {
        $fileSystem = new InMemoryFileSystem();
        $fileSystem->write('.env.dist', "KEY_DIR=keys\nKEY_PATH=\${KEY_DIR}/private.pem\n");
        $fileSystem->write('.env', $env);
        $companion = new Companion($fileSystem, new InMemoryInteraction(), new Chained());
        $writtenEnv = false !== strpos($env, "KEY_PATH=\n")
            ? str_replace("KEY_PATH=\n", "KEY_PATH=\${KEY_DIR}/private.pem\n", $env)
            : $env . "KEY_PATH=\${KEY_DIR}/private.pem\n";

        $this->assertSame(
            ['value' => $expectedValue, 'value Dotenv reads once written' => $expectedValue],
            [
                'value' => $companion->resolveDistValue(new Variable('KEY_PATH', '${KEY_DIR}/private.pem')),
                // @: the notice of Dotenv 4.4.0 and 5.0.0 for the undefined ${KEY_DIR}
                'value Dotenv reads once written' => @(new Dotenv())->parse($writtenEnv)['KEY_PATH'],
            ]
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function envLineDataProvider(): array
    {
        return [
            'variable not in .env: appended' => ["KEY_DIR=custom\n", 'custom/private.pem'],
            'empty line below the variable it refers to' => ["KEY_DIR=custom\nKEY_PATH=\n", 'custom/private.pem'],
            'empty line above the variable it refers to' => ["KEY_PATH=\nKEY_DIR=custom\n", '/private.pem'],
        ];
    }

    public function testGetDefinedVariablesHashParsesEachEnvContentOnce(): void
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'companienv-');
        $fileSystem = new InMemoryFileSystem();
        $fileSystem->write('.env.dist', "KEY_DIR=keys\nKEY_PATH=\${KEY_DIR}/private.pem\n");
        $fileSystem->write('.env', "RUN=\"\$(echo x >> {$log})\"\nKEY_DIR=first\n");
        $companion = new Companion($fileSystem, new InMemoryInteraction(), new Chained());
        $keyPath = new Variable('KEY_PATH', '${KEY_DIR}/private.pem');

        try {
            $companion->getDefinedVariablesHash();
            $before = [$companion->resolveDistValue($keyPath), $companion->getDefinedVariablesHash()];
            $runsBefore = substr_count((string) file_get_contents($log), 'x');
            $fileSystem->write('.env', "RUN=\"\$(echo x >> {$log})\"\nKEY_DIR=second\n");
            $after = [$companion->resolveDistValue($keyPath), $companion->getDefinedVariablesHash()];
            $runsAfter = substr_count((string) file_get_contents($log), 'x');
        } finally {
            unlink($log);
        }

        $this->assertSame(
            [
                'before' => ['first/private.pem', ['RUN' => '', 'KEY_DIR' => 'first']],
                'runs before' => 1,
                'after a change' => ['second/private.pem', ['RUN' => '', 'KEY_DIR' => 'second']],
                'runs after a change' => 2,
            ],
            [
                'before' => $before,
                'runs before' => $runsBefore,
                'after a change' => $after,
                'runs after a change' => $runsAfter,
            ]
        );
    }
}

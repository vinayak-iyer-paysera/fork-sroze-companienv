<?php

declare(strict_types=1);

namespace Companienv\DotEnv;

use Companienv\IO\InMemoryFileSystem;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ParserTest extends TestCase
{
    /**
     * @dataProvider distFileDataProvider
     */
    public function testParse(string $contents, File $expected): void
    {
        $this->assertEquals($expected, $this->parse($contents));
    }

    /**
     * @return array<string, array{0: string, 1: File}>
     */
    public static function distFileDataProvider(): array
    {
        return [
            'block title' => [
                "## Something\nMY_VARIABLE=default-value\n",
                new File('', [
                    new Block('Something', '', [new Variable('MY_VARIABLE', 'default-value')]),
                ]),
            ],
            'one-line description' => [
                "## Something\n# With more details\nMY_VARIABLE=default-value\n",
                new File('', [
                    new Block('Something', 'With more details', [new Variable('MY_VARIABLE', 'default-value')]),
                ]),
            ],
            'several-line description' => [
                "## Something\n# With more details\n# and even with extra lines\nMY_VARIABLE=default-value\n",
                new File('', [
                    new Block('Something', 'With more details and even with extra lines', [
                        new Variable('MY_VARIABLE', 'default-value'),
                    ]),
                ]),
            ],
            'ignored comment' => [
                "## Something\n#~ Not shown to the user\nMY_VARIABLE=default-value\n",
                new File('', [
                    new Block('Something', '', [new Variable('MY_VARIABLE', 'default-value')]),
                ]),
            ],
            'commented variable' => [
                "## Something\n#A_HIDDEN_VARIABLE=it-was-useful\nMY_VARIABLE=default-value\n",
                new File('', [
                    new Block('Something', '', [new Variable('MY_VARIABLE', 'default-value')]),
                ]),
            ],
            'attributes' => [
                "## Keys\n#+only-if(KEY_PATH KEY_PASS):(USE_KEYS=true)\n#+rsa-pair(KEY_PATH PUB_PATH KEY_PASS)\n"
                . "KEY_PATH=key.pem\nPUB_PATH=pub.pem\nKEY_PASS=\n",
                new File('', [
                    new Block(
                        'Keys',
                        '',
                        [
                            new Variable('KEY_PATH', 'key.pem'),
                            new Variable('PUB_PATH', 'pub.pem'),
                            new Variable('KEY_PASS', ''),
                        ],
                        [
                            new Attribute('only-if', ['KEY_PATH', 'KEY_PASS'], ['USE_KEYS' => 'true']),
                            new Attribute('rsa-pair', ['KEY_PATH', 'PUB_PATH', 'KEY_PASS'], []),
                        ]
                    ),
                ]),
            ],
            'Symfony recipe blocks' => [
                "# This file is a \"template\" of which env vars need to be defined\n\n"
                . "###> symfony/framework-bundle ###\nAPP_ENV=dev\n#TRUSTED_PROXIES=127.0.0.1\n"
                . "###< symfony/framework-bundle ###\n\n"
                . "###> sroze/enqueue-bridge ###\nENQUEUE_DSN=something\n###< sroze/enqueue-bridge ###\n",
                new File('', [
                    new Block('> symfony/framework-bundle', '', [new Variable('APP_ENV', 'dev')]),
                    new Block('< symfony/framework-bundle'),
                    new Block('> sroze/enqueue-bridge', '', [new Variable('ENQUEUE_DSN', 'something')]),
                    new Block('< sroze/enqueue-bridge'),
                ]),
            ],
            'variables before any block' => [
                "  MY_VARIABLE=default-value  \n\nOTHER_VARIABLE=\n",
                new File('', [
                    new Block('', '', [
                        new Variable('MY_VARIABLE', 'default-value'),
                        new Variable('OTHER_VARIABLE', ''),
                    ]),
                ]),
            ],
            'value containing equals signs' => [
                "A_BASE64_VALUE=abc123==\n",
                new File('', [
                    new Block('', '', [new Variable('A_BASE64_VALUE', 'abc123==')]),
                ]),
            ],
        ];
    }

    /**
     * @dataProvider malformedDistFileDataProvider
     */
    public function testParseRejectsMalformedFile(string $contents, string $exceptionClass, string $message): void
    {
        $this->expectException($exceptionClass);
        $this->expectExceptionMessage($message);

        $this->parse($contents);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function malformedDistFileDataProvider(): array
    {
        return [
            'line that is neither a comment nor a variable' => [
                "## Something\nNOT_A_VARIABLE\n",
                InvalidArgumentException::class,
                'of the file .env.dist is invalid: NOT_A_VARIABLE',
            ],
            'attribute that does not parse' => [
                "## Something\n#+only-if(lowercase)\nMY_VARIABLE=value\n",
                RuntimeException::class,
                'Unable to parse the given attribute: only-if(lowercase)',
            ],
            'attribute mapping with a trailing space' => [
                "## Something\n#+only-if(MY_VARIABLE):(OTHER=value )\nMY_VARIABLE=value\n",
                RuntimeException::class,
                'Could not parse attribute mapping "OTHER=value "',
            ],
        ];
    }

    private function parse(string $contents): File
    {
        $fileSystem = new InMemoryFileSystem();
        $fileSystem->write('.env.dist', $contents);

        return (new Parser())->parse($fileSystem, '.env.dist');
    }
}

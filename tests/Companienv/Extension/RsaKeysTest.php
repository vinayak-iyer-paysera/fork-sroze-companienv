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
    private const SHORT_PASS_PHRASE_QUESTION = 'The pass phrase must have at least 4 characters. '
        . self::PASS_PHRASE_QUESTION;

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
    public function testNothingGenerated(Block $block, array $answers, array $variableNames, string $expectedQuestions): void
    {
        $interaction = new InMemoryInteraction($answers);
        $companion = $this->companion($interaction);
        $extension = new RsaKeys();

        $values = [];
        foreach ($variableNames as $name) {
            $values[$name] = $extension->getVariableValue($companion, $block, $block->getVariable($name));
        }

        $this->assertSame(
            ['values' => array_fill_keys($variableNames, null), 'questions' => $expectedQuestions, 'files' => ['.env.dist']],
            ['values' => $values, 'questions' => $interaction->getBuffer(), 'files' => array_keys($this->readTemporaryDirectory())]
        );
    }

    /**
     * @return array<string, array{0: Block, 1: array<string, string>, 2: list<string>, 3: string}>
     */
    public static function nothingGeneratedDataProvider(): array
    {
        return [
            'no rsa-pair attribute' => [new Block('Keys', '', self::variables()), [], ['KEY_PATH'], ''],
            'generation declined' => [self::block(), [self::CONFIRMATION => ''], ['KEY_PATH'], self::CONFIRMATION . "\n"],
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
            'pass phrase of 4 characters' => [
                [self::PASS_PHRASE_QUESTION => 'abcd'],
                'abcd',
                self::PASS_PHRASE_QUESTION . "\n",
            ],
            'pass phrases shorter than 4 characters: asked again until one is long enough' => [
                [self::PASS_PHRASE_QUESTION => 'abc', self::SHORT_PASS_PHRASE_QUESTION => ['ab', 'abcd']],
                'abcd',
                self::PASS_PHRASE_QUESTION . "\n" . str_repeat(self::SHORT_PASS_PHRASE_QUESTION . "\n", 2),
            ],
        ];
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
        $interaction = new InMemoryInteraction([self::CONFIRMATION => 'y', self::PASS_PHRASE_QUESTION => 'secret-phrase']);

        $messages = [];
        try {
            (new RsaKeys())->getVariableValue($this->companion($interaction), $block, $variables[0]);
        } catch (RuntimeException $exception) {
            for ($error = $exception; $error !== null; $error = $error->getPrevious()) {
                $messages[] = $error->getMessage();
            }
        }

        $this->assertSame(
            ['first message' => 'Could not have generated the RSA public/private key', 'errors' => 2, 'pass phrase in a message' => false],
            [
                'first message' => $messages[0] ?? null,
                'errors' => count($messages),
                'pass phrase in a message' => preg_grep('/secret-phrase/', $messages) !== [],
            ]
        );
    }

    /**
     * @dataProvider existingFilesDataProvider
     *
     * @param list<string> $existingFiles
     */
    public function testIsVariableRequiringValue(Block $block, array $existingFiles, int $expected): void
    {
        foreach ($existingFiles as $name) {
            touch($this->temporaryDirectory . '/' . $name);
        }

        $this->assertSame(
            $expected,
            (new RsaKeys())->isVariableRequiringValue($this->companion(new InMemoryInteraction()), $block, new Variable('KEY_PATH', 'private.pem'))
        );
    }

    /**
     * @return array<string, array{0: Block, 1: list<string>, 2: int}>
     */
    public static function existingFilesDataProvider(): array
    {
        return [
            'no rsa-pair attribute' => [new Block('Keys', '', self::variables()), [], Extension::ABSTAIN],
            'no key file' => [self::block(), [], Extension::VARIABLE_REQUIRED],
            'only the private key' => [self::block(), ['private.pem'], Extension::VARIABLE_REQUIRED],
            'only the public key' => [self::block(), ['public.pem'], Extension::VARIABLE_REQUIRED],
            'both key files' => [self::block(), ['private.pem', 'public.pem'], Extension::ABSTAIN],
        ];
    }

    /**
     * @return list<Variable>
     */
    private static function variables(): array
    {
        return [new Variable('KEY_PATH', 'private.pem'), new Variable('PUB_PATH', 'public.pem'), new Variable('KEY_PASS', '')];
    }

    private static function block(): Block
    {
        return new Block('Keys', '', self::variables(), [new Attribute('rsa-pair', ['KEY_PATH', 'PUB_PATH', 'KEY_PASS'], [])]);
    }

    private function companion(InMemoryInteraction $interaction): Companion
    {
        return new Companion(new NativePhpFileSystem($this->temporaryDirectory), $interaction, new Chained());
    }
}

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

final class SslCertificateTest extends TestCase
{
    use TemporaryDirectory;

    private const CONFIRMATION = 'Variables CERT_KEY_PATH and CERT_PATH and CERT_DOMAIN represents an SSL certificate. '
        . 'Do you want to automatically generate them? (y)';
    private const DOMAIN_QUESTION = 'Enter the domain name for which to generate the self-signed SSL certificate:';

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
        $extension = new SslCertificate();

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
            'no ssl-certificate attribute' => [
                new Block('Certificate', '', self::variables()),
                [],
                ['CERT_KEY_PATH'],
                '',
            ],
            'generation declined' => [
                self::block(),
                [self::CONFIRMATION => ''],
                ['CERT_KEY_PATH'],
                self::CONFIRMATION . "\n",
            ],
            'generation declined, then the rest of the pair' => [
                self::block(),
                [self::CONFIRMATION => ''],
                ['CERT_KEY_PATH', 'CERT_PATH', 'CERT_DOMAIN'],
                self::CONFIRMATION . "\n",
            ],
        ];
    }

    /**
     * @dataProvider domainDataProvider
     */
    public function testGeneratedCertificate(string $domain): void
    {
        $interaction = new InMemoryInteraction([self::CONFIRMATION => 'y', self::DOMAIN_QUESTION => $domain]);
        $companion = $this->companion($interaction);
        $extension = new SslCertificate();

        $values = [];
        foreach (self::variables() as $variable) {
            $values[$variable->getName()] = $extension->getVariableValue($companion, self::block(), $variable);
        }

        $certificate = openssl_x509_parse(file_get_contents($this->temporaryDirectory . '/certificate.pem'));
        $key = openssl_pkey_get_private(file_get_contents($this->temporaryDirectory . '/key.pem'));
        $this->assertSame(
            [
                'values' => ['CERT_KEY_PATH' => 'key.pem', 'CERT_PATH' => 'certificate.pem', 'CERT_DOMAIN' => $domain],
                'questions' => self::CONFIRMATION . "\n" . self::DOMAIN_QUESTION . "\n",
                'files' => ['.env.dist', 'certificate.pem', 'key.pem'],
                'subject' => [
                    'C' => 'SS',
                    'ST' => 'SS',
                    'L' => 'SelfSignedCity',
                    'O' => 'SelfSignedOrg',
                    'CN' => $domain,
                ],
                'validity in days' => 3650,
                'key bits' => 2048,
                'key matches' => true,
            ],
            [
                'values' => $values,
                'questions' => $interaction->getBuffer(),
                'files' => array_keys($this->readTemporaryDirectory()),
                'subject' => $certificate['subject'],
                'validity in days' => ($certificate['validTo_time_t'] - $certificate['validFrom_time_t']) / 86400,
                'key bits' => openssl_pkey_get_details($key)['bits'],
                'key matches' => openssl_x509_check_private_key(
                    file_get_contents($this->temporaryDirectory . '/certificate.pem'),
                    $key
                ),
            ]
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function domainDataProvider(): array
    {
        return [
            'plain domain' => ['localhost'],
            'domain with spaces and quotes' => ['my "local" site\'s name'],
            'domain with a slash' => ['example.com/O=Other'],
            'domain with a plus' => ['a+b'],
            'domain with a backslash' => ['back\\slash'],
        ];
    }

    public function testGenerationFailure(): void
    {
        $variables = [
            new Variable('CERT_KEY_PATH', 'missing/key.pem'),
            new Variable('CERT_PATH', 'missing/certificate.pem'),
            new Variable('CERT_DOMAIN', ''),
        ];
        $block = new Block('Certificate', '', $variables, [
            new Attribute('ssl-certificate', ['CERT_KEY_PATH', 'CERT_PATH', 'CERT_DOMAIN'], []),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not have generated the SSL certificate: The command');

        (new SslCertificate())->getVariableValue(
            $this->companion(new InMemoryInteraction([
                self::CONFIRMATION => 'y',
                self::DOMAIN_QUESTION => 'localhost',
            ])),
            $block,
            $variables[0]
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
            (new SslCertificate())->isVariableRequiringValue(
                $this->companion(new InMemoryInteraction()),
                $block,
                new Variable('CERT_KEY_PATH', 'key.pem')
            )
        );
    }

    /**
     * @return array<string, array{0: Block, 1: list<string>, 2: int}>
     */
    public static function existingFilesDataProvider(): array
    {
        return [
            'no ssl-certificate attribute' => [new Block('Certificate', '', self::variables()), [], Extension::ABSTAIN],
            'no file' => [self::block(), [], Extension::VARIABLE_REQUIRED],
            'only the key' => [self::block(), ['key.pem'], Extension::VARIABLE_REQUIRED],
            'only the certificate' => [self::block(), ['certificate.pem'], Extension::VARIABLE_REQUIRED],
            'key and certificate' => [self::block(), ['key.pem', 'certificate.pem'], Extension::ABSTAIN],
        ];
    }

    /**
     * @return list<Variable>
     */
    private static function variables(): array
    {
        return [
            new Variable('CERT_KEY_PATH', 'key.pem'),
            new Variable('CERT_PATH', 'certificate.pem'),
            new Variable('CERT_DOMAIN', ''),
        ];
    }

    private static function block(): Block
    {
        return new Block('Certificate', '', self::variables(), [
            new Attribute('ssl-certificate', ['CERT_KEY_PATH', 'CERT_PATH', 'CERT_DOMAIN'], []),
        ]);
    }

    private function companion(InMemoryInteraction $interaction): Companion
    {
        return new Companion(new NativePhpFileSystem($this->temporaryDirectory), $interaction, new Chained());
    }
}

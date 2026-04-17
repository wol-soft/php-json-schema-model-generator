<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Format\FormatValidatorFromRegEx;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class FormatTest
 *
 * Tests that built-in format validators are registered automatically and
 * enforce validation without requiring addFormat() calls.
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class FormatTest extends AbstractPHPModelGeneratorTestCase
{
    public function testUnknownFormatThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/Unsupported format my-custom-unknown-format/');

        $this->generateClassFromFile('UnknownFormat.json');
    }

    public function testUserCanOverrideBuiltinFormat(): void
    {
        // Override the built-in 'date' format with an always-passing validator
        $className = $this->generateClassFromFile(
            'Date.json',
            (new GeneratorConfiguration())->addFormat('date', new FormatValidatorFromRegEx('/.*/'))
                ->setImmutable(false),
        );

        $object = new $className(['value' => 'not-a-date']);
        $this->assertSame('not-a-date', $object->getValue());
    }

    // --- date-time ---

    #[DataProvider('validDateTimeProvider')]
    public function testValidDateTime(string $value): void
    {
        $className = $this->generateClassFromFile(
            'DateTime.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['value' => $value]);
        $this->assertSame($value, $object->getValue());
    }

    public static function validDateTimeProvider(): array
    {
        return [
            'UTC'               => ['2023-06-15T10:30:00Z'],
            'with offset'       => ['2023-06-15T10:30:00+02:00'],
            'fractional seconds' => ['2023-06-15T10:30:00.123Z'],
            'lowercase t and z' => ['2023-06-15t10:30:00z'],
        ];
    }

    #[DataProvider('invalidDateTimeProvider')]
    public function testInvalidDateTime(string $value): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches('/must match the format date-time/');

        $className = $this->generateClassFromFile(
            'DateTime.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        new $className(['value' => $value]);
    }

    public static function invalidDateTimeProvider(): array
    {
        return [
            'date only'      => ['2023-06-15'],
            'no timezone'    => ['2023-06-15T10:30:00'],
            'empty string'   => [''],
        ];
    }

    // --- date ---

    #[DataProvider('validDateProvider')]
    public function testValidDate(string $value): void
    {
        $className = $this->generateClassFromFile(
            'Date.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['value' => $value]);
        $this->assertSame($value, $object->getValue());
    }

    public static function validDateProvider(): array
    {
        return [
            'standard date' => ['2023-06-15'],
            'start of year' => ['2000-01-01'],
        ];
    }

    #[DataProvider('invalidDateProvider')]
    public function testInvalidDate(string $value): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches('/must match the format date/');

        $className = $this->generateClassFromFile(
            'Date.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        new $className(['value' => $value]);
    }

    public static function invalidDateProvider(): array
    {
        return [
            'with time'    => ['2023-06-15T10:30:00Z'],
            'slashes'      => ['2023/06/15'],
            'empty string' => [''],
        ];
    }

    // --- time ---

    #[DataProvider('validTimeProvider')]
    public function testValidTime(string $value): void
    {
        $className = $this->generateClassFromFile(
            'Time.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['value' => $value]);
        $this->assertSame($value, $object->getValue());
    }

    public static function validTimeProvider(): array
    {
        return [
            'UTC'            => ['10:30:00Z'],
            'with offset'    => ['10:30:00+02:00'],
            'fractional'     => ['10:30:00.5Z'],
        ];
    }

    #[DataProvider('invalidTimeProvider')]
    public function testInvalidTime(string $value): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches('/must match the format time/');

        $className = $this->generateClassFromFile(
            'Time.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        new $className(['value' => $value]);
    }

    public static function invalidTimeProvider(): array
    {
        return [
            'no timezone'  => ['10:30:00'],
            'empty string' => [''],
        ];
    }

    // --- email ---

    #[DataProvider('validEmailProvider')]
    public function testValidEmail(string $value): void
    {
        $className = $this->generateClassFromFile(
            'Email.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['value' => $value]);
        $this->assertSame($value, $object->getValue());
    }

    public static function validEmailProvider(): array
    {
        return [
            'simple'         => ['user@example.com'],
            'subdomain'      => ['user@mail.example.com'],
            'plus tag'       => ['user+tag@example.com'],
        ];
    }

    #[DataProvider('invalidEmailProvider')]
    public function testInvalidEmail(string $value): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches('/must match the format email/');

        $className = $this->generateClassFromFile(
            'Email.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        new $className(['value' => $value]);
    }

    public static function invalidEmailProvider(): array
    {
        return [
            'no at sign'   => ['userexample.com'],
            'no domain'    => ['user@'],
            'empty string' => [''],
        ];
    }

    // --- hostname ---

    #[DataProvider('validHostnameProvider')]
    public function testValidHostname(string $value): void
    {
        $className = $this->generateClassFromFile(
            'Hostname.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['value' => $value]);
        $this->assertSame($value, $object->getValue());
    }

    public static function validHostnameProvider(): array
    {
        return [
            'simple'     => ['example'],
            'with dots'  => ['www.example.com'],
            'hyphen'     => ['my-host.example.com'],
        ];
    }

    #[DataProvider('invalidHostnameProvider')]
    public function testInvalidHostname(string $value): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches('/must match the format hostname/');

        $className = $this->generateClassFromFile(
            'Hostname.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        new $className(['value' => $value]);
    }

    public static function invalidHostnameProvider(): array
    {
        return [
            'trailing dot'  => ['example.'],
            'empty string'  => [''],
            'starts hyphen' => ['-example.com'],
        ];
    }

    // --- ipv4 ---

    #[DataProvider('validIpv4Provider')]
    public function testValidIpv4(string $value): void
    {
        $className = $this->generateClassFromFile(
            'Ipv4.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['value' => $value]);
        $this->assertSame($value, $object->getValue());
    }

    public static function validIpv4Provider(): array
    {
        return [
            'loopback'   => ['127.0.0.1'],
            'broadcast'  => ['255.255.255.255'],
            'private'    => ['192.168.1.100'],
        ];
    }

    #[DataProvider('invalidIpv4Provider')]
    public function testInvalidIpv4(string $value): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches('/must match the format ipv4/');

        $className = $this->generateClassFromFile(
            'Ipv4.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        new $className(['value' => $value]);
    }

    public static function invalidIpv4Provider(): array
    {
        return [
            'out of range'  => ['256.0.0.1'],
            'too few parts' => ['192.168.1'],
            'empty string'  => [''],
        ];
    }

    // --- ipv6 ---

    #[DataProvider('validIpv6Provider')]
    public function testValidIpv6(string $value): void
    {
        $className = $this->generateClassFromFile(
            'Ipv6.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['value' => $value]);
        $this->assertSame($value, $object->getValue());
    }

    public static function validIpv6Provider(): array
    {
        return [
            'full'       => ['2001:0db8:85a3:0000:0000:8a2e:0370:7334'],
            'compressed' => ['2001:db8::1'],
            'loopback'   => ['::1'],
        ];
    }

    #[DataProvider('invalidIpv6Provider')]
    public function testInvalidIpv6(string $value): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches('/must match the format ipv6/');

        $className = $this->generateClassFromFile(
            'Ipv6.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        new $className(['value' => $value]);
    }

    public static function invalidIpv6Provider(): array
    {
        return [
            'ipv4 address'    => ['192.168.1.1'],
            'too many groups' => ['2001:db8:85a3:0:0:8a2e:370:7334:extra'],
            'empty string'    => [''],
        ];
    }

    // --- uri ---

    #[DataProvider('validUriProvider')]
    public function testValidUri(string $value): void
    {
        $className = $this->generateClassFromFile(
            'Uri.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['value' => $value]);
        $this->assertSame($value, $object->getValue());
    }

    public static function validUriProvider(): array
    {
        return [
            'http'      => ['http://example.com'],
            'https'     => ['https://example.com/path?q=1#frag'],
            'with port' => ['http://example.com:8080/path'],
        ];
    }

    #[DataProvider('invalidUriProvider')]
    public function testInvalidUri(string $value): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches('/must match the format uri/');

        $className = $this->generateClassFromFile(
            'Uri.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        new $className(['value' => $value]);
    }

    public static function invalidUriProvider(): array
    {
        return [
            'relative path' => ['/relative/path'],
            'no scheme'     => ['example.com'],
            'empty string'  => [''],
        ];
    }

    // --- json-pointer ---

    #[DataProvider('validJsonPointerProvider')]
    public function testValidJsonPointer(string $value): void
    {
        $className = $this->generateClassFromFile(
            'JsonPointer.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['value' => $value]);
        $this->assertSame($value, $object->getValue());
    }

    public static function validJsonPointerProvider(): array
    {
        return [
            'root'         => [''],
            'single token' => ['/foo'],
            'nested'       => ['/foo/bar/baz'],
            'tilde escape' => ['/a~0b'],
            'slash escape' => ['/a~1b'],
        ];
    }

    #[DataProvider('invalidJsonPointerProvider')]
    public function testInvalidJsonPointer(string $value): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches('/must match the format json-pointer/');

        $className = $this->generateClassFromFile(
            'JsonPointer.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        new $className(['value' => $value]);
    }

    public static function invalidJsonPointerProvider(): array
    {
        return [
            'no leading slash' => ['foo'],
            'invalid tilde'    => ['/a~2b'],
        ];
    }

    // --- regex ---

    #[DataProvider('validRegexProvider')]
    public function testValidRegex(string $value): void
    {
        $className = $this->generateClassFromFile(
            'Regex.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['value' => $value]);
        $this->assertSame($value, $object->getValue());
    }

    public static function validRegexProvider(): array
    {
        return [
            'simple pattern' => ['/^\d+$/'],
            'anchored'       => ['/^[a-z]+$/i'],
            'empty pattern'  => ['//'],
        ];
    }

    #[DataProvider('invalidRegexProvider')]
    public function testInvalidRegex(string $value): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches('/must match the format regex/');

        $className = $this->generateClassFromFile(
            'Regex.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        new $className(['value' => $value]);
    }

    public static function invalidRegexProvider(): array
    {
        return [
            'unclosed bracket' => ['/ab[c/'],
            'unclosed group'   => ['/ab(c/'],
        ];
    }
}

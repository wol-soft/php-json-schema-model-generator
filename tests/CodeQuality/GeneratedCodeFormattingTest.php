<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\CodeQuality;

use DateTime;
use PHPModelGenerator\Filter\FilterInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\Hook\SetterBeforeValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\AdditionalPropertiesAccessorPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\BuilderClassPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;

/**
 * Runs the dedicated generated-code PHPCS ruleset (tests/generated_code_phpcs.xml) against classes generated
 * from schemas exercising allOf composition, pattern/additional properties, array tuples/items/contains,
 * if/then/else composition, propertyNames, schema dependencies and builder classes, to guard the formatting of
 * the generated PHP code itself.
 */
class GeneratedCodeFormattingTest extends AbstractPHPModelGeneratorTestCase
{
    public function testComposedPropertyGeneratesCodeMatchingTheCodingStandard(): void
    {
        $this->generateClassFromFile(
            'ComposedVehicle.json',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('\\GeneratedCodeFormattingTest')
                ->setImmutable(false)
                ->setCollectErrors(true),
        );

        $report = $this->runPhpcs($this->getGeneratedFiles());

        $this->assertSame([], $this->collectMessages($report), $this->formatReport($report));
    }

    /**
     * Generating without a namespace prefix is an intentionally supported configuration (setNamespacePrefix() is
     * optional), which tests/generated_code_phpcs.xml accounts for by excluding
     * PSR1.Classes.ClassDeclaration.MissingNamespace. Exercise that case explicitly so the exclusion is proven
     * necessary and correct, rather than trusting it was added for the right reason.
     */
    public function testComposedPropertyWithoutNamespaceGeneratesCodeMatchingTheCodingStandard(): void
    {
        $this->generateClassFromFile(
            'ComposedVehicle.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors(true),
        );

        $report = $this->runPhpcs($this->getGeneratedFiles());

        $this->assertSame([], $this->collectMessages($report), $this->formatReport($report));
    }

    /**
     * Covers the indentLevel wiring of PatternProperties, AdditionalProperties, ArrayTuple, ArrayItem,
     * ArrayContains, ConditionalComposedItem (if/then/else), PropertyNames, SchemaDependency, a forbidden
     * (patternProperties: false) pattern and a writeOnly property's serialization-exclusion hook in one
     * generation pass, since each construct is an independent top-level schema keyword/property that doesn't
     * interact with the others. collectErrors(true) matches the branch actually verified for SchemaDependency -
     * its generated check has a structurally different (and separately tracked, still-imperfect) shape under
     * collectErrors(false), which AbstractPHPModelGeneratorTestCase::generateClass() defaults to when no
     * configuration is passed explicitly. setSerialization(true) is required for the writeOnly exclusion hook
     * to be generated at all.
     */
    public function testComprehensivePropertyTypesGenerateCodeMatchingTheCodingStandard(): void
    {
        $this->generateClassFromFile(
            'ComprehensiveFormatting.json',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('\\GeneratedCodeFormattingTest')
                ->setImmutable(false)
                ->setCollectErrors(true)
                ->setSerialization(true),
        );

        $report = $this->runPhpcs($this->getGeneratedFiles());

        $this->assertSame([], $this->collectMessages($report), $this->formatReport($report));
    }

    /**
     * A filter chain where a filter runs after a transforming filter (here: dateTime followed by a custom
     * stripTime filter) renders Filter.phptpl with skipTransformedValuesCheck populated ("!$transformationFailed
     * &&" prefixing the check), a branch the other tests never exercise since their filters aren't chained after
     * a transforming filter. That branch previously left a stray blank line ahead of the check whenever
     * skipTransformedValuesCheck was empty (the dateTime filter itself, first in the chain) - both states of the
     * same template need coverage since the fix could regress either independently.
     */
    public function testFilterChainGeneratesCodeMatchingTheCodingStandard(): void
    {
        $this->generateClassFromFile(
            'FilterChain.json',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('\\GeneratedCodeFormattingTest')
                ->addFilter($this->getStripTimeFilter()),
        );

        $report = $this->runPhpcs($this->getGeneratedFiles());

        $this->assertSame([], $this->collectMessages($report), $this->formatReport($report));
    }

    private function getStripTimeFilter(): FilterInterface
    {
        // getFilter() is embedded verbatim as a literal class reference in the generated code, so it must name a
        // real, non-anonymous class - self::class inside the anonymous FilterInterface wrapper below would
        // resolve to the anonymous class itself, producing an unreferenceable "class@anonymous..." name.
        return new class implements FilterInterface {
            public function getToken(): string
            {
                return 'stripTime';
            }

            public function getFilter(): array
            {
                return [GeneratedCodeFormattingTest::class, 'stripTimeFilter'];
            }
        };
    }

    public static function stripTimeFilter(?DateTime $value): ?DateTime
    {
        return $value?->setTime(0, 0);
    }

    /**
     * AdditionalPropertiesAccessorPostProcessor's additionalProperties() method is rendered through
     * RenderedMethod, which - unlike most other rendering paths - enables php-micro-template's block dedent
     * (blockIndentWidth 4), stripping one level of leading whitespace from a standalone {% if %}'s body before
     * it is embedded. AdditionalPropertiesAccessorMethod.phptpl previously wrote the "not immutable" branch's
     * body at the same column as the {% if %} tag itself instead of one level deeper as that convention
     * requires, so the dedent stripped real indentation instead of a no-op decorative level, under-indenting
     * the setter/remover arguments by one level. This only surfaces with setImmutable(false), since the whole
     * branch is empty otherwise.
     */
    public function testAdditionalPropertiesAccessorGeneratesCodeMatchingTheCodingStandard(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor());
        };

        $this->generateClassFromFile(
            'ComprehensiveFormatting.json',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('\\GeneratedCodeFormattingTest')
                ->setImmutable(false)
                ->setCollectErrors(true),
        );

        $report = $this->runPhpcs($this->getGeneratedFiles());

        $this->assertSame([], $this->collectMessages($report), $this->formatReport($report));
    }

    /**
     * When two or more properties both match a patternProperties pattern, PatternPropertiesPostProcessor emits a
     * constructor hook with one "$this->_patternProperties[...][...] = &$this->...;" line per matching property.
     * Model.phptpl previously interpolated that hook's code with a raw {{ }} (no indent() wrapping), so only the
     * hook's first line inherited the template's ambient indentation - every subsequent line rendered at column
     * 0, since the hook itself concatenates its lines with no per-line indentation of its own. A single matching
     * property never exercised this, since the hook body was only ever one line.
     */
    public function testPatternPropertyMatchingObjectPropertyGeneratesCodeMatchingTheCodingStandard(): void
    {
        $this->generateClassFromFile(
            'PatternPropertyMatchingObjectProperty.json',
            (new GeneratorConfiguration())->setNamespacePrefix('\\GeneratedCodeFormattingTest'),
        );

        $report = $this->runPhpcs($this->getGeneratedFiles());

        $this->assertSame([], $this->collectMessages($report), $this->formatReport($report));
    }

    /**
     * ComprehensiveFormatting.json's patternProperties always registers an internal
     * SetterBeforeValidationHookInterface hook (ExtendObjectPropertiesMatchingPatternPropertiesPostProcessor)
     * that returns '' for every property not matching a pattern - which is every property in this schema.
     * SchemaHookResolver::resolveHook() previously joined all hooks' code with "\n\n" unconditionally, so mixing
     * that empty hook with a second, real hook (registered below) left a stray "\n\n" in the joined result,
     * pushing the real hook's code onto its own line at column 0 regardless of the embedding template's ambient
     * indentation - a single-hook schema never exercised this, since join() of one element is a no-op.
     */
    public function testMultipleSetterHooksWithAnEmptyHookGenerateCodeMatchingTheCodingStandard(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new class () extends PostProcessor {
                public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
                {
                    $schema->addSchemaHook(new class () implements SetterBeforeValidationHookInterface {
                        public function getCode(PropertyInterface $property, bool $batchUpdate = false): string
                        {
                            return '// setter hook';
                        }
                    });
                }
            });
        };

        $this->generateClassFromFile(
            'ComprehensiveFormatting.json',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('\\GeneratedCodeFormattingTest')
                ->setImmutable(false)
                ->setCollectErrors(true)
                ->setSerialization(true),
        );

        $report = $this->runPhpcs($this->getGeneratedFiles());

        $this->assertSame([], $this->collectMessages($report), $this->formatReport($report));
    }

    public function testBuilderClassGeneratesCodeMatchingTheCodingStandard(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new BuilderClassPostProcessor());
        };

        $this->generateClassFromFile(
            'ComposedVehicle.json',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('\\GeneratedCodeFormattingTest')
                ->setCollectErrors(true),
        );

        // BuilderClassPostProcessor writes its Builder class directly to disk next to the main class file -
        // it never registers with ModelGenerator::generateModels(), so getGeneratedFiles() alone would miss it
        $builderFiles = array_map(
            static fn(string $file): string => str_replace('.php', 'Builder.php', $file),
            $this->getGeneratedFiles(),
        );

        $report = $this->runPhpcs([...$this->getGeneratedFiles(), ...$builderFiles]);

        $this->assertSame([], $this->collectMessages($report), $this->formatReport($report));
    }

    /**
     * @param string[] $files
     */
    private function runPhpcs(array $files): array
    {
        $strippedFiles = array_map($this->stripIgnoreFileMarker(...), $files);

        // phpcs writes its "Time: ...; Memory: ..." summary to stderr even for machine-readable reports, so
        // stderr must stay separate from stdout - merging it would corrupt the JSON report on stdout
        $command = sprintf(
            '%s --standard=%s --report=json %s',
            escapeshellarg(__DIR__ . '/../../vendor/bin/phpcs'),
            escapeshellarg(__DIR__ . '/../generated_code_phpcs.xml'),
            implode(' ', array_map('escapeshellarg', $strippedFiles)),
        );

        $output = shell_exec($command);
        $report = json_decode($output ?? '', true);

        $this->assertIsArray($report, "phpcs didn't return a valid JSON report:\n" . $output);

        return $report;
    }

    /**
     * Generated classes carry "// @codingStandardsIgnoreFile", which makes phpcs skip the file entirely. Strip
     * it from a throwaway copy so the coding standard actually gets applied for this test, without touching the
     * original file the rest of the test run still relies on (it has already been require()d by the generator).
     */
    private function stripIgnoreFileMarker(string $file): string
    {
        // keep the .php extension so phpcs' default extension filter still picks the copy up
        $strippedFile = preg_replace('/\.php$/', '.phpcs-check.php', $file);

        file_put_contents(
            $strippedFile,
            preg_replace('/^\/\/ @codingStandardsIgnoreFile\n/m', '', file_get_contents($file)),
        );

        return $strippedFile;
    }

    /**
     * @return array{type: string, source: string, line: int, message: string}[]
     */
    private function collectMessages(array $report): array
    {
        $messages = [];

        foreach ($report['files'] as $file => $fileReport) {
            foreach ($fileReport['messages'] as $message) {
                $messages[] = [
                    'file' => $file,
                    'line' => $message['line'],
                    'type' => $message['type'],
                    'source' => $message['source'],
                    'message' => $message['message'],
                ];
            }
        }

        return $messages;
    }

    private function formatReport(array $report): string
    {
        $lines = ["phpcs found {$report['totals']['errors']} error(s) and {$report['totals']['warnings']} warning(s):"];

        foreach ($this->collectMessages($report) as $message) {
            $lines[] = sprintf(
                '%s:%d [%s] %s (%s)',
                basename($message['file']),
                $message['line'],
                $message['type'],
                $message['message'],
                $message['source'],
            );
        }

        return implode("\n", $lines);
    }
}

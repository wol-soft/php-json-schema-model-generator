<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;

class ClassNameGeneratorTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * All six named naming levels are exercised in a single generation run:
     *
     *  1. title      — "labeled" definition carries title "PostalAddress"; title wins.
     *  2. $id        — not exercised here (covered by existing object tests).
     *  3. $anchor    — "anchored" carries $anchor "AnchoredContent"; wins over pointer fallbacks.
     *  4. Definition — "address" and "contact_info" have no title/$id/$anchor; definition key used.
     *  5. Inline     — "location" is an inline object under "properties"; key used, no hash suffix.
     *  6. Array item — "tags" items schema sits at /properties/tags/items; "tags" + "Item" suffix.
     */
    public function testNamingLevels(): void
    {
        $this->generateDirectory(
            'NamingLevels',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('ClassNameGeneratorTest')
                ->setOutputEnabled(false),
        );

        $rootFqcn        = 'ClassNameGeneratorTest\NamingLevels';
        $addressFqcn     = 'ClassNameGeneratorTest\NamingLevels_Address';
        $postalFqcn      = 'ClassNameGeneratorTest\NamingLevels_PostalAddress';
        $contactInfoFqcn = 'ClassNameGeneratorTest\NamingLevels_Contact_Info';
        $anchoredFqcn    = 'ClassNameGeneratorTest\NamingLevels_AnchoredContent';
        $locationFqcn    = 'ClassNameGeneratorTest\NamingLevels_Location';
        $tagItemFqcn     = 'ClassNameGeneratorTest\NamingLevels_TagsItem';

        $object = new $rootFqcn([
            'home'     => ['street' => 'Main St'],
            'office'   => ['street' => 'Work Rd'],
            'postal'   => ['zip' => '12345'],
            'contact'  => ['email' => 'test@example.com'],
            'anchored' => ['text' => 'hello'],
            'location' => ['city' => 'Berlin'],
            'tags'     => [['id' => 1, 'name' => 'php']],
        ]);

        // Level 4: definition key "address" used when no title/$id/$anchor
        $this->assertSame($addressFqcn, $object->getHome()::class);

        // Two properties referencing the same definition resolve to the same class
        $this->assertSame($object->getHome()::class, $object->getOffice()::class);

        // Level 1: title "PostalAddress" wins over the definition key "labeled"
        $this->assertSame($postalFqcn, $object->getPostal()::class);

        // Level 4: snake_case definition key "contact_info" preserved as Contact_Info
        $this->assertSame($contactInfoFqcn, $object->getContact()::class);

        // Level 3: $anchor "AnchoredContent" wins over the inline-property fallback
        $this->assertSame($anchoredFqcn, $object->getAnchored()::class);

        // Level 5: inline property key used without a hash suffix
        $this->assertSame($locationFqcn, $object->getLocation()::class);

        // Level 6: array property name "tags" + "Item" suffix, no hash
        $tags = $object->getTags();
        $this->assertSame($tagItemFqcn, $tags[0]::class);

        // None of the named levels should produce a hex content hash
        foreach ([$addressFqcn, $postalFqcn, $contactInfoFqcn, $anchoredFqcn, $locationFqcn, $tagItemFqcn] as $fqcn) {
            $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{13}/', $fqcn);
        }

        // Functional assertions
        $this->assertSame('Main St', $object->getHome()->getStreet());
        $this->assertSame('Work Rd', $object->getOffice()->getStreet());
        $this->assertSame('12345', $object->getPostal()->getZip());
        $this->assertSame('test@example.com', $object->getContact()->getEmail());
        $this->assertSame('hello', $object->getAnchored()->getText());
        $this->assertSame('Berlin', $object->getLocation()->getCity());
        $this->assertSame(1, $tags[0]->getId());
        $this->assertSame('php', $tags[0]->getName());
    }
}

<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Field\FieldSupport;
use Grafida\Field\MediaFieldValue;

/**
 * A `media` custom field's value is an `accessiblemedia` record, not a string.
 * These pin the three stored shapes it may arrive in and the one shape it is
 * ever written back as.
 */
final class MediaFieldValueTest extends TestCase
{
    public function testMediaIsASupportedFieldType(): void
    {
        self::assertTrue((new FieldSupport())->isSupported('media'));
    }

    public function testDecodesTheJsonRecordJoomlaStores(): void
    {
        $value = MediaFieldValue::decode('{"imagefile":"images/cat.jpg","alt_text":"A cat","alt_empty":"1"}');

        self::assertSame(
            ['imagefile' => 'images/cat.jpg', 'alt_text' => 'A cat', 'alt_empty' => '1'],
            $value
        );
    }

    public function testDecodesAnAlreadyDecodedRecord(): void
    {
        $value = MediaFieldValue::decode(['imagefile' => 'images/cat.jpg', 'alt_text' => 'A cat']);

        self::assertSame(
            ['imagefile' => 'images/cat.jpg', 'alt_text' => 'A cat', 'alt_empty' => ''],
            $value
        );
    }

    /**
     * A field created in Joomla 3 holds the file name and nothing else;
     * `plg_fields_media::checkValue()` still carries that fallback, so dropping
     * it here would silently blank such a field on the next publish.
     */
    public function testDecodesALegacyBarePath(): void
    {
        $value = MediaFieldValue::decode('images/legacy.jpg');

        self::assertSame(
            ['imagefile' => 'images/legacy.jpg', 'alt_text' => '', 'alt_empty' => ''],
            $value
        );
    }

    public function testDecodesEmptyAndUnusableValuesToAnEmptyRecord(): void
    {
        $empty = ['imagefile' => '', 'alt_text' => '', 'alt_empty' => ''];

        self::assertSame($empty, MediaFieldValue::decode(''));
        self::assertSame($empty, MediaFieldValue::decode('   '));
        self::assertSame($empty, MediaFieldValue::decode(null));
        self::assertSame($empty, MediaFieldValue::decode(false));
    }

    /**
     * A partial record is not a partial save but *no* save:
     * `AccessiblemediaField::setup()` fails on an object missing `imagefile` or
     * `alt_text`, and `Form::filter()` drops a field whose setup failed.
     */
    public function testEncodeAlwaysWritesTheWholeRecordInAFixedOrder(): void
    {
        self::assertSame(
            '{"imagefile":"images/cat.jpg","alt_text":"","alt_empty":""}',
            MediaFieldValue::encode(['imagefile' => 'images/cat.jpg'])
        );
    }

    /** Unescaped slashes, so PHP and the SPA's JSON.stringify() agree byte for byte. */
    public function testEncodeLeavesSlashesAndUnicodeAlone(): void
    {
        self::assertSame(
            '{"imagefile":"images/γάτα.jpg","alt_text":"Μια γάτα","alt_empty":""}',
            MediaFieldValue::encode(['imagefile' => 'images/γάτα.jpg', 'alt_text' => 'Μια γάτα'])
        );
    }

    /**
     * `plg_system_fields` reads a zero-length value as "remove this field's
     * value", which is what clearing the picture has to mean — alt text on its
     * own describes nothing.
     */
    public function testEncodeCollapsesAPictureLessRecordToTheEmptyString(): void
    {
        self::assertSame('', MediaFieldValue::encode(['imagefile' => '', 'alt_text' => 'orphan']));
        self::assertSame('', MediaFieldValue::encode([]));
    }

    public function testEncodeNormalisesTheDecorativeFlag(): void
    {
        self::assertStringContainsString(
            '"alt_empty":"1"',
            MediaFieldValue::encode(['imagefile' => 'a.jpg', 'alt_empty' => true])
        );
        self::assertStringContainsString(
            '"alt_empty":""',
            MediaFieldValue::encode(['imagefile' => 'a.jpg', 'alt_empty' => '0'])
        );
    }

    public function testDecodeAndEncodeRoundTrip(): void
    {
        $json = '{"imagefile":"images/cat.jpg","alt_text":"A cat","alt_empty":"1"}';

        self::assertSame($json, MediaFieldValue::encode(MediaFieldValue::decode($json)));
    }
}

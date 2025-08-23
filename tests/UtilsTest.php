<?php


namespace tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Utils;

/**
 * Contains tests for the utility class.
 */
#[CoversClass(Utils::class)]
class UtilsTest extends TestCase
{
    // Used in (lack of) truncation test.
    private const LOREM_IPSUM = "Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Proin tortor purus platea sit eu id nisi litora libero. Neque vulputate consequat ac amet augue blandit maximus aliquet congue. Pharetra vestibulum posuere ornare faucibus fusce dictumst orci aenean eu facilisis ut volutpat commodo senectus purus himenaeos fames primis convallis nisi.";

    /**
     * Test output from generateSlug() method.
     * @return void
     */
    public function testGenerateSlug(): void
    {
        // Convert to lowercase and replace spaces.
        $this->assertEquals('hello-world', Utils::generateSlug('Hello World'));

        // Replace multiple spaces.
        $this->assertEquals('hello-world', Utils::generateSlug('Hello     World'));

        // Trim whitespace.
        $this->assertEquals('hello-world', Utils::generateSlug('     Hello world     '));

        // Replace special characters.
        $this->assertEquals('hello-world', Utils::generateSlug('Hello #$%& world'));

        // Underscore as divider.
        $this->assertEquals('hello_world', Utils::generateSlug('Hello world', '_'));

        // Empty string.
        $this->assertEquals('n-a', Utils::generateSlug(''));

        // String with only special characters.
        $this->assertEquals('n-a', Utils::generateSlug('#$%&'));

        // Accented characters.
        $this->assertEquals('café-münchén', Utils::generateSlug('Café Münchén'));

        // Preserve Chinese.
        $this->assertEquals('龙焰是六六六的', Utils::generateSlug('龙焰是六六六的'));

        // Trim trailing dividers.
        $this->assertEquals('hello-world', Utils::generateSlug('hello-world-----'));

        // Collapse multiple dividers.
        $this->assertEquals('hello-world', Utils::generateSlug('hello-----world'));
    }

    /**
     * Test output from sanitizeString() method.
     * @return void
     */
    public function testSanitizeString(): void
    {

        // Trim whitespace.
        $this->assertEquals("hello world", Utils::sanitizeString("     hello world     "));

        // Remove control characters.
        $this->assertEquals("helloworld", Utils::sanitizeString("hello\0\x08world"));

        // Collapse multiple spaces.
        $this->assertEquals("hello world", Utils::sanitizeString("hello     world"));

        // Trim and collapse.
        $this->assertEquals("a b c", Utils::sanitizeString(" a \t b \n c "));

        // Truncate.
        $this->assertEquals("hello", Utils::sanitizeString("hello world", 5));

        // Truncate at boundary.
        $this->assertEquals("hello", Utils::sanitizeString("hello", 5));

        // Ignore truncation if max chars = 0.
        $this->assertEquals(self::LOREM_IPSUM, Utils::sanitizeString(self::LOREM_IPSUM, 0));

        // Unicode preservation.
        $this->assertEquals("Café 測試", Utils::sanitizeString("Café 測試"));

        // Unicode truncation.
        $this->assertEquals("Café ", Utils::sanitizeString("Café 測試", 5));

        // Empty input.
        $this->assertEquals("", Utils::sanitizeString(""));

        // Only control characters.
        $this->assertEquals("", Utils::sanitizeString("\0\x08"));
    }
}

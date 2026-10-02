<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Tests\Feature;

use Asignua\FilamentXlsxExport\Tests\TestCase;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;

class TranslationsTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        $locales = [];

        foreach (glob(dirname(__DIR__, 2).'/resources/lang/*/xlsx-export.php') ?: [] as $file) {
            $locale = basename(dirname($file));
            $locales[$locale] = [$locale];
        }

        return $locales;
    }

    public function test_all_ten_languages_ship(): void
    {
        $this->assertSame(
            ['de', 'en', 'es', 'fr', 'it', 'nl', 'pl', 'pt_BR', 'tr', 'uk'],
            array_keys(self::locales()),
        );
    }

    #[DataProvider('locales')]
    public function test_every_locale_has_the_english_keys_and_placeholders(string $locale): void
    {
        $english = $this->strings('en');
        $strings = $this->strings($locale);

        $this->assertSame([], array_values(array_diff(array_keys($english), array_keys($strings))), 'missing keys');
        $this->assertSame([], array_values(array_diff(array_keys($strings), array_keys($english))), 'extra keys');

        foreach ($english as $key => $text) {
            $this->assertNotSame('', trim($strings[$key]), $key);
            $this->assertSame($this->placeholders($text), $this->placeholders($strings[$key]), $key);
        }
    }

    #[DataProvider('locales')]
    public function test_the_translator_resolves_every_key(string $locale): void
    {
        app()->setLocale($locale);

        foreach (array_keys($this->strings('en')) as $key) {
            $this->assertNotSame('filament-xlsx-export::xlsx-export.'.$key, __('filament-xlsx-export::xlsx-export.'.$key, ['count' => 1, 'time' => 'x', 'locales' => 'y']), $key);
        }
    }

    /**
     * @return array<string, string>
     */
    private function strings(string $locale): array
    {
        /** @var array<string, array<string, string>> $strings */
        $strings = require dirname(__DIR__, 2)."/resources/lang/{$locale}/xlsx-export.php";

        /** @var array<string, string> */
        return Arr::dot($strings);
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/:[a-z_]+/', $text, $matches);
        $found = $matches[0];
        sort($found);

        return $found;
    }
}

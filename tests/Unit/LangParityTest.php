<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Penjaga terjemahan backend (`lang/{id,en}/*.php`).
 *
 * `APP_FALLBACK_LOCALE` = `en`: kunci yang lupa ditambahkan ke `lang/id` diam-diam tampil dalam bahasa Inggris
 * bagi pengguna Indonesia, dan kunci yang tak ada di kedua bahasa tampil mentah sebagai "grup.kunci". Keduanya
 * tak memunculkan error apa pun, jadi dijaga di sini. Grup bawaan Laravel dilewati — versi en-nya dari framework.
 */
class LangParityTest extends TestCase
{
    private const LARAVEL_GROUPS = ['auth', 'pagination', 'passwords', 'validation'];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_every_group_has_the_same_keys_and_placeholders_in_id_and_en(): void
    {
        $problems = [];

        foreach ($this->langGroups() as $group) {
            $id = $this->load('id', $group);
            $en = $this->load('en', $group);

            if ($en === null) {
                $problems[] = "lang/en/{$group}.php tidak ada";

                continue;
            }

            foreach (array_keys(array_diff_key($id, $en)) as $key) {
                $problems[] = "{$group}.{$key} ada di id, tidak di en";
            }
            foreach (array_keys(array_diff_key($en, $id)) as $key) {
                $problems[] = "{$group}.{$key} ada di en, tidak di id";
            }
            foreach (array_intersect_key($id, $en) as $key => $text) {
                if ($this->placeholders($text) !== $this->placeholders($en[$key])) {
                    $problems[] = "{$group}.{$key} placeholder id [".implode(',', $this->placeholders($text)).'] ≠ en ['.implode(',', $this->placeholders($en[$key])).']';
                }
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_every_literal_translation_key_used_in_app_exists_in_both_locales(): void
    {
        $problems = [];
        $loaded = [];

        foreach ($this->sourceFiles() as $file) {
            $src = file_get_contents($file);
            // Hanya kunci literal utuh ('grup.kunci' diikuti , atau ) ) — kunci rakitan ('flash.x_'.$y) dilewati.
            if (! preg_match_all("/(?:__|trans|Lang::get)\(\s*'([a-z0-9_]+)\.([A-Za-z0-9_.]+)'\s*[,)]/", $src, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as [, $group, $key]) {
                if (in_array($group, self::LARAVEL_GROUPS, true)) {
                    continue;
                }
                foreach (['id', 'en'] as $locale) {
                    $loaded[$locale][$group] ??= $this->load($locale, $group) ?? [];
                    if (! array_key_exists($key, $loaded[$locale][$group])) {
                        $problems[] = substr($file, strlen($this->root) + 1)." → {$group}.{$key} tidak ada di lang/{$locale}";
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($problems)));
    }

    /**
     * @return list<string>
     */
    private function langGroups(): array
    {
        $groups = array_map(fn ($f) => basename($f, '.php'), glob($this->root.'/lang/id/*.php') ?: []);

        return array_values(array_diff($groups, self::LARAVEL_GROUPS));
    }

    /**
     * @return array<string, string>|null kunci diratakan dengan titik (mis. "a.b")
     */
    private function load(string $locale, string $group): ?array
    {
        $file = "{$this->root}/lang/{$locale}/{$group}.php";

        return is_file($file) ? $this->flatten(require $file) : null;
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, string>
     */
    private function flatten(array $items, string $prefix = ''): array
    {
        $flat = [];
        foreach ($items as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flatten($value, "{$prefix}{$key}.");
            } else {
                $flat["{$prefix}{$key}"] = (string) $value;
            }
        }

        return $flat;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/:([a-zA-Z_]+)/', $text, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];
        foreach (['app', 'resources/views'] as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$this->root}/{$dir}"));
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}

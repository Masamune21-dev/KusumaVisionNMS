<?php

namespace App\Services\Telegram;

/**
 * Builds inline keyboards and encodes/decodes the compact callback_data strings
 * that drive the interactive bot menu.
 *
 * Telegram caps callback_data at 64 bytes, so every screen is addressed with short
 * colon-separated numeric tokens, e.g. "on:5:1:2:1:3" = OLT 5, slot 1, PON 2,
 * filter 1 (LOS), page 3. The leading token is the screen key.
 */
class TelegramKeyboard
{
    // Port ONU-list filters (also used as the "source" marker on ONU detail).
    public const FILTER_ALL = 0;

    public const FILTER_LOS = 1;

    public const FILTER_RX = 2;

    // ONU-detail back-origin markers beyond the per-port filters above.
    public const SRC_LOS_LIST = 3;

    public const SRC_RX_LIST = 4;

    public const SRC_MENU = 5;

    /** Page size for paginated ONU / alarm lists. */
    public const PAGE_SIZE = 8;

    /**
     * Parse callback_data into [screen, intArgs].
     *
     * @return array{0: string, 1: array<int, int>}
     */
    public static function parse(string $data): array
    {
        $parts = explode(':', trim($data));
        $screen = array_shift($parts) ?: '';
        $args = array_map(static fn ($p) => (int) $p, $parts);

        return [$screen, $args];
    }

    /**
     * A single inline button.
     *
     * @return array<string, string>
     */
    public static function button(string $label, string $data): array
    {
        return ['text' => $label, 'callback_data' => $data];
    }

    // --- callback_data builders (single source of truth for the scheme) ---

    public static function menu(): string
    {
        return 'm';
    }

    public static function status(): string
    {
        return 'st';
    }

    public static function oltList(): string
    {
        return 'ol';
    }

    public static function oltDetail(int $oltId): string
    {
        return "o:{$oltId}";
    }

    public static function portList(int $oltId, int $page = 0): string
    {
        return "pl:{$oltId}:{$page}";
    }

    public static function portOnus(int $oltId, int $slot, int $port, int $filter = self::FILTER_ALL, int $page = 0): string
    {
        return "on:{$oltId}:{$slot}:{$port}:{$filter}:{$page}";
    }

    public static function onuDetail(int $oltId, int $slot, int $port, int $onuId, int $src, int $scope, int $page): string
    {
        return "u:{$oltId}:{$slot}:{$port}:{$onuId}:{$src}:{$scope}:{$page}";
    }

    public static function losList(int $scope = 0, int $page = 0): string
    {
        return "los:{$scope}:{$page}";
    }

    public static function rxList(int $scope = 0, int $page = 0): string
    {
        return "rx:{$scope}:{$page}";
    }

    public static function alarms(int $page = 0): string
    {
        return "al:{$page}";
    }

    public static function searchHelp(): string
    {
        return 'srh';
    }

    /**
     * Paginated search results. The query itself is held in cache under $token
     * (callback_data is too small for arbitrary text).
     */
    public static function searchResults(string $token, int $page = 0): string
    {
        return "sr:{$token}:{$page}";
    }

    /** ONU detail reached from a search result list (back returns to that page). */
    public static function searchDetail(string $token, int $page, int $oltId, int $slot, int $port, int $onuId): string
    {
        return "su:{$token}:{$page}:{$oltId}:{$slot}:{$port}:{$onuId}";
    }

    /**
     * "⬅️ Prev | n/total | Next ➡️" row. Returns [] when there is only one page.
     * $build maps a target page index to its callback_data.
     *
     * @param  callable(int): string  $build
     * @return array<int, array<string, string>>
     */
    public static function pager(int $page, int $totalPages, callable $build): array
    {
        if ($totalPages <= 1) {
            return [];
        }

        $row = [];

        if ($page > 0) {
            $row[] = self::button('⬅️', $build($page - 1));
        }

        $row[] = self::button(($page + 1).'/'.$totalPages, 'noop');

        if ($page < $totalPages - 1) {
            $row[] = self::button('➡️', $build($page + 1));
        }

        return $row;
    }

    /**
     * A "⬅️ Kembali" / "🏠 Menu" footer row.
     *
     * @return array<int, array<string, string>>
     */
    public static function backRow(?string $backData, bool $withHome = true): array
    {
        $row = [];

        if ($backData !== null) {
            $row[] = self::button('⬅️ Kembali', $backData);
        }

        if ($withHome) {
            $row[] = self::button('🏠 Menu', self::menu());
        }

        return $row;
    }
}

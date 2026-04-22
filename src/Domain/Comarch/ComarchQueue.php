<?php

declare(strict_types=1);

namespace App\Domain\Comarch;

use App\Bootstrap;

final class ComarchQueue
{
    private const ACTION_ADD_PRODUCT = 2;

    public static function isActive(): bool
    {
        return ($_ENV['COMARCH_ACTIVE'] ?? 'false') === 'true';
    }

    public static function addProduct(int $productId): void
    {
        if (!self::isActive()) {
            return;
        }

        $db = Bootstrap::db();

        // Remove existing entry to avoid duplicates (mirrors old Comarch::addToQueue behaviour)
        $db->delete('comarch_queue', [
            'action'  => self::ACTION_ADD_PRODUCT,
            'item_id' => $productId,
        ]);

        $db->insert('comarch_queue', [
            'action'  => self::ACTION_ADD_PRODUCT,
            'item_id' => $productId,
            'created' => date('Y-m-d H:i:s'),
        ]);
    }
}

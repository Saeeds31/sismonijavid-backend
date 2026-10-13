<?php

namespace Modules\Products\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Products\Models\ProductVariant;

class VariantBulkUpdateService
{
    public function __construct(
        protected ProductStockService $stockService,
    ) {}

    /**
     * آپدیت گروهی واریانت‌ها بر اساس ردیف‌های اعتبارسنجی‌شده
     *
     * @param array $rows خروجی VariantsImport::$rows
     * @param int   $adminId
     * @return array گزارش
     */
    public function bulkUpdate(array $rows, int $adminId): array
    {
        if (empty($rows)) {
            return ['updated' => 0, 'skipped' => 0, 'synced_products' => 0];
        }

        $variantIds = array_column($rows, 'variant_id');

        // بررسی وجود همه‌ی واریانت‌ها قبل از هر کاری
        $existingIds = ProductVariant::whereIn('id', $variantIds)
            ->pluck('id')
            ->all();

        $missingIds = array_values(array_diff($variantIds, $existingIds));
        if (!empty($missingIds)) {
            // این حالت نباید اتفاق بیفته چون توی Importer چک کردیم، ولی برای امنیت
            throw new \RuntimeException(
                'واریانت‌های زیر یافت نشدند: ' . implode(', ', $missingIds)
            );
        }

        // گرفتن product_id برای هر واریانت (برای sync)
        $variantToProduct = ProductVariant::whereIn('id', $variantIds)
            ->pluck('product_id', 'id')
            ->all();

        $updatedCount = 0;
        $skippedCount = 0;
        $affectedProductIds = [];

        DB::transaction(function () use (
            $rows, $variantToProduct, &$updatedCount, &$skippedCount, &$affectedProductIds,$adminId
        ) {
            foreach ($rows as $row) {
                $variant = ProductVariant::lockForUpdate()->find($row['variant_id']);
                if (!$variant) {
                    $skippedCount++;
                    continue;
                }

                $changed = false;
                $before = [
                    'stock' => $variant->stock,
                    'price' => $variant->price,
                    'in_store_price' => $variant->in_store_price,
                ];

                if ((int) $variant->stock !== $row['stock']) {
                    $variant->stock = $row['stock'];
                    $changed = true;
                }
                if ((int) $variant->price !== $row['price']) {
                    $variant->price = $row['price'];
                    $changed = true;
                }
                if ((int) ($variant->in_store_price ?? 0) !== (int) ($row['in_store_price'] ?? 0)) {
                    $variant->in_store_price = $row['in_store_price'];
                    $changed = true;
                }

                if (!$changed) {
                    $skippedCount++;
                    continue;
                }

                $variant->save();
                $updatedCount++;
                $affectedProductIds[$variant->product_id] = true;

                // لاگ جدا
                Log::channel('excel_import')->info('variant updated', [
                    'admin_id' => $adminId,
                    'variant_id' => $variant->id,
                    'product_id' => $variant->product_id,
                    'before' => $before,
                    'after' => [
                        'stock' => $variant->stock,
                        'price' => $variant->price,
                        'in_store_price' => $variant->in_store_price,
                    ],
                ]);
            }
        });

        // بعد از commit، sync محصولات رو انجام میدیم
        // (بیرون از transaction چون sync خودش transaction داره)
        $syncedCount = 0;
        foreach (array_keys($affectedProductIds) as $productId) {
            try {
                $product = \Modules\Products\Models\Product::find($productId);
                if ($product) {
                    $this->stockService->sync($product);
                    $syncedCount++;
                }
            } catch (\Throwable $e) {
                Log::channel('excel_import')->error('sync failed', [
                    'product_id' => $productId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'updated' => $updatedCount,
            'skipped' => $skippedCount,
            'synced_products' => $syncedCount,
        ];
    }
}
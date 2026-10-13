<?php

namespace Modules\Products\Imports;

use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Collection;

class VariantsImport implements ToCollection, WithHeadingRow
{
    /** @var array آرایه‌ی خطاها */
    public array $errors = [];

    /** @var array آرایه‌ی ردیف‌های سالم */
    public array $rows = [];

    /** ستون‌های اجباری */
    protected const REQUIRED_HEADERS = [
        'variant_id', 'product_title', 'attributes', 'stock', 'price', 'in_store_price',
    ];

    public function collection(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            $this->errors[] = ['row' => 0, 'column' => '-', 'message' => 'فایل اکسل خالی است'];
            return;
        }

        // بررسی هدرها (headingRow توسط WithHeadingRow خودکار حذف شده)
        $firstRow = $rows->first();
        $headers = array_keys($firstRow->toArray());
        foreach (self::REQUIRED_HEADERS as $header) {
            if (!in_array($header, $headers, true)) {
                $this->errors[] = [
                    'row' => 1,
                    'column' => $header,
                    'message' => "ستون «{$header}» در فایل یافت نشد",
                ];
            }
        }
        if (!empty($this->errors)) {
            return;
        }

        // اعتبارسنجی ردیف به ردیف
        foreach ($rows as $index => $row) {
            $excelRowNumber = $index + 2; // +2 چون هدر ردیف 1 و اندیس از 0 شروع میشه

            $variantId = $row['variant_id'] ?? null;
            $stock     = $row['stock'] ?? null;
            $price     = $row['price'] ?? null;
            $inStore   = $row['in_store_price'] ?? null;

            // variant_id
            if ($variantId === null || $variantId === '' || !is_numeric($variantId)) {
                $this->errors[] = [
                    'row' => $excelRowNumber,
                    'column' => 'variant_id',
                    'value' => (string) $variantId,
                    'message' => 'شناسه واریانت نامعتبر است',
                ];
                continue;
            }

            // stock
            if ($stock === null || $stock === '' || !is_numeric($stock) || (int) $stock < 0) {
                $this->errors[] = [
                    'row' => $excelRowNumber,
                    'column' => 'stock',
                    'value' => (string) $stock,
                    'message' => 'موجودی باید عدد صحیح و بزرگ‌تر یا مساوی صفر باشد',
                ];
            }

            // price
            if ($price === null || $price === '' || !is_numeric($price) || (float) $price < 0) {
                $this->errors[] = [
                    'row' => $excelRowNumber,
                    'column' => 'price',
                    'value' => (string) $price,
                    'message' => 'قیمت باید عدد و بزرگ‌تر یا مساوی صفر باشد',
                ];
            }

            // in_store_price (اختیاری)
            if ($inStore !== null && $inStore !== '') {
                if (!is_numeric($inStore) || (float) $inStore < 0) {
                    $this->errors[] = [
                        'row' => $excelRowNumber,
                        'column' => 'in_store_price',
                        'value' => (string) $inStore,
                        'message' => 'قیمت حضوری باید عدد و بزرگ‌تر یا مساوی صفر باشد (یا خالی)',
                    ];
                }
            }

            // اگه این ردیف خطا داشت، به rows اضافه نکن
            if (end($this->errors) && end($this->errors)['row'] === $excelRowNumber) {
                continue;
            }

            $this->rows[] = [
                'excel_row'      => $excelRowNumber,
                'variant_id'     => (int) $variantId,
                'stock'          => (int) $stock,
                'price'          => (int) $price,
                'in_store_price' => ($inStore === null || $inStore === '') ? null : (int) $inStore,
            ];
        }
    }
}
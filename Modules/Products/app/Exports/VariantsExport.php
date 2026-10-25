<?php

namespace Modules\Products\Exports;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Products\Models\Product;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Modules\Products\Models\ProductVariant;

class VariantsExport implements
    FromQuery,
    WithHeadings,
    WithMapping,
    WithStyles,
    ShouldAutoSize,
    WithColumnFormatting,
    WithEvents
{
    protected array $categoryIds;

    public function __construct(array $categoryIds = [])
    {
        $this->categoryIds = $categoryIds;
    }

    /**
     * کوئری با eager loading و فیلتر دسته‌بندی
     * محدود به 100 محصول (نه 100 واریانت)
     */
    public function query()
    {
        // اول 100 محصول رو انتخاب می‌کنیم (با فیلتر دسته‌بندی)
        $productIdsQuery = Product::query()
            ->select('id')
            ->when(!empty($this->categoryIds), function ($q) {
                $q->whereHas('categories', function ($c) {
                    $c->whereIn('categories.id', $this->categoryIds);
                });
            })
            ->limit(100)
            ->pluck('id');

        return ProductVariant::query()
            ->whereIn('product_id', $productIdsQuery)
            ->with(['product:id,title', 'values.attribute:id,name'])
            ->orderBy('product_id')
            ->orderBy('id');
    }

    /**
     * هدر ستون‌ها — ترتیب مهم‌ه، Import همینه رو انتظار داره
     */
    public function headings(): array
    {
        return [
            'variant_id',      // A - قفل
            'product_title',   // B - قفل
            'attributes',      // C - قفل
            'stock',           // D - قابل ویرایش
            'price',           // E - قابل ویرایش
            'in_store_price',  // F - قابل ویرایش
        ];
    }

    /**
     * مپ هر واریانت به یک ردیف
     */
    public function map($variant): array
    {
        // ساخت متن ویژگی‌ها: "رنگ: قرمز | سایز: L"
        $attributes = $variant->values
            ->map(fn($v) => "{$v->attribute->name}: {$v->value}")
            ->join(' | ');

        return [
            $variant->id,
            $variant->product?->title ?? '-',
            $attributes ?: '-',
            (int) $variant->stock,
            (int) $variant->price,
            $variant->in_store_price !== null ? (int) $variant->in_store_price : null,
        ];
    }

    /**
     * فرمت ستون‌ها — اعداد به صورت integer نمایش داده بشن
     */
    public function columnFormats(): array
    {
        return [
            'D' => NumberFormat::FORMAT_NUMBER,
            'E' => NumberFormat::FORMAT_NUMBER,
            'F' => NumberFormat::FORMAT_NUMBER,
        ];
    }

    /**
     * قفل کردن ستون‌های A, B, C برای جلوگیری از ویرایش تصادفی
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // محافظت از شیت (ستون‌های A/B/C قابل ویرایش نیستن)
                $sheet->getProtection()->setSheet(true);
                $sheet->getProtection()->setPassword('no-edit-please');

                // برای هر سلول، locked رو تنظیم می‌کنیم
                // پیش‌فرض همه قفل هستن، فقط D/E/F رو باز می‌کنیم
                $highestRow = $sheet->getHighestRow();
                for ($row = 2; $row <= $highestRow; $row++) {
                    foreach (['A', 'B', 'C'] as $col) {
                        $sheet->getStyle("{$col}{$row}")
                            ->getProtection()
                            ->setLocked(Protection::PROTECTION_PROTECTED);
                    }
                    foreach (['D', 'E', 'F'] as $col) {
                        $sheet->getStyle("{$col}{$row}")
                            ->getProtection()
                            ->setLocked(Protection::PROTECTION_UNPROTECTED);
                    }
                }

                // استایل هدر
                $sheet->getStyle('A1:F1')->getFont()->setBold(true);
                $sheet->getStyle('A1:F1')->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('D9E1F2');

                // رنگ‌بندی ستون‌های قابل ویرایش (زرد کم‌رنگ)
                $sheet->getStyle("D2:F{$highestRow}")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('FFF9C4');
            },
        ];
    }

    public function styles($sheet)
    {
        return [];
    }
}
<?php

namespace Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Products\Exports\VariantsExport;
use Modules\Products\Http\Requests\VariantImportRequest;
use Modules\Products\Imports\VariantsImport;
use Modules\Products\Services\VariantBulkUpdateService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VariantExcelController extends Controller
{
    public function __construct(
        protected VariantBulkUpdateService $bulkUpdateService,
    ) {}

    /**
     * خروجی اکسل از تنوع‌ها
     */
    public function export(Request $request): BinaryFileResponse
    {
        $categoryIds = [];
        if ($request->filled('category_ids')) {
            $categoryIds = array_filter(
                array_map('intval', explode(',', $request->query('category_ids')))
            );
        }

        $filename = 'variants-' . now()->format('Y-m-d_H-i-s') . '.xlsx';

        return Excel::download(
            new VariantsExport($categoryIds),
            $filename
        );
    }

    /**
     * ورودی اکسل و بروزرسانی گروهی
     * POST /api/v1/admin/variants/import
     */
    public function import(VariantImportRequest $request)
    {
        $importer = new VariantsImport();

        // مرحله 1: parse و validate
        Excel::import($importer, $request->file('file'));

        // اگه خطایی هست → هیچی ذخیره نشه
        if (!empty($importer->errors)) {
            return response()->json([
                'success' => false,
                'message' => count($importer->errors) . ' خطا در فایل اکسل یافت شد. هیچ تغییری اعمال نشد.',
                'errors'  => $importer->errors,
            ], 422);
        }

        if (empty($importer->rows)) {
            return response()->json([
                'success' => false,
                'message' => 'هیچ ردیف معتبری در فایل یافت نشد.',
            ], 422);
        }

        // مرحله 2: بررسی وجود variant_idها قبل از آپدیت
        $variantIds = array_column($importer->rows, 'variant_id');
        $existingIds = \Modules\Products\Models\ProductVariant::whereIn('id', $variantIds)
            ->pluck('id')
            ->all();
        $missingIds = array_values(array_diff($variantIds, $existingIds));

        if (!empty($missingIds)) {
            $errors = [];
            foreach ($importer->rows as $row) {
                if (in_array($row['variant_id'], $missingIds, true)) {
                    $errors[] = [
                        'row' => $row['excel_row'],
                        'column' => 'variant_id',
                        'value' => (string) $row['variant_id'],
                        'message' => 'تنوع با این شناسه در سیستم یافت نشد',
                    ];
                }
            }
            return response()->json([
                'success' => false,
                'message' => count($errors) . ' تنوع یافت نشد. هیچ تغییری اعمال نشد.',
                'errors'  => $errors,
            ], 422);
        }

        // مرحله 3: آپدیت گروهی + sync
        try {
            $report = $this->bulkUpdateService->bulkUpdate(
                $importer->rows,
                $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'بروزرسانی با موفقیت انجام شد.',
                'data'    => $report,
            ]);
        } catch (\Throwable $e) {
            Log::channel('excel_import')->error('bulk update failed', [
                'admin_id' => $request->user()->id,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در بروزرسانی: ' . $e->getMessage(),
            ], 500);
        }
    }
}
<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Users\Models\User;

class VariantExcelImportLog extends Model
{
    protected $table = 'variant_excel_import_logs';

    protected $fillable = [
        'user_id',
        'file_path',
        'total_rows',
        'updated_count',
    ];

    protected $casts = [
        'total_rows'    => 'integer',
        'updated_count' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

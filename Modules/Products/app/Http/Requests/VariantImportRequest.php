<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VariantImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware sanctum کارش رو کرده
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv',
                'max:10240', // 10MB
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'فایل اکسل الزامی است',
            'file.mimes' => 'فرمت فایل باید xlsx، xls یا csv باشد',
            'file.max' => 'حجم فایل نباید بیشتر از 10 مگابایت باشد',
        ];
    }
}
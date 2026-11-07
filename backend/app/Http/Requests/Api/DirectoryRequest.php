<?php

namespace App\Http\Requests\Api;

use App\Services\DirectoryCatalog;
use App\Services\FoundationAccess;
use Illuminate\Foundation\Http\FormRequest;

class DirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $module = DirectoryCatalog::module($this->route('resource'));
        $action = $this->route('id') ? 'update' : 'create';

        return $this->user()?->hasPermission($module.'.'.$action)
            && ($module !== 'geography' || FoundationAccess::administrator($this->user()));
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = trim($value) === '' ? null : trim($value);
            }
        }
        $this->replace($data);
    }

    public function rules(): array
    {
        return DirectoryCatalog::rules($this->route('resource'), $this->route('id'));
    }
}

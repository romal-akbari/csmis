<?php

namespace App\Http\Requests\Api;

use App\Services\FoundationAccess;
use App\Services\FoundationCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FoundationRequest extends FormRequest
{
    public function authorize(): bool
    {
        [, $module] = FoundationCatalog::resource($this->route('resource'));
        $action = $this->route('id') ? 'update' : 'create';
        $allowed = $this->user()?->hasPermission($module.'.'.$action);
        if (! in_array($this->route('resource'), ['accounts', 'employees', 'locations'], true)) {
            $allowed = $allowed && FoundationAccess::administrator($this->user());
        }

        return (bool) $allowed;
    }

    public function rules(): array
    {
        return FoundationCatalog::rules(
            $this->route('resource'), $this->route('id') ? (int) $this->route('id') : null,
        );
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->route('resource') !== 'locations') {
                return;
            }
            $branch = (bool) $this->input('branch_id');
            $facility = (bool) $this->input('washing_facility_id');
            $type = $this->input('location_type');
            if (! $branch && ! $facility) {
                if ($type === 'washing') {
                    $validator->errors()->add('washing_facility_id', 'Choose a washing facility for this location.');
                } elseif ($type) {
                    $validator->errors()->add('branch_id', 'Choose a branch for this stock location.');
                }
            } elseif ($branch && $facility) {
                $validator->errors()->add('branch_id', 'Choose exactly one branch or washing facility.');
            }
            if (($branch || $facility) && $type && ($type === 'washing') !== $facility) {
                $validator->errors()->add('location_type', 'Washing locations require a facility; other locations require a branch.');
            }
        });
    }
}

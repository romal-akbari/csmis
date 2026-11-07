<?php

namespace App\Http\Requests\Api;

/**
 * §4 customer update rules — same constraints as creation, but every
 * field is optional (partial updates are allowed).
 */
class UpdateCustomerRequest extends StoreCustomerRequest
{
    /**
     * @return array<string, array<int, string|array<int, string>>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (parent::rules() as $field => $rule) {
            $ruleKeys = is_array($rule) ? $rule : [$rule];
            $hasRequired = in_array('required', $ruleKeys, true);

            if ($hasRequired) {
                // Omitted fields are unchanged; a supplied required field may
                // never become null/blank in a partial update.
                if (! in_array('sometimes', $ruleKeys, true)) {
                    array_unshift($ruleKeys, 'sometimes');
                }
            }

            $rules[$field] = $ruleKeys;
        }

        return $rules;
    }
}

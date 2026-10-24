<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            // Shown publicly instead of the login email; must not reveal it.
            'display_name' => ['nullable', 'string', 'max:60', 'not_regex:/@/', function (string $attribute, mixed $value, \Closure $fail) {
                $email = strtolower((string) $this->input('email'));
                $local = strstr($email, '@', true) ?: $email;
                if (in_array(strtolower(trim((string) $value)), [$email, $local], true)) {
                    $fail('The display name must not match your login email.');
                }
            }],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
        ];
    }
}

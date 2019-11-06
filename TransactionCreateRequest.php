<?php

namespace App\Http\Requests\Api\Transaction;

use App\Models\Enum;
use App\Models\Transaction;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class TransactionCreateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $enums = Enum::where('type', Enum::TYPE_TransactionType)->get();
        $typeIds = $enums->pluck('id')->toArray();
        $enum = $enums->where('name', 'transfer')->first();
        $val = $enum ? $enum->getId() : '';
        return [
            'type_id' => ['required', 'numeric', Rule::in($typeIds)],
            'amount' => ['required', 'between:0,999999.99'],
            'account_id' => ['required', 'numeric', 'exists:accounts,id'],
            'account_transfer_id' => ["required_if:type_id,==,$val", 'nullable', 'numeric', 'exists:accounts,id'],
            'account_item_id' => ['required', 'numeric', 'exists:account_items,id'],
            'contractor_id' => ['required_without:account_transfer_id', 'nullable', 'numeric'],
            'project_id' => ['nullable', 'numeric', 'exists:projects,id'],
            'description' => ['nullable', 'string', 'max:3000'],
            'convertation_rate' => ['nullable', 'numeric', 'between:0,9999.9900'],
            'date' => ['required', 'date'],
            'planned' => ['nullable', 'numeric'],
            'repeated' => ['required_with:repeated_every', 'nullable', 'string', Rule::in([Transaction::REPEATED_WEEK, Transaction::REPEATED_MONTH, Transaction::REPEATED_YEAR])],
            'repeated_every' => ['required_with:repeated', 'nullable', 'numeric'],
            'image' => 'nullable|image|max:2048',
        ];
    }

    /**
     * @param Validator $validator
     */
    protected function failedValidation(Validator $validator) {
        throw new HttpResponseException(response()->json($validator->errors(),422));
    }
}

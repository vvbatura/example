<?php


namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class Transaction extends JsonResource
{
    public function toArray($data)
    {
        $actions = [];
        if ($this->planned) {
            if (Carbon::create($this->date)->format('Y-m-d') == Carbon::now()->format('Y-m-d')) {
                $actions[] = \App\Models\Transaction::BUTTON_APPROVED;
            } else {
                $actions[] = \App\Models\Transaction::BUTTON_PLANNED;
            }
        }
        if ($this->repeated) {
            $actions[] = \App\Models\Transaction::BUTTON_REPEATED;
        }
        return [
            'id' => $this->getId(),
            'ownerName' => $this->ownerName,
            'typeName' => $this->typeName,
            'typeValue' => $this->typeValue,
            'amount' => $this->amount,
            'accountItemName' => $this->accountItemName,
            'accountTypeName' => $this->accountTypeName,
            'projectTitle' => $this->projectTitle,
            'contractorName' => $this->contractorName,
            'description' => $this->description,
            'convertation_rate' => $this->convertation_rate,
            'actions' => $actions,
            'date' => $this->date,
            //'statusName' => $this->statusName,
            //'statusValue' => $this->statusValue,
            'status' => $this->statusValue,
        ];
    }
}

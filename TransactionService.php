<?php


namespace App\Services\Transaction;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\AccountItem;
use App\Models\Enum;
use Carbon\Carbon;
use Illuminate\Support\Str;

class TransactionService implements Contracts\Transaction
{

    public function prepareDataForCreate($data)
    {
        $addData =  $this->getCreateAddData($data);

        $createData = [];

        if (array_key_exists('repeated', $data) && $data['repeated']) {
            $addData['repeated_code'] = Str::random(32);
            $createData = $this->getCreateRepeatData($data, $addData);
        }

        array_unshift($createData, $addData);

        return $createData;
    }

    protected function getContractor($data, $currencyId)
    {
        $accountItem = AccountItem::find($data['account_item_id']);
        $model = $this->getModel($accountItem->widget);

        return $model::with(['_accounts' => function ($query) use ($currencyId) {
            $query->where('currency_id', $currencyId);
        }])->find($data['contractor_id']);
    }

    public function getModel($widget)
    {
        switch ($widget) {
            case Enum::AccountItemWidget_Office:
                $model = 'App\Models\Office';
                break;
            case Enum::AccountItemWidget_Customer:
                $model = 'App\Models\Customer';
                break;
            case Enum::AccountItemWidget_User:
                $model = 'App\User';
                break;
            default:
                $model = null;
        }
        return $model;
    }

    protected function getCreateAddData($data)
    {
        $dateNow = Carbon::now();

        $addData = collect($data)->only([
            'owner_id', 'type_id', 'account_item_id', 'project_id', 'description', 'amount', 'convertation_rate', 'date',
            'planned', 'repeated', 'repeated_every', 'repeated_code', 'image'
        ])->toArray();

        $addData['status_id'] = Transaction::Status_Complete;
        if (array_key_exists('planned', $data) && $data['planned']) {
            $addData['status_id'] = Transaction::Status_Pending;
        }

        $addData['created_at'] = $dateNow;
        $addData['updated_at'] = $dateNow;

        $enums = Enum::where('type', Enum::TYPE_TransactionType)->get();
        $currencyId = Account::find($data['account_id'])->currency_id;
        switch ($data['type_id']) {
            case $enums->where('name', 'expense')->first()->getId():
                $addData['account_from_id'] = $data['account_id'];

                $contractor = $this->getContractor($data, $currencyId);
                $account = $contractor->_accounts->first();
                if (!$account) {
                    $account = $contractor->_accounts()->create([
                        'currency_id' => $currencyId,
                        'total' => 0,
                    ]);
                }
                $addData['account_to_id'] = $account->getId();

                break;
            case $enums->where('name', 'income')->first()->getId():
                $addData['account_to_id'] = $data['account_id'];

                $contractor = $this->getContractor($data, $currencyId);
                $account = $contractor->_accounts->first();
                if (!$account) {
                    $account = $contractor->_accounts()->create([
                        'currency_id' => $currencyId,
                        'total' => 0,
                    ]);
                }

                $addData['account_from_id'] = $account->getId();
                break;
            case $enums->where('name', 'transfer')->first()->getId():
                $addData['account_from_id'] = $data['account_id'];
                $addData['account_to_id'] = $data['account_transfer_id'];

                $accounts = Account::with('_model')->whereIn('id', [$data['account_id'], $data['account_transfer_id']])->get();
                $accountFrom = $accounts->where('id', $data['account_id'])->first();
                $accountTo = $accounts->where('id', $data['account_transfer_id'])->first();
                if ($accountFrom->currency_id != $accountTo->currency_id) {
                    if (array_key_exists('convertation_rate', $data)) {
                        $convertationRate = $data['convertation_rate'];
                    } else {
                        $convertationRate = ExchangeRate::where('currency_id', $accountTo->currency_id)->first()->rate;
                    }
                    $addData['amount'] = $data['amount'] * $convertationRate;
                }
                $contractor = $accountTo->_model;
                break;
        }
        $addData['contractor_id'] = $contractor->getId();
        $addData['contractor_type'] = get_class($contractor);

        return $addData;
    }

    protected function getCreateRepeatData($data, $addData)
    {
        $createData = [];
        $addData['status_id'] = Transaction::Status_Pending;
        $addData['planned'] = 1;
        switch ($data['repeated']) {
            case Transaction::REPEATED_WEEK:
                $method = 'addWeeks';
                break;
            case Transaction::REPEATED_MONTH:
                $method = 'addMonths';
                break;
            case Transaction::REPEATED_YEAR:
                $method = 'addYears';
                break;
        }
        $every = $data['repeated_every'];
        $dateTransaction = Carbon::create($data['date']);
        $addDate = clone $dateTransaction;
        $addDate->$method($every);
        while($addDate->year == $dateTransaction->year) {
            $addData['date'] = clone $addDate;
            array_push($createData, $addData);
            $addDate->$method($every);
        }

        return $createData;
    }

    public function prepareDataForComplete ($transaction, $request)
    {
        $createData = collect($transaction)->only([
            'contractor_id', 'contractor_type', 'owner_id', 'type_id', 'amount', 'date', 'image',
            'account_from_id', 'account_to_id', 'account_item_id', 'project_id', 'description', 'convertation_rate',
        ])->toArray();
        if ($request->get('amount'))  {
            $createData['amount'] = $request->get('amount');
        }
        if ($request->get('description'))  {
            $createData['description'] = $request->get('description');
        }
        $createData['status_id'] = $request->get('status_id');

        return $createData;
    }

    public function cronCreatePlannedItems ()
    {
        $year = Carbon::now()->year -1;
        $transactions =Transaction::where('planned', 1)
            ->whereIn('status_id', [Transaction::Status_Complete])
            ->whereRaw("YEAR(date) = $year")
            ->groupBy('repeated_code')
            ->get();
        /* ---for test online
        $year = Carbon::now()->year;
        $transactions =Transaction::where('planned', 1)
            ->whereIn('status_id', [Transaction::Status_Complete, Transaction::Status_Pending])
            ->whereRaw("YEAR(date) = $year")
            ->groupBy('repeated_code')
            ->get();
        */
        $dateNow = Carbon::now();
        $insertData = [];
        foreach ($transactions as $transaction){
            $data = $transaction->only([
                'repeated', 'repeated_every'
            ]);
            $date = Carbon::create($transaction->date);
            $dateStart = Carbon::createFromDate($year +1, 1, 1);
            $day = $date->dayOfWeek;
            $dayNY = $dateStart->dayOfWeek;
            $dayStart = $day-$dayNY > 0 ? $day-$dayNY+1 : $day-$dayNY+8;
            switch ($transaction->repeated) {
                case Transaction::REPEATED_WEEK:
                    $dateStart = Carbon::createFromDate($year, 1, $dayStart+($transaction->repeated_every-1)*7);
                    break;
                case Transaction::REPEATED_MONTH:
                    $dateStart = Carbon::createFromDate($year, $transaction->repeated_every, $dayStart);
                    break;
                case Transaction::REPEATED_YEAR:
                    $dateStart = Carbon::createFromDate($year, $date->month, $day);
                    break;
            }
            $data['date'] = $dateStart->format("Y-m-d");
            $addData = $transaction->only([
                'contractor_id', 'contractor_type', 'owner_id', 'type_id', 'amount', 'image',
                'account_from_id', 'account_to_id', 'account_item_id', 'project_id', 'description', 'convertation_rate',
                'repeated', 'repeated_every', 'repeated_code'
            ]);
            $addData['planned'] = 1;
            $addData['created_at'] = $dateNow;
            $addData['updated_at'] = $dateNow;
            $addData['status_id'] = Transaction::Status_Pending;

            $insertAddData = $this->getCreateRepeatData($data, $addData);
            array_unshift($insertAddData, array_merge($data, $addData));
            $insertData = array_merge($insertData, $insertAddData);
        }

        $result = Transaction::insert($insertData);

    }

    public function prepareDataForChart($data, $request)
    {
        $data['expense']['plan'] = $this->buildReturnData($data['expense']['plan'], $request);
        $data['expense']['fact'] = $this->buildReturnData($data['expense']['fact'], $request);
        $data['income']['plan'] = $this->buildReturnData($data['income']['plan'], $request);
        $data['income']['fact'] = $this->buildReturnData($data['income']['fact'], $request);

        return $data;
    }

    protected function buildDataStructure ($request)
    {
        $periodFrom = $request->get('period_from', Carbon::now()->startOfMonth());
        $periodTo = $request->get('period_to',  Carbon::now());
        $periodType = $request->get('period_type', Enum::REPEATED_DAY);
        switch ($periodType) {
            case Enum::REPEATED_DAY:
                $method = 'addDay';
                $format = 'Y-m-d';
                break;
            case Enum::REPEATED_WEEK:
                $method = 'addWeek';
                $format = 'Y-W';
                break;
            case Enum::REPEATED_MONTH:
                $method = 'addMonth';
                $format = 'Y-m';
                break;
            case Enum::REPEATED_QUARTER:
                $method = 'addQuarter';
                $format = 'Y';
                break;
            case Enum::REPEATED_YEAR:
                $method = 'addYear';
                $format = 'Y';
                break;
        }
        $returnData = [];
        $start = Carbon::parse($periodFrom);
        $end = Carbon::parse($periodTo);
        for ($date = $start; $date <= $end; $date->$method()) {
            $key = $start->format($format);
            if ($periodType == Enum::REPEATED_QUARTER) {
                $key .= '-' . $start->quarter;
            }
            $elem = [
                'x' => $key,
                'y' => 0
            ];
            $returnData[] = $elem;
        }
        return $returnData;
    }

    protected function buildReturnData ($data, $request)
    {
        $returnData =  $this->buildDataStructure($request);
        foreach ($returnData as &$datum) {
            $elem = $data->where('date_group', $datum['x']);
            if (count($elem)) {
                $datum['y'] = $elem->first()->sum;
            }
        }
        return $this->buildDataFormat($returnData);
    }

    protected function buildDataFormat ($data)
    {
        foreach($data as &$item) {
            $x = $item['x'];
            if ($pos = strrpos($x, '-')){
                $x = substr($x, $pos+1);
            }
            $xy = '';
            /*if ($pos = strpos($item['x'], '-')){
                $xy = substr($item['x'], 0, $pos+1);
            }*/
            $item['x'] = $xy . intval($x);

        }
        return $data;
    }
}

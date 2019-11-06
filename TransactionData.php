<?php

namespace App\Traits;

use App\Models\Customer;
use App\Models\Enum;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\User;
use Illuminate\Http\Request;
use App\Models\Office;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

trait TransactionData
{
    public static function scopeRelations($query)
    {
        return $query->with([
            '_owner',
            '_accountItem',
            '_accountFrom',
            '_accountTo',
            '_project',
            //'_status',
            '_contractor',
            '_comments',
        ]);
    }

    public static function scopeForDate($query, Request $request)
    {
        $periodFrom = $request->get('period_from', Carbon::now()->startOfMonth())  ;
        $periodTo = $request->get('period_to',  Carbon::now()) ;
        return $query->whereBetween('date', [$periodFrom, $periodTo]);
    }

    public static function scopeForOffices($query, Request $request)
    {
        $officeIds = $request->get('office_ids', []);
        return $query->whereHas('_accountFrom', function ($qw) use ($officeIds) {
            $qw->whereHasMorph('_model', Office::class, function ($qr) use ($officeIds) {
                $qr->when(count($officeIds), function ($q) use ($officeIds) {
                    $q->whereIn('id', $officeIds);
                });
            });
        })->orWhereHas('_accountTo', function ($qw) use ($officeIds) {
            $qw->whereHasMorph('_model', Office::class, function ($qr) use ($officeIds) {
                $qr->when(count($officeIds), function ($q) use ($officeIds) {
                    $q->whereIn('id', $officeIds);
                });
            });
        });
    }

    public static function scopeForShow($query)
    {
        $officeClass = quotemeta(Office::class);
        $customerClass = quotemeta(Customer::class);
        $userClass = quotemeta(User::class);
        $incomeId = Enum::where('name', 'income')->first()->getId();
        $select = 'transactions.*, '
        . "(CASE "
        . "WHEN transactions.contractor_type = '$officeClass' THEN jt1.name "
        . "WHEN transactions.contractor_type = '$customerClass' THEN jt2.name "
        . "WHEN transactions.contractor_type = '$userClass' THEN CONCAT(jt3.first_name,jt3.last_name) "
        . "END) AS contractor"
        . ", (CASE "
        . "WHEN transactions.type_id = '$incomeId' THEN jt4.name "
        . "ELSE jt5.name "
        . "END) AS account_name";

        return $query->where('planned', 0)
            ->orWhere(function ($q) {
                $q->where('planned', 1)
                    ->whereIn('status_id', [Transaction::Status_Pending]);
                    //->whereIn('status_id', [Transaction::Status_Complete, Transaction::Status_Disabled]);
            })->select(DB::raw($select))
            ->leftJoin('offices as jt1', function ($join) use ($officeClass) {
                $join->on('jt1.id', '=', 'transactions.contractor_id')->whereRaw("transactions.contractor_type='$officeClass'");
            })->leftJoin('customers as jt2', function ($join) use ($customerClass) {
                $join->on('jt2.id', '=', 'transactions.contractor_id')->whereRaw("transactions.contractor_type='$customerClass'");
            })->leftJoin('users as jt3', function ($join) use ($userClass) {
                $join->on('jt3.id', '=', 'transactions.contractor_id')->whereRaw("transactions.contractor_type='$userClass'");
            })->leftJoin('accounts as jt4', function ($join) use ($incomeId) {
                $join->on('jt4.id', '=', 'transactions.account_from_id')->whereRaw("transactions.type_id=$incomeId");
            })->leftJoin('accounts as jt5', function ($join) use ($incomeId) {
                $join->on('jt5.id', '=', 'transactions.account_to_id')->whereRaw("transactions.type_id!='$incomeId'");
            })->withCount([
                '_accountItem as details' => function ($q) {
                    $q->select('name');
                }
            ]);
    }

    public static function scopeForOfficesBalance($query, Request $request, $type =Enum::TransactionType_EXPENSE)
    {
        $officeIds = $request->get('office_ids', []);
        $relation = '_accountFrom';
        if ($type==Enum::TransactionType_INCOME) {
            $relation = '_accountTo';
        }
        return $query->whereHas($relation, function ($qw) use ($officeIds) {
            $qw->whereHasMorph('_model', Office::class, function ($qr) use ($officeIds) {
                $qr->when(count($officeIds), function ($q) use ($officeIds) {
                    $q->whereIn('id', $officeIds);
                });
            });
        });
    }

    public static function scopeForDiagram($query, $type =Enum::TransactionType_EXPENSE, $planned =0)
    {
        $exchangeRates = ExchangeRate::get();
        $queryRate ='CASE';
        foreach ($exchangeRates as $exchangeRate) {
            $queryRate .= ' WHEN accounts.currency_id=' . $exchangeRate->currency_id . ' THEN ' . $exchangeRate->rate;
        }
        $queryRate .= ' ELSE 1.0 END';
        $relationField = 'account_from_id';
        if ($type==Enum::TransactionType_INCOME) {
            $relationField = 'account_to_id';
        }
        return $query->select(DB::raw("account_items.name as item"), DB::raw("SUM(amount * $queryRate ) as sum"))
            ->join('account_items', 'account_items.id', '=', 'transactions.account_item_id')
            ->join('accounts', 'accounts.id', '=', $relationField)
            ->where('transactions.planned', $planned)
            ->groupBy('item');
    }

    public static function scopeForChart($query, Request $request, $type =Enum::TransactionType_EXPENSE, $planned =0)
    {
        $exchangeRates = ExchangeRate::get();
        $queryRate ='CASE';
        foreach ($exchangeRates as $exchangeRate) {
            $queryRate .= ' WHEN accounts.currency_id=' . $exchangeRate->currency_id . ' THEN ' . $exchangeRate->rate;
        }
        $queryRate .= ' ELSE 1.0 END';
        $transactionType = $request->get('period_type', Enum::REPEATED_DAY);
        $queryTransactionType = '';
        switch ($transactionType) {
            case Enum::REPEATED_DAY:
                //$queryTransactionType = 'UNIX_TIMESTAMP(date)';
                $queryTransactionType = 'DATE(date)';
                break;
            case Enum::REPEATED_WEEK:
                //$queryTransactionType = 'YEARWEEK(date)';
                $queryTransactionType = 'DATE_FORMAT(date, "%Y-%V")';
                break;
            case Enum::REPEATED_MONTH:
                //$queryTransactionType = 'MONTH(date)';
                $queryTransactionType = 'DATE_FORMAT(date, "%Y-%m")';
                break;
            case Enum::REPEATED_QUARTER:
                //$queryTransactionType = 'QUARTER(date)';
                $queryTransactionType = 'CONCAT(YEAR(date), "-", QUARTER(date))';
                break;
            case Enum::REPEATED_YEAR:
                $queryTransactionType = 'YEAR(date)';
                break;
        }
        $relationField = 'account_from_id';
        if ($type==Enum::TransactionType_INCOME) {
            $relationField = 'account_to_id';
        }
        return $query->select(DB::raw($queryTransactionType . ' as date_group'), DB::raw("SUM(amount * $queryRate ) as sum"))
            ->join('accounts', 'accounts.id', '=', $relationField)
            ->where('transactions.planned', $planned)
            ->groupBy('date_group')
            ->orderBy('date_group');
    }

    /*public static function scopeSorting($query, Request $request)
    {
        $orderField = $request->get('order_field', 'date');
        $orderType = $request->get('order_type',  'asc');
        $sortable = self::getSortableFields();
        if(in_array($orderField, $sortable)) {
            return $query->orderBy($orderField, $orderType);
        }

        return $query;
    }*/
}

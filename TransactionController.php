<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\Transaction\CommentRequest;
use App\Http\Requests\Api\Transaction\TransactionContractorRequest;
use App\Http\Requests\Api\Transaction\TransactionCreateRequest;
use App\Http\Requests\Api\ExcelImportRequest;
use App\Http\Requests\Api\Transaction\TransactionDataRequest;
use App\Http\Requests\Api\Transaction\TransactionManyRequest;
use App\Http\Requests\Api\Transaction\TransactionProjectRequest;
use App\Http\Requests\Api\Transaction\TransactionRequest;
use App\Http\Requests\Api\Transaction\TransactionStatusRequest;
use App\Http\Resources\Account as AccountResource;
use App\Http\Resources\Autocomplete\Contractor;
use App\Http\Resources\Autocomplete\ProjectResource;
use App\Models\Account;
use App\Models\AccountItem;
use App\Models\Customer;
use App\Models\Enum;
use App\Models\ExchangeRate;
use App\Models\Office;
use App\Imports\CustomersImport;
use App\Imports\TransactionsImport;
use App\Models\Transaction;
use App\Http\Resources\Transaction as TransactionResource;
use App\Http\Controllers\Controller;
use App\Services\Transaction\TransactionService;
use App\Traits\HelperData;
use App\Traits\Importable;
use Maatwebsite\Excel\Facades\Excel;
use App\User;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\AccountItem as AccountItemResource;

class TransactionController extends Controller
{
    use Importable;
    use HelperData;

    const ImageFolder = 'transaction';

    protected $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    public function index(TransactionDataRequest $request)
    {
        $transactions = Transaction::relations()
            ->forDate($request)
            ->forOffices($request)
            ->forShow($request)
            ->forTable($request)
            ->sorting($request)
            ->paginate($request->get('per_page', Transaction::Paginate_PerPage));

        return TransactionResource::collection($transactions);
    }

    public function store(TransactionCreateRequest $request)
    {
        try {
            $data = $this->prepareData($request, $this::ImageFolder);
            $data = $this->transactionService->prepareDataForCreate($data);
            Transaction::insert($data);
            $accounts = Account::whereIn('id', [$data[0]['account_from_id'], $data[0]['account_to_id']])->get();
            $accountFrom = $accounts->where('id', $data[0]['account_from_id'])->first();
            $accountTo = $accounts->where('id', $data[0]['account_to_id'])->first();
            Account::where('id', $accountFrom->id)->update(['total' => $accountFrom->total - $data[0]['amount']]);
            $convertationRate = 1;
            if ($accountFrom->currency_id != $accountTo->currency_id) {
                $convertationRate = $data[0]['convertation_rate'];
            }
            Account::where('id', $accountTo->id)->update(['total' => $accountTo->total + $data[0]['amount'] / $convertationRate]);
            if ($request->get('delete', 0)) {
                Account::whereId($accountFrom->getId())->delete();
            }
            $response = ['result' => true, 'message' => 'Transaction created successfully.'];

        } catch (\Exception $e) {
            Log::error('Exception in transaction created: ', ['exception' => $e]);
            $this->deleteImage($data['image']);
            $response = ['result' => false, 'message' => 'Problems in transaction created.'];
        }

        return response()->json($response);
    }

    public function updateStatus(TransactionStatusRequest $request, $transactionId)
    {
        $transaction = Transaction::find($transactionId);
        $statusId = $request->get('status_id');
        if (!(($transaction->status_id == Transaction::Status_Pending && $transaction->planned == 1)
            && in_array($statusId, [Transaction::Status_Complete, Transaction::Status_Disabled]))) {
            return response()->json(['result' => false, 'message' => 'Error data for update status.']);
        }

        try {
            if ($statusId == Transaction::Status_Complete) {
                $createData = $this->transactionService->prepareDataForComplete($transaction, $request);

                $transactionNew = Transaction::create($createData);
                $transaction->update([ 'status_id' => Transaction::Status_Complete]);

            } elseif ($statusId == Transaction::Status_Disabled) {
                $repeatedCode = $transaction->repeated_code;
                $date = $transaction->date;
                Transaction::updateItems(['repeated_code' => $repeatedCode, 'status_id' => Transaction::Status_Complete],['status_id' => Transaction::Status_Disabled]);
                Transaction::forceDeleteItems(['repeated_code' => $repeatedCode], $date);
            }

            $response = ['result' => true, 'message' => 'Transaction status update successfully.'];

        } catch (\Exception $e) {
            Log::error('Exception in transaction status update: ', ['exception' => $e]);
            $response = ['result' => false, 'message' => 'Problems in transaction status update.'];
        }

        return response()->json($response);
    }

    public function delete(TransactionRequest $request, $transactionId)
    {
        try {
            Transaction::whereId($transactionId)->delete();
            $response = ['result' => true, 'message' => 'Transaction delete successfully.'];

        } catch (\Exception $e) {
            Log::error('Exception in transaction delete: ', ['exception' => $e]);
            $response = ['result' => false, 'message' => 'Problems in transaction delete.'];
        }

        return response()->json($response);
    }

    public function deleteMany(TransactionManyRequest $request)
    {
        try {
            Transaction::whereIn('id', $request->ids)->delete();
            $response = ['result' => true, 'message' => 'Transactions delete successfully.'];

        } catch (\Exception $e) {
            Log::error('Exception in transactions delete: ', ['exception' => $e]);
            $response = ['result' => false, 'message' => 'Problems in transactions delete.'];
        }

        return response()->json($response);
    }

    public function addComment(CommentRequest $request, $transactionId)
    {
        try {
            $transaction = User::find($transactionId);
            $data = $this->prepareData($request);
            $transaction->_comments()->create($data);
            $response = ['result' => true, 'message' => 'Comment add successfully.'];

        } catch (\Exception $e) {
            Log::error('Exception in comment add: ', ['exception' => $e]);
            $response = ['result' => false, 'message' => 'Problems in add comment.'];
        }

        return response()->json($response);
    }

    public function show(TransactionRequest $request, $transactionId)
    {
        $transaction = Transaction::find($transactionId);

        return new TransactionResource($transaction);
    }

    public function getForNew()
    {
        $accounts = Office::with('_accounts')->get()->pluck('_accounts');
        $accountItems = AccountItem::get();

        return [
            'accounts' => AccountResource::collection($accounts->collapse()),
            'accountItems' => AccountItemResource::collection($accountItems),
        ];
    }

    public function getContractors(TransactionContractorRequest $request)
    {

        $model = $this->transactionService->getModel($request->get('contractor_type'));
        $data = $model ? $model::get() : collect();

        return Contractor::collection($data);
    }

    public function getProjects(TransactionProjectRequest $request)
    {
        $model = $this->transactionService->getModel($request->get('contractor_type'));

        return ProjectResource::collection($model::find($request->get('contractor_id'))->_projects);
    }

    public function import(ExcelImportRequest $request)
    {
        $file = $request->file('file');

        if(!$this->isExcelExtension($file->getClientOriginalExtension())){
            return response()->json([
                'status' => 'error',
                'message' => 'Wrong file extension'
            ], 422);
        }

        Excel::queueImport(new TransactionsImport(), $file);

        return response()->json([
            'status' => 'success',
            'message' => 'Import finished'
        ], 200);
    }
}

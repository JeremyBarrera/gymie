<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\ExpenseStoreRequest;
use App\Http\Requests\Api\V1\ExpenseUpdateRequest;
use App\Http\Resources\V1\ExpenseResource;
use App\Models\Expense;
use App\Services\Api\QueryFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExpensesController extends ApiController
{
    private const RESOURCE_KEY = 'expenses';

    

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->requirePermission($request, 'ViewAny:Expense');

        $query = Expense::query();

        QueryFilters::applyIndexFilters($query, $request, self::RESOURCE_KEY);

        $perPage = QueryFilters::perPage($request->query('per_page'));

        return ExpenseResource::collection($query->paginate($perPage));
    }

    

    public function store(ExpenseStoreRequest $request): ExpenseResource
    {
        $this->requirePermission($request, 'Create:Expense');

        $expense = Expense::create($request->validated());

        return new ExpenseResource($expense->refresh());
    }

    

    public function show(Request $request, Expense $expense): ExpenseResource
    {
        $this->requirePermission($request, 'View:Expense');

        return new ExpenseResource($expense);
    }

    

    public function update(ExpenseUpdateRequest $request, Expense $expense): ExpenseResource
    {
        $this->requirePermission($request, 'Update:Expense');

        $expense->update($request->validated());

        return new ExpenseResource($expense->refresh());
    }

    

    public function destroy(Request $request, Expense $expense): JsonResponse
    {
        return $this->deleteModel($request, 'Delete:Expense', $expense);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * TransactionController.
 *
 * Read-only admin views for platform transactions.
 * Supports filtering by type, status, wallet address, and date range.
 */
class TransactionController extends Controller
{
    /**
     * Display a paginated listing of transactions with filters.
     */
    public function index(Request $request): InertiaResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'in:trade,swap,deposit,withdrawal'],
            'status' => ['nullable', 'string', 'in:pending,confirming,completed,failed,cancelled'],
            'wallet' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = Transaction::with('chain')
            ->orderByDesc('created_at');

        if (! empty($validated['type'])) {
            $query->byType($validated['type']);
        }

        if (! empty($validated['status'])) {
            $query->byStatus($validated['status']);
        }

        if (! empty($validated['wallet'])) {
            $query->byWallet($validated['wallet']);
        }

        if (! empty($validated['date_from'])) {
            $query->where('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->where('created_at', '<=', $validated['date_to'].' 23:59:59');
        }

        $perPage = $validated['per_page'] ?? 20;
        $transactions = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Transactions/Index', [
            'transactions' => $transactions,
            'filters' => $validated,
        ]);
    }

    /**
     * Display a single transaction's details.
     */
    public function show(Transaction $transaction): InertiaResponse
    {
        $transaction->load('chain');

        return Inertia::render('Admin/Transactions/Show', [
            'transaction' => $transaction,
        ]);
    }
}

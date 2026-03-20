<?php

namespace App\Services;

use App\Models\CarbonCredit;
use App\Models\CarbonProject;
use App\Models\CarbonRetirement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CarbonCreditService
{
    /**
     * ดึงโปรเจกต์ที่เปิดขาย.
     */
    public function getActiveProjects(int $perPage = 12): LengthAwarePaginator
    {
        return CarbonProject::active()
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * ดึงโปรเจกต์ตาม slug.
     */
    public function getProject(string $slug): ?CarbonProject
    {
        return CarbonProject::where('slug', $slug)->first();
    }

    /**
     * ซื้อ Carbon Credits (with transaction + locking).
     */
    public function purchaseCredits(array $data): CarbonCredit
    {
        return DB::transaction(function () use ($data) {
            $project = CarbonProject::lockForUpdate()->findOrFail($data['project_id']);

            if ($project->status !== 'active') {
                throw new \RuntimeException('Project is not active.');
            }

            $amount = (float) $data['amount'];
            if (bccomp((string) $amount, (string) $project->available_credits, 2) > 0) {
                throw new \RuntimeException('Not enough credits available.');
            }

            $priceUsd = bcmul((string) $amount, (string) $project->price_per_credit_usd, 2);

            $credit = CarbonCredit::create([
                'carbon_project_id' => $project->id,
                'serial_number' => 'CC-'.strtoupper(Str::random(8)).'-'.now()->format('Ymd'),
                'owner_address' => strtolower($data['wallet_address']),
                'amount' => $amount,
                'price_paid_usd' => $priceUsd,
                'payment_currency' => $data['payment_currency'] ?? 'TPIX',
                'payment_amount' => $data['payment_amount'] ?? null,
                'tx_hash' => $data['tx_hash'] ?? null,
                'status' => 'active',
            ]);

            $project->decrement('available_credits', $amount);
            $project->refresh();

            if (bccomp((string) $project->available_credits, '0', 2) <= 0) {
                $project->update(['status' => 'sold_out']);
            }

            return $credit;
        });
    }

    /**
     * Retire carbon credits (with transaction + locking).
     */
    public function retireCredits(array $data): CarbonRetirement
    {
        return DB::transaction(function () use ($data) {
            $credit = CarbonCredit::lockForUpdate()
                ->where('id', $data['credit_id'])
                ->where('owner_address', strtolower($data['wallet_address']))
                ->where('status', 'active')
                ->firstOrFail();

            $amount = (float) $data['amount'];
            if (bccomp((string) $amount, (string) $credit->amount, 2) > 0) {
                throw new \RuntimeException('Cannot retire more than owned amount.');
            }

            $retirement = CarbonRetirement::create([
                'carbon_credit_id' => $credit->id,
                'retiree_address' => strtolower($data['wallet_address']),
                'beneficiary_name' => $data['beneficiary_name'] ?? null,
                'retirement_reason' => $data['retirement_reason'] ?? null,
                'amount' => $amount,
                'certificate_hash' => '0x'.bin2hex(random_bytes(32)),
            ]);

            $remainingAmount = bcsub((string) $credit->amount, (string) $amount, 2);
            if (bccomp($remainingAmount, '0', 2) <= 0) {
                $credit->update(['status' => 'retired', 'amount' => 0]);
            } else {
                $credit->update(['amount' => $remainingAmount]);
            }

            $credit->project->increment('retired_credits', $amount);

            return $retirement;
        });
    }

    /**
     * ดึง Credits ของ wallet.
     */
    public function getCreditsByOwner(string $address): Collection
    {
        return CarbonCredit::byOwner($address)
            ->with(['project', 'retirements'])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * ดึง Retirements ของ wallet.
     */
    public function getRetirementsByAddress(string $address): Collection
    {
        return CarbonRetirement::where('retiree_address', strtolower($address))
            ->with('credit.project')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * สถิติรวม
     */
    public function getStats(): array
    {
        return [
            'total_projects' => CarbonProject::count(),
            'active_projects' => CarbonProject::active()->count(),
            'total_credits_issued' => (float) CarbonProject::sum('total_credits'),
            'total_credits_available' => (float) CarbonProject::sum('available_credits'),
            'total_credits_retired' => (float) CarbonProject::sum('retired_credits'),
            'total_purchases' => CarbonCredit::count(),
            'total_revenue_usd' => (float) CarbonCredit::sum('price_paid_usd'),
            'unique_buyers' => CarbonCredit::distinct('owner_address')->count('owner_address'),
        ];
    }

    /**
     * Admin: ดึงทุกโปรเจกต์.
     */
    public function getAllProjects(?string $status = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = CarbonProject::withCount('credits')
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }
}

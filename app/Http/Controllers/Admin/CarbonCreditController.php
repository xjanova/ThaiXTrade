<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CarbonProject;
use App\Services\CarbonCreditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CarbonCreditController extends Controller
{
    public function __construct(
        private CarbonCreditService $carbonCreditService,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->query('status');
        $projects = $this->carbonCreditService->getAllProjects($status);
        $stats = $this->carbonCreditService->getStats();

        return Inertia::render('Admin/CarbonCredits/Index', [
            'projects' => $projects,
            'stats' => $stats,
            'currentStatus' => $status,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'location' => 'required|string|max:255',
            'country' => 'required|string|size:2',
            'project_type' => 'required|in:reforestation,renewable_energy,methane_capture,ocean_cleanup,carbon_capture,biodiversity,other',
            'standard' => 'required|string|max:50',
            'registry_id' => 'nullable|string|max:100',
            'total_credits' => 'required|numeric|min:1',
            'price_per_credit_usd' => 'required|numeric|min:0.01',
            'price_per_credit_tpix' => 'nullable|numeric|min:0',
            'vintage_year' => 'nullable|integer|min:2000|max:2050',
            'status' => 'in:draft,active',
            'is_featured' => 'boolean',
        ]);

        $validated['available_credits'] = $validated['total_credits'];

        CarbonProject::create($validated);

        return back()->with('success', 'Carbon project created successfully.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $project = CarbonProject::findOrFail($id);

        $validated = $request->validate([
            'name' => 'string|max:255',
            'description' => 'string|max:5000',
            'location' => 'string|max:255',
            'country' => 'string|size:2',
            'project_type' => 'in:reforestation,renewable_energy,methane_capture,ocean_cleanup,carbon_capture,biodiversity,other',
            'standard' => 'string|max:50',
            'registry_id' => 'nullable|string|max:100',
            'price_per_credit_usd' => 'numeric|min:0.01',
            'price_per_credit_tpix' => 'nullable|numeric|min:0',
            'vintage_year' => 'nullable|integer|min:2000|max:2050',
            'status' => 'in:draft,active,sold_out,expired,suspended',
            'is_featured' => 'boolean',
        ]);

        $project->update($validated);

        return back()->with('success', 'Carbon project updated successfully.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $project = CarbonProject::findOrFail($id);

        if ($project->credits()->exists()) {
            return back()->with('error', 'Cannot delete project with existing credits.');
        }

        $project->delete();

        return back()->with('success', 'Carbon project deleted.');
    }
}

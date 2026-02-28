<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * SupportController
 *
 * Manages customer support tickets in the admin panel.
 * Supports replying, status changes, and assignment to admin staff.
 */
class SupportController extends Controller
{
    /**
     * Display a paginated listing of support tickets with filters.
     */
    public function index(Request $request): InertiaResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:open,in_progress,waiting_reply,resolved,closed'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
            'category' => ['nullable', 'string', 'in:general,trading,withdrawal,deposit,technical,account,other'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = SupportTicket::with('assignedAdmin')
            ->withCount('messages')
            ->orderByDesc('created_at');

        if (! empty($validated['status'])) {
            $query->byStatus($validated['status']);
        }

        if (! empty($validated['priority'])) {
            $query->byPriority($validated['priority']);
        }

        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('wallet_address', 'like', "%{$search}%");
            });
        }

        $perPage = $validated['per_page'] ?? 20;
        $tickets = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Support/Index', [
            'tickets' => $tickets,
            'filters' => $validated,
        ]);
    }

    /**
     * Display a single support ticket with its messages.
     */
    public function show(SupportTicket $ticket): InertiaResponse
    {
        $ticket->load([
            'assignedAdmin',
            'messages' => function ($query) {
                $query->with('admin')->orderBy('created_at');
            },
        ]);

        $admins = AdminUser::active()->select('id', 'name', 'email', 'role')->get();

        return Inertia::render('Admin/Support/Show', [
            'ticket' => $ticket,
            'admins' => $admins,
        ]);
    }

    /**
     * Add an admin reply to a support ticket.
     */
    public function reply(Request $request, SupportTicket $ticket): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:10000'],
            'is_internal' => ['boolean'],
        ]);

        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'sender_type' => 'admin',
            'admin_id' => Auth::guard('admin')->id(),
            'message' => $validated['message'],
            'is_internal' => $validated['is_internal'] ?? false,
        ]);

        // Update ticket status to waiting_reply if currently open or in_progress
        if (in_array($ticket->status, ['open', 'in_progress'])) {
            $ticket->update(['status' => 'waiting_reply']);
        }

        return back()->with('success', 'Reply sent successfully.');
    }

    /**
     * Update the status of a support ticket.
     */
    public function updateStatus(Request $request, SupportTicket $ticket): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:open,in_progress,waiting_reply,resolved,closed'],
        ]);

        $ticket->update(['status' => $validated['status']]);

        // Add a system message about the status change
        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'sender_type' => 'system',
            'admin_id' => Auth::guard('admin')->id(),
            'message' => "Ticket status changed to {$validated['status']}.",
            'is_internal' => true,
        ]);

        return back()->with('success', 'Ticket status updated successfully.');
    }

    /**
     * Assign a support ticket to an admin user.
     */
    public function assign(Request $request, SupportTicket $ticket): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'assigned_to' => ['required', 'integer', 'exists:admin_users,id'],
        ]);

        $ticket->update(['assigned_to' => $validated['assigned_to']]);

        $assignedAdmin = AdminUser::find($validated['assigned_to']);

        // Add a system message about the assignment
        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'sender_type' => 'system',
            'admin_id' => Auth::guard('admin')->id(),
            'message' => "Ticket assigned to {$assignedAdmin->name}.",
            'is_internal' => true,
        ]);

        return back()->with('success', 'Ticket assigned successfully.');
    }
}

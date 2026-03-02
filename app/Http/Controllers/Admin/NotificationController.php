<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * NotificationController.
 *
 * Manages admin panel notifications: listing, marking as read, and unread count.
 */
class NotificationController extends Controller
{
    /**
     * Display paginated notifications for the current admin.
     */
    public function index(): InertiaResponse
    {
        $adminId = Auth::guard('admin')->id();

        $notifications = AdminNotification::forAdmin($adminId)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $unreadCount = AdminNotification::forAdmin($adminId)->unread()->count();

        return Inertia::render('Admin/Notifications/Index', [
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
        ]);
    }

    /**
     * Return the unread notification count as JSON (for polling).
     */
    public function unreadCount(): JsonResponse
    {
        $adminId = Auth::guard('admin')->id();
        $count = AdminNotification::forAdmin($adminId)->unread()->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(AdminNotification $notification): RedirectResponse
    {
        // Ensure the notification belongs to the current admin
        if ($notification->admin_user_id !== Auth::guard('admin')->id()) {
            abort(403);
        }

        $notification->markAsRead();

        return back()->with('success', 'Notification marked as read.');
    }

    /**
     * Mark all notifications as read for the current admin.
     */
    public function markAllAsRead(): RedirectResponse
    {
        $adminId = Auth::guard('admin')->id();
        AdminNotification::markAllAsRead($adminId);

        return back()->with('success', 'All notifications marked as read.');
    }
}

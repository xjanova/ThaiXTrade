<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\AdminUser;
use App\Models\SupportTicket;

/**
 * AdminNotificationService.
 *
 * Centralized service for creating admin notifications.
 * Used by controllers to notify admins of important events.
 */
class AdminNotificationService
{
    /**
     * Send a notification to a specific admin user.
     */
    public function notify(int $adminId, string $type, string $title, string $message, ?array $data = null): AdminNotification
    {
        return AdminNotification::create([
            'admin_user_id' => $adminId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * Send a notification to all active admin users.
     */
    public function notifyAll(string $type, string $title, string $message, ?array $data = null): void
    {
        $adminIds = AdminUser::active()->pluck('id');

        foreach ($adminIds as $adminId) {
            $this->notify($adminId, $type, $title, $message, $data);
        }
    }

    /**
     * Send a notification to all active admins with a specific role.
     */
    public function notifyRole(string $role, string $type, string $title, string $message, ?array $data = null): void
    {
        $adminIds = AdminUser::active()->byRole($role)->pluck('id');

        foreach ($adminIds as $adminId) {
            $this->notify($adminId, $type, $title, $message, $data);
        }
    }

    /**
     * Notify when a new support ticket is created.
     */
    public function ticketCreated(SupportTicket $ticket): void
    {
        $this->notifyAll(
            'ticket_new',
            'New Support Ticket',
            "New ticket #{$ticket->ticket_number}: {$ticket->subject}",
            [
                'ticket_id' => $ticket->id,
                'url' => "/admin/support/{$ticket->id}",
            ]
        );
    }

    /**
     * Notify assigned admin when a ticket receives a user reply.
     */
    public function ticketReplied(SupportTicket $ticket): void
    {
        if ($ticket->assigned_to) {
            $this->notify(
                $ticket->assigned_to,
                'ticket_reply',
                'New Ticket Reply',
                "User replied to ticket #{$ticket->ticket_number}: {$ticket->subject}",
                [
                    'ticket_id' => $ticket->id,
                    'url' => "/admin/support/{$ticket->id}",
                ]
            );
        }
    }

    /**
     * Notify admin when a ticket is assigned to them.
     */
    public function ticketAssigned(SupportTicket $ticket, AdminUser $assignedAdmin): void
    {
        $this->notify(
            $assignedAdmin->id,
            'ticket_assigned',
            'Ticket Assigned to You',
            "Ticket #{$ticket->ticket_number} has been assigned to you: {$ticket->subject}",
            [
                'ticket_id' => $ticket->id,
                'url' => "/admin/support/{$ticket->id}",
            ]
        );
    }
}

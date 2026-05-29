<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $event = trim((string) $request->query('event', 'all')) ?: 'all';
        $userId = $request->integer('user_id') ?: null;
        $search = trim((string) $request->query('q', ''));
        $from = $request->date('from');
        $to = $request->date('to');

        $logs = AuditLog::query()
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->when($event !== 'all', fn ($query) => $query->where('event', $event))
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->when($from !== null, fn ($query) => $query->where('created_at', '>=', $from->startOfDay()))
            ->when($to !== null, fn ($query) => $query->where('created_at', '<=', $to->endOfDay()))
            ->when($search !== '', fn ($query) => $this->applySearch($query, $search))
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'user' => $log->user
                    ? ['id' => $log->user->id, 'name' => $log->user->name]
                    : null,
                'user_name' => $log->user?->name ?? $log->user_name,
                'event' => $log->event,
                'description' => $log->description,
                'subject' => $this->subjectLabel($log),
                'properties' => $log->properties,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('AuditLogs/Index', [
            'logs' => $logs,
            'filter' => [
                'event' => $event,
                'user_id' => $userId,
                'q' => $search,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'filterOptions' => [
                'events' => AuditLog::query()
                    ->select('event')
                    ->distinct()
                    ->orderBy('event')
                    ->pluck('event')
                    ->values(),
                'users' => User::query()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name]),
            ],
        ]);
    }

    private function applySearch($query, string $search): void
    {
        $like = '%'.mb_strtolower($search).'%';

        $query->where(function ($query) use ($like) {
            $query
                ->whereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(user_name, \'\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(ip_address, \'\')) LIKE ?', [$like])
                ->orWhereHas('user', fn ($query) => $query
                    ->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like]));
        });
    }

    private function subjectLabel(AuditLog $log): ?string
    {
        if ($log->auditable_type === null) {
            return null;
        }

        return class_basename($log->auditable_type).($log->auditable_id ? ' #'.$log->auditable_id : '');
    }
}

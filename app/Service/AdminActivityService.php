<?php

namespace App\Service;

use App\Http\Requests\Admin\AuditLogRequest;
use App\Models\User;
use App\Response\ResponseModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AdminActivityService
{
    public function __construct(private AuditDatabase $database) {}

    public function RequestHistory(AuditLogRequest $request): ResponseModel
    {
        $query = $this->database->Connection()->table('admin_api_audits');
        foreach (['actorUserId' => 'user_id', 'requestId' => 'request_id', 'method' => 'method', 'statusCode' => 'status_code'] as $field => $column) {
            if ($request->filled($field)) {
                $query->where($column, $request->validated($field));
            }
        }
        if ($request->filled('search')) {
            $query->where('path', 'like', '%'.$request->validated('search').'%');
        }
        foreach (['fromDate' => '>=', 'toDate' => '<='] as $field => $operator) {
            if ($request->filled($field)) {
                $query->whereDate('created_at', $operator, $request->validated($field));
            }
        }
        $records = $query->latest('created_at')->latest('id')->paginate($request->integer('perPage', 20));

        return ResponseModel::ok('Admin API audit logs fetched successfully!', [
            'items' => $records->items(),
            'pagination' => ['currentPage' => $records->currentPage(), 'lastPage' => $records->lastPage(), 'perPage' => $records->perPage(), 'total' => $records->total()],
        ]);
    }

    /** @param array<string, mixed> $details */
    public function Record(User $user, string $action, string $targetType, ?string $targetId, array $details = []): void
    {
        if (! $user->isAdministrator()) {
            return;
        }
        $record = [
            'id' => (string) Str::uuid7(), 'user_id' => $user->id, 'action' => $action,
            'actor_role_id' => $user->role_id,
            'target_type' => $targetType, 'target_id' => $targetId,
            'details' => json_encode([...$details, 'requestId' => app()->bound('request') ? request()->attributes->get('http_request_id') : null], JSON_THROW_ON_ERROR),
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ];
        DB::connection()->afterCommit(function () use ($record): void {
            try {
                $this->database->Connection()->table('admin_activities')->insert($record);
            } catch (Throwable $exception) {
                Log::channel('http')->error('Admin activity audit persistence failed', [
                    'user_id' => $record['user_id'], 'action' => $record['action'], 'target_id' => $record['target_id'],
                    'request_id' => app()->bound('request') ? request()->attributes->get('http_request_id') : null,
                    'exception_type' => $exception::class,
                ]);
            }
        });
    }
}

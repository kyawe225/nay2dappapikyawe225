<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogRequest;
use App\Service\AdminActivityService;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    public function __construct(private AdminActivityService $activities) {}

    public function Index(AuditLogRequest $request): JsonResponse
    {
        $response = $this->activities->RequestHistory($request);

        return response()->json($response, $response->status);
    }
}

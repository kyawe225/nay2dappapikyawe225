<?php

namespace App\Service;

use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\SupportRequest;
use App\Http\Resources\SupportResource;
use App\Http\Resources\SupportTypeResource;
use App\Http\ServiceContractor\IAdminManagementService;
use App\Http\ServiceContractor\ISupportService;
use App\Models\Support;
use App\Models\SupportType;
use App\Models\User;
use App\Response\ResponseModel;

class SupportService implements ISupportService
{
    public function __construct(private IAdminManagementService $management) {}

    public function AdminIndex(AdminListRequest $request): ResponseModel
    {
        $response = $this->management->Index('supports', $request);
        $response->data['supportTypes'] = SupportTypeResource::collection(SupportType::query()->orderBy('name')->orderBy('id')->get());

        return $response;
    }

    public function AdminShow(string $recordId): ResponseModel
    {
        $record = Support::query()->with('supportType')->find($recordId);

        return $record === null ? ResponseModel::NotFound('Support was not found!', null)
            : ResponseModel::ok('Support fetched successfully!', new SupportResource($record));
    }

    public function AdminCreate(SupportRequest $request, User $actor): ResponseModel
    {
        return $this->management->Save('supports', $request, $actor);
    }

    public function AdminUpdate(SupportRequest $request, string $recordId, User $actor): ResponseModel
    {
        return $this->management->Save('supports', $request, $actor, $recordId);
    }

    public function AdminDelete(string $recordId, User $actor): ResponseModel
    {
        return $this->management->Delete('supports', $recordId, $actor);
    }

    public function Index(): ResponseModel
    {
        $supports = Support::with(['supportType'])
            ->where('status', 'active')
            ->get();

        return ResponseModel::ok(
            'fetched the support data!',
            SupportResource::collection($supports),
        );
    }
}

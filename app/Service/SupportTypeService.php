<?php

namespace App\Service;

use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\SupportTypeRequest;
use App\Http\Resources\SupportTypeResource;
use App\Http\ServiceContractor\IAdminManagementService;
use App\Http\ServiceContractor\ISupportTypeService;
use App\Models\SupportType;
use App\Models\User;
use App\Response\ResponseModel;

class SupportTypeService implements ISupportTypeService
{
    public function __construct(private IAdminManagementService $management) {}

    public function Index(AdminListRequest $request): ResponseModel
    {
        return $this->management->Index('support-types', $request);
    }

    public function Show(string $recordId): ResponseModel
    {
        $record = SupportType::query()->find($recordId);

        return $record === null ? ResponseModel::NotFound('Support type was not found!', null)
            : ResponseModel::ok('Support type fetched successfully!', new SupportTypeResource($record));
    }

    public function Create(SupportTypeRequest $request, User $actor): ResponseModel
    {
        return $this->management->Save('support-types', $request, $actor);
    }

    public function Update(SupportTypeRequest $request, string $recordId, User $actor): ResponseModel
    {
        return $this->management->Save('support-types', $request, $actor, $recordId);
    }

    public function Delete(string $recordId, User $actor): ResponseModel
    {
        return $this->management->Delete('support-types', $recordId, $actor);
    }
}

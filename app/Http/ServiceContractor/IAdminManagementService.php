<?php

namespace App\Http\ServiceContractor;

use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\MasterDataRequest;
use App\Models\User;
use App\Response\ResponseModel;

interface IAdminManagementService
{
    public function Index(string $resource, AdminListRequest $request): ResponseModel;

    public function Save(string $resource, MasterDataRequest $request, User $user, ?string $recordId = null): ResponseModel;

    public function Delete(string $resource, string $recordId, User $user): ResponseModel;

    public function Dashboard(): ResponseModel;

    public function Activities(AdminListRequest $request): ResponseModel;

    public function CustomerHistory(string $userId, AdminListRequest $request): ResponseModel;
}

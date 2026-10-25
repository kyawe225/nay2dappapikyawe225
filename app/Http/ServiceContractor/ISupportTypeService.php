<?php

namespace App\Http\ServiceContractor;

use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\SupportTypeRequest;
use App\Models\User;
use App\Response\ResponseModel;

interface ISupportTypeService
{
    public function Index(AdminListRequest $request): ResponseModel;

    public function Show(string $recordId): ResponseModel;

    public function Create(SupportTypeRequest $request, User $actor): ResponseModel;

    public function Update(SupportTypeRequest $request, string $recordId, User $actor): ResponseModel;

    public function Delete(string $recordId, User $actor): ResponseModel;
}

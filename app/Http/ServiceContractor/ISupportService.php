<?php

namespace App\Http\ServiceContractor;

use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\SupportRequest;
use App\Models\User;
use App\Response\ResponseModel;

interface ISupportService
{
    public function AdminIndex(AdminListRequest $request): ResponseModel;

    public function AdminShow(string $recordId): ResponseModel;

    public function AdminCreate(SupportRequest $request, User $actor): ResponseModel;

    public function AdminUpdate(SupportRequest $request, string $recordId, User $actor): ResponseModel;

    public function AdminDelete(string $recordId, User $actor): ResponseModel;

    public function Index(): ResponseModel;
}

<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use Illuminate\Http\Request;

class UserController extends ApiController
{
    public function show(Request $request)
    {
        return $this->success($request->user());
    }
}

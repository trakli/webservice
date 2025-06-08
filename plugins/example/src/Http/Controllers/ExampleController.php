<?php

namespace Trakli\Example\Http\Controllers;

use App\Http\Controllers\Controller;

class ExampleController extends Controller
{
    public function index()
    {
        return response()->json([
            'message' => 'Hello from Example Plugin!',
            'version' => '1.0.0',
        ]);
    }

    /**
     * Get protected data that requires authentication
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function protectedData()
    {
        $user = auth()->user();

        return response()->json([
            'message' => 'This is protected data from Example Plugin!',
            'user_id' => $user->id,
            'user_name' => $user->name,
            'timestamp' => now()->toDateTimeString(),
        ]);
    }
}

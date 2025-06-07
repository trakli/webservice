<?php

namespace Trakli\ExamplePlugin\Http\Controllers;

use Illuminate\Routing\Controller;

class ExampleController extends Controller
{
    public function index()
    {
        return response()->json([
            'message' => 'Hello from Example Plugin!',
            'version' => '1.0.0',
        ]);
    }
}

<?php

use Illuminate\Support\Facades\Route;
use Trakli\ExamplePlugin\Http\Controllers\ExampleController;

Route::get('/', [ExampleController::class, 'index']);

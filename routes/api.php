<?php

use App\Http\Controllers\Api\V1\EnrollController;
use App\Http\Controllers\Api\V1\HeartbeatController;
use Illuminate\Support\Facades\Route;

Route::post('v1/heartbeats', HeartbeatController::class)
    ->middleware(['auth:sanctum', 'throttle:60,1'])
    ->name('api.v1.heartbeats');

Route::post('v1/enroll', EnrollController::class)
    ->middleware(['throttle:20,1', 'throttle:enroll-global'])
    ->name('api.v1.enroll');

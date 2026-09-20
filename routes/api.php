<?php

use App\Http\Controllers\Api\V1\EnrollController;
use App\Http\Controllers\Api\V1\HeartbeatController;
use App\Http\Controllers\Api\V1\LeadsController;
use Illuminate\Support\Facades\Route;

Route::post('v1/heartbeats', HeartbeatController::class)
    ->middleware(['auth:sanctum', 'throttle:60,1'])
    ->name('api.v1.heartbeats');

Route::post('v1/enroll', EnrollController::class)
    ->middleware(['throttle:20,1', 'throttle:enroll-global'])
    ->name('api.v1.enroll');

Route::post('v1/leads', LeadsController::class)
    ->middleware(['throttle:10,1', 'throttle:leads-global'])
    ->name('api.v1.leads');

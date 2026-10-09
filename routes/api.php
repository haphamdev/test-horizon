<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::post('/notifications', [NotificationController::class, 'store']);
Route::get('/notifications/{notification}', [NotificationController::class, 'show']);
Route::patch('/notifications/{notification}', [NotificationController::class, 'update']);
Route::post('/notifications/{notification}/cancel', [NotificationController::class, 'cancel']);

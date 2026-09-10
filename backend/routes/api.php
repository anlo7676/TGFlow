<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\NodeController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\TelegramController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::post('/telegram/webhook', [TelegramController::class, 'webhook'])->middleware('throttle:120,1');
Route::post('/payment/epay/notify', [PaymentController::class, 'notify'])->middleware('throttle:120,1');

Route::prefix('admin')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::middleware(['auth:sanctum', 'audit.admin'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/dashboard', DashboardController::class)->middleware('admin.role:super_admin,operation,finance,support,viewer');
        Route::get('/plans', [PlanController::class, 'index'])->middleware('admin.role:super_admin,operation,finance,support,viewer');
        Route::post('/plans', [PlanController::class, 'store'])->middleware('admin.role:super_admin,operation');
        Route::put('/plans/{plan}', [PlanController::class, 'update'])->middleware('admin.role:super_admin,operation');
        Route::delete('/plans/{plan}', [PlanController::class, 'destroy'])->middleware('admin.role:super_admin,operation');
        Route::get('/subscriptions', [SubscriptionController::class, 'index'])->middleware('admin.role:super_admin,operation,finance,support,viewer');
        Route::get('/subscriptions/{subscription}', [SubscriptionController::class, 'show'])->middleware('admin.role:super_admin,operation,finance,support,viewer');
        Route::post('/subscriptions/{subscription}/disable', [SubscriptionController::class, 'disable'])->middleware('admin.role:super_admin,operation');
        Route::post('/subscriptions/{subscription}/enable', [SubscriptionController::class, 'enable'])->middleware('admin.role:super_admin,operation');
        Route::post('/subscriptions/{subscription}/traffic', [SubscriptionController::class, 'addTraffic'])->middleware('admin.role:super_admin,operation,support');
        Route::post('/subscriptions/{subscription}/extend', [SubscriptionController::class, 'extend'])->middleware('admin.role:super_admin,operation,support');
        Route::get('/servers', [ServerController::class, 'index'])->middleware('admin.role:super_admin,operation,viewer');
        Route::post('/servers', [ServerController::class, 'store'])->middleware('admin.role:super_admin,operation');
        Route::put('/servers/{server}', [ServerController::class, 'update'])->middleware('admin.role:super_admin,operation');
        Route::post('/servers/{server}/disable', [ServerController::class, 'disable'])->middleware('admin.role:super_admin,operation');
        Route::post('/servers/{server}/deploy', [ServerController::class, 'deploy'])->middleware('admin.role:super_admin,operation');
        Route::get('/nodes', [NodeController::class, 'index'])->middleware('admin.role:super_admin,operation,viewer');
        Route::post('/nodes', [NodeController::class, 'store'])->middleware('admin.role:super_admin,operation');
        Route::put('/nodes/{node}', [NodeController::class, 'update'])->middleware('admin.role:super_admin,operation');
    });
});

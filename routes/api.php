<?php

use App\Http\Controllers\ErpController;
use App\Http\Controllers\GroupIntakeWebhookController;
use App\Http\Controllers\InboundReplyController;
use Illuminate\Support\Facades\Route;

Route::post('intake/group-requests', GroupIntakeWebhookController::class)
    ->middleware('throttle:30,1')
    ->name('api.intake.group-requests');
Route::post('intake/replies', [InboundReplyController::class, 'webhook'])->middleware('throttle:30,1')->name('api.intake.replies');
Route::post('intake/replies/heartbeat', [InboundReplyController::class, 'heartbeat'])->middleware('throttle:60,1,mailbox')->name('api.intake.replies.heartbeat');

Route::prefix('erp/v1')->middleware('throttle:60,1,erp')->group(function () {
    Route::get('bookings', [ErpController::class, 'bookings'])->name('api.erp.bookings');
    Route::post('deals/{deal}/payments/{kind}', [ErpController::class, 'payment'])
        ->whereNumber('deal')->whereIn('kind', ['deposit', 'balance'])->name('api.erp.payment');
});

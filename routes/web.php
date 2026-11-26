<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CustomPipelineController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\DealPaymentController;
use App\Http\Controllers\GroupRequestController;
use App\Http\Controllers\GuestListController;
use App\Http\Controllers\HospitalityController;
use App\Http\Controllers\InboundReplyController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\SalesReportController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\WorkController;
use App\Http\Middleware\RestrictLodgingStaff;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::middleware([...(config('crm.require_verified_email') ? ['auth', 'verified'] : ['auth']), RestrictLodgingStaff::class])->group(function () {
    Route::get('work', [WorkController::class, 'index'])->name('work.index');
    Route::patch('work/settings', [WorkController::class, 'settings'])->name('work.settings');
    Route::post('work/{group}/contact', [WorkController::class, 'contact'])->whereNumber('group')->name('work.contact');
    Route::get('hospitality', [HospitalityController::class, 'index'])->name('hospitality.index');
    Route::get('inbox', [InboundReplyController::class, 'index'])->name('inbound-replies.index');
    Route::post('inbox', [InboundReplyController::class, 'store'])->name('inbound-replies.store');
    Route::patch('inbox/{reply}', [InboundReplyController::class, 'link'])->whereNumber('reply')->name('inbound-replies.link');
    Route::post('hospitality/categories', [HospitalityController::class, 'category'])->name('hospitality.category');
    Route::patch('hospitality/capacity', [HospitalityController::class, 'capacity'])->name('hospitality.capacity');
    Route::patch('hospitality/rules', [HospitalityController::class, 'rules'])->name('hospitality.rules');
    Route::post('hospitality/reserves', [HospitalityController::class, 'reserve'])->name('hospitality.reserve');
    Route::patch('hospitality/reserves/{allocation}', [HospitalityController::class, 'allocation'])->whereNumber('allocation')->name('hospitality.allocation');
    Route::patch('hospitality/bookings/{allocation}', [HospitalityController::class, 'amend'])->whereNumber('allocation')->name('hospitality.amend');
    Route::patch('hospitality/bookings/{allocation}/handoff', [HospitalityController::class, 'handoff'])->whereNumber('allocation')->name('hospitality.handoff');
    Route::get('hospitality/bookings/{allocation}/guests', [GuestListController::class, 'show'])->whereNumber('allocation')->name('hospitality.guests');
    Route::post('hospitality/bookings/{allocation}/guests/preview', [GuestListController::class, 'preview'])->whereNumber('allocation')->name('hospitality.guests.preview');
    Route::post('hospitality/bookings/{allocation}/guests', [GuestListController::class, 'store'])->whereNumber('allocation')->name('hospitality.guests.store');
    Route::post('hospitality/suggestions', [HospitalityController::class, 'suggestions'])->middleware('throttle:12,1')->name('hospitality.suggestions');
    Route::post('deals/{deal}/proposals/{proposal}/email', [HospitalityController::class, 'email'])->whereNumber(['deal', 'proposal'])->name('deals.proposal.email');
    Route::patch('deals/{deal}/payments/{kind}', [DealPaymentController::class, 'update'])->whereNumber('deal')->name('deals.payments');
    Route::get('pipelines', [CustomPipelineController::class, 'index'])->name('pipelines.index');
    Route::post('pipelines', [CustomPipelineController::class, 'save'])->name('pipelines.store');
    Route::prefix('pipelines/{pipeline}')->whereNumber('pipeline')->controller(CustomPipelineController::class)->name('pipelines.')->group(function () {
        Route::get('/', 'show')->name('show');
        Route::patch('/', 'save')->name('update');
        Route::post('deals', 'attach')->name('attach');
        Route::patch('deals/{deal}', 'move')->whereNumber('deal')->name('move');
    });
    Route::get('team', [TeamController::class, 'index'])->name('team.index');
    Route::post('team/invitations', [TeamController::class, 'invite'])->name('team.invite');
    Route::get('team/invitations/{token}', [TeamController::class, 'invitation'])->whereAlphaNumeric('token')->name('team.invitation');
    Route::post('team/invitations/{token}', [TeamController::class, 'accept'])->whereAlphaNumeric('token')->name('team.accept');
    Route::delete('team/members/{member}', [TeamController::class, 'remove'])->whereNumber('member')->name('team.remove');
    Route::get('catalog', [CatalogController::class, 'index'])->name('catalog.index');
    Route::post('catalog', [CatalogController::class, 'store'])->name('catalog.store');
    Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
    Route::get('clients/{company}', [ClientController::class, 'show'])->whereNumber('company')->name('clients.show');
    Route::post('clients/{company}/merge', [ClientController::class, 'merge'])->whereNumber('company')->name('clients.merge');
    Route::post('clients/import', [ClientController::class, 'import'])->name('clients.import');
    Route::get('group-requests', [GroupRequestController::class, 'index'])->name('group-requests.index');
    Route::post('group-requests', [GroupRequestController::class, 'store'])->name('group-requests.store');
    Route::patch('group-requests/{group}/qualify', [GroupRequestController::class, 'qualify'])->whereNumber('group')->name('group-requests.qualify');
    Route::patch('group-requests/{group}/assignee', [GroupRequestController::class, 'assign'])->whereNumber('group')->name('group-requests.assign');
    Route::post('group-requests/{group}/convert', [GroupRequestController::class, 'convert'])->whereNumber('group')->name('group-requests.convert');
    Route::get('dashboard', [PipelineController::class, 'index'])->name('dashboard');
    Route::post('deals', [PipelineController::class, 'store'])->name('deals.store');
    Route::patch('deals/{deal}/stage', [PipelineController::class, 'move'])->whereNumber('deal')->name('deals.move');
    Route::patch('deals/{deal}/assignee', [PipelineController::class, 'assign'])->whereNumber('deal')->name('deals.assign');
    Route::patch('pipeline/settings', [PipelineController::class, 'settings'])->name('pipeline.settings');
    Route::get('reports', SalesReportController::class)->name('reports');
    Route::get('tasks', TaskController::class)->name('tasks');
    Route::get('tasks/{task}/calendar', [TaskController::class, 'calendar'])->whereNumber('task')->name('tasks.calendar');
    Route::prefix('deals/{deal}')->whereNumber('deal')->controller(DealController::class)->name('deals.')->group(function () {
        Route::get('/', 'show')->name('show');
        Route::patch('/', 'update')->name('update');
        Route::post('contacts', 'contact')->name('contact');
        Route::post('activities', 'activity')->name('activity');
        Route::patch('activities/{activity}', 'complete')->whereNumber('activity')->name('complete');
        Route::post('estimate', 'estimate')->middleware('throttle:20,1')->name('estimate');
        Route::post('proposals', 'proposal')->name('proposal');
        Route::patch('proposals/{proposal}', 'proposalStatus')->whereNumber('proposal')->name('proposal.status');
        Route::get('proposals/{proposal}/pdf', 'proposalDocument')->whereNumber('proposal')->name('proposal.pdf');
        Route::get('proposals/{proposal}/draft', 'proposalDraft')->whereNumber('proposal')->name('proposal.draft');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

<?php

use Illuminate\Support\Facades\Route;
use Src\Finance\CreditNote\Controllers\CreditNoteController;

Route::prefix('api/v1/finance')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('credit-notes', [CreditNoteController::class, 'index'])
            ->middleware('permission:credit-notes.view');

        Route::post('credit-notes', [CreditNoteController::class, 'store'])
            ->middleware('permission:credit-notes.issue');

        Route::get('credit-notes/{creditNote}', [CreditNoteController::class, 'show'])
            ->whereNumber('creditNote')
            ->middleware('permission:credit-notes.view');

        Route::get('credit-notes/{creditNote}/download/{type}', [CreditNoteController::class, 'download'])
            ->whereNumber('creditNote')
            ->middleware('permission:credit-notes.view');
    });

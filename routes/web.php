<?php

use App\Http\Controllers\SubdomainLookupController;
use Illuminate\Support\Facades\Route;

Route::domain(config('app.central_domain', 'pim.test'))->group(function (): void {
    Route::get('/', [SubdomainLookupController::class, 'show'])->name('subdomain.show');
    Route::post('/lookup', [SubdomainLookupController::class, 'lookup'])->name('subdomain.lookup');
});

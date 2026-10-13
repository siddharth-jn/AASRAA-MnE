<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/projects', [PortalController::class, 'projects'])->name('projects.index');
    Route::get('/projects/{project}', [PortalController::class, 'project'])->name('projects.show');
    Route::get('/centres', [PortalController::class, 'centres'])->name('centres.index');
    Route::get('/centres/{centre}', [PortalController::class, 'centre'])->name('centres.show');
    Route::get('/data-entry', [EntryController::class, 'index'])->name('entries.index');
    Route::get('/data-entry/{assignment}', [EntryController::class, 'edit'])->name('entries.edit');
    Route::post('/data-entry/{assignment}', [EntryController::class, 'store'])->name('entries.store');
    Route::get('/approvals', [EntryController::class, 'approvals'])->name('approvals.index');
    Route::post('/approvals/{entry}', [EntryController::class, 'review'])->name('approvals.review');
    Route::get('/evidence/{evidence}', [EntryController::class, 'evidence'])->name('evidence.download');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});

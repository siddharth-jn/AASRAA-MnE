<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DataQualityController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\KpiController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
    Route::get('/forgot-password', [PasswordController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordController::class, 'update'])->name('password.update');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/projects', [PortalController::class, 'projects'])->name('projects.index');
    Route::get('/projects/{project}', [PortalController::class, 'project'])->name('projects.show');
    Route::get('/projects/{project}/indicators/{indicator}', [KpiController::class, 'show'])->name('kpis.show');
    Route::get('/centres', [PortalController::class, 'centres'])->name('centres.index');
    Route::get('/centres/{centre}', [PortalController::class, 'centre'])->name('centres.show');
    Route::get('/data-entry', [EntryController::class, 'index'])->name('entries.index');
    Route::get('/data-entry/{assignment}', [EntryController::class, 'edit'])->name('entries.edit');
    Route::post('/data-entry/{assignment}', [EntryController::class, 'store'])->name('entries.store');
    Route::get('/approvals', [EntryController::class, 'approvals'])->name('approvals.index');
    Route::post('/approvals/{entry}', [EntryController::class, 'review'])->name('approvals.review');
    Route::get('/evidence/{evidence}', [EntryController::class, 'evidence'])->name('evidence.download');
    Route::get('/evidence/{evidence}/preview', [EntryController::class, 'previewEvidence'])->name('evidence.preview');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
    Route::get('/reports/excel', [ReportController::class, 'excel'])->name('reports.excel');
    Route::get('/data-quality', [DataQualityController::class, 'index'])->name('data-quality.index');
    Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
    Route::post('/imports', [ImportController::class, 'upload'])->name('imports.upload');
    Route::get('/imports/template/{project}', [ImportController::class, 'template'])->name('imports.template');
    Route::get('/imports/{batch}', [ImportController::class, 'show'])->name('imports.show');
    Route::post('/imports/{batch}/validate', [ImportController::class, 'validateImport'])->name('imports.validate');
    Route::post('/imports/{batch}/confirm', [ImportController::class, 'confirm'])->name('imports.confirm');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{notification}', [NotificationController::class, 'read'])->name('notifications.read');
    Route::get('/profile/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::patch('/profile/password', [PasswordController::class, 'change'])->name('password.change');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});

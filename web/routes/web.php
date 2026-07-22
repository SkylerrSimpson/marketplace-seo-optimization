<?php

use App\Http\Controllers\Admin\ActivityController as AdminActivityController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\ConnectionCheckController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OAuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RunConfirmationController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\ScheduledRunController;
use App\Http\Controllers\ScriptController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'))->middleware('auth');

// 'verified' gates the whole app: a self-registered user can sign in but can't
// use anything until they click the email verification link (they're bounced to
// the verify-email prompt). The prompt/resend/logout routes live in the 'auth'-only
// group in routes/auth.php so an unverified user can still complete verification.
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::patch('/settings/theme', [SettingsController::class, 'updateTheme'])->name('settings.theme');

    Route::get('/credentials', [CredentialController::class, 'index'])->name('credentials.index');
    Route::get('/credentials/new', [CredentialController::class, 'newForm'])->name('credentials.new');

    // In-browser OAuth connect (Shopify only for now) — see OAuthController.
    Route::get('/oauth/{marketplace}/authorize', [OAuthController::class, 'authorize'])->name('oauth.authorize');
    Route::get('/oauth/{marketplace}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');
    Route::get('/credentials/{marketplace}/{account}/edit', [CredentialController::class, 'edit'])->name('credentials.edit');
    Route::put('/credentials/{marketplace}/{account}', [CredentialController::class, 'update'])->name('credentials.update');
    Route::delete('/credentials/{marketplace}/{account}', [CredentialController::class, 'destroy'])->name('credentials.destroy');

    Route::get('/scripts', [ScriptController::class, 'index'])->name('scripts.index');
    Route::get('/scripts/{slug}', [ScriptController::class, 'show'])->name('scripts.show');
    Route::post('/scripts/{slug}/run', [RunController::class, 'store'])->name('scripts.run');
    Route::get('/scripts/{slug}/reference/{index}/{account?}', [ScriptController::class, 'downloadReference'])
        ->where('index', '[0-9]+')
        ->name('scripts.reference.download');

    Route::get('/runs', [RunController::class, 'index'])->name('runs.index');
    Route::get('/runs/{run}', [RunController::class, 'show'])->name('runs.show');
    Route::get('/runs/{run}/files/{filename}', [RunController::class, 'download'])->name('runs.download');
    Route::get('/runs/{run}/output', [RunController::class, 'output'])->name('runs.output');
    Route::post('/runs/{run}/cancel', [RunController::class, 'cancel'])->name('runs.cancel');
    Route::post('/runs/{run}/resume', [RunController::class, 'resume'])->name('runs.resume');
    Route::get('/runs/{run}/confirm', [RunConfirmationController::class, 'create'])->name('runs.confirm.create');
    Route::post('/runs/{run}/confirm', [RunConfirmationController::class, 'store'])->name('runs.confirm.store');

    Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
    Route::get('/backups/{account}/output/{filename}', [BackupController::class, 'downloadOutput'])->name('backups.download-output');
    Route::get('/backups/{account}/{backupName}/{filename}', [BackupController::class, 'download'])->name('backups.download');

    Route::get('/connection-check/{marketplace}', [ConnectionCheckController::class, 'show'])->name('connection-check.show');

    // Admin-only: user management + the cross-user activity audit. Guarded by the
    // 'admin' middleware (is_admin); everything else in this app stays per-user.
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
        Route::patch('/users/{user}/admin', [AdminUserController::class, 'toggleAdmin'])->name('users.toggle-admin');
        Route::patch('/users/{user}/active', [AdminUserController::class, 'toggleActive'])->name('users.toggle-active');
        Route::patch('/users/{user}/verify', [AdminUserController::class, 'markVerified'])->name('users.verify');
        Route::get('/runs', [AdminActivityController::class, 'index'])->name('runs.index');
    });

    // Scheduled (automated) runs — read-type scripts only; never a live write.
    Route::get('/scheduled', [ScheduledRunController::class, 'index'])->name('scheduled.index');
    Route::post('/scheduled', [ScheduledRunController::class, 'store'])->name('scheduled.store');
    Route::patch('/scheduled/{scheduledRun}/toggle', [ScheduledRunController::class, 'toggle'])->name('scheduled.toggle');
    Route::delete('/scheduled/{scheduledRun}', [ScheduledRunController::class, 'destroy'])->name('scheduled.destroy');
});

require __DIR__.'/auth.php';

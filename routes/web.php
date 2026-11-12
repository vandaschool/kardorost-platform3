<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\LearningController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\TeachingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'panel' : 'login'))->name('home');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::get('/register', fn (Request $r, AuthController $c) => $c->show($r, 'register'))->name('register');
    Route::get('/forgot-password', fn (Request $r, AuthController $c) => $c->show($r, 'forgot-password'))->name('forgot-password');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::post('/auth/send-code', [AuthController::class, 'send'])->middleware('throttle:10,10');
    Route::post('/auth/verify-code', [AuthController::class, 'verify'])->middleware('throttle:15,1');
    Route::post('/auth/complete', [AuthController::class, 'complete'])->middleware('throttle:10,1');
    Route::post('/auth/restart', [AuthController::class, 'restart']);
});
Route::get('/payments/callback', [EnrollmentController::class, 'callback'])->middleware('throttle:60,1')->name('payments.callback');
Route::middleware(['auth', 'account.active', 'auth.session'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/panel', [PanelController::class, 'home'])->name('panel');
    Route::post('/panel/role', [PanelController::class, 'switch']);
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::get('/submissions/{submission}/file', [SubmissionController::class, 'file'])->name('submissions.file');
    Route::get('/teaching/{course}', [TeachingController::class, 'show'])->name('teaching.course');
    Route::post('/teaching/{course}/sections', [TeachingController::class, 'section']);
    Route::post('/teaching/{course}/lessons', [TeachingController::class, 'lesson']);
    Route::patch('/teaching/{course}/lessons/{lesson}', [TeachingController::class, 'lesson']);
    Route::post('/teaching/{course}/cohorts/{cohort}/sessions', [TeachingController::class, 'session']);
    Route::patch('/teaching/{course}/cohorts/{cohort}/sessions/{session}', [TeachingController::class, 'session']);
    Route::post('/teaching/{course}/cohorts/{cohort}/assignments', [TeachingController::class, 'assignment']);
    Route::patch('/teaching/{course}/cohorts/{cohort}/assignments/{assignment}', [TeachingController::class, 'assignment']);
    Route::post('/teaching/{course}/sessions/{session}/attendance', [TeachingController::class, 'attendance']);
    Route::patch('/submissions/{submission}/review', [SubmissionController::class, 'review'])->middleware('throttle:30,1');
    Route::get('/account', [PanelController::class, 'profile'])->name('account');
    Route::patch('/account', [PanelController::class, 'updateProfile']);
    Route::patch('/account/password', [PanelController::class, 'changePassword'])->middleware('throttle:5,1');
    Route::middleware('panel.role:student')->group(function () {
        Route::get('/student', fn (Request $r, PanelController $c) => $c->dashboard($r, 'student'))->name('panel.student');
        Route::get('/learning', [PanelController::class, 'learning'])->name('learning');
        Route::get('/learning/{enrollment}', [LearningController::class, 'show'])->name('learning.room');
        Route::post('/learning/{enrollment}/lessons/{lesson}/progress', [LearningController::class, 'progress'])->middleware('throttle:120,1');
        Route::get('/learning/{enrollment}/sessions/{session}/join', [LearningController::class, 'join']);
        Route::post('/learning/{enrollment}/assignments/{assignment}/submit', [SubmissionController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/courses', [CourseController::class, 'index'])->name('courses');
        Route::post('/cohorts/{cohort}/enroll', [EnrollmentController::class, 'store'])->middleware('throttle:10,1');
        Route::post('/enrollments/{enrollment}/pay', [EnrollmentController::class, 'pay'])->middleware('throttle:10,1');
    });
    Route::middleware('panel.role:coach')->group(function () {
        Route::get('/coach', fn (Request $r, PanelController $c) => $c->dashboard($r, 'coach'))->name('panel.coach');
    });
    Route::middleware('panel.role:org_admin')->group(function () {
        Route::get('/organization', [OrganizationController::class, 'index'])->name('panel.org_admin');
        Route::post('/organization/switch', [OrganizationController::class, 'switch']);
        Route::post('/organization/members', [OrganizationController::class, 'addMember']);
        Route::get('/organization/members/{member}', [OrganizationController::class, 'report']);
    });
    Route::middleware('panel.role:admin')->group(function () {
        Route::get('/admin', fn (Request $r, PanelController $c) => $c->dashboard($r, 'admin'))->name('panel.admin');
        Route::get('/admin/{section}', [PanelController::class, 'admin']);
        Route::patch('/admin/users/{user}', [AdminUserController::class, 'update']);
        Route::patch('/admin/courses/{course}/coach', [AdminUserController::class, 'assignCoach']);
        Route::post('/admin/courses', [CourseController::class, 'store']);
        Route::post('/admin/organizations', [OrganizationController::class, 'store']);
    });
});

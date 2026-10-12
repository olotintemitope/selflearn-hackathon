<?php

use App\Http\Controllers\AccessibilityPreferenceController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClockPracticeController;
use App\Http\Controllers\LearningSpaceController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\MixedPastPaperPracticeController;
use App\Http\Controllers\OfflineAttemptContextController;
use App\Http\Controllers\OfflineStudyPackController;
use App\Http\Controllers\PastPaperPracticeController;
use App\Http\Controllers\ReadAloudController;
use App\Http\Controllers\StudyController;
use App\Http\Controllers\TutorLanguageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/materials');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'createLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.store');
    Route::get('/register', [AuthController::class, 'createRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/preferences/accessibility', [AccessibilityPreferenceController::class, 'update'])->name('accessibility-preferences.update');
    Route::post('/read-aloud', ReadAloudController::class)->middleware('throttle:12,1')->name('read-aloud');
    Route::get('/clock-practice', [ClockPracticeController::class, 'index'])->name('clock-practice.show');
    Route::post('/clock-practice', [ClockPracticeController::class, 'create'])->name('clock-practice.create');
    Route::post('/clock-practice/{question}/hint', [ClockPracticeController::class, 'hint'])->name('clock-practice.hint');
    Route::post('/clock-practice/{question}/answer', [ClockPracticeController::class, 'answer'])->name('clock-practice.answer');
    Route::get('/materials', [MaterialController::class, 'index'])->name('materials.index');
    Route::post('/materials', [MaterialController::class, 'store'])->name('materials.store');
    Route::post('/spaces', [LearningSpaceController::class, 'store'])->name('spaces.store');
    Route::get('/materials/{material}', [MaterialController::class, 'show'])->name('materials.show');
    Route::get('/materials/{material}/download', [MaterialController::class, 'download'])->name('materials.download');
    Route::post('/materials/{material}/retry', [MaterialController::class, 'retry'])->name('materials.retry');
    Route::post('/materials/{material}/rebuild-guide', [MaterialController::class, 'rebuildGuide'])->name('materials.rebuild-guide');
    Route::post('/materials/{material}/relevance', [MaterialController::class, 'resolveRelevance'])->name('materials.relevance');
    Route::post('/materials/{material}/past-paper-questions/index', [MaterialController::class, 'indexPastPaperQuestions'])->name('materials.past-paper-questions.index');
    Route::post('/materials/{material}/past-paper-questions/{question}/review', [MaterialController::class, 'reviewPastPaperQuestion'])->name('materials.past-paper-questions.review');
    Route::post('/materials/{material}/past-paper-practice', [PastPaperPracticeController::class, 'startPaper'])->name('past-paper-practice.start');
    Route::get('/materials/{material}/past-paper-practice/{run}', [PastPaperPracticeController::class, 'showPaper'])->name('past-paper-practice.show');
    Route::post('/materials/{material}/past-paper-practice/{run}', [PastPaperPracticeController::class, 'submitPaper'])->name('past-paper-practice.submit');
    Route::get('/past-paper-practice/{run}/results', [PastPaperPracticeController::class, 'paperResults'])->name('past-paper-practice.results');
    Route::get('/materials/{material}/past-paper-questions/{question}/practice', [PastPaperPracticeController::class, 'show'])->name('past-paper-questions.practice');
    Route::post('/materials/{material}/past-paper-questions/{question}/practice', [PastPaperPracticeController::class, 'submit'])->name('past-paper-questions.submit');
    Route::get('/past-paper-attempts/{attempt}', [PastPaperPracticeController::class, 'result'])->name('past-paper-attempts.show');
    Route::get('/spaces/{learningSpace}/study', [StudyController::class, 'show'])->name('study.show');
    Route::get('/spaces/{learningSpace}/past-paper-practice/mixed', [MixedPastPaperPracticeController::class, 'index'])->name('past-paper-mixed.index');
    Route::post('/spaces/{learningSpace}/past-paper-practice/mixed', [MixedPastPaperPracticeController::class, 'start'])->name('past-paper-mixed.start');
    Route::get('/spaces/{learningSpace}/offline-pack', [OfflineStudyPackController::class, 'show'])->name('offline.study-pack.show');
    Route::get('/offline-sync-context', [OfflineAttemptContextController::class, 'show'])->name('offline.sync-context');
    Route::post('/spaces/{learningSpace}/tutor', [StudyController::class, 'ask'])->name('tutor.ask');
    Route::post('/spaces/{learningSpace}/tutor-language', [TutorLanguageController::class, 'update'])
        ->name('tutor.language.update')
        ->missing(fn () => redirect()->route('materials.index')->with('status', 'That study space is no longer available. Choose another study space to continue.'));
    Route::post('/spaces/{learningSpace}/concepts/{concept}/assessments', [AssessmentController::class, 'store'])->name('assessments.store');
    Route::get('/assessments/{assessment}', [AssessmentController::class, 'show'])->name('assessments.show');
    Route::post('/assessments/{assessment}/attempts', [AssessmentController::class, 'submit'])->name('assessments.submit');
    Route::post('/assessments/{assessment}/attempts/{attempt}/mark', [AssessmentController::class, 'markOpenEnded'])->name('assessments.mark-open-ended');
});

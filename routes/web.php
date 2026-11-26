<?php

use App\Http\Controllers\Ashby\FacultyController;
use App\Http\Controllers\Ashby\PlayController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn () => to_route(request()->user()?->isStudent() ? 'play' : 'faculty'))->name('dashboard');

    Route::get('faculty', [FacultyController::class, 'home'])->name('faculty');
    Route::post('faculty/sample', [FacultyController::class, 'sample'])->middleware('throttle:6,1')->name('faculty.sample');
    Route::prefix('faculty/{section}')->whereNumber(['section', 'team', 'year'])->group(function () {
        Route::get('{year?}', [FacultyController::class, 'show'])->name('faculty.board');
        Route::post('future', [FacultyController::class, 'future'])->name('faculty.future');
        Route::post('years/{year}/open', [FacultyController::class, 'open'])->name('faculty.open');
        Route::post('years/{year}/close', [FacultyController::class, 'close'])->name('faculty.close');
        Route::post('teams/{team}/years/{year}/reopen', [FacultyController::class, 'reopen'])->name('faculty.reopen');
        Route::get('teams/{team}/years/{year}', [FacultyController::class, 'asTeam'])->name('faculty.team');
    });

    Route::get('play', [PlayController::class, 'home'])->name('play');
    Route::get('play/replay', [PlayController::class, 'replay'])->name('play.replay');
    Route::get('play/{year}', [PlayController::class, 'show'])->whereNumber('year')->name('play.year');
    Route::post('play/{year}/ask', [PlayController::class, 'ask'])->whereNumber('year')->middleware('throttle:60,1')->name('play.ask');
    Route::post('play/{year}/check', [PlayController::class, 'check'])->whereNumber('year')->name('play.check');
    Route::post('play/{year}/moment', [PlayController::class, 'moment'])->whereNumber('year')->name('play.moment');
    Route::post('play/{year}/cards', [PlayController::class, 'cards'])->whereNumber('year')->name('play.cards');
    Route::post('play/{year}/confirm', [PlayController::class, 'confirm'])->whereNumber('year')->name('play.confirm');
    Route::post('play/{year}/paragraph', [PlayController::class, 'paragraph'])->whereNumber('year')->name('play.paragraph');
});

require __DIR__.'/settings.php';

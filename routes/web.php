<?php

use App\Http\Controllers\Ashby\AdminController;
use App\Http\Controllers\Ashby\FacultyController;
use App\Http\Controllers\Ashby\JoinController;
use App\Http\Controllers\Ashby\PlayController;
use App\Http\Controllers\Ashby\RosterController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome'))->name('home');

// The join link works signed in or not: a new participant makes a sign-in there.
Route::get('join/{code}', [JoinController::class, 'show'])->name('join.show');
Route::post('join/{code}', [JoinController::class, 'store'])->middleware('throttle:10,1')->name('join.store');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn () => to_route(match (true) {
        (bool) request()->user()?->isStudent() => 'play',
        (bool) request()->user()?->isAdmin() && ! request()->user()->sections()->exists() => 'admin',
        default => 'faculty',
    }))->name('dashboard');

    Route::get('faculty', [FacultyController::class, 'home'])->name('faculty');
    Route::post('faculty/sample', [FacultyController::class, 'sample'])->middleware('throttle:6,1')->name('faculty.sample');
    Route::prefix('faculty/{section}')->whereNumber(['section', 'team', 'user'])->group(function () {
        Route::get('roster', [RosterController::class, 'show'])->name('roster.show');
        Route::post('roster/add', [RosterController::class, 'add'])->name('roster.add');
        Route::post('roster/join-code', [RosterController::class, 'joinCode'])->name('roster.join-code');
        Route::post('roster/capacity', [RosterController::class, 'capacity'])->name('roster.capacity');
        Route::post('roster/teams', [RosterController::class, 'newTeam'])->name('roster.teams.new');
        Route::post('roster/teams/form', [RosterController::class, 'formTeams'])->name('roster.teams.form');
        Route::post('roster/teams/{team}', [RosterController::class, 'renameTeam'])->name('roster.teams.rename');
        Route::post('roster/teams/{team}/delete', [RosterController::class, 'deleteTeam'])->name('roster.teams.delete');
        Route::post('roster/people/{user}/move', [RosterController::class, 'move'])->name('roster.move');
        Route::post('roster/people/{user}/seat', [RosterController::class, 'seat'])->name('roster.seat');
        Route::post('roster/people/{user}/pause', [RosterController::class, 'pause'])->name('roster.pause');
        Route::post('roster/people/{user}/paid', [RosterController::class, 'paid'])->name('roster.paid');
        Route::post('roster/people/{user}/remove', [RosterController::class, 'remove'])->name('roster.remove');
        Route::post('roster/people/{user}/reset', [RosterController::class, 'reset'])->name('roster.reset');
        Route::get('export/{kind}.csv', [RosterController::class, 'export'])->whereIn('kind', ['results', 'gradebook'])->name('roster.export');
    });

    Route::get('admin', [AdminController::class, 'index'])->name('admin');
    Route::post('admin/logins', [AdminController::class, 'storeLogin'])->name('admin.logins');
    Route::post('admin/classes', [AdminController::class, 'storeClass'])->name('admin.classes');
    Route::post('admin/classes/{section}/lead', [AdminController::class, 'assign'])->whereNumber('section')->name('admin.lead');
    Route::post('admin/classes/{section}/co', [AdminController::class, 'addCo'])->whereNumber('section')->name('admin.co.add');
    Route::post('admin/classes/{section}/co/{user}/remove', [AdminController::class, 'removeCo'])->whereNumber(['section', 'user'])->name('admin.co.remove');
    Route::post('admin/classes/{section}/delete', [AdminController::class, 'destroy'])->whereNumber('section')->name('admin.classes.delete');

    Route::prefix('faculty/{section}')->whereNumber(['section', 'team', 'year'])->group(function () {
        Route::get('{year?}', [FacultyController::class, 'show'])->name('faculty.board');
        Route::post('future', [FacultyController::class, 'future'])->name('faculty.future');
        Route::post('years/{year}/open', [FacultyController::class, 'open'])->name('faculty.open');
        Route::post('years/{year}/close', [FacultyController::class, 'close'])->name('faculty.close');
        Route::post('teams/{team}/years/{year}/reopen', [FacultyController::class, 'reopen'])->name('faculty.reopen');
        Route::get('teams/{team}/years/{year}', [FacultyController::class, 'asTeam'])->name('faculty.team');
        Route::post('calendar', [FacultyController::class, 'saveCalendar'])->name('faculty.calendar');
        Route::post('calendar/fill', [FacultyController::class, 'fillCalendar'])->name('faculty.calendar.fill');
        Route::post('ai', [FacultyController::class, 'switchAi'])->name('faculty.ai');
        Route::post('years/{year}/reading', [FacultyController::class, 'reading'])->middleware('throttle:6,1')->name('faculty.reading');
        Route::post('debrief', [FacultyController::class, 'debrief'])->middleware('throttle:6,1')->name('faculty.debrief');
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
    Route::post('play/{year}/feedback', [PlayController::class, 'feedback'])->whereNumber('year')->middleware('throttle:6,1')->name('play.feedback');
});

require __DIR__.'/settings.php';

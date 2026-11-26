<?php

namespace App\Http\Controllers\Ashby;

use App\Ashby\Content\ContentPack;
use App\Ashby\Help\Manual;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Help (H1): the participant manual for everyone, the instructor manual for staff, questions and what changed.
 */
class HelpController extends Controller
{
    public const TOURS = ['participant', 'instructor', 'admin'];

    public function __construct(private readonly Manual $manual, private readonly ContentPack $content) {}

    public function participant(Request $request): Response
    {
        return $this->page($request, 'participant');
    }

    public function instructor(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->isFaculty() || $user->isAdmin()), 403);

        return $this->page($request, 'instructor');
    }

    /** Marks a welcome tour done or skipped, so it isn't shown again. */
    public function tourDone(Request $request, string $tour): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($tour, self::TOURS, true), 404);
        $user->tours_done = array_values(array_unique([...($user->tours_done ?? []), $tour]));
        $user->save();

        return back();
    }

    private function page(Request $request, string $audience): Response
    {
        $user = $request->user();

        return Inertia::render('ashby/Help', [
            'audience' => $audience,
            'sections' => $this->manual->sections($audience),
            'questions' => $this->manual->questions($audience),
            'changes' => $this->manual->changes($audience),
            'words' => ['manual' => $this->manual->words(), 'common' => $this->content->file('common')],
            'isStaff' => $user instanceof User && ($user->isFaculty() || $user->isAdmin()),
        ]);
    }
}

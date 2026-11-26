<?php

namespace App\Http\Controllers\Ashby;

use App\Ashby\Admin\Roster;
use App\Ashby\Content\ContentPack;
use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The join link: a participant makes a sign-in, or uses the one they have, and is enrolled in the class.
 */
class JoinController extends Controller
{
    public function __construct(private readonly ContentPack $content, private readonly Roster $roster) {}

    public function show(Request $request, string $code): Response
    {
        $section = $this->section($code);
        $common = $this->content->file('common');
        $words = (array) $common['join'];
        $user = $request->user();
        $vars = ['class' => $section->name, 'company' => (string) $common['company'], 'name' => $user instanceof User ? $user->name : ''];
        $blocked = null;
        if ($user instanceof User && ! $user->isStudent()) {
            $blocked = $words['not_for_you'];
        } elseif (! $section->hasRoom() && ! ($user instanceof User && $section->enrolments()->where('user_id', $user->id)->exists())) {
            $blocked = $words['full'];
        }

        return Inertia::render('ashby/Join', [
            'code' => $code,
            'title' => $this->fill((string) $words['title'], $vars),
            'intro' => $this->fill((string) $words['intro'], $vars),
            'signedIn' => $user instanceof User ? $this->fill((string) $words['signed_in'], $vars) : null,
            'blocked' => $blocked,
            'words' => ['join' => $words, 'common' => $common],
        ]);
    }

    public function store(Request $request, string $code): RedirectResponse
    {
        $section = $this->section($code);
        $words = (array) $this->content->file('common')['join'];
        $user = $request->user();

        if ($user instanceof User) {
            try {
                $this->roster->joinExisting($section, $user);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['join' => (string) ($words[$e->getMessage()] ?? $e->getMessage())]);
            }

            return to_route('play');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'name.required' => $words['name_required'],
            'email.required' => $words['email_required'],
            'email.email' => $words['email_invalid'],
            'password.confirmed' => $words['mismatch'],
            'password.min' => $words['password_min'],
            'password.password.min' => $words['password_min'],
            'password.password.mixed' => $words['password_mixed'],
            'password.password.letters' => $words['password_mixed'],
            'password.password.numbers' => $words['password_numbers'],
            'password.password.symbols' => $words['password_symbols'],
            'password.password.uncompromised' => $words['password_leaked'],
        ]);
        if (User::query()->where('email', mb_strtolower((string) $data['email']))->exists()) {
            throw ValidationException::withMessages(['email' => $words['email_taken']]);
        }
        try {
            $new = $this->roster->join($section, (string) $data['name'], (string) $data['email'], (string) $data['password']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['join' => (string) ($words[$e->getMessage()] ?? $e->getMessage())]);
        }
        Auth::login($new);
        $request->session()->regenerate();

        return to_route('play');
    }

    private function section(string $code): Section
    {
        return Section::query()->where('join_code', $code)->firstOrFail();
    }

    /** @param  array<string, string>  $vars */
    private function fill(string $text, array $vars): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', fn (array $m): string => $vars[$m[1]] ?? $m[0], $text);
    }
}

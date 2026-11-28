# Moving Future CHRO to Livewire

28 Nov 2026. Decision B8: every Flexee sim follows Mukesh's server spec (Laravel, Livewire, Blade, Tailwind, no Vue or React). Future CHRO moves first, while it has no participants.

## What changes and what stays

Only the screens change. These stay exactly as they are:

- the engine, the Python model, the fixtures and `ashby:verify`
- the database and every migration
- the content pack: every word a person reads still comes from `resources/content/ashby/`
- the AI checks, mail, reporting, the calendar and the commands
- every URL, so links in mail and bookmarks keep working
- the deploy workflow, CI on SQLite, MySQL 8.4, MariaDB 10.11 and the Python model

What goes: Vue, Inertia, TypeScript, Wayfinder and the starter-kit components. About 10,500 lines of `.vue` and `.ts` files, of which about 3,700 are the sim's own pages.

## The pages, in the order they move

| PR | Pages | Vue lines now | Notes |
|---|---|---|---|
| 1 | Livewire set up, one Blade layout, sign-in, password reset, two-factor challenge, settings | ~1,900 (starter kit) | Unblocks every later page |
| 2 | Welcome, join, no-team page, help | ~430 | Simple pages, proves the pattern |
| 3 | Play: brief, table, moment, cards, results, the tour | ~1,480 | The biggest page; live clocks in Alpine |
| 4 | Replay | ~260 | |
| 5 | Faculty home and board | ~720 | Refreshes on its own every 15 seconds |
| 6 | Admin and roster | ~750 | One-time passwords still show once |
| 7 | Take out Vue, Inertia, TypeScript and Wayfinder; CI and docs updated | | Nothing on the site uses them by then |

Each PR keeps the words and the layout the same as now. Each one passes the full test suite, the content-words test and the manual check before it merges, and deploys on its own.

## Questions for Vikram

- **LW-D1. Which Livewire.** (a) Livewire 4 with Blade and the Alpine it ships with, **my pick**; (b) Livewire 3. Livewire 4.4 is already in the lock file through the starter kit.
- **LW-D2. How the switch happens.** (a) Page by page: each PR swaps its pages and goes live, and Inertia stays until PR 7 removes it, **my pick**; (b) one switch at the end from a long-lived branch. With (a), the rehearsal can carry on and each step is small enough to check.
- **LW-D3. Sign-in features.** (a) Keep password sign-in, password reset, two-factor and email change; drop passkeys and the light/dark switch, which no sim uses, **my pick**; (b) keep all of them.
- **LW-D4. Live parts of the play page.** (a) Clocks count down in the browser with Alpine; Livewire saves each choice to the server; the board and the table refresh with Livewire polling, **my pick**; (b) Livewire polling for the clocks too. Polling for a clock adds a request every second per person, too much on shared hosting.
- **LW-D5. Styling.** (a) Tailwind utilities in the Blade views, with a small set of shared classes (card, button, pill) in `app.css`, **my pick**; (b) keep today's hand-written CSS files. The spec asks for Tailwind.
- **LW-D6. Tests.** (a) Page tests move from Inertia checks to Livewire tests one for one: same cases, same assertions on the data, **my pick**; (b) rewrite the tests from scratch.
- **LW-D7. Migrations on deploy.** (a) The deploy stops before a new migration and posts the migration for Mukesh to review; he runs it and reruns the deploy, **my pick**; (b) migrations run on each deploy, as now. The spec asks for review before `migrate --force`.
- **LW-D8. fileinfo.** (a) Mukesh turns on fileinfo for chro.flexee.org in GoDaddy's PHP settings, for the website and the command line; then the workflow stops loading `fileinfo.so` by hand, **my pick**; (b) keep the by-hand load. The spec forbids loading extension files by hand.
- **LW-D9. The other sims.** (a) After Future CHRO, each Vue sim gets its own move plan, done between terms so no class sees a change mid-course, **my pick**; (b) move them all now.

## How each PR is checked

1. The full PHP suite, Pint, PHPStan level 7 and the Python model, locally and in CI on every database.
2. The content-words test and the manual check, so no banned word appears and the manuals still match the screens.
3. The reference teams play through the new pages and reach the same judgments: builder 67.9, waiter 59.8, middle 53.1, cutter 21.9.
4. A look at each new page on the live site after the deploy, at desktop width and at 390 px.
5. After PR 7: `ashby:verify --database` on the server, then a full rehearsal.

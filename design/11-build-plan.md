# The Future CHRO: build plan and feature checklist

26 Nov 2026. Laravel build of The Future CHRO, following the Flexee sim feature standard of 26 Nov 2026 (`FLEXEE-SIM-FEATURE-STANDARD-11-26-2026.md`, Drive) and the two reference sims read today: managerial accounting (`OneSmarterInc/managerial-accounting` at 7e863bd, Brightwell) and Corporate Finance (`OneSmarterInc/flexee-corporate-finance` at 19644c0, Brennock). Both run Laravel 13, Inertia 3, Vue 3 and TypeScript on the Halden skeleton. Vikram decides; Claude recommends, builds, tests and reports; Mukesh releases.

## 1. Repo, site and stack

| Item | Proposal | Needs |
|---|---|---|
| Repo | New: `OneSmarterInc/flexee-future-chro`, private (B1) | Vikram creates it, empty, and attaches it with push access |
| Site | `chro.flexee.org` (B2) | Mukesh: subdomain, certificate, MySQL database and user |
| Branches | `main` (released) and `v1` (working). Code reaches `main` only by pull request | |
| Skeleton | Copy from Corporate Finance at 19644c0 (B3): auth, layouts, admin and roster, board frame, AI client and guardrails, help and manual system with the manual-log check, reporting connector, release registry, verify command pattern | Rename Brennock to Ashby Calder; remove its content and engine |
| Stack | Laravel 13, PHP 8.4, Inertia 3, Vue 3, TypeScript. MySQL 8.4 and MariaDB 10.11; SQLite in tests | |
| Economics | `economics/` holds model v0.3 (data, model, teams, 27 checks, fixtures). The browser engine already reproduces all 972 checked fixture values; the PHP port must do the same | |

## 2. What Future CHRO does differently from the quarter sims

The standard is written for quarter sims run over a term. Future CHRO is built workshop-first (H2): eight years in one or two days, with the term format from the same engine. Where the standard says "quarter", this sim says "year". Five things are its own:

1. **The table.** Each year the team talks with three to five people who remember what was said and hold back facts until the right question is asked. Messages are capped per person per year, and table time is shared with checking.
2. **The planted-flaw plan.** One AI-written analysis each year with one flaw, the same for every class, and ten minutes of checking time.
3. **Workflow cards.** Eight workflows, four ways of working, and task types from verification economics.
4. **The replay.** Each team's exact decisions run under all three futures, with judgment as the worst result.
5. **The hidden future.** One of three futures is drawn per class and shown only to the instructor until the replay.

## 3. Feature checklist (standard section 3)

Have = in the prototype already; Build = to build; Not = not for this sim, with the reason. Rows S11, S12, F17 and F18 are proposed additions to the standard, waiting for Vikram.

### Students

| # | Feature | Mark | Note |
|---|---|---|---|
| S1 | Welcome tour | Build | Steps over the brief, the table, the plan, the cards and results |
| S2 | Sticky header | Have | In the prototype |
| S3 | Session bar | Build | Name, class, team, year, seat, Sign out |
| S4 | Save on Next | Build | Cards save on Next and on tab change |
| S5 | Bar when something changed since Send | Build | The CHRO's confirm is the Send |
| S6 | Bar when a new year opens; check for news | Build | Every 30 seconds; in a workshop years open within minutes |
| S7 | Times in the class time zone | Build | |
| S8 | The full record at the end | Build | Folded into the replay page: decisions, results, paragraphs |
| S9 | Source line under an AI answer | Build | Under each table reply: the person's file for that year |
| S10 | Student manual | Build | |
| S11 | Table time shared between talking and checking | Have (proposed for the standard) | Each question costs a minute; checking the planted-flaw plan costs minutes from the same budget |
| S12 | Replay under every future | Have (proposed for the standard) | The team's decisions run under all three futures, with judgment beside the reference teams |

### Instructors

| # | Feature | Mark | Note |
|---|---|---|---|
| F1 | Board per class and year | Build | Open, close, reopen, publish |
| F2 | Left menu with class switcher | Build | |
| F3 | Sticky header and menu | Build | |
| F4 | Students and teams | Build | Teams of three to five with seats (H3) |
| F5 | Paid mark | Build | |
| F6 | Two CSV files | Build | Results by team and year; gradebook |
| F7 | Class calendar | Build | Workshop: times within a day; term: one year a week |
| F8 | The bell | Build | |
| F9 | Search | Build | |
| F10 | Mark a memo | Build | The team's yearly paragraph is the memo |
| F11 | Debrief page | Build | Adds the replay for every team and the drawn future |
| F12 | Class reading per closed year | Build | |
| F13 | Ask Claude on instructor pages | Build | |
| F14 | What students see | Build | Sample class of the four reference teams |
| F15 | Per-class AI switches | Build | Table voices, plan checks, paragraph feedback, class reading, debrief text |
| F16 | Instructor manual | Build | With a teaching note per year |
| F17 | Choose the future, or draw it | Build (proposed for the standard) | The instructor picks one of the three futures or lets the class draw one; shown only to staff until the replay |
| F18 | Threads view | Build (proposed for the standard) | For each team, which earlier choice is coming back this year (the consequence threads) |

### Administrators, help and manuals

| # | Feature | Mark | Note |
|---|---|---|---|
| A1–A6 | Classes and logins, co-instructors, delete before first year, reporting connector, admin tour, first admin by command | Build | Copied from Corporate Finance |
| H1–H5 | Help menu, manuals that cannot fall behind, questions and answers, What changed, expected time per year | Build | Copied with the manual-log check |

## 4. Layers, one pull request each

Every pull request has feature tests, a browser walk at desktop width and 390px, and passes Pint, PHPStan level 7, PHP tests, front-end lint and format, the TypeScript check, the manual-log check and the Python checks.

| PR | Layer | Done when |
|---|---|---|
| 0 | Skeleton from Corporate Finance, `CLAUDE.md`, CI, release registry, design docs, `economics/` | CI green on three databases and Python |
| 1 | Engine port: `AshbyEngine` in PHP | Every fixture value reproduced (relative 1e-6, absolute 1e-4); the 27 checks hold as an economic-rules test |
| 2 | Student loop for 2027, 2029, 2031: brief, table (fixed replies first), plan check, moment, cards, results, paragraph, replay | A team plays the prototype slice through real screens; the content-words test passes |
| 3 | Faculty board, future choice, threads view, What students see | An instructor runs a class of four teams end to end |
| 4 | Admin and roster, workshop and term calendars | Corporate Finance's admin tests pass with Ashby Calder's names |
| 5 | AI voices at the table with held-back facts, paragraph feedback, class reading, debrief text, all with guardrails and switches | Guardrail tests pass with the stub; live smoke test on the dev site |
| 6 | Years 2028, 2030, 2032 to 2034 for the full eight-year format | Week-by-week weeks 1 to 8 playable |
| 7 | Help, manuals, tours, search, bell | Manual-log check seen failing, then passing |
| 8 | Mail, reporting connector, verify command, go-live docs | Mukesh's log matches expected |

First release to the dev site after PR 3, so Vikram can run a class before the AI voices go in.

## 5. Decisions for Vikram

**B1: Repo**
1. **A new repo, `OneSmarterInc/flexee-future-chro`. Claude's pick.** Clean history, a name that matches the product.
2. Reuse `flexee-leading-people` and replace its code.

**B2: Site**
1. **`chro.flexee.org`. Claude's pick.**
2. Reuse `people.flexee.org`.

**B3: Skeleton**
1. **Copy from Corporate Finance at 19644c0. Claude's pick.** It is the newest build and has the manuals that can't fall behind.
2. Copy from managerial accounting, the standard's target.

## 6. What Claude needs to start

1. Vikram: create the repo (B1) and attach it with push access.
2. Mukesh: the subdomain and database (B2), when the first release is near. Not blocking for PRs 0 to 2.
3. Vikram: the prototype notes from his own play, before PR 2.

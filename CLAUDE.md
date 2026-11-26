# Working rules for this repo

**In this repo Claude pushes to the working branch (`v1`) and merges to `main` when Vikram says "merge" and CI is green.** The other Flexee repos forbid Claude from pushing; this one is the exception, as in the Halden and Corporate Finance builds. Releases to the server are Mukesh's.

Never commit `.env`, keys or passwords, and never paste them into a chat.

## What this is

Flexee: The Future CHRO. An executive simulation (workshop first, term format from the same engine) set at Ashby Calder, an audit, tax and advisory firm of about 2,000 people, 2027 to 2035. Teams sit as the HR leadership team with four seats. One of three futures is drawn per class and hidden until the replay.

## Who does what

- Vikram decides. Claude recommends, builds, tests and reports. Mukesh deploys and owns the server, the secrets and any deploy switch.
- Every shaping choice goes in `design/02-decision-register.md` with lettered options and Claude's pick in bold. Decisions are never edited; a change is a new decision.
- Code reaches `main` only by pull request from `v1`. Open as draft, wait for CI, then Vikram says "merge".

## The order of work

1. Design before code: the docs in `design/` and `design/prototype/future-chro-prototype.html`. Where they differ on words a participant reads, the prototype wins.
2. Economics first: change `economics/` in Python, run `python3 economics/check.py`, rebuild with `python3 economics/build.py`, then change the PHP (and the prototype engine) to match. Fixtures are never edited by hand.
3. One review doc per year before building it.
4. Layers, one pull request each, in the order in `design/11-build-plan.md`. Features follow the Flexee sim feature standard of 26 Nov 2026.

## Words participants read

"You" and contractions. Everyday words first, the textbook term once after them, the number inside the sentence. Banned on any participant screen: room, war room, lever, tier, converge, commit, segment, cohort, runtime, round, snake_case values. Results stories are fixed templates chosen by outcome.

## AI text

Only figures from its inputs. Banned words checked as whole words. The engine's record outranks a team's account. Never score, rank, course, class, student, week or round in participant text; never "the model", "the engine" or "the AI". A held-back fact comes out only when its trigger is met. Every AI feature has a per-class switch the instructor controls.

## Checks before any commit

Python: `python3 economics/check.py` and `python3 economics/build.py --check`. Prototype engine: `node design/prototype/engine-test.cjs`. Once the app is in: Pint, PHPStan level 7, PHP tests, the manual-log check, front-end lint and format, TypeScript check. CI runs SQLite, MySQL 8.4, MariaDB 10.11 and the Python model.

## Standing rules

- Never edit a migration that has run in production.
- No demo accounts with known passwords on production before participants.
- Answer-revealing files (`economics/`, `design/`) are never on a participant route.
- One source per piece of content: numbers from CSVs, every participant word in the content pack.

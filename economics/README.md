# Flexee: The Future CHRO — reference model (v0.3)

Python reference model of the expertise pipeline, 2027-2035. It is the source of truth for the numbers the sim will show. Screens and the PHP engine come later and must reproduce the fixtures.

| File | Purpose |
|---|---|
| `data/levels.csv` | Two job families (delivery, operations), four levels each: starting headcount, salary, promotion, leaving, retirement |
| `data/futures.csv` | Three futures by year: what AI can do at each level, AI cost against salary, incidents, regulation cost, price pressure |
| `data/workflows.csv`, `data/modes.csv`, `data/task_types.csv` | Four workflows per family, the four ways of working, and the three task types |
| `data/constants.csv` | Every other constant, with a unit and a source. "Assumption" marks a value to review |
| `model.py` | `simulate(team, future)`, `summary(rows)`, `judgment(team)` (worst case across the three futures) |
| `make_teams.py`, `teams/*.json` | Four reference teams: builder, middle, cutter, waiter |
| `validate.py` | 27 named checks in plain English |
| `check.py`, `build.py`, `fixtures/*.json` | Runs the named checks; writes the teams, the fixtures and the prototype data (`--check` fails if any is out of date) |
| `chart.py`, `out/four-teams-three-futures.png` | Profit, pipeline strength and strain for the four teams under the three futures |

Run: `python3 economics/check.py && python3 economics/build.py`

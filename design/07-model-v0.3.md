# The Future CHRO: pipeline model v0.3

25 Nov 2026. Adds decisions V1 (verification economics from the AI Strategic Governance program) and R1 (an outage that spreads further when more work runs on one vendor's AI alone) to model v0.2. All 27 named checks pass. Files: `future-chro-model-v0.3.zip`.

## What changed from v0.2

| Mechanism | How the engine carries it | New data |
|---|---|---|
| Task types (Sethi and Jayaraj) | Each workflow has a type. Type 1 is cheap to check (billing, scheduling). Type 2 can be checked but takes time (audit testing, tax returns, client reports, IT support, client service). Type 3 can't be checked without redoing the work (advisory research). Type 3 work costs 1.6 times as much review and gives 2.2 times the hidden errors when AI drafts or works alone | `task_types.csv`, a `task_type` column in `workflows.csv` |
| The verification wall | Review needed is compared with 7% of senior associates' and managers' time. Above that line, AI errors rise by 1.5 times the overshoot and strain jumps | 3 constants |
| Spending on checking capacity | A new choice for the Head of AI and Workforce Governance: reviewer training and checking tools at $2.5M a year. It cuts review time by up to 30% over two years | 3 constants |
| Outage spread (R1) | The 2030 outage costs 0.6 to 1.4 times as much, depending on how much of the AI work runs on AI alone | 2 constants |

The builder now spends on checking capacity from 2029.

## Four reference teams

| Team | Profit 2027–35, $M (rapid / complement / constrained) | Pipeline 2035 | Hits the wall | Judgment |
|---|---|---|---|---|
| Builder | 567 / 803 / 653 | 0.59–0.81 | 2032, rapid only | **68.4** |
| Waiter | 323 / 618 / 777 | 1.05 | Never | 60.3 |
| Middle | 435 / 615 / 370 | 0.55–0.69 | 2033, rapid only | 53.7 |
| Cutter | 534 / −9 / −270 | 0.21–0.26 | Never; it skips checking and takes the errors instead | 21.9 |

## What the five new checks show

1. Type 3 work left to AI alone gives over four times the hidden errors of Type 1 work, per unit of AI work.
2. In the rapid future, both teams that check AI drafts hit the wall: the builder in 2032 and the middle team in 2033. After that, their AI errors more than double.
3. No team hits the wall before 2031 in any future. It builds from years of growing AI output.
4. Spending on checking capacity pays when AI keeps growing ($47M more for the middle team in rapid, $8M in complement) and costs $10M when it stalls (constrained).
5. The outage hits the cutter 2.7 times harder than the builder per unit of AI work, because the cutter's AI works alone.

## One lesson changed

With checking capacity, the builder now earns more than the cutter over nine years even in the rapid future ($567M against $534M). The check that said the cutter earns the most in rapid now says the cutter earns more than the middle team and the waiter. Cutting still pays first (2028) and still beats doing nothing in a fast-AI world. Spending on the people who check AI now beats cutting everywhere, which is the paper's central claim.

## Limits and open points

- 54 of 62 constants are assumptions. The task-type weights should be set from the paper's propositions.
- The wall arrives in 2032 to 2033 and only in the rapid future, not in 2031 as the ideas doc proposed. Week 6 or 7 is where it lands for teams that check AI drafts. A lower line would bring it to 2031 in all futures.
- Influence is still measured but not scored.

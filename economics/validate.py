"""Plain-English checks on the Future CHRO pipeline model. Run: python3 validate.py"""
import copy
from model import simulate, summary, judgment, team, FUTURES

T = {n: team(n) for n in ("builder", "middle", "cutter", "waiter")}
R = {(n, f): simulate(T[n], f) for n in T for f in FUTURES}
S = {k: summary(v) for k, v in R.items()}
yr = lambda rows, y: next(r for r in rows if r["year"] == y)
checks = []


def check(name, ok, detail=""):
    checks.append((name, bool(ok), detail))


w27 = yr(R[("waiter", "complement")], 2027)
check("The firm as you find it earns a healthy margin in 2027 and meets its work",
      0.18 <= w27["profit"] / w27["revenue"] <= 0.32 and w27["met_demand"] > 0.98,
      f"margin {w27['profit'] / w27['revenue']:.0%}, revenue ${w27['revenue'] / 1e6:.0f}M")
wr = R[("waiter", "rapid")]
check("A firm that changes nothing is squeezed when competitors adopt AI fast",
      yr(wr, 2035)["profit"] < 0.5 * yr(wr, 2027)["profit"],
      f"profit ${yr(wr, 2027)['profit'] / 1e6:.0f}M in 2027, ${yr(wr, 2035)['profit'] / 1e6:.0f}M in 2035")
check("Cutting pays once the layoffs are paid for when AI arrives fast: in 2028 the cutter out-earns the builder and the middle team (rapid)",
      all(yr(R[("cutter", "rapid")], 2028)["profit"] > yr(R[(n, "rapid")], 2028)["profit"] for n in ("builder", "middle")),
      ", ".join(f"{n} ${yr(R[(n, 'rapid')], 2028)['profit'] / 1e6:.0f}M" for n in T))
check("Cutting ahead of what AI can do hurts at once when AI is slow: in 2028 the cutter earns least (constrained)",
      all(yr(R[("cutter", "constrained")], 2028)["profit"] < yr(R[(n, "constrained")], 2028)["profit"] for n in ("builder", "middle", "waiter")))
check("...and costs later: by 2033 the cutter meets under 60% of its work (complement and constrained)",
      all(yr(R[("cutter", f)], 2033)["met_demand"] < 0.60 for f in ("complement", "constrained")),
      ", ".join(f"{f} {yr(R[('cutter', f)], 2033)['met_demand']:.0%}" for f in ("complement", "constrained")))
check("By 2035 the cutter's pipeline is under half the builder's, in every future",
      all(S[("cutter", f)]["end_bench"] < 0.5 * S[("builder", f)]["end_bench"] for f in FUTURES),
      "; ".join(f"{f}: cutter {S[('cutter', f)]['end_bench']:.2f}, builder {S[('builder', f)]['end_bench']:.2f}" for f in FUTURES))
best = {f: max(T, key=lambda n: S[(n, f)]["overall"]) for f in FUTURES}
check("There is no single winning strategy: the best team differs between futures", len(set(best.values())) >= 2,
      ", ".join(f"{f}: {n}" for f, n in best.items()))
J = {n: judgment(T[n])[0] for n in T}
check("Judgment: the builder has the best worst case across the three futures", max(J, key=J.get) == "builder",
      ", ".join(f"{n} {v:.1f}" for n, v in J.items()))
def outage_hit(n, f):
    rows = R[(n, f)]
    return 1 - yr(rows, 2030)["met_demand"] / yr(rows, 2029)["met_demand"]
check("Changing nothing looks best at first, then falls behind if AI arrives fast: the waiter earns most in 2027 and least of the four in 2035 (rapid)",
      max(T, key=lambda n: yr(R[(n, "rapid")], 2027)["profit"]) == "waiter" and min(T, key=lambda n: yr(R[(n, "rapid")], 2035)["profit"]) == "waiter")
check("In a fast-AI world cutting is not simply wrong: over nine years the cutter out-earns the middle team and the waiter (rapid)",
      all(S[("cutter", "rapid")]["cum_profit_m"] > S[(n, "rapid")]["cum_profit_m"] for n in ("middle", "waiter")),
      ", ".join(f"{n} ${S[(n, 'rapid')]['cum_profit_m']:.0f}M" for n in T))
check("The 2030 AI outage hurts the AI-heavy cutter more than the builder (complement)",
      outage_hit("cutter", "complement") > outage_hit("builder", "complement"),
      f"cutter {outage_hit('cutter', 'complement'):.0%}, builder {outage_hit('builder', 'complement'):.0%}")
def flip(n, **kw):
    t = copy.deepcopy(T[n])
    for k, val in kw.items():
        t["2027"][k] = val
    return t
check("Checking every piece of work pays where AI drafts or works alone: the middle team and the cutter earn more with full checking, in every future",
      all(summary(simulate(flip(n, oversight="strong"), f))["cum_profit_m"] > S[(n, f)]["cum_profit_m"] for n in ("middle", "cutter") for f in FUTURES))
sub = copy.deepcopy(T["builder"])
for y in sub:
    sub[y]["workflows"] = {k: ("draft_check" if m == "assist" else m) for k, m in sub[y].get("workflows", {}).items()}
sub["2027"]["tutor"] = 0.0
check("When people draft and AI helps, the 2035 pipeline is stronger than when AI drafts and people check, other choices the same",
      all(summary(simulate(T["builder"], f))["end_bench"] > summary(simulate(sub, f))["end_bench"] for f in FUTURES),
      ", ".join(f"{f} {summary(simulate(T['builder'], f))['end_bench']:.2f} vs {summary(simulate(sub, f))['end_bench']:.2f}" for f in FUTURES))

# ---- v0.2 mechanisms ----
m28 = yr(R[("middle", "complement")], 2028)
check("Checking AI is hidden work: when AI drafts and people check, review takes at least a tenth of the hours AI saves (middle, complement, 2028)",
      m28["review_units"] >= 0.10 * m28["ai_units"], f"review {m28['review_units']:.0f} units for {m28['ai_units']:.0f} units of AI work")
check("Review load wears people down: by 2029 the middle team's strain is above the builder's, in every future",
      all(yr(R[("middle", f)], 2029)["strain"] > yr(R[("builder", f)], 2029)["strain"] for f in FUTURES),
      ", ".join(f"{f} {yr(R[('middle', f)], 2029)['strain']:.0f} vs {yr(R[('builder', f)], 2029)['strain']:.0f}" for f in FUTURES))
check("Sharing the gains lowers strain, other choices the same (middle, complement, 2031)",
      yr(simulate(flip("middle", share_gains=True), "complement"), 2031)["strain"] < yr(R[("middle", "complement")], 2031)["strain"])
check("Knowledge kept in a firm-owned system outlasts knowledge kept only in people: by 2035 the builder holds more firm knowledge than the middle team, in every future",
      all(yr(R[("builder", f)], 2035)["knowledge"] > yr(R[("middle", f)], 2035)["knowledge"] for f in FUTURES),
      ", ".join(f"{f} {yr(R[('builder', f)], 2035)['knowledge']:.0f} vs {yr(R[('middle', f)], 2035)['knowledge']:.0f}" for f in FUTURES))
fk = copy.deepcopy(T["cutter"]); fk["2029"]["knowledge"] = "firm"
def k_drop(rows):
    return yr(rows, 2030)["knowledge"] - yr(rows, 2031)["knowledge"]
check("When the vendor changes its terms in 2031, a firm that left its knowledge with the vendor loses more of it than one that owns it (cutter, complement)",
      k_drop(R[("cutter", "complement")]) > k_drop(simulate(fk, "complement")) + 5,
      f"vendor {k_drop(R[('cutter', 'complement')]):.0f} points, firm-owned {k_drop(simulate(fk, 'complement')):.0f}")
inc = lambda rows: sum(r["incidents"] for r in rows)
check("HR and IT planning AI work together cuts AI errors against IT planning alone (cutter, rapid)",
      inc(simulate(flip("cutter", planning="joint"), "rapid")) < inc(R[("cutter", "rapid")]))
check("Handing AI planning to IT costs the CHRO standing; a board report on people and skills builds it (2035 influence: builder above cutter, every future)",
      all(yr(R[("builder", f)], 2035)["influence"] > yr(R[("cutter", f)], 2035)["influence"] + 20 for f in FUTURES),
      ", ".join(f"{f} {yr(R[('builder', f)], 2035)['influence']:.0f} vs {yr(R[('cutter', f)], 2035)['influence']:.0f}" for f in FUTURES))
check("The same decisions give the same numbers", simulate(T["middle"], "rapid") == R[("middle", "rapid")])
check("No level ever has fewer than zero people",
      all(x >= -1e-6 for rows in R.values() for r in rows for x in r["delivery_levels"] + r["operations_levels"]))

# ---- v0.3: verification economics ----
from model import load_work, ai_split
W, M, TT = load_work()
def one(key, fam):
    w0 = [w for w in W[fam] if w["key"] == key][0]
    Wx = {f: [dict(w0, share=1.0)] if f == fam else [] for f in W}
    return ai_split(Wx, M, TT, {key: "alone"}, [0.5, 0.3, 0.1, 0], fam)[2] / (w0["risk"] * w0["fit"])
check("Work that can't be checked without redoing it (Type 3) gives more hidden errors when AI works alone than work that is cheap to check (Type 1), per unit of AI work",
      one("advisory_research", "delivery") > 2 * one("billing", "operations"),
      f"Type 3 {one('advisory_research', 'delivery'):.2f}, Type 1 {one('billing', 'operations'):.2f}")
wall_year = lambda rows: next((r["year"] for r in rows if r["wall_ratio"] > 1), None)
check("The verification wall: in the rapid future, checking needed passes what seniors can give for both teams where AI drafts and people check, and AI errors at least double after it",
      all(wall_year(R[(n, "rapid")]) for n in ("builder", "middle"))
      and all(yr(R[(n, "rapid")], 2035)["incidents"] > 2 * yr(R[(n, "rapid")], wall_year(R[(n, "rapid")]) - 1)["incidents"] for n in ("builder", "middle")),
      ", ".join(f"{n} hits it in {wall_year(R[(n, 'rapid')])}" for n in ("builder", "middle")))
check("No team hits the wall before 2031 in any future: it comes from years of growing AI output, not from one choice",
      all((wall_year(R[(n, f)]) or 9999) > 2031 for n in T for f in FUTURES))
mc = copy.deepcopy(T["middle"]); mc["2029"]["checking"] = True
check("Spending on checking capacity pays when AI keeps growing (rapid, complement) and costs little when it stalls (constrained, under $15M): middle team with and without it",
      all(summary(simulate(mc, f))["cum_profit_m"] > S[("middle", f)]["cum_profit_m"] for f in ("rapid", "complement"))
      and summary(simulate(mc, "constrained"))["cum_profit_m"] > S[("middle", "constrained")]["cum_profit_m"] - 15,
      ", ".join(f"{f} +${summary(simulate(mc, f))['cum_profit_m'] - S[('middle', f)]['cum_profit_m']:.0f}M" for f in FUTURES))
def lost30(rows):
    r = yr(rows, 2030)
    return float(r["notes"][0].split()[2]) / max(1e-9, r["ai_units"]) if r["notes"] else 0.0
check("The outage spreads further when more work runs on AI alone: per unit of AI work, the cutter loses more in 2030 than the builder (complement)",
      lost30(R[("cutter", "complement")]) > lost30(R[("builder", "complement")]),
      f"cutter {lost30(R[('cutter', 'complement')]):.3f}, builder {lost30(R[('builder', 'complement')]):.3f} units lost per unit of AI work")

if __name__ == "__main__":
    for name, ok, detail in checks:
        print(("PASS  " if ok else "FAIL  ") + name + (f"  [{detail}]" if detail else ""))
    print(f"{sum(ok for _, ok, _ in checks)} of {len(checks)} checks pass")
    raise SystemExit(0 if all(ok for _, ok, _ in checks) else 1)

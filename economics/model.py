"""Flexee: The Future CHRO — reference model of the expertise pipeline (v0.4).

Yearly stock-flow model, 2027-2035. Two job families, four levels each.
Decisions are made in decision years and held until the next one.
The model is the source of truth for every number the sim shows.
"""
import csv, json, os, copy

HERE = os.path.dirname(os.path.abspath(__file__))
FAMILIES = ["delivery", "operations"]
FUTURES = ["rapid", "complement", "constrained"]


def _rows(name):
    with open(os.path.join(HERE, "data", name)) as f:
        return list(csv.DictReader(f))


def load():
    C = {}
    for r in _rows("constants.csv"):
        v = r["value"]
        C[r["key"]] = [float(x) for x in v.split("|")] if "|" in v else float(v)
    L = {f: [None] * 4 for f in FAMILIES}
    for r in _rows("levels.csv"):
        L[r["family"]][int(r["level"])] = {k: (r[k] if k in ("family", "title") else float(r[k])) for k in r}
    F = {}
    for r in _rows("futures.csv"):
        F[(r["future"], int(r["year"]))] = {k: float(v) for k, v in r.items() if k not in ("future", "year")}
    return C, L, F


def clamp(x, lo, hi):
    return max(lo, min(hi, x))


DEFAULT = {
    # way of working for each workflow: people, assist, draft_check or alone (data/workflows.csv, data/modes.csv)
    "workflows": {},
    "tutor": 0.0,            # share of AI-drafted entry work redesigned so people draft first and AI coaches (0-1)
    "intake": {"delivery": 1.0, "operations": 1.0},  # entry hiring as a share of the usual intake
    "mentoring": 0.0,        # share of level 2-3 time spent mentoring
    "layoffs": {},           # {"delivery": [s0, s1, s2, s3]} one-off shares laid off in this decision year
    "share_gains": False,    # share productivity gains with employees (pay, hours, bonuses)
    "transparency": False,   # tell people early and plainly what is changing
    "oversight": "light",    # "strong" (a person checks every piece of client work) or "light" (samples)
    "lateral": 0.0,          # share of the outside market you try to hire to fill gaps at levels 1-3
    "knowledge": "people",   # where firm knowledge is kept: people, firm (firm-owned system) or vendor
    "planning": "hr",        # who plans the work of AI agents: hr, it or joint
    "board_report": False,   # a yearly people and skills report to the board
    "checking": False,       # invest in checking capacity: reviewer training and checking tools
    "ruth": "go",            # 2032: Ruth Adeyemi's terms: go (leaves in June), stay (two years on judgment and teaching) or capture (a one-year project)
}


START_PIPE = (0.3 * 520 + 360) / (1180 * 1.04 * (0.165 + 0.0695))


def load_work():
    W = {f: [] for f in FAMILIES}
    for r in _rows("workflows.csv"):
        W[r["family"]].append({"key": r["key"], "name": r["name"], "share": float(r["share"]),
                               "fit": float(r["ai_fit"]), "risk": float(r["risk"]), "type": int(r["task_type"])})
    M = {r["mode"]: {"ai": [float(r["ai_l0"]), float(r["ai_l1"]), float(r["ai_l2"])],
                     "review": float(r["review_rate"]), "incident": float(r["incident_weight"]),
                     "lost": float(r["practice_lost"])} for r in _rows("modes.csv")}
    T = {int(r["task_type"]): {"review": float(r["review_weight"]), "error": float(r["hidden_error_weight"])} for r in _rows("task_types.csv")}
    return W, M, T


def ai_split(W, M, T, wf, caps, f):
    """AI share of each level's work, the parts of it by mode, the review weight by mode, and error risk, for one family.
    Task type (verification economics): Type 1 is cheap to check, Type 2 checkable but slow, Type 3 can't be checked
    without redoing the work, so checking costs more and misses more."""
    a = [0.0] * 4
    by_mode = {m: [0.0] * 4 for m in M}
    rev = {m: [0.0] * 4 for m in M}
    risk = 0.0
    for w in W[f]:
        m = wf.get(w["key"], "people")
        t = T[w["type"]]
        for i in range(3):
            s = w["share"] * min(1.0, M[m]["ai"][i] * caps[i] * w["fit"])
            a[i] += s
            by_mode[m][i] += s
            rev[m][i] += s * t["review"]
        err = t["error"] if m in ("draft_check", "alone") else 1.0  # when people draft first, hidden errors don't build up
        risk += w["share"] * w["risk"] * err * M[m]["incident"] * sum(min(1.0, M[m]["ai"][i] * caps[i] * w["fit"]) for i in range(2)) / 2
    return a, by_mode, risk, rev


def simulate(team, future):
    """team: {"2027": {...}, "2029": {...}, ...} decisions. Returns one row per year."""
    C, L, F = load()
    W, M, T = load_work()
    checking_years = 0
    y0, y1 = int(C["first_year"]), int(C["last_year"])
    hc = {f: [L[f][i]["start_headcount"] for i in range(4)] for f in FAMILIES}
    start23 = sum(L[f][i]["start_headcount"] for f in FAMILIES for i in (2, 3))
    base_intake = {f: L[f][0]["start_headcount"] * (L[f][0]["attrition_base"] + L[f][0]["promo_base"]) for f in FAMILIES}
    trust, strain, influence = C["trust_start"], C["strain_start"], C["influence_start"]
    know = {"people": C["knowledge_start"], "held": 0.0}  # knowledge in people's heads, and captured in a system
    d = copy.deepcopy(DEFAULT)
    ruth_y = int(C["ruth_year"])
    ruth, ruth_left = None, False
    rows = []
    for y in range(y0, y1 + 1):
        new = team.get(str(y))
        layoffs = {}
        if new:
            for k, v in new.items():
                if k == "workflows":
                    d["workflows"].update(copy.deepcopy(v))
                else:
                    d[k] = copy.deepcopy(v)
            layoffs = new.get("layoffs", {})
        d["layoffs"] = {}
        fx = F[(future, y)]
        caps = [fx["ai_entry_cap"], fx["ai_mid_cap"], fx["ai_expert_cap"], 0.0]
        cost = 0.0
        notes = []
        strong = d["oversight"] == "strong"

        # Ruth Adeyemi's terms are fixed by the 2032 decision. She leaves at the start of 2032 (go),
        # at the end of 2032 (capture) or at the end of 2033 (stay).
        if y == ruth_y:
            ruth = d["ruth"]
        if ruth is not None and not ruth_left and y == ruth_y + {"go": 0, "capture": 1, "stay": 2}[ruth]:
            kept = {"go": C["knowledge_capture_" + d["knowledge"]],
                    "capture": max(C["ruth_capture_share"], C["knowledge_capture_" + d["knowledge"]]),
                    "stay": max(C["ruth_stay_capture"], C["knowledge_capture_" + d["knowledge"]])}[ruth]
            know["people"] -= C["ruth_knowledge"]
            know["held"] += C["ruth_knowledge"] * kept
            hc["delivery"][3] -= 1
            ruth_left = True
            notes.append(f"Ruth Adeyemi retired; {100 * kept:.0f}% of her knowledge kept")
        ruth_fee_loss = C["ruth_fees"] * C["ruth_fee_loss_" + ruth] if ruth_left and ruth is not None else 0.0
        ruth_here = ruth is not None and not ruth_left

        # one-off layoffs at the start of a decision year
        laid_off = 0.0
        for f, shares in layoffs.items():
            for i, s in enumerate(shares):
                n = hc[f][i] * s
                hc[f][i] -= n
                laid_off += n
                cost += n * L[f][i]["salary"] * C["severance_months"] / 12.0
        total_start = sum(sum(hc[f]) for f in FAMILIES)
        if laid_off:
            trust -= C["layoff_trust_hit"] * (laid_off / max(1.0, total_start + laid_off)) * 10
            if d["transparency"]:
                trust += 0.25 * C["layoff_trust_hit"] * (laid_off / (total_start + laid_off)) * 10
        trust = clamp(trust + (C["share_gain_trust"] if d["share_gains"] else 0) + (C["transparency_trust"] if d["transparency"] else 0)
                      + (0.5 if d["board_report"] else 0), 0, 100)

        # firm knowledge: the vendor changes its terms once
        if y == int(C["vendor_terms_year"]) and d["knowledge"] == "vendor":
            lost_k = know["held"] * C["vendor_knowledge_loss"]
            know["held"] -= lost_k
            notes.append(f"Vendor terms changed: {lost_k:.0f} points of firm knowledge lost")
        knowledge = know["people"] + know["held"]
        kf = 1 - C["knowledge_productivity"] * (1 - min(1.2, knowledge / C["knowledge_start"]))
        prod = (1 + 0.004 * (trust - 60)) * (1 - C["strain_productivity"] * max(0, strain - 40))

        # work: each workflow's way of working sets the AI share at each level (capped by what AI can do)
        split = {f: ai_split(W, M, T, d["workflows"], caps, f) for f in FAMILIES}
        a = {f: split[f][0] for f in FAMILIES}
        demand = C["demand_start"] * (1 + C["demand_growth"]) ** (y - y0)
        mix = C["level_mix"]
        maturity = clamp((caps[0] - 0.20) / 0.75, 0, 1)
        plan_review = {"hr": 1.10, "it": 1.0, "joint": 0.90}[d["planning"]]
        review_rate = {m: (M[m]["review"] + (C["review_strong_extra"] if strong and m != "people" else 0))
                       * (1 - C["review_learning_fall"] * maturity) * plan_review for m in M}
        if d["checking"]:
            checking_years += 1
        check_factor = 1 - C["checking_cut"] * min(1.0, checking_years / C["checking_build_years"])
        review_per_unit = {f: check_factor * sum(mix[i] * split[f][3][m][i] * review_rate[m] for m in M for i in range(3)) for f in FAMILIES}

        def units(f, scale):
            # most units this family can carry: higher levels can do lower-level work, not the reverse
            cap = [hc[f][i] * L[f][i]["work_per_person"] * prod * (kf if i >= 2 else 1) * (1 - (d["mentoring"] if i >= 2 else 0)) for i in range(4)]
            need = [mix[i] * (1 - a[f][i]) * scale for i in range(4)]
            need[1] += review_per_unit[f] * C["review_to_l1"] * scale  # checking AI is real work for seniors
            need[2] += review_per_unit[f] * (1 - C["review_to_l1"]) * scale
            u = float("inf")
            for k in range(4):
                sn = sum(need[k:])
                if sn > 1e-12:
                    u = min(u, sum(cap[k:]) / sn)
            return u, cap, need

        u_del, cap_d, need_d = units("delivery", 1.0)
        u_ops, cap_o, need_o = units("operations", C["ops_support_ratio"])
        delivered = min(demand, u_del, u_ops)
        ai_share = sum(mix[i] * a["delivery"][i] for i in range(4))
        review_units = delivered * (review_per_unit["delivery"] + C["ops_support_ratio"] * review_per_unit["operations"])

        # the outage: AI work is lost unless people have spare room to cover it
        if y == int(C["outage_year"]):
            spare = max(0.0, min(u_del, u_ops) - delivered) / max(delivered, 1e-9)
            ai_all = sum(sum(split[f][0]) for f in FAMILIES)
            alone_share = sum(sum(split[f][1]["alone"]) for f in FAMILIES) / max(ai_all, 1e-9)
            spread = C["outage_alone_base"] + C["outage_alone_slope"] * alone_share  # the more AI works alone on one vendor, the further it spreads
            lost = C["outage_loss"] * spread * ai_share * delivered * max(0.0, 1 - spare / max(ai_share, 1e-9))
            delivered -= lost
            notes.append(f"Outage cost {lost:.0f} units of work")

        revenue = delivered * C["revenue_per_unit"] * fx["price_index"] - ruth_fee_loss  # competitors using AI push prices down
        salaries = sum(hc[f][i] * L[f][i]["salary"] for f in FAMILIES for i in range(4))
        ai_cost = 0.0
        naive_saving = 0.0
        ai_units_total = 0.0
        for f, scale in (("delivery", 1.0), ("operations", C["ops_support_ratio"])):
            for i in range(3):
                ai_units = delivered * scale * mix[i] * a[f][i]
                ai_cost += ai_units * fx["ai_cost_per_unit"] * L[f][i]["salary"] / L[f][i]["work_per_person"]
                naive_saving += ai_units * L[f][i]["salary"] / L[f][i]["work_per_person"]
                ai_units_total += ai_units
        if d["knowledge"] == "vendor" and y >= int(C["vendor_terms_year"]):
            ai_cost *= 1 + C["vendor_cost_rise"]
        if d["planning"] == "it":
            ai_cost *= 0.95
        ai_cost *= 1 + (C["oversight_cost"] if strong else 0)
        risk = sum(split[f][2] * (1.0 if f == "delivery" else C["ops_support_ratio"]) for f in FAMILIES)
        # the verification wall: checking needed against the share of senior time that can go to checking
        senior_units = sum(cap_d[i] + cap_o[i] for i in (1, 2))
        wall_ratio = review_units / max(1e-9, C["wall_share"] * senior_units)
        over = max(0.0, wall_ratio - 1)
        incidents = (fx["incident_rate"] * 100 * risk * (1 + C["strain_errors"] * max(0, strain - 40)) * (1 + C["wall_errors"] * over)
                     * (0.9 if d["planning"] == "joint" else 1))
        incident_cost = incidents * C["incident_cost"] * (1 - C["oversight_cut"] if strong else 1)
        regulation = fx["regulation_cost"] * revenue * ai_share * (0.5 if strong else 1.0)
        bm0 = split["delivery"][1]
        entry_ai = a["delivery"][0]
        coached = (bm0["assist"][0] + bm0["draft_check"][0] * d["tutor"]) / entry_ai if entry_ai > 0 else 0.0
        tutor_cost = 8000 * hc["delivery"][0] * coached  # coaching design: people draft first and AI coaches
        hires = {f: base_intake[f] * d["intake"][f] for f in FAMILIES}
        hire_cost = sum(hires.values()) * 5000
        extra = ({"people": 0, "firm": C["knowledge_cost_firm"], "vendor": C["knowledge_cost_vendor"]}[d["knowledge"]]
                 + (C["planning_joint_cost"] if d["planning"] == "joint" else 0) + (C["board_report_cost"] if d["board_report"] else 0) + (C["checking_cost"] if d["checking"] else 0)
                 + (C["ruth_stay_cost"] if ruth == "stay" and ruth_here else 0)
                 + (C["ruth_capture_cost"] if ruth == "capture" and ruth_here else 0))
        cost += salaries + ai_cost + incident_cost + regulation + tutor_cost + hire_cost + C["overhead"] + extra
        profit = revenue - cost

        # strain: checking AI and stretched teams wear people down
        seniors = sum(hc[f][i] for f in FAMILIES for i in (1, 2))
        stretch = max(0.0, delivered / max(1e-9, min(u_del, u_ops)) - 0.95)
        strain += (C["strain_per_review"] * review_units / max(1.0, seniors) + C["strain_per_stretch"] * stretch
                   - (C["strain_relief_gains"] if d["share_gains"] else 0) + (1.5 if d["planning"] == "it" else 0) + C["wall_strain"] * over
                   - C["strain_recovery"] * (strain - C["strain_start"]))
        strain = clamp(strain, 0, 100)

        # ---- people move at the end of the year ----
        ment = clamp((d["mentoring"] + (C["ruth_stay_mentor"] if ruth == "stay" and ruth_here else 0)) / C["mentor_full"], 0, 1)
        lateral_market = C["lateral_market_base"] * (C["lateral_scarcity_rapid"] if future == "rapid" else 1.0)
        bench = {}
        out23 = 0.0
        for f in FAMILIES:
            scale = 1.0 if f == "delivery" else C["ops_support_ratio"]
            bm = split[f][1]
            next_need = [demand * (1 + C["demand_growth"]) * scale * mix[i] * (1 - a[f][i]) / prod for i in range(4)]
            def reps(i):
                lost = sum(bm[m][i] * M[m]["lost"] for m in M)
                lost -= bm["draft_check"][i] * d["tutor"]
                r = 1 - lost * C["substitute_penalty"]
                if i == 1:
                    r -= C["check_deskill"] * (bm["draft_check"][1] + bm["draft_check"][0])
                return r
            h = hc[f][:]
            out = []
            for i in range(4):
                attr = (L[f][i]["attrition_base"] + C["trust_attrition"] * max(0, 60 - trust)
                        + (0.06 * (1 - reps(0)) if i == 0 else 0)
                        + (C["strain_attrition"] * max(0, strain - 40) if i in (1, 2) else 0))
                out.append(h[i] * (attr + L[f][i]["retire"]))
            out23 += out[2] + out[3]
            h = [h[i] - out[i] for i in range(4)]
            for i in (2, 1, 0):  # promote top-down so gaps open before they are filled
                assist_share = bm["assist"][i] / max(1e-9, a[f][i]) if a[f][i] > 0 else 0
                tutor_eff = clamp(assist_share + (1 - assist_share) * d["tutor"], 0, 1) if a[f][i] > 0 else 0
                tutor_bonus = C["learn_tutor_bonus"] * tutor_eff * min(1.0, caps[0] / 0.5)
                lf = C["learn_reps_weight"] * reps(i) + C["learn_mentor_weight"] * ment + (1 - C["learn_reps_weight"] - C["learn_mentor_weight"]) + tutor_bonus
                ready = h[i] * L[f][i]["promo_base"] * 2.0 * lf
                gap = max(0.0, next_need[i + 1] - h[i + 1])
                p = min(ready, gap)
                h[i] -= p
                h[i + 1] += p
                still = max(0.0, next_need[i + 1] - h[i + 1])
                if still > 0 and d["lateral"] > 0:
                    lat = min(still, d["lateral"] * lateral_market * (h[i + 1] + still))
                    h[i + 1] += lat
                    profit -= lat * L[f][i + 1]["salary"] * (C["lateral_cost_mult"] - 1)
            h[0] += hires[f]
            hc[f] = h
            # pipeline strength: people coming up through levels 0-1, against the senior work they will have to carry
            bench[f] = (0.3 * h[0] + h[1]) / max(1e-9, next_need[2] + next_need[3]) / START_PIPE

        # knowledge leaves with experts unless it was captured; experts at work renew it
        now23 = sum(hc[f][i] for f in FAMILIES for i in (2, 3))
        capture = C["knowledge_capture_" + d["knowledge"]]
        leave_share = out23 / max(1.0, now23 + out23)
        know["held"] += know["people"] * leave_share * capture
        know["people"] -= know["people"] * leave_share
        know["people"] += C["knowledge_renew"] * C["knowledge_start"] * min(1.0, now23 / start23) * (0.85 + 0.15 * ment)
        know["people"] = clamp(know["people"], 0, 1.2 * C["knowledge_start"])
        know["held"] = clamp(know["held"], 0, 0.6 * C["knowledge_start"])

        influence += ({"hr": 1.0, "it": -3.0, "joint": 2.0}[d["planning"]] + (2.5 if d["board_report"] else 0)
                      + (2 if profit > 0.15 * revenue else -2) + (1 if delivered / demand > 0.95 else -2))
        influence = clamp(influence, 0, 100)
        trust += 0.10 * (60 - trust)

        rows.append({
            "year": y, "future": future, "demand": demand, "delivered": delivered,
            "revenue": revenue, "cost": cost, "profit": profit, "ai_cost": ai_cost, "naive_saving": naive_saving, "ai_units": ai_units_total,
            "review_units": review_units, "wall_ratio": wall_ratio, "strain": strain, "knowledge": know["people"] + know["held"],
            "knowledge_held": know["held"], "influence": influence,
            "ai_share": ai_share, "incidents": incidents, "trust": trust,
            "headcount": sum(sum(hc[f]) for f in FAMILIES),
            "delivery_levels": [round(x, 1) for x in hc["delivery"]],
            "operations_levels": [round(x, 1) for x in hc["operations"]],
            "expert_bench": bench["delivery"], "ops_bench": bench["operations"],
            "met_demand": delivered / demand, "notes": notes,
        })
    return rows


WEIGHTS = {"financial": 0.40, "capability": 0.35, "trust": 0.25}  # decision S1


def summary(rows):
    profit = sum(r["profit"] for r in rows)
    end = rows[-1]
    financial = clamp(profit / 1e9, 0, 1)                      # $1 billion over 2027-2035 earns full marks
    capability = clamp(0.5 * min(end["expert_bench"], 1.0) + 0.5 * end["met_demand"], 0, 1)
    trust = end["trust"] / 100
    overall = 100 * (WEIGHTS["financial"] * financial + WEIGHTS["capability"] * capability + WEIGHTS["trust"] * trust)
    return {"cum_profit_m": profit / 1e6, "end_bench": end["expert_bench"], "end_met": end["met_demand"],
            "end_trust": end["trust"], "end_headcount": end["headcount"],
            "financial": financial, "capability": capability, "overall": overall}


def judgment(team_decisions):
    """The same decisions replayed under all three futures; judgment is the worst case."""
    s = {f: summary(simulate(team_decisions, f))["overall"] for f in FUTURES}
    return min(s.values()), s


def team(name):
    return json.load(open(os.path.join(HERE, "teams", name + ".json")))


if __name__ == "__main__":
    for name in ("builder", "middle", "cutter", "waiter"):
        for fut in FUTURES:
            rs = simulate(team(name), fut)
            s = summary(rs)
            prof = " ".join(f"{r['profit']/1e6:6.1f}" for r in rs)
            met = " ".join(f"{r['met_demand']:.2f}" for r in rs)
            print(f"{name:8} {fut:11} cum {s['cum_profit_m']:7.1f}  bench {s['end_bench']:.2f}  trust {s['end_trust']:4.1f}  hc {s['end_headcount']:6.0f}  overall {s['overall']:5.1f}")
            print(f"{'':20} profit {prof}")
            print(f"{'':20} met    {met}")
            print(f"{'':20} strain " + " ".join(f"{r['strain']:5.1f}" for r in rs) + "  know " + " ".join(f"{r['knowledge']:5.0f}" for r in rs))

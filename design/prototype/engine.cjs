// Port of chro/model.py simulate() (v0.3). Must reproduce the Python fixtures.
function chroEngine(D) {
  const FAM = ["delivery", "operations"];
  const clamp = (x, lo, hi) => Math.max(lo, Math.min(hi, x));
  const clone = o => JSON.parse(JSON.stringify(o));
  const DEFAULT = { workflows: {}, tutor: 0, intake: { delivery: 1, operations: 1 }, mentoring: 0, layoffs: {},
    share_gains: false, transparency: false, oversight: "light", lateral: 0, knowledge: "people",
    planning: "hr", board_report: false, checking: false };
  const START_PIPE = (0.3 * 520 + 360) / (1180 * 1.04 * (0.165 + 0.0695));
  const C = D.C, L = D.L, M = D.M, T = D.T, W = D.W;
  const modes = Object.keys(M);
  const sum = a => a.reduce((s, x) => s + x, 0);

  function aiSplit(wf, caps, f) {
    const a = [0, 0, 0, 0], by = {}, rev = {};
    modes.forEach(m => { by[m] = [0, 0, 0, 0]; rev[m] = [0, 0, 0, 0]; });
    let risk = 0;
    for (const w of W[f]) {
      const m = wf[w.key] || "people", t = T[String(w.type)];
      for (let i = 0; i < 3; i++) {
        const s = w.share * Math.min(1, M[m].ai[i] * caps[i] * w.fit);
        a[i] += s; by[m][i] += s; rev[m][i] += s * t.review;
      }
      const err = (m === "draft_check" || m === "alone") ? t.error : 1;
      let r2 = 0; for (let i = 0; i < 2; i++) r2 += Math.min(1, M[m].ai[i] * caps[i] * w.fit);
      risk += w.share * w.risk * err * M[m].incident * r2 / 2;
    }
    return [a, by, risk, rev];
  }

  function simulate(team, future, lastYear) {
    const y0 = C.first_year, y1 = lastYear || C.last_year;
    const hc = {}; FAM.forEach(f => hc[f] = L[f].map(l => l.start_headcount));
    const start23 = sum(FAM.map(f => L[f][2].start_headcount + L[f][3].start_headcount));
    const baseIntake = {}; FAM.forEach(f => baseIntake[f] = L[f][0].start_headcount * (L[f][0].attrition_base + L[f][0].promo_base));
    let trust = C.trust_start, strain = C.strain_start, influence = C.influence_start, checkingYears = 0;
    const know = { people: C.knowledge_start, held: 0 };
    const d = clone(DEFAULT); const rows = [];
    for (let y = y0; y <= y1; y++) {
      const nw = team[String(y)]; let layoffs = {};
      if (nw) { for (const k in nw) { if (k === "workflows") Object.assign(d.workflows, clone(nw[k])); else d[k] = clone(nw[k]); } layoffs = nw.layoffs || {}; }
      d.layoffs = {};
      const fx = D.F[future + "|" + y];
      const caps = [fx.ai_entry_cap, fx.ai_mid_cap, fx.ai_expert_cap, 0];
      let cost = 0; const notes = []; const strong = d.oversight === "strong";
      let laid = 0;
      for (const f in layoffs) layoffs[f].forEach((s, i) => { const n = hc[f][i] * s; hc[f][i] -= n; laid += n; cost += n * L[f][i].salary * C.severance_months / 12; });
      const totalStart = sum(FAM.map(f => sum(hc[f])));
      if (laid) {
        trust -= C.layoff_trust_hit * (laid / Math.max(1, totalStart + laid)) * 10;
        if (d.transparency) trust += 0.25 * C.layoff_trust_hit * (laid / (totalStart + laid)) * 10;
      }
      trust = clamp(trust + (d.share_gains ? C.share_gain_trust : 0) + (d.transparency ? C.transparency_trust : 0) + (d.board_report ? 0.5 : 0), 0, 100);
      if (y === C.vendor_terms_year && d.knowledge === "vendor") { const lk = know.held * C.vendor_knowledge_loss; know.held -= lk; notes.push("Vendor terms changed: " + lk.toFixed(0) + " points of firm knowledge lost"); }
      const knowledge = know.people + know.held;
      const kf = 1 - C.knowledge_productivity * (1 - Math.min(1.2, knowledge / C.knowledge_start));
      const prod = (1 + 0.004 * (trust - 60)) * (1 - C.strain_productivity * Math.max(0, strain - 40));
      const split = {}; FAM.forEach(f => split[f] = aiSplit(d.workflows, caps, f));
      const a = {}; FAM.forEach(f => a[f] = split[f][0]);
      const demand = C.demand_start * Math.pow(1 + C.demand_growth, y - y0);
      const mix = C.level_mix;
      const maturity = clamp((caps[0] - 0.20) / 0.75, 0, 1);
      const planReview = { hr: 1.10, it: 1.0, joint: 0.90 }[d.planning];
      const reviewRate = {}; modes.forEach(m => reviewRate[m] = (M[m].review + (strong && m !== "people" ? C.review_strong_extra : 0)) * (1 - C.review_learning_fall * maturity) * planReview);
      if (d.checking) checkingYears += 1;
      const checkFactor = 1 - C.checking_cut * Math.min(1, checkingYears / C.checking_build_years);
      const rpu = {}; FAM.forEach(f => { let s = 0; modes.forEach(m => { for (let i = 0; i < 3; i++) s += mix[i] * split[f][3][m][i] * reviewRate[m]; }); rpu[f] = checkFactor * s; });
      function units(f, scale) {
        const cap = [0, 1, 2, 3].map(i => hc[f][i] * L[f][i].work_per_person * prod * (i >= 2 ? kf : 1) * (1 - (i >= 2 ? d.mentoring : 0)));
        const need = [0, 1, 2, 3].map(i => mix[i] * (1 - a[f][i]) * scale);
        need[1] += rpu[f] * C.review_to_l1 * scale; need[2] += rpu[f] * (1 - C.review_to_l1) * scale;
        let u = Infinity;
        for (let k = 0; k < 4; k++) { const sn = sum(need.slice(k)); if (sn > 1e-12) u = Math.min(u, sum(cap.slice(k)) / sn); }
        return [u, cap];
      }
      const [uDel, capD] = units("delivery", 1), [uOps, capO] = units("operations", C.ops_support_ratio);
      let delivered = Math.min(demand, uDel, uOps);
      const aiShare = sum([0, 1, 2, 3].map(i => mix[i] * a.delivery[i]));
      const reviewUnits = delivered * (rpu.delivery + C.ops_support_ratio * rpu.operations);
      if (y === C.outage_year) {
        const spare = Math.max(0, Math.min(uDel, uOps) - delivered) / Math.max(delivered, 1e-9);
        const aiAll = sum(FAM.map(f => sum(split[f][0])));
        const aloneShare = sum(FAM.map(f => sum(split[f][1].alone))) / Math.max(aiAll, 1e-9);
        const spread = C.outage_alone_base + C.outage_alone_slope * aloneShare;
        const lost = C.outage_loss * spread * aiShare * delivered * Math.max(0, 1 - spare / Math.max(aiShare, 1e-9));
        delivered -= lost; notes.push("Outage cost " + lost.toFixed(0) + " units of work");
      }
      const revenue = delivered * C.revenue_per_unit * fx.price_index;
      const salaries = sum(FAM.map(f => sum([0, 1, 2, 3].map(i => hc[f][i] * L[f][i].salary))));
      let aiCost = 0, naive = 0, aiUnits = 0;
      [["delivery", 1], ["operations", C.ops_support_ratio]].forEach(([f, sc]) => { for (let i = 0; i < 3; i++) { const u = delivered * sc * mix[i] * a[f][i]; aiCost += u * fx.ai_cost_per_unit * L[f][i].salary / L[f][i].work_per_person; naive += u * L[f][i].salary / L[f][i].work_per_person; aiUnits += u; } });
      if (d.knowledge === "vendor" && y >= C.vendor_terms_year) aiCost *= 1 + C.vendor_cost_rise;
      if (d.planning === "it") aiCost *= 0.95;
      aiCost *= 1 + (strong ? C.oversight_cost : 0);
      const risk = sum(FAM.map(f => split[f][2] * (f === "delivery" ? 1 : C.ops_support_ratio)));
      const seniorUnits = sum([1, 2].map(i => capD[i] + capO[i]));
      const wallRatio = reviewUnits / Math.max(1e-9, C.wall_share * seniorUnits);
      const over = Math.max(0, wallRatio - 1);
      const incidents = fx.incident_rate * 100 * risk * (1 + C.strain_errors * Math.max(0, strain - 40)) * (1 + C.wall_errors * over) * (d.planning === "joint" ? 0.9 : 1);
      const incidentCost = incidents * C.incident_cost * (strong ? 1 - C.oversight_cut : 1);
      const regulation = fx.regulation_cost * revenue * aiShare * (strong ? 0.5 : 1);
      const bm0 = split.delivery[1], entryAi = a.delivery[0];
      const coached = entryAi > 0 ? (bm0.assist[0] + bm0.draft_check[0] * d.tutor) / entryAi : 0;
      const tutorCost = 8000 * hc.delivery[0] * coached;
      const hires = {}; FAM.forEach(f => hires[f] = baseIntake[f] * d.intake[f]);
      const hireCost = (hires.delivery + hires.operations) * 5000;
      const extra = { people: 0, firm: C.knowledge_cost_firm, vendor: C.knowledge_cost_vendor }[d.knowledge] + (d.planning === "joint" ? C.planning_joint_cost : 0) + (d.board_report ? C.board_report_cost : 0) + (d.checking ? C.checking_cost : 0);
      cost += salaries + aiCost + incidentCost + regulation + tutorCost + hireCost + C.overhead + extra;
      let profit = revenue - cost;
      const seniors = sum(FAM.map(f => hc[f][1] + hc[f][2]));
      const stretch = Math.max(0, delivered / Math.max(1e-9, Math.min(uDel, uOps)) - 0.95);
      strain += C.strain_per_review * reviewUnits / Math.max(1, seniors) + C.strain_per_stretch * stretch - (d.share_gains ? C.strain_relief_gains : 0) + (d.planning === "it" ? 1.5 : 0) + C.wall_strain * over - C.strain_recovery * (strain - C.strain_start);
      strain = clamp(strain, 0, 100);
      const ment = clamp(d.mentoring / C.mentor_full, 0, 1);
      const latMarket = C.lateral_market_base * (future === "rapid" ? C.lateral_scarcity_rapid : 1);
      const bench = {}; let out23 = 0;
      for (const f of FAM) {
        const scale = f === "delivery" ? 1 : C.ops_support_ratio, bm = split[f][1];
        const nextNeed = [0, 1, 2, 3].map(i => demand * (1 + C.demand_growth) * scale * mix[i] * (1 - a[f][i]) / prod);
        const reps = i => { let lost = 0; modes.forEach(m => lost += bm[m][i] * M[m].lost); lost -= bm.draft_check[i] * d.tutor; let r = 1 - lost * C.substitute_penalty; if (i === 1) r -= C.check_deskill * (bm.draft_check[1] + bm.draft_check[0]); return r; };
        let h = hc[f].slice(); const out = [];
        for (let i = 0; i < 4; i++) {
          const attr = L[f][i].attrition_base + C.trust_attrition * Math.max(0, 60 - trust) + (i === 0 ? 0.06 * (1 - reps(0)) : 0) + ((i === 1 || i === 2) ? C.strain_attrition * Math.max(0, strain - 40) : 0);
          out.push(h[i] * (attr + L[f][i].retire));
        }
        out23 += out[2] + out[3];
        h = h.map((x, i) => x - out[i]);
        for (const i of [2, 1, 0]) {
          const assistShare = a[f][i] > 0 ? bm.assist[i] / Math.max(1e-9, a[f][i]) : 0;
          const tutorEff = a[f][i] > 0 ? clamp(assistShare + (1 - assistShare) * d.tutor, 0, 1) : 0;
          const tutorBonus = C.learn_tutor_bonus * tutorEff * Math.min(1, caps[0] / 0.5);
          const lf = C.learn_reps_weight * reps(i) + C.learn_mentor_weight * ment + (1 - C.learn_reps_weight - C.learn_mentor_weight) + tutorBonus;
          const ready = h[i] * L[f][i].promo_base * 2 * lf;
          const gap = Math.max(0, nextNeed[i + 1] - h[i + 1]);
          const p = Math.min(ready, gap); h[i] -= p; h[i + 1] += p;
          const still = Math.max(0, nextNeed[i + 1] - h[i + 1]);
          if (still > 0 && d.lateral > 0) { const lat = Math.min(still, d.lateral * latMarket * (h[i + 1] + still)); h[i + 1] += lat; profit -= lat * L[f][i + 1].salary * (C.lateral_cost_mult - 1); }
        }
        h[0] += hires[f]; hc[f] = h;
        bench[f] = (0.3 * h[0] + h[1]) / Math.max(1e-9, nextNeed[2] + nextNeed[3]) / START_PIPE;
      }
      const now23 = sum(FAM.map(f => hc[f][2] + hc[f][3]));
      const capture = C["knowledge_capture_" + d.knowledge];
      const leave = out23 / Math.max(1, now23 + out23);
      know.held += know.people * leave * capture; know.people -= know.people * leave;
      know.people += C.knowledge_renew * C.knowledge_start * Math.min(1, now23 / start23) * (0.85 + 0.15 * ment);
      know.people = clamp(know.people, 0, 1.2 * C.knowledge_start); know.held = clamp(know.held, 0, 0.6 * C.knowledge_start);
      influence = clamp(influence + ({ hr: 1, it: -3, joint: 2 }[d.planning]) + (d.board_report ? 2.5 : 0) + (profit > 0.15 * revenue ? 2 : -2) + (delivered / demand > 0.95 ? 1 : -2), 0, 100);
      trust += 0.10 * (60 - trust);
      rows.push({ year: y, demand, delivered, revenue, cost, profit, ai_cost: aiCost, naive_saving: naive, ai_units: aiUnits, review_units: reviewUnits, wall_ratio: wallRatio, strain, knowledge: know.people + know.held, influence, ai_share: aiShare, incidents, trust, headcount: sum(FAM.map(f => sum(hc[f]))), delivery_levels: hc.delivery.slice(), operations_levels: hc.operations.slice(), expert_bench: bench.delivery, met_demand: delivered / demand, notes });
    }
    return rows;
  }
  return { simulate };
}
if (typeof module !== "undefined") module.exports = chroEngine;

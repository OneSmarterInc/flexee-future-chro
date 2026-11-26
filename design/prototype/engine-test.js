// The prototype's browser engine must reproduce the Python fixtures. Run: node design/prototype/engine-test.js
const path = require("path"), fs = require("fs");
const eng = require("./engine.js")(require("./data.json"));
const root = path.join(__dirname, "..", "..", "economics");
let worst = 0, n = 0;
for (const t of ["builder", "middle", "cutter", "waiter"]) {
  const team = JSON.parse(fs.readFileSync(path.join(root, "teams", t + ".json")));
  const fx = JSON.parse(fs.readFileSync(path.join(root, "fixtures", t + ".json")));
  for (const fut of ["rapid", "complement", "constrained"]) {
    eng.simulate(team, fut).forEach((r, i) => {
      for (const k of ["revenue", "profit", "strain", "trust", "headcount", "incidents", "knowledge", "influence", "wall_ratio"]) {
        const b = fx.futures[fut].rows[i][k];
        worst = Math.max(worst, Math.abs(r[k] - b) / Math.max(1, Math.abs(b))); n++;
      }
    });
  }
}
console.log(`${n} values compared, worst relative gap ${worst.toExponential(2)}`);
process.exit(worst < 1e-9 ? 0 : 1);

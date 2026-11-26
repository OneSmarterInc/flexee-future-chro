"""Builds the reference teams, the golden fixtures and the prototype's data file from the model.
Run: python3 economics/build.py          (writes them)
     python3 economics/build.py --check  (fails if any written file is out of date)
Never edit a fixture by hand: change the model or the data, then run this."""
import json, os, subprocess, sys
HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
CHECK = "--check" in sys.argv

subprocess.run([sys.executable, os.path.join(HERE, "make_teams.py")], check=True, cwd=HERE,
               stdout=subprocess.DEVNULL) if not CHECK else None
from model import simulate, summary, judgment, team, FUTURES, load, load_work  # noqa: E402
from validate import checks  # noqa: E402

if not all(ok for _, ok, _ in checks):
    sys.exit("The named checks must pass before fixtures are built. Run python3 economics/check.py")

files = {}
for n in ("builder", "middle", "cutter", "waiter"):
    t = team(n)
    out = {"team": n, "judgment": judgment(t)[0],
           "futures": {f: {"rows": simulate(t, f), "summary": summary(simulate(t, f))} for f in FUTURES}}
    files[os.path.join(HERE, "fixtures", n + ".json")] = json.dumps(out, indent=1, sort_keys=True)
C, L, F = load()
W, M, T = load_work()
data = {"C": C, "L": L, "F": {f"{k[0]}|{k[1]}": v for k, v in F.items()}, "W": W, "M": M, "T": {str(k): v for k, v in T.items()}}
files[os.path.join(HERE, "..", "design", "prototype", "data.json")] = json.dumps(data)

stale = []
for path, text in files.items():
    if CHECK:
        try:
            if open(path).read() != text:
                stale.append(path)
        except FileNotFoundError:
            stale.append(path)
    else:
        os.makedirs(os.path.dirname(path), exist_ok=True)
        open(path, "w").write(text)
if CHECK:
    if stale:
        sys.exit("Out of date (run python3 economics/build.py): " + ", ".join(os.path.relpath(p, HERE) for p in stale))
    print("Fixtures and prototype data are up to date.")
else:
    print(f"Wrote {len(files)} files.")

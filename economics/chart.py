"""Draws profit, pipeline strength and strain for the four reference teams under the three futures."""
import os
import matplotlib; matplotlib.use("Agg")
import matplotlib.pyplot as plt
from model import simulate, team, FUTURES, HERE
COL = {"builder": "#2a6f97", "middle": "#8d99ae", "cutter": "#d62828", "waiter": "#f4a261"}
rows_ = [("profit", "Profit, $M", 1e6), ("expert_bench", "Pipeline strength (1 = as in 2027)", 1), ("strain", "Strain (0-100)", 1)]
fig, ax = plt.subplots(3, 3, figsize=(14, 11), sharex=True)
for c, f in enumerate(FUTURES):
    for n in COL:
        rs = simulate(team(n), f)
        for r, (k, label, div) in enumerate(rows_):
            ax[r][c].plot([x["year"] for x in rs], [x[k] / div for x in rs], color=COL[n], lw=2, label=n)
    ax[0][c].set_title(f"{f.capitalize()} future")
    for r, (_, label, _) in enumerate(rows_):
        ax[r][c].grid(alpha=.3)
        if c == 0:
            ax[r][c].set_ylabel(label)
ax[0][0].axhline(0, color="k", lw=.5)
ax[0][0].legend(frameon=False)
fig.suptitle("The Future CHRO, model v0.2: four teams under three futures (Ashby Calder, 2027-2035)")
fig.tight_layout()
os.makedirs(os.path.join(HERE, "out"), exist_ok=True)
fig.savefig(os.path.join(HERE, "out", "four-teams-three-futures.png"), dpi=110)
print("chart written")

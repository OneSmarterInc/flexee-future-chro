"""Writes the four reference teams. Edit here, never in the JSON."""
import json, os
HERE = os.path.dirname(os.path.abspath(__file__))
DEL = ["audit_testing", "tax_returns", "advisory_research", "client_reporting"]
OPS = ["billing", "scheduling", "it_support", "client_service"]


def all_(mode, keys=DEL + OPS):
    return {k: mode for k in keys}


builder = {
 "2027": {"workflows": all_("assist"), "tutor": 1.0,
          "intake": {"delivery": 0.70, "operations": 0.60}, "mentoring": 0.06, "share_gains": True,
          "transparency": True, "oversight": "strong", "lateral": 0.0, "planning": "hr"},
 "2029": {"workflows": {"audit_testing": "draft_check", "billing": "draft_check", "scheduling": "draft_check"},
          "intake": {"delivery": 0.55, "operations": 0.45}, "knowledge": "firm", "planning": "joint", "board_report": True, "checking": True},
 "2031": {"workflows": {**all_("draft_check"), "advisory_research": "assist", "billing": "alone", "scheduling": "alone"},
          "intake": {"delivery": 0.50, "operations": 0.40}},
}
middle = {
 "2027": {"workflows": {**all_("draft_check", DEL), **all_("draft_check", OPS)}, "tutor": 0.4,
          "intake": {"delivery": 0.60, "operations": 0.50}, "mentoring": 0.05, "share_gains": False,
          "transparency": True, "oversight": "light", "lateral": 0.5, "planning": "hr"},
 "2029": {"workflows": all_("alone", OPS), "intake": {"delivery": 0.50, "operations": 0.40},
          "layoffs": {"delivery": [0.05, 0, 0, 0], "operations": [0.08, 0, 0, 0]}},
 "2031": {"workflows": {"audit_testing": "alone", "tax_returns": "alone"}, "intake": {"delivery": 0.45, "operations": 0.35}},
}
cutter = {
 "2027": {"workflows": all_("alone"), "tutor": 0.0,
          "intake": {"delivery": 0.35, "operations": 0.25}, "mentoring": 0.0, "share_gains": False,
          "transparency": False, "oversight": "light", "lateral": 1.0, "planning": "it",
          "layoffs": {"delivery": [0.25, 0, 0, 0], "operations": [0.25, 0, 0, 0]}},
 "2029": {"intake": {"delivery": 0.20, "operations": 0.15}, "knowledge": "vendor",
          "layoffs": {"delivery": [0.20, 0.10, 0, 0], "operations": [0.20, 0.10, 0, 0]}},
 "2031": {"intake": {"delivery": 0.15, "operations": 0.10}},
}
waiter = {}  # changes nothing: people do every workflow, the usual hiring
for n, t in (("builder", builder), ("middle", middle), ("cutter", cutter), ("waiter", waiter)):
    json.dump(t, open(os.path.join(HERE, "teams", n + ".json"), "w"), indent=1, sort_keys=True)
print("wrote 4 teams")

"""Runs every named check on the reference model and fails if any fails. Run: python3 economics/check.py"""
import os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from validate import checks  # noqa: E402

for name, ok, detail in checks:
    print(("PASS " if ok else "FAIL ") + name + (f"  [{detail}]" if detail else ""))
failed = [n for n, ok, _ in checks if not ok]
print(f"\n{len(checks) - len(failed)} of {len(checks)} checks pass")
sys.exit(1 if failed else 0)

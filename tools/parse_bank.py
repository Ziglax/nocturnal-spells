"""Parse a guild bank export (xlsx) into the app's seed dataset.

One-shot dataset consolidation: reads the "Spell bank" tab and produces
data/spells.js (a JS module assigning window.SEED_DATA) with:
  - per-class spell lists (name, level, era)
  - turn-in pools per PoK librarian NPC (Ethereal / Spectral / Glyphed)
  - pool-size validation against the odds published on pqdi.cc ("random 1/N")
The app itself has no runtime dependency on the guild bank export.

Usage: python tools/parse_bank.py "<path to xlsx>"
"""
import json
import re
import sys
from collections import defaultdict
from pathlib import Path

import openpyxl

XLSX = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\zigla\Downloads\_Nocturnal_ Bank.xlsx"
OUT = Path(__file__).resolve().parent.parent / "data" / "spells.js"

TURNIN_ITEMS = ("Ethereal Parchment", "Spectral Parchment", "Glyphed Rune Word")
TURNIN_RE = re.compile(
    r"(Ethereal Parchment|Spectral Parchment|Glyphed Rune Word)\s*->\s*([^(;|]+?)\s*\(PoK, random 1/(\d+)\)"
)

wb = openpyxl.load_workbook(XLSX, read_only=True, data_only=True)
ws = wb["Spell bank"]

classes = []          # [{name, spells:[...]}]
current = None
npc_hits = defaultdict(lambda: defaultdict(int))  # class -> npc -> count

for row in ws.iter_rows(values_only=True):
    if all(v is None for v in row):
        continue
    _, name, lvl, item_name, era, quested, bank, codex, source = (list(row) + [None] * 9)[:9]
    if name == "Spell name":
        continue
    if name and lvl is None and item_name is None:
        current = {"name": str(name).strip(), "spells": []}
        classes.append(current)
        continue
    if current is None or not name:
        continue
    turnins = []
    if source:
        for m in TURNIN_RE.finditer(str(source)):
            item, npc, denom = m.group(1), m.group(2).strip(), int(m.group(3))
            turnins.append({"item": item, "npc": npc, "denom": denom})
            npc_hits[current["name"]][npc] += 1
    current["spells"].append({
        "name": str(name).strip(),
        "level": int(lvl) if lvl else None,
        "itemName": str(item_name).strip() if item_name else None,
        "era": str(era).strip() if era else None,
        "turnins": turnins,
    })

# Patches for turn-ins lost to truncated "Source (pqdi.cc)" cells in the sheet
# (the Wizard rows for these two multi-class spells end mid-pattern with
# "...Channeler Olaemos (PoK, ra"). Verified against pqdi.cc:
# https://www.pqdi.cc/script-entities/poknowledge/Channeler_Olaemos
PQDI_PATCHES = [
    # (class, spell name, item, npc, denom)
    ("Wizard", "Shield of the Arcane", "Ethereal Parchment", "Channeler Olaemos", 8),
    ("Wizard", "Shield of Maelin", "Spectral Parchment", "Channeler Olaemos", 8),
]
for cls_name, spell_name, item, npc, denom in PQDI_PATCHES:
    cls = next(c for c in classes if c["name"] == cls_name)
    sp = next((s for s in cls["spells"] if s["name"] == spell_name), None)
    if sp is None:
        # Spell row missing entirely: clone level/itemName from another class (same scroll).
        donor = next(s for c in classes for s in c["spells"]
                     if s["name"] == spell_name and s["turnins"])
        sp = {**donor, "turnins": []}
        cls["spells"].append(sp)
    if not any(t["item"] == item and t["npc"] == npc for t in sp["turnins"]):
        sp["turnins"].append({"item": item, "npc": npc, "denom": denom})
        npc_hits[cls_name][npc] += 1
        print(f"PATCH: {cls_name} / {spell_name} -> {item} @ {npc} (1/{denom})")

# Map each class to its librarian NPC = the NPC most often cited in that class's section.
class_npc = {}
for cls, hits in npc_hits.items():
    class_npc[cls] = max(hits, key=hits.get)

# Build NPC pools: npc -> item -> {denom, spells:[{itemName, name, classes:[{class, level}]}]}
# The pool rewards are scroll ITEMS, so dedupe by scroll item name: the same scroll
# can be scribed by several classes at different levels and appears once per class
# section in the sheet.
pools = defaultdict(lambda: defaultdict(lambda: {"denom": None, "spells": []}))
for cls in classes:
    for sp in cls["spells"]:
        for t in sp["turnins"]:
            pool = pools[t["npc"]][t["item"]]
            pool["denom"] = t["denom"]
            scroll = sp["itemName"] or sp["name"]
            entry = next((s for s in pool["spells"] if s["itemName"] == scroll), None)
            if entry is None:
                entry = {"itemName": scroll, "name": sp["name"], "classes": []}
                pool["spells"].append(entry)
            entry["classes"].append({"class": cls["name"], "level": sp["level"]})

# Validation: pool size vs published odds
print("=== NPC pools (item: found spells / published pool size) ===")
report = []
for npc in sorted(pools):
    owner = next((c for c, n in class_npc.items() if n == npc), "?")
    for item in TURNIN_ITEMS:
        if item in pools[npc]:
            p = pools[npc][item]
            flag = "OK " if len(p["spells"]) == p["denom"] else "MISMATCH"
            line = f"{flag} {npc} [{owner}] {item}: {len(p['spells'])}/{p['denom']}"
            print(line)
            report.append(line)

# Flatten pools to the turn-in rule the guild uses: a pool spell belongs to
# the NPC's class, at that class's level - multiclass scrolls are NOT shared
# across classes in the turn-in process.
flat_pools = {}
for npc in pools:
    owner = next((c for c, n in class_npc.items() if n == npc), None)
    flat_pools[npc] = {}
    for item in TURNIN_ITEMS:
        if item not in pools[npc]:
            continue
        p = pools[npc][item]
        spells = []
        for s in p["spells"]:
            attr = next((x for x in s["classes"] if x["class"] == owner), None)
            if attr is None:
                print(f"WARNING: {npc} ({owner}) {item}: '{s['name']}' has no {owner} row in the sheet")
            spells.append({"itemName": s["itemName"], "name": s["name"],
                           "level": attr["level"] if attr else None})
        flat_pools[npc][item] = {"denom": p["denom"], "spells": spells}

data = {
    "generatedFrom": Path(XLSX).name,
    "classes": [
        {
            "name": c["name"],
            "npc": class_npc.get(c["name"]),
            "spells": c["spells"],
        }
        for c in classes
    ],
    "pools": flat_pools,
    "validation": report,
}

OUT.parent.mkdir(parents=True, exist_ok=True)
OUT.write_text("// Auto-generated by tools/parse_bank.py - do not edit by hand.\n"
               "window.SEED_DATA = " + json.dumps(data, indent=2) + ";\n",
               encoding="utf-8")
print(f"\nWrote {OUT} ({OUT.stat().st_size // 1024} KB)")
print(f"Classes: {[c['name'] + ' -> ' + str(class_npc.get(c['name'])) for c in classes]}")

# Compact pools-only summary for cross-checking against pqdi.cc.
summary = {
    npc: {
        "class": next((c for c, n in class_npc.items() if n == npc), None),
        "pools": {
            item: {
                "denom": pools[npc][item]["denom"],
                "spells": [s["name"] for s in pools[npc][item]["spells"]],
            }
            for item in TURNIN_ITEMS if item in pools[npc]
        },
    }
    for npc in sorted(pools)
}
SUMMARY_OUT = Path(__file__).resolve().parent / "pools_summary.json"
SUMMARY_OUT.write_text(json.dumps(summary, indent=1), encoding="utf-8")
print(f"Wrote {SUMMARY_OUT}")


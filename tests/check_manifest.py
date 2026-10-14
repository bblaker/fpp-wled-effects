#!/usr/bin/env python3
"""Check pluginInfo.json's "versions" against how FPP's Plugin Manager reads it.

FPP only honours an open-ended entry ("maxFPPVersion": "0") within the major
version named by its minFPPVersion; on any later major the plugin shows as
"Not updated for FPP N". So:

  - the last entry must be open-ended and start at the newest FPP major
    (looked up from FPP's latest GitHub release unless --fpp-major is given);
  - every earlier entry must be closed and pinned to a commit on this
    repository's history with updates off, as PLUGININFO_FORMAT.md asks.

Exit status is non-zero on any problem.
"""

import argparse
import json
import os
import subprocess
import sys
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def latest_fpp_major():
    req = urllib.request.Request(
        "https://api.github.com/repos/FalconChristmas/fpp/releases/latest",
        headers={"Accept": "application/vnd.github+json"},
    )
    token = os.environ.get("GITHUB_TOKEN")
    if token:
        req.add_header("Authorization", "Bearer " + token)
    with urllib.request.urlopen(req, timeout=20) as r:
        tag = json.load(r)["tag_name"]
    return int(tag.lstrip("v").split(".")[0]), tag


def major(v):
    return int(str(v).lstrip("v").split(".")[0])


def is_open(v):
    return v in ("0", "0.0", "", None)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--fpp-major", type=int, help="newest FPP major (default: from FPP's latest release)")
    args = ap.parse_args()

    with open(os.path.join(ROOT, "pluginInfo.json")) as f:
        versions = json.load(f)["versions"]

    errors = []
    if args.fpp_major is not None:
        newest, source = args.fpp_major, "--fpp-major"
    else:
        newest, tag = latest_fpp_major()
        source = "latest FPP release " + tag

    last = versions[-1]
    if not is_open(last.get("maxFPPVersion")):
        errors.append("the last versions entry must be open-ended (maxFPPVersion \"0\")")
    elif major(last["minFPPVersion"]) != newest:
        errors.append(
            "the open-ended entry starts at FPP %s, but the newest FPP major is %d (%s): "
            "FPP %d shows the plugin as \"Not updated for FPP %d\". Verify the plugin on FPP %d "
            "(tests/fpp_integration.sh), then pin the current entry and add one starting at \"%d.0\"."
            % (last["minFPPVersion"], newest, source, newest, newest, newest, newest)
        )
    if last.get("sha"):
        errors.append("the open-ended entry should track its branch (\"sha\": \"\")")

    for i, v in enumerate(versions[:-1]):
        where = "versions[%d] (FPP %s-%s)" % (i, v.get("minFPPVersion"), v.get("maxFPPVersion"))
        if is_open(v.get("maxFPPVersion")):
            errors.append(where + ": only the last entry may be open-ended")
        sha = v.get("sha", "")
        if not sha:
            errors.append(where + ": older FPP majors must be pinned to a commit (\"sha\")")
        else:
            r = subprocess.run(
                ["git", "-C", ROOT, "merge-base", "--is-ancestor", sha, "HEAD"],
                capture_output=True,
            )
            if r.returncode != 0:
                errors.append(where + ": sha %s is not in this branch's history" % sha)
        if v.get("allowUpdates", 1) != 0:
            errors.append(where + ": a pinned entry should set \"allowUpdates\": 0")

    for v in versions:
        print("  FPP %-5s - %-5s  %s @ %s" % (
            v["minFPPVersion"], v["maxFPPVersion"] or "open", v["branch"], v["sha"] or "latest"))
    print("  newest FPP major: %d (%s)" % (newest, source))
    if errors:
        for e in errors:
            print("ERROR: " + e)
        return 1
    print("versions OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())

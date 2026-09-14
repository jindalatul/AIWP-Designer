#!/usr/bin/env python3
"""Drive the AIWP MCP server from the command line, the way an AI client would."""
import json, sys, urllib.request, pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent
URL = "http://localhost:8090/wp-json/aiwp-designer/v1/mcp"
TOKEN = (ROOT / ".mcp-token").read_text().strip()
_id = [0]


def rpc(method, params=None):
    _id[0] += 1
    body = json.dumps({"jsonrpc": "2.0", "id": _id[0], "method": method, "params": params or {}}).encode()
    req = urllib.request.Request(URL, data=body, headers={
        "Content-Type": "application/json", "Authorization": f"Bearer {TOKEN}"})
    with urllib.request.urlopen(req) as r:
        return json.loads(r.read())


def tool(name, args=None):
    out = rpc("tools/call", {"name": name, "arguments": args or {}})
    if "result" not in out:
        raise SystemExit(json.dumps(out, indent=2))
    return out["result"]["structuredContent"]


def workflow(kind, page_id=None):
    args = {"workflow_type": kind}
    if page_id:
        args["page_id"] = page_id
    return tool("workflow_prepare", args)["workflow_id"]


def create(package):
    package["workflow_id"] = workflow("build_page")
    return tool("page_create", package)


def update(page_id, package):
    package["workflow_id"] = workflow("redesign_page", page_id)
    package["page_id"] = page_id
    package["expected_version"] = tool("page_get", {"page_id": page_id})["version"]
    return tool("page_update", package)


if __name__ == "__main__":
    print(json.dumps(tool(sys.argv[1], json.loads(sys.argv[2]) if len(sys.argv) > 2 else {}), indent=2)[:4000])

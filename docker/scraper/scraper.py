#!/usr/bin/env python3
"""
Xplendor Scraper Adapter
Placeholder entry point invoked by Laravel's RunScraperJob.
Accepts --source and --filters (JSON string), prints what it received.
Exit 0 on success, 1 on error.
"""

import argparse
import json
import sys


def main() -> int:
    parser = argparse.ArgumentParser(description="Xplendor scraper adapter")
    parser.add_argument("--source", required=True, help="Data source (e.g. standvirtual)")
    parser.add_argument("--mode", required=True, choices=["preview", "persist"], help="preview: print only; persist: send to Laravel")
    parser.add_argument("--filters", required=True, help="JSON-encoded filter object")

    args = parser.parse_args()

    try:
        filters = json.loads(args.filters)
    except json.JSONDecodeError as exc:
        print(f"ERROR: --filters is not valid JSON: {exc}", file=sys.stderr)
        return 1

    print(json.dumps({
        "status": "ok",
        "source": args.source,
        "mode": args.mode,
        "filters": filters,
        "message": "Placeholder — real scraper logic not yet wired.",
    }, ensure_ascii=False, indent=2))

    return 0


if __name__ == "__main__":
    sys.exit(main())

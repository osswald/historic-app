#!/usr/bin/env python3
"""Read the Historic Team Winterthur events. Nothing is written back to Spond."""

from __future__ import annotations

import asyncio
import json
import os
import sys
from datetime import datetime, timezone

from spond import spond

GROUP_NAME = "Historic Team Winterthur"
EVENT_HEADING = "Führung inbahn"
EVENT_DAY = "2026-11-14"


def credentials() -> tuple[str, str]:
    username = os.environ.get("SPOND_USERNAME", "").strip()
    password = os.environ.get("SPOND_PASSWORD", "").strip()
    if username == "" or password == "":
        print("SPOND_USERNAME und SPOND_PASSWORD fehlen.", file=sys.stderr)
        raise SystemExit(2)
    return username, password


def text(value: object) -> str | None:
    if not isinstance(value, str):
        return None
    value = value.strip()
    return value or None


def string_ids(value: object) -> list[str]:
    if not isinstance(value, list):
        return []
    ids: list[str] = []
    for item in value:
        if isinstance(item, str) and item.strip():
            ids.append(item.strip())
    return ids


def count_ids(value: object) -> int:
    if isinstance(value, int) and value >= 0:
        return value
    return len(string_ids(value))


def members(group: dict) -> list[dict[str, str]]:
    rows: list[dict[str, str]] = []
    seen: set[str] = set()
    for member in group.get("members") or []:
        if not isinstance(member, dict):
            continue
        spond_id = text(member.get("id"))
        if spond_id is None or spond_id in seen:
            continue
        seen.add(spond_id)
        rows.append({"spondId": spond_id})
    return rows


def thread_comment(event: dict) -> str | None:
    comments = event.get("comments")
    if isinstance(comments, str):
        return text(comments)
    if not isinstance(comments, list):
        return None
    parts: list[str] = []
    for item in comments:
        if isinstance(item, str):
            part = text(item)
        elif isinstance(item, dict):
            part = text(item.get("comment")) or text(item.get("text")) or text(item.get("body")) or text(item.get("message"))
        else:
            part = None
        if part is not None:
            parts.append(part)
    if not parts:
        return None
    return "\n".join(parts)


def answer_comments(event: dict) -> list[dict[str, str]]:
    responses = event.get("responses")
    messages = responses.get("declineMessages") if isinstance(responses, dict) else None
    if not isinstance(messages, dict):
        return []
    rows: list[dict[str, str]] = []
    for spond_id, payload in messages.items():
        if not isinstance(spond_id, str) or not spond_id.strip():
            continue
        comment = text(payload) if isinstance(payload, str) else None
        if isinstance(payload, dict):
            comment = text(payload.get("message"))
        if comment is None:
            continue
        rows.append({"spondId": spond_id.strip(), "comment": comment})
    return rows


def options(event: dict) -> list[dict]:
    tasks = event.get("tasks")
    if not isinstance(tasks, dict):
        return []
    rows: list[dict] = []
    seen: set[str] = set()
    for key in ("openTasks", "assignedTasks"):
        for task in tasks.get(key) or []:
            if not isinstance(task, dict):
                continue
            spond_id = text(task.get("id"))
            name = text(task.get("name"))
            if spond_id is None or name is None or "@" in name or spond_id in seen:
                continue
            seen.add(spond_id)
            rows.append({
                "spondId": spond_id,
                "name": name,
                "description": text(task.get("description")),
                "accepted": count_ids(task.get("accepted")),
                "declined": count_ids(task.get("declined")),
                "unanswered": count_ids(task.get("unanswered")),
            })
    return rows


def on_day(event: dict, day: str) -> bool:
    start = text(event.get("startTimestamp"))
    if start is None:
        return False
    if start.startswith(day):
        return True
    try:
        instant = datetime.fromisoformat(start.replace("Z", "+00:00"))
    except ValueError:
        return False
    return instant.astimezone(timezone.utc).date().isoformat() == day


def preferred(events: list, heading: str, day: str) -> dict | None:
    matches = [
        event for event in events
        if isinstance(event, dict) and text(event.get("heading")) == heading and on_day(event, day)
    ]
    if matches:
        return matches[0]
    return None


def newest(events: list) -> dict | None:
    dated = [event for event in events if isinstance(event, dict) and text(event.get("startTimestamp"))]
    if not dated:
        return None
    return max(dated, key=lambda event: str(event.get("startTimestamp")))


def event_row(event: dict) -> dict | None:
    responses = event.get("responses") if isinstance(event.get("responses"), dict) else {}
    event_id = text(event.get("id"))
    heading = text(event.get("heading"))
    start = text(event.get("startTimestamp"))
    if event_id is None or heading is None or start is None:
        return None
    location = event.get("location")
    if isinstance(location, dict):
        location = text(location.get("feature")) or text(location.get("address")) or text(location.get("name"))
    else:
        location = text(location)
    return {
        "spondId": event_id,
        "heading": heading,
        "startTimestamp": start,
        "endTimestamp": text(event.get("endTimestamp")),
        "location": location,
        "description": text(event.get("description")),
        "comment": thread_comment(event),
        "responses": {
            "acceptedIds": string_ids(responses.get("acceptedIds")),
            "declinedIds": string_ids(responses.get("declinedIds")),
            "unansweredIds": string_ids(responses.get("unansweredIds")),
        },
        "answerComments": answer_comments(event),
        "options": options(event),
    }


def export(group: dict, events: list[dict]) -> dict:
    group_id = text(group.get("id"))
    if group_id is None or not events:
        print("Die Spond-Antwort ist unvollständig.", file=sys.stderr)
        raise SystemExit(1)
    return {
        "group": {
            "spondId": group_id,
            "name": GROUP_NAME,
            "members": members(group),
        },
        "event": events[0],
        "events": events,
    }


async def read_export(username: str, password: str) -> dict:
    client = spond.Spond(username=username, password=password)
    try:
        groups = await client.get_groups() or []
        group = next(
            (item for item in groups if isinstance(item, dict) and text(item.get("name")) == GROUP_NAME),
            None,
        )
        if not isinstance(group, dict) or text(group.get("id")) is None:
            print("Die Gruppe Historic Team Winterthur wurde nicht gefunden.", file=sys.stderr)
            raise SystemExit(1)
        group_id = str(group["id"])
        listed = await client.get_events(
            group_id=group_id,
            include_scheduled=True,
            min_start=datetime(2018, 1, 1, tzinfo=timezone.utc),
            max_start=datetime(2028, 1, 1, tzinfo=timezone.utc),
            max_events=500,
        ) or []
        rows = [row for item in listed if isinstance(item, dict) and (row := event_row(item)) is not None]
        if not rows:
            print("In der Gruppe wurde kein Termin gefunden.", file=sys.stderr)
            raise SystemExit(1)
        rows.sort(key=lambda item: str(item.get("startTimestamp")))
        return export(group, rows)
    finally:
        await client.clientsession.close()


def main() -> None:
    username, password = credentials()
    try:
        payload = asyncio.run(read_export(username, password))
    except SystemExit:
        raise
    except Exception:
        print("Spond-Leseabfrage fehlgeschlagen.", file=sys.stderr)
        raise SystemExit(1) from None
    json.dump(payload, sys.stdout, ensure_ascii=False)
    sys.stdout.write("\n")


if __name__ == "__main__":
    main()

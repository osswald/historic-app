#!/usr/bin/env python3
"""Read the Historic Team Winterthur events. Nothing is written back to Spond."""

from __future__ import annotations

import asyncio
import json
import os
import sys
from datetime import datetime, timedelta, timezone

import aiohttp
from spond import spond

# The library session used aiohttp's five-minute total cap. One slow call, or the
# run of about 350 event details, died there. sock_read still stops a stalled socket.
READ_TIMEOUT = aiohttp.ClientTimeout(total=3600, sock_connect=60, sock_read=120)

GROUP_NAME = "Historic Team Winterthur"
MAX_EVENTS = 500
EARLIEST = datetime(2000, 1, 1, tzinfo=timezone.utc)
LATEST = datetime(2045, 12, 31, tzinfo=timezone.utc)


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


def person_name(member: dict) -> str | None:
    sources = [member]
    profile = member.get("profile")
    if isinstance(profile, dict):
        sources.append(profile)
    for source in sources:
        given = text(source.get("firstName"))
        family = text(source.get("lastName"))
        full = " ".join(part for part in (given, family) if part)
        if full:
            return full
        named = text(source.get("name"))
        if named:
            return named
    return None


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
        row = {"spondId": spond_id}
        name = person_name(member)
        if name is not None:
            row["name"] = name
        rows.append(row)
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
        "events": events,
    }


async def read_window(client: spond.Spond, group_id: str, min_start: datetime, max_start: datetime) -> list[dict]:
    if min_start.date() > max_start.date():
        return []
    batch = await client.get_events(
        group_id=group_id,
        include_scheduled=True,
        min_start=min_start,
        max_start=max_start,
        max_events=MAX_EVENTS,
    ) or []
    rows: list[dict] = []
    seen: set[str] = set()
    for item in batch:
        if not isinstance(item, dict):
            continue
        event_id = text(item.get("id"))
        if event_id is None or event_id in seen:
            continue
        seen.add(event_id)
        rows.append(item)
    if len(rows) < MAX_EVENTS:
        exported: list[dict] = []
        for item in rows:
            detail = await client.get_event(str(item["id"]))
            if not isinstance(detail, dict):
                print("Ein Termin konnte nicht gelesen werden.", file=sys.stderr)
                raise SystemExit(1)
            row = event_row(detail)
            if row is None:
                print("Ein Termin ist unvollständig.", file=sys.stderr)
                raise SystemExit(1)
            exported.append(row)
        return exported
    # The client sends each bound as midnight and treats it as inclusive.
    # The left window therefore ends on the day after the midpoint, so that day stays included.
    start_day = min_start.date()
    end_day = max_start.date()
    span = (end_day - start_day).days
    mid = start_day + timedelta(days=span // 2)
    right = mid + timedelta(days=1)
    if span < 3 or not (start_day < right < end_day):
        print("Die Terminliste ist unvollständig.", file=sys.stderr)
        raise SystemExit(1)
    right_start = datetime(right.year, right.month, right.day, tzinfo=timezone.utc)
    earlier = await read_window(client, group_id, min_start, right_start)
    later = await read_window(client, group_id, right_start, max_start)
    return earlier + later


async def read_export(username: str, password: str) -> dict:
    client = spond.Spond(username=username, password=password)
    stale = client.clientsession
    client.clientsession = aiohttp.ClientSession(cookie_jar=aiohttp.CookieJar(), timeout=READ_TIMEOUT)
    await stale.close()
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
        listed = await read_window(client, group_id, EARLIEST, LATEST)
        found: dict[str, dict] = {}
        order: list[str] = []
        for row in listed:
            event_id = str(row.get("spondId") or "")
            if event_id == "" or event_id in found:
                continue
            found[event_id] = row
            order.append(event_id)
        rows = [found[event_id] for event_id in order]
        if not rows:
            print("In der Gruppe wurde kein Termin gefunden.", file=sys.stderr)
            raise SystemExit(1)
        rows.sort(key=lambda item: (str(item.get("startTimestamp")), str(item.get("spondId"))))
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

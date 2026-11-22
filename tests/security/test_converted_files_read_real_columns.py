"""Every PHP file that reads `students` must read columns `students` has.

The defect this test was written for
------------------------------------
`admin/id_cards/view_id_card.php` was the first file converted in phase 3d: its
query moved from `members` to `students`. The conversion renamed the *table* but
not the *column*, so eight reads of `$member['member_code']` survived. `students`
has `student_code`, not `member_code`, so `fetch_assoc()` simply returned no such
key: every card ever generated rendered a file name of `ID_` and a QR payload of
`<site>/member.php?code=` with the code empty. Nothing threw. Nothing logged.
The existing guard (`test_wire_contract_frozen`) only asked whether a converted
file still *queried* `members`; it never asked whether the columns it read
existed.

That is the whole class of bug this test closes: a table rename that silently
outruns its columns. It is cheap to check and it runs on source, so it catches
the mistake at the commit that introduces it rather than in production.

Scope: every PHP file under admin/, api/, backend/, scripts/ that selects from
`students`. Today that is one file; the check is written to grow with the
rename rather than to be rewritten when it does.
"""
from __future__ import annotations

import re
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]
MIGRATION = ROOT / "sql" / "105_students_table.sql"

SEARCH_DIRS = ("admin", "api", "backend", "scripts")

# ADR-0028 A3: the wire vocabulary is frozen, so a PHP file may legitimately
# rebuild a `member_*` key from a `student_*` column. Any such translation has
# to be visible as an assignment, not smuggled in as a table column.
TRANSLATION = re.compile(
    r"""\$(\w+)\['(member_[a-z_]+)'\]\s*=\s*\$?\w+\['([a-z_]+)'\]""",
)


def _students_columns() -> set[str]:
    src = MIGRATION.read_text(encoding="utf-8")
    m = re.search(
        r"CREATE TABLE IF NOT EXISTS `students` \((.*?)\n\) ENGINE", src, re.S
    )
    assert m, "the students table definition could not be found in sql/105"
    # Column lines start with a backticked identifier followed by a type word.
    return set(re.findall(r"^\s*`([a-z_][a-z0-9_]*)`\s+[A-Z]", m.group(1), re.M))


def _files_reading_students() -> list[Path]:
    pattern = re.compile(
        r"(?:FROM|JOIN|INTO|UPDATE)\s+`?students`?\b", re.I
    )
    found: list[Path] = []
    for folder in SEARCH_DIRS:
        base = ROOT / folder
        if not base.is_dir():
            continue
        for path in sorted(base.rglob("*.php")):
            if pattern.search(path.read_text(encoding="utf-8", errors="replace")):
                found.append(path)
    return found


def test_the_migration_defines_the_columns_this_test_checks_against():
    cols = _students_columns()
    assert "id" in cols
    assert "student_code" in cols
    # The very column whose absence caused the incident.
    assert "member_code" not in cols, (
        "students must not carry member_code — the rename is the point"
    )
    assert len(cols) >= 60, f"expected the full learner table, parsed {len(cols)}"


def test_there_is_at_least_one_file_to_check():
    """A silently empty scan would make every assertion below vacuous."""
    assert _files_reading_students(), (
        "no PHP file reads `students` — either the conversion was reverted or "
        "this test's scan roots are wrong; both need a human look"
    )


@pytest.mark.parametrize(
    "path", _files_reading_students(), ids=lambda p: str(p.relative_to(ROOT))
)
def test_every_key_read_from_a_students_row_exists_on_students(path: Path):
    """`$row['x']` on a `students` row must name a real column or a rebuilt key.

    A key is acceptable when either:
      * it is a column of `students`, or
      * the file assigns it from such a column (an explicit ADR-0028 A3 alias).
    """
    cols = _students_columns()
    src = path.read_text(encoding="utf-8", errors="replace")

    translated = {
        alias
        for _, alias, source_col in TRANSLATION.findall(src)
        if source_col in cols
    }
    # `$member[...]` is the row variable; anything not literal is skipped, which
    # is why the alias form has to be a plain literal assignment to be counted.
    read = set(re.findall(r"\$member\['([a-z_][a-z0-9_]*)'\]", src))

    unknown = sorted(k for k in read if k not in cols and k not in translated)
    assert not unknown, (
        f"{path.relative_to(ROOT)} reads keys that are neither columns of "
        f"`students` nor aliases built from one: {unknown}. A table rename that "
        "outruns its columns reads NULL at runtime and fails silently."
    )


def test_the_wire_alias_still_carries_the_student_code():
    """The specific regression that started this test, pinned by name.

    view_id_card.php speaks `member_code` because the card templates and the
    QR payload are wire-visible (ADR-0028 A3). It must therefore rebuild that
    key from `student_code` explicitly.
    """
    card = (ROOT / "admin/id_cards/view_id_card.php").read_text(encoding="utf-8")
    assert "$member['member_code'] = $member['student_code'] ?? '';" in card, (
        "the member_code alias is gone; the card will render an empty code"
    )
    # And it must not have quietly gone back to the members table.
    code = re.sub(r"/\*.*?\*/", "", card, flags=re.S)
    code = re.sub(r"(?m)^\s*(//|#).*$", "", code)
    assert not re.search(
        r"(?:FROM|JOIN|UPDATE|INTO)\s+`?members`?\b", code, re.I
    ), "view_id_card.php must read students, not members"

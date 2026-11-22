"""DS-2 — every column the live database has is either kept or explicitly lost.

The live production schema was supplied on 2026-11-16 (`members` had 85 columns;
`sql/105` defines 63). This test makes the difference *accountable*: each of the
30 absent columns must fall under a stated rule — an owner decision, an ADR, or
a documented relocation — or the test fails.

Why this exists rather than a one-off script: the failure mode it prevents is
silent. Dropping a column loses data, and no other test in this suite would
notice, because a dropped column simply stops being mentioned. Six months later
nobody can tell "removed deliberately" from "forgotten".
"""
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FIXTURE = ROOT / "tests/security/fixtures/prod_members_columns.json"
MIGRATION = ROOT / "sql/105_students_table.sql"

# ── KEPT: present on `students` under the same or a renamed column ──────────
RENAMED = {
    "member_code": "student_code",          # ADR-0027 M1 / ADR-0024 S5
    "legacy_member_code": "legacy_student_code",
}

# ── REMOVED BY AN OWNER DECISION (2026-11-16) ──────────────────────────────
OWNER_DECIDED = {
    "membership_tier": "owner: no membership concepts for students",
    "member_type": "owner: no membership concepts for students",
    "registration_type": "owner: no temporary registration",
    "waiting_since": "owner: no temporary registration",
}

# ── REMOVED BY A RECORDED ADR RULE ─────────────────────────────────────────
ADR_RULE = {
    "is_teacher": "M1/M3 a learner is not staff",
    "is_staff": "M1/M3 a learner is not staff",
    "is_committee": "M1/M3 a learner is not staff",
    "is_volunteer": "M1/M3 a learner is not staff",
    "is_dept_head_1": "M1/M3 a learner is not staff",
    "is_dept_head_2": "M1/M3 a learner is not staff",
    "is_dept_head_3": "M1/M3 a learner is not staff",
    "is_dept_head_4": "M1/M3 a learner is not staff",
    "is_dept_head_5": "M1/M3 a learner is not staff",
    "is_dept_head_6": "M1/M3 a learner is not staff",
    "is_dept_head_7": "M1/M3 a learner is not staff",
    "is_dept_head_8": "M1/M3 a learner is not staff",
    "age_group": "ADR-0023 A2 age is a date of birth",
    "current_section": "ADR-0023 A2 age is a date of birth",
    "baptismal_name": "S6 church identity",
    "christian_name": "S6 church identity",
    "spiritual_education": "S6 church identity",
    "spiritual_level_id": "S6 church identity",
    "work_profession": "S6 adult fact, not a learner fact",
}

# ── RELOCATED, not lost: the data still exists, on the right table ─────────
RELOCATED = {
    "guardian_city": "guardians.city",
    "guardian_sub_city": "guardians.sub_city",
    "guardian_woreda": "guardians.woreda",
    "guardian_mender": "guardians.mender",
    "guardian_block_number": "guardians.block_number",
    "guardian_house": "guardians.house",
    "doc_spiritual_path": "removed with the church document set (S6)",
}


def prod_columns():
    return json.loads(FIXTURE.read_text(encoding="utf-8"))


def student_columns():
    src = MIGRATION.read_text(encoding="utf-8")
    body = re.search(r"CREATE TABLE IF NOT EXISTS `students` \((.*?)\n\) ENGINE", src, re.S).group(1)
    return [line.strip().split("`")[1] for line in body.splitlines() if line.strip().startswith("`")]


def test_the_fixture_reflects_the_supplied_production_schema():
    """85 columns measured from the live dump of 2026-10-08."""
    assert len(prod_columns()) == 85


def test_every_production_column_is_kept_renamed_removed_or_relocated():
    prod, stu = prod_columns(), set(student_columns())
    reasons = {**OWNER_DECIDED, **ADR_RULE, **RELOCATED}
    unaccounted = []
    for col in prod:
        if col in stu:
            continue
        if RENAMED.get(col) in stu:
            continue
        if col in reasons:
            continue
        unaccounted.append(col)
    assert not unaccounted, (
        "these production columns would vanish with no recorded reason: "
        f"{unaccounted}\n"
        "Add a rule (owner decision / ADR / relocation) or keep the column."
    )


def test_every_recorded_reason_still_describes_a_real_column():
    """A reason for a column that never existed is a stale comment."""
    prod = set(prod_columns())
    stale = [c for c in {**OWNER_DECIDED, **ADR_RULE, **RELOCATED} if c not in prod]
    assert not stale, f"reasons recorded for columns not in production: {stale}"


def test_the_two_owner_decisions_are_absent_from_students():
    """The 2026-11-16 answer: no membership, no temporary registration."""
    stu = set(student_columns())
    for col in ("membership_tier", "member_type", "registration_type", "waiting_since"):
        assert col not in stu, f"{col} must not exist on students (owner decision)"


def test_no_membership_vocabulary_leaks_back_in():
    """A future column called student_tier would be the same mistake renamed."""
    src = MIGRATION.read_text(encoding="utf-8")
    body = re.search(r"CREATE TABLE IF NOT EXISTS `students` \((.*?)\n\) ENGINE", src, re.S).group(1)
    for banned in ("tier", "member_type", "student_type", "temporary", "honorary", "regular"):
        assert banned not in body.lower().replace("no membership", ""), (
            f"membership vocabulary '{banned}' reappeared in the students definition"
        )

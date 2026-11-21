"""sql/105 — pin the shape of `students` to ADR-0027 M1.

The risk this test addresses is specific: a future "helpful" change adds
`is_teacher` back to `students` because some page wanted it, which recreates
exactly the overload ADR-0027 removed. The rule is a product rule (M3: a person
is never both), so it belongs in a test and not only in a document.
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MIGRATION = ROOT / "sql/105_students_table.sql"

# ADR-0027 M1 / ADR-0023 A2 / ADR-0024 S6: these never describe a learner.
MUST_NOT_EXIST = [
    "is_teacher", "is_staff", "is_committee", "is_volunteer",
    "is_dept_head_1", "is_dept_head_8",
    "member_type", "membership_tier",
    "age_group", "current_section",
    "baptismal_name", "christian_name",
    "registration_type", "waiting_since",
    "work_profession", "spiritual_education",
]

# The learner model keeps these; losing them would mean losing the student.
MUST_EXIST = [
    "id", "student_code", "full_name_am", "student_name", "father_name",
    "gender", "date_of_birth", "status", "registered_at",
    "current_class_id", "education_level", "guardian_name",
    "student_photo_path", "id_card_status", "qr_code_path",
    "legacy_student_code",
]


def source():
    return MIGRATION.read_text(encoding="utf-8")


def students_block():
    s = source()
    m = re.search(r"CREATE TABLE IF NOT EXISTS `students` \((.*?)\n\) ENGINE", s, re.S)
    assert m, "the students table definition could not be found"
    return m.group(1)


def column_block_only():
    """Column lines only — a COMMENT that mentions age_group is not a column."""
    out = []
    for line in students_block().splitlines():
        stripped = line.strip()
        if stripped.startswith("`"):
            out.append(stripped.split("`")[1])
    return out


def test_students_table_is_created():
    assert "CREATE TABLE IF NOT EXISTS `students`" in source()


def test_no_staff_or_church_column_survives_on_students():
    cols = column_block_only()
    present = [c for c in MUST_NOT_EXIST if c in cols]
    assert not present, (
        "ADR-0027 M1 forbids these on students (staff facts, age bands, church "
        f"identity): {present}"
    )


def test_the_learner_columns_are_all_present():
    cols = column_block_only()
    missing = [c for c in MUST_EXIST if c not in cols]
    assert not missing, f"students is missing learner columns: {missing}"


def test_student_code_is_the_only_code_column():
    """S5: plain sequential, no prefix — and no `member_code` under a new name."""
    cols = column_block_only()
    assert "student_code" in cols
    assert "member_code" not in cols, "member_code must not survive on students"


def test_the_education_level_survives_because_it_is_configured_data():
    """ADR-0025: the level is what the institution set, not a derived band."""
    cols = column_block_only()
    assert "education_level" in cols
    # ...while the age band that used to stand in for it is gone
    assert "age_group" not in cols


def test_the_migration_does_not_drop_members():
    """97 files still query `members`; dropping it here would break them all."""
    body = re.sub(r"(?m)^\s*--.*$", "", source())
    assert not re.search(r"DROP\s+TABLE[^;]*`?members`?", body, re.I), (
        "105 must not drop members — the drop is a later migration, once no "
        "query names the table"
    )


def test_the_migration_is_guarded_so_a_second_run_is_a_no_op():
    s = source()
    assert "CREATE TABLE IF NOT EXISTS" in s
    assert s.count("information_schema.tables") >= 2, "table existence is checked"
    assert "PREPARE stmt FROM" in s


def test_staff_positions_are_keyed_by_the_login_identity():
    """M2: a teacher is a users row, so positions hang off user_id."""
    s = source()
    m = re.search(r"CREATE TABLE IF NOT EXISTS `user_staff_positions` \((.*?)\n\) ENGINE", s, re.S)
    assert m, "user_staff_positions is missing"
    body = m.group(1)
    assert "`user_id`" in body
    assert "REFERENCES `users`" in body
    assert "member_id" not in body, "staff positions must not be keyed by a member"


def test_the_migration_records_what_it_could_not_carry():
    """A migration that drops meaning must say so in a table, not a comment."""
    assert "migration_105_report" in source()

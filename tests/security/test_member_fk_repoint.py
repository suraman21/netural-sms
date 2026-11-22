"""sql/106 — the member foreign keys now point at students (ADR-0027 M5).

Two things are pinned here and they are different in kind:

1. WHICH referrers moved. Not "some keys changed" but the exact list, so a
   silent removal of one is a failure rather than a shrug.
2. WHICH referrer deliberately did NOT move. `member_staff_positions` belongs to
   the staff identity (M2) and must not be dragged onto students — that is the
   precise mistake ADR-0027 was written to prevent.
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MIGRATION = ROOT / "sql/106_repoint_member_fks_to_students.sql"

# Learner referrers: the row belongs to a student.
LEARNER_REFERRERS = {
    "academic_records": ("fk_ar_member", "fk_ar_student", "CASCADE"),
    "attendance_month_rollup": ("fk_amr_member", "fk_amr_student", "CASCADE"),
    "attendance_warnings": ("fk_aw_member", "fk_aw_student", "CASCADE"),
    "attendance_session_records": (
        "fk_session_record_member", "fk_session_record_student", "CASCADE"),
    "assessment_followups": ("fk_followup_member", "fk_followup_student", "CASCADE"),
    "finance_member_fees": ("fk_fee_member", "fk_fee_student", "CASCADE"),
    "finance_transactions": ("fk_fin_member", "fk_fin_student", "SET NULL"),
    "hr_attendance": ("fk_hr_attendance_member", "fk_hr_attendance_student", "CASCADE"),
    "member_department_periods": ("fk_mdp_member", "fk_mdp_student", "CASCADE"),
    "mezmur_attendance": (
        "fk_mezmur_attendance_member", "fk_mezmur_attendance_student", "CASCADE"),
    "school_registration_receipts": (
        "fk_school_receipt_member", "fk_school_receipt_student", "RESTRICT"),
}

STAFF_REFERRER = "member_staff_positions"


def source():
    return MIGRATION.read_text(encoding="utf-8")


def test_every_learner_referrer_is_repointed():
    s = source()
    missing = []
    for table, (old, new, rule) in LEARNER_REFERRERS.items():
        if f"ALTER TABLE `{table}` ADD CONSTRAINT `{new}`" not in s:
            missing.append(table)
        elif f"ON DELETE {rule}" not in s.split(f"ADD CONSTRAINT `{new}`")[1][:300]:
            missing.append(f"{table} (rule {rule})")
    assert not missing, f"learner referrers not re-pointed: {missing}"


def test_delete_rules_are_preserved_not_normalised():
    """Two referrers do not cascade, and flattening them would be a data bug."""
    s = source()
    assert "ON DELETE SET NULL" in s, "finance_transactions must keep SET NULL"
    assert "ON DELETE RESTRICT" in s, "registration receipts must keep RESTRICT"
    # The two special rules belong to exactly the tables that declared them.
    for table, rule in (("finance_transactions", "SET NULL"),
                        ("school_registration_receipts", "RESTRICT")):
        block = s.split(f"ALTER TABLE `{table}` ADD CONSTRAINT")[1][:300]
        assert f"ON DELETE {rule}" in block, f"{table} lost its {rule} rule"


def test_staff_positions_are_not_dragged_onto_students():
    """M2: positions belong to users. Re-pointing this would recreate the bug."""
    s = source()
    assert not re.search(
        r"ALTER TABLE `member_staff_positions` ADD CONSTRAINT[^;]*REFERENCES `students`",
        s, re.I,
    ), "member_staff_positions must not reference students"
    assert STAFF_REFERRER not in [
        t for t in LEARNER_REFERRERS
    ]


def test_the_migration_is_idempotent_by_construction():
    """Every block checks before it acts, so a re-run cannot error."""
    s = source()
    assert s.count("information_schema.table_constraints") >= 2 * len(LEARNER_REFERRERS)
    assert "PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;" in s
    # A guard that only checks the table, never the constraint, would fail on rerun.
    assert "@has_new" in s and "@has_old" in s


def test_it_does_not_drop_members():
    body = re.sub(r"(?m)^\s*--.*$", "", source())
    assert not re.search(r"DROP\s+TABLE", body, re.I), (
        "106 re-points keys only; dropping members is a later, separate migration"
    )


def test_it_records_what_it_did():
    assert "migration_106_report" in source()

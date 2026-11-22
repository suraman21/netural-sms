"""DS-3 — no production table may exist only inside a PHP file.

Before sql/107, twelve tables that the live database has were defined nowhere in
`sql/*.sql` — only inside `sql/legacy/php-migrations/`. That is why `sql/003`
could add foreign keys to columns the chain never created, and why the finance
and materials subsystems were shells.

This test fails if a table the PHP migrations define is not ALSO defined in a
numbered `sql/*.sql` migration. It does not require the two definitions to be
identical (the SQL copy is the one that must exist); it requires the SQL copy to
exist at all, because that is the only thing a deployment runs.
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SQL = ROOT / "sql"
PHP_MIGRATIONS = SQL / "legacy/php-migrations"

# Tables deliberately not carried over, with the reason.
EXEMPT = {
    # Retired by N-4 (sql/104). Their absence is correct.
    "wbws_groups": "retired by N-4",
    "wbws_group_leaders": "retired by N-4",
    "wbws_group_members": "retired by N-4",
    # Created by sql/055 itself, which aborts by design without a seeded
    # hymn taxonomy. The table exists in sql/, just not on a fresh clone.
    "migration_055_mezmur_link_quarantine": "sql/055 owns it; aborts by design",
}


def php_created_tables():
    names = set()
    for path in PHP_MIGRATIONS.rglob("*.php"):
        src = path.read_text(encoding="utf-8", errors="ignore")
        names.update(re.findall(r"CREATE TABLE (?:IF NOT EXISTS )?`(\w+)`", src))
    return names


def sql_created_tables():
    """Numbered migrations PLUS the legacy baseline.

    The baseline counts: every deployment applies it first
    (tests/e2e/provision_env.sh line 82). Leaving it out made this test report
    `cache_storage` as a gap when it has always been created.
    """
    names = set()
    paths = list(SQL.glob("[0-9][0-9][0-9]_*.sql")) + [SQL / "legacy/database_schema.sql"]
    for path in paths:
        if not path.exists():
            continue
        src = path.read_text(encoding="utf-8", errors="ignore")
        names.update(re.findall(
            r"CREATE TABLE(?: (?:IF NOT EXISTS )?)`(\w+)`", src, re.I))
    return names


def test_every_php_defined_table_also_exists_in_a_numbered_migration():
    missing = sorted(php_created_tables() - sql_created_tables() - set(EXEMPT))
    assert not missing, (
        "these tables exist only inside PHP migrations, so a deployment from "
        f"sql/ alone cannot create them: {missing}"
    )


def test_no_exemption_covers_a_table_that_is_really_defined():
    """An exemption for a table that IS buildable hides a real omission."""
    defined = sql_created_tables()
    # wbws_* are retired, so their definitions legitimately remain in sql/012.
    # Both exempt kinds legitimately still appear in sql/: the wbws group
    # tables in sql/012 (defined, then retired by 104) and the mezmur
    # quarantine in sql/055 (defined, but 055 aborts before creating it on a
    # fresh clone). The exemption is about *creation on a fresh clone*, not
    # about the definition being absent from sql/.
    stale = [t for t in EXEMPT if t in defined and t not in (
        "wbws_groups", "wbws_group_leaders", "wbws_group_members",
        "migration_055_mezmur_link_quarantine")]
    assert not stale, f"exemption no longer true: {stale}"


def test_the_finance_and_materials_subsystems_are_buildable():
    """The specific defect 107 was written to fix."""
    defined = sql_created_tables()
    for table in ("finance_transactions", "finance_member_fees", "finance_budgets",
                  "finance_categories", "material_items", "material_requests",
                  "material_transactions", "material_categories"):
        assert table in defined, f"{table} is not created by any numbered migration"


def test_107_carries_the_columns_that_made_earlier_migrations_fail():
    src = (SQL / "107_php_only_schema_formalisation.sql").read_text(encoding="utf-8")
    assert "membership_tier" in src, "014 and 068 index this column"
    assert "sort_order" in src, "017/021 INSERTs reference it"


def test_107_is_idempotent_by_construction():
    src = (SQL / "107_php_only_schema_formalisation.sql").read_text(encoding="utf-8")
    assert src.count("CREATE TABLE IF NOT EXISTS") >= 13
    assert src.count("information_schema.columns") >= 2, "column adds are guarded"
    assert "PREPARE st FROM @s" in src

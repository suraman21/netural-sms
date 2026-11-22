"""DS-4 — the documented build procedure is real, executable, and stated.

`sql/apply_all.sh` is the answer to "how do I build this database from the
repository alone". Before it existed the answer lived in
`tests/e2e/provision_env.sh` — a test harness — which is how the repository came
to have a test suite that could build a database and a deployment path that
could not.

These pins are cheap and they protect the only documented procedure.
"""
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "sql/apply_all.sh"


def source():
    return SCRIPT.read_text(encoding="utf-8")


def test_the_script_exists_and_is_executable():
    assert SCRIPT.exists()
    mode = SCRIPT.stat().st_mode
    assert mode & 0o111, "sql/apply_all.sh must be executable (chmod 755)"


def executable_lines():
    """Source with comments removed — a comment may legitimately name what it avoids."""
    return "\n".join(
        line for line in source().splitlines()
        if not re.match(r"\s*#", line)
    )


def test_it_builds_from_sql_alone():
    """No PHP scraping: the whole point of 107 was to remove that dependency."""
    code = executable_lines()
    assert "php-migrations" not in code, (
        "the build must not extract DDL from PHP files any more — 107 formalised it"
    )
    assert "CREATE TABLE" not in code, "DDL belongs in migrations, not in this script"


def test_it_applies_the_expectations_this_repository_actually_has():
    src = source()
    for needle in ("legacy/database_schema.sql", "012_runtime_schema_baseline.sql",
                   "013_application_schema_completion.sql"):
        assert needle in src, f"{needle} is part of the real apply order"


def test_it_documents_the_two_files_that_do_not_apply():
    """A silent skip is indistinguishable from success. Say which and why."""
    src = source()
    assert "003" in src and "055" in src
    assert "by design" in src or "expected" in src


def test_it_shellchecks_when_a_shell_is_available():
    r = subprocess.run(["bash", "-n", str(SCRIPT)], capture_output=True, text=True)
    assert r.returncode == 0, r.stderr

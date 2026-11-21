"""N-5: the identity-code mapping report exists and cannot write.

The owner asked for a mapping report before identity codes change. The risk this
guards is the obvious one: a "report" tool that quietly also renumbers, or one
that grows an --execute flag later. Physical ID cards are the reason.
"""
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TOOL = ROOT / "admin/tools/id_code_mapping_report.php"


def code_only(source):
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    return "\n".join(re.sub(r"//.*$", "", line) for line in source.splitlines())


def test_the_report_tool_exists_and_is_cli_only():
    source = TOOL.read_text(encoding="utf-8")
    assert "PHP_SAPI !== 'cli'" in source, "must refuse to run from the web"
    assert "http_response_code(404)" in source


def test_the_report_cannot_modify_anything():
    body = code_only(TOOL.read_text(encoding="utf-8"))
    for verb in ("UPDATE ", "DELETE ", "INSERT ", "ALTER ", "DROP ", "TRUNCATE "):
        assert verb not in body.upper(), f"report tool contains {verb.strip()}"
    assert "--execute" not in body, "a report must never grow an execute mode"
    assert "commit(" not in body.lower()


def test_the_report_explains_the_projection_it_shows():
    """A reader must not mistake the projected code for the current one."""
    source = TOOL.read_text(encoding="utf-8")
    assert "READ ONLY" in source
    assert "projected_code" in source
    assert "plain sequential, no prefix" in source  # ADR-0024 S5


def test_the_tool_lints():
    r = subprocess.run(["php", "-l", str(TOOL)], capture_output=True, text=True)
    if r.returncode != 0 and "No such file" in (r.stdout + r.stderr):
        return  # php not installed in this environment; CI covers it
    assert r.returncode == 0, r.stdout + r.stderr

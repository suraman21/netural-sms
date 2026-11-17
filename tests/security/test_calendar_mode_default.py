"""ADR-0026: the calendar defaults to Ethiopian and only a super admin sets it.

Source and unit level. The one-time write itself is exercised against a real
database by tests/e2e/calendar_initial_setup.php; these tests pin the parts that
a database run cannot: that the default really is the default, that no ordinary
path can change it, and that the page which can change it is super-admin only.
"""
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def text(path):
    return (ROOT / path).read_text(encoding="utf-8")


def php(code):
    r = subprocess.run(["php", "-d", "error_reporting=24575", "-r", code],
                       capture_output=True, text=True)
    assert r.returncode == 0, r.stdout + r.stderr
    return r.stdout.strip()


def test_anything_but_an_explicit_gregorian_row_is_ethiopian():
    """The default is not a policy statement somebody can drift away from."""
    case = (
        "require '%s';"
        "foreach ([null, [], ['mode'=>null], ['mode'=>'ethiopian'],"
        " ['mode'=>'GREGORIAN'], ['setting_value'=>'gregorian'],"
        " ['mode'=>'gregorian']] as $row) {"
        "  echo wbws_calendar_mode_from_row($row), ',';"
        "}"
    ) % (ROOT / "admin/backend/calendar_system.php").as_posix()
    out = php(case)
    assert out.rstrip(",") == "ethiopian,ethiopian,ethiopian,ethiopian,ethiopian,ethiopian,gregorian", out


def test_an_unconfigured_installation_reads_ethiopian_without_a_connection():
    """No connection, no table, no server: the default, never an error."""
    case = (
        "require '%s'; echo wbws_get_calendar_mode();"
    ) % (ROOT / "admin/backend/calendar_system.php").as_posix()
    assert php(case) == "ethiopian"


def test_the_only_caller_that_can_write_the_mode_is_super_admin_only():
    page = text("admin/calendar_setup.php")
    assert "SchoolCalendarInitialSetup::choose" in page
    assert "generateCsrfToken" in page and "validateCsrf" in page
    # Impersonation is refused: choosing a school-wide calendar is the account's
    # own decision, not one made while acting as somebody else.
    assert "original_admin_role" in page
    assert "$role !== 'super_admin'" in page
    # It offers exactly two choices and no third "unset".
    assert 'value="ethiopian"' in page and 'value="gregorian"' in page
    assert page.count('name="mode"') == 2


def test_no_other_endpoint_grows_a_calendar_mode_write():
    """A second writer would make the one-time guarantee meaningless."""
    for path in (ROOT / "admin").rglob("*.php"):
        if path.name == "calendar_setup.php" or "services" in path.parts:
            continue
        source = path.read_text(encoding="utf-8", errors="ignore")
        # A comment may name the service; only an actual call would be a second
        # writer, so judge per line and ignore comment lines.
        for line in source.splitlines():
            code = line.strip()
            if code.startswith(("*", "//", "/*", "#")):
                continue
            assert "SchoolCalendarInitialSetup::choose" not in code, (path, line)
            assert "UPDATE school_calendar_configuration" not in code, (path, line)


def test_the_product_is_arkeo_school_in_its_own_defaults():
    """ADR-0026 P1, and ADR-0022 D8 kept: name yes, frozen identifiers no."""
    branding = text("branding_defaults.php")
    assert "define('SCHOOL_NAME', 'Arkeo School');" in branding
    assert "define('SCHOOL_TAGLINE', 'School Management System');" in branding
    # D8: these stay frozen even though they still read SSMS.
    assert "define('API_NAME', 'SSMS API');" in branding
    assert "define('EXPORT_PREFIX', 'ssms');" in branding
    # The deployed config names this installation, and carries no church identity
    # in its product name any more.
    config = text("school_config.php")
    assert "define('SCHOOL_NAME', 'Arkeo School');" in config
    assert "Sunday School Management System" not in config

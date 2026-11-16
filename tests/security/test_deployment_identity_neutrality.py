"""Deployment identity is configuration, not code (ADR-0022 D7).

The product is a general school system. A deployment that configures nothing
must render nothing church-specific, and a deployment that configures its own
identity must not be contradicted by leftovers from a previous occupant.

Two things are checked here:

1. **Neutral profile proof.** A copy of ``school_config.php`` with the identity
   values replaced by neutral ones, loaded by PHP together with
   ``branding_defaults.php``, resolves to a string set containing no church
   terms. This is a behavioural check of the actual config files, not a grep.

2. **Ratchet.** A scan of first-party code for church identity *string
   literals*. The set of files still holding one is pinned exactly: a new leak
   fails, and shrinking the backlog is a deliberate edit to ``KNOWN_REMAINING``.
   Comments and identifiers are not scanned — ``is_under_sunday_school`` is a
   frozen column name (ADR-0022 D8) and a comment saying "a missed Sunday" is
   domain description, not renderable text.

Not proved here: that every one of those literals is unreachable, or that any
given page renders correctly. This is a source-level constraint, not a UI test.
"""
import re
import shutil
import subprocess
import tempfile
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]

# Identity a general-school deployment must never inherit.
CHURCH_TERMS = (
    "Sunday School",
    "Parish",
    "Spring of Saints",
    "Wulide Birhan",
    "ውሉደ ብርሃን",
    "Ethiopian Orthodox",
)

# The identity source itself: these files are *allowed* to name an identity,
# because they are where each deployment states which identity it has.
DETECTORS = {"admin/leak_detector.php"}

IDENTITY_SOURCES = {
    "school_config.php",
    "branding_defaults.php",
    "Mobile/wbws_flutter_app/lib/utils/config.dart",
    "mobile/wbws_flutter_app/lib/utils/config.dart",
}

SCAN_ROOTS = ("admin", "api", "app", "themes", "backend", "frontend",
              "Mobile/wbws_flutter_app/lib")
SCAN_SUFFIXES = {".php", ".js", ".json", ".css", ".dart"}
SKIP_PARTS = ("vendor", "node_modules", "id_cards/libs", ".min.js", "code_dump.txt")

# Exactly what still renders a church identity from code, as of 2026-11-16.
# Every entry is Phase 3/4/7 work: the A/B/C category model, the ID-card and
# report-card layouts, and the Mezmur surface. This set must only ever shrink.
KNOWN_REMAINING = {
    # Phase 3 (model swap): the A/B/C category model is replaced, not renamed.
    "admin/backend/services/MemberCategory.php",
    # Phase 4 (identity codes / ID card): the printed card keeps its field set.
    "admin/backend/services/IdCardLayout.php",
}

BLOCK_COMMENT_RX = re.compile(r"/\*.*?\*/", re.S)
LINE_COMMENT_RX = re.compile(r"(?m)(^|[^:])//[^\n]*")
MAX_BYTES = 400_000

# Rendered text, not identifiers: "Parish" counts, "statParish" does not.
CHURCH_TERM_RX = {
    term: re.compile(r"(?<![A-Za-z0-9_\-])" + re.escape(term) + r"(?![A-Za-z0-9_\-])")
    for term in CHURCH_TERMS
}


def _strip_comments(source: str) -> str:
    source = BLOCK_COMMENT_RX.sub(" ", source)
    return LINE_COMMENT_RX.sub(r"\1", source)


def _first_church_literal(source: str):
    """First church term outside a comment, with its line for the report.

    HTML text and CSS content count: a heading reading "Sunday School" is as
    visible as a PHP string. Identifiers do not match because the terms are
    case-sensitive and spaced ("Sunday School", not "is_under_sunday_school").
    """
    stripped = _strip_comments(source)
    lines = stripped.splitlines()
    for number, line in enumerate(lines, 1):
        for term in CHURCH_TERMS:
            # word boundaries: an element id like `statParish` or a query key
            # like `data-parish` is an identifier, not a rendered word.
            if CHURCH_TERM_RX[term].search(line):
                return term, line.strip()[:110]
    return None


def _scan():
    hits = {}
    for root in SCAN_ROOTS:
        base = ROOT / root
        if not base.is_dir():
            continue
        for path in base.rglob("*"):
            if not path.is_file() or path.suffix not in SCAN_SUFFIXES:
                continue
            rel = path.relative_to(ROOT).as_posix()
            if any(skip in rel for skip in SKIP_PARTS):
                continue
            if rel in IDENTITY_SOURCES or rel in DETECTORS:
                continue
            try:
                if path.stat().st_size > MAX_BYTES:
                    continue
                source = path.read_text(encoding="utf-8")
            except (UnicodeDecodeError, OSError):
                continue
            found = _first_church_literal(source)
            if found:
                hits[rel] = found
    return hits


def test_no_new_hardcoded_church_identity_in_code():
    hits = _scan()
    unexpected = {p: v for p, v in hits.items() if p not in KNOWN_REMAINING}
    assert not unexpected, (
        "New hardcoded church identity. Route it through school_config.php "
        "(SCHOOL_TYPE, GROUP_CAT_*, LANDING_CONTACT_*): " + repr(unexpected)
    )


def test_identity_backlog_only_shrinks():
    hits = _scan()
    stale = [p for p in KNOWN_REMAINING if p not in hits]
    assert not stale, (
        "These files no longer contain a church identity literal - remove them "
        "from KNOWN_REMAINING so the ratchet tightens: " + repr(sorted(stale))
    )


def test_landing_page_carries_no_previous_occupants_contact_details():
    landing = (ROOT / "index.php").read_text(encoding="utf-8")
    # Another school's address shipped on the public homepage until 2026-11-16.
    assert "info@wulidebirhan.org" not in landing
    # The contact panel no longer hardcodes an address or a phone number; each
    # row is rendered from configuration and hidden when that value is empty.
    # (The "+251 XXX XXX XXX" *form placeholder* further down the page is a
    # phone-format hint for the registration input, not contact identity.)
    for constant in ("LANDING_CONTACT_EMAIL", "LANDING_CONTACT_PHONE",
                     "LANDING_CONTACT_ADDRESS", "LANDING_OFFICE_HOURS_1"):
        assert f"defined('{constant}')" in landing, constant
    contact_panel = landing.split("Get in Touch", 1)[1].split("Register Your Child", 1)[0]
    assert "wulidebirhan" not in contact_panel
    assert "+251" not in contact_panel


def test_stale_manifest_naming_another_school_is_gone():
    # app/manifest.json was unreferenced dead weight naming WBWS / ውሉደ ብርሃን,
    # while app/manifest.php is the manifest that is actually served.
    assert not (ROOT / "app/manifest.json").exists()
    assert (ROOT / "app/manifest.php").exists()


def test_exports_use_the_configured_prefix():
    export = (ROOT / "admin/api_export_members.php").read_text(encoding="utf-8")
    assert "$baseFilename = EXPORT_PREFIX . " in export
    assert "$baseFilename = 'sundayschool_" not in export


def test_neutral_profile_resolves_without_church_terms():
    php = shutil.which("php")
    if php is None:
        pytest.skip("PHP CLI is not installed")

    source = (ROOT / "school_config.php").read_text(encoding="utf-8")
    neutral = source
    for old, new in (
        ("define('SCHOOL_NAME', 'FKSS School Management System');",
         "define('SCHOOL_NAME', 'Green Valley Academy');"),
        ("define('SCHOOL_NAME_SHORT', 'FKSS');", "define('SCHOOL_NAME_SHORT', 'GVA');"),
        ("define('SCHOOL_TRANSLATION_EN', 'Spring of Saints');",
         "define('SCHOOL_TRANSLATION_EN', 'Green Valley');"),
        ("define('SCHOOL_TAGLINE', 'Sunday School Management System');",
         "define('SCHOOL_TAGLINE', 'School Management System');"),
        ("define('SCHOOL_TYPE', 'Sunday School');", "define('SCHOOL_TYPE', 'School');"),
    ):
        assert neutral.count(old) == 1, old
        neutral = neutral.replace(old, new)
    # The parish/denomination/invocation block becomes empty, as a secular
    # deployment would leave it.
    neutral = re.sub(r"define\('PARISH_NAME_AM', '[^']*'\);", "define('PARISH_NAME_AM', '');", neutral)
    neutral = re.sub(r"define\('PARISH_NAME_EN', '[^']*'\);", "define('PARISH_NAME_EN', '');", neutral)
    neutral = re.sub(r"define\('DENOMINATION_AM', '[^']*'\);", "define('DENOMINATION_AM', '');", neutral)
    neutral = re.sub(r"define\('DENOMINATION_EN', '[^']*'\);", "define('DENOMINATION_EN', '');", neutral)
    neutral = re.sub(r"define\('RELIGIOUS_INVOCATION', '[^']*'\);", "define('RELIGIOUS_INVOCATION', '');", neutral)
    # A general school names its second group category itself; "Parish" is the
    # church deployment's word for it.
    neutral = re.sub(r"define\('GROUP_CAT_PARISH', '[^']*'\);", "define('GROUP_CAT_PARISH', 'Community');", neutral)
    neutral = re.sub(r"define\('GROUP_CAT_PARISH_AM', '[^']*'\);", "define('GROUP_CAT_PARISH_AM', 'ማህበረሰብ');", neutral)
    # and it renders no church timetable when its schedule table is empty
    neutral = re.sub(r"define\('LANDING_FALLBACK_SCHEDULE_ENABLED', [a-z]+\);",
                     "define('LANDING_FALLBACK_SCHEDULE_ENABLED', false);", neutral)

    tmp = Path(tempfile.mkdtemp(prefix="neutral-profile-"))
    try:
        (tmp / "school_config.php").write_text(neutral, encoding="utf-8")
        probe = tmp / "probe.php"
        probe.write_text(
            "<?php\n"
            "require '" + str(tmp / "school_config.php") + "';\n"
            "require '" + str(ROOT / "branding_defaults.php") + "';\n"
            "$names = ['SCHOOL_NAME','SCHOOL_NAME_SHORT','SCHOOL_TYPE','SCHOOL_TYPE_AM',\n"
            "  'SCHOOL_TAGLINE','SCHOOL_TAGLINE_AM','SCHOOL_TRANSLATION_EN','PARISH_NAME_EN',\n"
            "  'PARISH_NAME_AM','DENOMINATION_EN','DENOMINATION_AM','RELIGIOUS_INVOCATION',\n"
            "  'ID_CARD_TITLE_EN','ID_CARD_TITLE_AM','ID_CARD_FOOTER_AM','GROUP_CAT_SS',\n"
            "  'GROUP_CAT_PARISH','GROUP_UNDER_SS_LABEL','GROUP_UNDER_SS_LABEL_AM',\n"
            "  'ADMIN_PANEL_TITLE','MONITOR_PROJECT_NAME','COPYRIGHT_TEXT','LANDING_MISSION_EN',\n"
            "  'LANDING_MISSION_AM','LANDING_CONTACT_EMAIL','API_NAME'];\n"
            "foreach ($names as $n) echo $n, '=', defined($n) ? constant($n) : '(undefined)', \"\\n\";\n",
            encoding="utf-8",
        )
        result = subprocess.run([php, "-d", "error_reporting=E_ALL", str(probe)],
                                capture_output=True, text=True, timeout=120)
        assert result.returncode == 0, result.stderr[-2000:]
        resolved = dict(line.split("=", 1) for line in result.stdout.splitlines() if "=" in line)
    finally:
        shutil.rmtree(tmp, ignore_errors=True)

    leaked = {k: v for k, v in resolved.items()
              if any(term in v for term in CHURCH_TERMS)}
    assert not leaked, f"neutral profile still resolves church identity: {leaked}"
    # and it really did resolve to the neutral names, not to blanks
    assert resolved["SCHOOL_TYPE"] == "School"
    assert resolved["SCHOOL_NAME"] == "Green Valley Academy"
    assert resolved["RELIGIOUS_INVOCATION"] == ""

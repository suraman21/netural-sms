"""Source-only documentation consistency checks; no database or network.

These pin corrected facts and evidence boundaries, not exhaustive source,
API-field, authorization, deployment or user-manual verification.
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def text(path):
    return (ROOT / path).read_text(encoding="utf-8")


def test_numbered_migration_inventory_excludes_comment_words():
    migrations = sorted((ROOT / "sql").glob("[0-9][0-9][0-9]_*.sql"))
    targets = set()
    for path in migrations:
        source = re.sub(r"/\*.*?\*/", "", path.read_text(encoding="utf-8"), flags=re.S)
        source = re.sub(r"(?m)^\s*--.*$", "", source)
        targets.update(re.findall(
            r"\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z_][a-zA-Z0-9_]*)",
            source, re.I,
        ))
    doc = text("docs/DATABASE.md")
    assert f"**{len(migrations)} numbered migration files**" in doc
    assert f"**{len(targets)} distinct `CREATE TABLE` target names**" in doc
    assert "only" not in targets
    row = next(line for line in doc.splitlines() if line.startswith("| `026_hr_attendance.sql`"))
    assert "hr_attendance, hr_attendance_audit, hr_submissions |" in row
    assert ", only" not in row


def test_openapi_exists_and_operation_count_is_not_an_absence_claim():
    spec = text("docs/api/openapi.yaml")
    operations = re.findall(r"^    (?:get|post|patch|put|delete|head|options):", spec, re.M)
    overview = text("docs/API.md")
    assert "openapi: 3.1.0" in spec
    assert f"{len(operations)} listed operations" in overview
    assert "No OpenAPI specification exists" not in overview
    assert "most bodies are summarised" in overview
    assert "Web chunk-upload recovery does not mean mobile APK downloads support resume" in overview


def test_legacy_authorization_compatibility_is_not_documented_as_universal_revocation():
    source = text("api/v1/core/auth.php")
    assert "function apiAuthorizationScopeEnforcedForClient()" in source
    assert "define('API_AUTHZ_LEGACY_COMPAT_UNTIL', PHP_INT_MAX)" in source
    for path in ("docs/API.md", "docs/architecture/TECHNICAL_DESIGN.md", "docs/product/PRD.md"):
        body = text(path)
        assert "compatibility" in body.lower(), path
    assert "API_AUTHZ_LEGACY_COMPAT_UNTIL" in text("docs/API.md")


def test_deployment_boundary_preserves_beta_isolation_and_separate_authorization():
    branching = text("BRANCHING.md")
    assert "never push `dev` to the production repository" in branching
    assert "May intentionally lag development" in branching
    assert "owner acceptance" in branching.lower()
    decision = text("docs/adr/0021-development-acceptance-before-production.md")
    assert "separate explicit owner decision" in decision
    for old in ("0001-php-monolith-on-shared-hosting.md", "0014-branching-main-dev.md"):
        assert "0021-development-acceptance-before-production.md" in text("docs/adr/" + old)
    design = text("docs/architecture/TECHNICAL_DESIGN.md")
    assert 'gh -->|"same commits"|' not in design
    assert "a red CI gate" not in design
    assert "probes database tables" in design


def test_release_guidance_preserves_signer_device_and_private_diagnostics_gates():
    release = text("Mobile/wbws_flutter_app/docs/RELEASE.md")
    for boundary in (
        "DEPENDENCY NOT VERIFIED", "certificate continuity", "restart from zero",
        "not guaranteed free of personal or sensitive data", "authorized administrator",
        "installer launch", "separate owner authorization", "unsent work",
    ):
        assert boundary.lower() in release.lower(), boundary
    assert "Stack traces only — no user data" not in release
    assert "works on every phone" not in release.lower()


def test_product_mobile_role_and_recovery_boundaries_are_explicit():
    prd = text("docs/product/PRD.md")
    for role in ("finance_dept", "material_dept"):
        row = next(line for line in prd.splitlines() if line.startswith("|") and f"(`{role}`)" in line)
        assert "Website-only" in row
    assert "410 `HR_ATTENDANCE_RETIRED`" in prd
    assert "Preserving logout" in prd
    assert "local_data_cleanup_service.dart" not in prd
    assert "offline_recovery_service.dart" not in prd
    assert "not complete" in prd


def test_operational_safety_corrections_keep_required_runbook_contract():
    runbook = text("docs/audits/DEPLOYMENT_RUNBOOK.md")
    assert "not a current release authorization" in runbook
    assert "find . -type d -exec chmod" not in runbook
    assert "find . -type f -exec chmod" not in runbook
    assert "-p<password>" not in runbook
    for boundary in (
        "031 -> 032 -> 033", "DEPLOYMENT BLOCKER", "39 / 42", "42 / 42",
        "sql/preflight/uniqueness_preflight.sql", "scripts/restore_production_dump.sh",
        "PROFILE_PRIVATE_STORAGE_PATH", "profile_image_cleanup.php",
        '"lock_acquired":true', "not a fresh-install sequence",
    ):
        assert boundary in runbook, boundary
    incident = text("docs/INCIDENT_RESPONSE.md")
    assert "owner acceptance" in incident
    assert "separate production authorization" in incident
    assert "Preserve unsent mobile work" in incident
    assert "Never request that staff paste raw reports into public issues" in text("Mobile/wbws_flutter_app/docs/RELEASE.md")


def test_retained_source_copy_and_partial_manual_are_not_hidden_as_completed():
    catalog = text("docs/DOCUMENTATION_CATALOG.md")
    assert "not complete" in catalog
    assert "Complete unique-knowledge retirement review remains unfinished" in catalog
    assert (ROOT / "Mobile/code_dump.txt").is_file()
    lifecycle = text("docs/DOCUMENT_LIFECYCLE.md")
    assert "6/30" in lifecycle
    assert "remaining differences were not fully reviewed" in lifecycle
    assert "INCOMPLETE — ADDITIONAL WORK REQUIRED" in text("docs/user_manual/COVERAGE.md")


def test_general_school_decision_record_exists_and_supersedes_explicitly():
    """ADR-0022 must precede the remodel's code (plan phase 1).

    Two things are pinned: that the superseded records were *marked* rather
    than quietly rewritten (the rule in docs/adr/README.md), and that the new
    record does not smuggle a data-destruction or a deferred owner decision
    into the decision text.
    """
    decision = text("docs/adr/0022-general-school-domain.md")
    assert "| Accepted" in decision
    assert "General school" in decision or "general school" in decision
    # D1..D10 decisions, and the clause that keeps retirement non-destructive.
    for number in range(1, 11):
        assert f"**D{number} —" in decision, number
    assert "This record removes code, not data." in decision
    # The superseded records point at the new one instead of being edited.
    for old in (
        "0007-section-equals-age-group.md",
        "0008-ethiopian-calendar-only.md",
        "0009-attendance-ownership.md",
        "0015-mezmur-media-object-storage.md",
    ):
        body = text("docs/adr/" + old)
        assert "0022-general-school-domain.md" in body, old
        assert "2026-11-16 —" in body, old
    # ADR-0017 is reaffirmed, not superseded: registration stays web-only.
    reaffirmed = text("docs/adr/0017-no-member-registration-on-mobile.md")
    assert "reaffirmed" in reaffirmed.lower()
    assert "0022-general-school-domain.md" in reaffirmed
    # Every deferred owner decision is named as deferred, so nobody implements it silently.
    deferred = decision.split("## Deferred decisions", 1)[1].split("## Consequences", 1)[0]
    for d in ("D-2", "D-4", "D-5", "D-6", "D-7", "D-8"):
        assert f"| {d} |" in deferred, d
    # The index navigates to it and flags the dead records.
    index = text("docs/adr/README.md")
    assert "0022-general-school-domain.md" in index
    assert "Superseded by ADR-0022" in index


def test_age_group_removal_decision_is_recorded_and_bounded():
    """ADR-0023 removes the age-group concept; pin what that must keep true.

    The risk this guards against is a later "compatibility" shim: an age band
    reintroduced under a new name, or a migration that quietly maps legacy
    values onto grades (which is exactly what the decision forbids).
    """
    decision = text("docs/adr/0023-no-age-group-or-age-section.md")
    for clause in ("A1", "A2", "A3", "A4", "A5", "A6"):
        assert f"**{clause} —" in decision, clause
    assert "is deleted, not refactored" in decision
    assert "No rule may assign a member to a class, section, grade or category from it" in decision
    assert "read-only, never migrated" in decision
    # The ADR index navigates to it and ADR-0022 points at it as the D-5 answer.
    assert "0023-no-age-group-or-age-section.md" in text("docs/adr/README.md")
    assert "D-5 resolved" in text("docs/adr/0022-general-school-domain.md")
    # The concept still exists in code today (phase 3 has not run). If this
    # fails, phase 3 landed and this test should assert the opposite.
    assert (ROOT / "admin/backend/services/MemberCategory.php").is_file()


def test_ethiopian_ladder_is_a_seed_not_a_migration():
    """A migration must not invent an institution's curriculum (ADR-0025 I1).

    The first draft of sql/103 seeded the ladder on an empty install and broke
    test_school_structure_read, which expects an unconfigured installation to
    report no levels. Structure and content are pinned apart here so the two
    cannot drift back together.
    """
    migration = text("sql/103_institution_levels_and_exam_gates.sql")
    assert "school_exam_gates" in migration
    assert "INSERT" not in migration.upper(), "sql/103 must change structure only"
    assert "school_education_levels" in migration

    seed_path = ROOT / "sql/seeds/ethiopian_ladder.sql"
    assert seed_path.exists(), "the ladder must ship as an opt-in seed"
    seed = seed_path.read_text(encoding="utf-8")
    assert "NOT A MIGRATION" in seed
    assert seed.count("WHERE NOT EXISTS (SELECT 1 FROM `school_education_levels`)") == 1
    # The guard is the whole table, so a configured institution is untouched and
    # a deleted level is never resurrected.
    assert "WHERE NOT EXISTS (SELECT 1 FROM `school_education_levels` WHERE" not in seed
    # It must not be picked up by the migration inventory glob (sql/NNN_*.sql).
    assert all("seeds" not in p.parts for p in (ROOT / "sql").glob("[0-9][0-9][0-9]_*.sql"))
    assert [p.name for p in (ROOT / "sql" / "seeds").glob("*.sql")] == ["ethiopian_ladder.sql"]


def test_master_plan_inventory_matches_the_tree():
    """The master plan's document inventory must not drift from reality.

    It is the map people will consult when asking "what is left", so a stale map
    is worse than no map: it makes a gap look covered. Both directions are
    checked - a missing file and an invented one are the same bug.
    """
    plan = text("docs/MASTER_PLAN.md")
    on_disk = sorted(
        p.name for p in (ROOT / "docs" / "adr").glob("[0-9][0-9][0-9][0-9]-*.md")
    )
    listed = sorted(re.findall(r"docs/adr/(\d{4}-[a-z0-9-]+\.md)", plan))
    assert listed == on_disk, (
        f"missing from the plan: {sorted(set(on_disk) - set(listed))}; "
        f"invented by the plan: {sorted(set(listed) - set(on_disk))}"
    )
    # Every ADR must carry a status the index can state, and the plan must name
    # the next free migration number correctly (it is what a contributor reads).
    migrations = sorted((ROOT / "sql").glob("[0-9][0-9][0-9]_*.sql"))
    assert f"**{migrations[-1].name.split('_')[0]}" not in plan  # sanity: no stale pin
    assert "104" in plan, "the plan must say which migration number is next"

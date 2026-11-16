# ADR-0015: Mezmur audio and art in object storage, with presigned URLs

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-09-03 | Maintainers |

> Recorded on 2026-10-22; the decision date is shown above. Evidence is cited so the decision can be checked; where the rationale is not documented, this is stated.

> **2026-11-16 — superseded:** the Mezmur ministry is retired by [ADR-0022](0022-general-school-domain.md); there is no audio, art, presigned upload or signed-URL playback contract in the target product. Read this record as history: it describes what existed until phase 7 of the remodel, not what the product will require.

## Context

Hymn audio files are too large for shared-hosting storage and PHP upload limits.

## Decision

Store media in S3-compatible object storage (Cloudflare R2). Clients upload directly with presigned URLs (presign, then confirm), and playback uses short-lived signed GET URLs. The app can download audio for offline use.

## Consequences

- **Positive:** offloads bandwidth and storage; no large uploads through PHP.
- **Negative:** an external dependency and credentials to manage; signed-URL expiry must be handled by the players.

## Evidence

`MezmurMediaService.php`; `api/v1/routes/mezmur.php` (`audio-presign`, `audio-confirm`); commits `b3f0e51`, `09ddbba`, `2a21a00`.

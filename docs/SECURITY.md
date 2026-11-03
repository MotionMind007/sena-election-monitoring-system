# Security & Integrity Requirements

## Threat model
Threats include unauthorized region access, compromised witness accounts, forged/duplicate reports, malicious uploads, self-approval, race conditions in approvals, tampering with evidence, stale aggregation, leaked internal results, and lost backups.

## Controls
- HTTPS; secure HttpOnly SameSite cookies for web, CSRF protection, session rotation and timeout.
- Argon2id passwords and rate limits. MFA is intentionally deferred by owner decision; require a separately approved MFA rollout before public or higher-risk deployment.
- Enforce policies on every endpoint, query, export, search, and object-storage link.
- No authorization based solely on hidden UI elements or user-supplied role/region fields.
- Private object storage, signed short-lived access URLs, MIME/content validation, file size limits, ClamAV malware scanning before evidence storage, and SHA-256 checksum. A running scanner is required in production; scanner failures reject uploads.
- Production evidence objects use a private S3-compatible disk with server-side encryption. Authorized application endpoints stream downloads with `private, no-store`; OCR uses short-lived OS temporary files and removes them on success or failure.
- Super Admin proxy entry records the original source and receipt method; the admin remains the technical submitter and may not approve that same report.
- Avoid storing unnecessary witness personal data; restrict PII and set retention policy.
- Approval transaction checks role, non-self-approval, report status, evidence, and expected version.
- Partial unique index for one active verified result; immutable version/evidence history.
- Official recap comparison writes are Super Admin-only, idempotent, append-only, and region/event scoped. Private evidence is MIME/size validated and checksummed; Coordinator access is read-only and scoped, and Witness access is denied.
- Final approvals and recap snapshots serialize on the contest row to avoid mixed pre/post-approval aggregates.
- Append-only audit logs; forward to separate protected log destination for high assurance.
- Encrypt backups and credentials; secrets in environment/secret store, never repo.
- Database least privilege; Redis/object storage not publicly accessible.
- Monitor failed logins, mass export, suspicious approvals, unusual repeated submissions.

## Data quality safeguards
- Validate event+contest+TPS membership and allowed ballot schema.
- Numeric consistency checks; OCR results are untrusted suggestions.
- Preserve source identity for coordinator proxy submissions.
- Flag duplicate/conflicting reports, require documented resolution.
- Dashboard shows internal/provisional label and last update time.

## Abuse and failure tests
- IDOR across region and TPS.
- CSRF, XSS in candidate/region labels, SQL injection in filters.
- Concurrent approvals, stale versions, replayed requests.
- Evidence replacement attempts, oversize/malformed uploads.
- Cross-event contamination, wrong contest aggregation.
- Restore from backup and verify audit integrity.

## Privacy
No voter-level personal data, voter targeting, or campaign profiling in V1. Confirm applicable Indonesian data protection and election-specific obligations with qualified local counsel before production.

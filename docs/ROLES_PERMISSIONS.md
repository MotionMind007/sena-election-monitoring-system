# Role & Scope Specification

## Exactly three roles
`super_admin`, `coordinator`, `witness`. Geography is a **scope**, not another role. A user may be assigned multiple events/scopes.

| Operation | Super Admin | Coordinator | Witness |
|---|---|---|---|
| Create/edit event/contest | Yes | No | No |
| Import/manage regions/TPS | Yes | Read assigned | Read assigned TPS |
| Manage users/assignments | Yes | Read assigned | Read self |
| Submit TPS report | Optional; no self-approval | Assigned region | Assigned TPS |
| Read report/evidence | All | Assigned region | Own assigned TPS |
| Review/recommend | Yes | Assigned region, not own submission | No |
| Final approve/reject | **Yes only**; not own submission | No | No |
| View verified dashboard | All | Assigned region | Assigned TPS |
| Export | Yes | No in V1 | No |
| Audit | All | Relevant region | Own actions |
| System settings | Yes | No | No |

## Authorization algorithm
1. Authenticate session/token.
2. Resolve event membership.
3. Check role permission for operation.
4. Resolve region/TPS assignment and verify requested object belongs to event and descendant scope.
5. For review/approval, check actor is not submitter and other separation-of-duty constraints.
6. Return 403/404 without leaking unauthorized data.

## Explicit tests
- Witness cannot read another TPS by changing URL.
- Coordinator cannot see sibling region or approve.
- Super Admin cannot approve own submission.
- A user cannot access another event without assignment unless Super Admin.
- Server ignores client-provided role or scope values.
- Export and search endpoints enforce same scopes as dashboard.

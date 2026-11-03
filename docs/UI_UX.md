# UI/UX Design System — Dark Glassmorphism

## Approved direction
Use `ui/references/electcount-desktop-mockup.png` as **visual inspiration**; do not copy fabricated candidate photos, dates, regional map shapes, or numbers as factual content. `ui/references/user-glass-style-reference.jpg` is the original visual inspiration.

## Tokens (starting values, refine via screenshot review)
```css
:root {
  --bg: #071426;
  --surface-glass: rgba(19, 38, 64, 0.78);
  --border-glass: rgba(180, 205, 255, 0.14);
  --primary: #318cfa;
  --verified: #16d9ad;
  --pending: #f59e42;
  --danger: #f35b77;
  --text: #f3f7ff;
  --muted: #aabbd1;
  --radius-card: 16px;
  --glass-blur: 18px;
}
```

## Layout
- Desktop ≥1280px: collapsible left sidebar (~232px), topbar, KPI row, main analytics grid, lower monitoring table.
- 1024–1279px: condensed sidebar, 3 KPI columns, responsive chart rows.
- 768–1023px: drawer navigation, 2–3 KPI columns.
- Mobile <768px: bottom nav for witness, drawer for others, 2 KPI columns, cards instead of wide tables, full-width forms.
- Never force horizontal page scroll. Wide admin tables may scroll within own container.

## Dashboard behavior
- **Single dashboard** for province → kab/kota → kecamatan/distrik → desa/kelurahan → TPS.
- Breadcrumb clickable; selected event/contest persists during region drilldown.
- KPI, results, map, table, alerts, and TPS list refresh with selected scope.
- Clicking a child region drills down without a full reload; browser Back restores context.
- TPS level shows report detail, evidence and approval history in context panel/drawer.
- Display separate submitted vs verified coverage; never mix unapproved vote figures into verified totals.

## Visual semantics
- Blue = navigation/primary action; teal = verified; amber = pending/review; rose = revision/conflict; gray = no report.
- Do not rely on color alone; always use text/icons.
- Maintain accessible contrast on glass; use solid-ish surfaces behind dense tables and form numbers.
- Focus visible, keyboard usable, motion reduced when requested.
- Loading skeletons and empty/error states required.

## Role variations
- Super Admin: full command center + Approval Center, user/event admin.
- Coordinator: same visual language, regional monitoring, review/recommend, alternate TPS input; no final approval button.
- Witness: mobile-first assigned TPS list, form entry, evidence upload, submission status, limited verified results.

## Image guidance
Avoid real political figure imagery and fake official symbols in fixtures. Use neutral initials/avatars in development. Map needs approved geographic GeoJSON; do not treat stylized mockup map as accurate.

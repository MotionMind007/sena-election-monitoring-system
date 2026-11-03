# Repository Structure (target; grow incrementally)

```text
electcount/
├── AGENTS.md
├── README.md
├── docs/
├── app/
│   ├── Http/Controllers/Web/
│   ├── Http/Controllers/Api/V1/
│   ├── Http/Requests/
│   ├── Policies/
│   ├── Models/
│   ├── Actions/              # business use cases
│   │   ├── Reporting/
│   │   ├── Verification/
│   │   └── Results/
│   ├── Services/             # shared domain services, only when needed
│   └── Jobs/
├── database/
│   ├── migrations/
│   ├── factories/
│   └── seeders/
├── resources/js/
│   ├── Pages/
│   │   ├── Dashboard/
│   │   ├── Events/
│   │   ├── TPS/
│   │   ├── Approval/
│   │   └── Admin/
│   ├── Components/
│   │   ├── UI/
│   │   ├── Dashboard/
│   │   └── Reporting/
│   ├── Layouts/
│   └── Composables/
├── routes/
│   ├── web.php
│   └── api.php
├── tests/
│   ├── Feature/
│   └── Unit/
└── public/
```

## Conventions
- Do not create empty folders, unused base classes, or abstract interfaces in anticipation of future work.
- HTTP controllers validate/delegate; policy enforces access; action executes transactional use case.
- DB constraints backstop application validation.
- API responses use predictable error envelopes and versioned URLs.
- Components share tokens and behavior, not copied markup.
- Naming: singular model, plural table, clear foreign keys, event/contest scope explicit.
- Do not build separate SPA and Laravel servers.

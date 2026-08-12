# BookMyCharters — Technical Assignment

Senior Backend Engineer shortlist round. Two assignments covering cache
invalidation in a Yii2 service and production incident response for an
Nginx/Yii2 stack.

**Stack:** PHP 8+ · Yii2 · MySQL · AWS · Docker

---

## Repository structure

```
.
├── assignment-1-cache-service/    Cache bug fix + reusable abstraction
│   ├── components/                CacheManager component
│   ├── config/                    Yii2 configuration (web, console, db)
│   ├── controllers/               Product & Category API controllers
│   ├── migrations/                Database schema + seed data
│   ├── models/                    ActiveRecord models
│   ├── tests/                     Integration & unit tests
│   ├── DECISIONS.md               Design rationale & trade-offs
│   ├── Dockerfile                 PHP 8.2 CLI image with all extensions
│   ├── docker-compose.yml         MySQL 8 + PHP dev server
│   └── README.md                  Setup, API docs, architecture
│
├── assignment-2-incident/         Production incident proposal
│   ├── proposal.md                2–3 page technical proposal
│   ├── access-log-sample.log      Redacted Nginx access log
│   └── README.md                  Summary of the proposal
│
└── README.md                      This file
```

## Quick start

### Assignment 1 — Cache service

```bash
cd assignment-1-cache-service
docker compose up -d                          # Start MySQL + PHP
docker compose exec app php yii migrate --interactive=0
docker compose exec app php tests/run.php     # Run tests
curl http://localhost:8080/products/1          # Test API
```

### Assignment 2 — Incident proposal

Read `assignment-2-incident/proposal.md`. No runtime required.

## Tech stack

| Layer | Technology |
|---|---|
| Language | PHP 8.2 (CLI) |
| Framework | Yii2 2.0.55 |
| Database | MySQL 8.0 (Docker) |
| Cache | Yii FileCache (runtime/cache/) |
| Testing | Custom test runner (tests/run.php) |
| Containerisation | Docker + Docker Compose |

## Key files

| File | Purpose |
|---|---|
| `assignment-1-cache-service/components/CacheManager.php` | Reusable cache abstraction with `invalidateEntity()` |
| `assignment-1-cache-service/DECISIONS.md` | Bug reproduction, design choices, PM response |
| `assignment-1-cache-service/tests/run.php` | 9 tests covering the bug fix and CacheManager |
| `assignment-2-incident/proposal.md` | Log analysis, immediate + durable fixes, risk assessment |

## Submission

Both assignments are in a single repository with one folder per assignment.
Each folder contains its own README for independent review.

# Assignment 2 — Production Incident Response

Technical proposal addressing intermittent CPU and memory spikes on a
production Yii2 application behind Nginx on AWS EC2.

**Stack:** PHP 8+ · Yii2 · MySQL · Nginx · AWS EC2

---

## Table of contents

- [Problem statement](#problem-statement)
- [Executive summary](#executive-summary)
- [Contents](#contents)
- [Log analysis summary](#log-analysis-summary)
- [Recommended approach](#recommended-approach)
- [Key constraints](#key-constraints)

---

## Problem statement

The production application has been suffering intermittent CPU and memory
spikes for two weeks. Every request — including requests for non-existent
URLs — reaches the Yii2 application, triggering routing, framework bootstrap,
logging, and processing before returning a 404. At ~100 invalid requests per
minute, this is beginning to affect real customers.

---

## Executive summary

The access log reveals three categories of traffic:

| Category | Sources | Action |
|---|---|---|
| **Hostile** | zgrab scanner (203.0.113.44), python-requests scanner (198.51.100.7), unknown-UA scanner (45.146.164.2) | Block at Nginx edge |
| **Legitimate** | UptimeRobot (216.144.250.9), Razorpay webhooks (104.18.22.33), Googlebot (66.249.66.1) | Must remain reachable |
| **Mixed** | iPhone user (110.226.180.5) — legitimate browsing + suspicious `.env` probe | Path-based blocking only; do not block IP |

**Recommended approach:**

1. **Immediate (2 hours):** Nginx path-based 403s + UA-based blocking +
   rate limiting — zero cost, ships today.
2. **Durable (this week):** Layered defence with Nginx edge filtering,
   Yii2 middleware, and optional IP reputation — still zero cost.

Full details are in [`proposal.md`](proposal.md).

---

## Contents

| File | Description |
|---|---|
| [`proposal.md`](proposal.md) | Full 2–3 page technical proposal |
| [`access-log-sample.log`](access-log-sample.log) | Redacted Nginx access log from the assignment brief |

---

## Log analysis summary

### Hostile sources

| IP | User-Agent | Paths probed | Verdict |
|---|---|---|---|
| 203.0.113.44 | `zgrab/0.x` | `/wp-admin/`, `/xmlrpc.php`, `/test.php` | Internet-wide vulnerability scanner |
| 198.51.100.7 | `python-requests/2.31` | `/.env`, `/phpmyadmin/`, `/api/debug` | Automated exploit scanner |
| 45.146.164.2 | `-` (empty) | `/admin/login.php`, `/random-string` | Admin-panel scanner + URL fuzzer |

### Legitimate sources

| IP | User-Agent | Path | Verdict |
|---|---|---|---|
| 216.144.250.9 | `UptimeRobot/2.0` | `/health` (200) | Uptime monitoring — must not break |
| 104.18.22.33 | `Razorpay-Webhook/1.0` | `POST /webhooks/razorpay` (200) | Payment webhook — must not break |
| 66.249.66.1 | `Googlebot/2.1` | `/sitemap.xml` (200) | Search engine crawler |

### Mixed source

| IP | User-Agent | Paths | Verdict |
|---|---|---|---|
| 110.226.180.5 | `Mozilla/5.0 (iPhone)` | `/flights/mumbai-delhi` (200), `/.env` (404) | Real user browsing + suspicious probe from same IP |

**Danger:** Bluntly blocking this IP would take down a real customer.

---

## Recommended approach

### Phase 1 — Immediate (2 hours, zero cost)

```nginx
# Block known hostile paths at the edge
location ~* ^/(wp-admin|xmlrpc\.php|\.env|phpmyadmin|admin/login\.php|test\.php|api/debug) {
    return 403;
}

# Block known hostile user-agents
map $http_user_agent $bad_agent {
    default          0;
    ~*zgrab           1;
    ~*python-requests 1;
}

# Rate limit all traffic
limit_req_zone $binary_remote_addr zone=general:10m rate=30r/s;
```

### Phase 2 — Durable (this week, zero cost)

1. **Nginx edge filtering** (already shipped above)
2. **Yii2 URL filter middleware** — denylist of known-bad paths at the
   application layer as a second line of defence
3. **Log monitoring** — daily cron job counting 403s and new 404 paths to
   detect new scanning patterns early

---

## Key constraints

- Two engineers, one working week
- Website must stay online for real customers throughout
- Minimise additional AWS spend (no paid services without justification)
- Do not break Razorpay webhook delivery or UptimeRobot monitoring
- Some noisy traffic originates from legitimate sources — no blunt IP or UA blocking

---

## Weakest point

The Nginx path denylist is static. It blocks the paths identified in this log
extract, but novel scanning paths (e.g., `/cgi-bin/`, `/.git/config`) would
still reach the application. The rate limiter is the safety net that catches
novel paths at volume. Detection relies on log monitoring to identify new
scanning patterns early.

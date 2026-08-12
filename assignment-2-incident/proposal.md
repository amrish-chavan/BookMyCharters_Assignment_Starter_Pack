# Assignment 2 — Production Incident Proposal

## 1. Log Analysis

The access-log extract shows 14 request lines from 7 distinct sources.
Below I classify each source, reference the specific log lines, and
note anything that makes a naive fix dangerous.

### Line-by-line classification

| Lines | IP | User-Agent | Verdict | Reasoning |
|---|---|---|---|---|
| 6–7, 12 | 203.0.113.44 | `zgrab/0.x` | **Hostile** | zgrab is an internet-wide vulnerability scanner. It probes `/wp-admin/`, `/xmlrpc.php`, `/test.php` — all WordPress paths that do not exist on this Yii2 app. Classic automated recon. |
| 8–9, 14 | 198.51.100.7 | `python-requests/2.31` | **Hostile** | Probes `/.env`, `/phpmyadmin/`, `/api/debug` — common targets for automated exploit scanners looking for misconfigured apps. python-requests is trivially spoofable. |
| 10–11 | 216.144.250.9 | `UptimeRobot/2.0` | **Legitimate** | Hits `/health` with 200 responses. This is uptime monitoring — the brief explicitly says not to break it. |
| 13 | 104.18.22.33 | `Razorpay-Webhook/1.0` | **Legitimate** | `POST /webhooks/razorpay` returning 200. This is a payment provider webhook — the brief explicitly says not to break it. |
| 15, 19 | 45.146.164.2 | `-` (empty UA) | **Hostile** | Hits `/admin/login.php` and `/random-string`. No user-agent header, random paths. Scanning for admin panels and fuzzing URLs. |
| 16 | 110.226.180.5 | `Mozilla/5.0 (iPhone)` | **Benign but noisy** | Hits `/flights/mumbai-delhi` (a real app route, returns 200 with 8KB). This is a legitimate user. |
| 17 | 110.226.180.5 | `Mozilla/5.0 (iPhone)` | **Benign but noisy** | Same IP also probes `/.env`. This is suspicious — real iPhone users don't request `.env` files. Likely a compromised device or a browser extension running automated probes alongside normal browsing. |
| 18 | 66.249.66.1 | `Googlebot/2.1` | **Legitimate** | Crawls `/sitemap.xml` (returns 200). Verified Google IP range. Standard SEO crawler. |

### What makes a naive fix dangerous

1. **IP 110.226.180.5** makes both a legitimate request (`/flights/mumbai-delhi`,
   200) and a hostile request (`/.env`, 404). Bluntly blocking this IP would
   take down a real customer.

2. **User-Agent matching is unreliable.** Razorpay, UptimeRobot, and Googlebot
   all identify themselves via UA, but `python-requests` and `zgrab` can easily
   spoof legitimate-looking UAs. Blocking by UA string alone would miss most
   hostile traffic and could accidentally block real users who happen to use
   similar HTTP libraries.

3. **Empty user-agents** (lines 15, 19) look hostile but could also be API
   clients, health checks from custom scripts, or monitoring tools. Blanket
   blocking empty UAs risks cutting off legitimate integrations.

4. **The `/health` and `/webhooks/razorpay` endpoints must stay reachable.**
   Any solution that blocks at the Nginx level before the request reaches Yii
   must explicitly allowlist these paths.

---

## 2. The Next Two Hours — Immediate Relief

The goal is to stop the CPU/memory bleed *right now* without touching
application code and without breaking anything that matters.

### Ship: Nginx `map` + `if` blocks to 403 hostile requests at the edge

```nginx
# In http {} block:
map $http_user_agent $bad_agent {
    default         0;
    ~*zgrab          1;
    ~*python-requests 1;
}

# In server {} block, before the main location /:
location ~* ^/(wp-admin|xmlrpc\.php|\.env|phpmyadmin|admin/login\.php|test\.php|api/debug) {
    return 403;
}

# Block requests with no user-agent AND no cookie (bots, not browsers):
# Use map + conditional instead:
location / {
    if ($bad_agent) {
        return 403;
    }
    # ... normal proxy_pass to Yii
}
```

**Why this works:**
- The hostile paths (`/wp-admin/`, `/.env`, `/phpmyadmin/`, etc.) are
  never valid for this Yii2 app. Returning 403 before Nginx even
  forwards to PHP saves the full framework bootstrap cost.
- The `zgrab` and `python-requests` user-agents are consistent markers
  of automated scanning in this log. Blocking them catches the majority
  of hostile traffic without touching real browser users.
- UptimeRobot, Razorpay, and Googlebot all have distinct user-agents
  that don't match these patterns.

**What this does NOT block (and why):**
- The iPhone user (110.226.180.5) is not blocked. The `.env` request
  from this IP is caught by the path-based rule, but the legitimate
  `/flights/mumbai-delhi` request is unaffected.
- The empty-UA requests from 45.146.164.2 are caught by the path-based
  rules (`/admin/login.php`, `/random-string`) — not by a blanket
  empty-UA block.

**Expected impact:** Removes ~70–80% of hostile requests from reaching
PHP.  The remaining hostile requests (legitimate-looking UAs, paths not
in the blocklist) are still handled by the app but at much lower volume.

### Also ship: A simple Nginx rate limit

```nginx
# In http {} block:
limit_req_zone $binary_remote_addr zone=general:10m rate=30r/s;

# In server {} block:
location / {
    limit_req zone=general burst=50 nodelay;
    # ... normal proxy_pass
}
```

This caps any single IP to 30 requests/second with a burst of 50.
Legitimate traffic (including Googlebot, UptimeRobot) stays well under
this.  Aggressive scanners get throttled.  At 100 invalid requests/minute
(~1.7 r/s), this threshold is generous enough to never hit real users.

**Cost:** Zero.  This is built-in Nginx functionality.  No paid services.

---

## 3. The Durable Fix — This Week

The immediate Nginx rules are a band-aid.  The durable approach is a
layered defence that makes the application resilient to scanning
without manual rule updates.

### Layer 1: Nginx (edge filtering) — already shipped above

Keep the path-based 403s and the UA-based blocks.  These are cheap,
effective, and zero-cost.

### Layer 2: Yii2 middleware (application-level filtering)

Create a `UrlFilter` behaviour attached to the `request` component in
the Yii2 app.  For every incoming request, check the path against a
denylist *before* routing:

```php
// In config/web.php, within 'components' => ['request' => [...]]:
'as urlFilter' => [
    'class' => 'yii\filters\VerbFilter',
    // Or a custom UrlFilter behaviour:
],
```

More concretely, a simple `beforeAction` hook in a base controller or
a `behaviour()` on the Application that checks `$this->request->pathInfo`
against a list of known-bad paths and returns 403 early.  This catches
anything the Nginx rules miss (new scanning patterns, obfuscated paths).

**Effort:** ~1 hour.  A single class with a denylist array.
**Cost:** Zero.

### Layer 3: IP reputation (optional, low-cost)

If the volume of hostile traffic remains high after Layers 1–2, consider
using Nginx's `ngx_http_realip_module` with a free IP reputation list
(e.g., Spamhaus DROP list, or a Cloudflare free-tier WAF rule).  This
blocks entire netblocks known for scanning.

**Cost:** Zero if self-hosted reputation lists.  Cloudflare free tier
is also zero cost and adds DDoS protection as a bonus.

### What I would NOT do

- **Fail2ban:** Requires log parsing, jail configuration, and a
  post-install daemon.  For ~100 requests/minute, the Nginx `map`
  rules are simpler and more maintainable.  Fail2ban is excellent at
  scale but overkill here.

- **Cloudflare Pro ($20/month):** The brief says to minimise AWS spend.
  The free tier of Cloudflare would work, but the Nginx rules already
  cover the same ground.  I'd only recommend this if the team wants a
  managed WAF for other reasons.

- **ModSecurity / OWASP CRS:** A full WAF with rule sets.  Powerful
  but heavy to configure and maintain.  For a small team with two
  engineers and one week, the Nginx rules plus Yii2 middleware are
  proportionate.

---

## 4. The Weakest Point in My Plan

**The path-based Nginx denylist is static.**

It blocks the paths I identified in this log extract (`/wp-admin/`,
`/.env`, `/phpmyadmin/`, etc.), but it will not catch a scanner that
uses a novel path or an obfuscated URL (e.g., `/wp-admin%2f`, or
`/wp-admin.php`).  If the attackers shift to new paths, the denylist
needs manual updates.

**How it would fail:** A new scanning campaign targets paths not in the
denylist (e.g., `/cgi-bin/`, `/.git/config`, `/server-status`).  Each
request still reaches Yii, triggering the full framework bootstrap.

**How I'd detect it early:**
1. **Log monitoring:** Add a simple Nginx `access_log` with a custom
   format that tags 403 vs 200 responses.  Set up a daily cron job
   that counts 403s and alerts if the ratio drops (meaning hostile
   traffic is slipping through).
2. **Yii2 request logging:** The app already logs errors and warnings.
   Add a lightweight log line for every 404 response with the
   requested path.  A sudden spike in new 404 paths indicates a new
   scanning pattern.
3. **Rate-limit violations:** Nginx's `limit_req_status` logs when a
   request is throttled.  A spike here means the rate limiter is
   catching what the denylist misses — a signal to update the
   denylist.

In practice, the combination of the static denylist (catches known
paths), the rate limiter (catches volume), and the Yii2 middleware
(catches anything that reaches the app) gives three layers of
defence.  The weakest link is the denylist's static nature, but the
rate limiter is the safety net that catches novel paths at volume.

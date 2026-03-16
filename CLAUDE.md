# tp3-fast — CloudFront CDN Accelerator for TrinityP3

## Purpose

Put CloudFront in front of `www.trinityp3.com` (WordPress on Apache/EC2 behind ALB in ap-southeast-1) to dramatically improve page load times and Lighthouse scores.

## Architecture

```
User → CloudFront (edge) → origin.trinityp3.com (CNAME to ALB) → EC2
                              ↑ HTTP-only, Host: www.trinityp3.com forwarded
```

Direct admin access (bypasses CDN):
```
Admin → admin.trinityp3.com (A record to EC2 IP) → EC2
```

CloudFront cache behaviors route requests by path pattern:
- Static assets (CSS/JS/images/fonts): 30-day TTL
- Blog/content pages: 24-hour TTL
- Default pages: 12-hour TTL
- Bypass (no cache): wp-admin, wp-login, wp-cron, my-account, cart, checkout, wp-json
- Custom error page: branded "We'll be right back" page from S3 on origin 5xx (cached 5 min)

## DNS Records

| Record | Type | Target | Purpose |
|--------|------|--------|---------|
| `www.trinityp3.com` | A (alias) | CloudFront distribution | Production CDN |
| `trinityp3.com` | A (alias) | CloudFront distribution | Apex domain |
| `origin.trinityp3.com` | CNAME | `dualstack.www-trinityp3-com-2026-1815045803.ap-southeast-1.elb.amazonaws.com` | CloudFront origin |
| `admin.trinityp3.com` | A | `54.169.176.222` | Direct admin access |
| `tp3-fast.trinityp3.com` | A (alias) | API Gateway custom domain | Cache invalidation API |

## Origin Details

| Key | Value |
|-----|-------|
| Origin domain | `origin.trinityp3.com` (CNAME to `dualstack.www-trinityp3-com-2026-1815045803.ap-southeast-1.elb.amazonaws.com`) |
| Protocol | HTTP-only (CloudFront → origin) |
| Server | Apache/2.4.58 (Ubuntu) on EC2 behind ALB |
| AWS Region | ap-southeast-1 |
| CMS | WordPress + Visual Composer + WooCommerce |
| Forms | HubSpot (JS-loaded, POST to HubSpot servers) + Contact Form 7 |

## File Structure

```
tp3-fast/
├── CLAUDE.md
├── infra/
│   ├── cloudfront.yaml        # Test stack template (account 273617194870, tp3-fast.yma.cloud)
│   ├── cloudfront-live.yaml   # Production template (account 513635640086, www.trinityp3.com)
│   └── error/
│       └── index.html         # Custom error page (uploaded to S3)
├── scripts/
│   ├── invalidate.sh          # Cache invalidation CLI with presets (requires AWS creds)
│   ├── invalidate-api.sh      # Cache invalidation via API (no AWS creds needed)
│   ├── lighthouse.sh          # Lighthouse baseline/test/compare
│   └── warm-cache.sh          # Warm CloudFront cache via sitemap crawl
├── wordpress/
│   └── tp3-cache-purge.php    # WordPress plugin — purge CDN cache from wp-admin
├── reports/                   # Lighthouse JSON/HTML reports
└── package.json               # lighthouse dev dependency
```

## AWS

### Stacks

| Stack | Account | Domain | Template | Status |
|-------|---------|--------|----------|--------|
| `tp3-fast` | 273617194870 (yma-web) | `tp3-fast.yma.cloud` | `cloudfront.yaml` | Test (to be decommissioned) |
| `tp3-fast-live` | 513635640086 | `www.trinityp3.com` | `cloudfront-live.yaml` | Production |

### Credentials

Production stack uses `sa-tp3` profile from `~/.aws/credentials-admin` (Tier 3 — manual use only):
```bash
AWS_SHARED_CREDENTIALS_FILE=~/.aws/credentials-admin aws <command> --profile sa-tp3
```

Route53 hosted zone: `Z2J7S1514T8FGD` (trinityp3.com, account 513635640086)

## Cache Invalidation API

Serverless endpoint (API Gateway + Lambda) for triggering CloudFront invalidations without AWS credentials. Secured with API key.

Custom domain: `https://tp3-fast.trinityp3.com/invalidate` (edge-optimized API Gateway with ACM cert).

```bash
# Via curl
curl -X POST "https://tp3-fast.trinityp3.com/invalidate" \
  -H "x-api-key: $TP3_INVALIDATE_KEY" \
  -d '{"preset":"home"}'

# Via wrapper script (from EC2 or anywhere)
./scripts/invalidate-api.sh home
./scripts/invalidate-api.sh /blog/*
```

Presets: `all`, `home`, `css`, `js`, `static`, `blog`, `pages` (same as `invalidate.sh`).

Environment variables:
- `TP3_INVALIDATE_URL` — `https://tp3-fast.trinityp3.com/invalidate`
- `TP3_INVALIDATE_KEY` — API key (retrieve with `aws apigateway get-api-key --api-key <InvalidationApiKeyId> --include-value`)

Throttle: 2 req/sec, burst 5, max 100/day.

## WordPress Plugin

`wordpress/tp3-cache-purge.php` — single-file plugin for purging CloudFront cache from wp-admin.

Install: upload via Plugins > Add New > Upload, or SCP to `wp-content/plugins/`.

Features:
- Top-level "CDN Cache" menu in wp-admin sidebar
- Settings page for API URL + API Key (key masked in UI, stored in `wp_options`)
- Preset purge buttons: All, Home, CSS, JS, Static, Blog, Pages
- Custom path input for arbitrary invalidations
- Admin-only (`manage_options`), nonce-protected, uses `wp_remote_post()`

No auto-invalidation — manual purge only.

## Key Decisions

- No Lambda@Edge needed — CloudFront serves the real domain, so no URL rewriting required
- Origin is `origin.trinityp3.com` (CNAME to ALB) with HTTP-only protocol to avoid SSL cert mismatch
- Host header forwarded from viewer to origin so WordPress sees `Host: www.trinityp3.com`
- DNS managed outside CloudFormation for instant rollback capability
- Cookie forwarding whitelisted (not forward-all) to prevent WP cookies busting cache
- Query string forwarding enabled for pagination/search but excluded from cache key where possible
- Custom error page served from S3 via OAI (not Lambda@Edge)

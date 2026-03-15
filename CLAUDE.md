# tp3-fast — CloudFront CDN Accelerator for TrinityP3

## Purpose

Put CloudFront in front of `www.trinityp3.com` (WordPress on Apache/EC2 in ap-southeast-1) to dramatically improve page load times and Lighthouse scores.

## Architecture

```
User → CloudFront (edge) → Origin: www.trinityp3.com (18.138.157.149 / 18.140.186.2)
```

CloudFront cache behaviors route requests by path pattern:
- Static assets (CSS/JS/images/fonts): 30-day TTL
- Blog/content pages: 24-hour TTL
- Default pages: 12-hour TTL
- Bypass (no cache): wp-admin, wp-login, my-account, cart, checkout, wp-json, contact forms
- Custom error page: branded "We'll be right back" page on origin 5xx or connection failure (cached 5 min)

## Origin Details

| Key | Value |
|-----|-------|
| Domain | `www.trinityp3.com` |
| Server | Apache/2.4.58 (Ubuntu) on EC2 |
| AWS Region | ap-southeast-1 |
| CMS | WordPress + Visual Composer + WooCommerce |
| Forms | HubSpot (JS-loaded, POST to HubSpot servers) + Contact Form 7 |
| Current TTFB | ~613ms (no caching at all) |
| Current cache headers | None |

## File Structure

```
tp3-fast/
├── CLAUDE.md
├── infra/
│   └── cloudfront.yaml       # CloudFormation template
├── scripts/
│   ├── invalidate.sh          # Cache invalidation CLI
│   ├── lighthouse.sh           # Lighthouse baseline/test/compare
│   └── warm-cache.sh           # Warm CloudFront cache via sitemap crawl
├── reports/                   # Lighthouse JSON/HTML reports
└── package.json               # lighthouse dev dependency
```

## AWS

- Uses the parent orchestrator's deployer profiles
- CloudFront distribution will need an ACM certificate in us-east-1 (required for CF)
- Origin uses HTTPS to www.trinityp3.com

## Key Decisions

- CloudFront origin is the existing domain, not the EC2 IPs — preserves SSL and Host header
- Cookie forwarding whitelisted (not forward-all) to prevent WP cookies busting cache
- Query string forwarding enabled for pagination/search but excluded from cache key where possible

# wp-cloudfront-accelerator

Put CloudFront in front of any WordPress site for dramatically faster page loads — with **zero changes to WordPress**.

Lambda@Edge rewrites all URLs (HTML, CSS, JS) so the entire site stays on the CDN domain during testing, then seamlessly cuts over to the production domain via DNS.

## What it does

- Caches static assets (CSS, JS, images, fonts) at CloudFront edge locations worldwide (30-day TTL)
- Caches HTML pages with configurable TTLs (15 min default, 1 hour for blog/content)
- Bypasses cache for dynamic paths: wp-admin, wp-login, cart, checkout, WooCommerce, REST API, contact forms
- Lambda@Edge rewrites all `www.example.com` URLs to the CDN domain — no WordPress changes needed
- Adds security headers (HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy)
- HTTP/2 + HTTP/3 (QUIC) enabled
- Brotli + Gzip compression

## Architecture

```
Visitor ──> CloudFront (nearest edge) ──> Lambda@Edge (URL rewrite, cache miss only)
                │                              │
                │ cache hit                    │ origin fetch
                ▼                              ▼
           Cached response              WordPress origin
```

On cache hits, Lambda@Edge doesn't run. Visitors get sub-50ms responses from their nearest edge location.

## Results (trinityp3.com)

| Metric | Before | After | Change |
|--------|--------|-------|--------|
| First Contentful Paint | 10.9s | 5.2s | **-53%** |
| Largest Contentful Paint | 13.1s | 11.3s | **-14%** |
| Speed Index | 10.9s | 7.3s | **-33%** |
| Server Response (TTFB) | 230ms | 20ms | **-91%** |

## Prerequisites

- AWS account with CloudFront, Lambda, Route53, ACM, IAM permissions
- ACM certificate in **us-east-1** covering your CDN domain (CloudFront requirement)
- Route53 hosted zone for DNS (or manual CNAME setup)
- Node.js (for Lighthouse benchmarking)

## Quick start

### 1. Configure

Edit the parameters in `infra/cloudfront.yaml`:

```yaml
Parameters:
  OriginDomain:    # Your WordPress domain (e.g., www.example.com)
  FastDomain:      # Your CDN test domain (e.g., fast.example.com)
  AcmCertificateArn:  # ACM cert ARN in us-east-1
  HostedZoneId:    # Route53 hosted zone ID
```

Update the two constants in the Lambda@Edge code inside the template:

```javascript
const O='www.example.com';   // origin domain
const C='fast.example.com';  // CDN domain
```

### 2. Deploy

```bash
aws cloudformation create-stack \
  --region us-east-1 \
  --stack-name my-wp-accelerator \
  --template-body file://infra/cloudfront.yaml \
  --capabilities CAPABILITY_NAMED_IAM
```

Stack deploys in ~5 minutes. CloudFront distribution + Lambda@Edge + DNS record created automatically.

### 3. Benchmark

```bash
npm install
./scripts/lighthouse.sh baseline          # measure origin
./scripts/lighthouse.sh test https://fast.example.com  # measure CDN
./scripts/lighthouse.sh compare           # side-by-side
```

### 4. Production cutover

When satisfied with the results, point the production domain's DNS to CloudFront:

1. Update `FastDomain` parameter to `www.example.com`
2. Update the Lambda constants to match
3. Update the stack
4. Change DNS: `www.example.com` → CloudFront distribution domain

Users won't notice anything except faster pages. The WordPress server becomes a hidden origin.

## Cache behavior map

| Path | TTL | Lambda | Notes |
|------|-----|--------|-------|
| `/wp-admin/*` | Bypass | No | Admin panel — never cached |
| `/wp-login.php` | Bypass | No | Login page |
| `/my-account/*` | Bypass | No | WooCommerce user pages |
| `/cart/*`, `/checkout/*` | Bypass | No | WooCommerce transactions |
| `/wp-json/*` | Bypass | No | REST API |
| `/wp-content/*` | 30 days | Yes | Themes, plugins, uploads |
| `/wp-includes/*` | 30 days | Yes | Core CSS/JS |
| `/blog/*`, `/insights/*`, `/resources/*` | 1 hour | Yes | Content pages |
| Everything else | 15 min | Yes | Homepage, general pages |

## Cache invalidation

```bash
./scripts/invalidate.sh /                  # homepage
./scripts/invalidate.sh /blog/*            # all blog pages
./scripts/invalidate.sh /wp-content/*      # all static assets
./scripts/invalidate.sh /*                 # everything (use sparingly)
```

## How Lambda@Edge works

The Lambda function runs on **origin-request** (cache miss only):

1. **Binary files** (images, fonts, PDFs): Sets `Host` header to origin domain and forwards — CloudFront fetches directly
2. **Text files** (HTML, CSS, JS): Fetches from origin, rewrites all origin domain URLs to CDN domain, returns the modified response
3. **Redirects**: Rewrites `Location` header so redirects stay on CDN
4. **Non-GET requests**: Forwards with Host header fix (for POST forms, etc.)

CloudFront caches the Lambda's response, so the function only runs once per unique URL until the TTL expires.

## Customising for your site

### Adding bypass paths

If your WordPress has custom dynamic paths (e.g., `/members/*`, `/shop/*`), add them as cache behaviors with `CachePolicyId: 4135ea2d-6df8-44a3-9df3-4b5a84be39ad` (CachingDisabled).

### Adjusting TTLs

Modify the cache policy resources in the template. Lower TTLs = fresher content, higher TTLs = better performance. The 15-minute default is a good balance for most sites.

### Adding content path patterns

If your site has content under paths not listed (e.g., `/news/*`, `/guides/*`), add cache behaviors with `ContentCachePolicy` for 1-hour caching.

## Cost estimate

For a typical business WordPress site (~10,000-50,000 monthly page views):

| Component | Monthly cost |
|-----------|-------------|
| CloudFront data transfer | $1-5 |
| CloudFront requests | $0.50-2 |
| Lambda@Edge invocations | < $0.01 |
| **Total** | **$2-7/month** |

Lambda@Edge runs only on cache misses, making it effectively free at normal traffic levels.

## File structure

```
infra/
  cloudfront.yaml       # CloudFormation template (distribution + Lambda@Edge + DNS)
scripts/
  invalidate.sh         # Cache invalidation CLI
  lighthouse.sh         # Lighthouse baseline/test/compare
reports/                # Generated Lighthouse reports (gitignored)
```

## Limitations

- Lambda@Edge response body limit: 1 MB (sufficient for all standard WordPress pages)
- URL-encoded references (`%2F%2Fwww.example.com`) are not rewritten (harmless — only affects oEmbed/meta tags)
- Binary files (images, fonts) are not scanned for URL references (they don't contain any)
- Lambda@Edge must be deployed in us-east-1 (AWS requirement)

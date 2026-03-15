#!/usr/bin/env bash
# Warm the CloudFront cache by crawling all pages and their assets
#
# Usage:
#   ./warm-cache.sh                     # warm tp3-fast.yma.cloud
#   ./warm-cache.sh https://example.com # warm a custom domain
#   ./warm-cache.sh --pages-only        # only warm HTML pages, skip assets

set -u

SITE="${1:-https://tp3-fast.yma.cloud}"
SITE="${SITE%/}"
PAGES_ONLY=false
PARALLEL=10
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

if [ "${1:-}" = "--pages-only" ]; then
    PAGES_ONLY=true
    SITE="${2:-https://tp3-fast.yma.cloud}"
    SITE="${SITE%/}"
fi

# Extract domain from SITE for sed replacements
CDN_DOMAIN=$(echo "$SITE" | sed 's|https\?://||')

echo "Cache warmer for: $SITE"
echo ""

# --- Step 1: Discover pages from sitemap ---

echo "[1/4] Fetching sitemap index..."
curl -s "$SITE/sitemap_index.xml" -o "$WORK/sitemap_index.xml" 2>/dev/null || true

# Extract sitemap URLs from index (works with BSD and GNU grep)
grep -oE 'https?://[^<]+\.xml' "$WORK/sitemap_index.xml" 2>/dev/null | \
    sed "s|https*://[^/]*|$SITE|g" > "$WORK/sitemaps.txt" 2>/dev/null || true

if [ ! -s "$WORK/sitemaps.txt" ]; then
    echo "$SITE/sitemap.xml" > "$WORK/sitemaps.txt"
fi

SITEMAP_COUNT=$(wc -l < "$WORK/sitemaps.txt" | tr -d ' ')
echo "  Found $SITEMAP_COUNT sitemap(s)"

# Download each sitemap and extract page URLs
echo "[2/4] Extracting page URLs from sitemaps..."
> "$WORK/pages_raw.txt"
while IFS= read -r sitemap_url; do
    curl -s "$sitemap_url" 2>/dev/null | \
        sed -n 's/.*<loc>\([^<]*\)<\/loc>.*/\1/p' | \
        sed "s|https*://[^/]*|$SITE|g" >> "$WORK/pages_raw.txt" 2>/dev/null || true
done < "$WORK/sitemaps.txt"

echo "$SITE/" >> "$WORK/pages_raw.txt"
sort -u "$WORK/pages_raw.txt" > "$WORK/pages.txt"
PAGE_COUNT=$(wc -l < "$WORK/pages.txt" | tr -d ' ')
echo "  Found $PAGE_COUNT unique pages"

# --- Step 2: Warm HTML pages ---

echo "[3/4] Warming HTML pages ($PAGE_COUNT pages, $PARALLEL parallel)..."

> "$WORK/page_results.txt"
cat "$WORK/pages.txt" | xargs -P "$PARALLEL" -I {} \
    curl -s -o /dev/null -w '%{http_code} %{time_starttransfer}s %{url_effective}\n' \
    -H 'Accept: text/html' {} 2>/dev/null | tee -a "$WORK/page_results.txt" | \
    awk '{n++; if(n%25==0) printf "  ...warmed %d pages\n", n > "/dev/stderr"}'

WARMED=$(wc -l < "$WORK/page_results.txt" | tr -d ' ')
echo "  Warmed $WARMED pages"

if [ "$PAGES_ONLY" = true ]; then
    echo ""
    echo "Done (pages only). $WARMED pages warmed."
    exit 0
fi

# --- Step 3: Extract assets from sampled pages ---

echo "[4/4] Extracting and warming assets..."

SAMPLE_COUNT=50
head -n "$SAMPLE_COUNT" "$WORK/pages.txt" > "$WORK/sample_pages.txt"
ACTUAL_SAMPLE=$(wc -l < "$WORK/sample_pages.txt" | tr -d ' ')
echo "  Scanning $ACTUAL_SAMPLE pages for linked assets..."

> "$WORK/assets_raw.txt"
while IFS= read -r page_url; do
    curl -s "$page_url" -o "$WORK/page.html" 2>/dev/null || continue

    # CSS: href="...*.css..."
    grep -oE 'href="[^"]*\.css[^"]*"' "$WORK/page.html" 2>/dev/null | sed 's/href="//;s/"$//' >> "$WORK/assets_raw.txt" || true

    # JS: src="...*.js..."
    grep -oE 'src="[^"]*\.js[^"]*"' "$WORK/page.html" 2>/dev/null | sed 's/src="//;s/"$//' >> "$WORK/assets_raw.txt" || true

    # Images
    grep -oE 'src="[^"]*\.(png|jpg|jpeg|gif|svg|webp|avif|ico)[^"]*"' "$WORK/page.html" 2>/dev/null | sed 's/src="//;s/"$//' >> "$WORK/assets_raw.txt" || true

    # data-src (lazy loaded images)
    grep -oE 'data-src="[^"]*\.(png|jpg|jpeg|gif|svg|webp|avif)[^"]*"' "$WORK/page.html" 2>/dev/null | sed 's/data-src="//;s/"$//' >> "$WORK/assets_raw.txt" || true

    # srcset images
    grep -oE 'srcset="[^"]*"' "$WORK/page.html" 2>/dev/null | sed 's/srcset="//;s/"$//' | tr ',' '\n' | sed 's/^ *//;s/ .*//' >> "$WORK/assets_raw.txt" || true

    # Fonts from inline CSS: url(...woff2...)
    grep -oE "url(['\"]?[^)]*\.(woff2|woff|ttf|eot)[^)]*)" "$WORK/page.html" 2>/dev/null | sed "s/url(['\"]\\{0,1\\}//;s/['\"]\\{0,1\\})//" >> "$WORK/assets_raw.txt" || true

done < "$WORK/sample_pages.txt"

# Also extract assets from CSS files themselves
echo "  Scanning CSS files for font/image references..."
grep -E '\.css' "$WORK/assets_raw.txt" 2>/dev/null | head -20 | sort -u | while IFS= read -r css_url; do
    # Normalise the URL first
    case "$css_url" in
        https://*|http://*) full_url=$(echo "$css_url" | sed "s|https*://[^/]*|$SITE|g") ;;
        //*) full_url="https:$css_url" ;;
        /*) full_url="$SITE$css_url" ;;
        *) continue ;;
    esac
    curl -s "$full_url" -o "$WORK/css_tmp.css" 2>/dev/null || continue
    grep -oE "url\(['\"]?[^)]*\.(woff2|woff|ttf|eot|png|jpg|jpeg|gif|svg|webp)[^)]*\)" "$WORK/css_tmp.css" 2>/dev/null | \
        sed "s/url(['\"]\\{0,1\\}//;s/['\"]\\{0,1\\})//" >> "$WORK/assets_raw.txt" || true
done 2>/dev/null

# Normalise all URLs to absolute
> "$WORK/assets.txt"
while IFS= read -r asset; do
    [ -z "$asset" ] && continue
    # Strip query strings with ver= for dedup (CloudFront ignores them for static)
    clean=$(echo "$asset" | sed 's/?.*//')
    case "$asset" in
        https://*|http://*)
            echo "$asset" | sed "s|https*://[^/]*|$SITE|g"
            ;;
        //*)
            echo "$asset" | sed "s|//[^/]*|//$CDN_DOMAIN|g"
            ;;
        /*)
            echo "$SITE$asset"
            ;;
    esac
done < "$WORK/assets_raw.txt" | sort -u > "$WORK/assets.txt"

ASSET_COUNT=$(wc -l < "$WORK/assets.txt" | tr -d ' ')
echo "  Found $ASSET_COUNT unique assets"
echo "  Warming assets ($PARALLEL parallel)..."

cat "$WORK/assets.txt" | xargs -P "$PARALLEL" -I {} \
    curl -s -o /dev/null -w '%{http_code} %{time_starttransfer}s %{url_effective}\n' {} 2>/dev/null | \
    tee "$WORK/asset_results.txt" | \
    awk '{n++; if(n%50==0) printf "  ...warmed %d assets\n", n > "/dev/stderr"}'

ASSETS_WARMED=$(wc -l < "$WORK/asset_results.txt" | tr -d ' ')

# --- Summary ---

echo ""
echo "==============================="
echo "  Cache warming complete"
echo "==============================="
echo "  Pages:  $WARMED"
echo "  Assets: $ASSETS_WARMED"
echo "  Total:  $((WARMED + ASSETS_WARMED)) URLs"
echo ""

# Show cache hit stats
echo "Cache status check:"
curl -sI "$SITE/" 2>/dev/null | grep -i 'x-cache' || echo "  (could not check)"
echo ""

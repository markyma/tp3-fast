#!/usr/bin/env bash
# Run Lighthouse and optionally compare against baseline
#
# Usage:
#   ./lighthouse.sh baseline                    # save baseline report
#   ./lighthouse.sh test https://fast.trinityp3.com  # test a URL and compare to baseline
#   ./lighthouse.sh compare                     # show saved baseline vs latest test

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
REPORTS_DIR="$PROJECT_DIR/reports"
CHROME_PATH="${CHROME_PATH:-/Applications/Brave Browser.app/Contents/MacOS/Brave Browser}"

mkdir -p "$REPORTS_DIR"

run_lighthouse() {
    local url="$1"
    local output_name="$2"

    echo "Running Lighthouse against $url ..."
    echo ""

    CHROME_PATH="$CHROME_PATH" npx lighthouse "$url" \
        --output=json --output=html \
        --output-path="$REPORTS_DIR/$output_name" \
        --chrome-flags="--headless=new --no-sandbox" \
        --only-categories=performance,accessibility,best-practices,seo \
        2>/dev/null

    echo "Reports saved:"
    echo "  JSON: $REPORTS_DIR/$output_name.report.json"
    echo "  HTML: $REPORTS_DIR/$output_name.report.html"
    echo ""

    extract_scores "$REPORTS_DIR/$output_name.report.json"
}

extract_scores() {
    local json_file="$1"
    python3 -c "
import json, sys
with open('$json_file') as f:
    data = json.load(f)
cats = data['categories']
audits = data['audits']

print('Scores:')
for k in ['performance','accessibility','best-practices','seo']:
    c = cats[k]
    print(f'  {c[\"title\"]}: {int(c[\"score\"] * 100)}')

print()
print('Key metrics:')
metrics = [
    ('first-contentful-paint', 'FCP'),
    ('largest-contentful-paint', 'LCP'),
    ('total-blocking-time', 'TBT'),
    ('cumulative-layout-shift', 'CLS'),
    ('speed-index', 'Speed Index'),
    ('server-response-time', 'TTFB'),
]
for audit_id, label in metrics:
    a = audits.get(audit_id, {})
    val = a.get('displayValue', 'N/A')
    num = a.get('numericValue', 0)
    print(f'  {label}: {val}')
"
}

compare_reports() {
    local baseline="$REPORTS_DIR/baseline.report.json"
    local test="$REPORTS_DIR/test.report.json"

    if [ ! -f "$baseline" ]; then
        echo "No baseline found. Run: $0 baseline"
        exit 1
    fi
    if [ ! -f "$test" ]; then
        echo "No test report found. Run: $0 test <url>"
        exit 1
    fi

    python3 -c "
import json

with open('$baseline') as f:
    base = json.load(f)
with open('$test') as f:
    test = json.load(f)

print('=' * 60)
print('LIGHTHOUSE COMPARISON')
print('=' * 60)
print(f'Baseline: {base[\"finalUrl\"]}')
print(f'Test:     {test[\"finalUrl\"]}')
print()

# Category scores
print(f'{\"Category\":<20} {\"Baseline\":>10} {\"Test\":>10} {\"Delta\":>10}')
print('-' * 52)
for k in ['performance','accessibility','best-practices','seo']:
    b_score = int(base['categories'][k]['score'] * 100)
    t_score = int(test['categories'][k]['score'] * 100)
    delta = t_score - b_score
    sign = '+' if delta > 0 else ''
    print(f'{base[\"categories\"][k][\"title\"]:<20} {b_score:>10} {t_score:>10} {sign + str(delta):>10}')

print()

# Key metrics
metrics = [
    ('first-contentful-paint', 'FCP'),
    ('largest-contentful-paint', 'LCP'),
    ('total-blocking-time', 'TBT'),
    ('cumulative-layout-shift', 'CLS'),
    ('speed-index', 'Speed Index'),
    ('server-response-time', 'TTFB'),
]
print(f'{\"Metric\":<15} {\"Baseline\":>12} {\"Test\":>12} {\"Change\":>12}')
print('-' * 53)
for audit_id, label in metrics:
    b_val = base['audits'].get(audit_id, {}).get('numericValue', 0)
    t_val = test['audits'].get(audit_id, {}).get('numericValue', 0)
    b_disp = base['audits'].get(audit_id, {}).get('displayValue', 'N/A')
    t_disp = test['audits'].get(audit_id, {}).get('displayValue', 'N/A')

    if b_val and t_val:
        pct = ((t_val - b_val) / b_val) * 100
        sign = '+' if pct > 0 else ''
        change = f'{sign}{pct:.0f}%'
    else:
        change = 'N/A'
    print(f'{label:<15} {b_disp:>12} {t_disp:>12} {change:>12}')
print()
"
}

case "${1:-help}" in
    baseline)
        run_lighthouse "https://www.trinityp3.com/" "baseline"
        ;;
    test)
        url="${2:-https://fast.trinityp3.com/}"
        run_lighthouse "$url" "test"
        echo ""
        echo "Run '$0 compare' to see side-by-side comparison."
        ;;
    compare)
        compare_reports
        ;;
    *)
        echo "Usage: $0 {baseline|test [url]|compare}"
        echo ""
        echo "  baseline              Run Lighthouse on www.trinityp3.com (origin)"
        echo "  test [url]            Run Lighthouse on fast.trinityp3.com (CDN)"
        echo "  compare               Compare baseline vs test scores"
        ;;
esac

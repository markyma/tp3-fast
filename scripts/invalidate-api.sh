#!/usr/bin/env bash
# Invalidate CloudFront cache via the tp3-fast API endpoint.
# Works from EC2, CI, or anywhere with curl — no AWS credentials needed.
#
# Usage:
#   ./invalidate-api.sh <preset|path>
#
# Presets: all, home, css, js, static, blog, pages
# Custom:  ./invalidate-api.sh /some/path/*
#
# Environment variables:
#   TP3_INVALIDATE_URL   API endpoint URL (required)
#   TP3_INVALIDATE_KEY   API key (required)

set -euo pipefail

if [ -z "${TP3_INVALIDATE_URL:-}" ] || [ -z "${TP3_INVALIDATE_KEY:-}" ]; then
    echo "ERROR: Set TP3_INVALIDATE_URL and TP3_INVALIDATE_KEY environment variables"
    echo ""
    echo "  export TP3_INVALIDATE_URL='https://xxx.execute-api.us-east-1.amazonaws.com/prod/invalidate'"
    echo "  export TP3_INVALIDATE_KEY='your-api-key'"
    exit 1
fi

if [ $# -eq 0 ]; then
    echo "Usage: $0 <preset|path>"
    echo ""
    echo "Presets: all, home, css, js, static, blog, pages"
    echo "Custom:  $0 /some/path/*"
    exit 1
fi

PRESETS="all home css js static blog pages"
ARG="$1"

# Check if argument is a preset
IS_PRESET=false
for p in $PRESETS; do
    if [ "$ARG" = "$p" ]; then
        IS_PRESET=true
        break
    fi
done

if [ "$IS_PRESET" = true ]; then
    PAYLOAD="{\"preset\":\"$ARG\"}"
else
    PAYLOAD="{\"paths\":[\"$ARG\"]}"
fi

echo "Sending invalidation request..."
echo "  Payload: $PAYLOAD"
echo ""

RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$TP3_INVALIDATE_URL" \
    -H "x-api-key: $TP3_INVALIDATE_KEY" \
    -H "Content-Type: application/json" \
    -d "$PAYLOAD")

HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [ "$HTTP_CODE" -ge 200 ] && [ "$HTTP_CODE" -lt 300 ]; then
    echo "Success ($HTTP_CODE):"
    echo "$BODY" | python3 -m json.tool 2>/dev/null || echo "$BODY"
else
    echo "ERROR ($HTTP_CODE):"
    echo "$BODY" | python3 -m json.tool 2>/dev/null || echo "$BODY"
    exit 1
fi

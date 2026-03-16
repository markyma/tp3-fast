#!/usr/bin/env bash
# Invalidate CloudFront cache paths for tp3-fast (production)
#
# Usage:
#   ./invalidate.sh <preset|path...>
#
# Presets:
#   all        Invalidate everything (/*)
#   home       Homepage only (/)
#   css        All CSS files
#   js         All JS files
#   static     All static assets (wp-content + wp-includes)
#   blog       All blog pages
#   pages      All content pages (blog + insights + resources)
#
# Custom:
#   ./invalidate.sh /some/path/* /another/path
#
# Rollback DNS (revert to direct ALB):
#   Record the original www.trinityp3.com and trinityp3.com DNS records
#   before cutover and UPSERT them back via Route53 if needed.

set -euo pipefail

STACK_NAME="tp3-fast-live"
AWS_PROFILE="${AWS_PROFILE:-sa-tp3}"
export AWS_SHARED_CREDENTIALS_FILE="${AWS_SHARED_CREDENTIALS_FILE:-$HOME/.aws/credentials-admin}"
REGION="${AWS_REGION:-us-east-1}"

resolve_preset() {
    case "$1" in
        all)
            echo "/*"
            ;;
        home)
            echo "/"
            ;;
        css)
            echo "/wp-content/*.css"
            echo "/wp-includes/*.css"
            ;;
        js)
            echo "/wp-content/*.js"
            echo "/wp-includes/*.js"
            ;;
        static)
            echo "/wp-content/*"
            echo "/wp-includes/*"
            ;;
        blog)
            echo "/blog/*"
            ;;
        pages)
            echo "/blog/*"
            echo "/insights/*"
            echo "/resources/*"
            ;;
        *)
            return 1
            ;;
    esac
}

if [ $# -eq 0 ]; then
    echo "Usage: $0 <preset|path...>"
    echo ""
    echo "Presets:"
    echo "  all        Invalidate everything (/*)"
    echo "  home       Homepage only (/)"
    echo "  css        All CSS (/wp-content/*.css, /wp-includes/*.css)"
    echo "  js         All JS (/wp-content/*.js, /wp-includes/*.js)"
    echo "  static     All static assets (/wp-content/*, /wp-includes/*)"
    echo "  blog       All blog pages (/blog/*)"
    echo "  pages      All content pages (/blog/*, /insights/*, /resources/*)"
    echo ""
    echo "Custom:"
    echo "  $0 /some/path/* /another/path"
    echo ""
    echo "Cost: First 1,000 invalidations/month free, then \$0.005/path"
    exit 1
fi

# Get distribution ID from CloudFormation
DIST_ID=$(aws cloudformation describe-stacks \
    --profile "$AWS_PROFILE" \
    --region "$REGION" \
    --stack-name "$STACK_NAME" \
    --query "Stacks[0].Outputs[?OutputKey=='DistributionId'].OutputValue" \
    --output text 2>/dev/null || echo "")

if [ -z "$DIST_ID" ] || [ "$DIST_ID" = "None" ]; then
    echo "ERROR: Could not find distribution ID from stack '$STACK_NAME'"
    echo "Is the CloudFormation stack deployed?"
    exit 1
fi

# Resolve presets or use raw paths
INVALIDATE_PATHS=()
for arg in "$@"; do
    if preset_paths=$(resolve_preset "$arg" 2>/dev/null); then
        while IFS= read -r p; do
            INVALIDATE_PATHS+=("$p")
        done <<< "$preset_paths"
    else
        INVALIDATE_PATHS+=("$arg")
    fi
done

# Build paths JSON
PATHS=""
COUNT=0
for p in "${INVALIDATE_PATHS[@]}"; do
    COUNT=$((COUNT + 1))
    if [ -n "$PATHS" ]; then PATHS="$PATHS,"; fi
    PATHS="$PATHS\"$p\""
done

CALLER_REF="tp3-live-$(date +%s)"

echo "Invalidating $COUNT path(s) on distribution $DIST_ID..."
for p in "${INVALIDATE_PATHS[@]}"; do
    echo "  $p"
done
echo ""

aws cloudfront create-invalidation \
    --profile "$AWS_PROFILE" \
    --distribution-id "$DIST_ID" \
    --invalidation-batch "{
        \"Paths\": {
            \"Quantity\": $COUNT,
            \"Items\": [$PATHS]
        },
        \"CallerReference\": \"$CALLER_REF\"
    }" \
    --output table

echo ""
echo "Invalidation submitted. Typically completes in 1-2 minutes."

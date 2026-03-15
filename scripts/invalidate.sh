#!/usr/bin/env bash
# Invalidate CloudFront cache paths for tp3-fast
#
# Usage:
#   ./invalidate.sh /blog/*          # invalidate all blog pages
#   ./invalidate.sh /                # invalidate homepage
#   ./invalidate.sh /*               # invalidate everything (use sparingly)
#   ./invalidate.sh /page1 /page2    # invalidate multiple paths

set -euo pipefail

STACK_NAME="tp3-fast"
AWS_PROFILE="${AWS_PROFILE:-yma-cloud-deployer}"
REGION="${AWS_REGION:-ap-southeast-1}"

if [ $# -eq 0 ]; then
    echo "Usage: $0 <path> [path2] [path3] ..."
    echo ""
    echo "Examples:"
    echo "  $0 /                    # homepage"
    echo "  $0 /blog/*              # all blog pages"
    echo "  $0 /*                   # everything (costs \$0.005/path after first 1000/month)"
    echo "  $0 /wp-content/*        # all static assets"
    echo ""
    echo "Current invalidations in progress:"
    DIST_ID=$(aws cloudfront list-distributions \
        --profile "$AWS_PROFILE" \
        --query "DistributionList.Items[?Comment=='TrinityP3 CDN Accelerator'].Id" \
        --output text 2>/dev/null || echo "")
    if [ -n "$DIST_ID" ]; then
        aws cloudfront list-invalidations \
            --profile "$AWS_PROFILE" \
            --distribution-id "$DIST_ID" \
            --query "InvalidationList.Items[?Status=='InProgress']" \
            --output table 2>/dev/null || echo "  (none or unable to query)"
    else
        echo "  Distribution not found"
    fi
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

# Build paths JSON
PATHS=""
COUNT=0
for p in "$@"; do
    COUNT=$((COUNT + 1))
    if [ -n "$PATHS" ]; then PATHS="$PATHS,"; fi
    PATHS="$PATHS\"$p\""
done

CALLER_REF="tp3-fast-$(date +%s)"

echo "Invalidating $COUNT path(s) on distribution $DIST_ID..."
echo "Paths: $*"
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

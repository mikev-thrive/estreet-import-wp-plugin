#!/bin/bash

# Test script for the setorderdate API endpoint
# Make sure to update the variables below with your actual values

# Configuration
SITE_URL="https://estreetplastics.thriveagency.local"  # Update with your actual site URL
USERNAME="hosting@thriveagency.com"         # Update with your admin username
PASSWORD="ZMb5 XsKq Bpuw 9iSY yTlJ 80tX"         # Update with your admin password
ORDER_ID="51633"                        # Update with an actual order ID
NEW_DATE="2023-07-10 10:30:00"        # Update with desired date

echo "Testing setorderdate endpoint..."
echo "Site URL: $SITE_URL"
echo "Order ID: $ORDER_ID"
echo "New Date: $NEW_DATE"
echo ""

# Get authentication token (if using Application Passwords)
# Note: You may need to create an Application Password in WordPress admin
RAW_HEADERS=$(curl -X POST \
  -u "$USERNAME:$PASSWORD" \
  -H "Content-Type: application/json" \
  -d "{
    \"order_id\": $ORDER_ID,
    \"date\": \"$NEW_DATE\"
  }" \
  -s -D - -o /dev/null \
  "$SITE_URL/wp-json/thrive/v1/setorderdate")

RAW_BODY=$(curl -X POST \
  -u "$USERNAME:$PASSWORD" \
  -H "Content-Type: application/json" \
  -d "{
    \"order_id\": $ORDER_ID,
    \"date\": \"$NEW_DATE\"
  }" \
  -s \
  "$SITE_URL/wp-json/thrive/v1/setorderdate")

# Output raw headers and body
echo "Raw Headers:"
echo "$RAW_HEADERS"
echo "Raw Body:"
echo "$RAW_BODY"

# Pass raw body to jq for formatting
echo "$RAW_BODY" | jq '.'
echo ""
echo "Test completed."

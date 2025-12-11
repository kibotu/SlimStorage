#!/bin/bash
#
# Unified Test Runner
# Runs all API tests (Key/Value Store + Event API)
#

set -e

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

# Get the directory where this script is located
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

echo -e "${BOLD}${BLUE}╔════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${BLUE}║     SlimStorage API Test Suite Runner     ║${NC}"
echo -e "${BOLD}${BLUE}╚════════════════════════════════════════════╝${NC}"
echo ""

# Parse command line arguments
API_URL="https://yourdomain.com/api"
API_KEY="8287a1ff7bb18c13e28842054001a95b8b3481c2507dc1510a2184009e1103bb"

# Check if API key is provided
if [ -z "$API_KEY" ]; then
    echo -e "${YELLOW}⚠️  No API key provided${NC}"
    echo ""
    echo "Usage: $0 [API_URL] [API_KEY]"
    echo ""
    echo "Examples:"
    echo "  $0 https://yourdomain.com/api YOUR_API_KEY"
    echo "  $0 YOUR_API_KEY  # Uses default URL"
    echo ""
    echo "Or set environment variable:"
    echo "  export API_KEY=YOUR_API_KEY"
    echo "  $0"
    echo ""
    
    # Check if API_KEY is in environment
    if [ -n "$API_KEY" ]; then
        echo -e "${GREEN}✓ Using API_KEY from environment${NC}"
    else
        exit 1
    fi
fi

# If only one argument provided, assume it's the API key
if [ -n "$1" ] && [ -z "$2" ]; then
    # Check if first arg looks like a URL
    if [[ "$1" =~ ^https?:// ]]; then
        API_URL="$1"
        API_KEY="${API_KEY}"
    else
        API_KEY="$1"
        API_URL="https://yourdomain.com/api"
    fi
fi

echo -e "${BLUE}Configuration:${NC}"
echo "  API URL: $API_URL"
echo "  API Key: ${API_KEY:0:16}...${API_KEY: -8}"
echo ""

# Check if uv is installed
if ! command -v uv &> /dev/null; then
    echo -e "${YELLOW}⚙️  Installing uv (Python package manager)...${NC}"
    curl -LsSf https://astral.sh/uv/install.sh | sh
    export PATH="$HOME/.cargo/bin:$PATH"
    echo ""
fi

# Track test results
TESTS_PASSED=0
TESTS_FAILED=0

# Export API_KEY for test scripts
export API_KEY="$API_KEY"
export BASE_URL="$API_URL"

# Run Key/Value Store API tests
echo -e "${BOLD}${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BOLD}${BLUE}  Test Suite 1: Key/Value Store API${NC}"
echo -e "${BOLD}${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

cd "$SCRIPT_DIR"
if API_KEY="$API_KEY" BASE_URL="$API_URL" uv run --with requests test-api.py 2>&1; then
    echo ""
    echo -e "${GREEN}✅ Key/Value Store API tests: PASSED${NC}"
    ((TESTS_PASSED++))
else
    echo ""
    echo -e "${RED}❌ Key/Value Store API tests: FAILED${NC}"
    ((TESTS_FAILED++))
fi

echo ""
echo ""

# Run Event API tests
echo -e "${BOLD}${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BOLD}${BLUE}  Test Suite 2: Event Data API${NC}"
echo -e "${BOLD}${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

cd "$SCRIPT_DIR"
if python3 test-event-api.py "$API_URL" "$API_KEY" 2>&1; then
    echo ""
    echo -e "${GREEN}✅ Event API tests: PASSED${NC}"
    ((TESTS_PASSED++))
else
    echo ""
    echo -e "${RED}❌ Event API tests: FAILED${NC}"
    ((TESTS_FAILED++))
fi

echo ""
echo ""

# Run Schema API tests
echo -e "${BOLD}${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BOLD}${BLUE}  Test Suite 3: Schema API (Optimization)${NC}"
echo -e "${BOLD}${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

cd "$SCRIPT_DIR"
if python3 test-schema-api.py "$API_URL" "$API_KEY" 2>&1; then
    echo ""
    echo -e "${GREEN}✅ Schema API tests: PASSED${NC}"
    ((TESTS_PASSED++))
else
    echo ""
    echo -e "${RED}❌ Schema API tests: FAILED${NC}"
    ((TESTS_FAILED++))
fi

echo ""
echo ""

# Print summary
echo -e "${BOLD}${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BOLD}${BLUE}  Test Summary${NC}"
echo -e "${BOLD}${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo -e "  Total Test Suites: $((TESTS_PASSED + TESTS_FAILED))"
echo -e "  ${GREEN}Passed: $TESTS_PASSED${NC}"
echo -e "  ${RED}Failed: $TESTS_FAILED${NC}"
echo ""

if [ $TESTS_FAILED -eq 0 ]; then
    echo -e "${BOLD}${GREEN}🎉 All test suites passed!${NC}"
    echo ""
    exit 0
else
    echo -e "${BOLD}${RED}❌ Some test suites failed${NC}"
    echo ""
    exit 1
fi



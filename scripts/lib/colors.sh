# shellcheck shell=bash
# Terminal colors + print helpers used by helper.sh and any sibling scripts.
# Source via: . "$(dirname "$0")/scripts/lib/colors.sh"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
RESET='\033[0m'

print_header() { echo -e "\n${CYAN}${BOLD}$1${RESET}"; }
print_ok()     { echo -e "${GREEN}✓ $1${RESET}"; }
print_err()    { echo -e "${RED}✗ $1${RESET}"; }
print_info()   { echo -e "${YELLOW}→ $1${RESET}"; }
print_sep()    { echo "─────────────────────────────────────────"; }

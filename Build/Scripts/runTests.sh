#!/usr/bin/env bash

#
# TYPO3 Extension Test Runner - nr_passkeys_be
# Based on typo3-ci-workflows template.
#
# Usage: ./Build/Scripts/runTests.sh [options] <command>
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Extension root directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# Composer binary directory
VENDOR_BIN="${ROOT_DIR}/.Build/bin"

# Default values
PHP_VERSION="${PHP_VERSION:-8.4}"
EXTRA_TEST_OPTIONS=""
COVERAGE=""

# --- Extension points: override these paths if your layout differs ---
PHPSTAN_CONFIG="${ROOT_DIR}/Build/phpstan.neon"
CGL_CONFIG="${ROOT_DIR}/Build/.php-cs-fixer.php"
RECTOR_CONFIG="${ROOT_DIR}/Build/rector.php"
PHPUNIT_CONFIG="${ROOT_DIR}/Build/phpunit.xml"
PHPUNIT_FUNCTIONAL_CONFIG="${ROOT_DIR}/Build/phpunit.functional.xml"
INFECTION_CONFIG="${ROOT_DIR}/Build/infection.json5"
# --- End extension points ---

usage() {
    cat << EOF
TYPO3 Extension Test Runner - nr_passkeys_be

Usage: $(basename "$0") [OPTIONS] <COMMAND>

Commands:
    unit              Run unit tests
    functional        Run functional tests
    fuzz              Run fuzz tests (property-based testing)
    mutation          Run mutation tests with Infection
    phpstan           Run PHPStan static analysis
    cgl               Run PHP-CS-Fixer in dry-run mode
    cgl:fix           Run PHP-CS-Fixer and apply fixes
    rector            Run Rector in dry-run mode
    rector:fix        Run Rector and apply changes
    ci                Run full CI suite (cgl, phpstan, unit)
    all               Run all tests and quality checks

Options:
    -h, --help        Show this help message
    -v, --verbose     Verbose output
    -c, --coverage    Generate code coverage report
    -p, --php         PHP version (informational, default: ${PHP_VERSION})
    -e, --extra       Extra options to pass to test tools

Examples:
    $(basename "$0") unit
    $(basename "$0") -c unit
    $(basename "$0") -e "--filter=testName" unit
    $(basename "$0") ci

EOF
}

info()    { echo -e "${BLUE}[INFO]${NC} $1"; }
success() { echo -e "${GREEN}[OK]${NC} $1"; }
warning() { echo -e "${YELLOW}[WARN]${NC} $1"; }
error()   { echo -e "${RED}[ERROR]${NC} $1"; }

check_dependencies() {
    if [[ ! -d "${ROOT_DIR}/.Build/vendor" ]]; then
        error "Dependencies not installed. Run 'composer install' first."
        exit 1
    fi
}

run_unit_tests() {
    info "Running unit tests..."
    check_dependencies
    local coverage_opts=""
    if [[ -n "${COVERAGE}" ]]; then
        coverage_opts="--coverage-html ${ROOT_DIR}/.Build/coverage/html --coverage-clover ${ROOT_DIR}/.Build/coverage/clover.xml"
        mkdir -p "${ROOT_DIR}/.Build/coverage"
    fi
    # shellcheck disable=SC2086
    "${VENDOR_BIN}/phpunit" -c "${PHPUNIT_CONFIG}" --testsuite unit ${coverage_opts} ${EXTRA_TEST_OPTIONS}
    success "Unit tests completed"
}

run_functional_tests() {
    info "Running functional tests..."
    check_dependencies
    export typo3DatabaseDriver="pdo_sqlite"
    if [[ -f "${PHPUNIT_FUNCTIONAL_CONFIG}" ]]; then
        # shellcheck disable=SC2086
        "${VENDOR_BIN}/phpunit" -c "${PHPUNIT_FUNCTIONAL_CONFIG}" ${EXTRA_TEST_OPTIONS}
    elif [[ -f "${PHPUNIT_CONFIG}" ]]; then
        # shellcheck disable=SC2086
        "${VENDOR_BIN}/phpunit" -c "${PHPUNIT_CONFIG}" --testsuite functional ${EXTRA_TEST_OPTIONS}
    else
        warning "No functional test configuration found, skipping."
        return
    fi
    success "Functional tests completed"
}

run_fuzz_tests() {
    info "Running fuzz tests..."
    check_dependencies
    # shellcheck disable=SC2086
    "${VENDOR_BIN}/phpunit" -c "${PHPUNIT_CONFIG}" --testsuite fuzz ${EXTRA_TEST_OPTIONS}
    success "Fuzz tests completed"
}

run_mutation_tests() {
    info "Running mutation tests with Infection..."
    check_dependencies
    if [[ -f "${INFECTION_CONFIG}" ]]; then
        # shellcheck disable=SC2086
        "${VENDOR_BIN}/infection" --configuration="${INFECTION_CONFIG}" --threads=4 -s --no-progress ${EXTRA_TEST_OPTIONS}
    else
        warning "infection config not found, skipping."
        return
    fi
    success "Mutation tests completed"
}

run_phpstan() {
    info "Running PHPStan static analysis..."
    check_dependencies
    "${VENDOR_BIN}/phpstan" analyse -c "${PHPSTAN_CONFIG}"
    success "PHPStan analysis completed"
}

run_cgl() {
    info "Running PHP-CS-Fixer (dry-run)..."
    check_dependencies
    "${VENDOR_BIN}/php-cs-fixer" fix --dry-run --diff --config="${CGL_CONFIG}"
    success "CGL check completed"
}

run_cgl_fix() {
    info "Running PHP-CS-Fixer (applying fixes)..."
    check_dependencies
    "${VENDOR_BIN}/php-cs-fixer" fix --config="${CGL_CONFIG}"
    success "CGL fixes applied"
}

run_rector() {
    info "Running Rector (dry-run)..."
    check_dependencies
    "${VENDOR_BIN}/rector" process --config "${RECTOR_CONFIG}" --dry-run
    success "Rector analysis completed"
}

run_rector_fix() {
    info "Running Rector (applying changes)..."
    check_dependencies
    "${VENDOR_BIN}/rector" process --config "${RECTOR_CONFIG}"
    success "Rector changes applied"
}

run_ci() {
    info "Running CI suite..."
    run_cgl
    run_phpstan
    run_unit_tests
    success "CI suite completed"
}

run_all() {
    info "Running all tests and quality checks..."
    run_cgl
    run_phpstan
    run_unit_tests
    run_functional_tests
    success "All tests and checks completed"
}

# Parse command line arguments
COMMAND=""
while [[ $# -gt 0 ]]; do
    case $1 in
        -h|--help)     usage; exit 0 ;;
        -v|--verbose)  set -x; shift ;;
        -c|--coverage) COVERAGE="1"; shift ;;
        -p|--php)      PHP_VERSION="$2"; shift 2 ;;
        -e|--extra)    EXTRA_TEST_OPTIONS="$2"; shift 2 ;;
        -*)            error "Unknown option: $1"; usage; exit 1 ;;
        *)             COMMAND="$1"; shift ;;
    esac
done

cd "${ROOT_DIR}"

case "${COMMAND}" in
    unit)       run_unit_tests ;;
    functional) run_functional_tests ;;
    fuzz)       run_fuzz_tests ;;
    mutation)   run_mutation_tests ;;
    phpstan)    run_phpstan ;;
    cgl)        run_cgl ;;
    cgl:fix)    run_cgl_fix ;;
    rector)     run_rector ;;
    rector:fix) run_rector_fix ;;
    ci)         run_ci ;;
    all)        run_all ;;
    "")         usage; exit 1 ;;
    *)          error "Unknown command: ${COMMAND}"; usage; exit 1 ;;
esac

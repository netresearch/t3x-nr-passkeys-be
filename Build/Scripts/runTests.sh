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
    e2e               Run E2E tests (PHP built-in server + MySQL)
    js                Run JavaScript unit tests (vitest)
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
    $(basename "$0") e2e
    $(basename "$0") js
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

run_e2e_tests() {
    info "Running E2E tests (PHP built-in server + MySQL)..."
    check_dependencies

    local TYPO3_BASE_URL="${TYPO3_BASE_URL:-http://localhost:8080}"
    local MYSQL_PORT="${MYSQL_PORT:-3306}"
    local PHP_SERVER_PID=""
    local MYSQL_CONTAINER=""
    local E2E_EXIT_CODE=0

    cleanup_e2e() {
        info "Cleaning up E2E environment..."
        if [[ -n "${PHP_SERVER_PID}" ]]; then
            kill "${PHP_SERVER_PID}" 2>/dev/null || true
            wait "${PHP_SERVER_PID}" 2>/dev/null || true
        fi
        if [[ -n "${MYSQL_CONTAINER}" ]]; then
            docker rm -f "${MYSQL_CONTAINER}" 2>/dev/null || true
        fi
    }
    trap cleanup_e2e EXIT

    # Start MySQL container
    MYSQL_CONTAINER="nr-passkeys-e2e-mysql-$$"
    info "Starting MySQL container: ${MYSQL_CONTAINER}"
    docker run --rm -d \
        --name "${MYSQL_CONTAINER}" \
        -e MYSQL_ROOT_PASSWORD=root \
        -e MYSQL_DATABASE=typo3 \
        -p "${MYSQL_PORT}:3306" \
        --tmpfs /var/lib/mysql:rw,noexec,nosuid \
        mysql:8.0 >/dev/null

    # Wait for MySQL to be ready
    info "Waiting for MySQL..."
    local COUNT=0
    while ! docker exec "${MYSQL_CONTAINER}" mysqladmin ping -h localhost --silent 2>/dev/null; do
        if [[ "${COUNT}" -gt 30 ]]; then
            error "MySQL did not become ready in time."
            exit 1
        fi
        sleep 1
        COUNT=$((COUNT + 1))
    done
    success "MySQL is ready"

    # Set up TYPO3
    info "Setting up TYPO3..."
    export TYPO3_DB_DRIVER=mysqli
    export TYPO3_DB_HOST=127.0.0.1
    export TYPO3_DB_PORT="${MYSQL_PORT}"
    export TYPO3_DB_DBNAME=typo3
    export TYPO3_DB_USERNAME=root
    export TYPO3_DB_PASSWORD=root

    if [[ -f "${VENDOR_BIN}/typo3" ]]; then
        "${VENDOR_BIN}/typo3" setup \
            --driver=mysqli \
            --host=127.0.0.1 \
            --port="${MYSQL_PORT}" \
            --dbname=typo3 \
            --username=root \
            --password=root \
            --admin-username=admin \
            --admin-password='Joh316!!' \
            --admin-email=admin@example.com \
            --project-name=nr-passkeys-e2e \
            --no-interaction \
            --force
    else
        warning "typo3 CLI not found, skipping TYPO3 setup (ensure instance is configured)."
    fi

    # Start PHP built-in server
    local DOCROOT="${ROOT_DIR}/.Build/web"
    if [[ ! -d "${DOCROOT}" ]]; then
        DOCROOT="${ROOT_DIR}/public"
    fi
    info "Starting PHP built-in server on ${TYPO3_BASE_URL} (docroot: ${DOCROOT})..."
    php -S 0.0.0.0:8080 -t "${DOCROOT}" >/dev/null 2>&1 &
    PHP_SERVER_PID=$!

    # Wait for PHP server
    COUNT=0
    while ! curl -s -o /dev/null "http://localhost:8080" 2>/dev/null; do
        if [[ "${COUNT}" -gt 15 ]]; then
            error "PHP server did not start in time."
            exit 1
        fi
        sleep 1
        COUNT=$((COUNT + 1))
    done
    success "PHP server is ready"

    # Install Playwright browsers if needed
    if ! npx playwright install --dry-run chromium >/dev/null 2>&1; then
        info "Installing Playwright browsers..."
        npx playwright install chromium
    fi

    # Run Playwright tests
    info "Running Playwright tests..."
    export TYPO3_BASE_URL
    # shellcheck disable=SC2086
    npx playwright test ${EXTRA_TEST_OPTIONS} || E2E_EXIT_CODE=$?

    if [[ "${E2E_EXIT_CODE}" -eq 0 ]]; then
        success "E2E tests completed"
    else
        error "E2E tests failed (exit code: ${E2E_EXIT_CODE})"
    fi
    return "${E2E_EXIT_CODE}"
}

run_js_tests() {
    info "Running JavaScript unit tests..."
    # shellcheck disable=SC2086
    npx vitest run ${EXTRA_TEST_OPTIONS}
    success "JavaScript tests completed"
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
    e2e)        run_e2e_tests ;;
    js)         run_js_tests ;;
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

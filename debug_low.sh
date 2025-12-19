#!/bin/bash

###############################################################################
# debug_low.sh - Ultimate Interactive PHP Debugging & Testing Assistant
# Version: 2.0.2 (Debug Edition)
# Author: CodeIgniter Sensei
###############################################################################

# Exit on error
set -e

# Debug: Show we're starting
echo "=== DEBUG_LOW STARTING ===" >&2
echo "Current directory: $(pwd)" >&2
echo "User: $(whoami)" >&2
echo "Shell: $SHELL" >&2

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[1;36m'
WHITE='\033[1;37m'
GRAY='\033[0;90m'
NC='\033[0m'

# Global variables
PROJECT_ROOT=""
PROJECT_TYPE=""
PHP_VERSION=""
CURRENT_FILE=""
CURRENT_CLASS=""
CACHE_DIR=""
LOG_FILE=""

# Configuration
DEBUG_MODE=true
MAX_RECENT_FILES=10

###############################################################################
# INITIALIZATION
###############################################################################

init_cache() {
    echo "=== INIT CACHE ===" >&2
    
    # Try multiple locations for cache
    local cache_base=""
    
    # Check for Termux
    if [ -d "/data/data/com.termux/files/usr/tmp" ]; then
        cache_base="/data/data/com.termux/files/usr/tmp"
        echo "Using Termux temp directory" >&2
    # Check for TMPDIR
    elif [ -n "${TMPDIR:-}" ] && [ -d "${TMPDIR}" ]; then
        cache_base="${TMPDIR}"
        echo "Using TMPDIR: $TMPDIR" >&2
    # Default to /tmp
    else
        cache_base="/tmp"
        echo "Using default /tmp" >&2
    fi
    
    # Create unique cache directory
    local user_id
    user_id=$(id -u 2>/dev/null || echo "user")
    local dir_hash
    dir_hash=$(echo "$PWD" | md5sum 2>/dev/null | cut -c1-8 || echo "default")
    
    CACHE_DIR="${cache_base}/debug_low_${user_id}_${dir_hash}"
    LOG_FILE="${CACHE_DIR}/debug.log"
    
    echo "Cache directory will be: $CACHE_DIR" >&2
    
    # Create directory
    mkdir -p "$CACHE_DIR" 2>/dev/null || {
        echo "Failed to create cache directory" >&2
        return 1
    }
    
    # Create log file
    touch "$LOG_FILE" 2>/dev/null || {
        echo "Failed to create log file" >&2
        return 1
    }
    
    echo "Cache initialized successfully" >&2
    return 0
}

log_message() {
    local level="$1"
    local message="$2"
    local timestamp
    timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    echo "[$timestamp] [$level] $message" >> "$LOG_FILE"
    
    case "$level" in
        "ERROR") echo -e "${RED}[ERROR] $message${NC}" >&2 ;;
        "WARN") echo -e "${YELLOW}[WARN] $message${NC}" >&2 ;;
        "INFO") echo -e "${BLUE}[INFO] $message${NC}" >&2 ;;
        "DEBUG") [ "$DEBUG_MODE" = true ] && echo -e "${GRAY}[DEBUG] $message${NC}" >&2 ;;
        "SUCCESS") echo -e "${GREEN}[SUCCESS] $message${NC}" >&2 ;;
    esac
}

log_error() { log_message "ERROR" "$1"; }
log_warn() { log_message "WARN" "$1"; }
log_info() { log_message "INFO" "$1"; }
log_debug() { log_message "DEBUG" "$1"; }
log_success() { log_message "SUCCESS" "$1"; }

###############################################################################
# DEPENDENCY CHECKS
###############################################################################

check_dependencies() {
    echo "=== CHECKING DEPENDENCIES ===" >&2
    
    # Check PHP
    if ! command -v php >/dev/null 2>&1; then
        echo -e "${RED}PHP is not installed!${NC}" >&2
        echo -e "${YELLOW}Please install PHP first:${NC}" >&2
        echo -e "  Ubuntu/Debian: sudo apt install php-cli" >&2
        echo -e "  Termux: pkg install php" >&2
        echo -e "  macOS: brew install php" >&2
        return 1
    fi
    
    PHP_VERSION=$(php -r "echo PHP_VERSION;" 2>/dev/null || echo "unknown")
    log_info "PHP version: $PHP_VERSION"
    
    # Check for common PHP extensions
    local has_tokenizer=$(php -r "echo extension_loaded('tokenizer') ? 'yes' : 'no';" 2>/dev/null || echo "no")
    if [ "$has_tokenizer" = "no" ]; then
        log_warn "PHP tokenizer extension not loaded (analysis may be limited)"
    fi
    
    return 0
}

detect_project() {
    echo "=== DETECTING PROJECT ===" >&2
    
    PROJECT_ROOT=$(pwd)
    log_info "Project root: $PROJECT_ROOT"
    
    # Check for composer.json
    if [ -f "composer.json" ]; then
        if grep -q '"codeigniter4/framework"' "composer.json"; then
            PROJECT_TYPE="CodeIgniter 4"
        elif grep -q '"laravel/framework"' "composer.json"; then
            PROJECT_TYPE="Laravel"
        else
            PROJECT_TYPE="PHP (Composer)"
        fi
    else
        PROJECT_TYPE="Plain PHP"
    fi
    
    log_info "Project type: $PROJECT_TYPE"
}

###############################################################################
# SIMPLE UI FUNCTIONS
###############################################################################

print_header() {
    clear 2>/dev/null || printf "\033c"
    
    echo -e "${PURPLE}"
    echo "╔══════════════════════════════════════════════════════════════╗"
    echo "║                   PHP DEBUGGING ASSISTANT                    ║"
    echo "╚══════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
    echo -e "${CYAN}Version: 2.0.2 | Project: $PROJECT_TYPE | PHP: $PHP_VERSION${NC}"
    echo
}

print_menu() {
    local title="$1"
    shift
    local items=("$@")
    
    echo -e "${CYAN}${title}${NC}"
    echo -e "${BLUE}══════════════════════════════════════════════════════════════${NC}"
    echo
    
    for i in "${!items[@]}"; do
        local num=$((i + 1))
        local item="${items[$i]}"
        echo -e "  ${GREEN}$num)${NC} ${WHITE}$item${NC}"
    done
    
    echo
    echo -e "${BLUE}══════════════════════════════════════════════════════════════${NC}"
    echo
}

get_choice() {
    local prompt="$1"
    local max="$2"
    
    while true; do
        echo -ne "${CYAN}$prompt (1-$max, b=back, q=quit): ${NC}"
        read -r choice
        
        case "$choice" in
            [1-9]*) 
                if [ "$choice" -ge 1 ] && [ "$choice" -le "$max" ]; then
                    echo "$choice"
                    return 0
                else
                    echo -e "${RED}Please enter a number between 1 and $max${NC}"
                fi
                ;;
            b|B) echo "back"; return 0 ;;
            q|Q) echo "quit"; return 0 ;;
            "") echo -e "${RED}Please enter a choice${NC}" ;;
            *) echo -e "${RED}Invalid choice${NC}" ;;
        esac
    done
}

pause() {
    echo -e "${GRAY}Press any key to continue...${NC}"
    read -n 1 -s
}

###############################################################################
# FILE OPERATIONS
###############################################################################

scan_php_files() {
    local dir="${1:-.}"
    
    log_debug "Scanning PHP files in: $dir"
    
    # Simple find command
    find "$dir" -type f -name "*.php" 2>/dev/null | head -50 || true
}

analyze_file() {
    local file="$1"
    
    [ ! -f "$file" ] && {
        log_error "File not found: $file"
        return 1
    }
    
    log_info "Analyzing: $file"
    
    # Simple analysis using grep (fallback if PHP tokenizer not available)
    local class_name=$(grep -E '^\s*(class|interface|trait)\s+[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*' "$file" | head -1 | sed -E 's/.*(class|interface|trait)\s+//' | awk '{print $1}' || echo "")
    local namespace=$(grep -E '^\s*namespace\s+' "$file" | head -1 | sed -E 's/namespace\s+//' | sed 's/;//' || echo "")
    
    if [ -n "$class_name" ]; then
        CURRENT_CLASS="$class_name"
        log_success "Found class: $class_name"
        
        # Extract methods
        echo -e "${CYAN}Analysis Results:${NC}"
        echo -e "  ${WHITE}File:${NC} $(basename "$file")"
        echo -e "  ${WHITE}Class:${NC} $class_name"
        [ -n "$namespace" ] && echo -e "  ${WHITE}Namespace:${NC} $namespace"
        echo
        
        # Show methods
        echo -e "${WHITE}Methods found:${NC}"
        grep -E '^\s*(public|protected|private|function)\s+function\s+[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*' "$file" | \
            sed -E 's/.*function\s+//' | \
            sed 's/(.*//' | \
            while read method; do
                echo -e "  ${GRAY}• $method()${NC}"
            done || echo "  ${GRAY}No methods found${NC}"
    else
        log_warn "No class found in file"
        CURRENT_CLASS=""
    fi
    
    return 0
}

###############################################################################
# MENU FLOWS
###############################################################################

main_menu() {
    while true; do
        print_header
        
        local menu_items=(
            "Select PHP File"
            "Analyze Current File"
            "Debug Methods"
            "Run Tests"
            "Generate Test Cases"
            "Project Settings"
            "Help"
            "Exit"
        )
        
        print_menu "MAIN MENU" "${menu_items[@]}"
        
        if [ -n "$CURRENT_FILE" ]; then
            echo -e "${WHITE}Current file:${NC} ${GREEN}$(basename "$CURRENT_FILE")${NC}"
            [ -n "$CURRENT_CLASS" ] && echo -e "${WHITE}Current class:${NC} ${CYAN}$CURRENT_CLASS${NC}"
            echo
        fi
        
        local choice
        choice=$(get_choice "Select option" "${#menu_items[@]}")
        
        case "$choice" in
            1) file_menu ;;
            2) analyze_current_file ;;
            3) debug_methods_menu ;;
            4) run_tests_menu ;;
            5) generate_tests_menu ;;
            6) settings_menu ;;
            7) help_menu ;;
            8|"quit") exit 0 ;;
            "back") continue ;;
        esac
        
        pause
    done
}

file_menu() {
    print_header
    echo -e "${CYAN}SELECT PHP FILE${NC}"
    echo -e "${BLUE}══════════════════════════════════════════════════════════════${NC}"
    echo
    
    log_info "Scanning for PHP files..."
    
    local files=()
    while IFS= read -r file; do
        [ -n "$file" ] && files+=("$file")
    done < <(scan_php_files)
    
    if [ ${#files[@]} -eq 0 ]; then
        echo -e "${YELLOW}No PHP files found in current directory!${NC}"
        echo
        echo -e "Please navigate to a PHP project directory."
        pause
        return
    fi
    
    # Limit display
    local display_count=20
    [ ${#files[@]} -lt 20 ] && display_count=${#files[@]}
    
    echo -e "${GREEN}Found ${#files[@]} PHP files (showing first $display_count):${NC}"
    echo
    
    for i in $(seq 0 $((display_count - 1))); do
        local num=$((i + 1))
        local file="${files[$i]}"
        local relative_path="${file#$(pwd)/}"
        echo -e "  ${GREEN}$num)${NC} ${WHITE}$(basename "$file")${NC}"
        echo -e "      ${GRAY}$relative_path${NC}"
    done
    
    [ ${#files[@]} -gt 20 ] && echo -e "  ${GRAY}... and $(( ${#files[@]} - 20 )) more${NC}"
    
    echo
    local choice
    choice=$(get_choice "Select file" "$display_count")
    
    case "$choice" in
        [1-9]*) 
            local idx=$((choice - 1))
            if [ "$idx" -lt "${#files[@]}" ]; then
                CURRENT_FILE="${files[$idx]}"
                log_success "Selected: $(basename "$CURRENT_FILE")"
            fi
            ;;
        "back") return ;;
        "quit") exit 0 ;;
    esac
}

analyze_current_file() {
    if [ -z "$CURRENT_FILE" ]; then
        echo -e "${YELLOW}No file selected! Please select a file first.${NC}"
        return
    fi
    
    print_header
    echo -e "${CYAN}ANALYZING FILE${NC}"
    echo -e "${BLUE}══════════════════════════════════════════════════════════════${NC}"
    echo
    
    analyze_file "$CURRENT_FILE"
}

debug_methods_menu() {
    if [ -z "$CURRENT_FILE" ] || [ -z "$CURRENT_CLASS" ]; then
        echo -e "${YELLOW}No class selected! Please analyze a file first.${NC}"
        return
    fi
    
    print_header
    echo -e "${CYAN}DEBUG METHODS: $CURRENT_CLASS${NC}"
    echo -e "${BLUE}══════════════════════════════════════════════════════════════${NC}"
    echo
    
    echo -e "${YELLOW}Debug methods feature coming soon!${NC}"
    echo
    echo -e "For now, you can:"
    echo -e "  1. Use Xdebug with your IDE"
    echo -e "  2. Add var_dump() statements"
    echo -e "  3. Use error_log() for logging"
    echo
    echo -e "Future features:"
    echo -e "  • Method call with parameters"
    echo -e "  • Return value inspection"
    echo -e "  • Performance profiling"
}

run_tests_menu() {
    print_header
    echo -e "${CYAN}RUN TESTS${NC}"
    echo -e "${BLUE}══════════════════════════════════════════════════════════════${NC}"
    echo
    
    if [ -z "$CURRENT_CLASS" ]; then
        echo -e "${YELLOW}No class selected${NC}"
    else
        echo -e "${WHITE}Current class:${NC} ${CYAN}$CURRENT_CLASS${NC}"
        echo
    fi
    
    # Check for test files
    local test_file=""
    if [ -f "tests/${CURRENT_CLASS}Test.php" ]; then
        test_file="tests/${CURRENT_CLASS}Test.php"
    elif [ -f "tests/Unit/${CURRENT_CLASS}Test.php" ]; then
        test_file="tests/Unit/${CURRENT_CLASS}Test.php"
    fi
    
    if [ -n "$test_file" ]; then
        echo -e "${GREEN}Test file found:${NC} $test_file"
        echo
        echo -e "${WHITE}Run with:${NC}"
        echo -e "  ${GRAY}phpunit $test_file${NC}"
    else
        echo -e "${YELLOW}No test file found for $CURRENT_CLASS${NC}"
    fi
    
    echo
    echo -e "${WHITE}Available options:${NC}"
    local menu_items=(
        "Run PHPUnit tests"
        "Check test coverage"
        "Run all tests"
        "Back to main menu"
    )
    
    for i in "${!menu_items[@]}"; do
        echo -e "  ${GREEN}$((i+1)))${NC} ${WHITE}${menu_items[$i]}${NC}"
    done
    
    echo
    local choice
    choice=$(get_choice "Select option" "${#menu_items[@]}")
    
    case "$choice" in
        1)
            if [ -n "$test_file" ] && [ -f "vendor/bin/phpunit" ]; then
                echo
                echo -e "${CYAN}Running tests...${NC}"
                vendor/bin/phpunit "$test_file"
            else
                echo -e "${RED}Cannot run tests${NC}"
            fi
            ;;
        4|"back") return ;;
        "quit") exit 0 ;;
    esac
}

generate_tests_menu() {
    if [ -z "$CURRENT_CLASS" ]; then
        echo -e "${YELLOW}No class selected! Please analyze a file first.${NC}"
        return
    fi
    
    print_header
    echo -e "${CYAN}GENERATE TEST CASES: $CURRENT_CLASS${NC}"
    echo -e "${BLUE}══════════════════════════════════════════════════════════════${NC}"
    echo
    
    # Simple test template
    local test_template="<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ${CURRENT_CLASS}Test extends TestCase
{
    private \$${CURRENT_CLASS,};
    
    protected function setUp(): void
    {
        \$this->${CURRENT_CLASS,} = new ${CURRENT_CLASS}();
    }
    
    public function testInstanceCanBeCreated(): void
    {
        \$this->assertInstanceOf(
            ${CURRENT_CLASS}::class,
            \$this->${CURRENT_CLASS,}
        );
    }
    
    public function testMethodReturnsExpectedValue(): void
    {
        // TODO: Implement actual test
        \$this->markTestIncomplete('Test not implemented yet');
    }
}
"
    
    echo -e "${GREEN}Generated test template:${NC}"
    echo -e "${GRAY}$test_template${NC}"
    echo
    
    local menu_items=(
        "Save to tests/${CURRENT_CLASS}Test.php"
        "Copy to clipboard"
        "Show again"
        "Back"
    )
    
    for i in "${!menu_items[@]}"; do
        echo -e "  ${GREEN}$((i+1)))${NC} ${WHITE}${menu_items[$i]}${NC}"
    done
    
    echo
    local choice
    choice=$(get_choice "Select option" "${#menu_items[@]}")
    
    case "$choice" in
        1)
            local test_dir="tests"
            [ ! -d "$test_dir" ] && mkdir -p "$test_dir"
            
            echo "$test_template" > "$test_dir/${CURRENT_CLASS}Test.php"
            echo -e "${GREEN}Test file saved to: $test_dir/${CURRENT_CLASS}Test.php${NC}"
            ;;
        2)
            if command -v pbcopy >/dev/null 2>&1; then
                echo "$test_template" | pbcopy
                echo -e "${GREEN}Copied to clipboard (macOS)${NC}"
            elif command -v xclip >/dev/null 2>&1; then
                echo "$test_template" | xclip -selection clipboard
                echo -e "${GREEN}Copied to clipboard (Linux)${NC}"
            else
                echo -e "${YELLOW}Clipboard utility not found${NC}"
            fi
            ;;
        4|"back") return ;;
        "quit") exit 0 ;;
    esac
}

settings_menu() {
    print_header
    echo -e "${CYAN}SETTINGS${NC}"
    echo -e "${BLUE}══════════════════════════════════════════════════════════════${NC}"
    echo
    
    echo -e "${WHITE}Current Configuration:${NC}"
    echo -e "  • Project root: ${PROJECT_ROOT:-Not set}"
    echo -e "  • Project type: ${PROJECT_TYPE:-Not detected}"
    echo -e "  • PHP version: ${PHP_VERSION:-Unknown}"
    echo -e "  • Cache directory: ${CACHE_DIR:-Not set}"
    echo -e "  • Debug mode: ${DEBUG_MODE:-false}"
    echo -e "  • Current file: ${CURRENT_FILE:-None}"
    echo -e "  • Current class: ${CURRENT_CLASS:-None}"
    
    echo
    echo -e "${WHITE}Options:${NC}"
    local menu_items=(
        "Toggle debug mode (currently: $DEBUG_MODE)"
        "Change project directory"
        "Clear cache"
        "View logs"
        "Back"
    )
    
    for i in "${!menu_items[@]}"; do
        echo -e "  ${GREEN}$((i+1)))${NC} ${WHITE}${menu_items[$i]}${NC}"
    done
    
    echo
    local choice
    choice=$(get_choice "Select option" "${#menu_items[@]}")
    
    case "$choice" in
        1)
            if [ "$DEBUG_MODE" = true ]; then
                DEBUG_MODE=false
                echo -e "${GREEN}Debug mode disabled${NC}"
            else
                DEBUG_MODE=true
                echo -e "${GREEN}Debug mode enabled${NC}"
            fi
            ;;
        3)
            if [ -d "$CACHE_DIR" ]; then
                rm -rf "$CACHE_DIR"
                echo -e "${GREEN}Cache cleared${NC}"
                # Reinitialize cache
                init_cache
            fi
            ;;
        4)
            if [ -f "$LOG_FILE" ]; then
                echo
                echo -e "${CYAN}LOG FILE ($LOG_FILE):${NC}"
                echo -e "${BLUE}══════════════════════════════════════════════════════════════${NC}"
                echo
                tail -20 "$LOG_FILE" | while read line; do
                    echo -e "${GRAY}$line${NC}"
                done
            else
                echo -e "${YELLOW}No log file found${NC}"
            fi
            ;;
        5|"back") return ;;
        "quit") exit 0 ;;
    esac
}

help_menu() {
    print_header
    echo -e "${CYAN}HELP & DOCUMENTATION${NC}"
    echo -e "${BLUE}══════════════════════════════════════════════════════════════${NC}"
    echo
    
    echo -e "${WHITE}debug_low.sh - PHP Debugging Assistant${NC}"
    echo
    echo -e "${WHITE}Purpose:${NC}"
    echo -e "  Interactive tool for analyzing and debugging PHP code during development."
    echo
    echo -e "${WHITE}Basic Usage:${NC}"
    echo -e "  1. Navigate to your PHP project directory"
    echo -e "  2. Run: ./debug_low.sh"
    echo -e "  3. Select a PHP file to analyze"
    echo -e "  4. View class structure and methods"
    echo -e "  5. Generate test cases"
    echo
    echo -e "${WHITE}Features:${NC}"
    echo -e "  • PHP file scanning and selection"
    echo -e "  • Class and method analysis"
    echo -e "  • Test case generation"
    echo -e "  • Basic project information"
    echo
    echo -e "${WHITE}Navigation:${NC}"
    echo -e "  • Use numbers to select menu items"
    echo -e "  • 'b' to go back"
    echo -e "  • 'q' to quit"
    echo -e "  • Press any key when prompted"
    echo
    echo -e "${WHITE}Requirements:${NC}"
    echo -e "  • PHP 7.0 or higher"
    echo -e "  • Bash shell"
    echo -e "  • Read/write permissions in project directory"
    echo
    echo -e "${WHITE}Logs:${NC}"
    echo -e "  Log file: ${LOG_FILE:-Not set}"
    echo
    echo -e "${GRAY}Note: This is a development tool.${NC}"
    echo -e "${GRAY}For production debugging, use Xdebug with your IDE.${NC}"
}

###############################################################################
# MAIN EXECUTION
###############################################################################

main() {
    echo "=== STARTING MAIN ===" >&2
    
    # Initialize cache first
    if ! init_cache; then
        echo -e "${RED}Failed to initialize cache. Exiting.${NC}" >&2
        exit 1
    fi
    
    # Log startup
    log_info "Starting debug_low.sh v2.0.2"
    log_info "Working directory: $(pwd)"
    
    # Check dependencies
    if ! check_dependencies; then
        log_error "Dependency check failed"
        exit 1
    fi
    
    # Detect project
    detect_project
    
    # Show welcome message
    print_header
    echo -e "${GREEN}✓ PHP Debugging Assistant ready!${NC}"
    echo
    echo -e "${WHITE}Project:${NC} $PROJECT_TYPE"
    echo -e "${WHITE}PHP:${NC} $PHP_VERSION"
    echo -e "${WHITE}Directory:${NC} $(pwd)"
    echo
    echo -e "${GRAY}Press Enter to continue...${NC}"
    read -r
    
    # Start main menu
    main_menu
}

# Trap Ctrl+C for clean exit
trap 'echo -e "\n${YELLOW}Exiting debug_low.sh...${NC}"; exit 0' INT

# Run main function
echo "=== CALLING MAIN FUNCTION ===" >&2
main "$@"
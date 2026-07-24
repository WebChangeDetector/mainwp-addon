#!/bin/bash

################################################################################
# WebChange Detector for MainWP - Release Build Script
#
# Builds a clean, distributable copy of the plugin (analogous to the WP plugin
# deployment script in wcd-plugin/scripts/deploy-to-wp-svn.sh):
#
#   1. Validates version consistency across the plugin header, the readme.txt
#      stable tag and the latest changelog entry (the version is derived from
#      the header at runtime, so there is no separate version constant).
#   2. Validates the Git working directory.
#   3. Syncs a clean copy (excludes from .distignore + junk files) to
#      ../wp-repo-mainwp/trunk/ (same layout as wp-repo-plugin, SVN-ready).
#   4. Optionally creates a zip for manual upload (asks during the process).
#   5. Optionally creates a Git tag vX.Y.Z (asks during the process).
#   6. Optionally deploys to the WordPress.org SVN repository (asks during the
#      process): checks the repo out in place as wp-repo-mainwp if needed,
#      stages adds/deletes, copies trunk to tags/X.Y.Z and commits.
#
# Usage:
#   ./scripts/build-release.sh [--dry-run] [--force] [--svn-user <name>]
#
# Options:
#   --dry-run         Run validation and show what would be built without building
#   --force           Skip confirmation prompts (use with caution)
#   --svn-user <name> WordPress.org SVN username for the deploy commit
#                     (otherwise prompted; under --force, cached creds are used)
#
# Author: Mike Miler
# Project: Web Change Detector
################################################################################

set -Ee  # Exit on error; -E so the ERR trap fires inside functions too

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
PLUGIN_SLUG="webchangedetector-for-mainwp"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# Plugin lives at <wcd-root>/mainwp/app/public/wp-content/plugins/<slug>
WCD_ROOT="$(cd "$PLUGIN_DIR/../../../../../.." && pwd)"
REPO_DIR="${WCD_ROOT}/wp-repo-mainwp"
TRUNK_DIR="${REPO_DIR}/trunk"
SVN_URL="https://plugins.svn.wordpress.org/${PLUGIN_SLUG}/"
MAIN_PLUGIN_FILE="${PLUGIN_DIR}/${PLUGIN_SLUG}.php"
README_FILE="${PLUGIN_DIR}/readme.txt"
DISTIGNORE_FILE="${PLUGIN_DIR}/.distignore"

# Junk patterns that must never ship, regardless of .distignore.
# .svn is protected so a future SVN checkout of wp-repo-mainwp keeps working.
JUNK_PATTERNS=(".DS_Store" "._*" "Thumbs.db" "*.log" ".git" ".svn")

# Parse command line arguments
DRY_RUN=false
FORCE=false
SVN_USER=""

while [ $# -gt 0 ]; do
    case $1 in
        --dry-run)
            DRY_RUN=true
            ;;
        --force)
            FORCE=true
            ;;
        --svn-user)
            shift
            if [ -z "${1:-}" ]; then
                echo -e "${RED}--svn-user requires a username${NC}"
                exit 1
            fi
            SVN_USER="$1"
            ;;
        --svn-user=*)
            SVN_USER="${1#*=}"
            ;;
        --help)
            echo "Usage: $0 [--dry-run] [--force] [--svn-user <name>]"
            echo ""
            echo "Options:"
            echo "  --dry-run          Run validation and show what would be built without building"
            echo "  --force            Skip confirmation prompts (use with caution)"
            echo "  --svn-user <name>  WordPress.org SVN username for the deploy commit"
            echo "  --help             Show this help message"
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
    shift
done

################################################################################
# Helper Functions
################################################################################

# Errors and warnings go to stderr so they are never captured by $(...)
# in the version getters and always reach the terminal.
print_error() {
    echo -e "${RED}ERROR: $1${NC}" >&2
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}" >&2
}

print_info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

print_header() {
    echo -e "\n${BLUE}================================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}================================================${NC}\n"
}

exit_error() {
    print_error "$1"
    exit 1
}

# Confirm action or cancel the whole run (unless --force is used)
confirm() {
    if [ "$FORCE" = true ]; then
        return 0
    fi

    read -p "$(echo -e "${YELLOW}$1 [y/N]: ${NC}")" -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        print_warning "Operation cancelled by user"
        exit 0
    fi
}

# Ask a yes/no question for an optional step. Returns 0 (yes) or 1 (no)
# without cancelling the run. --force answers yes.
ask_yes_no() {
    if [ "$FORCE" = true ]; then
        return 0
    fi

    read -p "$(echo -e "${YELLOW}$1 [y/N]: ${NC}")" -n 1 -r
    echo
    [[ $REPLY =~ ^[Yy]$ ]]
}

################################################################################
# Validation Functions
################################################################################

validate_file_structure() {
    print_header "Validating File Structure"

    if [ ! -f "$MAIN_PLUGIN_FILE" ]; then
        exit_error "Main plugin file not found: $MAIN_PLUGIN_FILE"
    fi
    print_success "Main plugin file found"

    if [ ! -f "$README_FILE" ]; then
        exit_error "readme.txt not found: $README_FILE"
    fi
    print_success "readme.txt found"

    if [ ! -f "$DISTIGNORE_FILE" ]; then
        exit_error ".distignore not found: $DISTIGNORE_FILE"
    fi
    print_success ".distignore found"
}

# Create the deployment directory (same layout as wp-repo-plugin) if missing
ensure_repo_dir() {
    print_header "Checking Deployment Directory"

    if [ -d "$REPO_DIR" ]; then
        print_success "Deployment directory found: $REPO_DIR"
        return 0
    fi

    print_warning "Deployment directory does not exist yet: $REPO_DIR"
    if [ "$DRY_RUN" = true ]; then
        print_info "[DRY RUN] Would create: trunk/, tags/, assets/, branches/"
        return 0
    fi

    mkdir -p "$REPO_DIR/trunk" "$REPO_DIR/tags" "$REPO_DIR/assets" "$REPO_DIR/branches"
    print_success "Created $REPO_DIR with trunk/, tags/, assets/, branches/"
}

# Extract version from the plugin file header
get_plugin_version() {
    local version=$(grep -i "^ \* Version:" "$MAIN_PLUGIN_FILE" | awk '{print $3}' | tr -d '\r' | tr -d ' ')

    if [ -z "$version" ]; then
        exit_error "Could not extract version from $MAIN_PLUGIN_FILE"
    fi

    if ! [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        exit_error "Invalid version format in plugin file: '$version' (expected X.Y.Z)"
    fi

    echo "$version"
}

# Extract stable tag from readme.txt
get_readme_stable_tag() {
    local version=$(grep -i "^Stable tag:" "$README_FILE" | awk '{print $3}' | tr -d '\r' | tr -d ' ')

    if [ -z "$version" ]; then
        exit_error "Could not extract stable tag from $README_FILE"
    fi

    if ! [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        exit_error "Invalid version format in readme.txt stable tag: '$version' (expected X.Y.Z)"
    fi

    echo "$version"
}

# Extract latest changelog version from readme.txt
get_readme_changelog_version() {
    local version
    version=$(awk '
        /^== Changelog ==/ { in_changelog = 1; next }
        in_changelog && /^= [0-9]+\.[0-9]+\.[0-9]+ =/ {
            gsub(/^= /, "")
            gsub(/ =$/, "")
            print
            exit
        }
    ' "$README_FILE" | tr -d '\r')

    if [ -z "$version" ]; then
        exit_error "Could not extract changelog version from $README_FILE"
    fi

    if ! [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        exit_error "Invalid version format in changelog: '$version' (expected X.Y.Z)"
    fi

    echo "$version"
}

# Validate version consistency across the plugin header, the readme.txt stable
# tag and the latest changelog entry. The version is derived from the header at
# runtime (no separate WCD_MAINWP_VERSION literal), so there is no constant to
# cross-check here.
validate_versions() {
    print_header "Validating Version Consistency"

    local plugin_version=$(get_plugin_version)
    local readme_stable=$(get_readme_stable_tag)
    local changelog_version=$(get_readme_changelog_version)

    print_info "Plugin file version:       $plugin_version"
    print_info "readme.txt stable tag:     $readme_stable"
    print_info "Changelog version:         $changelog_version"

    local versions_match=true

    if [ "$plugin_version" != "$readme_stable" ]; then
        print_error "Version mismatch: Plugin file ($plugin_version) != readme.txt stable tag ($readme_stable)"
        versions_match=false
    fi

    if [ "$plugin_version" != "$changelog_version" ]; then
        print_error "Version mismatch: Plugin file ($plugin_version) != Changelog ($changelog_version)"
        versions_match=false
    fi

    if [ "$versions_match" = false ]; then
        exit_error "Version inconsistency detected. Please fix version numbers before building."
    fi

    print_success "All versions are consistent: $plugin_version"

    export BUILD_VERSION="$plugin_version"
}

# Validate Git repository status (the plugin directory is the Git repo)
validate_git_status() {
    print_header "Validating Git Repository Status"

    if ! command -v git &> /dev/null; then
        print_warning "Git command not found. Skipping Git checks and tagging."
        export SKIP_GIT=true
        return 0
    fi

    if [ ! -d "$PLUGIN_DIR/.git" ]; then
        print_warning "Plugin directory is not a Git repository. Skipping Git checks and tagging."
        export SKIP_GIT=true
        return 0
    fi

    export SKIP_GIT=false
    cd "$PLUGIN_DIR"

    # Check for uncommitted changes (both tracked and untracked)
    local has_changes=false
    if ! git diff-index --quiet HEAD -- 2>/dev/null; then
        has_changes=true
    fi
    if [ -n "$(git ls-files --others --exclude-standard)" ]; then
        has_changes=true
    fi

    if [ "$has_changes" = true ]; then
        print_warning "Git repository has uncommitted or untracked changes:"
        git status --short
        confirm "Continue with uncommitted changes? (Consider committing first)"
    else
        print_success "Git working directory is clean"
    fi

    local current_branch=$(git branch --show-current 2>/dev/null || echo "detached")
    print_info "Current Git branch: $current_branch"

    if [[ "$current_branch" != "main" && "$current_branch" != "master" ]]; then
        print_warning "You are not on 'main' or 'master' branch"
        confirm "Continue building from branch '$current_branch'?"
    fi

    if git rev-parse "v$BUILD_VERSION" >/dev/null 2>&1; then
        print_warning "Git tag v$BUILD_VERSION already exists locally"
        export GIT_TAG_EXISTS=true
    else
        print_success "Git tag v$BUILD_VERSION does not exist yet"
        export GIT_TAG_EXISTS=false
    fi

    if git remote -v | grep -q "origin"; then
        print_success "Git remote 'origin' found"
        export HAS_GIT_REMOTE=true
    else
        print_info "No Git remote 'origin' configured (tag would stay local only)"
        export HAS_GIT_REMOTE=false
    fi

    cd - > /dev/null
}

################################################################################
# Build Functions
################################################################################

# Read exclude patterns from .distignore (skip comments and blank lines)
# and combine them with the hardwired junk patterns.
# Note: rsync excludes are unanchored, so a name like "vendor" matches at any
# depth. If a nested dir of that name should ever ship (e.g. a runtime
# includes/vendor/), anchor the .distignore entry as "/vendor".
build_exclude_patterns() {
    EXCLUDE_PATTERNS=()

    # "|| [ -n "$line" ]" keeps a last line without trailing newline.
    while IFS= read -r line || [ -n "$line" ]; do
        line="$(echo "$line" | tr -d '\r' | sed 's/[[:space:]]*$//')"
        [ -z "$line" ] && continue
        [[ "$line" == \#* ]] && continue
        # Defense in depth: never accept absolute or parent-traversal
        # patterns, they end up in "rm -rf" inside trunk.
        if [[ "$line" == /* || "$line" == *..* ]]; then
            print_warning "Ignoring unsafe .distignore pattern: $line"
            continue
        fi
        EXCLUDE_PATTERNS+=("$line")
    done < "$DISTIGNORE_FILE"

    for pattern in "${JUNK_PATTERNS[@]}"; do
        EXCLUDE_PATTERNS+=("$pattern")
    done
}

# Sync a clean copy of the plugin to trunk
sync_to_trunk() {
    print_header "Syncing Clean Copy to Trunk"

    build_exclude_patterns

    # Build rsync arguments array (safer than eval with string)
    local rsync_args=(-a --delete)
    for pattern in "${EXCLUDE_PATTERNS[@]}"; do
        rsync_args+=(--exclude="$pattern")
    done

    if [ "$DRY_RUN" = true ]; then
        print_info "[DRY RUN] Would sync $PLUGIN_DIR/ to $TRUNK_DIR/ with excludes:"
        for pattern in "${EXCLUDE_PATTERNS[@]}"; do
            echo "  - Exclude: $pattern"
        done
        return 0
    fi

    # rsync --delete does not remove already-existing excluded files from the
    # destination, so clean them up manually first: literal names at trunk
    # top level, plus junk files (.DS_Store etc.) at any depth, sparing .svn.
    if [ -d "$TRUNK_DIR" ]; then
        cd "$TRUNK_DIR"
        for pattern in "${EXCLUDE_PATTERNS[@]}"; do
            if [ "$pattern" != ".svn" ] && [ -e "$pattern" ]; then
                print_info "Removing stale excluded file from trunk: $pattern"
                rm -rf "$pattern"
            fi
        done
        cd - > /dev/null

        # No "find -delete" here: it implies -depth, which disables the
        # -prune protection for a future .svn checkout.
        find "$TRUNK_DIR" -path "*/.svn" -prune -o \
            \( -name ".DS_Store" -o -name "._*" -o -name "Thumbs.db" -o -name "*.log" \) \
            -type f -print | while IFS= read -r junk; do
                print_info "Removing stale junk file from trunk: $junk"
                rm -f "$junk"
            done
    fi

    print_info "Syncing files to trunk..."
    if rsync "${rsync_args[@]}" "$PLUGIN_DIR/" "$TRUNK_DIR/"; then
        print_success "Files synced to $TRUNK_DIR"
    else
        exit_error "Failed to sync files to trunk"
    fi
}

# Verify no development or junk files made it into trunk
verify_trunk() {
    print_header "Verifying Trunk Contents"

    if [ "$DRY_RUN" = true ]; then
        print_info "[DRY RUN] Would verify trunk contains no dev/junk files"
        return 0
    fi

    # Derive the check from the same exclude list used for the sync, so the
    # safety net can never drift out of sync with .distignore.
    build_exclude_patterns
    local find_args=()
    local pattern
    for pattern in "${EXCLUDE_PATTERNS[@]}"; do
        [ "$pattern" = ".svn" ] && continue
        if [ ${#find_args[@]} -gt 0 ]; then
            find_args+=(-o)
        fi
        find_args+=(-name "$pattern")
    done

    local leftovers
    leftovers=$(find "$TRUNK_DIR" \( "${find_args[@]}" \) 2>/dev/null)

    if [ -n "$leftovers" ]; then
        print_error "Development/junk files found in trunk:"
        echo "$leftovers"
        exit_error "Trunk is not clean. Check .distignore and the exclude patterns."
    fi
    print_success "Trunk contains no development or junk files"

    local file_count=$(find "$TRUNK_DIR" -type f | wc -l | tr -d ' ')
    local total_size=$(du -sh "$TRUNK_DIR" | awk '{print $1}')
    print_info "Trunk contains $file_count files ($total_size)"
}

# Optionally create the zip for manual upload
create_zip() {
    print_header "Creating Release Zip"

    if [ "$DRY_RUN" = true ]; then
        print_info "[DRY RUN] Would offer to create: $REPO_DIR/${PLUGIN_SLUG}-${BUILD_VERSION}.zip"
        return 0
    fi

    if ! ask_yes_no "Create zip file ${PLUGIN_SLUG}-${BUILD_VERSION}.zip?"; then
        print_info "Skipping zip creation"
        export ZIP_FILE=""
        return 0
    fi

    local zip_file="$REPO_DIR/${PLUGIN_SLUG}-${BUILD_VERSION}.zip"

    if [ -f "$zip_file" ]; then
        print_warning "Zip file already exists: $zip_file"
        if ! ask_yes_no "Overwrite existing zip file?"; then
            print_info "Skipping zip creation"
            export ZIP_FILE=""
            return 0
        fi
        rm -f "$zip_file"
    fi

    # Stage trunk under the plugin slug so the zip contains the
    # WordPress-conventional top-level folder (slug, no version suffix).
    local staging_dir
    staging_dir=$(mktemp -d)
    mkdir -p "$staging_dir/$PLUGIN_SLUG"
    if ! rsync -a --exclude=".svn" "$TRUNK_DIR/" "$staging_dir/$PLUGIN_SLUG/"; then
        rm -rf "$staging_dir"
        exit_error "Failed to stage files for zip"
    fi

    print_info "Creating zip file..."
    if (cd "$staging_dir" && zip -rq "$zip_file" "$PLUGIN_SLUG"); then
        print_success "Zip created: $zip_file"
        export ZIP_FILE="$zip_file"
    else
        rm -rf "$staging_dir"
        exit_error "Failed to create zip file"
    fi

    rm -rf "$staging_dir"
}

################################################################################
# Git Tag Functions
################################################################################

# Get changelog entry for the current version from readme.txt
get_changelog_entry() {
    awk -v version="$BUILD_VERSION" '
        /^= [0-9]+\.[0-9]+\.[0-9]+ =/ {
            ver = $0
            gsub(/[= ]/, "", ver)
            if (ver == version) {
                found = 1
                next
            }
        }
        found {
            if (/^= [0-9]+\.[0-9]+\.[0-9]+ =/) {
                exit
            }
            if (NF > 0) {
                print $0
            }
        }
    ' "$README_FILE"
}

# Optionally create (and push) the Git tag
create_git_tag() {
    print_header "Creating Git Tag: v$BUILD_VERSION"

    if [ "$SKIP_GIT" = true ]; then
        print_info "Skipping Git tagging (not available)"
        return 0
    fi

    if [ "$DRY_RUN" = true ]; then
        print_info "[DRY RUN] Would offer to create Git tag: v$BUILD_VERSION"
        return 0
    fi

    if ! ask_yes_no "Create git tag v$BUILD_VERSION?"; then
        print_info "Skipping Git tag creation"
        export TAG_CREATED=false
        return 0
    fi

    cd "$PLUGIN_DIR"

    if [ "$GIT_TAG_EXISTS" = true ]; then
        print_warning "Git tag v$BUILD_VERSION already exists locally"
        if ! ask_yes_no "Overwrite existing Git tag v$BUILD_VERSION?"; then
            print_info "Skipping Git tag creation"
            export TAG_CREATED=false
            cd - > /dev/null
            return 0
        fi
        git tag -d "v$BUILD_VERSION" 2>/dev/null || true
    fi

    local changelog_content=$(get_changelog_entry)
    local tag_message="Release version $BUILD_VERSION

Built on $(date '+%Y-%m-%d %H:%M:%S')
"

    if [ -n "$changelog_content" ]; then
        tag_message+="
Changes in this release:
$changelog_content"
    else
        print_warning "No changelog entry found for version $BUILD_VERSION"
        tag_message+="
No changelog entry available."
    fi

    print_info "Creating annotated Git tag..."
    if git tag -a "v$BUILD_VERSION" -m "$tag_message"; then
        print_success "Git tag v$BUILD_VERSION created"
        export TAG_CREATED=true
    else
        cd - > /dev/null
        exit_error "Failed to create Git tag v$BUILD_VERSION"
    fi

    if [ "$HAS_GIT_REMOTE" = true ]; then
        if ask_yes_no "Push Git tag v$BUILD_VERSION to origin?"; then
            if git push origin "v$BUILD_VERSION"; then
                print_success "Git tag pushed to origin"
            else
                print_error "Failed to push Git tag"
                print_info "You can manually push later with: git push origin v$BUILD_VERSION"
            fi
        fi
    else
        print_info "No remote configured, tag created locally only"
    fi

    cd - > /dev/null
}

################################################################################
# WordPress.org SVN Functions
################################################################################

# Ensure wp-repo-mainwp is an SVN working copy. If .svn is missing, check the
# repo out in place with --force: the wp.org repo already contains the
# trunk/tags/branches/assets directories, so --force lets svn adopt the existing
# (already-built) local dirs instead of tree-conflicting on them. The built
# files inside trunk then show as unversioned and are added by svn_deploy.
ensure_svn_checkout() {
    if [ -d "$REPO_DIR/.svn" ]; then
        print_success "SVN working copy found: $REPO_DIR"
        return 0
    fi

    print_info "No SVN working copy yet. Checking out $SVN_URL in place..."
    if [ "$DRY_RUN" = true ]; then
        print_info "[DRY RUN] Would run: svn checkout --force ${SVN_AUTH[*]} \"$SVN_URL\" \"$REPO_DIR\""
        return 0
    fi

    if svn checkout --force "${SVN_AUTH[@]}" "$SVN_URL" "$REPO_DIR"; then
        print_success "SVN repository checked out into $REPO_DIR"
    else
        exit_error "Failed to check out SVN repository from $SVN_URL"
    fi
}

# Optionally deploy the built trunk to the WordPress.org SVN repository.
# Runs LAST, after sync_to_trunk has already populated trunk/. Opt-in: the
# local build behaves exactly as before when this is declined.
svn_deploy() {
    print_header "Deploying to WordPress.org SVN"

    # Resolve auth first so the dry-run preview shows the real command shape.
    SVN_AUTH=()
    if [ -n "$SVN_USER" ]; then
        SVN_AUTH=(--username "$SVN_USER")
    fi

    if [ "$DRY_RUN" = true ]; then
        print_info "[DRY RUN] Would offer to deploy version $BUILD_VERSION to $SVN_URL"
        print_info "[DRY RUN] Would ensure SVN checkout (svn checkout --force), then run:"
        print_info "  svn update ${SVN_AUTH[*]}"
        print_info "  svn status trunk | grep '^?' | svn add  (new files in trunk)"
        print_info "  svn status trunk | grep '^!' | svn delete  (removed files in trunk)"
        print_info "  svn copy trunk tags/$BUILD_VERSION  (if not present)"
        print_info "  svn commit ${SVN_AUTH[*]} -m \"Deploying version $BUILD_VERSION\""
        return 0
    fi

    if ! ask_yes_no "Deploy version $BUILD_VERSION to WordPress.org SVN now?"; then
        print_info "Skipping WordPress.org SVN deploy"
        return 0
    fi

    if ! command -v svn &> /dev/null; then
        print_warning "svn command not found. Skipping WordPress.org SVN deploy."
        return 0
    fi

    # Prompt for the username unless one was supplied or --force is set
    # (under --force with no --svn-user we fall back to SVN's cached creds).
    if [ -z "$SVN_USER" ] && [ "$FORCE" != true ]; then
        read -p "$(echo -e "${YELLOW}WordPress.org SVN username (blank = use cached credentials): ${NC}")" -r SVN_USER
        if [ -n "$SVN_USER" ]; then
            SVN_AUTH=(--username "$SVN_USER")
        fi
    fi

    ensure_svn_checkout

    cd "$REPO_DIR"

    print_info "Updating SVN working copy..."
    svn update "${SVN_AUTH[@]}"

    # Stage new and removed files. Scope to trunk/ so root-level artifacts (the
    # release zip, the empty tags/branches/assets scaffold) are never committed;
    # only the distributable files inside trunk are. The {}@ peg-revision suffix
    # guards filenames containing an '@'. Errors are tolerated (nothing to do).
    print_info "Staging new files in trunk..."
    svn status trunk | grep "^?" | awk '{print $2}' | xargs -I {} svn add {}@ 2>/dev/null || true
    print_info "Staging removed files in trunk..."
    svn status trunk | grep "^!" | awk '{print $2}' | xargs -I {} svn delete {}@ 2>/dev/null || true

    # Tag from trunk (skip if the tag already exists so re-runs don't fail;
    # a trunk-only re-commit is still possible). svn copy schedules the tag add
    # itself, so it is not affected by the trunk-scoped status above.
    if [ -d "tags/$BUILD_VERSION" ]; then
        print_warning "SVN tag tags/$BUILD_VERSION already exists; skipping tag creation"
    else
        print_info "Creating tag from trunk: tags/$BUILD_VERSION"
        if ! svn copy trunk "tags/$BUILD_VERSION"; then
            cd - > /dev/null
            exit_error "Failed to create SVN tag tags/$BUILD_VERSION"
        fi
    fi

    print_info "Final SVN status:"
    svn status

    confirm "Commit version $BUILD_VERSION to WordPress.org?"

    print_info "Committing..."
    if svn commit "${SVN_AUTH[@]}" -m "Deploying version $BUILD_VERSION"; then
        print_success "Committed version $BUILD_VERSION to WordPress.org SVN"
        export SVN_DEPLOYED=true
    else
        cd - > /dev/null
        exit_error "SVN commit failed"
    fi

    cd - > /dev/null
}

################################################################################
# Pre-flight Checks
################################################################################

pre_flight_checks() {
    print_header "Pre-flight Checks"

    if ! command -v rsync &> /dev/null; then
        exit_error "rsync command not found. Please install rsync."
    fi
    print_success "rsync command found"

    if ! command -v zip &> /dev/null; then
        exit_error "zip command not found. Please install zip."
    fi
    print_success "zip command found"

    if [ ! -d "$PLUGIN_DIR" ]; then
        exit_error "Plugin directory not found: $PLUGIN_DIR"
    fi
    print_success "Plugin directory found"
}

################################################################################
# Main Execution
################################################################################

main() {
    print_header "WebChange Detector for MainWP - Release Build"

    if [ "$DRY_RUN" = true ]; then
        print_warning "DRY RUN MODE - No changes will be made"
    fi

    pre_flight_checks
    validate_file_structure
    validate_versions
    validate_git_status

    print_header "Build Summary"
    echo -e "Plugin:        ${GREEN}${PLUGIN_SLUG}${NC}"
    echo -e "Version:       ${GREEN}${BUILD_VERSION}${NC}"
    echo -e "Trunk:         ${BLUE}${TRUNK_DIR}${NC}"
    echo -e "SVN repo:      ${BLUE}${SVN_URL}${NC}"
    echo -e "Git Tagging:   ${YELLOW}$([ "$SKIP_GIT" = true ] && echo "Disabled" || echo "Enabled")${NC}"
    echo -e "Dry Run:       ${YELLOW}${DRY_RUN}${NC}"
    echo ""

    if [ "$DRY_RUN" = false ]; then
        confirm "Do you want to proceed with the build?"
    fi

    ensure_repo_dir
    sync_to_trunk
    verify_trunk
    create_zip
    create_git_tag
    svn_deploy

    if [ "$DRY_RUN" = false ]; then
        print_header "BUILD SUCCESSFUL!"
        print_success "Clean copy: $TRUNK_DIR"
        if [ -n "${ZIP_FILE:-}" ]; then
            print_success "Zip for upload: $ZIP_FILE"
        else
            print_info "No zip created"
        fi
        if [ "${TAG_CREATED:-false}" = true ]; then
            print_success "Git tag v$BUILD_VERSION created"
        fi
        if [ "${SVN_DEPLOYED:-false}" = true ]; then
            print_success "Deployed to WordPress.org: trunk + tags/$BUILD_VERSION"
            print_info "Live at: https://wordpress.org/plugins/${PLUGIN_SLUG}/"
        else
            print_info "Not deployed to WordPress.org SVN (upload the zip manually if needed)"
        fi
    else
        print_info "\nDry run complete. No changes were made."
        print_info "Run without --dry-run to perform the actual build."
    fi
}

# Set up error handling
trap 'print_error "Script failed on line $LINENO"' ERR

main

exit 0

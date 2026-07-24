#!/usr/bin/env bash
#
# wcd-mainwp-release.sh - cut a WebChange Detector for MainWP add-on release.
#
# Bumps the plugin version header (and, for stable releases only, the
# "Stable tag" in readme.txt), commits, tags and pushes. The GitHub Actions
# workflow .github/workflows/release.yml takes over from the tag push and
# builds/publishes the distribution zip (consumed by Git Updater on dev sites).
#
# This script NEVER touches the wordpress.org SVN checkout (wp-repo-mainwp/);
# that is the stable channel handled by scripts/build-release.sh.
#
# Usage: ./scripts/wcd-mainwp-release.sh                   (interactive menu)
#        ./scripts/wcd-mainwp-release.sh <version> [--dry-run] [--yes]
#        ./scripts/wcd-mainwp-release.sh --next    [--dry-run] [--yes]
#
#   <version>   e.g. 1.1.0 or 1.1.0-beta.1
#   --next      derive the version from the current "Version:" header by
#               incrementing its pre-release counter (1.1.0-beta.1 ->
#               1.1.0-beta.2); mutually exclusive with <version>
#   --dry-run   validate and report only; changes nothing
#   --yes       skip the interactive confirmation
#
# With no version and no --next the script asks what to release (next
# pre-release / final / custom). That menu needs a terminal, so it is skipped
# for --yes and for a non-tty stdin; those stay a usage error, because an
# unattended run must never have its version guessed for it.
#
# Portability note: this runs on macOS (BSD userland). No `grep -P`, and no
# in-place `sed -i` (BSD requires a backup suffix, GNU forbids an empty one),
# so rewrites go through a temp file.

# No `pipefail` on purpose: `<cmd> | head -n1` makes the left side die with
# SIGPIPE, which pipefail would turn into a spurious script abort.
set -eu

PLUGIN_FILE="webchangedetector-for-mainwp.php"
README_FILE="readme.txt"
VERSION_PATTERN='^[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)\.[0-9]+)?$'
# Used by --next to decide whether the CURRENT version can be incremented.
PRERELEASE_PATTERN='^.+-(alpha|beta|rc)\.[0-9]+$'
STABLE_VERSION_PATTERN='^[0-9]+\.[0-9]+\.[0-9]+$'
VERSION_LINE_RE='^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*[^[:space:]]'
STABLE_LINE_RE='^Stable tag:[[:space:]]*[^[:space:]]'

DRY_RUN=0
ASSUME_YES=0
USE_NEXT=0
INTERACTIVE=0
NEW_VERSION=""
DERIVED_NOTE=""

usage() {
	cat <<'USAGE'
Usage: ./scripts/wcd-mainwp-release.sh                   (interactive menu)
       ./scripts/wcd-mainwp-release.sh <version> [--dry-run] [--yes]
       ./scripts/wcd-mainwp-release.sh --next    [--dry-run] [--yes]

  (no args)   Ask what to release: next pre-release, final release, or a
              custom version. Needs a terminal; not available with --yes.
  <version>   Semantic version, optionally with a pre-release suffix.
              Examples: 1.1.0, 1.1.0-beta.1, 1.1.0-rc.2
  --next      Derive the version from the current 'Version:' header by
              incrementing its pre-release counter (1.1.0-beta.1 ->
              1.1.0-beta.2, 1.1.0-beta.9 -> 1.1.0-beta.10). The current
              version must itself be a pre-release; a stable one is rejected
              because the intended next version would be ambiguous.
              Cannot be combined with <version>.
  --dry-run   Validate and report only. Changes no files, no git state.
  --yes       Skip the interactive confirmation prompt.

Run it without arguments and pick from the menu. --next and an explicit
version are the non-interactive forms, for scripting and unattended runs.
USAGE
}

die() {
	echo "Error: $*" >&2
	exit 1
}

read_plugin_version() {
	sed -E -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' "$PLUGIN_FILE" | head -n1
}

read_stable_tag() {
	sed -E -n 's/^Stable tag:[[:space:]]*([^[:space:]]+).*/\1/p' "$README_FILE" | head -n1
}

# Increment the trailing pre-release counter, keeping base version and stage
# (1.1.0-beta.1 -> 1.1.0-beta.2, 1.2.0-rc.2 -> 1.2.0-rc.3). ${1%.*} is
# everything before the counter, ${1##*.} the counter itself; 10# forces base 10
# so a padded counter (beta.08) is not read as octal.
# The caller must have checked $1 against PRERELEASE_PATTERN.
derive_next_prerelease() {
	printf '%s.%s\n' "${1%.*}" "$(( 10#${1##*.} + 1 ))"
}

# Pre-release stage of a version: 1.1.0-beta.2 -> beta, 1.2.0-rc.1 -> rc.
# The caller must have checked $1 against PRERELEASE_PATTERN.
prerelease_stage() {
	local base="${1%.*}"
	printf '%s\n' "${base##*-}"
}

# --- Parse arguments -------------------------------------------------------

while [ "$#" -gt 0 ]; do
	case "$1" in
		--next)
			USE_NEXT=1
			;;
		--dry-run)
			DRY_RUN=1
			;;
		--yes|-y)
			ASSUME_YES=1
			;;
		-h|--help)
			usage
			exit 0
			;;
		-*)
			usage >&2
			die "Unknown option: $1"
			;;
		*)
			if [ -n "$NEW_VERSION" ]; then
				usage >&2
				die "Unexpected extra argument: $1"
			fi
			NEW_VERSION="$1"
			;;
	esac
	shift
done

# --next derives the version, so passing one as well is contradictory.
if [ "$USE_NEXT" -eq 1 ] && [ -n "$NEW_VERSION" ]; then
	usage >&2
	die "--next and an explicit version ('$NEW_VERSION') are mutually exclusive."
fi

# No version and no --next: ask interactively, but only when a human is
# actually there to answer. An unattended run (--yes, or stdin redirected from
# a file/pipe) must never have a version picked for it, so it stays a usage
# error with an explicit message.
if [ "$USE_NEXT" -eq 0 ] && [ -z "$NEW_VERSION" ]; then
	if [ "$ASSUME_YES" -eq 1 ]; then
		usage >&2
		die "--yes skips every prompt, so the release menu cannot run. Pass a version explicitly or use --next."
	fi
	if [ ! -t 0 ]; then
		usage >&2
		die "No version and no --next, and stdin is not a terminal. The release menu needs one. Pass a version explicitly or use --next."
	fi
	INTERACTIVE=1
fi

# --- Locate the plugin root ------------------------------------------------
#
# Self-locate from the script's own path (like scripts/build-release.sh) so the
# script runs from any CWD. The plugin root is also the git repo root, so the
# relative $PLUGIN_FILE/$README_FILE reads and every git command below keep
# working once we are there.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/.." || die "Could not cd to the plugin root."

# --- Preconditions ---------------------------------------------------------

[ -f "$PLUGIN_FILE" ] && [ -f "$README_FILE" ] \
	|| die "Run this script from the plugin root (where $PLUGIN_FILE lives)."

command -v git >/dev/null 2>&1 || die "git is required but not installed."
command -v php >/dev/null 2>&1 || die "php is required but not installed."

git rev-parse --is-inside-work-tree >/dev/null 2>&1 \
	|| die "Not inside a git working tree."

if [ -n "$(git status --porcelain)" ]; then
	die "Working tree is not clean. Commit or stash your changes first."
fi

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [ "$BRANCH" != "dev" ]; then
	echo "Warning: you are on branch '$BRANCH', not 'dev'."
fi

# --- Resolve the target version --------------------------------------------

CURRENT_VERSION="$(read_plugin_version)"
[ -n "$CURRENT_VERSION" ] || die "Could not read the 'Version:' header from $PLUGIN_FILE."

# Read early: the stable-release guard and the final-release warning both need
# it before the first file is written.
CURRENT_STABLE_TAG="$(read_stable_tag)"

if [ "$USE_NEXT" -eq 1 ]; then
	if printf '%s' "$CURRENT_VERSION" | grep -Eq "$PRERELEASE_PATTERN"; then
		NEW_VERSION="$(derive_next_prerelease "$CURRENT_VERSION")"
		DERIVED_NOTE=" (derived via --next)"
	elif printf '%s' "$CURRENT_VERSION" | grep -Eq "$STABLE_VERSION_PATTERN"; then
		# Deliberately no guess: 1.0.2 could mean 1.0.3-beta.1 or 1.1.0-beta.1.
		die "current version ${CURRENT_VERSION} is a stable release; --next cannot infer the next pre-release. Pass it explicitly, e.g. ./scripts/wcd-mainwp-release.sh 1.1.0-beta.1"
	else
		die "current version '${CURRENT_VERSION}' is not a recognised version; --next cannot derive from it. Pass it explicitly, e.g. ./scripts/wcd-mainwp-release.sh 1.1.0-beta.1"
	fi

	# Printed before the diff and the confirmation prompt, so no release can be
	# cut without the derived version having been on screen.
	echo
	echo "Next version: ${CURRENT_VERSION} -> ${NEW_VERSION}${DERIVED_NOTE}"
fi

# --- Interactive release-type menu -----------------------------------------
#
# Reached only for a bare invocation on a terminal. It decides WHAT to release
# and nothing else: every guard below (format, duplicate tag, ordering, stable
# tag handling) applies to the picked version exactly as it does to an
# explicitly passed one, and the release plan plus the y/N prompt still confirm
# the actual diff afterwards.

# Extra warning for a stable target version: this is the number customers see
# on wordpress.org, and it is the only case that moves 'Stable tag:'. Its gate
# (further below) is about WHAT is released and WHO is asked, never about how the
# version was chosen: "Custom version" and a version typed on the command line
# produce a stable release just as well as the "Final release" menu entry.
warn_final_release() {
	echo
	echo "Careful: ${1} is a FINAL release."
	echo "  It is the customer-facing version published on wordpress.org."
	echo "  'Stable tag:' in ${README_FILE} will be updated: ${CURRENT_STABLE_TAG} -> ${1}."
	echo "  It ends the pre-release cycle, so only continue when the betas are"
	echo "  tested and the changelog is final."
}

# Reads a version for the "Custom version" entry. Returns 0 with NEW_VERSION
# set, or 1 to go back to the menu (empty input). A malformed version is
# rejected right here and re-prompted, so a typo costs one line instead of the
# whole run. This is an early usability check only: the format guard below
# stays the single source of truth and still validates every version, including
# the ones passed on the command line.
prompt_custom_version() {
	local custom=""
	while true; do
		printf 'Version to release (empty to go back): '
		read -r custom || { echo; echo "Aborted. Nothing was changed."; exit 0; }
		[ -n "$custom" ] || return 1
		if printf '%s' "$custom" | grep -Eq "$VERSION_PATTERN"; then
			NEW_VERSION="$custom"
			DERIVED_NOTE=" (custom version)"
			return 0
		fi
		echo "Invalid version '${custom}'. Expected e.g. 1.1.0 or 1.1.0-beta.1."
	done
}

prompt_release_type() {
	local stage="" next_version="" final_version="" reason="" choice=""

	if printf '%s' "$CURRENT_VERSION" | grep -Eq "$PRERELEASE_PATTERN"; then
		stage="$(prerelease_stage "$CURRENT_VERSION")"
		next_version="$(derive_next_prerelease "$CURRENT_VERSION")"
		# Drop the pre-release suffix: 1.1.0-beta.2 -> 1.1.0.
		final_version="${CURRENT_VERSION%%-*}"
	elif printf '%s' "$CURRENT_VERSION" | grep -Eq "$STABLE_VERSION_PATTERN"; then
		reason=$'The current version is a stable release, so the next pre-release is\nambiguous (1.0.3-beta.1 or 1.1.0-beta.1?). Name the version yourself.'
	else
		reason=$'The current version is not a recognised version number, so no next\nversion can be derived from it. Name the version yourself.'
	fi

	echo
	echo "Current version: ${CURRENT_VERSION}"

	while true; do
		echo
		if [ -n "$reason" ]; then
			echo "$reason"
			echo
		fi
		echo "What do you want to release?"
		if [ -n "$stage" ]; then
			printf '  1) %-17s%s\n' "Next ${stage}" "$next_version"
			printf '  2) %-17s%s\n' "Final release" "$final_version"
			echo "  3) Custom version"
		else
			echo "  1) Custom version"
		fi
		echo "  q) Abort"
		echo
		printf 'Choice [1]: '

		choice=""
		# Ctrl-D (EOF) counts as abort, not as the default choice.
		read -r choice || { echo; echo "Aborted. Nothing was changed."; exit 0; }
		[ -n "$choice" ] || choice="1"

		case "$choice" in
			q|Q)
				echo "Aborted. Nothing was changed."
				exit 0
				;;
		esac

		if [ -n "$stage" ]; then
			case "$choice" in
				1)
					NEW_VERSION="$next_version"
					DERIVED_NOTE=" (next ${stage})"
					return 0
					;;
				2)
					NEW_VERSION="$final_version"
					DERIVED_NOTE=" (final release)"
					return 0
					;;
				3)
					if prompt_custom_version; then
						return 0
					fi
					;;
				*)
					echo "Please enter 1, 2, 3 or q."
					;;
			esac
		else
			case "$choice" in
				1)
					if prompt_custom_version; then
						return 0
					fi
					;;
				*)
					echo "Please enter 1 or q."
					;;
			esac
		fi
	done
}

if [ "$INTERACTIVE" -eq 1 ]; then
	prompt_release_type
fi

# --- Validate the requested version ----------------------------------------
#
# Everything below applies to the derived version exactly as it does to an
# explicitly passed one: format, duplicate tag (local and origin), ordering.

if ! printf '%s' "$NEW_VERSION" | grep -Eq "$VERSION_PATTERN"; then
	die "Invalid version '$NEW_VERSION'. Expected e.g. 1.1.0 or 1.1.0-beta.1."
fi

TAG="v${NEW_VERSION}"

if git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null; then
	die "Tag ${TAG} already exists locally."
fi

if [ -n "$(git ls-remote --tags origin "refs/tags/${TAG}" 2>/dev/null)" ]; then
	die "Tag ${TAG} already exists on origin."
fi

# Use PHP's own comparator so the ordering matches what WordPress will do.
if ! php -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$NEW_VERSION" "$CURRENT_VERSION"; then
	die "New version ${NEW_VERSION} is not greater than the current version ${CURRENT_VERSION}."
fi

# A pre-release suffix means "Stable tag" must keep pointing at the last stable.
IS_STABLE=1
case "$NEW_VERSION" in
	*-*) IS_STABLE=0 ;;
esac

# A stable release rewrites 'Stable tag:', so the line has to be readable BEFORE
# the first file write. Without this guard the plugin header is bumped first and
# the run then dies inside replace_first_match(), leaving a half-rewritten tree.
if [ "$IS_STABLE" -eq 1 ] && [ -z "$CURRENT_STABLE_TAG" ]; then
	die "No readable 'Stable tag:' line in ${README_FILE}, which a stable release has to update. Restore it (format: 'Stable tag: <last stable version>') and run this script again."
fi

# Warn on WHAT is being released and WHO is being asked, never on how the version
# was chosen: a custom stable version and one typed on the command line move
# 'Stable tag:' exactly like the "Final release" menu entry does. So the gate is
# "the target is stable AND this run is going to prompt a human anyway", which
# covers every menu route plus an explicit stable version without --yes.
# --yes and a non-tty stdin are excluded: that run reaches no prompt (with no tty
# the y/N read below gets EOF and reverts), so there is nobody to read a warning,
# and it still has the release plan. stdin is the tty tested, not stdout: it is
# the stream the prompts read from, and it is what the menu gate above checks.
if [ "$IS_STABLE" -eq 1 ] && [ "$ASSUME_YES" -ne 1 ] && [ -t 0 ]; then
	warn_final_release "$NEW_VERSION"
fi

echo
echo "Release plan"
echo "  Branch:          ${BRANCH}"
echo "  Current version: ${CURRENT_VERSION}"
echo "  New version:     ${NEW_VERSION}${DERIVED_NOTE}"
echo "  Tag:             ${TAG}"
if [ "$IS_STABLE" -eq 1 ]; then
	echo "  Stable tag:      ${CURRENT_STABLE_TAG} -> ${NEW_VERSION} (stable release)"
else
	echo "  Stable tag:      ${CURRENT_STABLE_TAG} (unchanged: pre-release)"
fi
echo

if [ "$DRY_RUN" -eq 1 ]; then
	echo "Dry run: nothing was changed."
	exit 0
fi

# --- Rewrite the version headers -------------------------------------------

replace_first_match() {
	# $1 = file, $2 = ERE identifying the line, $3 = sed -E substitution for that line.
	# The substitution is addressed by line number so only the FIRST match is
	# rewritten, even if a similar line appears later in the file.
	local file="$1" line_re="$2" expression="$3" line tmp
	line="$(grep -n -E "$line_re" "$file" | head -n1 | cut -d: -f1)"
	[ -n "$line" ] || die "No line matching /${line_re}/ in ${file}."
	# A template is mandatory for BSD mktemp, so pass one explicitly.
	tmp="$(mktemp "${file}.XXXXXX")"
	# Expand $tmp now, on purpose: a failing sed/cp must not leave the temp file
	# behind in the plugin root (where the dist build would pick it up).
	# shellcheck disable=SC2064
	trap "rm -f '$tmp'" EXIT
	sed -E "${line}${expression}" "$file" >"$tmp"
	# cp (not mv) so the original file permissions are preserved.
	cp "$tmp" "$file"
	rm -f "$tmp"
	trap - EXIT
}

replace_first_match "$PLUGIN_FILE" "$VERSION_LINE_RE" \
	"s|^([[:space:]]*\*[[:space:]]*Version:[[:space:]]*)[^[:space:]]+|\1${NEW_VERSION}|"

if [ "$IS_STABLE" -eq 1 ]; then
	replace_first_match "$README_FILE" "$STABLE_LINE_RE" \
		"s|^(Stable tag:[[:space:]]*)[^[:space:]]+|\1${NEW_VERSION}|"
fi

# Guard against a silently failed rewrite.
WRITTEN_VERSION="$(read_plugin_version)"
if [ "$WRITTEN_VERSION" != "$NEW_VERSION" ]; then
	git checkout -- "$PLUGIN_FILE" "$README_FILE"
	die "Version rewrite failed (header still reads '${WRITTEN_VERSION}'). Changes reverted."
fi

echo "--- git diff ---"
git --no-pager diff
echo

# --- Confirm ---------------------------------------------------------------

if [ "$ASSUME_YES" -ne 1 ]; then
	printf 'Commit, tag %s and push to origin/%s? [y/N] ' "$TAG" "$BRANCH"
	# Tolerate EOF (no tty): treat it as "no" instead of aborting via set -e
	# with the version rewrite still on disk.
	ANSWER=""
	read -r ANSWER || true
	case "$ANSWER" in
		y|Y|yes|YES) ;;
		*)
			git checkout -- "$PLUGIN_FILE" "$README_FILE"
			echo "Aborted. Changes reverted."
			exit 1
			;;
	esac
fi

# --- Commit, tag, push -----------------------------------------------------

# Recovery instructions for a half-finished release. Which state we are in
# decides what is safe to advise, so the state is always passed in explicitly:
#
#   commit-failed  headers rewritten and staged, no commit, no tag, nothing pushed
#   tag-failed     release commit exists, tag missing, nothing pushed
#   not-pushed     release commit and local tag exist, origin has neither
#   pushed         `git push` succeeded, so origin HAS the commit; only the tag
#                  is missing there (or the verification query itself failed)
#
# Re-running this script for the same version never works once the headers are
# rewritten: it aborts on the existing local tag, and deleting that tag makes it
# abort on the version guard instead, because the header already holds the new
# version. So every state spells out the manual way forward.
#
# Only states where the release commit is provably NOT on origin may suggest
# rewinding the branch. After a successful push, `git reset --hard` would leave
# the branch behind origin and make the next push a non-fast-forward.
recovery_hint() {
	case "$1" in
		commit-failed)
			cat <<HINT

Recovery
--------
The version headers were rewritten and staged, but the release commit was NOT
created. Nothing was tagged and nothing was pushed. Undo the rewrite with:

  git reset HEAD -- ${PLUGIN_FILE} ${README_FILE}
  git checkout -- ${PLUGIN_FILE} ${README_FILE}

Then fix the cause (a pre-commit hook, for example) and run this script again.
HINT
			;;
		tag-failed)
			cat <<HINT

Recovery
--------
The release commit exists on ${BRANCH}, but the tag ${TAG} was NOT created and
nothing was pushed. Do NOT re-run this script for ${NEW_VERSION}. Finish the
release by hand:

  git tag -a ${TAG} -m "Release ${TAG}"
  git push origin ${BRANCH} --follow-tags

To abandon this release instead (only while the release commit is still the
last commit on ${BRANCH} and was not pushed):

  git reset --hard HEAD~1
HINT
			;;
		not-pushed)
			cat <<HINT

Recovery
--------
The release commit and the local tag ${TAG} exist, but origin does not have
them. Do NOT re-run this script for ${NEW_VERSION}. Push the existing state by
hand instead:

  git push origin ${BRANCH}
  git push origin refs/tags/${TAG}

To abandon this release instead (only while the release commit is still the
last commit on ${BRANCH} and was not pushed):

  git tag -d ${TAG}
  git reset --hard HEAD~1
HINT
			;;
		pushed)
			cat <<HINT

Recovery
--------
The release commit IS on origin/${BRANCH} (the push succeeded); only the tag
${TAG} is missing there, or could not be confirmed. Do NOT re-run this script
for ${NEW_VERSION}. Push the tag by hand (a no-op if it is already there):

  git push origin refs/tags/${TAG}
HINT
			;;
		*)
			die "Internal error: recovery_hint called with unknown state '$1'."
			;;
	esac
}

git add "$PLUGIN_FILE" "$README_FILE"

if ! git commit -m "Release ${TAG}"; then
	recovery_hint commit-failed
	die "Could not create the release commit for ${TAG}."
fi

# Annotated (-a), not lightweight: `git push --follow-tags` transfers annotated
# tags only, and release.yml is triggered by the tag push.
if ! git tag -a "$TAG" -m "Release ${TAG}"; then
	recovery_hint tag-failed
	die "Could not create the annotated tag ${TAG}."
fi

if ! git push origin "$BRANCH" --follow-tags; then
	recovery_hint not-pushed
	die "Push to origin/${BRANCH} failed."
fi

# --- Verify the tag actually landed on origin ------------------------------
#
# The tag push is what triggers release.yml. A branch push that silently leaves
# the tag behind would look like success while no release is ever built, so
# confirm the ref exists on the remote before reporting success.
#
# `git ls-remote` exits 0 whether or not the ref matches, so emptiness (not the
# exit status) is the test for "tag not on origin". Keep stderr out of the
# captured output: a warning on a successful query must never be mistaken for a
# matching ref. It is reported separately so an auth/network failure is not
# mislabelled as a missing tag. Either way the check fails closed.

REMOTE_TAG_ERR_FILE="$(mktemp "${TMPDIR:-/tmp}/wcd-mainwp-release-lsremote.XXXXXX")"
REMOTE_TAG="$(git ls-remote --tags origin "refs/tags/${TAG}" 2>"$REMOTE_TAG_ERR_FILE" || true)"
REMOTE_TAG_ERR="$(cat "$REMOTE_TAG_ERR_FILE")"
rm -f "$REMOTE_TAG_ERR_FILE"

if [ -z "$REMOTE_TAG" ]; then
	recovery_hint pushed
	if [ -n "$REMOTE_TAG_ERR" ]; then
		die "Could not confirm tag ${TAG} on origin (git said: ${REMOTE_TAG_ERR}). If the tag is missing, GitHub Actions will NOT build this release."
	fi
	die "Tag ${TAG} did not reach origin. GitHub Actions will NOT build this release."
fi

echo
echo "Pushed ${TAG} (verified on origin). GitHub Actions is now building and publishing the release zip:"
echo "  https://github.com/WebChangeDetector/webchangedetector-for-mainwp/actions"

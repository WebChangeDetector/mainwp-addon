# Release Scripts

## build-release.sh

Builds a clean, distributable copy of the plugin, analogous to the WP plugin
deployment script (`wcd-plugin/scripts/deploy-to-wp-svn.sh`).

What it does:

1. Validates version consistency across all four sources: plugin header,
   `WCD_MAINWP_VERSION` constant, `readme.txt` stable tag, latest changelog entry.
2. Validates the Git working directory (uncommitted changes, branch).
3. Syncs a clean copy to `/Users/mike/htdocs/wcd/wp-repo-mainwp/trunk/`
   (excludes from `.distignore` plus junk files like `.DS_Store`).
4. Asks whether to create a zip for manual upload:
   `wp-repo-mainwp/webchangedetector-for-mainwp-X.Y.Z.zip`
5. Asks whether to create the Git tag `vX.Y.Z`.

Usage:

```bash
# Always run a dry run first
./scripts/build-release.sh --dry-run

# Actual build (interactive prompts for zip and git tag)
./scripts/build-release.sh
```

There is no WordPress.org SVN repository for this plugin yet. Once it exists,
check it out as `wp-repo-mainwp` and extend the script with the SVN
add/commit/tag steps from the WP plugin script.

**Note for Claude Code:** NEVER run this script for a real build. Builds,
tags and uploads are done manually by Mike. Dry runs for verification are OK
when explicitly asked.

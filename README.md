# WebChange Detector for MainWP

Visual checks for your MainWP updates: capture before/after screenshots of all child sites around an update run and instantly see what changed.

This is the development repository of the WebChange Detector for MainWP WordPress plugin. The user-facing documentation lives in [readme.txt](readme.txt); the technical documentation for this codebase lives in [.docs/MAINWP.md](.docs/MAINWP.md) and [.docs/MAINWP-HOOKS.md](.docs/MAINWP-HOOKS.md).

## Development

- `wp-env start` boots a local WordPress (port 8081) with this plugin and MainWP (see `.wp-env.json`). Point the plugin at a local API via the gitignored `.wp-env.override.json`.
- `composer install`, then `composer lint` (phpcs, WordPress standard) or `composer lint:fix`.

## Release packaging

The wordpress.org distribution excludes the development files listed in `.distignore` (e.g. `wp dist-archive .`).

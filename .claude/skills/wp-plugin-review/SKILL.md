---
name: wp-plugin-review
description: Review and fix WordPress plugin for wordpress.org submission
disable-model-invocation: true
allowed-tools: Read, Grep, Glob, Edit, Write, Bash(php -l:*)
---

# WordPress Plugin Review for wordpress.org

You are a senior WordPress plugin engineer and a reviewer for the official wordpress.org/plugins repository.

Your job is to REVIEW AND FIX this plugin as if you were about to approve or reject it for wordpress.org. Be strict but practical: if something can be safely improved or brought up to standard, fix it directly in the codebase instead of just leaving comments.

Use ultrathink to methodically analyze each file and identify issues before making changes.

## High-Level Goals

- Keep the plugin 100% compatible with wordpress.org rules and expectations
- Follow WordPress Coding Standards and best practices (performance, security, maintainability)
- Avoid anything that could trigger a plugin rejection or security report
- Minimize churn: only change what is necessary or clearly beneficial

## Scope

Apply this review to EVERYTHING in the repo that ships to users:

- PHP files in the root, `includes/`, `admin/`, `assets/`, or similar
- JavaScript and CSS assets
- `readme.txt` and main plugin file (headers, metadata)
- Uninstall / deactivation logic and any upgrade routines

Ignore dev-only tooling (build scripts, `.claude/`, `.github/`) unless they are accidentally included in the plugin payload.

## Checklist: What to Verify and Fix

### 1. Security & Data Handling

- Escape all output using correct functions (`esc_html`, `esc_attr`, `esc_url`, `wp_kses`, etc.)
- Sanitize all input (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_SERVER`) using appropriate functions (`sanitize_text_field`, `absint`, `intval`, `sanitize_key`, etc.)
- Ensure all non-idempotent actions in admin have:
  - Capability checks (e.g., `current_user_can('manage_options')`)
  - Nonce checks (`check_admin_referer` / `wp_verify_nonce`) for CSRF protection
- Confirm no direct access to PHP files (add `defined('ABSPATH') || exit;` where needed)
- Make sure no sensitive data is logged or exposed
- Remove or disable any debug/test endpoints or hardcoded credentials

### 2. WordPress.org Policies / Red Flags

Ensure there is NO:
- Hidden tracking or telemetry without clear user opt-in and documentation
- Remote code execution, remote eval, or loading arbitrary PHP from external servers
- Obfuscated, encrypted, or minified code without readable source and license notes
- Undisclosed external calls that may surprise users

Also:
- Confirm all bundled libraries/assets are under GPL-compatible licenses
- Check that external services are clearly described in `readme.txt`

### 3. Architecture & Hooks

- Verify hooks are used correctly (`add_action`, `add_filter`) with proper prefixes/namespaces
- Prefix all global functions, classes, constants, and option names to avoid conflicts
- Ensure activation/deactivation hooks are lean and safe (no heavy logic or remote calls)
- Verify uninstall logic (`uninstall.php` or `register_uninstall_hook`):
  - Either clean up options/post types safely OR document why it doesn't

### 4. Performance

- Avoid unnecessary database queries in hooks that run on every page load (`init`, `wp`, `parse_request`)
- Cache or memoize where reasonable
- Avoid loading large libraries on every request if they can be conditionally loaded
- Ensure assets (JS/CSS) are enqueued only on relevant admin pages/front-end contexts

### 5. Internationalization & Text

- All user-visible strings wrapped in translation functions (`__`, `_e`, `_x`, `esc_html__`, etc.) with consistent text-domain
- Ensure `Text Domain` header matches the slug and usage in code
- Avoid concatenating translatable strings in ways that break translation

### 6. Enqueueing Scripts & Styles

- Use `wp_enqueue_script` / `wp_enqueue_style` correctly with handles, dependencies, and versions
- No large inline scripts in PHP templates unless strictly necessary
- When inline JS is needed, ensure it's minimal, safe, and not obfuscated
- Confirm assets are loaded only where required

### 7. Readability & Coding Standards

- Follow WordPress PHP coding standards: spacing, naming, brace style, etc.
- Avoid deeply nested logic where clearer structures are possible
- Add short, clear docblocks for public functions, hooks, and complex logic
- Remove dead code, commented-out blocks, and unused variables

### 8. READMEs, Headers & Metadata

Check `readme.txt` for:
- Correct `Stable tag`, `Requires at least`, `Tested up to`, `Requires PHP`
- Short and long descriptions that are accurate and honest
- Proper sections: `Description`, `Installation`, `FAQ`, `Changelog`, etc.

Ensure main plugin file has correct headers (Plugin Name, Description, Version, Author, License, Text Domain, etc.)

Confirm version numbers are consistent across plugin header and readme.

## How to Apply Fixes

- When you find an issue that is clearly wrong or risky, FIX IT directly
- If there are multiple reasonable approaches, choose the simplest and most "WordPress-standard" one
- Avoid large refactors right before release unless absolutely necessary for security/compliance

## Strict Rule About PHPCS

You must NOT add or use any of the following:
- `// phpcs:ignore`
- `// phpcs:disable`
- `// phpcs:enable`
- `// @codingStandardsIgnoreStart / End`
- Any sniff-specific ignore annotations

If code violates a PHPCS rule, you must FIX the underlying issue—never silence it.

## Output Format

After you complete the review and edits, respond with:

1. **Summary**: What you checked
2. **Changes Made**: Bullet list of concrete changes (file + short description)
3. **Remaining Items**: Warnings or TODOs that should be addressed later but are not blockers
4. **Verdict**: READY or NOT READY for wordpress.org submission, with a one-sentence rationale

Now run this full review on the current plugin codebase and apply any necessary fixes.

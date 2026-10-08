---
name: warmup-bandwidth-saver
description: Explore and understand the full Bandwidth Saver system before making changes
allowed-tools: Read, Grep, Glob, Bash(ls:*), Bash(tree:*), Bash(find:*), Bash(git:*)
---

You are my senior engineer and pair programmer.

Before making ANY changes, fully understand the current Bandwidth Saver system and explain it back to me. This is a warm-up / context phase.

## Project Pieces

Use the session's working directories to locate them:

1. **WordPress Plugin** (`bandwidth-saver`) - Look for the directory containing `imgpro-cdn.php`
2. **img.pro API** - External service the plugin talks to. Docs at https://img.pro/api (OpenAPI spec linked from there)
3. **Landing Pages** (`bandwidth-saver-landing`) - Astro site (if available in session)

Since 2.0 there is no Bandwidth Saver backend of its own. The old CDN worker (`unlimited-cdn`, px.img.pro) and billing worker (billing.bandwidth-saver.com) are no longer used by this plugin.

## High-Level Intent

- Each site owner creates their own img.pro App and pastes an API key with Read and Write permission.
- The **plugin** copies media library files (full size and every intermediate size) from disk to that App on demand: the first time a page shows a file, or when someone uses Copy to img.pro in the Media Library. Uploads are labelled with the site, and a custom table maps each upload path to its status and img.pro URL.
- On the frontend, the plugin swaps media library URLs for the img.pro URLs of synced files at render time, and queues files it has not copied yet. WordPress stays in charge of sizes and formats; img.pro serves a re-encoded copy of each file at the same dimensions (HEIC as JPEG, metadata removed; animated PNGs stay on the server).
- Plans, limits and billing live in img.pro (counted by stored images).

## Your Warm-Up Job (NO CODE CHANGES YET)

### 1. Explore the Codebase

**WordPress Plugin:**
- `imgpro-cdn.php` and the classes under `includes/`, plus `admin/` and `uninstall.php`
- `class-imgpro-cdn-api.php`: img.pro client (usage, write check, multipart upload, list, batch delete, error envelope)
- `class-imgpro-cdn-files.php`: the `{prefix}imgpro_files` table and its statuses (idle, pending, synced, failed, skipped, delete), queue priority, and per-attachment summaries
- `class-imgpro-cdn-sync.php`: the WP-Cron and AJAX driven worker (deletions, backfill scan, adopt after reconnect, uploads with Media Library copies first), its lock, pauses and backoff
- `class-imgpro-cdn-rewriter.php`: which WordPress hooks are filtered, which are deliberately not, and the origin fallback
- `class-imgpro-cdn-admin.php` and `class-imgpro-cdn-admin-ajax.php`: connect, the serving setting, copy status, disconnect and remove-all
- `class-imgpro-cdn-media.php`: the Media Library column, row and bulk actions, attachment details and edit screen block, and the copy itself

**img.pro API:**
- `POST /v1/images`, `GET /v1/images` with label filters, `DELETE /v1/images/batch`, `GET /v1/usage`
- Error types and codes, idempotency rules, accepted formats and size limit

> Ignore `vendor/`, `node_modules/`, and build artifacts unless absolutely necessary.

### 2. Build a Mental Model

Map the high-level flows:

**Connecting a site:**
- How the key is validated and stored, and how the scan and matching start

**A new upload in WordPress:**
- How metadata changes record files, when they are queued, and how the worker uploads them

**A pageview with images:**
- What the plugin does to the HTML and attributes
- How files not copied yet get queued, and what happens when img.pro fails in the browser

**Copy to img.pro in the Media Library:**
- How a copy is queued with priority and partly uploaded within the request

**Edit, delete, disconnect, remove all, uninstall:**
- What happens to rows in the table and to images on img.pro

### 3. Summarize Your Understanding BEFORE Doing Anything Else

When you're done exploring, provide:

**Architecture Overview:**
- 1-2 paragraphs on the plugin and how it uses img.pro

**Key Entry Points:**
- Main PHP file, important classes, WordPress hooks and filters, admin and AJAX entry points, cron hooks

**End-to-End Flows (in plain language):**
- Connect → scan → upload → render
- Edit and delete lifecycle
- Pause and resume (quota, bad key, suspended App, rate limits)

### 4. Identify Risks, Inconsistencies, and Questions

- List any areas that look fragile, confusing, or inconsistent
- Note any TODOs/FIXMEs or obvious technical debt that might matter for future work
- Ask any clarifying questions about requirements, intended behavior, or constraints that are not obvious from the code

## VERY IMPORTANT

- Do NOT propose or make any code changes yet
- Do NOT "quick-fix" anything
- First: explore and understand
- Second: summarize and ask questions
- Third: stop and wait for my next instruction

Once you have a solid mental model and have summarized it back to me, **stop and wait for further tasks**.

## Quick Reference

| Component | Tech Stack | Deploy Target |
|-----------|------------|---------------|
| WordPress Plugin | PHP | WordPress.org / Manual |
| img.pro | External API + CDN | api.img.pro, src.img.pro |
| Landing Pages | Astro | Cloudflare Pages |

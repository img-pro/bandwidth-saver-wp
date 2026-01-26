---
name: warmup-bandwidth-saver
description: Explore and understand the full Bandwidth Saver system before making changes
allowed-tools: Read, Grep, Glob, Bash(ls:*), Bash(tree:*), Bash(find:*), Bash(git:*)
---

You are my senior engineer and pair programmer.

Before making ANY changes, fully understand the current Bandwidth Saver system and explain it back to me. This is a warm-up / context phase.

## Project Repositories

| Component | GitHub Repo | Local Path |
|-----------|-------------|------------|
| WordPress Plugin | `img-pro/bandwidth-saver-wp` | !`git rev-parse --show-toplevel` |
| CDN Worker | `img-pro/unlimited-cdn` | !`find ~/GitHub -maxdepth 3 -type d -name "unlimited-cdn" ! -path "*-wp*" ! -path "*-billing*" 2>/dev/null \| head -1` |
| Billing Worker | `img-pro/unlimited-cdn-billing` | !`find ~/GitHub -maxdepth 3 -type d -name "unlimited-cdn-billing" 2>/dev/null \| head -1` |
| Landing Pages | `img-pro/bandwidth-saver-landing` | !`find ~/GitHub -maxdepth 3 -type d -name "bandwidth-saver-landing" 2>/dev/null \| head -1` |

## High-Level Intent

- The **WordPress plugin** rewrites frontend image URLs so they are served through a Cloudflare Worker + R2 cache instead of directly from the origin server, without touching DNS or moving images in WordPress.
- The **CDN worker** fetches images from the configured origin(s), stores them in R2 on first request, and serves cached images with long-lived cache headers. It also handles origin validation and security.
- The **billing worker** handles Stripe subscriptions, API key generation and validation, site registration and updates, multi-domain origin support, usage analytics, and admin domain blocking.

## Your Warm-Up Job (NO CODE CHANGES YET)

### 1. Explore the Codebase

Use your tools to inspect all relevant code under those paths.

**WordPress Plugin:**
- Find the main plugin file (`imgpro-cdn.php`) and the classes under `includes/`, `admin/`, and `assets/`
- Identify how it hooks into WordPress to rewrite image URLs
- Understand how it stores configuration
- Find how it talks to the billing and CDN workers

**CDN Worker:**
- Identify the main entry file (e.g., `src/index.ts`) and supporting modules (cache, origin, validation, viewer, analytics, utils, etc.)
- Understand how it parses the CDN URL, validates the origin domain, fetches from origin, stores objects in R2, and returns responses

**Billing Worker:**
- Identify the main entry handler and routing
- Understand the modules that deal with Stripe (checkout, portal, webhooks), account management (sites, API keys), source URLs, usage analytics, and admin domain blocking

> Ignore `vendor/`, `node_modules/`, and build artifacts unless absolutely necessary.

### 2. Build a Mental Model

For each repo, identify:
- Main entry points and request handlers
- Core data models or types (sites, tiers, source URLs, usage records, etc.)
- Important helpers or utility modules

Map the high-level flows:

**A pageview in WordPress that includes images:**
- What the plugin does to the HTML and URLs
- How the browser hits the CDN worker
- How the CDN worker decides to fetch from origin vs serve from R2

**A managed subscription flow:**
- How the plugin initiates billing calls
- How the billing worker creates checkout/portal sessions
- How API keys are issued, validated, and used by the plugin and/or workers

**Origin validation:**
- How domain allow/block lists work across billing + CDN worker (if applicable)

### 3. Summarize Your Understanding BEFORE Doing Anything Else

When you're done exploring, provide:

**Architecture Overview:**
- 1-2 paragraphs per repo explaining its role and key components

**Key Entry Points:**
- Plugin: main PHP file, important classes, primary WordPress hooks and filters, key admin/settings entry points
- CDN worker: request handler(s), routing, R2 integration, origin validation logic, notable query parameters/endpoints like `/health`, `/stats`, debug viewer, etc.
- Billing worker: main request handler, API routes, Stripe integration points (checkout, portal, webhooks), D1/KV usage if present

**End-to-End Flows (in plain language):**
- WordPress request with images → CDN worker → origin/R2 → browser
- Subscription lifecycle: registration, checkout, webhooks, account updates, API key usage

**Configuration & Environment:**
- Important environment variables and how they influence behavior

### 4. Identify Risks, Inconsistencies, and Questions

- List any areas that look fragile, confusing, or inconsistent across the three repos
- Note any TODOs/FIXMEs or obvious technical debt that might matter for future work
- Ask any clarifying questions you have about requirements, intended behavior, or constraints that are not obvious from the code

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
| CDN Worker | TypeScript | Cloudflare Workers + R2 |
| Billing Worker | TypeScript | Cloudflare Workers + D1 |
| Landing Pages | Astro | Cloudflare Pages |

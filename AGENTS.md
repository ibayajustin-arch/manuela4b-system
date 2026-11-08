# AGENTS.md

## Project
Manuela 4B MIS: a PHP + MySQL web app (admin dashboard, analytics, residents, payments, facility reservations).
Runs locally on XAMPP (C:\xampp\htdocs\Manuela). Deployed on InfinityFree free hosting (shared PHP hosting, no Node.js on the server).

## Stack
- PHP (plain, no framework), MySQL, vanilla JavaScript
- Hosting limits: shared hosting only. Do not add Node.js, Composer-heavy, or server-side build steps.

## Folder map
- Admin/Dashboard.php, Admin/analytics.php: admin pages
- Admin/api/analytics*.php: JSON endpoints (must output valid JSON only, no JS or HTML)
- includes/: shared PHP helpers (analytics_data.php, analytics_overview.php)
- js/: admin-analytics.js, admin-dashboard-analytics.js

## Do not touch (ask first)
- The database connection/config file (credentials). Never print or commit passwords or API keys.
- Any .sql file with real data. Sample data files are fine to edit.
- Do not delete files. Do not rename folders.

## Rules for changes
- Make the smallest change that fixes the problem. Edit only the files I name.
- Use prepared statements for all SQL. Escape all output (htmlspecialchars).
- Keep API endpoints returning valid JSON only.
- Do not rewrite a whole file to change a few lines.

## Domain rules
- Streets (block values): Duhat, Santol, Mansanas, Granada, Guyabano, Atis, Ubas
- Payment methods: GCash and Maya only
- Facility reservations: Court and Clubhouse only, auto-approved by the system
- Monthly dues: 250 pesos per homeowner

## Workflow
- Before editing, tell me which files you will change in one line.
- After editing, summarize what changed in 3 lines or fewer.
- Reply in simple Taglish. Keep answers short.

## UI rules
- Frontend: Bootstrap 5.3 + Bootstrap Icons + vanilla JS. No React, Tailwind, or build step.
- Brand: green #0F6B5A, font Inter. Keep the existing look; do not redesign unless I ask.
- Mobile-first. Inputs and buttons 48px tall, font-size 16px on inputs, safe-area padding.
- UI work: edit CSS/HTML only. Do not change PHP logic, SQL, or CSRF code.
- Visible text: real info only, no filler or demo data. Taglish is OK for resident-facing labels.

## Deploy
- Do not upload .claude/, .codex/, .agents/, .github/, .shared/, AGENTS.md, or *.md files to InfinityFree.
- File and folder names are case-sensitive on the server. Match the exact case in every path.

## Using the frontend-design skill
- Treat this project as an EXISTING design system (Bootstrap 5.3, brand green #0F6B5A, Inter). Match it. Do not redesign.
- Ignore Next.js, React, Tailwind, and package.json guidance. This is PHP + vanilla JS.
- Do not add libraries or build steps. Skip the 3-sentence design philosophy for fixes and small changes.
- Verify at 360px and a wide desktop width. Do not claim visual checks you could not run.
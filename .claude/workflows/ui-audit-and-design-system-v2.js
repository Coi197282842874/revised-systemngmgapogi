export const meta = {
  name: 'ui-audit-and-design-system-v2',
  description: 'Audit every Arve\'s House page from accurate screenshots + code, build one shared design system (theme.css, icons.php, preview), critique it; review the new floating login in parallel',
  phases: [
    { title: 'Audit', detail: '6 auditors (page groups) + 2 floating-login reviewers' },
    { title: 'Design', detail: 'design lead builds theme.css, icons.php, DESIGN.md, preview.php' },
    { title: 'Critique', detail: 'craft/contrast critic + visual critic on the preview' },
    { title: 'Revise', detail: 'design lead resolves critiques' },
  ],
}

const ROOT = 'C:/xampp/htdocs/arves-house'
const S = 'C:/Users/ASUSTU~1/AppData/Local/Temp/claude/C--xampp-htdocs-arves-house/99d2b0ed-1c2d-454e-b1be-37bcaf5ad351/scratchpad'
const PHP = 'C:/xampp/php/php.exe'
const SKILLS = `${ROOT}/.claude/skills`
const shot = (url, out, mobile, extra = '') =>
  `powershell -NoProfile -ExecutionPolicy Bypass -File "${S}/cdp-shot.ps1" -Url "${url}" -Out "${out}" ${mobile ? '-Width 390 -Height 844 -Mobile' : '-Width 1440 -Height 900'} ${extra}`.trim()

const PAGES = {
  index: 'index.php', indexagoda: 'indexagoda.php', rooms: 'rooms.php',
  reservation: 'reservation.php', payment: 'payment.php', reservation_success: 'reservation_success.php',
  login: 'login.php', register: 'register.php', admin_login: 'admin/login.php',
  customer_dashboard: 'customer/dashboard.php', customer_profile: 'customer/profile.php',
  admin_dashboard: 'admin/dashboard.php', admin_reservations: 'admin/reservations.php', admin_payment: 'admin/payment.php',
  admin_rooms: 'admin/rooms.php', admin_customers: 'admin/customers.php', admin_site_settings: 'admin/site_settings.php',
}
const GROUPS = [
  { key: 'marketing', pages: ['index', 'indexagoda', 'rooms'] },
  { key: 'booking', pages: ['reservation', 'payment', 'reservation_success'] },
  { key: 'auth', pages: ['login', 'register', 'admin_login'] },
  { key: 'customer_app', pages: ['customer_dashboard', 'customer_profile'] },
  { key: 'admin_core', pages: ['admin_dashboard', 'admin_reservations', 'admin_payment'] },
  { key: 'admin_manage', pages: ['admin_rooms', 'admin_customers', 'admin_site_settings'] },
]

const AUDIT_SCHEMA = {
  type: 'object',
  properties: {
    pages: { type: 'array', items: { type: 'object', properties: {
      key: { type: 'string' },
      issues: { type: 'array', items: { type: 'object', properties: {
        severity: { type: 'string', enum: ['high', 'medium', 'low'] },
        area: { type: 'string' }, problem: { type: 'string' }, fix: { type: 'string' },
      }, required: ['severity', 'area', 'problem', 'fix'] } },
      components: { type: 'array', items: { type: 'object', properties: {
        name: { type: 'string' }, selectors: { type: 'string' }, notes: { type: 'string' },
      }, required: ['name', 'selectors'] } },
      emoji_icons: { type: 'array', items: { type: 'object', properties: {
        emoji: { type: 'string' }, meaning: { type: 'string' }, suggested_icon: { type: 'string' },
      }, required: ['emoji', 'meaning', 'suggested_icon'] } },
      js_contract: { type: 'array', items: { type: 'string' }, description: 'ids/classes/data-attrs that JS or PHP depends on and must be kept' },
    }, required: ['key', 'issues', 'components', 'emoji_icons', 'js_contract'] } },
  },
  required: ['pages'],
}

const FINDINGS_SCHEMA = {
  type: 'object',
  properties: {
    issues: { type: 'array', items: { type: 'object', properties: {
      severity: { type: 'string', enum: ['blocker', 'major', 'minor'] },
      where: { type: 'string' }, problem: { type: 'string' }, evidence: { type: 'string' }, fix: { type: 'string' },
    }, required: ['severity', 'where', 'problem', 'fix'] } },
    verdict: { type: 'string' },
  },
  required: ['issues', 'verdict'],
}

const auditPrompt = g => `You are a senior product designer + design engineer auditing part of "Arve's House", a small PHP guesthouse booking site, before a redesign.

Read first: ${S}/BRIEF.md (approved redesign direction, constraints, the floating login, the screenshot tool) and ${S}/STANDARD.md (motion/mobile rules).
Skim for the craft bar: ${SKILLS}/emil-design-eng/SKILL.md and ${SKILLS}/apple-design/SKILL.md (typography, materials, hierarchy).

Your pages. Screenshots of the current state are ACCURATE (true 390px mobile emulation; "-full" = whole page). Read the PNGs to look at them:
${g.pages.map(k => `- ${k}: code ${ROOT}/${PAGES[k]}\n    ${S}/shots/before/${k}-desktop.png, ${k}-desktop-full.png, ${k}-mobile.png, ${k}-mobile-full.png`).join('\n')}
If you need to check an interactive state (menu open, modal, error), take your own shot with the tool described in BRIEF.md (write into ${S}/shots/audit/).

For EACH page:
1. Look at desktop + mobile (fold and full).
2. Read the page code (long — read in chunks; focus on <style>, HTML structure, <script>).
3. List concrete UI problems with severity + concrete fix: visual hierarchy, typography, spacing rhythm, alignment, contrast (flag anything that looks < AA), color consistency, button hierarchy (destructive actions too loud), form usability, empty/zero states, table readability, icon quality (emoji), image placeholders, mobile layout (cramped, tap targets, overflow — verify overflow claims with -Eval "document.documentElement.scrollWidth" before reporting), consistency with the rest of the site. Be specific (selector/element + change). No generic advice.
4. Inventory the page's components (name + current selectors).
5. List every emoji used as an icon, its meaning, and a Lucide-style icon name to replace it.
6. js_contract: every id/class/data-attribute that <script> or PHP logic depends on (querySelector, getElementById, classList, onclick, form names), plus the floating-login contract from BRIEF.md where it applies.
Read-only: do NOT edit project files.`

const lmReviewers = [
  { key: 'security', prompt: `You are an application-security reviewer. A floating login was just added to a PHP site (XAMPP, local dev at http://127.0.0.1:8801 guest server).
Review: ${ROOT}/login.php (new redirect validation, $wantsJson, finishLogin(), JSON error path) and ${ROOT}/includes/login-modal.php (dialog + JS: link interception, fetch POST with X-Requested-With: fetch, client-side redirect guard, navigation via new URL(target, form.action)). It is included in ${ROOT}/index.php, indexagoda.php, rooms.php.
Hunt for: open redirect / javascript: / data: / protocol-relative / backslash / encoded-bypass variants (e.g. %2F%2F, tab/newline inside scheme like "java\\tscript:", leading whitespace, "\\\\", "/%09/evil"), XSS (error rendering, redirect reflection into the hidden input on the full page), session fixation, CSRF/login-CSRF implications (pre-existing vs newly introduced — say which), JSON endpoint leaking info (user enumeration differences between inactive vs invalid), behavior when a logged-in user hits it, caching of JSON responses, anything the fetch path does differently from the classic POST path.
You may test the server with curl against http://127.0.0.1:8801/login.php (the guest dev server) using the header "X-Requested-With: fetch" — use only fake credentials; do not create users or modify the database. To test success-path redirect handling without real credentials, read the PHP and reason, or run ${S}/test-login.php (in-process harness: inserts a temp user inside a transaction and rolls back; env XRW=fetch REDIR=<value> [PW=...]) with ${PHP} -d display_errors=stderr.
Distinguish newly introduced issues from pre-existing ones. Read-only on project files. Return findings with evidence and concrete fixes.` },
  { key: 'craft', prompt: `You are a strict design-engineering + accessibility reviewer (Emil Kowalski's bar: default to flagging; approval is earned). A floating login dialog was just added: ${ROOT}/includes/login-modal.php (native <dialog>, centered card on desktop, bottom sheet at ≤560px), included in ${ROOT}/index.php, indexagoda.php, rooms.php; triggered by clicks on links to login.php.
Standards: ${SKILLS}/emil-design-eng/SKILL.md, ${SKILLS}/review-animations/STANDARDS.md, ${SKILLS}/mobile-native/SKILL.md, ${SKILLS}/apple-design/SKILL.md, ${S}/STANDARD.md.
Check with real rendering — use the DevTools screenshot tool (see ${S}/BRIEF.md) against http://127.0.0.1:8801/index.php and rooms.php; use -Eval to click a login link (e.g. document.querySelector('a[href^="login.php"]').click()) and to measure things (focus target, computed styles, dialog rect, scrollWidth, getAnimations()). Screens go in ${S}/shots/lm-review/. Check desktop 1440x900 and mobile 390x844 (-Mobile).
Review: entrance/exit motion (curves, durations ≤300ms, exit faster, @starting-style + allow-discrete fallback behavior, reduced motion), press feedback, hover gating, focus management (initial focus on fine vs coarse pointers, focus return on close, Esc, backdrop click, no focus trap bugs), screen-reader semantics (labelling, role=alert error, aria-busy, Show/Hide toggle), keyboard flow, contrast of every text color on its background (compute ratios), tap targets ≥44px, iOS input zoom, safe-area on the sheet, keyboard covering the sheet, scroll lock + scrollbar compensation, interaction with the pages' own mobile menus (open the hamburger menu first, then tap Login inside it), password managers/autofill, and whether the visual design matches the site's brand.
Read-only. Return issues with evidence and concrete fixes.` },
]

const designPrompt = audits => `You are the design lead for the "Arve's House" UI redesign. Build ONE shared design system that every page will migrate onto.

Inputs:
- ${S}/BRIEF.md — approved direction, constraints, architecture, the floating login, and the screenshot tool. Follow it exactly.
- ${S}/STANDARD.md — motion + mobile rules; theme.css bakes these in (tokens, explicit transitions, press scale, hover gating, reduced motion, mobile baseline).
- Skills: ${SKILLS}/emil-design-eng/SKILL.md, ${SKILLS}/apple-design/SKILL.md, ${SKILLS}/mobile-native/SKILL.md.
- Current-state screenshots (accurate): ${S}/shots/before/*.png — look at several customer AND admin pages, desktop and mobile.
- Audit results (JSON below): per-page issues, component inventories, emoji→icon mapping, js contracts.

Deliverables (create these files):
1. ${ROOT}/assets/css/theme.css — tokens (brand colors incl. semantic status colors, neutrals, text levels verified ≥ AA; type scale; spacing; radii; layered rgba shadows; motion tokens), font stacks, low-specificity base element styles, mobile baseline, focus-visible ring, reduced-motion, and components: buttons (primary/secondary/ghost/danger-ghost + sizes + icon buttons), form controls (input/select/textarea/checkbox/radio cards/file input), field labels/help/error, cards, definition-list rows, badges/status pills (every status in the audits), alerts/flash messages, tables (+ mobile behavior), customer topbar/nav + mobile nav, footer, admin shell (sidebar, topbar, content), stat cards, section headers/eyebrow, empty states, modals, room card + room image placeholder. Namespaced predictable class names (.btn, .btn--primary, .badge, .badge--pending, .card, .field, .input, .table, .admin-shell …). Check the audits' component inventories for class-name COLLISIONS with existing page classes (.btn, .card, .badge, .alert…) and choose names/specificity so linking theme.css before a page's inline <style> does not break any page before it is migrated. Organized with section comments; one file, no @import.
2. ${ROOT}/includes/icons.php — \`function icon(string $name, string $class = 'icon', string $label = ''): string\` returning inline SVG (Lucide-style 24x24, stroke=currentColor, stroke-width 1.75, round caps/joins, fill none). Cover every icon the audits need plus nav basics (home, bed, calendar, users, credit-card, wallet, banknote, settings, globe, log-out, log-in, user, check, check-circle, x, clock, hourglass, info, alert-triangle, search, arrow-right, arrow-left, chevron-down, menu, lock, shield-check, mail, phone, image, upload, trash, pencil, eye, plus, star, map-pin, receipt, layout-dashboard, door-open, sparkles…). Accurate Lucide path data. Guard with \`if (!function_exists('icon'))\`. Escape $class/$label. Decorative → aria-hidden="true"; labelled → role="img" + aria-label.
3. ${S}/DESIGN.md — the migration guide page agents follow: exact <head> snippet (Google Fonts + theme.css); RELATIVE paths (root pages: \`assets/css/theme.css\`, \`require_once __DIR__ . '/includes/icons.php'\`; admin/ and customer/ pages: \`../assets/css/theme.css\`, \`__DIR__ . '/../includes/icons.php'\` — the site lives at /arves-house/ under Apache); component reference with HTML examples; token reference; emoji→icon table; per-page-type guidance (marketing, booking, auth, customer app, admin); how to restyle the floating login to the tokens without breaking its contract; a checklist; a "do not break" section with each page's js_contract; and each page's top audit issues.
4. ${S}/preview.php — style guide page: \`require '${ROOT}/includes/icons.php'\`, link /assets/css/theme.css (absolute here; served at /__scratch/preview.php by the dev router) + Google Fonts; render color + type specimens, every button variant/state, all form controls (incl. error), cards, definition list, all badges, alerts, a table, customer topbar, admin shell mini-mock with sidebar + stat cards, a room card with the image placeholder, an empty state, a modal, and a grid of EVERY icon with its name.

Verify visually:
  ${shot('http://127.0.0.1:8801/__scratch/preview.php', `${S}/shots/preview-desktop.png`, false, '-Full')}
  ${shot('http://127.0.0.1:8801/__scratch/preview.php', `${S}/shots/preview-mobile.png`, true, '-Full')}
Read the PNGs, fix anything off (broken icons, clashing styles, contrast), re-shoot. Run ${PHP} -l on icons.php and preview.php.

Audits:
${JSON.stringify(audits)}

Return a concise summary: palette (hex), fonts, key decisions, class-collision strategy, icon count, open questions.`

const critics = [
  { key: 'craft', prompt: `You are a strict design-systems + accessibility critic (Emil Kowalski's bar: default to flagging).
Review the new design system for "Arve's House": ${ROOT}/assets/css/theme.css, ${ROOT}/includes/icons.php, ${S}/DESIGN.md, ${S}/preview.php
against ${S}/BRIEF.md, ${S}/STANDARD.md, ${SKILLS}/emil-design-eng/SKILL.md, ${SKILLS}/review-animations/STANDARDS.md, ${SKILLS}/mobile-native/SKILL.md, ${SKILLS}/apple-design/SKILL.md.
Check hard: (1) COMPUTE WCAG contrast for every text/background token pair and badge/button/alert combo — write a tiny PHP script (relative luminance) and run it with ${PHP}; flag < 4.5:1 body text, < 3:1 large text/UI boundaries; (2) motion: no transition:all, no bare durations, no ease-in, UI ≤300ms, press scale, hover gated by (hover:hover) and (pointer:fine) with :focus-visible parity, reduced-motion correct; (3) mobile baseline: 16px inputs on coarse pointers, 44px targets, tap highlight, dvh/svh; (4) base-element styles or class names that would clobber EXISTING page CSS before migration (grep ${ROOT}/*.php ${ROOT}/admin/*.php ${ROOT}/customer/*.php for .btn/.card/.badge/.alert/.table/.field etc. and compare meanings) — say how to resolve; (5) icons.php: escaping, function_exists guard, php -l, every icon referenced in DESIGN.md exists; (6) DESIGN.md relative-path instructions correct for root vs admin/ vs customer/ under Apache at /arves-house/; (7) the floating login contract in BRIEF.md is preserved by DESIGN.md guidance.
Read-only. Return issues with concrete fixes.` },
  { key: 'visual', prompt: `You are a senior visual designer critiquing a new design system by LOOKING at it.
Context: ${S}/BRIEF.md. Style guide: http://127.0.0.1:8801/__scratch/preview.php (source ${S}/preview.php, CSS ${ROOT}/assets/css/theme.css).
Take fresh screenshots and Read them:
  ${shot('http://127.0.0.1:8801/__scratch/preview.php', `${S}/shots/critic-desktop.png`, false, '-Full')}
  ${shot('http://127.0.0.1:8801/__scratch/preview.php', `${S}/shots/critic-mobile.png`, true, '-Full')}
(Very tall captures get downscaled when read — if details are too small, add -Eval "window.scrollTo(0, N)" without -Full to shoot specific sections at full resolution.)
Compare with the current site: ${S}/shots/before/index-desktop.png, customer_dashboard-desktop.png, admin_dashboard-desktop.png, payment-desktop.png, login-mobile.png.
Judge: clearly better than before while still recognizably Arve's House? One product across customer and admin? Typography (sizes, weights, line-height; are the Google fonts actually loading or is it a fallback?), spacing rhythm, shadow/border subtlety, button hierarchy, badge legibility, table readability, room placeholder, admin sidebar. Inspect EVERY icon in the grid: flag any broken, clipped, blank, or wrong for its name. Mobile: overflow, cramped spacing, tap targets.
Read-only. Return issues with concrete fixes (exact token/selector changes).` },
]

const revisePrompt = crit => `You are the design lead. Critics reviewed your design system. Resolve every blocker and major issue, and minor ones where cheap.
Files you own: ${ROOT}/assets/css/theme.css, ${ROOT}/includes/icons.php, ${S}/DESIGN.md, ${S}/preview.php. Context: ${S}/BRIEF.md, ${S}/STANDARD.md.
Critiques (JSON): ${JSON.stringify(crit)}
After fixing, re-screenshot and Read:
  ${shot('http://127.0.0.1:8801/__scratch/preview.php', `${S}/shots/preview-desktop.png`, false, '-Full')}
  ${shot('http://127.0.0.1:8801/__scratch/preview.php', `${S}/shots/preview-mobile.png`, true, '-Full')}
Run ${PHP} -l on icons.php and preview.php. Make sure DESIGN.md reflects the final class names/tokens.
Return: what you changed per critique item (or why not), and the final palette/fonts summary.`

phase('Audit')
const [auditResults, lmReview] = await Promise.all([
  parallel(GROUPS.map(g => () => agent(auditPrompt(g), { label: `audit:${g.key}`, phase: 'Audit', schema: AUDIT_SCHEMA }))),
  parallel(lmReviewers.map(r => () => agent(r.prompt, { label: `login-modal:${r.key}`, phase: 'Audit', schema: FINDINGS_SCHEMA }).then(x => x && ({ reviewer: r.key, ...x })))),
])
const audits = auditResults.filter(Boolean).flatMap(r => r.pages)
log(`Audited ${audits.length} pages, ${audits.reduce((n, p) => n + p.issues.length, 0)} issues; login-modal reviews: ${lmReview.filter(Boolean).map(r => `${r.reviewer}=${r.issues.length}`).join(', ')}`)

phase('Design')
const design = await agent(designPrompt(audits), { label: 'design-lead', phase: 'Design' })

phase('Critique')
const crit = (await parallel(critics.map(c => () =>
  agent(c.prompt, { label: `critic:${c.key}`, phase: 'Critique', schema: FINDINGS_SCHEMA }).then(r => r && ({ critic: c.key, ...r }))
))).filter(Boolean)

phase('Revise')
const revision = await agent(revisePrompt(crit), { label: 'design-lead:revise', phase: 'Revise' })

return { auditCount: audits.length, audits, lmReview: lmReview.filter(Boolean), design, crit, revision }

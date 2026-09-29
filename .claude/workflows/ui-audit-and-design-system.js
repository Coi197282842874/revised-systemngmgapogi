export const meta = {
  name: 'ui-audit-and-design-system',
  description: 'Audit every Arve\'s House page from screenshots + code, then build one shared design system (theme.css, icons.php, preview) and critique it',
  phases: [
    { title: 'Audit', detail: '6 auditors, one per page group, screenshots + code' },
    { title: 'Design', detail: 'design lead builds theme.css, icons.php, DESIGN.md, preview.php' },
    { title: 'Critique', detail: 'craft/contrast critic + visual critic on the preview' },
    { title: 'Revise', detail: 'design lead resolves critiques' },
  ],
}

const ROOT = 'C:/xampp/htdocs/arves-house'
const S = 'C:/Users/ASUSTU~1/AppData/Local/Temp/claude/C--xampp-htdocs-arves-house/99d2b0ed-1c2d-454e-b1be-37bcaf5ad351/scratchpad'
const PHP = 'C:/xampp/php/php.exe'
const CHROME = '/c/Program Files/Google/Chrome/Application/chrome.exe'
const SKILLS = `${ROOT}/.claude/skills`

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

const CRITIQUE_SCHEMA = {
  type: 'object',
  properties: {
    issues: { type: 'array', items: { type: 'object', properties: {
      severity: { type: 'string', enum: ['blocker', 'major', 'minor'] },
      where: { type: 'string' }, problem: { type: 'string' }, fix: { type: 'string' },
    }, required: ['severity', 'where', 'problem', 'fix'] } },
    verdict: { type: 'string' },
  },
  required: ['issues', 'verdict'],
}

const shotCmd = (url, out, w, h) => `"${CHROME}" --headless=new --disable-gpu --hide-scrollbars --no-first-run --user-data-dir="$(mktemp -d)" --window-size=${w},${h} --virtual-time-budget=4000 --screenshot="$(cygpath -w '${out}')" "${url}"`

const auditPrompt = g => `You are a senior product designer + design engineer auditing part of "Arve's House", a small PHP guesthouse booking site, before a redesign.

Read first: ${S}/BRIEF.md (the approved redesign direction and constraints) and ${S}/STANDARD.md (motion/mobile rules already being applied).
Skim for the craft bar: ${SKILLS}/emil-design-eng/SKILL.md and ${SKILLS}/apple-design/SKILL.md (typography, materials, hierarchy sections).

Your pages (screenshots already captured of the current state; Read the PNGs to look at them):
${g.pages.map(k => `- ${k}: code ${ROOT}/${PAGES[k]}\n    screenshots: ${S}/shots/before/${k}-desktop.png, ${k}-desktop-full.png, ${k}-mobile.png, ${k}-mobile-full.png (same folder)`).join('\n')}

For EACH page:
1. Look at the desktop and mobile screenshots (at least the fold shots; the -full shots for long pages).
2. Read the page code (it is long — read in chunks; focus on <style>, HTML structure, <script>).
3. List concrete UI problems with severity and a concrete fix: visual hierarchy, typography, spacing rhythm, alignment, contrast (estimate; flag anything that looks < AA), color consistency, button hierarchy (e.g. destructive actions too loud), form usability, empty/zero states, table readability, icon quality (emoji), placeholders, mobile layout (overflow, cramped, tap targets), consistency with the rest of the site. Be specific (selector or element + what to change). No generic advice.
4. Inventory the page's components (name + current selectors) so the design system covers them.
5. List every emoji used as an icon, its meaning, and a Lucide-style icon name to replace it.
6. List the js_contract: every id/class/data-attribute that <script> or PHP logic depends on (querySelector, getElementById, classList, onclick handlers, form names) — the redesign must keep these.
Read-only: do NOT edit any files.`

const designPrompt = audits => `You are the design lead for the "Arve's House" UI redesign. Build ONE shared design system that every page will migrate onto.

Inputs:
- ${S}/BRIEF.md — the approved direction, constraints and architecture. Follow it exactly.
- ${S}/STANDARD.md — motion + mobile rules; theme.css must bake these in (tokens, explicit transitions, press scale, hover gating, reduced motion, mobile baseline).
- Skills for the craft bar: ${SKILLS}/emil-design-eng/SKILL.md, ${SKILLS}/apple-design/SKILL.md, ${SKILLS}/mobile-native/SKILL.md.
- Current-state screenshots: ${S}/shots/before/*.png (look at several customer AND admin pages).
- Audit results from 6 auditors (JSON below): per-page issues, component inventories, emoji→icon mapping, js contracts.

Deliverables (create these files):
1. ${ROOT}/assets/css/theme.css — tokens (brand colors incl. semantic status colors, neutrals, text levels verified ≥ AA; type scale; spacing; radii; layered rgba shadows; motion tokens), Google-font-aware font stacks, base element styles (low specificity), mobile baseline, focus-visible ring, reduced-motion, and components: buttons (primary/secondary/ghost/danger-ghost + sizes + icon buttons), form controls (input/select/textarea/checkbox/radio cards/file input), field labels/help/error, cards, definition-list rows, badges/status pills (every status seen in the audits), alerts/flash messages, tables (+ mobile behavior), customer topbar/nav + mobile nav, footer, admin shell (sidebar, topbar, content), stat cards, section headers/eyebrow, empty states, modals, room card + room image placeholder. Namespaced, predictable class names (e.g. .btn, .btn--primary, .badge, .badge--pending, .card, .field, .input, .table, .admin-shell ...). Keep it well-organized with section comments. Target a single coherent file (no @import).
2. ${ROOT}/includes/icons.php — \`function icon(string $name, string $class = 'icon', string $label = ''): string\` returning inline SVG (Lucide-style 24x24, stroke=currentColor, stroke-width 1.75, round caps/joins, fill none). Cover every icon the audits need (union of suggested_icon lists) plus nav basics (home, bed, calendar, users, credit-card, wallet, banknote, settings, globe, log-out, log-in, user, check, check-circle, x, clock, hourglass, info, alert-triangle, search, arrow-right, arrow-left, chevron-down, menu, lock, shield-check, mail, phone, image, upload, trash, edit/pencil, eye, plus, star, map-pin, receipt, layout-dashboard, door-open, sparkles, wifi, coffee...). Use accurate Lucide path data. Guard with \`if (!function_exists('icon'))\`. Escape $class/$label. Decorative → aria-hidden="true"; labelled → role="img" + aria-label.
3. ${S}/DESIGN.md — the migration guide the page agents will follow: how to link fonts + theme.css (IMPORTANT: pages must use RELATIVE paths — root pages \`assets/css/theme.css\` and \`includes/icons.php\` via \`__DIR__\`; pages in admin/ and customer/ use \`../assets/css/theme.css\` and \`__DIR__ . '/../includes/icons.php'\` — because under Apache the site lives at /arves-house/), the exact <head> snippet, component reference with HTML examples, token reference, the emoji→icon table, per-page-type guidance (marketing, booking, auth, customer app, admin), a checklist, and a "do not break" section summarizing each page's js_contract from the audits. Also include per-page top issues from the audits so page agents know what to fix.
4. ${S}/preview.php — a style-guide page that requires ${ROOT}/includes/icons.php (absolute path is fine here), links /assets/css/theme.css (absolute here — it is served by the dev router at /__scratch/preview.php) plus the Google Fonts link, and renders: color + type specimens, every button variant/state, all form controls (incl. error state), cards, definition list, all badges, alerts, a table, a customer topbar, an admin shell mini-mock with sidebar + stat cards, a room card with the image placeholder, an empty state, a modal, and a grid of EVERY icon with its name.

Then verify visually: screenshot the preview with Bash:
  ${shotCmd('http://127.0.0.1:8801/__scratch/preview.php', `${S}/shots/preview-desktop.png`, 1440, 3600)}
  ${shotCmd('http://127.0.0.1:8801/__scratch/preview.php', `${S}/shots/preview-mobile.png`, 390, 5200)}
Read the PNGs, fix anything off (broken icons, clashing styles, contrast), re-shoot. Also run \`${PHP} -l\` on icons.php and preview.php.

Audits:
${JSON.stringify(audits)}

Return a concise summary: the palette (hex), fonts, key decisions, icon count, and anything you were unsure about.`

const critics = [
  { key: 'craft', prompt: `You are a strict design-systems + accessibility critic (Emil Kowalski's review bar: default to flagging; approval is earned).
Review the new design system for "Arve's House":
- ${ROOT}/assets/css/theme.css, ${ROOT}/includes/icons.php, ${S}/DESIGN.md, ${S}/preview.php
against ${S}/BRIEF.md, ${S}/STANDARD.md, ${SKILLS}/emil-design-eng/SKILL.md, ${SKILLS}/review-animations/STANDARDS.md, ${SKILLS}/mobile-native/SKILL.md, ${SKILLS}/apple-design/SKILL.md.
Check hard: (1) COMPUTE WCAG contrast ratios for every text/background token pair and badge/button/alert combo — write a tiny PHP script with the relative-luminance formula and run it with ${PHP}; flag anything < 4.5:1 for body text, < 3:1 for large text/UI boundaries; (2) motion: no transition:all, no bare durations, no ease-in, UI ≤300ms, press scale on buttons, hover gated by (hover:hover) and (pointer:fine) with :focus-visible parity, reduced-motion present and correct; (3) mobile baseline: 16px inputs on coarse pointers, 44px targets, tap highlight, dvh/svh; (4) base-element styles that would clobber existing page CSS in surprising ways (overly broad selectors, high specificity, !important), or naming that collides with class names already used in pages (grep the pages: ${ROOT}/*.php ${ROOT}/admin/*.php ${ROOT}/customer/*.php — collisions like an existing .card/.btn/.badge with different meaning are a migration hazard, say how to resolve); (5) icons.php correctness: escaping, function_exists guard, php -l, every icon name referenced in DESIGN.md exists; (6) DESIGN.md relative-path instructions are correct for root vs admin/ vs customer/ pages under Apache at /arves-house/.
Read-only. Return issues with concrete fixes.` },
  { key: 'visual', prompt: `You are a senior visual designer critiquing a new design system by LOOKING at it.
Context: ${S}/BRIEF.md. The style guide is served at http://127.0.0.1:8801/__scratch/preview.php (source ${S}/preview.php, CSS ${ROOT}/assets/css/theme.css).
Take fresh screenshots with Bash and Read them:
  ${shotCmd('http://127.0.0.1:8801/__scratch/preview.php', `${S}/shots/critic-desktop.png`, 1440, 3600)}
  ${shotCmd('http://127.0.0.1:8801/__scratch/preview.php', `${S}/shots/critic-mobile.png`, 390, 5200)}
(If a page is taller than the shot, take additional shots of scrolled sections by adding a #anchor or temporarily a larger height.)
Compare with the current site (${S}/shots/before/index-desktop.png, customer_dashboard-desktop.png, admin_dashboard-desktop.png, payment-desktop.png).
Judge: Is it clearly better than before while still recognizably the Arve's House brand? Does it feel like one product across customer and admin? Typography quality (sizes, weights, line-height, font actually loading — if it looks like a fallback font, say so), spacing rhythm, shadow/border subtlety, button hierarchy, badge legibility, table readability, the room placeholder, the admin sidebar. Inspect EVERY icon in the icon grid at zoom: flag any that render broken, clipped, blank, or wrong for their name. Mobile: overflow, cramped spacing, tap targets.
Read-only (don't edit theme.css). Return issues with concrete fixes (exact token/selector changes).` },
]

const revisePrompt = (crit) => `You are the design lead. Critics reviewed your design system. Resolve every blocker and major issue, and minor ones where cheap.
Files you own: ${ROOT}/assets/css/theme.css, ${ROOT}/includes/icons.php, ${S}/DESIGN.md, ${S}/preview.php. Context: ${S}/BRIEF.md, ${S}/STANDARD.md.
Critiques (JSON): ${JSON.stringify(crit)}
After fixing, re-screenshot the preview (desktop + mobile) and Read them to confirm:
  ${shotCmd('http://127.0.0.1:8801/__scratch/preview.php', `${S}/shots/preview-desktop.png`, 1440, 3600)}
  ${shotCmd('http://127.0.0.1:8801/__scratch/preview.php', `${S}/shots/preview-mobile.png`, 390, 5200)}
Run ${PHP} -l on icons.php and preview.php. Make sure DESIGN.md reflects the final class names/tokens.
Return: what you changed per critique item (or why not), and the final palette/fonts summary.`

phase('Audit')
const audits = (await parallel(GROUPS.map(g => () =>
  agent(auditPrompt(g), { label: `audit:${g.key}`, phase: 'Audit', schema: AUDIT_SCHEMA })
))).filter(Boolean).flatMap(r => r.pages)
log(`Audited ${audits.length} pages, ${audits.reduce((n, p) => n + p.issues.length, 0)} issues`)

phase('Design')
const design = await agent(designPrompt(audits), { label: 'design-lead', phase: 'Design' })

phase('Critique')
const crit = (await parallel(critics.map(c => () =>
  agent(c.prompt, { label: `critic:${c.key}`, phase: 'Critique', schema: CRITIQUE_SCHEMA }).then(r => r && ({ critic: c.key, ...r }))
))).filter(Boolean)

phase('Revise')
const revision = await agent(revisePrompt(crit), { label: 'design-lead:revise', phase: 'Revise' })

return { auditCount: audits.length, audits, design, crit, revision }

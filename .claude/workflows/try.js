export const meta = {
  name: 'apply-emil-skills',
  description: 'Apply Emil Kowalski design-engineering skills (motion + mobile-native) to every Arve\'s House page, then strictly review each',
  phases: [
    { title: 'Apply', detail: 'one agent per page unit applies STANDARD.md' },
    { title: 'Review', detail: 'strict reviewer diffs vs originals, lints PHP, fixes misses' },
  ],
}

const ROOT = 'C:/xampp/htdocs/arves-house'
const S = 'C:/Users/ASUSTU~1/AppData/Local/Temp/claude/C--xampp-htdocs-arves-house/99d2b0ed-1c2d-454e-b1be-37bcaf5ad351/scratchpad'
const PHP = 'C:/xampp/php/php.exe'

const UNITS = [
  { key: 'index', files: ['index.php'], kind: 'customer-facing marketing homepage' },
  { key: 'indexagoda', files: ['indexagoda.php'], kind: 'customer-facing marketing homepage (alternate variant)' },
  { key: 'rooms', files: ['rooms.php'], kind: 'customer-facing room listing / browsing page' },
  { key: 'reservation', files: ['reservation.php'], kind: 'customer booking form flow' },
  { key: 'payment', files: ['payment.php'], kind: 'customer payment form flow' },
  { key: 'reservation_success', files: ['reservation_success.php'], kind: 'customer booking confirmation (rare, can have a little delight)' },
  { key: 'auth', files: ['login.php', 'register.php'], kind: 'customer login and registration forms' },
  { key: 'customer_dashboard', files: ['customer/dashboard.php'], kind: 'customer account dashboard (app shell)' },
  { key: 'customer_profile', files: ['customer/profile.php'], kind: 'customer profile / settings (app shell, forms, image upload)' },
  { key: 'admin_dashboard', files: ['admin/dashboard.php'], kind: 'admin dashboard (daily-use tool: crisp, fast, minimal motion)' },
  { key: 'admin_reservations', files: ['admin/reservations.php'], kind: 'admin reservations management (daily-use tool)' },
  { key: 'admin_payment', files: ['admin/payment.php'], kind: 'admin payments management (daily-use tool)' },
  { key: 'admin_rooms', files: ['admin/rooms.php'], kind: 'admin room CRUD (daily-use tool)' },
  { key: 'admin_misc', files: ['admin/login.php', 'admin/customers.php', 'admin/site_settings.php'], kind: 'admin login, customer list, site settings (daily-use tool)' },
]

const CHANGES_SCHEMA = {
  type: 'object',
  properties: {
    changes: { type: 'array', items: { type: 'object', properties: {
      file: { type: 'string' }, before: { type: 'string' }, after: { type: 'string' }, why: { type: 'string' },
    }, required: ['file', 'before', 'after', 'why'] } },
    added_motion: { type: 'array', items: { type: 'string' }, description: 'new animations added and their purpose' },
    rejected_motion: { type: 'array', items: { type: 'string' }, description: 'things deliberately NOT animated and why' },
    needs_device: { type: 'array', items: { type: 'string' } },
    php_lint: { type: 'string', description: 'output of php -l for each file' },
  },
  required: ['changes', 'needs_device', 'php_lint'],
}

const REVIEW_SCHEMA = {
  type: 'object',
  properties: {
    issues: { type: 'array', items: { type: 'object', properties: {
      file: { type: 'string' }, problem: { type: 'string' }, fix: { type: 'string' }, fixed: { type: 'boolean' },
    }, required: ['file', 'problem', 'fixed'] } },
    php_lint_ok: { type: 'boolean' },
    php_logic_untouched: { type: 'boolean', description: 'true only if every diff hunk is CSS/HTML-attribute/meta/UI-JS, with no PHP/SQL/form-name/ID changes' },
    remaining_bare_transitions: { type: 'integer' },
    verdict: { type: 'string', enum: ['pass', 'pass-after-fixes', 'fail'] },
    summary: { type: 'string' },
  },
  required: ['issues', 'php_lint_ok', 'php_logic_untouched', 'remaining_bare_transitions', 'verdict', 'summary'],
}

const fileList = u => u.files.map(f => `${ROOT}/${f}`).join('\n  ')

const applyPrompt = u => `You are a senior design engineer applying Emil Kowalski's design-engineering skills to one part of a PHP hotel-booking site ("Arve's House", XAMPP, Windows).

Files you own (edit ONLY these):
  ${fileList(u)}
Page type: ${u.kind}

1. Read the binding standard first: ${S}/STANDARD.md  — follow it exactly, especially "0. Scope guard".
2. Skim the source skills it references (under ${ROOT}/.claude/skills/) for the reasoning — at minimum emil-design-eng/SKILL.md, mobile-native/SKILL.md, and review-animations/STANDARDS.md.
3. Read each owned file fully (they are long; read in chunks). Understand its <style> block, its HTML, and any <script> (modals, dropdowns, tabs, sidebars toggled by JS).
4. Apply the standard with the Edit tool:
   - motion tokens in :root; replace EVERY bare/"all" transition with explicit properties + curve; press feedback on real controls; gate transform/background/shadow hovers behind (hover: hover) and (pointer: fine) with :focus-visible parity; mobile baseline (viewport-fit=cover, tap highlight, text-size-adjust, touch-action, user-select on controls only, 16px inputs on coarse pointers, 100vh→svh/dvh with fallback, overscroll-behavior: contain on inner scrollers, safe-area padding on fixed/sticky bars); a page-specific prefers-reduced-motion block at the end of <style>.
   - Then run the find-animation-opportunities lens (section 7): add only purposeful, occasional motion appropriate to this page type (modal/dropdown/alert entrances; for marketing pages a one-time hero entrance + short card stagger). Record what you rejected and why.
5. Do NOT touch PHP code, SQL, form names/actions, IDs/classes that JS or PHP use. Do NOT restyle (colors/layout/typography stay). If a JS-toggled element uses display:none, prefer @starting-style or add a class-based transition WITHOUT changing the JS contract; only minimally touch JS if needed and keep behavior identical.
6. Verify: run \`${PHP} -l <file>\` for each file (Bash tool) and \`diff -u "${S}/orig/<relpath>" "${ROOT}/<relpath>"\` to self-check that every hunk is presentation-only. Also run \`grep -nE "transition: *[0-9.]+m?s *;|transition: *all" <file>\` and make it return nothing.
Return the structured result: every change as a Before/After/Why row (keep each cell short), added/rejected motion, what needs a real phone to confirm, and the php -l output.`

const reviewPrompt = (u, applied) => `You are a strict animation & mobile-craft reviewer (Emil Kowalski's review-animations skill: default to flagging; approval is earned). Another agent just applied ${S}/STANDARD.md to these files:
  ${fileList(u)}
Page type: ${u.kind}

The applier reported ${applied ? applied.changes.length : 'an unknown number of'} changes. Do not trust the report — check the code.

1. Read ${S}/STANDARD.md and ${ROOT}/.claude/skills/review-animations/STANDARDS.md.
2. For each file run \`diff -u "${S}/orig/<relpath>" "${ROOT}/<relpath>"\` (Bash) and read every hunk. Also read the full current <style> block and any <script>.
3. Hunt for defects, including:
   - PHP-safety: any hunk touching <?php ?> code, SQL, form name/action, input name, IDs/classes referenced by JS, redirects. php -l must pass (\`${PHP} -l <file>\`).
   - Leftover \`transition: all\` or bare-duration transitions (grep: \`grep -nE "transition: *[0-9.]+m?s *;|transition: *all|transition:[^;]*ease-in[^-]" <file>\`), ease-in, >300ms UI durations, scale(0), animating layout props.
   - Hover gating that changed desktop specificity/order so the desktop look is different, or hover feedback lost for keyboard users (missing :focus-visible parity).
   - :active scale missing on real buttons, applied to disabled buttons, or clobbering an existing transform.
   - Transitions that no longer include a property that was previously transitioning (regression: e.g. color no longer fades).
   - Reduced-motion block missing, or blanket-killing all transitions, or not covering this page's actual transform-producing selectors.
   - Mobile baseline gaps: viewport-fit=cover, tap-highlight, 16px inputs on coarse pointers, 100vh without svh/dvh, user-select:none on content text, safe-area on fixed bars.
   - Added motion that is decorative on frequently-used UI, hides content if animation doesn't run, uses keyframes for retriggerable UI, or breaks existing JS toggles (e.g. @starting-style on something whose display is toggled but the transition never runs / element stuck invisible).
   - Broken CSS syntax (unbalanced braces, nested media queries in wrong place) — count braces in the <style> block.
4. FIX every real defect directly with the Edit tool (you own these files now). Re-run php -l and the grep after fixing.
Return the structured verdict. Set php_logic_untouched=true only if you confirmed every hunk is presentation-only.`

phase('Apply')
const results = await pipeline(
  UNITS,
  u => agent(applyPrompt(u), { label: `apply:${u.key}`, phase: 'Apply', schema: CHANGES_SCHEMA }),
  (applied, u) => agent(reviewPrompt(u, applied), { label: `review:${u.key}`, phase: 'Review', schema: REVIEW_SCHEMA })
    .then(review => ({ key: u.key, files: u.files, applied, review })),
)

const done = results.filter(Boolean)
const failed = UNITS.filter(u => !done.find(d => d.key === u.key)).map(u => u.key)
if (failed.length) log(`Units with no result: ${failed.join(', ')}`)
return { done, failed }

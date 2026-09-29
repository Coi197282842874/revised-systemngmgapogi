export const meta = {
  name: 'verify-email-google-auth',
  description: 'Adversarially review the new email verification + Google sign-in for security and correctness (2 agents, CLI only)',
  phases: [{ title: 'Review', detail: 'security reviewer + correctness/edge-case reviewer, read-only' }],
}

const ROOT = 'C:/xampp/htdocs/arves-house'
const S = 'C:/Users/ASUSTU~1/AppData/Local/Temp/claude/C--xampp-htdocs-arves-house/99d2b0ed-1c2d-454e-b1be-37bcaf5ad351/scratchpad'
const PHP = 'C:/xampp/php/php.exe'

const FINDINGS = {
  type: 'object',
  properties: {
    issues: { type: 'array', items: { type: 'object', properties: {
      severity: { type: 'string', enum: ['blocker', 'major', 'minor'] },
      file: { type: 'string' }, line: { type: 'integer' },
      problem: { type: 'string' }, evidence: { type: 'string', description: 'how you confirmed it: test output, trace, or exact code path' },
      fix: { type: 'string' }, preexisting: { type: 'boolean', description: 'true if the problem existed before this feature' },
    }, required: ['severity', 'file', 'problem', 'evidence', 'fix', 'preexisting'] } },
    verified_ok: { type: 'array', items: { type: 'string' }, description: 'things you specifically tried to break and could not' },
  },
  required: ['issues', 'verified_ok'],
}

const CONTEXT = `Feature just added to "ARVE'S House" (plain PHP 8.2 + PDO/MySQL, XAMPP on Windows; will be deployed to a typical shared Linux host):
- includes/auth.php — settings (config/auth.php), schema migration ensure_auth_schema (adds users.email_verified/email_verified_at/google_id, table email_verifications; grandfathers existing users as verified), safe_redirect, login_user, CSRF, 6-digit email codes (issue_verification_code / check_verification_code, PHPMailer in lib/PHPMailer, dev fallback logs to temp dir when mail unconfigured), Google OAuth/OIDC (google_authorization_url, google_exchange_code, google_id_token_claims, google_handle_callback).
- google-login.php, google-callback.php, verify-email.php (new pages); includes/google-button.php (button partial).
- login.php (customers with email_verified=0 are sent to verify-email.php instead of being logged in; JSON path for the floating login in includes/login-modal.php), register.php (creates unverified user, emails code, goes to verify page).
- Admins are exempt from verification and may NOT sign in with Google. Existing accounts were marked verified.
- config/auth.sample.php documents setup; config/auth.php is the local copy (empty = Google button hidden, codes logged to %TEMP%/arves-house-mail.log).
Code: ${ROOT}. Existing tests you can run and extend (copy into ${S}/review/ first; don't edit the originals): ${S}/test-auth.php (50 unit checks; uses throwaway users @example.invalid and deletes them), ${S}/test-page.php (runs ONE page request in-process with a persistent file session: env PAGE, METHOD, SID_IN, POST_JSON, GET_JSON, XRW, ARVES_AUTH_CONFIG, HTML_OUT; prints session keys/JSON/alerts). Run with ${PHP} -d display_errors=stderr.
The web dev servers are OFF (machine is low on memory) — do NOT start servers or browsers; test in-process with the PHP CLI only.
Database rules: you may create throwaway users ONLY with emails ending @example.invalid and MUST delete them before finishing; never modify or delete other rows; never change schema.
Read-only on project files — report, don't fix.`

const REVIEWERS = [
  { key: 'security', prompt: `You are an application-security reviewer. ${CONTEXT}
Try to break it. At minimum: account takeover paths (Google linking to existing accounts incl. unverified pre-hijack, admin accounts, email case/Unicode/whitespace variants, a Google account whose email later changes, sub vs email trust), verification-code brute force (attempt counting under concurrency, resend resetting attempts → effectively unlimited guesses? compute the real guess budget per hour), code entropy/storage, timing leaks, user enumeration (register "already exists", login unverified vs wrong password, resend responses), CSRF on every state-changing request (verify, resend, cancel via GET, google-login GET), OAuth state/nonce handling, redirect_uri computation from Host / X-Forwarded-Proto headers (host header injection → does it matter?), open redirects through pending_redirect / google state, session fixation across register→verify→login, session state left behind (pending ids letting someone else verify?), XSS in all new output (names from Google, flash messages, masked email), header/mail injection via name/email into PHPMailer, secrets exposure (config/auth.php, mail log location, error messages), the ID-token-without-signature decision (is TLS-direct justification valid as implemented? any way an attacker controls tokens?), and what happens on a shared host where config/auth.php might be web-readable.
Say which issues are pre-existing vs introduced. Give concrete evidence for each finding.` },
  { key: 'correctness', prompt: `You are a senior PHP engineer reviewing correctness, robustness and UX edge cases. ${CONTEXT}
Try to break it. At minimum: the migration on a live DB (idempotency, concurrent first requests, MySQL/MariaDB version differences e.g. ADD COLUMN ... AFTER with two columns in one ALTER, UNIQUE on nullable google_id, the grandfather UPDATE running only once, what if users table has rows created by create_admin.php without phone), timezone handling (gmdate/UTC_TIMESTAMP vs strtotime ... UTC, MySQL session time_zone), REPLACE INTO semantics with the FK, nested transactions (check_verification_code begins a transaction — any caller already in one?), register → verify → login full flows incl. refresh/back-button/double-submit, a user who registers then closes the tab and comes back days later (login → gets a code?), resend rate-limit math, the floating login JSON path for unverified users, Google users with empty phone hitting pages that expect a phone (grep reservation/payment/admin pages for phone usage), Google-created account name/email length limits vs column sizes, PHPMailer include paths and failure handling when SMTP is blocked (timeouts → page hangs how long?), PHP 8.0/8.1 compatibility on shared hosts (str_contains, etc. — list the minimum PHP version the code actually needs), mb_* availability, what the verify page does when mail is unconfigured on a non-local host, and HTML/a11y of the new pages (labels, autocomplete=one-time-code, autofocus + auto-submit behavior, error announcement).
Give concrete evidence (test output or exact code path) for each finding.` },
]

phase('Review')
const results = await parallel(REVIEWERS.map(r => () =>
  agent(r.prompt, { label: `review:${r.key}`, phase: 'Review', schema: FINDINGS }).then(x => x && ({ reviewer: r.key, ...x }))
))
return results.filter(Boolean)

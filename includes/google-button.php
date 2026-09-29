<?php
/*
 * "Continue with Google" button followed by an "or" divider.
 * Renders nothing until Google sign-in is configured in config/auth.php.
 * Optional: set $googleRedirect before including to return there after sign-in.
 */

require_once __DIR__ . "/auth.php";

if (!google_enabled()) {
    return;
}

$googleHref = "google-login.php" . (!empty($googleRedirect) ? "?redirect=" . urlencode($googleRedirect) : "");
?>

<a class="gbtn" href="<?= htmlspecialchars($googleHref) ?>" data-google-login>
    <svg class="gbtn-logo" viewBox="0 0 48 48" width="20" height="20" aria-hidden="true">
        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
    </svg>
    <span>Continue with Google</span>
</a>

<p class="gbtn-terms">By continuing with Google, you agree to the <a href="#terms">Terms and Conditions</a>.</p>

<div class="auth-or" role="separator"><span>or</span></div>

<?php if (empty($GLOBALS["__google_button_css"])): $GLOBALS["__google_button_css"] = true; ?>
<style>
    .gbtn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        width: 100%;
        height: 48px;
        padding: 0 16px;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: inset 0 0 0 1px #747775;
        color: #1f1f1f;
        font: inherit;
        font-size: 15px;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
        touch-action: manipulation;
        -webkit-user-select: none;
        user-select: none;
        transition:
            background-color 180ms ease,
            transform 140ms cubic-bezier(0.23, 1, 0.32, 1);
    }

    .gbtn:active {
        transform: scale(0.97);
        background: #f1f3f4;
    }

    .gbtn:focus-visible {
        outline: 2px solid #6f4e37;
        outline-offset: 3px;
    }

    .gbtn-logo {
        flex: none;
    }

    @media (hover: hover) and (pointer: fine) {
        .gbtn:hover {
            background: #f7f8f8;
        }
    }

    .gbtn-terms {
        margin: 10px 0 0;
        font-size: 12px;
        line-height: 1.45;
        text-align: center;
        color: #6b5f58;
    }

    .gbtn-terms a {
        color: #6f4e37;
        font-weight: 700;
        text-decoration: underline;
        text-underline-offset: 2px;
    }

    .auth-or {
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 18px 0;
        font-size: 13px;
        color: #6b5f58;
    }

    .auth-or::before,
    .auth-or::after {
        content: "";
        flex: 1;
        height: 1px;
        background: rgba(36, 26, 21, 0.14);
    }

    @media (prefers-reduced-motion: reduce) {
        .gbtn:active {
            transform: none;
        }
    }
</style>
<?php endif; ?>

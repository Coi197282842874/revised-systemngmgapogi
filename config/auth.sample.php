<?php
/*
 * ARVE'S House — sign-in settings.
 * Copy this file to config/auth.php and fill it in (on your computer AND on the live host).
 * Keep config/auth.php private: it holds secrets.
 *
 * GOOGLE ("Continue with Google")
 *   1. https://console.cloud.google.com/ → create or pick a project.
 *   2. APIs & Services → OAuth consent screen: User type "External", app name "ARVE'S House",
 *      support email; add scopes openid, email, profile; publish the app ("In production")
 *      so anyone with a Google account can sign in.
 *   3. APIs & Services → Credentials → Create credentials → OAuth client ID → "Web application".
 *      Authorized redirect URIs — add one per place the site runs, exactly:
 *        http://localhost/arves-house/google-callback.php
 *        https://YOUR-DOMAIN/google-callback.php            (or /subfolder/google-callback.php)
 *   4. Paste the Client ID and Client secret below.
 *   The button stays hidden until both are filled in.
 *
 * EMAIL CODES (sent from a Gmail account)
 *   1. On that Google account turn on 2-Step Verification (myaccount.google.com → Security).
 *   2. Create an App Password: myaccount.google.com/apppasswords → name it "ARVE'S House".
 *   3. Put the Gmail address in "username" and the 16-character app password in "password".
 *   Until this is filled in, codes are NOT emailed: they are written to arves-house-mail.log in the
 *   system temp folder (for local testing only).
 *   Some free hosts block outgoing SMTP; if codes never arrive online, ask the host which SMTP
 *   server to use and put it in "host"/"port".
 */

return [

    "google" => [
        "client_id" => "",          // e.g. 1234567890-abc123.apps.googleusercontent.com
        "client_secret" => "",      // e.g. GOCSPX-...
        "redirect_uri" => "",       // leave empty: worked out automatically from the site's URL
    ],

    "mail" => [
        "host" => "smtp.gmail.com",
        "port" => 587,
        "encryption" => "tls",      // "tls" for port 587, "ssl" for port 465
        "username" => "",           // your Gmail address, e.g. arveshouse@gmail.com
        "password" => "",           // the 16-character App Password (not your normal Gmail password)
        "from_email" => "",         // leave empty to send from the username address
        "from_name" => "ARVE'S House",
    ],

    // PayMongo online payments (GCash, Maya, GrabPay, cards).
    // dashboard.paymongo.com → Developers → API Keys. sk_test_... = test mode, sk_live_... = real money.
    // The "Pay Online" option stays hidden until secret_key is filled in.
    "paymongo" => [
        "secret_key" => "",
    ],

    // A reservation that still has no payment after this many hours is cancelled automatically,
    // so the dates open up for other guests. 0 = never cancel automatically.
    "booking" => [
        "unpaid_hold_hours" => 24,
    ],

    // Emails about bookings, payments and messages.
    "notifications" => [
        "enabled" => true,
        "admin_email" => "",        // where YOUR notifications go; leave empty to use the mail username above
    ],

    // Shown on receipts and to Google. Fill in what you want public; empty fields are left out.
    "business" => [
        "name" => "ARVE'S House",
        "tagline" => "Your Home Away From Home",
        "phone" => "",              // e.g. +63 917 123 4567
        "email" => "",
        "street" => "",
        "city" => "",
        "province" => "",
        "postal_code" => "",
        "country" => "PH",
        "map_url" => "",            // Google Maps link to the property
        "facebook_url" => "",
    ],

];

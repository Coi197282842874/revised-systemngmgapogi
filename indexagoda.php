<?php
session_start();

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/icons.php";

$isLoggedIn = isset($_SESSION["user_id"]);
$isCustomer = $isLoggedIn && ($_SESSION["role"] ?? "") === "customer";
$isAdmin = $isLoggedIn && ($_SESSION["role"] ?? "") === "admin";

$stmt = $pdo->query("
    SELECT *
    FROM rooms
    WHERE status = 'available'
    ORDER BY id DESC
    LIMIT 3
");
$rooms = $stmt->fetchAll();

$customerRequired = isset($_GET["customer_required"]);

// ======================================================
// HOMEPAGE EDITABLE SETTINGS
// ======================================================

$siteSettings = [
    "hero_card_icon" => "🏡",
    "hero_card_title" => "ARVE'S House",
    "hero_card_subtitle" => "Simple stay. Greater memories.",
    "hero_card_bg_start" => "#c9a27f",
    "hero_card_bg_end" => "#7b5841"
];

try {
    $stmt = $pdo->query("
        SELECT setting_key, setting_value
        FROM site_settings
        WHERE setting_key IN (
            'hero_card_icon',
            'hero_card_title',
            'hero_card_subtitle',
            'hero_card_bg_start',
            'hero_card_bg_end'
        )
    ");

    foreach ($stmt->fetchAll() as $setting) {
        if (array_key_exists($setting["setting_key"], $siteSettings)) {
            $siteSettings[$setting["setting_key"]] = $setting["setting_value"];
        }
    }
} catch (PDOException $e) {
    // Keep defaults until the site_settings table is created.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#fffaf4">
<title>ARVE'S House | Transient & Reservation</title>

<style>
*{margin:0;padding:0;box-sizing:border-box}
html{
    scroll-behavior:smooth;
    -webkit-tap-highlight-color:transparent;
    -webkit-text-size-adjust:100%;
    text-size-adjust:100%;
}
:root{
    --ease-out:cubic-bezier(0.23, 1, 0.32, 1);
    --ease-in-out:cubic-bezier(0.77, 0, 0.175, 1);
    --ease-drawer:cubic-bezier(0.32, 0.72, 0, 1);
    --cream:#fffaf4;
    --cream2:#f4e7d9;
    --brown:#7a4f36;
    --brown2:#5a3826;
    --brown3:#2d1d16;
    --gold:#d4a76a;
    --text:#241a15;
    --muted:#786d66;
    --white:rgba(255,255,255,.93);
    --border:rgba(122,79,54,.14);
    --shadow:0 18px 50px rgba(76,50,34,.10);
}
body{
    font-family:Arial,Helvetica,sans-serif;
    color:var(--text);
    line-height:1.6;
    background:
        radial-gradient(circle at 7% 10%,rgba(212,167,106,.24),transparent 28%),
        radial-gradient(circle at 92% 40%,rgba(122,79,54,.12),transparent 28%),
        linear-gradient(135deg,#fffdf9 0%,#f8f0e6 48%,#eee0d1 100%);
    min-height:100vh;
    min-height:100svh;
}
a{text-decoration:none;color:inherit}
button,input{font:inherit}
img{max-width:100%;height:auto}
button,a,input{touch-action:manipulation}
button,a{ -webkit-tap-highlight-color:transparent }
button,.btn,.room-btn,.drawer-action,.mobile-bottom-nav a,
.nav-links .login-btn,.nav-links .register-btn{
    -webkit-user-select:none;
    user-select:none;
}
input[type="date"]{min-height:44px}
body.menu-open{overflow:hidden}

/* NAV */
.navbar{
    height:78px;
    padding:0 7%;
    display:flex;
    align-items:center;
    justify-content:space-between;
    position:sticky;
    top:0;
    z-index:1000;
    background:rgba(255,250,244,.90);
    backdrop-filter:blur(16px);
    border-bottom:1px solid var(--border);
}
.logo{display:flex;align-items:center;gap:12px;font-weight:800;font-size:21px}
.logo-mark{
    width:43px;height:43px;border-radius:13px;
    display:grid;place-items:center;
    background:linear-gradient(135deg,var(--brown),var(--brown2));
    color:#fff;font-size:21px;
    box-shadow:0 8px 20px rgba(90,56,38,.20);
}
.logo small{display:block;font-size:9px;font-weight:500;letter-spacing:1.2px;color:var(--muted)}
.nav-links{display:flex;align-items:center;gap:6px}
.nav-links a{padding:9px 13px;border-radius:9px;font-size:13px;font-weight:600}
@media (hover:hover) and (pointer:fine){
    .nav-links a:hover{background:rgba(122,79,54,.08);color:var(--brown)}
}
.nav-links a:focus-visible{background:rgba(122,79,54,.08);color:var(--brown)}
.nav-links .login-btn{border:1px solid var(--border)}
.nav-links .register-btn{
    background:linear-gradient(135deg,var(--brown),var(--brown2));
    color:#fff;padding-left:18px;padding-right:18px;
}
.nav-links .login-btn,
.nav-links .register-btn{transition:transform 140ms var(--ease-out)}
.nav-links .login-btn:active,
.nav-links .register-btn:active{transform:scale(0.97)}
.mobile-menu{
    display:none;border:0;background:transparent;font-size:25px;cursor:pointer;
    transition:transform 140ms var(--ease-out),background-color 180ms ease;
}
.mobile-menu:active{transform:scale(0.97)}

/* HERO */
.hero{
    min-height:600px;
    padding:75px 7% 95px;
    display:flex;
    align-items:center;
    position:relative;
    overflow:hidden;
}
.hero:before{
    content:"";position:absolute;width:520px;height:520px;border-radius:50%;
    left:-210px;top:-270px;border:70px solid rgba(212,167,106,.11);
}
.hero:after{
    content:"";position:absolute;width:420px;height:420px;border-radius:50%;
    right:-170px;bottom:-190px;background:rgba(122,79,54,.06);
}
.hero-inner{
    max-width:1180px;width:100%;margin:auto;position:relative;z-index:2;
    display:grid;grid-template-columns:1.15fr .85fr;gap:70px;align-items:center;
}
.eyebrow{
    display:inline-block;color:var(--brown);font-size:11px;font-weight:800;
    letter-spacing:4px;text-transform:uppercase;margin-bottom:17px;
}
.hero h1{
    font-family:Georgia,"Times New Roman",serif;
    font-size:clamp(48px,6vw,78px);
    line-height:1.03;letter-spacing:-2px;margin-bottom:22px;
}
.hero h1 span{color:var(--brown)}
.hero p{max-width:610px;color:var(--muted);font-size:17px;margin-bottom:30px}
.hero-buttons{display:flex;gap:12px;flex-wrap:wrap}
.btn{
    display:inline-flex;align-items:center;justify-content:center;
    min-height:48px;padding:0 23px;border-radius:11px;font-size:13px;font-weight:800;
    transition:transform 140ms var(--ease-out);border:1px solid transparent;
}
@media (hover:hover) and (pointer:fine){
    .btn:hover{transform:translateY(-2px)}
}
.btn:active{transform:translateY(0) scale(0.97)}
.btn-primary{background:linear-gradient(135deg,var(--brown),var(--brown2));color:#fff;box-shadow:0 10px 25px rgba(90,56,38,.20)}
.btn-secondary{background:rgba(255,255,255,.62);border-color:var(--border);color:var(--brown3)}
.hero-card{
    background:rgba(255,255,255,.72);backdrop-filter:blur(16px);
    border:1px solid rgba(255,255,255,.8);border-radius:28px;padding:30px;
    box-shadow:var(--shadow);transform:rotate(1deg);
}
.hero-card-inner{
    min-height:315px;border-radius:20px;
    background:linear-gradient(
        145deg,
        <?= htmlspecialchars($siteSettings["hero_card_bg_start"]) ?>,
        <?= htmlspecialchars($siteSettings["hero_card_bg_end"]) ?>
    );
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    color:#fff;text-align:center;padding:30px;
}
.hero-card-inner .house{font-size:95px;line-height:1;margin-bottom:12px}
.hero-card-inner h2{
    font-family:Georgia,"Times New Roman",serif;
    font-size:30px;
    margin:0 0 6px;
    color:#fff;
}
.hero-card-inner p{
    font-size:12px;
    opacity:.88;
    margin:0;
}

/* SEARCH */
.search-wrap{max-width:1060px;margin:-42px auto 0;padding:0 20px;position:relative;z-index:5}
.search-card{
    background:var(--white);border:1px solid rgba(255,255,255,.9);border-radius:18px;
    box-shadow:var(--shadow);padding:18px;display:grid;grid-template-columns:1fr 1fr auto;
    gap:14px;align-items:end;backdrop-filter:blur(14px);
}
.search-field{padding:0 14px;border-right:1px solid var(--border)}
.search-field label{display:block;font-size:11px;font-weight:800;margin-bottom:7px;color:var(--brown3)}
.search-field input{width:100%;border:0;background:transparent;outline:0;color:var(--muted)}
.search-btn{
    height:52px;border:0;border-radius:11px;padding:0 28px;cursor:pointer;
    background:linear-gradient(135deg,var(--brown),var(--brown2));color:#fff;font-weight:800;
    transition:transform 140ms var(--ease-out);
}
.search-btn:active{transform:scale(0.97)}

/* ALERT */
.alert-wrapper{max-width:1180px;margin:28px auto 0;padding:0 20px}
.alert{
    background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;
    border-radius:12px;padding:14px 18px;display:flex;justify-content:space-between;gap:15px
}
.alert a{font-weight:800;text-decoration:underline}

/* SECTIONS */
.section{padding:90px 7%}
.section-inner{max-width:1180px;margin:auto}
.section-heading{text-align:center;max-width:720px;margin:0 auto 46px}
.section-heading span{color:var(--brown);font-size:10px;font-weight:800;letter-spacing:3px;text-transform:uppercase}
.section-heading h2{font-family:Georgia,"Times New Roman",serif;font-size:clamp(34px,4vw,48px);line-height:1.15;margin:8px 0 12px}
.section-heading p{color:var(--muted);font-size:14px}

/* FEATURES */
.features{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
.feature-card{
    background:rgba(255,255,255,.76);backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,.8);
    border-radius:20px;padding:28px;text-align:center;box-shadow:0 12px 35px rgba(76,50,34,.06)
}
.feature-icon{
    width:58px;height:58px;margin:0 auto 16px;border-radius:17px;display:grid;place-items:center;
    background:linear-gradient(135deg,#f6e6d3,#ead1b4);font-size:25px
}
.feature-card h3{font-size:16px;margin-bottom:7px}
.feature-card p{font-size:13px;color:var(--muted)}

/* ROOMS */
.rooms-section{background:rgba(255,255,255,.34)}
.rooms-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}
.room-card{
    background:rgba(255,255,255,.92);border:1px solid rgba(255,255,255,.85);
    border-radius:20px;overflow:hidden;box-shadow:var(--shadow);transition:transform 180ms var(--ease-out)
}
@media (hover:hover) and (pointer:fine){
    .room-card:hover{transform:translateY(-5px)}
}
.room-visual{
    height:220px;background:linear-gradient(145deg,#c5a07f,#74513b);
    display:grid;place-items:center;color:white;position:relative
}
.room-visual .bed{font-size:70px}
.room-badge{
    position:absolute;top:14px;right:14px;background:#fff;color:#456a46;
    border-radius:20px;padding:6px 10px;font-size:10px;font-weight:800
}
.room-body{padding:21px}
.room-head{display:flex;justify-content:space-between;gap:15px;align-items:start;margin-bottom:9px}
.room-name{font-family:Georgia,serif;font-size:21px}
.room-price{font-size:18px;font-weight:800;color:var(--brown);white-space:nowrap}
.room-price small{display:block;font-size:10px;color:var(--muted);font-weight:500;text-align:right}
.room-description{font-size:12px;color:var(--muted);min-height:58px;margin-bottom:14px}
.room-meta{font-size:12px;color:var(--muted);padding:12px 0;border-top:1px solid var(--border);margin-bottom:13px}
.room-btn{
    width:100%;min-height:44px;border:0;border-radius:10px;
    display:flex;align-items:center;justify-content:center;
    background:linear-gradient(135deg,var(--brown),var(--brown2));
    color:#fff;font-size:12px;font-weight:800;cursor:pointer;
    transition:transform 140ms var(--ease-out)
}
.room-btn:active:not(:disabled){transform:scale(0.97)}
.login-to-book{background:linear-gradient(135deg,#b37a50,#7a4f36)}
.room-btn:disabled{background:#d6d0ca;color:#7d756f;cursor:not-allowed}
.view-all{text-align:center;margin-top:34px}

/* ABOUT */
.about-wrap{
    display:grid;grid-template-columns:.9fr 1.1fr;gap:55px;align-items:center;
    max-width:1180px;margin:auto
}
.about-visual{
    min-height:390px;border-radius:28px;background:linear-gradient(145deg,#b98e6d,#694936);
    box-shadow:var(--shadow);display:grid;place-items:center;color:#fff;font-size:100px
}
.about-copy .eyebrow{margin-bottom:8px}
.about-copy h2{font-family:Georgia,serif;font-size:clamp(34px,4vw,48px);line-height:1.12;margin-bottom:18px}
.about-copy p{color:var(--muted);margin-bottom:14px}

/* CTA */
.cta{padding:75px 7%}
.cta-box{
    max-width:1180px;margin:auto;border-radius:28px;padding:55px 30px;text-align:center;
    background:linear-gradient(135deg,#7a4f36,#4d3021);color:#fff;
    box-shadow:0 22px 55px rgba(74,46,31,.20)
}
.cta-box h2{font-family:Georgia,serif;font-size:clamp(31px,4vw,45px);margin-bottom:10px}
.cta-box p{max-width:620px;margin:0 auto 22px;color:#eadfd8}
.cta-box .btn{background:#fff;color:var(--brown3)}

/* FOOTER */
footer{padding:45px 7% 25px;background:#2d1d16;color:#d8c9bf}
.footer-inner{max-width:1180px;margin:auto;display:flex;justify-content:space-between;gap:30px;align-items:center}
footer h3{font-family:Georgia,serif;color:#fff;font-size:22px}
footer p{font-size:12px;color:#bdaea5}
.footer-copy{max-width:1180px;margin:28px auto 0;padding-top:20px;border-top:1px solid rgba(255,255,255,.1);font-size:11px;color:#a9988e}

/* RESPONSIVE */
@media(max-width:1050px){
    .navbar{padding:0 5%}
    .hero{padding-left:5%;padding-right:5%}
    .section{padding-left:5%;padding-right:5%}
}

@media(max-width:950px){
    .hero{
        min-height:auto;
        padding-top:58px;
        padding-bottom:88px;
    }
    .hero-inner{
        grid-template-columns:1fr;
        gap:34px;
    }
    .hero-card{
        display:block;
        max-width:560px;
        width:100%;
        margin:0 auto;
        transform:none;
    }
    .hero-card-inner{min-height:250px}
    .hero-card-inner .house{font-size:76px}
    .features,.rooms-grid{grid-template-columns:repeat(2,1fr)}
    .about-wrap{grid-template-columns:1fr;gap:35px}
    .about-visual{min-height:280px}
}

@media(max-width:780px){
    .navbar{
        height:72px;
        padding:0 18px;
    }

    .logo{
        font-size:18px;
        min-width:0;
    }

    .logo-mark{
        width:40px;
        height:40px;
        border-radius:12px;
        flex:0 0 40px;
    }

    .logo small{
        font-size:8px;
        letter-spacing:.8px;
        white-space:nowrap;
    }

    .mobile-menu{
        display:grid;
        place-items:center;
        width:44px;
        height:44px;
        border-radius:10px;
        font-size:24px;
        color:var(--brown3);
        position:relative;
        z-index:1002;
    }

    .mobile-menu:focus-visible{
        background:rgba(122,79,54,.08);
        outline:none;
    }

    @media (hover:hover) and (pointer:fine){
        .mobile-menu:hover{
            background:rgba(122,79,54,.08);
            outline:none;
        }
    }

    .nav-links{
        display:none;
        position:fixed;
        top:72px;
        left:0;
        right:0;
        max-height:calc(100vh - 72px);
        max-height:calc(100dvh - 72px);
        overflow:auto;
        overscroll-behavior:contain;
        padding:16px 18px calc(22px + env(safe-area-inset-bottom, 0px));
        background:rgba(255,250,244,.985);
        backdrop-filter:blur(18px);
        flex-direction:column;
        align-items:stretch;
        gap:7px;
        border-bottom:1px solid var(--border);
        box-shadow:0 16px 35px rgba(76,50,34,.10);
    }

    .nav-links.show{display:flex}

    .nav-links a{
        min-height:46px;
        display:flex;
        align-items:center;
        justify-content:center;
        text-align:center;
        padding:11px 14px;
    }

    .nav-links .register-btn,
    .nav-links .login-btn{
        width:100%;
    }

    .hero{
        padding:46px 18px 80px;
    }

    .hero:before{
        width:320px;
        height:320px;
        left:-180px;
        top:-180px;
        border-width:48px;
    }

    .hero:after{
        width:280px;
        height:280px;
        right:-150px;
        bottom:-100px;
    }

    .hero-inner{
        gap:28px;
    }

    .eyebrow{
        font-size:9px;
        letter-spacing:2.5px;
        margin-bottom:12px;
    }

    .hero h1{
        font-size:clamp(40px,12vw,58px);
        letter-spacing:-1.2px;
        margin-bottom:16px;
    }

    .hero p{
        font-size:15px;
        margin-bottom:23px;
    }

    .hero-buttons{
        display:grid;
        grid-template-columns:1fr;
        gap:10px;
    }

    .hero-buttons .btn{
        width:100%;
        min-height:50px;
    }

    .hero-card{
        max-width:100%;
        padding:16px;
        border-radius:22px;
    }

    .hero-card-inner{
        min-height:215px;
        border-radius:17px;
        padding:22px 18px;
    }

    .hero-card-inner .house{
        font-size:64px;
    }

    .hero-card-inner h2{
        font-size:25px;
    }

    .search-wrap{
        margin:-35px auto 0;
        padding:0 14px;
    }

    .search-card{
        grid-template-columns:1fr;
        gap:0;
        padding:12px;
        border-radius:16px;
    }

    .search-field{
        border-right:0;
        border-bottom:1px solid var(--border);
        padding:12px 8px;
    }

    .search-field input{
        min-height:42px;
        width:100%;
        font-size:16px;
    }

    .search-btn{
        width:100%;
        min-height:50px;
        height:auto;
        margin-top:10px;
        padding:12px 18px;
    }

    .alert-wrapper{
        margin-top:22px;
        padding:0 14px;
    }

    .alert{
        flex-direction:column;
        align-items:flex-start;
        padding:13px 14px;
        font-size:12px;
    }

    .section{
        padding:64px 18px;
    }

    .section-heading{
        margin-bottom:30px;
    }

    .section-heading h2{
        font-size:clamp(30px,9vw,40px);
    }

    .section-heading p{
        font-size:13px;
    }

    .features,.rooms-grid{
        grid-template-columns:1fr;
        gap:16px;
    }

    .feature-card{
        padding:23px 20px;
    }

    .room-card:hover{
        transform:none;
    }

    .room-visual{
        height:210px;
    }

    .room-head{
        gap:10px;
    }

    .room-name{
        font-size:20px;
    }

    .room-price{
        font-size:16px;
    }

    .room-btn{
        min-height:48px;
    }

    .about-wrap{
        gap:25px;
    }

    .about-visual{
        min-height:230px;
        font-size:78px;
        border-radius:22px;
    }

    .about-copy h2{
        font-size:clamp(30px,9vw,40px);
    }

    .about-copy p{
        font-size:14px;
    }

    .about-copy .btn{
        width:100%;
    }

    .cta{
        padding:54px 18px;
    }

    .cta-box{
        padding:38px 20px;
        border-radius:22px;
    }

    .cta-box h2{
        font-size:clamp(28px,9vw,38px);
    }

    .cta-box p{
        font-size:13px;
    }

    .cta-box .btn{
        width:100%;
    }

    footer{
        padding:35px 18px calc(22px + env(safe-area-inset-bottom, 0px));
    }

    .footer-inner{
        flex-direction:column;
        align-items:flex-start;
        gap:16px;
    }

    .footer-copy{
        margin-top:20px;
    }
}

@media(max-width:430px){
    .navbar{padding:0 14px}
    .logo{font-size:16px;gap:9px}
    .logo-mark{
        width:38px;
        height:38px;
        flex-basis:38px;
        font-size:19px;
    }
    .logo small{
        font-size:7px;
        max-width:180px;
        overflow:hidden;
        text-overflow:ellipsis;
    }

    .hero{
        padding-left:14px;
        padding-right:14px;
    }

    .hero h1{
        font-size:clamp(36px,12vw,48px);
    }

    .hero p{
        font-size:14px;
    }

    .hero-card{
        padding:12px;
    }

    .hero-card-inner{
        min-height:190px;
    }

    .hero-card-inner .house{
        font-size:56px;
    }

    .hero-card-inner h2{
        font-size:22px;
    }

    .section{
        padding-left:14px;
        padding-right:14px;
    }

    .room-body{
        padding:18px;
    }

    .room-head{
        flex-direction:column;
        align-items:flex-start;
    }

    .room-price small{
        display:inline;
        margin-left:4px;
    }

    .about-visual{
        min-height:200px;
        font-size:68px;
    }

    .cta{
        padding-left:14px;
        padding-right:14px;
    }
}

@media(max-width:360px){
    .logo small{display:none}
    .hero h1{font-size:34px}
    .hero-card-inner{min-height:175px}
    .section-heading h2,
    .about-copy h2,
    .cta-box h2{font-size:28px}
}

/* ======================================================
   MOBILE APP-LIKE UI / PARTIAL DRAWER
====================================================== */
.mobile-drawer,
.drawer-backdrop,
.mobile-bottom-nav{
    display:none;
}

@media(max-width:780px){

    body{
        padding-bottom:76px;
    }

    .navbar{
        position:sticky;
        top:0;
        height:72px;
        z-index:1200;
        padding:0 16px;
        background:rgba(255,250,244,.96);
        border-bottom:1px solid var(--border);
        box-shadow:0 8px 24px rgba(76,50,34,.06);
    }

    .logo{
        gap:10px;
        font-size:18px;
    }

    .logo-mark{
        width:40px;
        height:40px;
        border-radius:12px;
        flex:0 0 40px;
    }

    .logo small{
        font-size:7px;
        letter-spacing:1px;
    }

    .nav-links{
        display:none !important;
    }

    .mobile-menu{
        display:grid;
        place-items:center;
        width:44px;
        height:44px;
        border:0;
        border-radius:13px;
        background:#f4e8dd;
        color:var(--brown3);
        font-size:23px;
        cursor:pointer;
        position:relative;
        z-index:1302;
    }

    .drawer-backdrop{
        display:block;
        position:fixed;
        inset:0;
        z-index:1290;
        background:rgba(35,24,18,.28);
        backdrop-filter:blur(2px);
        opacity:0;
        visibility:hidden;
        transition:opacity 200ms ease,visibility 200ms ease;
    }

    .drawer-backdrop.show{
        opacity:1;
        visibility:visible;
    }

    .mobile-drawer{
        display:flex;
        flex-direction:column;
        position:fixed;
        top:84px;
        right:12px;
        width:min(82vw,320px);
        max-height:calc(100vh - 104px);
        max-height:calc(100dvh - 104px);
        z-index:1300;
        border-radius:22px;
        overflow:hidden;
        background:rgba(255,252,248,.985);
        border:1px solid rgba(122,79,54,.13);
        box-shadow:0 24px 70px rgba(48,31,21,.24);
        transform:translateX(calc(100% + 26px));
        opacity:0;
        pointer-events:none;
        transition:
            transform 220ms var(--ease-drawer),
            opacity 180ms ease;
    }

    .mobile-drawer.show{
        transform:translateX(0);
        opacity:1;
        pointer-events:auto;
        transition:
            transform 280ms var(--ease-drawer),
            opacity 200ms ease;
    }

    .drawer-head{
        display:flex;
        align-items:center;
        justify-content:space-between;
        padding:18px 18px 12px;
    }

    .drawer-title{
        font-family:Georgia,"Times New Roman",serif;
        font-size:21px;
        font-weight:700;
        color:var(--brown3);
    }

    .drawer-close{
        width:40px;
        height:40px;
        border:0;
        border-radius:12px;
        background:#f5ece4;
        color:var(--brown3);
        font-size:21px;
        cursor:pointer;
        transition:transform 140ms var(--ease-out);
    }

    .drawer-close:active{
        transform:scale(0.97);
    }

    .drawer-menu{
        overflow:auto;
        overscroll-behavior:contain;
        padding:6px 12px 14px;
    }

    .drawer-section{
        padding:8px 6px 5px;
        font-size:9px;
        font-weight:800;
        letter-spacing:2px;
        text-transform:uppercase;
        color:#a18d7f;
    }

    .drawer-link{
        min-height:50px;
        padding:0 13px;
        margin:3px 0;
        border-radius:13px;
        display:flex;
        align-items:center;
        gap:12px;
        color:var(--brown3);
        font-size:14px;
        font-weight:700;
    }

    .drawer-link.active,
    .drawer-link:focus-visible,
    .drawer-link:active{
        background:#f4e7db;
        color:var(--brown);
    }

    @media (hover:hover) and (pointer:fine){
        .drawer-link:hover{
            background:#f4e7db;
            color:var(--brown);
        }
    }

    .drawer-icon{
        width:28px;
        height:28px;
        display:grid;
        place-items:center;
        flex:0 0 28px;
        font-size:18px;
    }

    .drawer-divider{
        height:1px;
        background:var(--border);
        margin:10px 7px;
    }

    .drawer-action{
        display:flex;
        min-height:50px;
        margin:9px 12px 16px;
        align-items:center;
        justify-content:center;
        border-radius:14px;
        background:linear-gradient(135deg,var(--brown),var(--brown2));
        color:white;
        font-size:13px;
        font-weight:800;
        box-shadow:0 10px 22px rgba(90,56,38,.18);
        transition:transform 140ms var(--ease-out);
    }

    .drawer-action:active{
        transform:scale(0.97);
    }

    .hero{
        padding:34px 16px 58px;
        min-height:auto;
    }

    .hero-inner{
        display:block;
    }

    .hero-card{
        display:none;
    }

    .eyebrow{
        margin-bottom:10px;
        font-size:9px;
        letter-spacing:2.8px;
    }

    .hero h1{
        max-width:350px;
        font-size:clamp(39px,12.5vw,54px);
        line-height:1.02;
        margin-bottom:15px;
    }

    .hero p{
        max-width:430px;
        font-size:14px;
        line-height:1.7;
        margin-bottom:22px;
    }

    .hero-buttons{
        grid-template-columns:1fr;
    }

    .search-wrap{
        margin:-30px auto 0;
        padding:0 14px;
    }

    .search-card{
        padding:12px;
        border-radius:18px;
        gap:0;
        background:rgba(255,255,255,.97);
        box-shadow:0 18px 45px rgba(76,50,34,.14);
    }

    .search-field{
        padding:11px 10px;
        border-right:0;
        border-bottom:1px solid var(--border);
    }

    .search-field label{
        color:#9d897c;
        font-size:9px;
        letter-spacing:1.2px;
    }

    .search-field input{
        min-height:36px;
        font-size:16px;
        color:var(--brown3);
    }

    .search-btn{
        margin-top:10px;
        min-height:52px;
        width:100%;
        border-radius:14px;
    }

    .section{
        padding:54px 14px;
    }

    .section-heading{
        text-align:left;
        margin:0 0 22px;
    }

    .section-heading h2{
        font-size:30px;
    }

    .section-heading p{
        font-size:13px;
    }

    .features{
        grid-template-columns:1fr;
    }

    .feature-card{
        text-align:left;
        display:grid;
        grid-template-columns:54px 1fr;
        column-gap:14px;
        padding:18px;
        border-radius:18px;
    }

    .feature-icon{
        grid-row:1 / span 2;
        margin:0;
        width:52px;
        height:52px;
        border-radius:15px;
    }

    .feature-card h3{
        align-self:end;
    }

    .feature-card p{
        align-self:start;
    }

    .rooms-section{
        overflow:hidden;
    }

    .rooms-grid{
        display:flex;
        gap:14px;
        overflow-x:auto;
        overscroll-behavior-x:contain;
        scroll-snap-type:x mandatory;
        padding:3px 2px 14px;
        margin-right:-14px;
        scrollbar-width:none;
    }

    .rooms-grid::-webkit-scrollbar{
        display:none;
    }

    .room-card{
        min-width:min(78vw,300px);
        scroll-snap-align:start;
        border-radius:18px;
    }

    .room-visual{
        height:190px;
    }

    .room-body{
        padding:17px;
    }

    .room-head{
        display:block;
    }

    .room-price{
        margin-top:6px;
        text-align:left;
    }

    .room-price small{
        display:inline;
        margin-left:4px;
    }

    .room-description{
        min-height:0;
    }

    .view-all{
        text-align:left;
        margin-top:18px;
    }

    .view-all .btn{
        min-height:46px;
    }

    .about-wrap{
        display:block;
    }

    .about-visual{
        min-height:220px;
        margin-bottom:25px;
        border-radius:22px;
    }

    .cta{
        padding:35px 14px 54px;
    }

    .cta-box{
        padding:34px 20px;
        border-radius:22px;
    }

    .mobile-bottom-nav{
        display:grid;
        grid-template-columns:repeat(4,1fr);
        position:fixed;
        left:10px;
        right:10px;
        bottom:calc(8px + env(safe-area-inset-bottom, 0px));
        z-index:1180;
        min-height:62px;
        padding:7px;
        border-radius:20px;
        background:rgba(255,252,248,.97);
        border:1px solid rgba(122,79,54,.12);
        box-shadow:0 16px 45px rgba(55,36,24,.18);
        backdrop-filter:blur(18px);
    }

    .mobile-bottom-nav a{
        min-width:0;
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        gap:2px;
        border-radius:14px;
        color:#76675d;
        font-size:9px;
        font-weight:700;
        transition:transform 140ms var(--ease-out);
    }

    .mobile-bottom-nav a:active{
        transform:scale(0.97);
    }

    .mobile-bottom-nav a.active{
        color:var(--brown);
        background:#f4e8dc;
    }

    .bottom-icon{
        font-size:19px;
        line-height:1;
    }
}

@media(max-width:360px){
    .mobile-drawer{
        width:86vw;
        right:8px;
    }

    .hero h1{
        font-size:35px;
    }
}

</style>
<?php require __DIR__ . "/includes/glass.php"; ?>
</head>

<body>

<nav class="navbar">
    <a href="index.php" class="logo">
        <span class="logo-mark"><?= icon("home") ?></span>
        <span>
            ARVE'S House
            <small>YOUR HOME AWAY FROM HOME</small>
        </span>
    </a>

    <button class="mobile-menu" id="mobileMenuButton" onclick="openDrawer()" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="mobileDrawer"><?= icon("menu") ?></button>

    <div class="nav-links" id="navLinks">
        <a href="index.php">Home</a>
        <a href="rooms.php">Rooms</a>
        <a href="#about">About</a>

        <?php if ($isCustomer): ?>
            <a href="customer/dashboard.php">My Reservations</a>
            <a href="customer/profile.php">My Profile</a>
            <a href="logout.php" class="register-btn">Logout</a>
        <?php elseif ($isAdmin): ?>
            <a href="admin/dashboard.php" class="register-btn">Admin Dashboard</a>
        <?php else: ?>
            <a href="login.php" class="login-btn">Login</a>
            <a href="register.php" class="register-btn">Register</a>
        <?php endif; ?>
    </div>
</nav>

<div class="drawer-backdrop" id="drawerBackdrop" onclick="closeDrawer()"></div>

<aside class="mobile-drawer" id="mobileDrawer" aria-hidden="true">
    <div class="drawer-head">
        <div class="drawer-title">Menu</div>
        <button type="button" class="drawer-close" onclick="closeDrawer()" aria-label="Close menu"><?= icon("x") ?></button>
    </div>

    <div class="drawer-menu">
        <div class="drawer-section">Explore</div>

        <a href="index.php" class="drawer-link active">
            <span class="drawer-icon"><?= icon("home") ?></span>
            <span>Home</span>
        </a>

        <a href="rooms.php" class="drawer-link">
            <span class="drawer-icon"><?= icon("bed") ?></span>
            <span>Rooms</span>
        </a>

        <a href="#about" class="drawer-link" onclick="closeDrawer()">
            <span class="drawer-icon">ⓘ</span>
            <span>About Us</span>
        </a>

        <div class="drawer-divider"></div>

        <div class="drawer-section">Account</div>

        <?php if ($isCustomer): ?>
            <a href="customer/dashboard.php" class="drawer-link">
                <span class="drawer-icon"><?= icon("calendar") ?></span>
                <span>My Reservations</span>
            </a>

            <a href="customer/profile.php" class="drawer-link">
                <span class="drawer-icon"><?= icon("user") ?></span>
                <span>My Profile</span>
            </a>

            <a href="logout.php" class="drawer-link">
                <span class="drawer-icon"><?= icon("log-out") ?></span>
                <span>Logout</span>
            </a>

            <a href="rooms.php" class="drawer-action">Book a Room</a>

        <?php elseif ($isAdmin): ?>
            <a href="admin/dashboard.php" class="drawer-link">
                <span class="drawer-icon"><?= icon("settings") ?></span>
                <span>Admin Dashboard</span>
            </a>

            <a href="logout.php" class="drawer-link">
                <span class="drawer-icon"><?= icon("log-out") ?></span>
                <span>Logout</span>
            </a>

            <a href="admin/dashboard.php" class="drawer-action">Open Dashboard</a>

        <?php else: ?>
            <a href="login.php" class="drawer-link">
                <span class="drawer-icon"><?= icon("user") ?></span>
                <span>Login</span>
            </a>

            <a href="register.php" class="drawer-link">
                <span class="drawer-icon">＋</span>
                <span>Create Account</span>
            </a>

            <a href="rooms.php" class="drawer-action">Explore Rooms</a>
        <?php endif; ?>
    </div>
</aside>


<?php if ($customerRequired): ?>
<div class="alert-wrapper">
    <div class="alert">
        <div><?= icon("alert") ?> Booking requires a customer account. Administrator accounts cannot create reservations.</div>
        <?php if ($isAdmin): ?>
            <a href="admin/dashboard.php">Back to Admin</a>
        <?php else: ?>
            <a href="login.php">Customer Login</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<section class="hero">
    <div class="hero-inner">
        <div>
            <span class="eyebrow">Comfort · Relax · Stay</span>
            <h1>Find your <span>perfect stay.</span></h1>
            <p>
                Comfortable rooms, simple reservations and a relaxing place to stay.
                Discover ARVE'S House and book the room that fits your trip.
            </p>

            <div class="hero-buttons">
                <a href="rooms.php" class="btn btn-primary">Explore Rooms →</a>

                <?php if (!$isLoggedIn): ?>
                    <a href="login.php" class="btn btn-secondary">Customer Login</a>
                <?php elseif ($isCustomer): ?>
                    <a href="customer/dashboard.php" class="btn btn-secondary">My Reservations</a>
                <?php else: ?>
                    <a href="admin/dashboard.php" class="btn btn-secondary">Admin Dashboard</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="hero-card">
            <div class="hero-card-inner">
                <div class="house">
                    <?= in_array(trim($siteSettings["hero_card_icon"]), ["🏡", "🏠", "⌂", ""], true) ? icon("home") : htmlspecialchars($siteSettings["hero_card_icon"]) ?>
                </div>

                <h2>
                    <?= htmlspecialchars($siteSettings["hero_card_title"]) ?>
                </h2>

                <p>
                    <?= htmlspecialchars($siteSettings["hero_card_subtitle"]) ?>
                </p>
            </div>
        </div>
    </div>
</section>

<div class="search-wrap">
    <form action="rooms.php" method="GET" class="search-card">
        <div class="search-field">
            <label for="home_check_in">CHECK-IN</label>
            <input type="date" id="home_check_in" name="check_in" min="<?= date("Y-m-d") ?>" required>
        </div>

        <div class="search-field">
            <label for="home_check_out">CHECK-OUT</label>
            <input type="date" id="home_check_out" name="check_out" min="<?= date("Y-m-d") ?>" required>
        </div>

        <button type="submit" class="search-btn"><?= icon("search") ?> Check Availability</button>
    </form>
</div>

<section class="section">
    <div class="section-inner">
        <div class="section-heading">
            <span>Why Choose Us</span>
            <h2>Everything you need for a comfortable stay</h2>
            <p>A simple reservation experience designed for convenience, comfort and peace of mind.</p>
        </div>

        <div class="features">
            <div class="feature-card">
                <div class="feature-icon"><?= icon("bed") ?></div>
                <h3>Comfortable Rooms</h3>
                <p>Clean and relaxing rooms prepared to make every visit more comfortable.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><?= icon("calendar") ?></div>
                <h3>Easy Reservation</h3>
                <p>Check room availability and create your reservation directly from the website.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><?= icon("lock") ?></div>
                <h3>Secure Booking</h3>
                <p>Your reservation and payment workflow stays organized through your customer account.</p>
            </div>
        </div>
    </div>
</section>

<section class="section rooms-section" id="rooms">
    <div class="section-inner">
        <div class="section-heading">
            <span>Our Rooms</span>
            <h2>Featured Rooms</h2>
            <p>Choose from our currently available accommodation.</p>
        </div>

        <?php if (count($rooms) > 0): ?>
        <div class="rooms-grid">
            <?php foreach ($rooms as $room): ?>
                <?php
                $bookingUrl = "reservation.php?room_id=" . (int) $room["id"];
                ?>
                <article class="room-card">
                    <div class="room-visual">
                        <span class="room-badge">✓ Available</span>
                        <span class="bed"><?= icon("bed") ?></span>
                    </div>

                    <div class="room-body">
                        <div class="room-head">
                            <h3 class="room-name"><?= htmlspecialchars($room["room_name"]) ?></h3>
                            <div class="room-price">
                                ₱<?= number_format((float) $room["price"], 2) ?>
                                <small>/ night</small>
                            </div>
                        </div>

                        <p class="room-description">
                            <?= htmlspecialchars($room["description"] ?? "Comfortable room available for your stay.") ?>
                        </p>

                        <div class="room-meta">
                            <?= icon("users") ?> Up to <?= (int) $room["capacity"] ?>
                            guest<?= (int) $room["capacity"] !== 1 ? "s" : "" ?>
                        </div>

                        <?php if ($isCustomer): ?>
                            <a href="<?= htmlspecialchars($bookingUrl) ?>" class="room-btn"><?= icon("calendar") ?> Book Now</a>
                        <?php elseif (!$isLoggedIn): ?>
                            <a href="login.php?redirect=<?= urlencode($bookingUrl) ?>" class="room-btn login-to-book">
                                <?= icon("log-in") ?> Login to Book
                            </a>
                        <?php else: ?>
                            <button type="button" class="room-btn" disabled>Customer Account Required</button>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="feature-card" style="max-width:700px;margin:auto">
                <h3>No rooms currently available</h3>
                <p>Please check again later for room availability.</p>
            </div>
        <?php endif; ?>

        <div class="view-all">
            <a href="rooms.php" class="btn btn-secondary">View All Rooms →</a>
        </div>
    </div>
</section>

<section class="section" id="about">
    <div class="about-wrap">
        <div class="about-visual"><?= icon("home") ?></div>

        <div class="about-copy">
            <span class="eyebrow">About ARVE'S House</span>
            <h2>A simple, comfortable place to call home for a while.</h2>
            <p>
                ARVE'S House gives guests an accessible way to browse rooms,
                check availability, create reservations and manage their stay.
            </p>
            <p>
                Our online reservation system keeps the booking process organized
                for customers and administrators while maintaining a comfortable,
                welcoming experience.
            </p>
            <a href="rooms.php" class="btn btn-primary">Explore Our Rooms</a>
        </div>
    </div>
</section>

<section class="cta">
    <div class="cta-box">
        <?php if ($isCustomer): ?>
            <h2>Ready for your next stay?</h2>
            <p>Choose your dates, check room availability and create your next reservation.</p>
            <a href="rooms.php" class="btn">Book Your Stay</a>
        <?php elseif (!$isLoggedIn): ?>
            <h2>Your comfortable stay starts here.</h2>
            <p>Create a customer account or sign in to reserve an available room.</p>
            <a href="register.php" class="btn">Create an Account</a>
        <?php else: ?>
            <h2>Administrator Website Preview</h2>
            <p>Booking is limited to customer accounts. Return to your dashboard to manage the system.</p>
            <a href="admin/dashboard.php" class="btn">Admin Dashboard</a>
        <?php endif; ?>
    </div>
</section>


<nav class="mobile-bottom-nav" aria-label="Mobile navigation">
    <a href="index.php" class="active">
        <span class="bottom-icon"><?= icon("home") ?></span>
        <span>Home</span>
    </a>

    <a href="rooms.php">
        <span class="bottom-icon"><?= icon("bed") ?></span>
        <span>Rooms</span>
    </a>

    <?php if ($isCustomer): ?>
        <a href="customer/dashboard.php">
            <span class="bottom-icon"><?= icon("calendar") ?></span>
            <span>Bookings</span>
        </a>

        <a href="customer/profile.php">
            <span class="bottom-icon"><?= icon("user") ?></span>
            <span>Profile</span>
        </a>
    <?php elseif ($isAdmin): ?>
        <a href="admin/dashboard.php">
            <span class="bottom-icon"><?= icon("settings") ?></span>
            <span>Admin</span>
        </a>

        <a href="logout.php">
            <span class="bottom-icon"><?= icon("log-out") ?></span>
            <span>Logout</span>
        </a>
    <?php else: ?>
        <a href="login.php">
            <span class="bottom-icon"><?= icon("user") ?></span>
            <span>Login</span>
        </a>

        <a href="register.php">
            <span class="bottom-icon">＋</span>
            <span>Register</span>
        </a>
    <?php endif; ?>
</nav>

<footer>
    <div class="footer-inner">
        <div>
            <h3>ARVE'S House</h3>
            <p>Your Home Away From Home</p>
        </div>

        <div>
            <p>Transient & Reservation System</p>
            <p>Comfort · Relax · Stay</p>
        </div>
    </div>

    <div class="footer-copy">
        &copy; <?= date("Y") ?> ARVE'S House. All rights reserved.
    </div>
</footer>

<script>
const mobileDrawer = document.getElementById("mobileDrawer");
const drawerBackdrop = document.getElementById("drawerBackdrop");
const mobileMenuButton = document.getElementById("mobileMenuButton");

function openDrawer() {
    mobileDrawer.classList.add("show");
    drawerBackdrop.classList.add("show");
    document.body.classList.add("menu-open");

    mobileDrawer.setAttribute("aria-hidden", "false");
    mobileMenuButton.setAttribute("aria-expanded", "true");
}

function closeDrawer() {
    mobileDrawer.classList.remove("show");
    drawerBackdrop.classList.remove("show");
    document.body.classList.remove("menu-open");

    mobileDrawer.setAttribute("aria-hidden", "true");
    mobileMenuButton.setAttribute("aria-expanded", "false");
}

document.addEventListener("keydown", event => {
    if (event.key === "Escape") {
        closeDrawer();
    }
});

window.addEventListener("resize", () => {
    if (window.innerWidth > 780) {
        closeDrawer();
    }
});

const homeCheckIn = document.getElementById("home_check_in");
const homeCheckOut = document.getElementById("home_check_out");

function formatDateLocal(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
}

function updateHomeCheckout() {
    if (!homeCheckIn.value) {
        return;
    }

    const date = new Date(homeCheckIn.value + "T00:00:00");
    date.setDate(date.getDate() + 1);

    const minDate = formatDateLocal(date);
    homeCheckOut.min = minDate;

    if (homeCheckOut.value && homeCheckOut.value < minDate) {
        homeCheckOut.value = "";
    }
}

homeCheckIn.addEventListener("change", updateHomeCheckout);
updateHomeCheckout();
</script>

<?php require __DIR__ . "/includes/login-modal.php"; ?>

<?php $inboxFabLift = 72; require __DIR__ . "/includes/inbox-widget.php"; ?>
<?php $termsFabLift = 72; require __DIR__ . "/includes/terms-modal.php"; ?>

</body>
</html>

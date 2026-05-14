<?php
session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$isLoggedIn = isset($_SESSION['user_id']);
$fullName   = $_SESSION['full_name'] ?? null;
$username   = $_SESSION['username'] ?? null;
$role       = $_SESSION['role'] ?? 'member';
$avatar     = $_SESSION['avatar'] ?? null;

$openAuthModal = isset($_SESSION['auth_error_login']) ||
                 isset($_SESSION['auth_error_register']) ||
                 isset($_SESSION['auth_success_register']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PortalFeed — Connect, Share, Discover</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
:root{
  --bg:#f7f9fc;
  --panel:#ffffff;
  --soft:#f1f5f9;
  --line:#e2e8f0;
  --text:#0f172a;
  --muted:#64748b;
  --blue:#2563eb;
  --blue2:#1d4ed8;
  --blueSoft:#eff6ff;
  --shadow:0 18px 50px rgba(15,23,42,.08);
}

*{box-sizing:border-box}

body{
  margin:0;
  background:
    radial-gradient(circle at top left, rgba(37,99,235,.10), transparent 28%),
    linear-gradient(180deg,#fff 0%,var(--bg) 38%,#eef3f9 100%);
  color:var(--text);
  font-family:"Plus Jakarta Sans",system-ui,sans-serif;
}

a{text-decoration:none}

.navbar{
  background:rgba(255,255,255,.86);
  backdrop-filter:blur(18px);
  border-bottom:1px solid rgba(226,232,240,.9);
}

.brand-icon{
  width:42px;
  height:42px;
  border-radius:14px;
  display:grid;
  place-items:center;
  background:linear-gradient(135deg,var(--blue),#60a5fa);
  color:#fff;
  box-shadow:0 12px 28px rgba(37,99,235,.22);
}

.navbar-brand{
  font-weight:900;
  letter-spacing:-.04em;
  color:var(--text)!important;
}

.nav-link{
  color:var(--muted)!important;
  font-size:.9rem;
  font-weight:700;
}

.nav-link:hover{color:var(--text)!important}

.btn-main{
  border:0;
  border-radius:999px;
  padding:.75rem 1.1rem;
  font-weight:800;
  background:linear-gradient(135deg,var(--blue),var(--blue2));
  color:#fff;
  box-shadow:0 14px 30px rgba(37,99,235,.22);
}

.btn-soft{
  border:1px solid var(--line);
  border-radius:999px;
  padding:.75rem 1.1rem;
  font-weight:800;
  background:#fff;
  color:var(--text);
}

.hero{
  padding:118px 0 70px;
}

.kicker{
  display:inline-flex;
  align-items:center;
  gap:.45rem;
  background:var(--blueSoft);
  border:1px solid #dbeafe;
  color:var(--blue2);
  padding:.45rem .75rem;
  border-radius:999px;
  font-size:.78rem;
  font-weight:900;
  margin-bottom:18px;
}

.hero-title{
  font-size:clamp(2.6rem,6vw,5.4rem);
  line-height:.96;
  font-weight:900;
  letter-spacing:-.07em;
  margin-bottom:18px;
}

.hero-title span{
  background:linear-gradient(135deg,var(--blue),#7c3aed);
  -webkit-background-clip:text;
  background-clip:text;
  color:transparent;
}

.hero-text{
  color:var(--muted);
  font-size:1.06rem;
  line-height:1.8;
  max-width:620px;
}

.hero-actions{
  display:flex;
  gap:.75rem;
  flex-wrap:wrap;
  margin-top:24px;
}

.trust-row{
  display:flex;
  flex-wrap:wrap;
  gap:.65rem;
  margin-top:24px;
}

.trust-pill{
  background:#fff;
  border:1px solid var(--line);
  border-radius:999px;
  padding:.5rem .75rem;
  font-size:.8rem;
  color:var(--muted);
  font-weight:700;
}

.phone-frame{
  width:min(390px,100%);
  margin:auto;
  background:#fff;
  border:1px solid var(--line);
  border-radius:38px;
  padding:14px;
  box-shadow:0 30px 90px rgba(15,23,42,.16);
  position:relative;
}

.phone-inner{
  border-radius:28px;
  overflow:hidden;
  border:1px solid var(--line);
  background:#fff;
}

.phone-top{
  height:54px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:0 16px;
  border-bottom:1px solid var(--line);
}

.phone-brand{
  font-weight:900;
  letter-spacing:-.05em;
}

.story-row{
  display:flex;
  gap:12px;
  overflow:hidden;
  padding:14px;
  border-bottom:1px solid var(--line);
}

.story{
  min-width:58px;
  text-align:center;
}

.story-avatar{
  width:54px;
  height:54px;
  border-radius:50%;
  padding:3px;
  background:linear-gradient(135deg,#f97316,#db2777,#2563eb);
}

.story-avatar div{
  width:100%;
  height:100%;
  border-radius:50%;
  background:#fff;
  display:grid;
  place-items:center;
  font-size:.8rem;
  font-weight:900;
}

.story small{
  display:block;
  margin-top:4px;
  color:var(--muted);
  font-size:.67rem;
}

.preview-post{
  padding:14px;
}

.post-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  margin-bottom:12px;
}

.user-mini{
  display:flex;
  align-items:center;
  gap:9px;
}

.avatar{
  width:36px;
  height:36px;
  border-radius:50%;
  background:linear-gradient(135deg,#bfdbfe,#2563eb);
}

.fake-img{
  height:280px;
  border-radius:20px;
  background:
    radial-gradient(circle at 25% 25%, rgba(255,255,255,.75), transparent 18%),
    linear-gradient(135deg,#dbeafe,#bfdbfe 38%,#93c5fd);
  position:relative;
  overflow:hidden;
}

.lock-overlay{
  position:absolute;
  inset:0;
  display:grid;
  place-items:center;
  background:rgba(255,255,255,.45);
  backdrop-filter:blur(8px);
}

.lock-box{
  background:#fff;
  border:1px solid var(--line);
  border-radius:20px;
  padding:16px;
  text-align:center;
  width:82%;
  box-shadow:var(--shadow);
}

.action-row{
  display:flex;
  gap:14px;
  font-size:1.25rem;
  padding:12px 2px 4px;
}

.feed-caption{
  color:var(--muted);
  font-size:.84rem;
  line-height:1.55;
}

.section{
  padding:62px 0;
}

.section-title{
  font-size:clamp(2rem,4vw,3.5rem);
  font-weight:900;
  letter-spacing:-.06em;
  line-height:1;
}

.section-lead{
  color:var(--muted);
  max-width:720px;
  line-height:1.75;
}

.feature-card{
  height:100%;
  background:#fff;
  border:1px solid var(--line);
  border-radius:26px;
  padding:24px;
  box-shadow:0 12px 36px rgba(15,23,42,.05);
}

.feature-icon{
  width:48px;
  height:48px;
  border-radius:16px;
  background:var(--blueSoft);
  color:var(--blue2);
  display:grid;
  place-items:center;
  font-size:1.25rem;
  margin-bottom:14px;
}

.feature-card h5{
  font-weight:900;
  letter-spacing:-.03em;
}

.feature-card p{
  color:var(--muted);
  line-height:1.65;
  margin:0;
}

.lock-demo{
  background:#fff;
  border:1px solid var(--line);
  border-radius:30px;
  padding:28px;
  box-shadow:var(--shadow);
}

.lock-item{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
  padding:16px 0;
  border-bottom:1px solid var(--line);
}

.lock-item:last-child{border-bottom:0}

.lock-left{
  display:flex;
  align-items:center;
  gap:12px;
}

.lock-dot{
  width:42px;
  height:42px;
  border-radius:50%;
  display:grid;
  place-items:center;
  background:var(--soft);
  color:var(--blue2);
}

.lock-badge{
  background:#f8fafc;
  border:1px solid var(--line);
  color:var(--muted);
  border-radius:999px;
  padding:.42rem .7rem;
  font-size:.76rem;
  font-weight:800;
  white-space:nowrap;
}

.cta-box{
  background:linear-gradient(135deg,#fff,#eff6ff);
  border:1px solid #dbeafe;
  border-radius:34px;
  padding:36px;
  box-shadow:var(--shadow);
}

footer{
  padding:32px 0;
  border-top:1px solid var(--line);
  color:var(--muted);
  font-size:.86rem;
}

.modal-content{
  border:1px solid var(--line);
  border-radius:28px;
  box-shadow:0 30px 90px rgba(15,23,42,.2);
}

.modal-header{
  border-bottom:1px solid var(--line);
}

.nav-tabs{
  border:0;
  background:var(--soft);
  padding:5px;
  border-radius:999px;
}

.nav-tabs .nav-link{
  border:0!important;
  border-radius:999px;
  color:var(--muted)!important;
  font-weight:900;
}

.nav-tabs .nav-link.active{
  background:#fff!important;
  color:var(--text)!important;
  box-shadow:0 8px 20px rgba(15,23,42,.08);
}

.form-control{
  border-radius:16px;
  border:1px solid var(--line);
  padding:.85rem .95rem;
}

.form-control:focus{
  border-color:#bfdbfe;
  box-shadow:0 0 0 .2rem rgba(37,99,235,.10);
}

.auth-btn{
  width:100%;
  border:0;
  border-radius:999px;
  padding:.85rem 1rem;
  font-weight:900;
  background:linear-gradient(135deg,var(--blue),var(--blue2));
  color:#fff;
}

.auth-side{
  background:var(--blueSoft);
  border:1px solid #dbeafe;
  border-radius:24px;
  padding:24px;
  height:100%;
}

.locked-action{cursor:pointer}

@media(max-width:991px){
  .hero{padding-top:96px}
  .phone-frame{margin-top:32px}
}

@media(max-width:576px){
  .hero-actions .btn-main,
  .hero-actions .btn-soft{
    width:100%;
    justify-content:center;
  }

  .cta-box{padding:24px}
  .fake-img{height:230px}
}
</style>
</head>

<body>

<nav class="navbar navbar-expand-lg fixed-top">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="#home">
      <div class="brand-icon"><i class="bi bi-grid-1x2-fill"></i></div>
      <div>
        <div>PortalFeed</div>
        <small class="d-block" style="color:var(--muted);font-size:.68rem;letter-spacing:.08em;">SOCIAL WORKSPACE</small>
      </div>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div id="navMain" class="collapse navbar-collapse justify-content-end">
      <ul class="navbar-nav align-items-lg-center gap-lg-2">
        <li class="nav-item"><a class="nav-link" href="#preview">Preview</a></li>
        <li class="nav-item"><a class="nav-link" href="#features">Features</a></li>
        <li class="nav-item"><a class="nav-link" href="#access">Access</a></li>
        <li class="nav-item ms-lg-2">
          <button class="btn-soft" type="button" id="navLoginBtn">Log in</button>
        </li>
        <li class="nav-item ms-lg-1">
          <button class="btn-main" type="button" id="navSignupBtn">Sign up</button>
        </li>
      </ul>
    </div>
  </div>
</nav>

<header class="hero" id="home">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-7">
        <div class="kicker">
          <i class="bi bi-eye-fill"></i>
          Browse first. Join to interact.
        </div>

        <h1 class="hero-title">
          A clean social feed for <span>creators, teams & builders.</span>
        </h1>

        <p class="hero-text">
          PortalFeed lets visitors preview the platform like a real social network.
          They can see the experience, discover posts and understand the value —
          but actions like posting, liking, following and messaging unlock after login.
        </p>

        <div class="hero-actions">
          <button class="btn-main" type="button" id="heroSignupBtn">
            <i class="bi bi-person-plus-fill me-1"></i> Create account
          </button>
          <button class="btn-soft locked-action" type="button">
            <i class="bi bi-heart-fill me-1"></i> Try an action
          </button>
        </div>

        <div class="trust-row">
          <span class="trust-pill"><i class="bi bi-chat-dots me-1"></i> Messaging</span>
          <span class="trust-pill"><i class="bi bi-people me-1"></i> Groups</span>
          <span class="trust-pill"><i class="bi bi-images me-1"></i> Media posts</span>
          <span class="trust-pill"><i class="bi bi-lock me-1"></i> Member-only actions</span>
        </div>
      </div>

      <div class="col-lg-5" id="preview">
        <div class="phone-frame">
          <div class="phone-inner">
            <div class="phone-top">
              <div class="phone-brand">PortalFeed</div>
              <div class="d-flex gap-3">
                <i class="bi bi-heart"></i>
                <i class="bi bi-chat-dots"></i>
              </div>
            </div>

            <div class="story-row">
              <?php foreach(['You','Mira','Dev','Team','Art'] as $s): ?>
                <div class="story">
                  <div class="story-avatar"><div><?= htmlspecialchars(substr($s,0,1)) ?></div></div>
                  <small><?= htmlspecialchars($s) ?></small>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="preview-post">
              <div class="post-head">
                <div class="user-mini">
                  <div class="avatar"></div>
                  <div>
                    <strong style="font-size:.85rem;">creator.studio</strong>
                    <div style="font-size:.72rem;color:var(--muted);">Project update</div>
                  </div>
                </div>
                <i class="bi bi-three-dots"></i>
              </div>

              <div class="fake-img">
                <div class="lock-overlay">
                  <div class="lock-box">
                    <div style="font-size:1.45rem;color:var(--blue2);"><i class="bi bi-lock-fill"></i></div>
                    <strong>Sign up to interact</strong>
                    <p class="mb-2" style="font-size:.78rem;color:var(--muted);">Guests can preview. Members can like, comment, follow and message.</p>
                    <button class="btn-main py-2 px-3 locked-action" type="button">Unlock</button>
                  </div>
                </div>
              </div>

              <div class="action-row">
                <i class="bi bi-heart locked-action"></i>
                <i class="bi bi-chat locked-action"></i>
                <i class="bi bi-send locked-action"></i>
                <i class="bi bi-bookmark ms-auto locked-action"></i>
              </div>

              <div class="feed-caption">
                <strong>creator.studio</strong> Building a new workspace for creators and communities.
                <span style="color:var(--muted)">Login to view comments.</span>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</header>

<section class="section" id="features">
  <div class="container">
    <div class="text-center mb-4">
      <h2 class="section-title">Everything feels open. Actions stay locked.</h2>
      <p class="section-lead mx-auto">
        This creates a better first impression: people see the product before signup, but the platform still pushes account creation when they try to engage.
      </p>
    </div>

    <div class="row g-3">
      <div class="col-md-6 col-lg-4">
        <div class="feature-card">
          <div class="feature-icon"><i class="bi bi-layout-text-window"></i></div>
          <h5>Guest Feed Preview</h5>
          <p>Show the social experience publicly without allowing guests to create posts or interact.</p>
        </div>
      </div>

      <div class="col-md-6 col-lg-4">
        <div class="feature-card">
          <div class="feature-icon"><i class="bi bi-person-plus"></i></div>
          <h5>Natural Signup Prompt</h5>
          <p>When a visitor clicks like, comment, follow, message or join group, the login/signup modal opens.</p>
        </div>
      </div>

      <div class="col-md-6 col-lg-4">
        <div class="feature-card">
          <div class="feature-icon"><i class="bi bi-phone"></i></div>
          <h5>Instagram-style UI</h5>
          <p>White, clean, mobile-first interface with story previews, post cards and simple navigation.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section pt-0" id="access">
  <div class="container">
    <div class="row g-4 align-items-center">
      <div class="col-lg-6">
        <h2 class="section-title">What guests can and can’t do.</h2>
        <p class="section-lead">
          Guests can understand the platform without being forced too early. But every real social action becomes a conversion point.
        </p>
        <button class="btn-main mt-2" type="button" id="accessSignupBtn">
          Join PortalFeed
        </button>
      </div>

      <div class="col-lg-6">
        <div class="lock-demo">
          <div class="lock-item">
            <div class="lock-left">
              <div class="lock-dot"><i class="bi bi-eye"></i></div>
              <div>
                <strong>View landing preview</strong>
                <div style="color:var(--muted);font-size:.86rem;">Available for everyone</div>
              </div>
            </div>
            <span class="lock-badge">Guest allowed</span>
          </div>

          <div class="lock-item">
            <div class="lock-left">
              <div class="lock-dot"><i class="bi bi-heart"></i></div>
              <div>
                <strong>Like and comment</strong>
                <div style="color:var(--muted);font-size:.86rem;">Requires account</div>
              </div>
            </div>
            <span class="lock-badge">Login required</span>
          </div>

          <div class="lock-item">
            <div class="lock-left">
              <div class="lock-dot"><i class="bi bi-chat-dots"></i></div>
              <div>
                <strong>Message users</strong>
                <div style="color:var(--muted);font-size:.86rem;">Requires account</div>
              </div>
            </div>
            <span class="lock-badge">Login required</span>
          </div>

          <div class="lock-item">
            <div class="lock-left">
              <div class="lock-dot"><i class="bi bi-pencil-square"></i></div>
              <div>
                <strong>Create posts</strong>
                <div style="color:var(--muted);font-size:.86rem;">Members only</div>
              </div>
            </div>
            <span class="lock-badge">Member only</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section pt-0">
  <div class="container">
    <div class="cta-box d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
      <div>
        <h2 class="mb-2" style="font-weight:900;letter-spacing:-.05em;">Ready to enter the feed?</h2>
        <p class="mb-0" style="color:var(--muted);">Create your account and unlock posting, messaging, groups, follows and comments.</p>
      </div>
      <button class="btn-main" type="button" id="bottomSignupBtn">
        Login / Signup
      </button>
    </div>
  </div>
</section>

<footer>
  <div class="container d-flex flex-column flex-md-row justify-content-between gap-2">
    <div>© <?= date('Y') ?> PortalFeed. Social workspace platform.</div>
    <div>Preview mode for guests • Full access for members</div>
  </div>
</footer>

<div class="modal fade" id="authModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title fw-black mb-0" style="font-weight:900;">Welcome to PortalFeed</h5>
          <small style="color:var(--muted);">Login or create your account to continue.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body p-4">
        <?php if (isset($_SESSION['auth_error_login'])): ?>
          <div class="alert alert-danger rounded-4 small">
            <?= htmlspecialchars($_SESSION['auth_error_login']); unset($_SESSION['auth_error_login']); ?>
          </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['auth_error_register'])): ?>
          <div class="alert alert-danger rounded-4 small">
            <?= htmlspecialchars($_SESSION['auth_error_register']); unset($_SESSION['auth_error_register']); ?>
          </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['auth_success_register'])): ?>
          <div class="alert alert-success rounded-4 small">
            <?= htmlspecialchars($_SESSION['auth_success_register']); unset($_SESSION['auth_success_register']); ?>
          </div>
        <?php endif; ?>

        <div class="row g-4">
          <div class="col-lg-6">
            <ul class="nav nav-tabs nav-fill mb-3" id="authTabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="login-tab" data-bs-toggle="tab" data-bs-target="#login-pane" type="button">
                  Login
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="signup-tab" data-bs-toggle="tab" data-bs-target="#signup-pane" type="button">
                  Sign up
                </button>
              </li>
            </ul>

            <div class="tab-content">
              <div class="tab-pane fade show active" id="login-pane" role="tabpanel">
                <form action="login.php" method="POST">
                  <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Username</label>
                    <input type="text" name="username" class="form-control" required>
                  </div>

                  <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Password</label>
                    <input type="password" name="password" class="form-control" required>
                  </div>

                  <button type="submit" class="auth-btn">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Login
                  </button>
                </form>
              </div>

              <div class="tab-pane fade" id="signup-pane" role="tabpanel">
                <form action="register.php" method="POST">
                  <div class="mb-2">
                    <label class="form-label small fw-bold text-muted">Full name</label>
                    <input type="text" name="full_name" class="form-control">
                  </div>

                  <div class="mb-2">
                    <label class="form-label small fw-bold text-muted">Username</label>
                    <input type="text" name="username" class="form-control" required>
                  </div>

                  <div class="mb-2">
                    <label class="form-label small fw-bold text-muted">Email</label>
                    <input type="email" name="email" class="form-control" required>
                  </div>

                  <div class="mb-2">
                    <label class="form-label small fw-bold text-muted">Password</label>
                    <input type="password" name="password" class="form-control" required>
                  </div>

                  <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Confirm password</label>
                    <input type="password" name="password_confirm" class="form-control" required>
                  </div>

                  <button type="submit" class="auth-btn">
                    <i class="bi bi-person-check-fill me-1"></i> Create account
                  </button>
                </form>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="auth-side">
              <h5 class="fw-bold mb-3">Unlock the full platform</h5>
              <p style="color:var(--muted);line-height:1.7;">
                Guests can preview the product, but members get the full social workspace experience.
              </p>

              <div class="d-grid gap-2 small">
                <div><i class="bi bi-check-circle-fill text-primary me-2"></i>Create and share posts</div>
                <div><i class="bi bi-check-circle-fill text-primary me-2"></i>Like, comment and follow</div>
                <div><i class="bi bi-check-circle-fill text-primary me-2"></i>Message other users</div>
                <div><i class="bi bi-check-circle-fill text-primary me-2"></i>Join groups and communities</div>
                <div><i class="bi bi-check-circle-fill text-primary me-2"></i>Build your creator profile</div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
function openAuthModal(tab = 'login') {
  const modalEl = document.getElementById('authModal');
  const modal = new bootstrap.Modal(modalEl);

  if (tab === 'signup') {
    const signupTab = document.getElementById('signup-tab');
    if (signupTab) new bootstrap.Tab(signupTab).show();
  } else {
    const loginTab = document.getElementById('login-tab');
    if (loginTab) new bootstrap.Tab(loginTab).show();
  }

  modal.show();
}

document.getElementById('navLoginBtn')?.addEventListener('click', () => openAuthModal('login'));
document.getElementById('navSignupBtn')?.addEventListener('click', () => openAuthModal('signup'));
document.getElementById('heroSignupBtn')?.addEventListener('click', () => openAuthModal('signup'));
document.getElementById('accessSignupBtn')?.addEventListener('click', () => openAuthModal('signup'));
document.getElementById('bottomSignupBtn')?.addEventListener('click', () => openAuthModal('signup'));

document.querySelectorAll('.locked-action').forEach(btn => {
  btn.addEventListener('click', function(e){
    e.preventDefault();
    openAuthModal('signup');
  });
});

<?php if ($openAuthModal): ?>
window.addEventListener('load', function () {
  openAuthModal('login');
});
<?php endif; ?>
</script>

</body>
</html>
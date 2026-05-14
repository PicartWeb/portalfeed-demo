<?php
// settings.php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("
  SELECT username, email, full_name, avatar, bio, role,
         skills, country, timezone,
         link_tiktok, link_instagram, link_x, link_portfolio
  FROM users
  WHERE id = :id
  LIMIT 1
");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    echo "User not found.";
    exit;
}

$avatar = $user['avatar'] ?? null;
$fullName = $user['full_name'] ?: $user['username'];
$role = $user['role'];
?>
<?php require_once __DIR__ . '/includes/app_shell.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Profile & Settings | Social Platform</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/app-shell.css">
<style>
    :root {
      --bg:#f4f7fb;
      --surface:#ffffff;
      --surface-soft:#f8fafc;
      --surface-alt:#eef4ff;
      --border:#e2e8f0;
      --border-strong:#cfd8e3;
      --text:#0f172a;
      --text-soft:#475569;
      --text-muted:#64748b;
      --accent:#2563eb;
      --accent-strong:#1d4ed8;
      --accent-soft:#dbeafe;
      --success:#16a34a;
      --danger:#dc2626;
      --warning:#f59e0b;
      --shadow:0 24px 54px rgba(15,23,42,.08);
      --shadow-soft:0 14px 28px rgba(15,23,42,.06);
      --radius-xl:28px;
      --radius-lg:22px;
      --radius-md:18px;
      --radius-sm:14px;
    }
    *{box-sizing:border-box;}
    body {
      margin:0;
      min-height:100vh;
      background:
        radial-gradient(circle at top left, rgba(37,99,235,.09), transparent 22%),
        radial-gradient(circle at bottom right, rgba(59,130,246,.08), transparent 20%),
        var(--bg);
      color:var(--text);
      font-family:"Plus Jakarta Sans","Segoe UI",sans-serif;
    }
    a{text-decoration:none;color:inherit;}
    .shell {
      min-height:100vh;
      padding:28px 22px 34px;
    }
    .page-wrap{
      max-width:1160px;
      margin:0 auto;
    }
    .topbar{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:16px;
      flex-wrap:wrap;
      margin-bottom:22px;
    }
    .topbar h1{
      margin:0;
      font-size:2rem;
      font-weight:800;
      letter-spacing:-.03em;
    }
    .topbar p{
      margin:8px 0 0;
      color:var(--text-soft);
      font-size:.98rem;
    }
    .topbar-actions{
      display:flex;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
    }
    .nav-button,
    .ghost-link,
    .save-button{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      padding:12px 18px;
      border-radius:999px;
      border:1px solid var(--border);
      background:var(--surface);
      color:var(--text-soft);
      font-size:.9rem;
      font-weight:700;
      box-shadow:var(--shadow-soft);
      transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease, color .18s ease, background .18s ease;
    }
    .nav-button:hover,
    .ghost-link:hover,
    .save-button:hover{
      transform:translateY(-1px);
      border-color:rgba(37,99,235,.26);
      color:var(--accent);
      box-shadow:0 16px 28px rgba(37,99,235,.10);
    }
    .save-button{
      background:linear-gradient(135deg, #2563eb, #60a5fa);
      border-color:transparent;
      color:#fff;
    }
    .save-button:hover{
      color:#fff;
      background:linear-gradient(135deg, #1d4ed8, #3b82f6);
    }
    .settings-shell{
      display:grid;
      grid-template-columns:320px minmax(0, 1fr);
      gap:22px;
      align-items:start;
    }
    .panel,
    .summary-card{
      background:rgba(255,255,255,.96);
      border:1px solid rgba(226,232,240,.94);
      border-radius:var(--radius-xl);
      box-shadow:var(--shadow);
    }
    .summary-card{
      overflow:hidden;
      position:sticky;
      top:24px;
    }
    .summary-banner{
      min-height:120px;
      background:
        radial-gradient(circle at 20% 20%, rgba(96,165,250,.34), transparent 24%),
        linear-gradient(135deg, #eff6ff, #f8fbff 58%, #ffffff);
      border-bottom:1px solid rgba(226,232,240,.9);
    }
    .summary-body{
      padding:0 22px 22px;
      margin-top:-42px;
    }
    .avatar-lg {
      width:96px;
      height:96px;
      border-radius:28px;
      object-fit:cover;
      border:5px solid rgba(255,255,255,.96);
      box-shadow:0 18px 34px rgba(15,23,42,.12);
      background:#dbeafe;
    }
    .summary-name{
      margin:16px 0 0;
      font-size:1.4rem;
      font-weight:800;
      letter-spacing:-.03em;
    }
    .summary-handle{
      margin:8px 0 0;
      color:var(--text-soft);
      font-size:.95rem;
      font-weight:600;
    }
    .role-pill{
      display:inline-flex;
      align-items:center;
      gap:8px;
      margin-top:14px;
      padding:8px 14px;
      border-radius:999px;
      background:rgba(219,234,254,.72);
      border:1px solid rgba(37,99,235,.16);
      color:var(--accent);
      font-size:.78rem;
      font-weight:800;
      letter-spacing:.08em;
      text-transform:uppercase;
    }
    .summary-note{
      margin:16px 0 0;
      color:var(--text-muted);
      line-height:1.7;
      font-size:.9rem;
    }
    .summary-list{
      display:flex;
      flex-direction:column;
      gap:12px;
      margin-top:18px;
    }
    .summary-item{
      display:flex;
      gap:12px;
      align-items:flex-start;
      padding:12px 14px;
      border-radius:18px;
      background:var(--surface-soft);
      border:1px solid var(--border);
      color:var(--text-soft);
      font-size:.9rem;
    }
    .summary-item i{
      color:var(--accent);
      font-size:1rem;
      margin-top:2px;
    }
    .panel{
      padding:24px;
    }
    .alert{
      border:none;
      border-radius:18px;
      padding:16px 18px;
      box-shadow:var(--shadow-soft);
      margin-bottom:16px;
    }
    .alert-danger{
      background:#fff1f2;
      color:#9f1239;
    }
    .alert-success{
      background:#f0fdf4;
      color:#166534;
    }
    .form-grid{
      display:flex;
      flex-direction:column;
      gap:18px;
    }
    .section-card{
      background:linear-gradient(180deg, #ffffff, #fbfdff);
      border:1px solid var(--border);
      border-radius:24px;
      padding:22px;
      box-shadow:var(--shadow-soft);
    }
    .section-head{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:16px;
      flex-wrap:wrap;
      margin-bottom:18px;
    }
    .section-kicker{
      margin:0 0 8px;
      color:var(--text-muted);
      font-size:.76rem;
      font-weight:800;
      letter-spacing:.16em;
      text-transform:uppercase;
    }
    .section-title{
      margin:0;
      font-size:1.2rem;
      font-weight:800;
      letter-spacing:-.02em;
    }
    .section-desc{
      margin:8px 0 0;
      color:var(--text-soft);
      font-size:.92rem;
      line-height:1.7;
      max-width:680px;
    }
    .section-badge{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:9px 14px;
      border-radius:999px;
      background:var(--surface-alt);
      border:1px solid rgba(37,99,235,.12);
      color:var(--accent);
      font-size:.8rem;
      font-weight:700;
    }
    .form-label {
      margin-bottom:8px;
      font-size:.86rem;
      font-weight:700;
      color:var(--text);
    }
    .form-help{
      margin-top:8px;
      color:var(--text-muted);
      font-size:.82rem;
      line-height:1.6;
    }
    .form-control {
      background:var(--surface);
      border:1px solid var(--border);
      border-radius:16px;
      color:var(--text);
      font-size:.94rem;
      padding:13px 15px;
      min-height:52px;
      box-shadow:none;
    }
    textarea.form-control{
      min-height:auto;
      resize:vertical;
    }
    .form-control:focus {
      border-color:rgba(37,99,235,.42);
      box-shadow:0 0 0 4px rgba(37,99,235,.12);
      background:#fff;
      color:var(--text);
    }
    .upload-card{
      display:flex;
      align-items:center;
      gap:16px;
      padding:16px;
      border-radius:20px;
      border:1px dashed var(--border-strong);
      background:var(--surface-soft);
      flex-wrap:wrap;
    }
    .upload-avatar{
      width:72px;
      height:72px;
      border-radius:22px;
      object-fit:cover;
      background:#dbeafe;
      box-shadow:var(--shadow-soft);
      flex:0 0 auto;
    }
    .form-actions{
      display:flex;
      align-items:center;
      gap:14px;
      flex-wrap:wrap;
      margin-top:8px;
    }
    .foot-note{
      color:var(--text-muted);
      font-size:.88rem;
      line-height:1.7;
    }
    @media (max-width: 991.98px){
      .settings-shell{
        grid-template-columns:1fr;
      }
      .summary-card{
        position:static;
      }
    }
    @media (max-width: 767.98px){
      .shell{
        padding:16px 12px 22px;
      }
      .topbar h1{
        font-size:1.65rem;
      }
      .panel,
      .section-card,
      .summary-body{
        padding:16px;
      }
      .summary-body{
        margin-top:-34px;
      }
      .topbar-actions,
      .form-actions{
        width:100%;
      }
      .nav-button,
      .ghost-link,
      .save-button{
        flex:1 1 auto;
      }
      .upload-card{
        align-items:flex-start;
      }
    }
  </style>
</head>
<?php render_app_shell_start(['active' => 'settings', 'context_label' => 'Settings', 'context_title' => 'Profile Settings', 'context_subtitle' => 'Manage your public profile, identity, and creator links.']); ?>
<div class=\
      <aside class="summary-card">
        <div class="summary-banner"></div>
        <div class="summary-body">
          <img
            src="<?= $avatar ? htmlspecialchars($avatar) : 'https://ui-avatars.com/api/?name=' . urlencode($fullName) . '&background=111827&color=fff&rounded=true&size=128' ?>"
            alt="avatar"
            class="avatar-lg"
          >
          <h2 class="summary-name"><?= htmlspecialchars($fullName) ?></h2>
          <p class="summary-handle">@<?= htmlspecialchars($user['username']) ?></p>
          <div class="role-pill">
            <i class="bi bi-patch-check-fill"></i>
            <span><?= htmlspecialchars($role) ?></span>
          </div>
          <p class="summary-note">This is the profile identity people see across the platform. Keep it clear, credible, and complete.</p>

          <div class="summary-list">
            <div class="summary-item">
              <i class="bi bi-envelope"></i>
              <div>
                <strong style="display:block;color:var(--text);font-size:.9rem;">Primary email</strong>
                <span><?= htmlspecialchars($user['email']) ?></span>
              </div>
            </div>
            <div class="summary-item">
              <i class="bi bi-lightning-charge"></i>
              <div>
                <strong style="display:block;color:var(--text);font-size:.9rem;">Skills snapshot</strong>
                <span><?= htmlspecialchars($user['skills'] ?: 'Add your focus areas and strengths') ?></span>
              </div>
            </div>
          </div>
        </div>
      </aside>

      <section class="panel">
<?php if (!empty($_SESSION['profile_error'])): ?>
  <div class="alert alert-danger">
    <?= htmlspecialchars($_SESSION['profile_error']) ?>
  </div>
  <?php unset($_SESSION['profile_error']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['profile_success'])): ?>
  <div class="alert alert-success">
    <?= htmlspecialchars($_SESSION['profile_success']) ?>
  </div>
  <?php unset($_SESSION['profile_success']); ?>
<?php endif; ?>
        <form action="update_profile.php" method="POST" enctype="multipart/form-data" class="form-grid">
          <section class="section-card">
            <div class="section-head">
              <div>
                <p class="section-kicker">Account</p>
                <h3 class="section-title">Identity and login details</h3>
                <p class="section-desc">Update the primary information tied to your public account profile and how people find you.</p>
              </div>
              <div class="section-badge"><i class="bi bi-shield-check"></i> Core profile</div>
            </div>

            <div class="row g-3">
              <div class="col-12 col-md-6">
                <label class="form-label">Full name</label>
                <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" required>
              </div>
              <div class="col-12">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
              </div>
            </div>
          </section>

          <section class="section-card">
            <div class="section-head">
              <div>
                <p class="section-kicker">Avatar</p>
                <h3 class="section-title">Profile photo</h3>
                <p class="section-desc">Upload a clear image that looks good in profile headers, feed cards, and messages.</p>
              </div>
              <div class="section-badge"><i class="bi bi-image"></i> Public avatar</div>
            </div>

            <div class="upload-card">
              <img
                src="<?= $avatar ? htmlspecialchars($avatar) : 'https://ui-avatars.com/api/?name=' . urlencode($fullName) . '&background=111827&color=fff&rounded=true&size=128' ?>"
                alt="avatar preview"
                class="upload-avatar"
              >
              <div class="flex-grow-1">
                <label class="form-label">Profile photo</label>
                <input type="file" name="avatar" class="form-control" accept="image/*">
                <div class="form-help">PNG/JPG up to about 2MB. Your round/cropped avatar presentation stays handled by the app.</div>
              </div>
            </div>
          </section>

          <section class="section-card">
            <div class="section-head">
              <div>
                <p class="section-kicker">About</p>
                <h3 class="section-title">Bio, skills, and location</h3>
                <p class="section-desc">Help people understand what you do, what you specialize in, and where you operate.</p>
              </div>
              <div class="section-badge"><i class="bi bi-person-lines-fill"></i> Public details</div>
            </div>

            <div class="row g-3">
              <div class="col-12">
                <label class="form-label">About / Bio</label>
                <textarea name="bio" rows="4" class="form-control" placeholder="Tell people what you do, who you help, and why you exist."><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
              </div>
              <div class="col-12">
                <label class="form-label">Skills</label>
                <input type="text" name="skills" class="form-control" placeholder="JavaScript, React, PHP, Money game..." value="<?= htmlspecialchars($user['skills'] ?? '') ?>">
                <div class="form-help">Keep this comma-separated so the profile page can present each skill cleanly.</div>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">Country</label>
                <input type="text" name="country" class="form-control" placeholder="Albania, UK, USA..." value="<?= htmlspecialchars($user['country'] ?? '') ?>">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">Timezone</label>
                <input type="text" name="timezone" class="form-control" placeholder="CET, EST, GMT+1..." value="<?= htmlspecialchars($user['timezone'] ?? '') ?>">
              </div>
            </div>
          </section>

          <section class="section-card">
            <div class="section-head">
              <div>
                <p class="section-kicker">Links</p>
                <h3 class="section-title">Social and portfolio links</h3>
                <p class="section-desc">Add the destinations that support your creator identity and external presence.</p>
              </div>
              <div class="section-badge"><i class="bi bi-link-45deg"></i> External links</div>
            </div>

            <div class="row g-3">
              <div class="col-12 col-md-6">
                <label class="form-label">TikTok URL</label>
                <input type="url" name="link_tiktok" class="form-control" placeholder="TikTok URL" value="<?= htmlspecialchars($user['link_tiktok'] ?? '') ?>">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">Instagram URL</label>
                <input type="url" name="link_instagram" class="form-control" placeholder="Instagram URL" value="<?= htmlspecialchars($user['link_instagram'] ?? '') ?>">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">X (Twitter) URL</label>
                <input type="url" name="link_x" class="form-control" placeholder="X (Twitter) URL" value="<?= htmlspecialchars($user['link_x'] ?? '') ?>">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">Portfolio / Website URL</label>
                <input type="url" name="link_portfolio" class="form-control" placeholder="Portfolio / Website URL" value="<?= htmlspecialchars($user['link_portfolio'] ?? '') ?>">
              </div>
            </div>
          </section>

          <div class="form-actions">
            <button type="submit" class="save-button">
              <i class="bi bi-check2-circle"></i>
              <span>Save changes</span>
            </button>
            <span class="foot-note">The save flow and field names stay exactly the same, so this remains a frontend-only redesign.</span>
          </div>
        </form>
      </section>
    </div>
  </div>
</div>
<?php render_app_shell_end(['active' => 'settings']); ?>
</body>
</html>





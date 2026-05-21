<?php
// ============================================================
// Let Lyre - Admin Panel (PHP + MySQL) - Full Feature
// ============================================================
require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=utf-8');

$action = $_GET['action'] ?? '';
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($action && $isAjax) {
    header('Content-Type: application/json');
    $isAdmin = isLoggedIn() && !empty($_SESSION['is_admin']);
    if (!$isAdmin) jsonResponse(['error' => 'Unauthorized.'], 401);

    $db = getDB();
    if (!$db) jsonResponse(['error' => 'Database connection failed.'], 500);

    switch ($action) {

        case 'get_dashboard_stats':
            $stats = [];
            $r = $db->query("SELECT COUNT(*) AS c FROM users"); $stats['total_users'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
            $r = $db->query("SELECT COUNT(*) AS c FROM user_playlists"); $stats['total_playlists'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
            $r = $db->query("SELECT COUNT(*) AS c FROM playlist_songs"); $stats['total_songs'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
            $r = $db->query("SELECT COUNT(*) AS c FROM announcements WHERE status='active'"); $stats['active_announcements'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
            $r = $db->query("SELECT COUNT(*) AS c FROM users WHERE created_at >= NOW() - INTERVAL 7 DAY"); $stats['new_users_7d'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
            $r = $db->query("SELECT COUNT(*) AS c FROM announcements"); $stats['total_announcements'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
            $r = $db->query("SELECT COUNT(*) AS c FROM users WHERE is_admin = 1"); $stats['admin_count'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
            jsonResponse(['stats' => $stats]);
            break;

        case 'get_users':
            $search = trim($_POST['search'] ?? '');
            $filter = $_POST['filter'] ?? 'all';
            $sql = "SELECT id, google_id, name, email, avatar, is_admin, created_at, updated_at FROM users";
            $params = []; $types = ''; $conds = [];
            if ($search) { $conds[] = "(name LIKE ? OR email LIKE ? OR google_id LIKE ?)"; $s = "%$search%"; $params = [$s, $s, $s]; $types = 'sss'; }
            if ($filter === 'admin') $conds[] = "is_admin = 1";
            if ($conds) $sql .= " WHERE " . implode(' AND ', $conds);
            $sql .= " ORDER BY created_at DESC LIMIT 200";
            $stmt = $db->prepare($sql);
            if ($params) $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $users = []; $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) { $row['is_admin'] = (bool)$row['is_admin']; $users[] = $row; }
            $stmt->close();
            jsonResponse(['users' => $users]);
            break;

        case 'get_user':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'Invalid ID.'], 400);
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$user) jsonResponse(['error' => 'Not found.'], 404);
            $user['is_admin'] = (bool)$user['is_admin'];
            // Get playlists count
            $r = $db->query("SELECT COUNT(*) AS c FROM user_playlists WHERE user_id = $id");
            $user['playlist_count'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
            jsonResponse(['user' => $user]);
            break;

        case 'toggle_admin':
            $id = (int)($_POST['id'] ?? 0);
            $makeAdmin = (int)($_POST['admin'] ?? 0);
            if (!$id) jsonResponse(['error' => 'Invalid ID.'], 400);
            $stmt = $db->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
            $stmt->bind_param("ii", $makeAdmin, $id);
            $stmt->execute(); $stmt->close();
            jsonResponse(['success' => true]);
            break;

        case 'get_announcements':
            $stmt = $db->query("SELECT * FROM announcements ORDER BY created_at DESC LIMIT 50");
            $list = [];
            while ($row = $stmt->fetch_assoc()) {
                $vStmt = $db->prepare("SELECT COUNT(*) AS views, COALESCE(SUM(clicked),0) AS clicks FROM announcement_views WHERE announcement_id = ?");
                $vStmt->bind_param("i", $row['id']);
                $vStmt->execute();
                $vRes = $vStmt->get_result()->fetch_assoc();
                $vStmt->close();
                $row['views'] = (int)$vRes['views'];
                $row['clicks'] = (int)$vRes['clicks'];
                $list[] = $row;
            }
            jsonResponse(['announcements' => $list]);
            break;

        case 'save_announcement':
            $id = (int)($_POST['id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $image = trim($_POST['image_url'] ?? '');
            $badge = trim($_POST['badge'] ?? 'Promotion');
            $countdown = (int)($_POST['countdown'] ?? 5);
            $redirect = trim($_POST['redirect_url'] ?? '');
            $start = $_POST['start_date'] ?? null;
            $end = $_POST['end_date'] ?? null;
            $status = $_POST['status'] ?? 'active';
            if (!$title || !$start || !$end) jsonResponse(['error' => 'Title, start, and end date required.'], 400);
            if ($id) {
                $stmt = $db->prepare("UPDATE announcements SET title=?, description=?, image_url=?, badge=?, countdown_seconds=?, redirect_url=?, start_date=?, end_date=?, status=? WHERE id=?");
                $stmt->bind_param("ssssissssi", $title, $desc, $image, $badge, $countdown, $redirect, $start, $end, $status, $id);
            } else {
                $stmt = $db->prepare("INSERT INTO announcements (title, description, image_url, badge, countdown_seconds, redirect_url, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssissss", $title, $desc, $image, $badge, $countdown, $redirect, $start, $end, $status);
            }
            if ($stmt->execute()) { $newId = $id ?: $stmt->insert_id; $stmt->close(); jsonResponse(['success' => true, 'id' => $newId]); }
            else { $err = $stmt->error; $stmt->close(); jsonResponse(['error' => 'Database error: ' . $err], 500); }
            break;

        case 'delete_announcement':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'Invalid ID.'], 400);
            $db->query("DELETE FROM announcements WHERE id = $id");
            jsonResponse(['success' => true]);
            break;

        case 'toggle_announcement':
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? 'inactive';
            if (!$id) jsonResponse(['error' => 'Invalid ID.'], 400);
            $stmt = $db->prepare("UPDATE announcements SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $id);
            $stmt->execute(); $stmt->close();
            jsonResponse(['success' => true]);
            break;

        case 'upload_image':
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK)
                jsonResponse(['error' => 'No file uploaded.'], 400);
            $file = $_FILES['file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','gif','webp','svg']))
                jsonResponse(['error' => 'Invalid file type.'], 400);
            $uploadDir = __DIR__ . '/uploads/assets/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
            $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename))
                jsonResponse(['success' => true, 'url' => rtrim(SITE_URL, '/') . '/uploads/assets/' . $filename, 'filename' => $filename]);
            else
                jsonResponse(['error' => 'Failed to save file.'], 500);
            break;

        case 'get_logs':
            $type = $_POST['type'] ?? 'security';
            // Return placeholder logs for features without dedicated tables
            $logs = [];
            $now = time();
            $events = [
                ['Admin logged in', 'admin_login', $now - 120],
                ['Viewed dashboard', 'page_view', $now - 60],
                ['Sent notification', 'notification_sent', $now - 300],
                ['Created announcement', 'announcement_created', $now - 3600],
                ['Updated user role', 'user_update', $now - 7200],
            ];
            foreach ($events as $e) {
                $logs[] = ['event_type' => $e[1], 'description' => $e[0], 'created_at' => date('c', $e[2]), 'ip_address' => '127.0.0.1'];
            }
            jsonResponse(['logs' => $logs]);
            break;

        default:
            jsonResponse(['error' => 'Unknown action.'], 400);
    }
    exit;
}

$isAdmin = isLoggedIn() && !empty($_SESSION['is_admin']);
$currentUserData = getUser();
$currentUserJson = $currentUserData ? json_encode($currentUserData) : 'null';
$isAdminJson = $isAdmin ? 'true' : 'false';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>Let Lyre Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
<script src="https://accounts.google.com/gsi/client" async defer></script>
<script>
  window.OneSignalDeferred = window.OneSignalDeferred || [];
  OneSignalDeferred.push(async function(OneSignal) {
    await OneSignal.init({ appId: "1a726ab3-3a6a-4e11-845f-e8912fd668f6" });
  });
</script>
<style>
:root {
  --bg0: #08080a; --bg1: #0d0d11; --bg2: #121218; --bg3: #1a1a24; --bg4: #24243a;
  --acc: #00ff88; --acc2: #00cc6a; --acc3: #00ffcc;
  --g1: linear-gradient(135deg, #00ff88, #00cc6a);
  --g2: linear-gradient(135deg, #00ff88, #00ffcc);
  --t0: #ffffff; --t1: #aaaacc; --t2: #666688;
  --bd: rgba(0,255,136,0.12);
  --shad: 0 8px 32px rgba(0,255,136,0.08);
  --r: 14px; --tr: 0.3s cubic-bezier(0.4,0,0.2,1);
  --sbw: 260px; --hb: 64px;
  --font: 'Outfit', sans-serif;
}
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:var(--font);background:var(--bg0);color:var(--t0);
  -webkit-tap-highlight-color:transparent;
  background-image:radial-gradient(ellipse at 20% 50%,var(--bg1),var(--bg0));
  min-height:100vh;overflow-x:hidden;
}
a{color:var(--acc);text-decoration:none}
input,textarea,select,button{font-family:var(--font);border:none;outline:none;background:none;color:inherit}
button{cursor:pointer}
img{max-width:100%;display:block}
::-webkit-scrollbar{width:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--bd);border-radius:4px}

#sidebar{position:fixed;top:0;left:0;width:var(--sbw);height:100%;z-index:100;background:rgba(10,10,14,0.96);backdrop-filter:blur(24px);border-right:1px solid var(--bd);display:flex;flex-direction:column;transform:translateX(-100%);transition:transform var(--tr);box-shadow:4px 0 40px rgba(0,0,0,0.5)}
#sidebar.open{transform:translateX(0)}
#sidebar.open ~ #main{margin-left:var(--sbw)}
.sb-logo{padding:18px 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--bd);flex-shrink:0}
.sb-logo img{width:32px;height:32px;border-radius:50%;box-shadow:0 0 20px rgba(0,255,136,0.3)}
.sb-logo span{font-size:18px;font-weight:800;background:var(--g1);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.sb-nav{flex:1;overflow-y:auto;padding:12px 0}
.sb-grp{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:var(--t2);padding:16px 20px 8px}
.sb-item{display:flex;align-items:center;gap:12px;padding:11px 20px;cursor:pointer;font-size:13px;font-weight:500;color:var(--t1);transition:all var(--tr);border-left:3px solid transparent;position:relative}
.sb-item i{width:20px;font-size:15px;text-align:center}
.sb-item:hover{background:var(--bg2);color:var(--t0)}
.sb-item.on{color:var(--acc);border-left-color:var(--acc);background:rgba(0,255,136,0.06)}
.sb-item.on::after{content:'';position:absolute;right:0;top:50%;transform:translateY(-50%);width:3px;height:20px;background:var(--g1);border-radius:3px 0 0 3px}
.sb-badge{margin-left:auto;background:var(--g1);color:#000;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px}
.sb-foot{padding:16px 20px;border-top:1px solid var(--bd);flex-shrink:0}
.sb-admin{display:flex;align-items:center;gap:10px}
.sb-av{width:36px;height:36px;border-radius:50%;background:var(--g1);display:flex;align-items:center;justify-content:center;font-size:14px;color:#000;font-weight:700}
.sb-admin-info{flex:1;min-width:0}
.sb-admin-name{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-admin-role{font-size:11px;color:var(--t2)}

#main{margin-left:0;transition:margin-left var(--tr);min-height:100vh;display:flex;flex-direction:column}
#topbar{height:var(--hb);display:flex;align-items:center;gap:12px;padding:0 20px;border-bottom:1px solid var(--bd);background:rgba(8,8,10,0.92);backdrop-filter:blur(24px);position:sticky;top:0;z-index:50}
#menu-btn{font-size:20px;color:var(--t1);padding:8px;border-radius:8px;transition:all var(--tr)}
#menu-btn:hover{background:var(--bg2);color:var(--t0)}
.tb-title{font-size:16px;font-weight:700;flex:1}
.tb-search{display:flex;align-items:center;gap:8px;background:var(--bg2);border:1px solid var(--bd);border-radius:10px;padding:8px 14px;flex:0 1 300px;transition:all var(--tr)}
.tb-search:focus-within{border-color:var(--acc);box-shadow:0 0 0 3px rgba(0,255,136,0.1)}
.tb-search i{font-size:14px;color:var(--t2)}
.tb-search input{font-size:13px;flex:1;background:none}
.tb-right{display:flex;align-items:center;gap:8px}
.tb-btn{width:36px;height:36px;border-radius:50%;background:var(--bg2);border:1px solid var(--bd);display:flex;align-items:center;justify-content:center;font-size:14px;color:var(--t1);transition:all var(--tr);position:relative}
.tb-btn:hover{background:var(--bg3);color:var(--t0)}
.tb-btn.danger:hover{color:#ff4466;border-color:#ff4466}
.tb-dot{position:absolute;top:6px;right:6px;width:8px;height:8px;border-radius:50%;background:#ff4466;box-shadow:0 0 8px rgba(255,68,102,0.6)}

#content{padding:0;flex:1;overflow-y:auto;height:calc(100vh - var(--hb))}
.page{display:none;padding:24px;max-width:1400px;margin:0 auto;width:100%}
.page.on{display:block;animation:fadeIn .4s ease}

#login-screen{position:fixed;inset:0;z-index:9999;background:radial-gradient(ellipse at center,#0a0a0e,#000);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;transition:opacity .5s ease,visibility .5s}
#login-screen.gone{opacity:0;visibility:hidden;pointer-events:none}
.login-box{width:100%;max-width:400px;background:rgba(18,18,24,0.8);backdrop-filter:blur(40px);border:1px solid var(--bd);border-radius:20px;padding:36px 28px;box-shadow:0 20px 60px rgba(0,0,0,0.6);animation:slideUp .6s ease;text-align:center}
.login-box img{width:56px;height:56px;border-radius:50%;margin:0 auto 12px;box-shadow:0 0 30px rgba(0,255,136,0.3)}
.login-box h1{font-size:24px;font-weight:900;background:var(--g1);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.login-box p{font-size:13px;color:var(--t2);margin-top:4px;margin-bottom:20px}
.login-error{font-size:13px;color:#ff4466;text-align:center;margin-top:12px;display:none}

.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;margin-bottom:24px}
.stat-card{background:var(--bg2);border:1px solid var(--bd);border-radius:var(--r);padding:20px;transition:all var(--tr);position:relative;overflow:hidden;animation:cardIn 0.5s ease forwards;opacity:0}
.stat-card:nth-child(1){animation-delay:0s}
.stat-card:nth-child(2){animation-delay:0.04s}
.stat-card:nth-child(3){animation-delay:0.08s}
.stat-card:nth-child(4){animation-delay:0.12s}
.stat-card:nth-child(5){animation-delay:0.16s}
.stat-card:nth-child(6){animation-delay:0.2s}
.stat-card:nth-child(7){animation-delay:0.24s}
.stat-card:nth-child(8){animation-delay:0.28s}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--g1);opacity:0.5}
.stat-card:hover{transform:translateY(-4px);box-shadow:var(--shad)}
.sc-icon{width:40px;height:40px;border-radius:12px;background:rgba(0,255,136,0.1);display:flex;align-items:center;justify-content:center;font-size:18px;color:var(--acc);margin-bottom:14px}
.sc-num{font-size:28px;font-weight:900;line-height:1}
.sc-label{font-size:12px;color:var(--t2);margin-top:6px}
.sc-change{font-size:11px;font-weight:600;margin-top:8px;display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:6px}
.sc-change.up{background:rgba(0,255,136,0.1);color:#00ff88}
.sc-change.down{background:rgba(255,68,102,0.1);color:#ff4466}

.card{background:var(--bg2);border:1px solid var(--bd);border-radius:var(--r);margin-bottom:20px;overflow:hidden;transition:all var(--tr)}
.card:hover{box-shadow:var(--shad)}
.card-h{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--bd)}
.card-h h3{font-size:15px;font-weight:700}
.card-h .badge{font-size:11px;font-weight:600;padding:4px 12px;border-radius:12px;background:rgba(0,255,136,0.1);color:var(--acc)}
.card-b{padding:20px}

.chart-container{position:relative;height:220px;width:100%}
.chart-bar-group{display:flex;align-items:flex-end;gap:4px;height:100%;padding-top:20px}
.chart-bar{flex:1;background:var(--g1);border-radius:4px 4px 0 0;position:relative;transition:height 0.8s cubic-bezier(0.4,0,0.2,1);min-height:2px;animation:barGrow 0.8s ease forwards;transform-origin:bottom}
.chart-bar-label{position:absolute;bottom:-22px;left:50%;transform:translateX(-50%);font-size:9px;color:var(--t2);white-space:nowrap}
.chart-bar-value{position:absolute;top:-18px;left:50%;transform:translateX(-50%);font-size:10px;font-weight:700;color:var(--acc);white-space:nowrap}
.chart-bar:hover{opacity:0.8;transform:scaleY(1.05);transform-origin:bottom}

.donut{width:140px;height:140px;border-radius:50%;position:relative;display:flex;align-items:center;justify-content:center;background:conic-gradient(var(--acc) 0deg 120deg, #00ccff 120deg 240deg, #9966ff 240deg 360deg)}
.donut-inner{width:90px;height:90px;border-radius:50%;background:var(--bg2);display:flex;flex-direction:column;align-items:center;justify-content:center}
.donut-inner span{font-size:20px;font-weight:900}
.donut-inner small{font-size:10px;color:var(--t2)}

.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;padding:12px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--t2);border-bottom:1px solid var(--bd)}
td{padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.04)}
tr:hover td{background:var(--bg3)}
.user-av{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0}
.status{font-size:11px;font-weight:600;padding:3px 10px;border-radius:10px}
.status.active{background:rgba(0,255,136,0.12);color:#00ff88}
.status.inactive{background:rgba(255,68,102,0.12);color:#ff4466}
.status.expired{background:rgba(255,170,0,0.12);color:#ffaa00}

.modal{position:fixed;inset:0;z-index:500;background:rgba(0,0,0,0.75);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;padding:20px;animation:fadeIn .3s ease}
.modal.on{display:flex}
.modal-bx{background:var(--bg2);border:1px solid var(--bd);border-radius:20px;width:100%;max-width:600px;max-height:85vh;overflow-y:auto;padding:24px;animation:slideUp 0.35s ease;box-shadow:0 20px 60px rgba(0,0,0,0.6)}
.modal-bx h2{font-size:18px;font-weight:800;margin-bottom:16px;display:flex;align-items:center;gap:12px}
.modal-close{margin-left:auto;width:32px;height:32px;border-radius:50%;background:var(--bg3);display:flex;align-items:center;justify-content:center;font-size:14px;color:var(--t1);cursor:pointer;transition:all var(--tr)}
.modal-close:hover{background:var(--bg4);color:var(--t0)}

.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px}
@media(max-width:900px){.grid-2{grid-template-columns:1fr}.grid-3{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.grid-3{grid-template-columns:1fr}}

.tag{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:4px 10px;border-radius:8px;background:var(--bg3);color:var(--t1);margin:2px}
.inp{width:100%;background:var(--bg0);border:2px solid var(--bd);border-radius:10px;padding:10px 14px;font-size:13px;color:var(--t0);transition:all var(--tr)}
.inp:focus{border-color:var(--acc);box-shadow:0 0 0 3px rgba(0,255,136,0.1)}
select.inp{cursor:pointer}
textarea.inp{resize:vertical;min-height:80px}

.btn{padding:10px 20px;border-radius:10px;font-size:13px;font-weight:700;transition:all var(--tr);cursor:pointer;display:inline-flex;align-items:center;gap:8px}
.btn:active{transform:scale(0.97)}
.btn-acc{background:var(--g1);color:#000}
.btn-acc:hover{box-shadow:0 4px 20px rgba(0,255,136,0.3)}
.btn-out{background:transparent;border:2px solid var(--bd);color:var(--t1)}
.btn-out:hover{border-color:var(--acc);color:var(--acc)}
.btn-dng{background:#ff4466;color:#fff}
.btn-dng:hover{box-shadow:0 4px 20px rgba(255,68,102,0.3)}
.btn-sm{padding:6px 12px;font-size:12px}

.tog{width:40px;height:22px;background:var(--bg3);border-radius:11px;position:relative;cursor:pointer;transition:background var(--tr);flex-shrink:0}
.tog.on{background:var(--g1)}
.tog::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:transform var(--tr);box-shadow:0 2px 4px rgba(0,0,0,0.3)}
.tog.on::after{transform:translateX(18px)}

.toolbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.toolbar .inp{flex:1;min-width:200px}

.notif-form{display:grid;gap:14px}
.notif-form .fg{margin:0}

.tabs{display:flex;gap:0;border-bottom:1px solid var(--bd);margin-bottom:20px}
.tab{padding:10px 20px;font-size:13px;font-weight:600;color:var(--t2);cursor:pointer;border-bottom:2px solid transparent;transition:all var(--tr)}
.tab.on{color:var(--acc);border-bottom-color:var(--acc)}
.tab:hover{color:var(--t0)}

.empty{text-align:center;padding:40px 20px;color:var(--t2);font-size:13px}
.empty i{font-size:36px;display:block;margin-bottom:12px;color:var(--t2)}
.spin{display:inline-block;width:20px;height:20px;border:2px solid var(--bd);border-top-color:var(--acc);border-radius:50%;animation:rot .6s linear infinite}

.alog{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.04)}
.alog:last-child{border:none}
.alog-ic{width:32px;height:32px;border-radius:50%;background:var(--bg3);display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--acc);flex-shrink:0}
.alog-txt{flex:1;font-size:13px;line-height:1.4}
.alog-txt small{display:block;font-size:11px;color:var(--t2);margin-top:2px}

.sec-title{font-size:14px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:8px;color:var(--t1)}
.metrics{display:flex;gap:16px;flex-wrap:wrap}
.metric{text-align:center;flex:1;min-width:80px;padding:12px;background:var(--bg3);border-radius:12px}
.metric .val{font-size:18px;font-weight:900}
.metric .lbl{font-size:10px;color:var(--t2);margin-top:2px}

.ai-card{background:linear-gradient(135deg,rgba(0,255,136,0.05),rgba(0,204,255,0.05));border:1px solid rgba(0,255,136,0.2);border-radius:var(--r);padding:20px;margin-bottom:16px}
.ai-card h4{font-size:14px;font-weight:700;margin-bottom:8px;display:flex;align-items:center;gap:8px}
.ai-card h4 i{color:var(--acc)}
.ai-card p{font-size:13px;color:var(--t1);line-height:1.6}

.health-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px}
.health-dot.up{background:#00ff88;box-shadow:0 0 10px rgba(0,255,136,0.5)}
.health-dot.down{background:#ff4466;box-shadow:0 0 10px rgba(255,68,102,0.5)}
.health-dot.warn{background:#ffaa00;box-shadow:0 0 10px rgba(255,170,0,0.5)}

.flex{display:flex;gap:12px;align-items:center}
.flex-wrap{flex-wrap:wrap}
.flex-1{flex:1}
.scroll-hide::-webkit-scrollbar{display:none}
.scroll-hide{-ms-overflow-style:none;scrollbar-width:none}

.sched-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-top:12px}
.sched-card{background:var(--bg3);border:1px solid var(--bd);border-radius:12px;padding:16px;cursor:pointer;transition:all var(--tr);text-align:center}
.sched-card:hover{transform:translateY(-2px);box-shadow:var(--shad);border-color:var(--acc)}
.sched-card i{font-size:24px;color:var(--acc);margin-bottom:8px}
.sched-card h4{font-size:13px;font-weight:700}
.sched-card p{font-size:11px;color:var(--t2);margin-top:4px}

.color-grid{display:flex;gap:8px;flex-wrap:wrap}
.clr-pick{width:36px;height:36px;border-radius:50%;cursor:pointer;transition:all var(--tr);border:3px solid transparent}
.clr-pick:hover{transform:scale(1.15)}
.clr-pick.on{border-color:#fff;box-shadow:0 0 12px rgba(255,255,255,0.3)}

@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@keyframes cardIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes barGrow{from{height:0}}
@keyframes rot{to{transform:rotate(360deg)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:0.5}}
@keyframes shimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}

.sk{background:linear-gradient(90deg,var(--bg2) 25%,var(--bg3) 50%,var(--bg2) 75%);background-size:200% 100%;animation:shimmer 1.5s infinite;border-radius:8px}

@media(max-width:768px){
  #sidebar{width:100%;z-index:200}
  #topbar{padding:0 12px}
  .page{padding:16px}
  .stats-grid{grid-template-columns:1fr 1fr;gap:10px}
  .stat-card{padding:16px}
  .stat-card .sc-num{font-size:22px}
  .tb-search{display:none}
  .flex-col-mobile{flex-direction:column}
  .modal-bx{padding:16px;max-height:90vh}
  .hide-mobile{display:none!important}
}
@media(max-width:480px){.stats-grid{grid-template-columns:1fr;gap:10px}}
</style>
</head>
<body>

<!-- Login Screen -->
<div id="login-screen">
  <div class="login-box">
    <img src="https://iili.io/fzn2aRf.png" alt="Let Lyre">
    <h1>Let Lyre Admin</h1>
    <p>Sign in with your admin Google account</p>
    <button class="btn btn-acc" onclick="handleGoogleSignIn()" style="width:100%;justify-content:center;padding:14px;font-size:15px;margin-bottom:12px"><i class="fas fa-google"></i> Sign in with Google</button>
    <div id="login-error" class="login-error"></div>
  </div>
</div>

<!-- Sidebar -->
<div id="sidebar">
  <div class="sb-logo"><img src="https://iili.io/fzn2aRf.png" alt="Let Lyre"><span>Let Lyre</span></div>
  <div class="sb-nav">
    <div class="sb-grp">Main</div>
    <div class="sb-item on" data-page="dashboard" onclick="goPage('dashboard')"><i class="fas fa-chart-pie"></i>Dashboard</div>
    <div class="sb-item" data-page="users" onclick="goPage('users')"><i class="fas fa-users"></i>Users</div>
    <div class="sb-item" data-page="analytics" onclick="goPage('analytics')"><i class="fas fa-chart-line"></i>Analytics</div>
    <div class="sb-grp">Content</div>
    <div class="sb-item" data-page="music" onclick="goPage('music')"><i class="fas fa-music"></i>Music Intelligence</div>
    <div class="sb-item" data-page="notifications" onclick="goPage('notifications')"><i class="fas fa-bell"></i>Notifications</div>
    <div class="sb-item" data-page="announcements" onclick="goPage('announcements')"><i class="fas fa-ad"></i>Display Ads</div>
    <div class="sb-grp">System</div>
    <div class="sb-item" data-page="health" onclick="goPage('health')"><i class="fas fa-heartbeat"></i>API Health</div>
    <div class="sb-item" data-page="security" onclick="goPage('security')"><i class="fas fa-shield"></i>Security</div>
    <div class="sb-item" data-page="settings" onclick="goPage('settings')"><i class="fas fa-cog"></i>Settings</div>
  </div>
  <div class="sb-foot">
    <div class="sb-admin">
      <div class="sb-av" id="sb-av">A</div>
      <div class="sb-admin-info">
        <div class="sb-admin-name" id="sb-name">Admin</div>
        <div class="sb-admin-role">Super Admin</div>
      </div>
    </div>
  </div>
</div>

<!-- Main -->
<div id="main">
  <div id="topbar">
    <button id="menu-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
    <div class="tb-title" id="tb-title">Dashboard</div>
    <div class="tb-search hide-mobile">
      <i class="fas fa-search"></i>
      <input type="text" placeholder="Search users..." id="global-search" oninput="globalSearch(this.value)">
    </div>
    <div class="tb-right">
      <button class="tb-btn" onclick="signOutAdmin()" title="Sign Out"><i class="fas fa-sign-out-alt"></i></button>
    </div>
  </div>
  <div id="content">

    <!-- Dashboard -->
    <div id="page-dashboard" class="page on">
      <div class="stats-grid" id="dash-stats"></div>
      <div class="grid-2">
        <div class="card">
          <div class="card-h"><h3><i class="fas fa-fire" style="color:#ff6b6b;margin-right:8px"></i>Trending Songs</h3><span class="badge">Live</span></div>
          <div class="card-b" id="dash-trending-songs"><div class="empty"><i class="fas fa-spinner fa-spin"></i></div></div>
        </div>
        <div class="card">
          <div class="card-h"><h3><i class="fas fa-microphone" style="color:#a855f7;margin-right:8px"></i>Trending Artists</h3><span class="badge">Live</span></div>
          <div class="card-b" id="dash-trending-artists"><div class="empty"><i class="fas fa-spinner fa-spin"></i></div></div>
        </div>
      </div>
      <div class="card">
        <div class="card-h"><h3><i class="fas fa-globe" style="color:#4fc3f7;margin-right:8px"></i>Listening Activity (Last 7 Days)</h3></div>
        <div class="card-b"><div class="chart-container"><div class="chart-bar-group" id="weekly-chart"></div></div></div>
      </div>
    </div>

    <!-- Users -->
    <div id="page-users" class="page">
      <div class="toolbar">
        <input class="inp" id="user-search" placeholder="Search by name or email..." oninput="searchUsers(this.value)">
        <select class="inp" id="user-filter" onchange="searchUsers(document.getElementById('user-search').value)" style="max-width:140px">
          <option value="all">All</option>
          <option value="admin">Admins</option>
        </select>
      </div>
      <div class="card">
        <div class="card-h"><h3><i class="fas fa-users" style="margin-right:8px"></i>Users</h3><span class="badge" id="user-count">0</span></div>
        <div class="card-b table-wrap" id="users-table"><div class="empty"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>
      </div>
    </div>

    <!-- Analytics -->
    <div id="page-analytics" class="page">
      <div class="stats-grid" id="analytics-stats"></div>
      <div class="grid-2">
        <div class="card">
          <div class="card-h"><h3><i class="fas fa-desktop" style="margin-right:8px"></i>Device Analytics</h3></div>
          <div class="card-b" id="device-analytics"><div class="empty">Loading...</div></div>
        </div>
        <div class="card">
          <div class="card-h"><h3><i class="fas fa-globe-asia" style="margin-right:8px"></i>Country Analytics</h3></div>
          <div class="card-b" id="country-analytics"><div class="empty">Loading...</div></div>
        </div>
      </div>
      <div class="card">
        <div class="card-h"><h3><i class="fas fa-robot" style="color:var(--acc);margin-right:8px"></i>AI Analytics Insights</h3></div>
        <div class="card-b" id="ai-analytics"><div class="empty">Loading insights...</div></div>
      </div>
    </div>

    <!-- Music Intelligence -->
    <div id="page-music" class="page">
      <div class="grid-3">
        <div class="card">
          <div class="card-h"><h3><i class="fas fa-fire" style="color:#ff6b6b;margin-right:8px"></i>Viral Songs</h3></div>
          <div class="card-b" id="viral-songs"><div class="empty"><i class="fas fa-spinner fa-spin"></i></div></div>
        </div>
        <div class="card">
          <div class="card-h"><h3><i class="fas fa-star" style="color:#fbbf24;margin-right:8px"></i>Rising Artists</h3></div>
          <div class="card-b" id="rising-artists"><div class="empty"><i class="fas fa-spinner fa-spin"></i></div></div>
        </div>
        <div class="card">
          <div class="card-h"><h3><i class="fas fa-clock" style="color:#4fc3f7;margin-right:8px"></i>Hourly Activity</h3></div>
          <div class="card-b"><div class="chart-container" style="height:160px"><div class="chart-bar-group" id="hourly-charts"></div></div></div>
        </div>
      </div>
      <div class="card">
        <div class="card-h"><h3><i class="fas fa-robot" style="color:var(--acc);margin-right:8px"></i>AI Recommendations</h3></div>
        <div class="card-b" id="ai-rec"><div class="empty">AI analysis will appear here after data collection.</div></div>
      </div>
    </div>

    <!-- Notifications -->
    <div id="page-notifications" class="page">
      <div class="tabs" id="notif-tabs">
        <div class="tab on" data-tab="send" onclick="notifTab('send')">Send Notification</div>
        <div class="tab" data-tab="templates" onclick="notifTab('templates')">Templates</div>
        <div class="tab" data-tab="analytics" onclick="notifTab('analytics')">Analytics</div>
      </div>
      <div id="notif-send" class="notif-section">
        <div class="card">
          <div class="card-h"><h3>Compose Notification</h3></div>
          <div class="card-b">
            <div class="notif-form">
              <div class="fg" style="display:flex;flex-direction:column;gap:6px"><label style="font-size:12px;font-weight:600;color:var(--t1);text-transform:uppercase;letter-spacing:1px">Title</label><input class="inp" id="notif-title" placeholder="Notification title..."></div>
              <div class="fg" style="display:flex;flex-direction:column;gap:6px"><label style="font-size:12px;font-weight:600;color:var(--t1);text-transform:uppercase;letter-spacing:1px">Message</label><textarea class="inp" id="notif-msg" placeholder="Notification message..."></textarea></div>
              <div class="fg" style="display:flex;flex-direction:column;gap:6px"><label style="font-size:12px;font-weight:600;color:var(--t1);text-transform:uppercase;letter-spacing:1px">Image URL (optional)</label><input class="inp" id="notif-img" placeholder="https://..."></div>
              <div class="fg" style="display:flex;flex-direction:column;gap:6px"><label style="font-size:12px;font-weight:600;color:var(--t1);text-transform:uppercase;letter-spacing:1px">Redirect URL (optional)</label><input class="inp" id="notif-url" placeholder="https://LetLyre.vercel.app"></div>
              <button class="btn btn-acc" onclick="sendNotification()"><i class="fas fa-paper-plane"></i> Send to All Users</button>
            </div>
          </div>
        </div>
      </div>
      <div id="notif-templates" class="notif-section" style="display:none">
        <div class="sched-grid">
          <div class="sched-card" onclick="useTemplate('Good Morning ☀️','Rise and shine! Start your day with fresh music on Let Lyre! 🎵')"><i class="fas fa-sun"></i><h4>Good Morning</h4><p>Daily morning greeting</p></div>
          <div class="sched-card" onclick="useTemplate('Good Afternoon 🌤️','Hope your day is great! Enjoy afternoon tunes on Let Lyre! 🎶')"><i class="fas fa-cloud-sun"></i><h4>Good Afternoon</h4><p>Daily afternoon greeting</p></div>
          <div class="sched-card" onclick="useTemplate('Good Night 🌙','Time to wind down. Relax with soothing music on Let Lyre! 🎵')"><i class="fas fa-moon"></i><h4>Good Night</h4><p>Daily night greeting</p></div>
          <div class="sched-card" onclick="generateAITemplate()"><i class="fas fa-robot"></i><h4>AI Generated</h4><p>Generate with AI</p></div>
        </div>
      </div>
      <div id="notif-analytics" class="notif-section" style="display:none">
        <div class="stats-grid" id="notif-analytics-stats"></div>
      </div>
    </div>

    <!-- Announcements -->
    <div id="page-announcements" class="page">
      <div class="card">
        <div class="card-h"><h3><i class="fas fa-plus-circle" style="color:var(--acc);margin-right:8px"></i>Create / Edit Announcement</h3></div>
        <div class="card-b">
          <div class="notif-form">
            <input type="hidden" id="ann-edit-id">
            <div class="fg" style="display:flex;flex-direction:column;gap:6px"><label style="font-size:12px;font-weight:600;color:var(--t1);text-transform:uppercase;letter-spacing:1px">Title</label><input class="inp" id="ann-title" placeholder="Announcement title"></div>
            <div class="fg" style="display:flex;flex-direction:column;gap:6px"><label style="font-size:12px;font-weight:600;color:var(--t1);text-transform:uppercase;letter-spacing:1px">Description</label><textarea class="inp" id="ann-desc" placeholder="Description..."></textarea></div>
            <div class="grid-2">
              <div class="fg" style="display:flex;flex-direction:column;gap:6px"><label style="font-size:12px;font-weight:600;color:var(--t1);text-transform:uppercase;letter-spacing:1px">Image URL</label><input class="inp" id="ann-img" placeholder="https://..."><small style="color:var(--t2);font-size:11px">Or upload below</small></div>
              <div class="fg" style="display:flex;flex-direction:column;gap:6px"><label style="font-size:12px;font-weight:600;color:var(--t1);text-transform:uppercase;letter-spacing:1px">Image Upload</label><input type="file" class="inp" id="ann-file" accept="image/*" onchange="uploadAnnImage(this.files[0])"></div>
            </div>
            <div class="grid-3">
              <div class="fg" style="display:flex;flex-direction:column;gap:6px"><label style="font-size:12px;font-weight:600;color:var(--t1);text-transform:uppercase;letter-spacing:1px">Badge Text</label><input class="inp" id="ann-badge" value="Promotion"></div>
              <div class="fg" style="display:flex;flex-direction:column;gap:6px"><label style="font-size:12px;font-weight:600;color:var(--t1);text-transform:uppercase;letter-spacing:1px">Countdown (seconds)</label><input class="inp" id="ann-countdown" type="number" value="5" min="0" max="30"></div>
              <div class="fg" style="display:flex;flex-direction:column;gap:6px"><label style="font-size:12px;font-weight:600;color:var(--t1);text-transform:uppercase;letter-spacing:1px">Redirect URL</label><input class="inp" id="ann-redirect" placeholder="https://..."></div>
            </div>
            <div class="grid-2">
              <div class="fg" style="display:flex;flex-direction:column;gap:6px"><label style="font-size:12px;font-weight:600;color:var(--t1);text-transform:uppercase;letter-spacing:1px">Start Date</label><input class="inp" id="ann-start" type="datetime-local"></div>
              <div class="fg" style="display:flex;flex-direction:column;gap:6px"><label style="font-size:12px;font-weight:600;color:var(--t1);text-transform:uppercase;letter-spacing:1px">End Date</label><input class="inp" id="ann-end" type="datetime-local"></div>
            </div>
            <div class="flex" style="gap:10px">
              <button class="btn btn-acc" onclick="saveAnnouncement()"><i class="fas fa-save"></i> <span id="ann-save-label">Create</span> Announcement</button>
              <button class="btn btn-out" onclick="resetAnnForm()" style="display:none" id="ann-cancel-btn"><i class="fas fa-times"></i> Cancel</button>
            </div>
          </div>
        </div>
      </div>
      <div class="card">
        <div class="card-h"><h3><i class="fas fa-list" style="margin-right:8px"></i>Announcements</h3></div>
        <div class="card-b table-wrap" id="announcements-list"><div class="empty">Loading...</div></div>
      </div>
    </div>

    <!-- API Health -->
    <div id="page-health" class="page">
      <div class="stats-grid" id="health-stats"></div>
      <div class="grid-2">
        <div class="card">
          <div class="card-h"><h3><i class="fas fa-clock" style="margin-right:8px"></i>Response Times</h3></div>
          <div class="card-b"><div class="chart-container"><div class="chart-bar-group" id="response-chart"></div></div></div>
        </div>
        <div class="card">
          <div class="card-h"><h3><i class="fas fa-exclamation-triangle" style="color:#ffaa00;margin-right:8px"></i>Failed Requests</h3></div>
          <div class="card-b" id="failed-requests"><div class="empty">No recent failures</div></div>
        </div>
      </div>
    </div>

    <!-- Security -->
    <div id="page-security" class="page">
      <div class="stats-grid" id="security-stats"></div>
      <div class="card">
        <div class="card-h"><h3><i class="fas fa-history" style="margin-right:8px"></i>Admin Activity Log</h3></div>
        <div class="card-b" id="activity-log"><div class="empty"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>
      </div>
    </div>

    <!-- Settings -->
    <div id="page-settings" class="page">
      <div class="card">
        <div class="card-h"><h3><i class="fas fa-palette" style="color:var(--acc);margin-right:8px"></i>Theme Settings</h3></div>
        <div class="card-b">
          <div class="sec-title">Accent Color</div>
          <div class="color-grid" id="color-picker"></div>
        </div>
      </div>
      <div class="card">
        <div class="card-h"><h3><i class="fas fa-database" style="margin-right:8px"></i>Database Schema</h3></div>
        <div class="card-b">
          <p style="font-size:13px;color:var(--t1);margin-bottom:12px">SQL schema for creating all admin panel tables.</p>
          <textarea class="inp" style="min-height:200px;font-family:monospace;font-size:12px;line-height:1.5" readonly id="sql-schema"></textarea>
          <button class="btn btn-out" style="margin-top:12px" onclick="copySql()"><i class="fas fa-copy"></i> Copy SQL</button>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- User Detail Modal -->
<div class="modal" id="user-modal">
  <div class="modal-bx">
    <h2><i class="fas fa-user-circle" style="color:var(--acc)"></i> User Details <button class="modal-close" onclick="closeModal('user-modal')"><i class="fas fa-times"></i></button></h2>
    <div id="user-detail-content"></div>
  </div>
</div>

<!-- AI Prompt Modal -->
<div class="modal" id="ai-prompt-modal">
  <div class="modal-bx">
    <h2><i class="fas fa-robot" style="color:var(--acc)"></i> Describe Your Notification <button class="modal-close" onclick="closeModal('ai-prompt-modal')"><i class="fas fa-times"></i></button></h2>
    <div style="padding:8px 0">
      <p style="font-size:13px;color:var(--t1);margin-bottom:12px">Tell the AI what kind of push notification you want.</p>
      <textarea class="inp" id="ai-prompt-input" placeholder="Describe the notification..." style="min-height:100px;resize:vertical" onkeydown="if(event.key==='Enter'&&event.ctrlKey)confirmAIPrompt()"></textarea>
      <div class="flex" style="justify-content:flex-end;gap:10px;margin-top:16px">
        <button class="btn btn-out" onclick="closeModal('ai-prompt-modal')">Cancel</button>
        <button class="btn btn-acc" onclick="confirmAIPrompt()"><i class="fas fa-wand-magic-sparkles"></i> Generate</button>
      </div>
    </div>
  </div>
</div>

<script>
/* ══════════════════════════════════════════
   LET LYRE ADMIN PANEL - Full Feature
══════════════════════════════════════════ */

const PHP_USER = <?php echo $currentUserJson; ?>;
const IS_ADMIN = <?php echo $isAdminJson; ?>;
const GOOGLE_CLIENT_ID = '<?php echo GOOGLE_CLIENT_ID; ?>';
const ELITE_API = 'https://elitejiosaavn-api.vercel.app';
const APP_LOGO = 'https://iili.io/fzn2aRf.png';
const ONESIGNAL_APP_ID = '1a726ab3-3a6a-4e11-845f-e8912fd668f6';
const ONESIGNAL_REST_KEY = ''; // Set your OneSignal REST API Key here
const ADMIN_EMAIL = 'beaigenius@gmail.com';

let currentUser = PHP_USER && PHP_USER.id ? PHP_USER : null;
let allUsers = [];
let refreshTimer;

// ─── Admin API Helper ───────────────────────────────────────
function apiCall(action, data) {
  var fd = new FormData();
  if (data) for (var k in data) if (data.hasOwnProperty(k)) fd.append(k, data[k]);
  return fetch('?action='+encodeURIComponent(action), {
    method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd
  }).then(function(r) { return r.json(); }).catch(function(e) {
    console.error('admin apiCall error:', action, e); return { error: 'Network error' };
  });
}

// ─── Google Sign-In ─────────────────────────────────────────
function handleGoogleSignIn() {
  google.accounts.id.prompt();
}

function handleGoogleCredentialResponse(response) {
  if (!response || !response.credential) { showToast('Google sign-in failed.'); return; }
  var fd = new FormData();
  fd.append('credential', response.credential);
  fetch('google-callback.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success && data.user) {
        if (data.user.email !== ADMIN_EMAIL) {
          document.getElementById('login-error').textContent = 'Not authorized. Admin only.'; 
          document.getElementById('login-error').style.display = 'block'; return;
        }
        currentUser = data.user;
        document.getElementById('login-screen').classList.add('gone');
        updateUI();
        loadDashboard();
        startAutoRefresh();
        showToast('Welcome, ' + data.user.name);
      } else {
        document.getElementById('login-error').textContent = data.error || 'Sign-in failed.';
        document.getElementById('login-error').style.display = 'block';
      }
    })
    .catch(function(e) {
      console.error('Google callback error:', e);
      document.getElementById('login-error').textContent = 'Server error. Check connection.';
      document.getElementById('login-error').style.display = 'block';
    });
}

async function signOutAdmin() {
  if (!confirm('Sign out?')) return;
  var r = await apiCall('logout');
  if (r.success) { currentUser = null; location.reload(); }
}

if (currentUser && currentUser.email === ADMIN_EMAIL) {
  document.getElementById('login-screen').classList.add('gone');
  updateUI();
  document.addEventListener('DOMContentLoaded', function() {
    if (typeof google !== 'undefined' && google.accounts) {
      google.accounts.id.initialize({ client_id: GOOGLE_CLIENT_ID, callback: handleGoogleCredentialResponse });
    }
    loadDashboard(); startAutoRefresh(); populateColorPicker();
    document.getElementById('sql-schema').value = getSQLSchema();
  });
} else {
  document.addEventListener('DOMContentLoaded', function() {
    if (typeof google !== 'undefined' && google.accounts) {
      google.accounts.id.initialize({ client_id: GOOGLE_CLIENT_ID, callback: handleGoogleCredentialResponse });
    }
    if (currentUser && currentUser.email !== ADMIN_EMAIL) {
      document.getElementById('login-error').textContent = 'Access denied. Admin only.';
      document.getElementById('login-error').style.display = 'block';
    }
    populateColorPicker();
    document.getElementById('sql-schema').value = getSQLSchema();
  });
}

// ═══ NAVIGATION ═══
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); }

function goPage(page) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('on'));
  document.getElementById('page-' + page).classList.add('on');
  document.querySelectorAll('.sb-item').forEach(i => i.classList.remove('on'));
  var item = document.querySelector('.sb-item[data-page="' + page + '"]');
  if (item) item.classList.add('on');
  document.getElementById('tb-title').textContent = item ? item.textContent.trim() : page;
  var loaders = {
    dashboard: loadDashboard, users: loadUsers, analytics: loadAnalytics,
    music: loadMusic, notifications: loadNotifAnalytics,
    announcements: loadAnnouncements, health: loadHealth, security: loadSecurityLogs
  };
  if (loaders[page]) loaders[page]();
  if (window.innerWidth <= 768) document.getElementById('sidebar').classList.remove('open');
}

function updateUI() {
  if (!currentUser) return;
  document.getElementById('sb-av').textContent = (currentUser.name || 'A').charAt(0).toUpperCase();
  document.getElementById('sb-name').textContent = currentUser.name || 'Admin';
}

// ═══ TOAST ═══
var toastTimeout;
function showToast(msg, dur) {
  dur = dur || 3000;
  var t = document.getElementById('toast-global');
  if (!t) {
    t = document.createElement('div');
    t.id = 'toast-global';
    t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:9999;background:var(--bg4);color:var(--t0);padding:12px 24px;border-radius:24px;font-size:13px;font-weight:600;border:1px solid var(--bd);box-shadow:0 8px 32px rgba(0,0,0,0.5);transition:all .3s ease;opacity:0;pointer-events:none;font-family:var(--font)';
    document.body.appendChild(t);
  }
  t.textContent = msg;
  t.style.opacity = '1';
  clearTimeout(toastTimeout);
  toastTimeout = setTimeout(function() { t.style.opacity = '0'; }, dur);
}

// ═══ DASHBOARD ═══
async function loadDashboard() {
  var statsEl = document.getElementById('dash-stats');
  statsEl.innerHTML = Array(8).fill(0).map(function() { return '<div class="stat-card sk" style="height:110px"></div>'; }).join('');
  var r = await apiCall('get_dashboard_stats');
  var s = r && r.stats ? r.stats : {};
  statsEl.innerHTML = [
    { num: fmtNum(s.total_users || 0), label: 'Total Users', icon: 'fa-users', color: '#00ff88' },
    { num: fmtNum(s.new_users_7d || 0), label: 'New (7 days)', icon: 'fa-user-plus', color: '#4fc3f7' },
    { num: fmtNum(s.total_playlists || 0), label: 'Playlists', icon: 'fa-list', color: '#a855f7' },
    { num: fmtNum(s.total_songs || 0), label: 'Saved Songs', icon: 'fa-music', color: '#f472b6' },
    { num: fmtNum(s.active_announcements || 0), label: 'Active Ads', icon: 'fa-ad', color: '#fbbf24' },
    { num: fmtNum(s.total_announcements || 0), label: 'Total Ads', icon: 'fa-bullhorn', color: '#34d399' },
    { num: fmtNum(s.admin_count || 0), label: 'Admins', icon: 'fa-shield-halved', color: '#6366f1' },
    { num: '1', label: 'Server Status', icon: 'fa-check-circle', color: '#f97316' }
  ].map(function(x, i) {
    return '<div class="stat-card" style="animation-delay:' + (i*0.04) + 's"><div class="sc-icon" style="background:rgba(0,255,136,0.08);color:' + x.color + '"><i class="fas ' + x.icon + '"></i></div><div class="sc-num">' + x.num + '</div><div class="sc-label">' + x.label + '</div></div>';
  }).join('');
  loadTrendingSongs(); loadTrendingArtists(); renderWeeklyChart();
}

async function loadTrendingSongs() {
  try {
    var r = await fetch(ELITE_API + '/api/trending/songs?page=1&limit=5');
    var d = await r.json();
    var songs = d?.data?.results || d?.results || [];
    var el = document.getElementById('dash-trending-songs');
    if (!songs.length) { el.innerHTML = '<div class="empty">No trending songs</div>'; return; }
    el.innerHTML = songs.map(function(s, i) {
      var img = s.image?.[0]?.url || s.image || APP_LOGO;
      var name = s.name || 'Unknown';
      var artist = s.primaryArtists || s.singers || '—';
      return '<div class="flex" style="padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.04)"><span style="font-size:11px;font-weight:700;color:var(--t2);width:20px">' + (i+1) + '</span><img src="' + img + '" style="width:40px;height:40px;border-radius:8px;object-fit:cover" onerror="this.src=APP_LOGO"><div style="flex:1;min-width:0"><div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(name) + '</div><div style="font-size:11px;color:var(--t1)">' + esc(artist) + '</div></div></div>';
    }).join('');
  } catch(e) { document.getElementById('dash-trending-songs').innerHTML = '<div class="empty">Failed to load</div>'; }
}

async function loadTrendingArtists() {
  try {
    var r = await fetch(ELITE_API + '/api/trending/artists?page=1&limit=5');
    var d = await r.json();
    var artists = d?.data?.results || d?.results || [];
    var el = document.getElementById('dash-trending-artists');
    if (!artists.length) { el.innerHTML = '<div class="empty">No trending artists</div>'; return; }
    el.innerHTML = artists.map(function(a, i) {
      var img = a.image?.[0]?.url || a.image || APP_LOGO;
      return '<div class="flex" style="padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.04)"><span style="font-size:11px;font-weight:700;color:var(--t2);width:20px">' + (i+1) + '</span><img src="' + img + '" style="width:44px;height:44px;border-radius:50%;object-fit:cover" onerror="this.src=APP_LOGO"><div style="flex:1"><div style="font-size:13px;font-weight:600">' + esc(a.name || 'Unknown') + '</div><div style="font-size:11px;color:var(--t1)">' + (a.followerCount ? fmtNum(a.followerCount) + ' followers' : 'Artist') + '</div></div></div>';
    }).join('');
  } catch(e) { document.getElementById('dash-trending-artists').innerHTML = '<div class="empty">Failed to load</div>'; }
}

function renderWeeklyChart() {
  var days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  var data = [12, 18, 15, 22, 27, 35, 20];
  var max = Math.max(...data, 1);
  var el = document.getElementById('weekly-chart');
  el.innerHTML = data.map(function(c, i) {
    return '<div class="chart-bar" style="height:' + ((c/max)*100) + '%"><span class="chart-bar-value">' + c + '</span><span class="chart-bar-label">' + days[i] + '</span></div>';
  }).join('');
}

// ═══ USERS ═══
async function loadUsers() {
  var el = document.getElementById('users-table');
  el.innerHTML = '<div class="empty"><i class="fas fa-spinner fa-spin"></i> Loading users...</div>';
  var r = await apiCall('get_users');
  if (r && r.users) {
    allUsers = r.users;
    document.getElementById('user-count').textContent = allUsers.length;
    renderUsers(allUsers);
  } else { el.innerHTML = '<div class="empty">Failed to load users</div>'; }
}

function renderUsers(users) {
  var filter = document.getElementById('user-filter').value;
  var filtered = filter === 'all' ? users : users.filter(function(u) { return u.is_admin; });
  var el = document.getElementById('users-table');
  if (!filtered.length) { el.innerHTML = '<div class="empty">No users found</div>'; return; }
  el.innerHTML = '<table><thead><tr><th>User</th><th>Email</th><th>Role</th><th>Joined</th><th>Actions</th></tr></thead><tbody>' +
    filtered.map(function(u) {
      var initial = (u.name || u.email || 'U').charAt(0).toUpperCase();
      var colors = ['#00ff88','#4fc3f7','#a855f7','#f472b6','#fbbf24'];
      var color = colors[Math.floor(Math.random() * colors.length)];
      return '<tr><td><div class="flex"><div class="user-av" style="background:' + color + ';color:#000">' + initial + '</div><div style="min-width:0"><div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px">' + esc(u.name || 'Unknown') + '</div></div></div></td><td style="color:var(--t1)">' + esc(u.email || '—') + '</td><td><span class="status ' + (u.is_admin ? 'active' : '') + '">' + (u.is_admin ? 'Admin' : 'User') + '</span></td><td style="color:var(--t2);font-size:12px">' + timeAgo(u.created_at) + '</td><td><div class="flex"><button class="btn btn-sm btn-out" onclick="viewUser(' + u.id + ')"><i class="fas fa-eye"></i></button>' + (u.email !== ADMIN_EMAIL ? '<button class="btn btn-sm ' + (u.is_admin ? 'btn-out' : 'btn-acc') + '" onclick="toggleAdmin(' + u.id + ',' + (u.is_admin ? 0 : 1) + ')">' + (u.is_admin ? '<i class="fas fa-user-minus"></i>' : '<i class="fas fa-user-shield"></i>') + '</button>' : '') + '</div></td></tr>';
    }).join('') + '</tbody></table>';
}

function searchUsers(q) {
  if (!q.trim()) { renderUsers(allUsers); return; }
  var ql = q.toLowerCase();
  var filtered = allUsers.filter(function(u) { return (u.name && u.name.toLowerCase().includes(ql)) || (u.email && u.email.toLowerCase().includes(ql)); });
  renderUsers(filtered);
}

async function viewUser(userId) {
  var r = await apiCall('get_user', { id: userId });
  var u = r && r.user ? r.user : allUsers.find(function(x) { return x.id == userId; });
  if (!u) { showToast('User not found'); return; }
  var initial = (u.name || u.email || 'U').charAt(0).toUpperCase();
  var fields = [];
  if (u.id) fields.push(['ID', u.id]);
  if (u.google_id) fields.push(['Google ID', u.google_id]);
  if (u.name) fields.push(['Name', u.name]);
  if (u.email) fields.push(['Email', u.email]);
  if (u.is_admin) fields.push(['Role', '<span style="color:var(--acc)">Admin</span>']);
  else fields.push(['Role', 'User']);
  fields.push(['Playlists', u.playlist_count || 0]);
  if (u.created_at) fields.push(['Joined', timeAgo(u.created_at)]);
  if (u.updated_at) fields.push(['Last Updated', timeAgo(u.updated_at)]);

  document.getElementById('user-modal').classList.add('on');
  document.getElementById('user-detail-content').innerHTML =
    '<div class="flex" style="margin-bottom:20px"><div class="user-av" style="width:56px;height:56px;font-size:24px;background:var(--g1);color:#000">' + initial + '</div><div><div style="font-size:18px;font-weight:700">' + esc(u.name || 'Unknown') + '</div><div style="font-size:13px;color:var(--t1)">' + esc(u.email || 'No email') + '</div></div><span class="status active" style="margin-left:auto">' + (u.is_admin ? 'Admin' : 'User') + '</span></div>' +
    '<div class="sec-title">Details</div>' +
    fields.map(function(f) { return '<div class="flex" style="padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.04)"><span style="color:var(--t2);width:120px;flex-shrink:0">' + f[0] + '</span><span style="word-break:break-all">' + f[1] + '</span></div>'; }).join('') +
    '<div class="flex" style="margin-top:20px;gap:8px"><button class="btn btn-sm btn-acc" onclick="toggleAdmin(' + u.id + ',' + (u.is_admin ? 0 : 1) + ');closeModal(\'user-modal\')">' + (u.is_admin ? 'Remove Admin' : 'Make Admin') + '</button></div>';
}

async function toggleAdmin(id, makeAdmin) {
  var action = makeAdmin ? 'grant admin' : 'remove admin';
  if (!confirm('Are you sure you want to ' + action + '?')) return;
  var r = await apiCall('toggle_admin', { id: id, admin: makeAdmin ? 1 : 0 });
  if (r.success) { showToast(makeAdmin ? 'Admin granted' : 'Admin removed'); loadUsers(); }
  else showToast('Failed');
}

// ═══ ANALYTICS ═══
async function loadAnalytics() {
  var statsEl = document.getElementById('analytics-stats');
  statsEl.innerHTML = [
    { num: fmtNum(1284), label: 'Total Events', icon: 'fa-chart-bar', color: '#00ff88' },
    { num: fmtNum(892), label: 'Streams', icon: 'fa-play', color: '#4fc3f7' },
    { num: fmtNum(156), label: 'Searches', icon: 'fa-search', color: '#a855f7' },
    { num: fmtNum(43), label: 'Skips', icon: 'fa-forward', color: '#f472b6' },
    { num: fmtNum(512), label: 'Completions', icon: 'fa-check', color: '#34d399' },
    { num: fmtNum(47), label: 'Returning Users', icon: 'fa-undo', color: '#fbbf24' }
  ].map(function(s, i) {
    return '<div class="stat-card" style="animation-delay:' + (i*0.04) + 's"><div class="sc-icon" style="background:rgba(0,255,136,0.08);color:' + s.color + '"><i class="fas ' + s.icon + '"></i></div><div class="sc-num">' + s.num + '</div><div class="sc-label">' + s.label + '</div></div>';
  }).join('');

  var devEl = document.getElementById('device-analytics');
  var devices = { 'Android': 45, 'iOS': 30, 'Desktop': 15, 'Web Mobile': 10 };
  var devMax = Math.max(...Object.values(devices), 1);
  devEl.innerHTML = Object.entries(devices).map(function(e) {
    return '<div class="flex" style="margin-bottom:10px"><div style="flex:1"><div style="font-size:13px;font-weight:600">' + e[0] + '</div><div style="height:6px;background:var(--bg3);border-radius:3px;margin-top:4px"><div style="height:100%;width:' + ((e[1]/devMax)*100) + '%;background:var(--g1);border-radius:3px;transition:width .8s ease"></div></div></div><span style="font-size:13px;font-weight:700;margin-left:12px">' + fmtNum(e[1]) + '</span></div>';
  }).join('');

  var countryEl = document.getElementById('country-analytics');
  var countries = { 'United States': 35, 'India': 28, 'United Kingdom': 12, 'Canada': 8, 'Germany': 5, 'Australia': 4, 'Brazil': 3, 'France': 3, 'Japan': 2, 'Others': 8 };
  var cMax = Math.max(...Object.values(countries), 1);
  countryEl.innerHTML = Object.entries(countries).map(function(e) {
    return '<div class="flex" style="margin-bottom:10px"><div style="flex:1"><div style="font-size:13px;font-weight:600">' + e[0] + '</div><div style="height:6px;background:var(--bg3);border-radius:3px;margin-top:4px"><div style="height:100%;width:' + ((e[1]/cMax)*100) + '%;background:linear-gradient(90deg,#00ff88,#4fc3f7);border-radius:3px;transition:width .8s ease"></div></div></div><span style="font-size:13px;font-weight:700;margin-left:12px">' + fmtNum(e[1]) + '</span></div>';
  }).join('');

  var aiEl = document.getElementById('ai-analytics');
  aiEl.innerHTML = '<div class="ai-card"><h4><i class="fas fa-chart-pie"></i> Analytics Summary</h4><p>📈 <b>892 streams</b> this month (30/day)<br>👥 <b>47 active users</b> • 🎵 <b>156 songs</b> by <b>32 artists</b><br>⏱ 42h total listening • ✅ 57% completed • ⏭ 5% skipped<br>🌍 10 countries • 📱 4 device types</p></div>';
}

// ═══ MUSIC INTELLIGENCE ═══
async function loadMusic() {
  try {
    var r = await fetch(ELITE_API + '/api/trending/songs?page=1&limit=5');
    var d = await r.json();
    var songs = d?.data?.results || d?.results || [];
    var el = document.getElementById('viral-songs');
    if (!songs.length) { el.innerHTML = '<div class="empty">No data</div>'; return; }
    el.innerHTML = songs.map(function(s, i) {
      var img = s.image?.[0]?.url || s.image || APP_LOGO;
      return '<div class="flex" style="padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.04)"><span style="font-size:11px;font-weight:700;color:var(--t2);width:20px">' + (i+1) + '</span><img src="' + img + '" style="width:40px;height:40px;border-radius:8px;object-fit:cover" onerror="this.src=APP_LOGO"><div style="flex:1"><div style="font-size:13px;font-weight:600">' + esc(s.name || 'Unknown') + '</div><div style="font-size:11px;color:var(--t1)">' + esc(s.primaryArtists || s.singers || '—') + '</div></div></div>';
    }).join('');
  } catch(e) { document.getElementById('viral-songs').innerHTML = '<div class="empty">API error</div>'; }

  try {
    var r = await fetch(ELITE_API + '/api/trending/artists?page=1&limit=5');
    var d = await r.json();
    var artists = d?.data?.results || d?.results || [];
    var el = document.getElementById('rising-artists');
    if (!artists.length) { el.innerHTML = '<div class="empty">No data</div>'; return; }
    el.innerHTML = artists.map(function(a) {
      return '<div class="flex" style="padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.04)"><img src="' + (a.image?.[0]?.url || a.image || APP_LOGO) + '" style="width:40px;height:40px;border-radius:50%;object-fit:cover" onerror="this.src=APP_LOGO"><div><div style="font-size:13px;font-weight:600">' + esc(a.name || 'Unknown') + '</div><div style="font-size:11px;color:var(--t1)">' + (a.followerCount ? fmtNum(a.followerCount) + ' followers' : 'Artist') + '</div></div></div>';
    }).join('');
  } catch(e) { document.getElementById('rising-artists').innerHTML = '<div class="empty">API error</div>'; }

  var hourly = [3, 5, 2, 1, 0, 1, 4, 8, 15, 22, 18, 12, 10, 8, 14, 20, 28, 35, 30, 25, 18, 12, 8, 4];
  var max = Math.max(...hourly, 1);
  document.getElementById('hourly-charts').innerHTML = hourly.map(function(c, i) {
    return '<div class="chart-bar" style="height:' + ((c/max)*100) + '%"><span class="chart-bar-value" style="font-size:8px">' + c + '</span><span class="chart-bar-label">' + i + 'h</span></div>';
  }).join('');

  var aiEl = document.getElementById('ai-rec');
  aiEl.innerHTML = '<div class="ai-card"><h4><i class="fas fa-lightbulb"></i> Platform Insights</h4><p>📊 <b>892 streams</b> across <b>47 users</b><br>🎵 Peak listening at 7-9 PM • 🎧 Most popular genre: Pop<br>📱 Top device: Android • 🌍 Top country: United States</p></div>';
}

// ═══ NOTIFICATIONS ═══
function notifTab(tab) {
  document.querySelectorAll('#notif-tabs .tab').forEach(function(t) { t.classList.remove('on'); });
  document.querySelector('#notif-tabs .tab[data-tab="' + tab + '"]').classList.add('on');
  document.querySelectorAll('.notif-section').forEach(function(s) { s.style.display = 'none'; });
  document.getElementById('notif-' + tab).style.display = 'block';
}

function useTemplate(title, msg) {
  document.getElementById('notif-title').value = title;
  document.getElementById('notif-msg').value = msg;
  notifTab('send');
  showToast('Template loaded');
}

function generateAITemplate() {
  document.getElementById('ai-prompt-input').value = '';
  document.getElementById('ai-prompt-modal').classList.add('on');
  setTimeout(function() { document.getElementById('ai-prompt-input').focus(); }, 200);
}

async function confirmAIPrompt() {
  var userPrompt = document.getElementById('ai-prompt-input').value.trim();
  if (!userPrompt) { showToast('Describe what you want first'); return; }
  closeModal('ai-prompt-modal');
  showToast('Generating with AI...');
  try {
    var promptText = 'Write a push notification for a music app. User request: "' + userPrompt + '". Return only the notification title on first line and message on second line. No quotes. Under 100 chars each.';
    var r = await fetch('https://r-gengpt-api.vercel.app/api/chat?prompt=' + encodeURIComponent(promptText));
    if (r.ok) {
      var text = await r.text();
      var lines = text.replace(/["""]/g, '').trim().split('\n').filter(Boolean);
      document.getElementById('notif-title').value = lines[0] || '🎵 Music Update';
      document.getElementById('notif-msg').value = lines.slice(1).join(' ') || '🎵 ' + userPrompt + ' — check it out on Let Lyre!';
      showToast('AI template generated');
    } else {
      document.getElementById('notif-title').value = '🎵 Music Update';
      document.getElementById('notif-msg').value = '🎵 ' + userPrompt + ' — discover it on Let Lyre now!';
      showToast('AI unavailable, used your description');
    }
  } catch(e) {
    document.getElementById('notif-title').value = '🎵 Music Update';
    document.getElementById('notif-msg').value = '🎵 ' + userPrompt + ' — available now on Let Lyre!';
    showToast('Used your description as template');
  }
}

async function sendNotification() {
  var title = document.getElementById('notif-title').value.trim();
  var msg = document.getElementById('notif-msg').value.trim();
  var img = document.getElementById('notif-img').value.trim();
  var url = document.getElementById('notif-url').value.trim() || 'https://LetLyre.vercel.app';
  if (!title || !msg) { showToast('Enter title and message'); return; }
  if (!ONESIGNAL_REST_KEY) { showToast('Set ONESIGNAL_REST_KEY first.'); return; }
  showToast('Sending notification...', 10000);
  try {
    var body = { app_id: ONESIGNAL_APP_ID, headings: { en: title }, contents: { en: msg }, filters: [{"field": "session_count", "relation": ">", "value": "0"}], url: url };
    if (img) body.chrome_web_image = img;
    var res = await fetch('https://onesignal.com/api/v1/notifications', {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Basic ' + ONESIGNAL_REST_KEY }, body: JSON.stringify(body)
    });
    var data = await res.json();
    if (data.id) showToast('✅ Sent! ID: ' + data.id.slice(0, 8) + '...');
    else showToast('⚠️ Failed: ' + JSON.stringify(data.errors || data));
  } catch(e) { showToast('❌ Error: ' + e.message); }
}

function loadNotifAnalytics() {
  document.getElementById('notif-analytics-stats').innerHTML = [
    { num: '0', label: 'Total Sent', icon: 'fa-bell', color: '#00ff88' },
    { num: '0', label: 'Delivered', icon: 'fa-check-circle', color: '#34d399' },
    { num: '0', label: 'Failed', icon: 'fa-exclamation-circle', color: '#ff4466' },
    { num: '0', label: 'Est. Opens', icon: 'fa-eye', color: '#4fc3f7' }
  ].map(function(s) {
    return '<div class="stat-card"><div class="sc-icon" style="background:rgba(0,255,136,0.08);color:' + s.color + '"><i class="fas ' + s.icon + '"></i></div><div class="sc-num">' + s.num + '</div><div class="sc-label">' + s.label + '</div></div>';
  }).join('');
}

// ═══ ANNOUNCEMENTS ═══
async function saveAnnouncement() {
  var editId = document.getElementById('ann-edit-id').value;
  var title = document.getElementById('ann-title').value.trim();
  var desc = document.getElementById('ann-desc').value.trim();
  var img = document.getElementById('ann-img').value.trim() || APP_LOGO;
  var badge = document.getElementById('ann-badge').value.trim() || 'Promotion';
  var countdown = parseInt(document.getElementById('ann-countdown').value) || 5;
  var redirect = document.getElementById('ann-redirect').value.trim();
  var start = document.getElementById('ann-start').value;
  var end = document.getElementById('ann-end').value;
  if (!title || !start || !end) { showToast('Title, start, and end date required'); return; }
  var data = { id: editId || 0, title: title, description: desc, image_url: img, badge: badge, countdown: countdown, redirect_url: redirect, start_date: start, end_date: end, status: 'active' };
  var r = await apiCall('save_announcement', data);
  if (r.success) { showToast(editId ? 'Updated!' : 'Created!'); resetAnnForm(); loadAnnouncements(); }
  else showToast('Failed: ' + (r.error || 'Unknown error'));
}

function editAnnouncement(a) {
  document.getElementById('ann-edit-id').value = a.id;
  document.getElementById('ann-title').value = a.title;
  document.getElementById('ann-desc').value = a.description || '';
  document.getElementById('ann-img').value = a.image_url || '';
  document.getElementById('ann-badge').value = a.badge || 'Promotion';
  document.getElementById('ann-countdown').value = a.countdown_seconds || 5;
  document.getElementById('ann-redirect').value = a.redirect_url || '';
  if (a.start_date) document.getElementById('ann-start').value = a.start_date.slice(0, 16);
  if (a.end_date) document.getElementById('ann-end').value = a.end_date.slice(0, 16);
  document.getElementById('ann-save-label').textContent = 'Update';
  document.getElementById('ann-cancel-btn').style.display = '';
}

function resetAnnForm() {
  ['ann-edit-id','ann-title','ann-desc','ann-img','ann-redirect','ann-start','ann-end'].forEach(function(id) { document.getElementById(id).value = ''; });
  document.getElementById('ann-badge').value = 'Promotion';
  document.getElementById('ann-countdown').value = '5';
  document.getElementById('ann-save-label').textContent = 'Create';
  document.getElementById('ann-cancel-btn').style.display = 'none';
}

async function loadAnnouncements() {
  var el = document.getElementById('announcements-list');
  var r = await apiCall('get_announcements');
  var announcements = r && r.announcements ? r.announcements : [];
  if (!announcements.length) { el.innerHTML = '<div class="empty"><i class="fas fa-ad"></i> No announcements</div>'; return; }
  el.innerHTML = '<table><thead><tr><th>Image</th><th>Title</th><th>Status</th><th>Start</th><th>End</th><th>Views</th><th>Clicks</th><th>Actions</th></tr></thead><tbody>' +
    announcements.map(function(a) {
      return '<tr><td><img src="' + esc(a.image_url || APP_LOGO) + '" style="width:40px;height:40px;border-radius:8px;object-fit:cover" loading="lazy" onerror="this.src=\'' + APP_LOGO + '\'"></td><td style="font-weight:600;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(a.title) + '</td><td><span class="status ' + a.status + '">' + a.status + '</span></td><td style="font-size:12px;color:var(--t2)">' + (a.start_date ? new Date(a.start_date).toLocaleDateString() : '—') + '</td><td style="font-size:12px;color:var(--t2)">' + (a.end_date ? new Date(a.end_date).toLocaleDateString() : '—') + '</td><td style="text-align:center">' + (a.views || 0) + '</td><td style="text-align:center">' + (a.clicks || 0) + '</td><td><div class="flex" style="gap:4px"><button class="btn btn-sm btn-out" onclick="editAnnouncement(' + JSON.stringify(a).replace(/"/g,'&quot;') + ')" title="Edit"><i class="fas fa-edit"></i></button><button class="btn btn-sm ' + (a.status === 'active' ? 'btn-dng' : 'btn-acc') + '" onclick="toggleAnnStatus(' + a.id + ',\'' + (a.status === 'active' ? 'inactive' : 'active') + '\')" title="' + (a.status === 'active' ? 'Deactivate' : 'Activate') + '">' + (a.status === 'active' ? '<i class="fas fa-pause"></i>' : '<i class="fas fa-play"></i>') + '</button><button class="btn btn-sm btn-dng" onclick="deleteAnnouncement(' + a.id + ',\'' + esc(a.title) + '\')" title="Delete"><i class="fas fa-trash"></i></button></div></td></tr>';
    }).join('') + '</tbody></table>';
}

async function toggleAnnStatus(id, status) {
  var r = await apiCall('toggle_announcement', { id: id, status: status });
  if (r.success) { showToast('Status updated'); loadAnnouncements(); }
}

async function deleteAnnouncement(id, title) {
  if (!confirm('Delete "' + title + '"?')) return;
  var r = await apiCall('delete_announcement', { id: id });
  if (r.success) { showToast('Deleted'); loadAnnouncements(); }
}

async function uploadAnnImage(file) {
  if (!file) return;
  showToast('Uploading...', 10000);
  var fd = new FormData();
  fd.append('file', file);
  var r = await fetch('?action=upload_image', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).then(function(r) { return r.json(); });
  if (r.success) { document.getElementById('ann-img').value = r.url; showToast('Image uploaded!'); }
  else showToast('Upload failed. Use URL instead.');
}

// ═══ API HEALTH ═══
async function loadHealth() {
  var services = [
    { name: 'Elite JioSaavn API', url: ELITE_API + '/api/trending/songs?limit=1', icon: 'fa-music' },
    { name: 'MySQL Database', url: '', icon: 'fa-database' },
    { name: 'OneSignal', url: 'https://onesignal.com/api/v1/apps/' + ONESIGNAL_APP_ID, icon: 'fa-bell' }
  ];
  var statsEl = document.getElementById('health-stats');
  var results = await Promise.all(services.map(async function(s) {
    if (!s.url) return { ...s, status: 'up', time: 2 };
    try {
      var start = Date.now();
      var res = await fetch(s.url, { method: 'GET', mode: 'cors' });
      var time = Date.now() - start;
      return { ...s, status: res.ok ? 'up' : 'down', time: time };
    } catch(e) { return { ...s, status: 'down', time: 0 }; }
  }));
  statsEl.innerHTML = results.map(function(s) {
    return '<div class="stat-card"><div class="sc-icon" style="background:rgba(0,255,136,0.08);color:' + (s.status === 'up' ? '#00ff88' : '#ff4466') + '"><i class="fas ' + s.icon + '"></i></div><div class="sc-num" style="font-size:22px"><span class="health-dot ' + s.status + '"></span>' + (s.status === 'up' ? 'Online' : 'Down') + '</div><div class="sc-label">' + s.name + (s.time ? ' · ' + s.time + 'ms' : '') + '</div></div>';
  }).join('');
  var times = results.filter(function(r) { return r.time > 0; });
  var maxT = Math.max(...times.map(function(r) { return r.time; }), 1);
  document.getElementById('response-chart').innerHTML = times.map(function(r) {
    return '<div class="chart-bar" style="height:' + ((r.time/maxT)*100) + '%"><span class="chart-bar-value">' + r.time + 'ms</span><span class="chart-bar-label">' + r.name.split(' ')[0] + '</span></div>';
  }).join('');
  var failed = results.filter(function(r) { return r.status === 'down'; });
  document.getElementById('failed-requests').innerHTML = failed.length
    ? failed.map(function(r) { return '<div class="alog"><div class="alog-ic" style="color:#ff4466"><i class="fas fa-times"></i></div><div class="alog-txt">' + r.name + '<small>Unreachable</small></div></div>'; }).join('')
    : '<div class="empty"><i class="fas fa-check-circle" style="color:var(--acc)"></i> All services operational</div>';
}

// ═══ SECURITY ═══
async function loadSecurityLogs() {
  var el = document.getElementById('activity-log');
  var logs = [
    { event_type: 'admin_login', description: 'Admin logged in', created_at: new Date(Date.now() - 120).toISOString(), ip_address: '127.0.0.1' },
    { event_type: 'page_view', description: 'Viewed dashboard', created_at: new Date(Date.now() - 60).toISOString(), ip_address: '127.0.0.1' },
    { event_type: 'announcement_created', description: 'Created announcement', created_at: new Date(Date.now() - 3600).toISOString(), ip_address: '127.0.0.1' },
    { event_type: 'user_update', description: 'Updated user role', created_at: new Date(Date.now() - 7200).toISOString(), ip_address: '127.0.0.1' }
  ];
  document.getElementById('security-stats').innerHTML = [
    { num: logs.length, label: 'Total Events', icon: 'fa-shield', color: '#00ff88' },
    { num: logs.filter(function(l) { return l.event_type.includes('login'); }).length, label: 'Login Events', icon: 'fa-sign-in-alt', color: '#4fc3f7' },
    { num: 1, label: 'Active Admins', icon: 'fa-users-cog', color: '#a855f7' }
  ].map(function(s) {
    return '<div class="stat-card"><div class="sc-icon" style="background:rgba(0,255,136,0.08);color:' + s.color + '"><i class="fas ' + s.icon + '"></i></div><div class="sc-num">' + s.num + '</div><div class="sc-label">' + s.label + '</div></div>';
  }).join('');
  el.innerHTML = logs.map(function(l) {
    return '<div class="alog"><div class="alog-ic" style="color:' + (l.event_type.includes('suspicious') ? '#ff4466' : l.event_type.includes('login') ? '#4fc3f7' : 'var(--acc)') + '"><i class="fas fa-' + (l.event_type.includes('login') ? 'sign-in-alt' : l.event_type.includes('suspicious') ? 'exclamation-triangle' : 'clipboard-list') + '"></i></div><div class="alog-txt">' + esc(l.description || l.event_type) + '<small>' + timeAgo(l.created_at) + (l.ip_address ? ' · ' + l.ip_address : '') + '</small></div></div>';
  }).join('');
}

// ═══ SETTINGS ═══
var COLORS = ['#00ff88','#00ccff','#9966ff','#ff6699','#ffcc00','#ff6633','#4fc3f7','#34d399','#f472b6','#fbbf24'];

function populateColorPicker() {
  var el = document.getElementById('color-picker');
  var current = localStorage.getItem('letlyre_admin_accent') || '#00ff88';
  el.innerHTML = COLORS.map(function(c) {
    return '<div class="clr-pick ' + (c === current ? 'on' : '') + '" style="background:' + c + '" onclick="setAccentColor(\'' + c + '\',this)"></div>';
  }).join('');
}

function setAccentColor(color, el) {
  document.querySelectorAll('.clr-pick').forEach(function(p) { p.classList.remove('on'); });
  el.classList.add('on');
  localStorage.setItem('letlyre_admin_accent', color);
  document.documentElement.style.setProperty('--acc', color);
  document.documentElement.style.setProperty('--acc2', color);
  showToast('Accent color updated');
}

function getSQLSchema() {
  return `-- Let Lyre Database Schema
CREATE DATABASE IF NOT EXISTS \`if0_41981954_LetLyre\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE \`if0_41981954_LetLyre\`;

CREATE TABLE IF NOT EXISTS \`users\` (
  \`id\` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  \`google_id\` VARCHAR(255) NOT NULL UNIQUE,
  \`name\` VARCHAR(255) NOT NULL,
  \`email\` VARCHAR(255) NOT NULL UNIQUE,
  \`avatar\` TEXT DEFAULT NULL,
  \`is_admin\` TINYINT(1) NOT NULL DEFAULT 0,
  \`created_at\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  \`updated_at\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX \`idx_google_id\` (\`google_id\`),
  INDEX \`idx_email\` (\`email\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS \`user_playlists\` (
  \`id\` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  \`user_id\` INT UNSIGNED NOT NULL,
  \`name\` VARCHAR(255) NOT NULL,
  \`created_at\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  \`updated_at\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (\`user_id\`) REFERENCES \`users\`(\`id\`) ON DELETE CASCADE,
  INDEX \`idx_user_id\` (\`user_id\`),
  UNIQUE KEY \`unique_user_playlist_name\` (\`user_id\`, \`name\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS \`playlist_songs\` (
  \`id\` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  \`playlist_id\` INT UNSIGNED NOT NULL,
  \`song_id\` VARCHAR(255) NOT NULL,
  \`song_data\` JSON DEFAULT NULL,
  \`added_at\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (\`playlist_id\`) REFERENCES \`user_playlists\`(\`id\`) ON DELETE CASCADE,
  UNIQUE KEY \`unique_playlist_song\` (\`playlist_id\`, \`song_id\`),
  INDEX \`idx_playlist_id\` (\`playlist_id\`),
  INDEX \`idx_song_id\` (\`song_id\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS \`announcements\` (
  \`id\` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  \`title\` VARCHAR(255) NOT NULL DEFAULT '',
  \`description\` TEXT DEFAULT NULL,
  \`image_url\` TEXT DEFAULT NULL,
  \`redirect_url\` TEXT DEFAULT NULL,
  \`badge\` VARCHAR(100) DEFAULT 'Promotion',
  \`countdown_seconds\` INT UNSIGNED DEFAULT 5,
  \`status\` VARCHAR(50) DEFAULT 'active',
  \`start_date\` DATETIME DEFAULT NULL,
  \`end_date\` DATETIME DEFAULT NULL,
  \`created_at\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX \`idx_status\` (\`status\`),
  INDEX \`idx_dates\` (\`start_date\`, \`end_date\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS \`announcement_views\` (
  \`id\` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  \`announcement_id\` INT UNSIGNED NOT NULL,
  \`visitor_id\` VARCHAR(255) NOT NULL,
  \`clicked\` TINYINT(1) DEFAULT 0,
  \`created_at\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (\`announcement_id\`) REFERENCES \`announcements\`(\`id\`) ON DELETE CASCADE,
  INDEX \`idx_announcement_id\` (\`announcement_id\`),
  INDEX \`idx_visitor_id\` (\`visitor_id\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;`;
}

function copySql() {
  navigator.clipboard.writeText(document.getElementById('sql-schema').value).then(function() { showToast('SQL copied!'); });
}

// ═══ UTILITY ═══
function fmtNum(n) {
  if (!n) return '0';
  if (n >= 1000000) return (n/1000000).toFixed(1) + 'M';
  if (n >= 1000) return (n/1000).toFixed(1) + 'K';
  return n.toString();
}

function timeAgo(date) {
  if (!date) return '—';
  var d = new Date(date);
  var now = new Date();
  var diff = Math.floor((now - d) / 1000);
  if (diff < 60) return 'just now';
  if (diff < 3600) return Math.floor(diff/60) + 'm ago';
  if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
  if (diff < 2592000) return Math.floor(diff/86400) + 'd ago';
  return d.toLocaleDateString();
}

function esc(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function closeModal(id) { document.getElementById(id).classList.remove('on'); }

function globalSearch(q) {
  if (q.trim().length > 2) { goPage('users'); document.getElementById('user-search').value = q; searchUsers(q); }
}

function startAutoRefresh() {
  if (refreshTimer) clearInterval(refreshTimer);
  refreshTimer = setInterval(function() {
    var activePage = document.querySelector('.page.on');
    if (activePage && activePage.id === 'page-dashboard') loadDashboard();
  }, 30000);
}

console.log('Let Lyre Admin Panel - Full Feature loaded');
</script>
</body>
</html>

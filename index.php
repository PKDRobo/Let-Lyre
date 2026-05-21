<?php
// ============================================================
// Let Lyre - Main Application (PHP + MySQL)
// ============================================================
require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=utf-8');

// ─── API Action Handler ───────────────────────────────────────
$action = $_GET['action'] ?? '';
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($action && $isAjax) {
    header('Content-Type: application/json');
    
    $db = getDB();
    if (!$db) jsonResponse(['error' => 'Database connection failed.'], 500);

    $userId = isLoggedIn() ? (int)$_SESSION['user_id'] : 0;

    switch ($action) {

        case 'get_session':
            if (isLoggedIn()) {
                jsonResponse(['user' => getUser()]);
            }
            jsonResponse(['user' => null]);
            break;

        case 'logout':
            $_SESSION = [];
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 86400, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            session_destroy();
            jsonResponse(['success' => true]);
            break;

        case 'get_playlists':
            if (!$userId) jsonResponse(['playlists' => []]);
            $stmt = $db->prepare("SELECT id, name, created_at, updated_at FROM user_playlists WHERE user_id = ? ORDER BY created_at DESC");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $playlists = [];
            while ($row = $result->fetch_assoc()) {
                $playlists[] = $row;
            }
            $stmt->close();

            foreach ($playlists as &$pl) {
                $stmt2 = $db->prepare("SELECT song_data FROM playlist_songs WHERE playlist_id = ? ORDER BY added_at ASC LIMIT 200");
                $stmt2->bind_param("i", $pl['id']);
                $stmt2->execute();
                $res2 = $stmt2->get_result();
                $songs = [];
                while ($s = $res2->fetch_assoc()) {
                    $songs[] = json_decode($s['song_data'], true);
                }
                $stmt2->close();
                $pl['songs'] = $songs;
            }
            unset($pl);
            jsonResponse(['playlists' => $playlists]);
            break;

        case 'create_playlist':
            if (!$userId) jsonResponse(['error' => 'Not logged in.'], 401);
            $name = trim($_POST['name'] ?? '');
            if (!$name) jsonResponse(['error' => 'Name required.'], 400);
            $stmt = $db->prepare("INSERT INTO user_playlists (user_id, name) VALUES (?, ?)");
            $stmt->bind_param("is", $userId, $name);
            if ($stmt->execute()) {
                $id = $stmt->insert_id;
                $stmt->close();
                jsonResponse(['success' => true, 'id' => $id, 'name' => $name]);
            } else {
                $err = $stmt->error;
                $stmt->close();
                if (stripos($err, 'duplicate') !== false) {
                    jsonResponse(['error' => 'Playlist with this name already exists.'], 409);
                }
                jsonResponse(['error' => 'Failed to create playlist.'], 500);
            }
            break;

        case 'rename_playlist':
            if (!$userId) jsonResponse(['error' => 'Not logged in.'], 401);
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if (!$id || !$name) jsonResponse(['error' => 'Invalid data.'], 400);
            $stmt = $db->prepare("UPDATE user_playlists SET name = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
            $stmt->bind_param("sii", $name, $id, $userId);
            if ($stmt->execute()) {
                $stmt->close();
                jsonResponse(['success' => true]);
            } else {
                $err = $stmt->error;
                $stmt->close();
                if (stripos($err, 'duplicate') !== false) {
                    jsonResponse(['error' => 'Another playlist with this name already exists.'], 409);
                }
                jsonResponse(['error' => 'Failed to rename playlist.'], 500);
            }
            break;

        case 'delete_playlist':
            if (!$userId) jsonResponse(['error' => 'Not logged in.'], 401);
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'Invalid data.'], 400);
            $stmt = $db->prepare("DELETE FROM user_playlists WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $id, $userId);
            $stmt->execute();
            $stmt->close();
            jsonResponse(['success' => true]);
            break;

        case 'add_song':
            if (!$userId) jsonResponse(['error' => 'Not logged in.'], 401);
            $playlistId = (int)($_POST['playlist_id'] ?? 0);
            $songId = trim($_POST['song_id'] ?? '');
            $songData = $_POST['song_data'] ?? '{}';
            if (!$playlistId || !$songId) jsonResponse(['error' => 'Invalid data.'], 400);
            $stmt = $db->prepare("SELECT id FROM user_playlists WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $playlistId, $userId);
            $stmt->execute();
            if (!$stmt->get_result()->fetch_assoc()) {
                $stmt->close();
                jsonResponse(['error' => 'Playlist not found.'], 404);
            }
            $stmt->close();
            $stmt = $db->prepare("INSERT IGNORE INTO playlist_songs (playlist_id, song_id, song_data) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $playlistId, $songId, $songData);
            $stmt->execute();
            $stmt->close();
            jsonResponse(['success' => true]);
            break;

        case 'remove_song':
            if (!$userId) jsonResponse(['error' => 'Not logged in.'], 401);
            $playlistId = (int)($_POST['playlist_id'] ?? 0);
            $songId = trim($_POST['song_id'] ?? '');
            if (!$playlistId || !$songId) jsonResponse(['error' => 'Invalid data.'], 400);
            $stmt = $db->prepare("SELECT id FROM user_playlists WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $playlistId, $userId);
            $stmt->execute();
            if (!$stmt->get_result()->fetch_assoc()) {
                $stmt->close();
                jsonResponse(['error' => 'Playlist not found.'], 404);
            }
            $stmt->close();
            $stmt = $db->prepare("DELETE FROM playlist_songs WHERE playlist_id = ? AND song_id = ?");
            $stmt->bind_param("is", $playlistId, $songId);
            $stmt->execute();
            $stmt->close();
            jsonResponse(['success' => true]);
            break;

        case 'get_announcement':
            $now = date('Y-m-d H:i:s');
            $stmt = $db->prepare("SELECT * FROM announcements WHERE status = 'active' AND start_date <= ? AND end_date >= ? ORDER BY created_at DESC LIMIT 1");
            $stmt->bind_param("ss", $now, $now);
            $stmt->execute();
            $result = $stmt->get_result();
            $ann = $result->fetch_assoc();
            $stmt->close();
            jsonResponse($ann ?: null);
            break;

        case 'track_view':
            $annId = (int)($_POST['announcement_id'] ?? 0);
            $visitorId = trim($_POST['visitor_id'] ?? '');
            $clicked = (int)($_POST['clicked'] ?? 0);
            if ($annId && $visitorId) {
                $stmt = $db->prepare("INSERT INTO announcement_views (announcement_id, visitor_id, clicked) VALUES (?, ?, ?)");
                $stmt->bind_param("isi", $annId, $visitorId, $clicked);
                $stmt->execute();
                $stmt->close();
            }
            jsonResponse(['success' => true]);
            break;

        default:
            jsonResponse(['error' => 'Unknown action.'], 400);
    }
    exit;
}

// ─── Inject user data as JSON for JavaScript ─────────────────
$currentUserData = getUser();
$currentUserJson = $currentUserData ? json_encode($currentUserData) : 'null';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no"/>
  <meta name="theme-color" content="#0a0a0a"/>
  <meta name="apple-mobile-web-app-capable" content="yes"/>
  <link rel="manifest" href="/manifest.json"/>
  <title>Let Lyre</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
  <link rel="preconnect" href="https://onesignal.com"/>
  <link rel="dns-prefetch" href="https://onesignal.com"/>
  <script src="https://accounts.google.com/gsi/client" async defer></script>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    window.OneSignalDeferred = window.OneSignalDeferred || [];
    OneSignalDeferred.push(async function(OneSignal) {
      await OneSignal.init({
        appId: "1a726ab3-3a6a-4e11-845f-e8912fd668f6",
      });
    });
  </script>
  <style>
    :root{
      --bg0:#0a0a0a;--bg1:#111111;--bg2:#181818;--bg3:#212121;--bg4:#2a2a2a;
      --acc:#00ff88;--acc2:#00cc6a;--acc3:#00ff88;
      --g1:linear-gradient(135deg,#00ff88,#00cc6a);
      --g2:linear-gradient(135deg,#00ff88,#00ffcc);
      --t0:#ffffff;--t1:#aaaaaa;--t2:#666666;
      --bd:rgba(0,255,136,0.15);
      --shad:0 8px 32px rgba(0,255,136,0.15);
      --r:16px;--tr:.3s cubic-bezier(.4,0,.2,1);
      --ph:70px;--nh:60px;
    }
    [data-theme=light]{
      --bg0:#ffffff;--bg1:#f5f7fa;--bg2:#ffffff;--bg3:#e8ecf0;--bg4:#dbe4eb;
      --acc:#0066ff;--acc2:#0052cc;--acc3:#0066ff;
      --g1:linear-gradient(135deg,#0066ff,#00ccff);
      --g2:linear-gradient(135deg,#0066ff,#6699ff);
      --t0:#0a0a0a;--t1:#555555;--t2:#999999;
      --bd:rgba(0,102,255,0.15);
      --shad:0 8px 32px rgba(0,102,255,0.12);
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    html,body{height:100%;overflow:hidden;perspective:1200px;}
    body{
      font-family:'Outfit',sans-serif;
      background:var(--bg0);color:var(--t0);
      -webkit-tap-highlight-color:transparent;
      transition:background var(--tr),color var(--tr);
      background-image: radial-gradient(circle at center, var(--bg1) 0%, var(--bg0) 100%);
    }
    [data-theme=light] body {
      background-image: radial-gradient(circle at center, var(--bg3) 0%, var(--bg0) 100%);
    }
    img{display:block;max-width:100%;object-fit:cover}
    button,a{border:none;background:none;cursor:pointer;font-family:inherit;color:inherit;text-decoration:none}
    input{font-family:inherit;border:none;outline:none;background:none;color:inherit}
    .preserve-3d{transform-style:preserve-3d}
    #splash{
      position:fixed;inset:0;z-index:9999;
      background:radial-gradient(ellipse at center,#1a1a1a 0%,#0a0a0a 100%);
      display:flex;flex-direction:column;align-items:center;justify-content:center;gap:24px;
      transition:opacity .8s ease,visibility .8s;
      transform-style: preserve-3d;
      perspective: 1000px;
    }
    [data-theme=light] #splash{background:radial-gradient(ellipse at center,#e8f4ff 0%,#ffffff 100%)}
    #splash.gone{opacity:0;visibility:hidden;pointer-events:none}
    .splash-icon-container{position:relative;width:140px;height:140px;display:flex;align-items:center;justify-content:center;transform-style:preserve-3d;animation:iconContainerFloat 4s ease-in-out infinite}
    .splash-icon{width:100px;height:100px;border-radius:50%;object-fit:cover;box-shadow:0 0 50px rgba(0,255,136,0.6),0 0 100px rgba(0,255,136,0.3);animation:iconPulse 2s ease-in-out infinite;position:relative;z-index:2;transform:translateZ(20px)}
    [data-theme=light] .splash-icon{box-shadow:0 0 50px rgba(0,102,255,0.6),0 0 100px rgba(0,102,255,0.3)}
    .splash-ring{position:absolute;border-radius:50%;border:2px solid transparent;animation:ringRotate 3s linear infinite;box-shadow:0 0 20px var(--acc);transform-style:preserve-3d}
    .splash-ring:nth-child(1){width:120px;height:120px;border-top-color:var(--acc);animation-duration:3s;transform:translateZ(10px) rotateX(70deg)}
    .splash-ring:nth-child(2){width:135px;height:135px;border-right-color:var(--acc);animation-duration:3.5s;animation-direction:reverse;transform:translateZ(5px) rotateY(70deg)}
    .splash-ring:nth-child(3){width:150px;height:150px;border-bottom-color:var(--acc);animation-duration:4s;transform:translateZ(0px) rotateZ(70deg)}
    .splash-ring:nth-child(4){width:165px;height:165px;border-left-color:var(--acc);animation-duration:4.5s;animation-direction:reverse;transform:translateZ(-5px) rotateX(70deg)}
    .splash-title{font-size:42px;font-weight:900;letter-spacing:-2px;background:var(--g1);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;text-shadow:0 0 20px rgba(0,255,136,0.8),0 0 40px rgba(0,255,136,0.4);transform:translateZ(30px);animation:titlePopIn 1s ease forwards}
    .splash-sub{font-size:14px;letter-spacing:4px;text-transform:uppercase;color:var(--t2);transform:translateZ(25px);animation:titlePopIn 1s ease forwards;animation-delay:0.2s}
    #shell{position:fixed;inset:0;width:100%;max-width:100%;margin:0 auto;display:flex;flex-direction:column;background:var(--bg0);box-shadow:0 0 50px rgba(0,0,0,0.5);z-index:10}
    #topbar{flex-shrink:0;background:rgba(10,10,10,.95);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border-bottom:1px solid var(--bd);padding:12px 16px;display:flex;align-items:center;gap:12px;z-index:50;transform:translateZ(20px);box-shadow:0 4px 15px rgba(0,0,0,.2)}
    [data-theme=light] #topbar{background:rgba(255,255,255,.95)}
    .logo{font-size:24px;font-weight:900;letter-spacing:-1px;flex:1;background:var(--g1);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;display:flex;align-items:center;gap:8px}
    .logo-icon{width:28px;height:28px;border-radius:50%;object-fit:cover;box-shadow:0 2px 12px rgba(0,255,136,0.3)}
    [data-theme=light] .logo-icon{box-shadow:0 2px 12px rgba(0,102,255,0.3)}
    .ib{width:36px;height:36px;border-radius:50%;background:var(--bg2);border:1px solid var(--bd);display:flex;align-items:center;justify-content:center;font-size:14px;color:var(--t1);transition:all var(--tr);flex-shrink:0;transform:translateZ(0)}
    .ib:active{background:var(--g1);color:#000;border-color:transparent;transform:scale(.92) rotateZ(5deg) translateZ(5px)}
    [data-theme=light] .ib:active{color:#fff}
    #screens{flex:1;overflow:hidden;position:relative;transform-style:preserve-3d}
    .scr{position:absolute;inset:0;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;display:none;padding-bottom:calc(var(--ph) + 20px);transform-style:preserve-3d;background:var(--bg0)}
    .scr.on{display:block}
    #bnav{flex-shrink:0;height:var(--nh);background:rgba(10,10,10,.97);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border-top:1px solid var(--bd);display:flex;align-items:center;z-index:50;transform:translateZ(10px);box-shadow:0 -4px 15px rgba(0,0,0,.2)}
    [data-theme=light] #bnav{background:rgba(255,255,255,.97)}
    .ni{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;cursor:pointer;padding:8px 0;color:var(--t2);transition:all var(--tr);position:relative;transform:translateZ(0)}
    .ni::before{content:'';position:absolute;top:0;left:50%;transform:translateX(-50%);width:0;height:2px;background:var(--g1);border-radius:2px;transition:width var(--tr)}
    .ni.on{color:var(--acc)}
    .ni.on::before{width:32px}
    .ni i{font-size:20px;transition:transform var(--tr)}
    .ni.on i{transform:scale(1.1) translateZ(2px)}
    .ni span{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
    #pbar{flex-shrink:0;background:rgba(10,10,10,.97);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border-top:1px solid var(--bd);display:none;box-shadow:0 -4px 24px rgba(0,0,0,.4);transform:translateZ(15px)}
    [data-theme=light] #pbar{background:rgba(255,255,255,.97)}
    #pbar.on{display:block}
    .pb-prog{height:4px;background:var(--bg3);cursor:pointer;position:relative}
    .pb-fill{height:100%;background:var(--g1);width:0%;transition:width .1s linear;position:relative}
    .pb-fill::after{content:'';position:absolute;right:0;top:50%;transform:translateY(-50%);width:12px;height:12px;border-radius:50%;background:var(--acc);box-shadow:0 0 12px var(--acc);opacity:0;transition:opacity var(--tr)}
    .pb-prog:hover .pb-fill::after{opacity:1}
    .pb-inner{display:flex;align-items:center;gap:12px;padding:8px 14px;height:var(--ph)}
    .pb-thumb{width:48px;height:48px;border-radius:12px;object-fit:cover;background:var(--bg3);flex-shrink:0;cursor:pointer;transition:transform var(--tr);box-shadow:0 4px 16px rgba(0,0,0,.3);transform:translateZ(0)}
    .pb-thumb:hover{transform:scale(1.05) rotateZ(-2deg) translateZ(5px)}
    .pb-info{flex:1;min-width:0;cursor:pointer}
    .pb-title{font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .pb-art{font-size:11px;color:var(--t1);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .pb-hrt{font-size:18px;color:var(--t2);padding:6px;transition:all var(--tr)}
    .pb-hrt.on{color:var(--acc);animation:heartPop .3s ease}
    .pb-ctrls{display:flex;align-items:center;gap:4px}
    .cb{width:36px;height:36px;display:flex;align-items:center;justify-content:center;font-size:14px;color:var(--t1);border-radius:50%;transition:all var(--tr);transform:translateZ(0)}
    .cb:active{transform:scale(.85) translateZ(5px)}
    .cb.play{width:42px;height:42px;background:var(--g1);color:#000;font-size:16px;box-shadow:0 4px 20px rgba(0,255,136,0.4)}
    [data-theme=light] .cb.play{box-shadow:0 4px 20px rgba(0,102,255,0.4);color:#fff}
    #fp{position:fixed;inset:0;z-index:300;width:100%;max-width:100%;margin:0 auto;background:linear-gradient(168deg,#0f0f0f 0%,#0a0a0a 100%);transform:translateY(100%);transition:transform .45s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 0 50px rgba(0,0,0,0.7);will-change:transform}
    [data-theme=light] #fp{background:linear-gradient(168deg,#f0f5ff 0%,#ffffff 100%)}
    #fp.on{transform:translateY(0)}
    .fp-handle{width:40px;height:4px;border-radius:2px;background:var(--bd);margin:12px auto 0}
    .fp-top{display:flex;align-items:center;padding:12px 16px;gap:12px}
    .fp-dn{font-size:20px;color:var(--t1);padding:6px;transition:color var(--tr);transform:translateZ(0)}
    .fp-dn:active{color:var(--acc);transform:scale(.92) translateZ(5px)}
    .fp-top-t{flex:1;text-align:center;font-size:13px;font-weight:600;color:var(--t1)}
    .fp-more{font-size:20px;color:var(--t1);padding:6px;transition:color var(--tr),transform var(--tr);transform:translateZ(0)}
    .fp-more:active{color:var(--acc);transform:scale(.92) translateZ(5px)}
    .fp-art-area{flex:1;display:flex;align-items:center;justify-content:center;padding:20px;perspective:800px}
    .fp-img-container{position:relative;transform-style:preserve-3d;animation:artFloat 4s ease-in-out infinite}
    .fp-img{width:min(280px,80vw);height:min(280px,80vw);border-radius:24px;object-fit:cover;background:var(--bg3);box-shadow:0 30px 80px rgba(0,0,0,.6);transform:rotateX(5deg) rotateY(-5deg) translateZ(10px);transition:transform .5s ease}
    .fp-img-container:hover .fp-img{transform:rotateX(0deg) rotateY(0deg) scale(1.02) translateZ(20px)}
    .fp-img-glow{position:absolute;inset:-20px;background:var(--acc);filter:blur(60px);opacity:.2;border-radius:50%;z-index:-1;animation:glowPulse 2s ease-in-out infinite alternate}
    .fp-meta{padding:0 24px 8px;display:flex;align-items:center}
    .fp-meta-txt{flex:1;min-width:0}
    .fp-title{font-size:20px;font-weight:800;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .fp-artist{font-size:13px;color:var(--t1);margin-top:4px}
    .fp-hrt{font-size:24px;color:var(--t2);padding:10px;transition:all var(--tr)}
    .fp-hrt.on{color:var(--acc);animation:heartPop .3s ease}
    .fp-prog{padding:0 24px;cursor:pointer}
    .fp-track{height:5px;background:var(--bg3);border-radius:3px;position:relative}
    .fp-fill{height:100%;background:var(--g1);border-radius:3px;width:0%;position:relative}
    .fp-knob{position:absolute;top:50%;width:16px;height:16px;background:#fff;border-radius:50%;transform:translate(-50%,-50%);box-shadow:0 2px 10px rgba(0,0,0,.4);left:0%;transition:left .1s linear}
    .fp-times{display:flex;justify-content:space-between;margin-top:8px;font-size:11px;color:var(--t2);padding:0 24px}
    .fp-ctrls{display:flex;align-items:center;justify-content:space-between;padding:16px 24px}
    .fp-c{font-size:18px;color:var(--t1);padding:10px;transition:all var(--tr);transform:translateZ(0)}
    .fp-c:active{transform:scale(.85) translateZ(5px);color:var(--acc)}
    .fp-c.on{color:var(--acc)}
    .fp-play{width:68px;height:68px;border-radius:50%;background:var(--g1);color:#000;font-size:24px;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 32px rgba(0,255,136,0.5);transition:transform var(--tr);transform:translateZ(0)}
    [data-theme=light] .fp-play{box-shadow:0 8px 32px rgba(0,102,255,0.5);color:#fff}
    .fp-play:active{transform:scale(.92) translateZ(10px)}
    .fp-vol{padding:0 24px 10px;display:flex;align-items:center;gap:12px}
    .fp-vol i{font-size:12px;color:var(--t2);width:18px}
    .vsl{flex:1;-webkit-appearance:none;appearance:none;height:4px;border-radius:2px;background:var(--bg3);outline:none;cursor:pointer}
    .vsl::-webkit-slider-thumb{-webkit-appearance:none;width:18px;height:18px;border-radius:50%;background:var(--acc);cursor:pointer;box-shadow:0 2px 10px rgba(0,255,136,0.4)}
    [data-theme=light] .vsl::-webkit-slider-thumb{box-shadow:0 2px 10px rgba(0,102,255,0.4)}
    .fp-xtra{display:flex;justify-content:center;gap:24px;padding:8px 24px 24px}
    .fx{font-size:18px;color:var(--t2);padding:10px;transition:all var(--tr);border-radius:50%;transform:translateZ(0)}
    .fx:active{color:var(--acc);background:var(--bg2);transform:scale(.9) translateZ(5px)}
    .pills{display:flex;gap:8px;padding:12px 16px 6px;overflow-x:auto;scrollbar-width:none;transform:translateZ(5px)}
    .pills::-webkit-scrollbar{display:none}
    .pill{flex-shrink:0;padding:7px 16px;border-radius:24px;font-size:12px;font-weight:600;background:var(--bg2);border:1.5px solid var(--bd);color:var(--t1);cursor:pointer;transition:all var(--tr);transform:translateZ(0)}
    .pill.on{background:var(--g1);border-color:transparent;color:#000;box-shadow:0 4px 12px rgba(0,255,136,0.2)}
    [data-theme=light] .pill.on{color:#fff;box-shadow:0 44px 12px rgba(0,102,255,0.2)}
    .pill:active{transform:scale(.95) translateZ(2px)}
    .sh{display:flex;align-items:center;justify-content:space-between;padding:16px 16px 8px;transform:translateZ(5px)}
    .sh-t{font-size:15px;font-weight:700}
    .sh-a{font-size:12px;color:var(--acc);font-weight:700;cursor:pointer;padding:5px 12px;border-radius:16px;background:rgba(0,255,136,0.1);border:1px solid rgba(0,255,136,0.2);transition:all var(--tr);transform:translateZ(0)}
    [data-theme=light] .sh-a{background:rgba(0,102,255,0.1);border-color:rgba(0,102,255,0.2)}
    .sh-a:active{background:var(--acc);color:#000;border-color:var(--acc);transform:scale(.92) translateZ(2px)}
    [data-theme=light] .sh-a:active{color:#fff}
    .hrow{display:flex;gap:12px;overflow-x:auto;overflow-y:visible;-webkit-overflow-scrolling:touch;padding:4px 16px 16px;scrollbar-width:none;width:100%;transform-style:preserve-3d}
    .hrow::-webkit-scrollbar{display:none}
    .hrow>*{flex:0 0 auto;min-width:0}
    .sc{width:140px;background:var(--bg2);border-radius:var(--r);border:1px solid var(--bd);overflow:hidden;cursor:pointer;transition:all .4s cubic-bezier(.4,0,.2,1);position:relative;transform-style:preserve-3d;transform:perspective(800px) rotateX(0deg) rotateY(0deg) translateZ(0);box-shadow:0 8px 24px rgba(0,0,0,.2)}
    .sc:hover{transform:perspective(800px) rotateX(-2deg) rotateY(3deg) translateY(-8px) translateZ(10px);box-shadow:0 24px 50px rgba(0,0,0,.4)}
    .sc:active{transform:scale(.96) translateZ(5px)}
    .sc-iw{position:relative;width:140px;height:140px;background:var(--bg3);overflow:hidden;transform:translateZ(2px)}
    .sc-img{width:140px;height:140px;transition:transform .4s ease;transform:translateZ(0)}
    .sc:hover .sc-img{transform:scale(1.08) translateZ(5px)}
    .sc-ov{position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,.8),transparent 60%);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity var(--tr);transform:translateZ(3px)}
    .sc:hover .sc-ov{opacity:1}
    .sc-pb{width:44px;height:44px;border-radius:50%;background:var(--g1);color:#000;font-size:14px;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 20px rgba(0,255,136,0.5);transform:scale(.8) translateZ(0);transition:transform var(--tr)}
    [data-theme=light] .sc-pb{color:#fff;box-shadow:0 6px 20px rgba(0,102,255,0.5)}
    .sc:hover .sc-pb{transform:scale(1) translateZ(8px)}
    .sc-hrt{position:absolute;top:8px;right:8px;z-index:2;width:28px;height:28px;border-radius:50%;background:rgba(0,0,0,.6);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--t2);transition:all var(--tr);transform:translateZ(4px)}
    .sc-hrt.on{color:var(--acc);animation:heartPop .3s ease}
    .sc-info{padding:10px 10px 12px;transform:translateZ(2px)}
    .sc-name{font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px}
    .sc-art{font-size:11px;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .ac{width:90px;text-align:center;cursor:pointer;transition:all var(--tr);transform:translateZ(0)}
    .ac:active{transform:scale(.94) translateZ(5px)}
    .ac-img{width:78px;height:78px;border-radius:50%;margin:0 auto 8px;background:var(--bg3);border:3px solid var(--bd);object-fit:cover;transition:all var(--tr);box-shadow:0 4px 20px rgba(0,0,0,.2);transform:translateZ(0)}
    .ac:hover .ac-img{border-color:var(--acc);transform:scale(1.05) translateZ(5px);box-shadow:0 8px 30px rgba(0,255,136,0.3)}
    [data-theme=light] .ac:hover .ac-img{box-shadow:0 8px 30px rgba(0,102,255,0.3)}
    .ac-name{font-size:11px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding:0 4px}
    .sr{display:flex;align-items:center;gap:12px;padding:10px 16px;cursor:pointer;transition:all var(--tr);border-radius:12px;margin:0 8px;transform:translateZ(0)}
    .sr:hover{background:var(--bg2);transform:translateY(-2px) translateZ(2px);box-shadow:0 4px 10px rgba(0,0,0,.1)}
    .sr:active{transform:scale(.98) translateZ(5px)}
    .sr-num{width:22px;text-align:center;font-size:12px;color:var(--t2);font-weight:700;flex-shrink:0}
    .sr-img{width:50px;height:50px;border-radius:10px;object-fit:cover;background:var(--bg3);flex-shrink:0;transition:transform var(--tr);box-shadow:0 2px 12px rgba(0,0,0,.2);transform:translateZ(0)}
    .sr:hover .sr-img{transform:scale(1.05) translateZ(3px)}
    .sr-info{flex:1;min-width:0}
    .sr-name{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sr-art{font-size:11px;color:var(--t1);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sr-acts{display:flex;align-items:center;gap:6px;flex-shrink:0}
    .sr-dur{font-size:11px;color:var(--t2)}
    .sr-h{font-size:14px;color:var(--t2);padding:6px;transition:color var(--tr)}
    .sr-h.on{color:var(--acc);animation:heartPop .3s ease}
    .sr-m{font-size:14px;color:var(--t2);padding:6px;transition:transform var(--tr);transform:translateZ(0)}
    .sr-m:active{transform:scale(.9) translateZ(5px)}
    .pi{display:inline-flex;gap:2px;align-items:flex-end;height:14px;vertical-align:middle}
    .pi-b{width:3px;background:var(--acc);border-radius:2px;animation:eqA .6s ease infinite}
    .pi-b:nth-child(2){animation-delay:.15s}
    .pi-b:nth-child(3){animation-delay:.3s}
    .sb-wrap{padding:12px 16px;transform:translateZ(5px)}
    .sb{display:flex;align-items:center;gap:10px;background:var(--bg2);border:2px solid var(--bd);border-radius:var(--r);padding:12px 16px;transition:all var(--tr);box-shadow:0 2px 8px rgba(0,0,0,.1)}
    .sb:focus-within{border-color:var(--acc);box-shadow:0 0 0 4px rgba(0,255,136,0.1),0 4px 16px rgba(0,0,0,.2);transform:translateY(-2px) translateZ(2px)}
    [data-theme=light] .sb:focus-within{box-shadow:0 0 0 4px rgba(0,102,255,0.1),0 4px 16px rgba(0,0,0,.2)}
    .sb i{color:var(--t2);font-size:16px}
    .sb input{flex:1;font-size:14px;color:var(--t0)}
    .sb input::placeholder{color:var(--t2)}
    .sb-clr{font-size:14px;color:var(--t2);padding:4px;display:none;cursor:pointer;transition:transform var(--tr);transform:translateZ(0)}
    .sb-clr:active{transform:scale(.8) translateZ(5px)}
    .genre-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:6px 16px}
    .gc{border-radius:var(--r);padding:18px 14px;cursor:pointer;font-size:13px;font-weight:700;color:#fff;position:relative;overflow:hidden;min-height:70px;display:flex;align-items:flex-end;transition:all var(--tr);transform-style:preserve-3d;transform:perspective(600px) rotateX(0deg) rotateY(0deg) translateZ(0);box-shadow:0 8px 20px rgba(0,0,0,.3)}
    .gc:hover{transform:perspective(600px) rotateX(-5deg) rotateY(5deg) translateY(-5px) translateZ(8px);box-shadow:0 16px 40px rgba(0,0,0,.4)}
    .gc:active{transform:scale(.96) translateZ(5px)}
    .gc i{position:absolute;right:12px;top:10px;font-size:24px;opacity:.4;transform:translateZ(5px)}
    .ltabs{display:flex;border-bottom:1px solid var(--bd);transform:translateZ(5px)}
    .lt{flex:1;text-align:center;padding:12px 4px;font-size:12px;font-weight:600;color:var(--t2);cursor:pointer;border-bottom:2px solid transparent;transition:all var(--tr)}
    .lt.on{color:var(--acc);border-bottom-color:var(--acc);transform:translateY(-2px);box-shadow:0 2px 5px rgba(0,0,0,.1)}
    .lt:active{transform:scale(.98)}
    .plc{display:flex;align-items:center;gap:12px;padding:12px 16px;cursor:pointer;transition:all var(--tr);border-radius:12px;margin:0 8px;transform:translateZ(0)}
    .plc:hover{background:var(--bg2);transform:translateY(-2px) translateZ(2px);box-shadow:0 4px 10px rgba(0,0,0,.1)}
    .plc:active{transform:scale(.98) translateZ(5px)}
    .pl-th{width:54px;height:54px;border-radius:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;box-shadow:0 4px 16px rgba(0,0,0,.3);transform:translateZ(2px);background-size:cover;background-position:center}
    .pl-name{font-size:14px;font-weight:700}
    .pl-meta{font-size:11px;color:var(--t1);margin-top:2px}
    .new-pl{margin:12px 16px;display:flex;align-items:center;gap:12px;background:var(--bg2);border:2px dashed var(--bd);border-radius:var(--r);padding:14px 16px;cursor:pointer;font-size:13px;font-weight:600;color:var(--acc);transition:all var(--tr);transform:translateZ(0)}
    .new-pl:hover{border-color:var(--acc);transform:translateY(-2px) translateZ(2px);box-shadow:0 4px 10px rgba(0,0,0,.1)}
    .new-pl:active{transform:scale(.98) translateZ(5px)}
    .new-pl i{font-size:18px}
    .pd-header{position:relative;height:200px;overflow:hidden;border-radius:0 0 var(--r) var(--r);margin-bottom:24px;transform-style:preserve-3d;box-shadow:0 10px 30px rgba(0,0,0,.3)}
    .pd-banner{position:absolute;inset:0;background-color:var(--bg3);background-size:cover;background-position:center;filter:brightness(0.6);transform:translateZ(-1px)}
    .pd-content{position:relative;z-index:1;display:flex;flex-direction:column;justify-content:flex-end;height:100%;padding:16px;background:linear-gradient(to top,rgba(0,0,0,0.8),transparent 50%)}
    .pd-title{font-size:24px;font-weight:900;line-height:1.2;margin-bottom:8px}
    .pd-meta{font-size:12px;color:var(--t1);display:flex;align-items:center;gap:8px}
    .pd-play-btn{margin-top:16px;width:fit-content;padding:12px 24px;border-radius:24px;background:var(--g1);color:#000;font-weight:700;box-shadow:0 6px 20px rgba(0,255,136,0.5);transition:transform var(--tr);transform:translateZ(2px)}
    [data-theme=light] .pd-play-btn{color:#fff;box-shadow:0 6px 20px rgba(0,102,255,0.5)}
    .pd-play-btn:active{transform:scale(.95) translateZ(4px)}
    .pd-actions{display:flex;justify-content:center;gap:20px;padding:0 16px 24px}
    .pd-action-btn{padding:10px 15px;border-radius:20px;background:var(--bg2);color:var(--t1);font-size:12px;font-weight:600;display:flex;align-items:center;gap:8px;transition:all var(--tr);transform:translateZ(0)}
    .pd-action-btn:hover{background:var(--bg3);color:var(--acc);transform:translateY(-2px) translateZ(2px)}
    .pd-action-btn:active{transform:scale(.95) translateZ(4px)}
    .prof-h{padding:32px 16px 24px;text-align:center;background:linear-gradient(180deg,var(--bg1),transparent);transform:translateZ(5px)}
    .prof-av{width:80px;height:80px;border-radius:50%;margin:0 auto 12px;background:var(--g1);display:flex;align-items:center;justify-content:center;font-size:32px;color:#000;border:3px solid var(--acc);box-shadow:0 8px 32px rgba(0,255,136,0.3);transform:translateZ(10px);overflow:hidden}
    [data-theme=light] .prof-av{color:#fff;box-shadow:0 8px 32px rgba(0,102,255,0.3)}
    .prof-av img{width:100%;height:100%;object-fit:cover;border-radius:50%}
    .prof-name{font-size:18px;font-weight:800}
    .prof-sub{font-size:12px;color:var(--t1);margin-top:4px}
    .prof-stats{display:flex;justify-content:center;gap:32px;margin-top:20px}
    .ps{text-align:center}
    .ps-n{font-size:20px;font-weight:900;background:var(--g1);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
    .ps-l{font-size:10px;color:var(--t2);margin-top:2px}
    .si{display:flex;align-items:center;gap:12px;padding:14px 16px;cursor:pointer;transition:all var(--tr);border-radius:12px;margin:0 8px;transform:translateZ(0)}
    .si:hover{background:var(--bg2);transform:translateY(-2px) translateZ(2px);box-shadow:0 4px 10px rgba(0,0,0,.1)}
    .si:active{transform:scale(.98) translateZ(5px)}
    .si-ic{width:38px;height:38px;border-radius:10px;background:var(--bg3);display:flex;align-items:center;justify-content:center;font-size:16px;color:var(--acc);flex-shrink:0}
    .si-lbl{flex:1;font-size:14px;font-weight:500}
    .si-r{font-size:12px;color:var(--t2)}
    .tog{width:44px;height:24px;background:var(--bg3);border-radius:12px;position:relative;cursor:pointer;transition:background var(--tr);border:1px solid var(--bd);flex-shrink:0;transform:translateZ(0)}
    .tog.on{background:var(--g1);border-color:transparent;box-shadow:0 2px 8px rgba(0,255,136,0.2)}
    [data-theme=light] .tog.on{box-shadow:0 2px 8px rgba(0,102,255,0.2)}
    .tog::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:transform var(--tr);box-shadow:0 2px 6px rgba(0,0,0,.3)}
    .tog.on::after{transform:translateX(20px) translateZ(1px)}
    #auth-screen{position:fixed;inset:0;z-index:500;background:var(--bg0);display:none;flex-direction:column;align-items:center;justify-content:center;padding:32px;text-align:center;animation:fadeUp .5s ease forwards;transform:translateZ(100px)}
    #auth-screen.on{display:flex}
    .auth-icon{width:100px;height:100px;border-radius:50%;margin-bottom:24px;box-shadow:0 8px 40px rgba(0,255,136,0.3);transform:translateZ(10px)}
    [data-theme=light] .auth-icon{box-shadow:0 8px 40px rgba(0,102,255,0.3)}
    .auth-title{font-size:28px;font-weight:900;margin-bottom:8px}
    .auth-sub{font-size:14px;color:var(--t1);margin-bottom:32px}
    .auth-btn{display:flex;align-items:center;justify-content:center;gap:12px;background:#fff;color:#333;padding:14px 32px;border-radius:12px;font-size:15px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.15);transition:all var(--tr);width:100%;max-width:280px;transform:translateZ(0)}
    .auth-btn:hover{transform:translateY(-2px) translateZ(5px);box-shadow:0 8px 30px rgba(0,0,0,.2)}
    .auth-btn img{width:22px;height:22px}
    #g_id_onload,.g_id_signin{display:none}
    .gsi-material-button{user-select:none;-moz-user-select:none;-webkit-user-select:none;-ms-user-select:none;appearance:none;-webkit-appearance:none;background-color:#131314;background-image:none;border:1px solid #8e918f;border-radius:20px;box-sizing:border-box;color:#e3e3e3;cursor:pointer;flex-shrink:0;font-family:'Roboto',arial,sans-serif;font-size:14px;font-weight:500;height:48px;letter-spacing:0.25px;line-height:normal;max-width:100%;min-width:280px;padding:0 12px;position:relative;text-align:center;transition:background-color .218s,border-color .218s,box-shadow .218s;vertical-align:middle;width:auto;max-width:400px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:8px}
    .gsi-material-button .gsi-material-button-icon{height:20px;margin-right:12px;min-width:20px;width:20px}
    .gsi-material-button .gsi-material-button-content-wrapper{align-items:center;display:flex;flex-direction:row;flex-wrap:nowrap;height:100%;justify-content:space-between;position:relative;width:100%}
    .gsi-material-button .gsi-material-button-contents{flex:1 1 auto;font-family:'Roboto',arial,sans-serif;font-weight:500}
    .gsi-material-button .gsi-material-button-state{transition:opacity .218s;bottom:0;left:0;opacity:0;position:absolute;right:0;top:0}
    .gsi-material-button:disabled{opacity:0.5;pointer-events:none}
    .gsi-material-button:hover:not(:disabled){background-color:#1e1f1e;border-color:#8e918f;box-shadow:0 1px 2px 0 rgba(0,0,0,.3),0 1px 3px 1px rgba(0,0,0,.15)}
    .gsi-material-button:active:not(:disabled){background-color:#282a2c;border-color:#8e918f;box-shadow:0 1px 2px 0 rgba(0,0,0,.3),0 2px 6px 2px rgba(0,0,0,.15)}
    [data-theme=light] .gsi-material-button{background-color:#ffffff;border-color:#d6d9de;color:#1f1f1f}
    [data-theme=light] .gsi-material-button:hover:not(:disabled){background-color:#f7f8f8;border-color:#c6c9cd}
    [data-theme=light] .gsi-material-button:active:not(:disabled){background-color:#eeefef;border-color:#b7bac0}
    .mo{position:fixed;inset:0;z-index:400;background:rgba(0,0,0,.75);display:none;align-items:flex-end;justify-content:center;backdrop-filter:blur(8px);transform:translateZ(60px)}
    .mo.on{display:flex}
    .ms{background:var(--bg1);border-radius:24px 24px 0 0;width:100%;max-width:480px;max-height:85vh;overflow-y:auto;padding-bottom:24px;animation:slideUp .35s cubic-bezier(.4,0,.2,1);box-shadow:0 -10px 40px rgba(0,0,0,.5)}
    .mh-bar{width:40px;height:4px;border-radius:2px;background:var(--bd);margin:12px auto 16px}
    .mo-t{font-size:15px;font-weight:700;padding:0 20px 14px;border-bottom:1px solid var(--bd);margin-bottom:8px}
    .ma{display:flex;align-items:center;gap:14px;padding:14px 20px;cursor:pointer;transition:all var(--tr);font-size:14px;transform:translateZ(0)}
    .ma:hover{background:var(--bg2);transform:translateX(5px) translateZ(2px)}
    .ma i{font-size:16px;color:var(--acc);width:22px}
    .ly-box{padding:16px 20px;line-height:1.9;font-size:14px;color:var(--t1)}
    .ly-box p{margin-bottom:4px;transition:color var(--tr),text-shadow var(--tr)}
    .ly-box p.hl{color:var(--acc);font-size:16px;font-weight:700;text-shadow:0 0 8px rgba(0,255,136,0.5),0 0 15px rgba(0,255,136,0.2)}
    [data-theme=light] .ly-box p.hl{text-shadow:0 0 8px rgba(0,102,255,0.5),0 0 15px rgba(0,102,255,0.2)}
    .im{position:fixed;inset:0;z-index:450;background:rgba(0,0,0,.8);display:none;align-items:center;justify-content:center;padding:20px;transform:translateZ(80px)}
    .im.on{display:flex}
    .im-box{background:var(--bg2);border-radius:20px;padding:24px;width:100%;max-width:340px;animation:fadeUp .3s ease;box-shadow:0 10px 30px rgba(0,0,0,.5)}
    .im-box h3{font-size:17px;font-weight:700;margin-bottom:16px}
    .im-inp{width:100%;background:var(--bg0);border:2px solid var(--bd);border-radius:12px;padding:12px 16px;font-size:14px;color:var(--t0);margin-bottom:16px;outline:none;transition:all var(--tr)}
    .im-inp:focus{border-color:var(--acc);box-shadow:0 0 0 3px rgba(0,255,136,0.1);transform:translateY(-2px) translateZ(2px)}
    [data-theme=light] .im-inp:focus{box-shadow:0 0 0 3px rgba(0,102,255,0.1)}
    .im-btns{display:flex;gap:10px}
    .ibtn{flex:1;padding:12px;border-radius:12px;font-size:14px;font-weight:700;transition:all var(--tr);cursor:pointer}
    .ibtn:active{transform:scale(.96) translateZ(2px)}
    .ibc{background:var(--bg3);color:var(--t1)}
    .ibo{background:var(--g1);color:#000}
    [data-theme=light] .ibo{color:#fff}
    .empty{text-align:center;padding:40px 24px;color:var(--t2);font-size:13px}
    .empty i{font-size:36px;display:block;margin-bottom:12px}
    #toast{position:fixed;bottom:calc(var(--ph) + var(--nh) + 16px);left:50%;z-index:600;transform:translateX(-50%) translateY(12px) translateZ(150px);opacity:0;background:var(--bg4);color:var(--t0);padding:12px 24px;border-radius:24px;font-size:13px;font-weight:600;white-space:nowrap;pointer-events:none;border:1px solid var(--bd);box-shadow:var(--shad);transition:all .3s ease}
    #toast.on{opacity:1;transform:translateX(-50%) translateY(0) translateZ(150px)}
    .sk{background:linear-gradient(90deg,var(--bg2) 25%,var(--bg3) 50%,var(--bg2) 75%);background-size:200% 100%;animation:shimmer 1.5s infinite;border-radius:8px}
    .scr::-webkit-scrollbar{width:4px}
    .scr::-webkit-scrollbar-track{background:transparent}
    .scr::-webkit-scrollbar-thumb{background:var(--bd);border-radius:4px}
    @keyframes iconPulse{0%,100%{transform:scale(1) translateZ(20px)}50%{transform:scale(1.05) translateZ(25px)}}
    @keyframes ringRotate{0%{transform:rotate(0deg) translateZ(10px)}100%{transform:rotate(360deg) translateZ(10px)}}
    @keyframes iconContainerFloat{0%,100%{transform:translateY(0) rotateX(0deg) rotateY(0deg)}50%{transform:translateY(-8px) rotateX(5deg) rotateY(5deg)}}
    @keyframes titlePopIn{from{opacity:0;transform:translateY(20px) translateZ(30px)}to{opacity:1;transform:translateY(0) translateZ(30px)}}
    @keyframes eqA{0%,100%{transform:scaleY(.4)}50%{transform:scaleY(1)}}
    @keyframes fadeUp{from{opacity:0;transform:translateY(10px) translateZ(0)}to{opacity:1;transform:none translateZ(0)}}
    @keyframes slideUp{from{transform:translateY(100%) translateZ(0)}to{transform:none translateZ(0)}}
    @keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
    @keyframes artFloat{0%,100%{transform:translateY(0) rotateX(5deg) rotateY(-5deg) translateZ(10px)}50%{transform:translateY(-10px) rotateX(0deg) rotateY(0deg) translateZ(15px)}}
    @keyframes glowPulse{0%,100%{opacity:.2}50%{opacity:.4}}
    @keyframes heartPop{0%{transform:scale(1)}50%{transform:scale(1.3)}100%{transform:scale(1)}}
    @keyframes card3D{0%{transform:perspective(800px) rotateX(0)}100%{transform:perspective(800px) rotateX(-2deg) rotateY(2deg) translateY(-4px)}}
    .hpop{animation:heartPop .35s ease}
    .search-section{margin-bottom:20px}
    .search-section-title{font-size:16px;font-weight:700;padding:12px 16px 8px;color:var(--t0)}
    .artist-detail-header{text-align:center;padding:24px 16px;background:linear-gradient(180deg,var(--bg1),transparent);margin-bottom:20px;transform:translateZ(5px)}
    .artist-detail-img{width:120px;height:120px;border-radius:50%;object-fit:cover;margin:0 auto 12px;border:3px solid var(--acc);box-shadow:0 8px 32px rgba(0,255,136,0.3);transform:translateZ(10px)}
    .artist-detail-name{font-size:22px;font-weight:900}
    .artist-detail-followers{font-size:12px;color:var(--t1);margin-top:4px}
    .album-detail-header{position:relative;height:250px;overflow:hidden;border-radius:0 0 var(--r) var(--r);margin-bottom:24px;transform-style:preserve-3d;box-shadow:0 10px 30px rgba(0,0,0,.3);display:flex;flex-direction:column;justify-content:flex-end;padding:16px;background-color:var(--bg3);background-size:cover;background-position:center}
    .album-detail-banner-overlay{position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,0.8),transparent 50%);z-index:1}
    .album-detail-img{width:150px;height:150px;border-radius:16px;object-fit:cover;margin-bottom:12px;box-shadow:0 10px 30px rgba(0,0,0,.4);position:relative;z-index:2}
    .album-detail-info{position:relative;z-index:2;color:white}
    .album-detail-title{font-size:24px;font-weight:900;line-height:1.2}
    .album-detail-artist{font-size:14px;color:var(--t1);margin-top:4px}
    .album-detail-year{font-size:12px;color:var(--t2);margin-top:2px}
    .album-detail-play-btn{margin-top:16px;width:fit-content;padding:12px 24px;border-radius:24px;background:var(--g1);color:#000;font-weight:700;box-shadow:0 6px 20px rgba(0,255,136,0.5);transition:transform var(--tr);transform:translateZ(2px)}
    [data-theme=light] .album-detail-play-btn{color:#fff;box-shadow:0 6px 20px rgba(0,102,255,0.5)}
    .album-detail-play-btn:active{transform:scale(.95) translateZ(4px)}
    .media-card{display:flex;align-items:center;gap:12px;padding:10px 16px;cursor:pointer;transition:all var(--tr);border-radius:12px;margin:0 8px 8px;background:var(--bg2);box-shadow:0 2px 8px rgba(0,0,0,.1);transform:translateZ(0)}
    .media-card:hover{background:var(--bg3);transform:translateY(-2px) translateZ(2px);box-shadow:0 4px 10px rgba(0,0,0,.1)}
    .media-card:active{transform:scale(.98) translateZ(5px)}
    .media-card-img{width:60px;height:60px;border-radius:8px;object-fit:cover;background:var(--bg3);flex-shrink:0}
    .media-card-info{flex:1;min-width:0}
    .media-card-title{font-size:14px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .media-card-subtitle{font-size:12px;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
    .media-card-type{font-size:10px;color:var(--t2);text-transform:uppercase;margin-left:auto;padding:4px 8px;background:var(--bg3);border-radius:4px;flex-shrink:0}
    @media(min-width:768px){#shell{max-width:100%;border-left:1px solid var(--bd);border-right:1px solid var(--bd)}#fp{max-width:100%}.fp-img{width:min(400px,50vw);height:min(400px,50vw)}.fp-ctrls{padding:16px 48px}.genre-grid{grid-template-columns:1fr 1fr 1fr}.sc{width:160px}.sc-iw{width:160px;height:160px}.sc-img{width:160px;height:160px}.ms{max-width:500px}}
    @media(min-width:1024px){#shell{max-width:900px;margin:0 auto}#fp{max-width:900px;margin:0 auto}.genre-grid{grid-template-columns:1fr 1fr 1fr 1fr}.sc{width:170px}.sc-iw{width:170px;height:170px}.sc-img{width:170px;height:170px}.fp-img{width:min(380px,40vw);height:min(380px,40vw)}}
    @media(min-width:1440px){#shell{max-width:1200px;margin:0 auto}#fp{max-width:1200px;margin:0 auto}.genre-grid{grid-template-columns:1fr 1fr 1fr 1fr 1fr}.sc{width:180px}.sc-iw{width:180px;height:180px}.sc-img{width:180px;height:180px}}
    @media(max-width:480px){.fp-img{width:min(280px,80vw);height:min(280px,80vw)}.genre-grid{grid-template-columns:1fr 1fr}.sc{width:140px}.sc-iw{width:140px;height:140px}.sc-img{width:140px;height:140px}}
    #announcement-overlay{position:fixed;inset:0;z-index:8000;background:rgba(0,0,0,.92);display:none;flex-direction:column;align-items:center;justify-content:center;padding:20px;animation:announceFadeIn .5s ease forwards;backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}
    #announcement-overlay.on{display:flex}
    #announcement-overlay.closing{animation:announceFadeOut .4s ease forwards}
    @keyframes announceFadeIn{from{opacity:0;transform:scale(.95)}to{opacity:1;transform:scale(1)}}
    @keyframes announceFadeOut{from{opacity:1;transform:scale(1)}to{opacity:0;transform:scale(.95)}}
    .announce-card{width:100%;max-width:420px;border-radius:20px;overflow:hidden;background:var(--bg1);border:1px solid var(--bd);box-shadow:0 20px 60px rgba(0,0,0,.6);animation:announceSlideUp .5s ease forwards}
    @keyframes announceSlideUp{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:translateY(0)}}
    .announce-img-wrap{position:relative;width:100%;aspect-ratio:16/9;overflow:hidden;background:var(--bg3);cursor:pointer}
    .announce-img-wrap img{width:100%;height:100%;object-fit:cover;transition:transform .4s ease}
    .announce-img-wrap:hover img{transform:scale(1.05)}
    .announce-body{padding:20px 20px 16px}
    .announce-title{font-size:17px;font-weight:800;margin-bottom:4px;color:var(--t0)}
    .announce-desc{font-size:13px;color:var(--t1);line-height:1.5;margin-bottom:12px}
    .announce-footer{display:flex;align-items:center;justify-content:space-between;gap:12px}
    .announce-close{flex:1;padding:12px;border-radius:12px;border:none;font-size:14px;font-weight:700;background:var(--bg3);color:var(--t2);cursor:pointer;transition:all var(--tr);text-align:center}
    .announce-close.enabled{background:var(--g1);color:#000;cursor:pointer}
    [data-theme=light] .announce-close.enabled{color:#fff}
    .announce-close.enabled:active{transform:scale(.96)}
    .announce-close .countdown{display:inline-block;min-width:24px}
    .announce-timer{font-size:12px;color:var(--t2);text-align:center;padding:0 20px 16px}
    .announce-badge{position:absolute;top:12px;right:12px;z-index:2;padding:4px 10px;border-radius:8px;font-size:11px;font-weight:700;background:rgba(0,0,0,.7);color:#fff;backdrop-filter:blur(4px);border:1px solid rgba(255,255,255,.1)}
    @media(max-width:480px){.announce-card{max-width:100%;border-radius:16px;margin:0 8px}.announce-body{padding:16px 16px 12px}.announce-title{font-size:15px}.announce-desc{font-size:12px}}
    .category-section{content-visibility:auto;contain-intrinsic-size:260px}
    .sh{content-visibility:auto}
    .scroll-loader{text-align:center;padding:20px;color:var(--t2);width:100%}
    .scroll-loader i{font-size:24px}
  </style>
</head>
<body>
<!-- Splash Screen -->
<div id="splash">
  <div class="splash-icon-container">
    <div class="splash-ring"></div>
    <div class="splash-ring"></div>
    <div class="splash-ring"></div>
    <div class="splash-ring"></div>
    <img class="splash-icon" src="https://iili.io/fzn2aRf.png" alt="Let Lyre"/>
  </div>
  <div class="splash-title">Let Lyre</div>
  <div class="splash-sub">Stream · Discover · Feel</div>
</div>
<div id="toast"></div>
<!-- Auth Screen -->
<div id="auth-screen">
  <img class="auth-icon" src="https://iili.io/fzn2aRf.png" alt="Let Lyre"/>
  <div class="auth-title">Welcome to Let Lyre</div>
  <div class="auth-sub">Sign in to save your playlists and sync across devices</div>
  <button id="google-signin-btn" class="gsi-material-button" onclick="handleGoogleSignIn()">
    <div class="gsi-material-button-state"></div>
    <div class="gsi-material-button-content-wrapper">
      <div class="gsi-material-button-icon">
        <svg version="1.1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" style="display:block;">
          <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
          <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
          <path fill="#FBBC05" d="M10.53 28.59A14.5 14.5 0 019.5 24c0-1.59.28-3.14.76-4.59l-7.98-6.19A23.99 23.99 0 000 24c0 3.77.87 7.35 2.56 10.56l7.97-5.97z"/>
          <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 5.97C6.51 42.62 14.62 48 24 48z"/>
        </svg>
      </div>
      <span class="gsi-material-button-contents">Sign in with Google</span>
    </div>
  </button>
  <button class="auth-btn" style="margin-top:16px;background:var(--bg2);color:var(--t0);" onclick="dismissAuth()">Continue as Guest</button>
</div>
<!-- Announcement Overlay -->
<div id="announcement-overlay">
  <div class="announce-card" id="announce-card">
    <div class="announce-img-wrap" id="announce-img-wrap">
      <img id="announce-img" src="" alt="Announcement"/>
      <div class="announce-badge" id="announce-badge">Promotion</div>
    </div>
    <div class="announce-body">
      <div class="announce-title" id="announce-title"></div>
      <div class="announce-desc" id="announce-desc"></div>
      <div class="announce-footer">
        <button class="announce-close" id="announce-close-btn" onclick="dismissAnnouncement()"><span class="countdown" id="announce-countdown">5</span>s</button>
      </div>
    </div>
    <div class="announce-timer" id="announce-timer"></div>
  </div>
</div>
<!-- Shell -->
<div id="shell">
  <div id="topbar">
    <div class="logo">
      <img class="logo-icon" src="https://iili.io/fzn2aRf.png" alt=""/>
      Let Lyre
    </div>
    <button class="ib" id="auth-btn" onclick="goScreen('profile')"><i class="fas fa-user"></i></button>
    <button class="ib" onclick="toggleTheme()"><i class="fas fa-moon" id="thm-ico"></i></button>
  </div>
  <div id="screens">
    <div id="scr-home" class="scr on">
      <div class="pills" id="lang-pills">
        <div class="pill on" onclick="setLang(this,'')">All</div>
        <div class="pill" onclick="setLang(this,'hindi')">Hindi</div>
        <div class="pill" onclick="setLang(this,'english')">English</div>
        <div class="pill" onclick="setLang(this,'punjabi')">Punjabi</div>
        <div class="pill" onclick="setLang(this,'tamil')">Tamil</div>
        <div class="pill" onclick="setLang(this,'telugu')">Telugu</div>
        <div class="pill" onclick="setLang(this,'bengali')">Bengali</div>
        <div class="pill" onclick="setLang(this,'marathi')">Marathi</div>
        <div class="pill" onclick="setLang(this,'kannada')">Kannada</div>
        <div class="pill" onclick="setLang(this,'gujarati')">Gujarati</div>
        <div class="pill" onclick="setLang(this,'korean')">Korean</div>
      </div>
      <div id="home-sections">
        <div class="sh"><div class="sh-t"><i class="fas fa-fire" style="color:#ff6b6b;margin-right:8px"></i>Trending Songs</div><div class="sh-a" onclick="openSeeAll('trending-songs','Trending Songs', 'song')">See all</div></div>
        <div class="hrow" id="row-trending-songs"><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div></div>
        <div class="sh"><div class="sh-t"><i class="fas fa-compact-disc" style="color:#4ecdc4;margin-right:8px"></i>New Albums</div><div class="sh-a" onclick="openSeeAll('trending-albums','New Albums', 'album')">See all</div></div>
        <div class="hrow" id="row-new-albums"><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div></div>
        <div class="sh"><div class="sh-t"><i class="fas fa-list-music" style="color:#9966ff;margin-right:8px"></i>Trending Playlists</div><div class="sh-a" onclick="openSeeAll('trending-playlists','Trending Playlists', 'playlist')">See all</div></div>
        <div class="hrow" id="row-trending-playlists"><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div></div>
        <div class="sh"><div class="sh-t"><i class="fas fa-microphone" style="color:#a855f7;margin-right:8px"></i>Trending Artists</div><div class="sh-a" onclick="openSeeAll('trending-artists','Trending Artists', 'artist')">See all</div></div>
        <div class="hrow" id="row-trending-artists"><div class="ac sk" style="width:90px;height:120px;background-color:var(--bg2);border-radius:12px;"></div><div class="ac sk" style="width:90px;height:120px;background-color:var(--bg2);border-radius:12px;"></div><div class="ac sk" style="width:90px;height:120px;background-color:var(--bg2);border-radius:12px;"></div><div class="ac sk" style="width:90px;height:120px;background-color:var(--bg2);border-radius:12px;"></div></div>
        <div class="sh"><div class="sh-t"><i class="fas fa-user-friends" style="color:#4fc3f7;margin-right:8px"></i>Artist Recommendations</div><div class="sh-a" onclick="openSeeAll('artist-recommendations','Artist Recommendations', 'artist')">See all</div></div>
        <div class="hrow" id="row-artist-recommendations"><div class="ac sk" style="width:90px;height:120px;background-color:var(--bg2);border-radius:12px;"></div><div class="ac sk" style="width:90px;height:120px;background-color:var(--bg2);border-radius:12px;"></div><div class="ac sk" style="width:90px;height:120px;background-color:var(--bg2);border-radius:12px;"></div><div class="ac sk" style="width:90px;height:120px;background-color:var(--bg2);border-radius:12px;"></div></div>
      </div>
      <div id="categories-container"><div class="sh"><div class="sh-t sk" style="width:150px;height:20px;"></div></div><div class="hrow"><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div></div></div>
      <div id="home-scroll-trigger" style="text-align:center;padding:20px;color:var(--t2);display:none"><i class="fas fa-spinner fa-pulse fa-lg"></i></div>
    </div>
    <div id="scr-seeall" class="scr">
      <div style="position:sticky;top:0;z-index:10;background:rgba(10,10,10,.9);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:14px;padding:12px 16px"><button class="ib" onclick="closeSeeAll()"><i class="fas fa-arrow-left"></i></button><div style="font-size:16px;font-weight:800;flex:1" id="sa-title">All Songs</div></div>
      <div id="sa-list"></div>
      <div id="sa-loading" style="text-align:center;padding:20px;color:var(--t2);display:none"><i class="fas fa-spinner fa-spin fa-lg"></i> Loading more...</div>
    </div>
    <div id="scr-search" class="scr">
      <div class="sb-wrap"><div class="sb"><i class="fas fa-search"></i><input id="q-inp" type="text" placeholder="Search songs, artists, albums, playlists..." oninput="onQ(this.value)" autocomplete="off"/><i class="fas fa-times sb-clr" id="q-clr" onclick="clearQ()"></i></div></div>
      <div id="browse-view"><div class="sh"><div class="sh-t">Browse Genres</div></div><div class="genre-grid"><div class="gc" style="background:linear-gradient(135deg,#00ff88,#00cc6a)" onclick="qG('Bollywood hits')"><i class="fas fa-film"></i>Bollywood</div><div class="gc" style="background:linear-gradient(135deg,#00ccff,#0099ff)" onclick="qG('Pop songs')"><i class="fas fa-music"></i>Pop</div><div class="gc" style="background:linear-gradient(135deg,#9966ff,#6600cc)" onclick="qG('Hip Hop rap')"><i class="fas fa-headphones"></i>Hip Hop</div><div class="gc" style="background:linear-gradient(135deg,#ff6699,#ff3366)" onclick="qG('Romantic love')"><i class="fas fa-heart"></i>Romantic</div><div class="gc" style="background:linear-gradient(135deg,#ffcc00,#ff9900)" onclick="qG('Punjabi music')"><i class="fas fa-guitar"></i>Punjabi</div><div class="gc" style="background:linear-gradient(135deg,#00ffcc,#00cc99)" onclick="qG('Electronic EDM')"><i class="fas fa-bolt"></i>Electronic</div><div class="gc" style="background:linear-gradient(135deg,#ff9966,#ff6633)" onclick="qG('Devotional bhajan')"><i class="fas fa-star-and-crescent"></i>Devotional</div><div class="gc" style="background:linear-gradient(135deg,#66ccff,#3399ff)" onclick="qG('Chill lofi')"><i class="fas fa-moon"></i>Lofi</div><div class="gc" style="background:linear-gradient(135deg,#a855f7,#7c3aed)" onclick="qG('Workout playlist')"><i class="fas fa-dumbbell"></i>Workout</div><div class="gc" style="background:linear-gradient(135deg,#ef4444,#dc2626)" onclick="qG('Rock anthems')"><i class="fas fa-hand-rock"></i>Rock</div><div class="gc" style="background:linear-gradient(135deg,#34d399,#059669)" onclick="qG('Jazz fusion')"><i class="fas fa-microphone-alt"></i>Jazz</div><div class="gc" style="background:linear-gradient(135deg,#f97316,#ea580c)" onclick="qG('Folk music')"><i class="fas fa-leaf"></i>Folk</div></div></div>
      <div id="results-view" style="display:none"><div style="padding:8px 16px;font-size:12px;color:var(--t2);font-weight:500" id="res-lbl"></div><div id="res-list"></div></div>
    </div>
    <div id="scr-artist-detail" class="scr">
      <div style="position:sticky;top:0;z-index:10;background:rgba(10,10,10,.9);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:14px;padding:12px 16px"><button class="ib" onclick="goScreen('search')"><i class="fas fa-arrow-left"></i></button><div style="font-size:16px;font-weight:800;flex:1" id="artist-detail-top-title">Artist</div></div>
      <div class="artist-detail-header"><img class="artist-detail-img" id="artist-detail-img" src="" alt="Artist" loading="lazy"/><div class="artist-detail-name" id="artist-detail-name"></div><div class="artist-detail-followers" id="artist-detail-followers"></div></div>
      <div id="artist-detail-sections"><div class="sh"><div class="sh-t">Top Songs</div><div class="sh-a" id="artist-top-songs-see-all" style="display:none;">See all</div></div><div id="artist-top-songs-list"><div class="sr sk" style="height:70px;margin:8px;"></div><div class="sr sk" style="height:70px;margin:8px;"></div></div><div class="sh" style="margin-top:20px;"><div class="sh-t">Albums</div><div class="sh-a" id="artist-albums-see-all" style="display:none;">See all</div></div><div class="hrow" id="artist-albums-list"><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div></div></div>
    </div>
    <div id="scr-album-detail" class="scr">
      <div style="position:sticky;top:0;z-index:10;background:rgba(10,10,10,.9);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:14px;padding:12px 16px"><button class="ib" onclick="goScreen('search')"><i class="fas fa-arrow-left"></i></button><div style="font-size:16px;font-weight:800;flex:1" id="album-detail-top-title">Album</div></div>
      <div class="album-detail-header" id="album-detail-header"><div class="album-detail-banner-overlay"></div><img class="album-detail-img" id="album-detail-img" src="" alt="Album" loading="lazy"/><div class="album-detail-info"><div class="album-detail-title" id="album-detail-title"></div><div class="album-detail-artist" id="album-detail-artist"></div><div class="album-detail-year" id="album-detail-year"></div><button class="album-detail-play-btn" id="album-detail-play-all-btn" style="display:none;"><i class="fas fa-play" style="margin-right:8px;"></i> Play Album</button></div></div>
      <div id="album-detail-songs-list"></div>
      <div id="album-detail-empty" class="empty" style="display:none;"><i class="fas fa-music"></i>No songs found in this album.</div>
    </div>
    <div id="scr-library" class="scr">
      <div class="ltabs"><div class="lt on" id="lt-pl" onclick="libTab('pl')">Playlists</div><div class="lt" id="lt-lk" onclick="libTab('lk')">Liked</div><div class="lt" id="lt-rc" onclick="libTab('rc')">Recent</div><div class="lt" id="lt-dl" onclick="libTab('dl')">Downloads</div></div>
      <div id="lib-pl"><div class="new-pl" onclick="openNewPl()"><i class="fas fa-plus"></i> Create New Playlist</div><div id="pl-list"></div></div>
      <div id="lib-lk" style="display:none"><div id="liked-list"></div></div>
      <div id="lib-rc" style="display:none"><div id="rc-list"></div></div>
      <div id="lib-dl" style="display:none"><div id="dl-list"></div></div>
    </div>
    <div id="scr-playlist-detail" class="scr">
      <div style="position:sticky;top:0;z-index:10;background:rgba(10,10,10,.9);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:14px;padding:12px 16px"><button class="ib" onclick="goScreen('library')"><i class="fas fa-arrow-left"></i></button><div style="font-size:16px;font-weight:800;flex:1" id="pd-top-title">Playlist</div><button class="ib" onclick="openPlOpts()"><i class="fas fa-ellipsis-v"></i></button></div>
      <div class="pd-header"><div class="pd-banner" id="pd-banner"></div><div class="pd-content"><div class="pd-title" id="pd-title"></div><div class="pd-meta"><span id="pd-songs-count">0 songs</span><span>&bull;</span><span id="pd-owner"></span></div><button class="pd-play-btn" id="pd-play-all-btn" style="display:none;"><i class="fas fa-play" style="margin-right:8px;"></i> Play All Songs</button></div></div>
      <div id="pd-list"></div>
      <div id="pd-empty" class="empty" style="display:none;"><i class="fas fa-music"></i>This playlist is empty.<br>Add some songs to it!</div>
    </div>
    <div id="scr-profile" class="scr">
      <div class="prof-h"><div class="prof-av" id="prof-av"><i class="fas fa-user"></i></div><div class="prof-name" id="prof-name">Guest User</div><div class="prof-sub" id="prof-email">Sign in to sync</div><div class="prof-stats"><div class="ps"><div class="ps-n" id="st-lk">0</div><div class="ps-l">Liked</div></div><div class="ps"><div class="ps-n" id="st-pl">0</div><div class="ps-l">Playlists</div></div><div class="ps"><div class="ps-n" id="st-dl">0</div><div class="ps-l">Downloads</div></div></div></div>
      <div style="padding:8px 0">
        <div class="si" onclick="toggleTheme()"><div class="si-ic"><i class="fas fa-moon"></i></div><div class="si-lbl">Dark Mode</div><div class="tog" id="dm-tog"></div></div>
        <div class="si" onclick="showToast('High quality audio enabled')"><div class="si-ic"><i class="fas fa-sliders-h"></i></div><div class="si-lbl">Audio Quality</div><div class="si-r">320kbps <i class="fas fa-chevron-right" style="font-size:10px;margin-left:4px"></i></div></div>
        <div class="si" id="signout-btn" style="display:none" onclick="signOut()"><div class="si-ic" style="background:var(--bg4);color:#ff4466"><i class="fas fa-sign-out-alt"></i></div><div class="si-lbl">Sign Out</div></div>
        <div class="si" onclick="goScreen('privacy-policy')"><div class="si-ic"><i class="fas fa-shield-alt"></i></div><div class="si-lbl">Privacy Policy</div><div class="si-r"><i class="fas fa-chevron-right" style="font-size:10px;margin-left:4px"></i></div></div>
        <div class="si" onclick="goScreen('terms-conditions')"><div class="si-ic"><i class="fas fa-file-contract"></i></div><div class="si-lbl">Terms & Conditions</div><div class="si-r"><i class="fas fa-chevron-right" style="font-size:10px;margin-left:4px"></i></div></div>
        <div class="si" onclick="togglePushNotifications()"><div class="si-ic"><i class="fas fa-bell"></i></div><div class="si-lbl">Push Notifications</div><div class="tog on" id="notif-tog"></div></div>
        <div class="si" id="send-notif-btn" style="display:none" onclick="openSendNotifModal()"><div class="si-ic" style="background:var(--bg4);color:#ff4466"><i class="fas fa-paper-plane"></i></div><div class="si-lbl">Send Notification</div><div class="si-r"><i class="fas fa-chevron-right" style="font-size:10px;margin-left:4px"></i></div></div>
        <div class="si" onclick="clearAll()"><div class="si-ic" style="background:var(--bg4);color:#ff4466"><i class="fas fa-trash-alt"></i></div><div class="si-lbl">Clear All Local Data</div></div>
        <div class="si" onclick="window.open('https://t.me/PKDRobo_Owner', '_blank')"><div class="si-ic"><i class="fab fa-telegram-plane"></i></div><div class="si-lbl">Developed by Pratik Das</div><div class="si-r"><i class="fas fa-external-link-alt" style="font-size:10px;margin-left:4px"></i></div></div>
        <div class="si" onclick="showToast('Let Lyre v1.0')"><div class="si-ic"><i class="fas fa-info-circle"></i></div><div class="si-lbl">About Let Lyre</div><div class="si-r">v1.0</div></div>
      </div>
    </div>
    <div id="scr-privacy-policy" class="scr">
      <div style="position:sticky;top:0;z-index:10;background:rgba(10,10,10,.9);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:14px;padding:12px 16px"><button class="ib" onclick="goScreen('profile')"><i class="fas fa-arrow-left"></i></button><div style="font-size:16px;font-weight:800;flex:1">Privacy Policy</div></div>
      <div style="padding:24px 20px 60px;max-width:720px;margin:0 auto"><div style="font-size:28px;font-weight:900;margin-bottom:8px;background:var(--g1);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">Privacy Policy</div><div style="font-size:12px;color:var(--t2);margin-bottom:24px">Last Updated: May 20, 2026</div><p style="font-size:14px;line-height:1.8;color:var(--t1);margin-bottom:12px">Let Lyre ("we", "our", "us") respects your privacy. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our music streaming application and website at <strong style="color:var(--t0)">https://LetLyre.vercel.app</strong>.</p><h2 style="font-size:18px;font-weight:700;margin:28px 0 10px;color:var(--acc)">1. Information We Collect</h2><p style="font-size:14px;line-height:1.8;color:var(--t1);margin-bottom:12px">We collect minimal information necessary to provide you with a seamless music streaming experience:</p><ul style="margin:8px 0 16px 20px"><li style="font-size:14px;line-height:1.8;color:var(--t1);margin-bottom:6px"><strong style="color:var(--t0)">Account Information:</strong> When you sign in with Google, we receive your name, email address, and profile picture.</li><li style="font-size:14px;line-height:1.8;color:var(--t1);margin-bottom:6px"><strong style="color:var(--t0)">Usage Data:</strong> We store your liked songs, playlists, recently played tracks, and downloads locally on your device.</li><li style="font-size:14px;line-height:1.8;color:var(--t1);margin-bottom:6px"><strong style="color:var(--t0)">Device Information:</strong> Basic device and browser information for performance optimization.</li></ul><h2 style="font-size:18px;font-weight:700;margin:28px 0 10px;color:var(--acc)">2. How We Use Your Information</h2><p style="font-size:14px;line-height:1.8;color:var(--t1);margin-bottom:12px">Your information is used solely to authenticate your account, sync your playlists and preferences across devices via MySQL, improve app performance, and send important service updates.</p><h2 style="font-size:18px;font-weight:700;margin:28px 0 10px;color:var(--acc)">3. Data Storage & Security</h2><p style="font-size:14px;line-height:1.8;color:var(--t1);margin-bottom:12px">Your account data is stored securely in our MySQL database. Locally stored data (likes, recent plays, downloads) remains on your device and is never uploaded to our servers unless you explicitly sync via your account.</p><h2 style="font-size:18px;font-weight:700;margin:28px 0 10px;color:var(--acc)">4. Third-Party Services</h2><p style="font-size:14px;line-height:1.8;color:var(--t1);margin-bottom:12px">We use Google Sign-In (OAuth) and Elite JioSaavn API (music data and streaming).</p><h2 style="font-size:18px;font-weight:700;margin:28px 0 10px;color:var(--acc)">5. Your Rights</h2><p style="font-size:14px;line-height:1.8;color:var(--t1);margin-bottom:12px">You have the right to access your personal data, delete your account and associated data, opt out of data collection by not signing in, and clear all locally stored data from the app settings.</p><h2 style="font-size:18px;font-weight:700;margin:28px 0 10px;color:var(--acc)">6. Contact</h2><p style="font-size:14px;line-height:1.8;color:var(--t1);margin-bottom:12px"><strong style="color:var(--t0)">Pratik Das</strong><br>Telegram: <a href="https://t.me/PKDRobo_Owner" target="_blank" style="color:var(--acc);text-decoration:none">@PKDRobo_Owner</a><br>Website: <a href="https://LetLyre.vercel.app" target="_blank" style="color:var(--acc);text-decoration:none">https://LetLyre.vercel.app</a></p></div>
    </div>
    <div id="scr-terms-conditions" class="scr">
      <div style="position:sticky;top:0;z-index:10;background:rgba(10,10,10,.9);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:14px;padding:12px 16px"><button class="ib" onclick="goScreen('profile')"><i class="fas fa-arrow-left"></i></button><div style="font-size:16px;font-weight:800;flex:1">Terms & Conditions</div></div>
      <div style="padding:24px 20px 60px;max-width:720px;margin:0 auto"><div style="font-size:28px;font-weight:900;margin-bottom:8px;background:var(--g1);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">Terms & Conditions</div><div style="font-size:12px;color:var(--t2);margin-bottom:24px">Last Updated: May 20, 2026</div><p style="font-size:14px;line-height:1.8;color:var(--t1);margin-bottom:12px">Welcome to Let Lyre. By using our music streaming application and website at <strong style="color:var(--t0)">https://LetLyre.vercel.app</strong>, you agree to these Terms & Conditions.</p><h2 style="font-size:18px;font-weight:700;margin:28px 0 10px;color:var(--acc)">1. Acceptance of Terms</h2><p style="font-size:14px;line-height:1.8;color:var(--t1);margin-bottom:12px">By accessing or using Let Lyre, you agree to be bound by these Terms. If you do not agree, please do not use the service.</p><h2 style="font-size:18px;font-weight:700;margin:28px 0 10px;color:var(--acc)">2. Service Description</h2><p style="font-size:14px;line-height:1.8;color:var(--t1);margin-bottom:12px">Let Lyre is a free music streaming application providing access to songs, albums, playlists, and artist information via third-party APIs. Features include music streaming, playlist creation, song liking, downloading, sharing, and Google Sign-In for account sync.</p><h2 style="font-size:18px;font-weight:700;margin:28px 0 10px;color:var(--acc)">3. User Accounts</h2><p style="font-size:14px;line-height:1.8;color:var(--t1);margin-bottom:12px">You may use the app as a guest without signing in. Signing in with Google allows you to sync playlists across devices. Let Lyre does not store your password.</p><h2 style="font-size:18px;font-weight:700;margin:28px 0 10px;color:var(--acc)">4. Acceptable Use</h2><p style="font-size:14px;line-height:1.8;color:var(--t1);margin-bottom:12px">You agree not to use the service for any illegal purpose, attempt to reverse engineer or hack the service, upload malicious code, or violate any applicable laws.</p><h2 style="font-size:18px;font-weight:700;margin:28px 0 10px;color:var(--acc)">5. Intellectual Property</h2><p style="font-size:14px;line-height:1.8;color:var(--t1);margin-bottom:12px">All music content is sourced from third-party APIs. We do not host or distribute copyrighted files. The Let Lyre name, logo, and app design are the property of the developer.</p><h2 style="font-size:18px;font-weight:700;margin:28px 0 10px;color:var(--acc)">6. Limitation of Liability</h2><p style="font-size:14px;line-height:1.8;color:var(--t1);margin-bottom:12px">Let Lyre is provided "as is" without warranties. The developer shall not be liable for any damages arising from the use of the service.</p><h2 style="font-size:18px;font-weight:700;margin:28px 0 10px;color:var(--acc)">7. Contact</h2><p style="font-size:14px;line-height:1.8;color:var(--t1);margin-bottom:12px"><strong style="color:var(--t0)">Pratik Das</strong><br>Telegram: <a href="https://t.me/PKDRobo_Owner" target="_blank" style="color:var(--acc);text-decoration:none">@PKDRobo_Owner</a><br>Website: <a href="https://LetLyre.vercel.app" target="_blank" style="color:var(--acc);text-decoration:none">https://LetLyre.vercel.app</a></p></div>
    </div>
  </div>
  <div id="pbar">
    <div class="pb-prog" onclick="miniSeek(event)"><div class="pb-fill" id="pb-fill"></div></div>
    <div class="pb-inner">
      <img class="pb-thumb" id="pb-thumb" src="" alt="" onclick="openFP()" loading="lazy"/>
      <div class="pb-info" onclick="openFP()"><div class="pb-title" id="pb-title">Nothing playing</div><div class="pb-art" id="pb-art">&mdash;</div></div>
      <button class="pb-hrt" id="pb-hrt" onclick="toggleLikeCur()"><i class="fas fa-heart"></i></button>
      <div class="pb-ctrls">
        <button class="cb" onclick="prevSong()"><i class="fas fa-step-backward"></i></button>
        <button class="cb play" onclick="togglePlay()"><i class="fas fa-play" id="pb-pi"></i></button>
        <button class="cb" onclick="nextSong()"><i class="fas fa-step-forward"></i></button>
      </div>
    </div>
  </div>
  <div id="bnav">
    <div class="ni on" id="ni-home" onclick="goScreen('home')"><i class="fas fa-home"></i><span>Home</span></div>
    <div class="ni" id="ni-search" onclick="goScreen('search')"><i class="fas fa-search"></i><span>Search</span></div>
    <div class="ni" id="ni-library" onclick="goScreen('library')"><i class="fas fa-book-open"></i><span>Library</span></div>
    <div class="ni" id="ni-profile" onclick="goScreen('profile')"><i class="fas fa-user-circle"></i><span>Profile</span></div>
  </div>
</div>
<div id="fp">
  <div class="fp-handle"></div>
  <div class="fp-top">
    <button class="fp-dn" onclick="closeFP()"><i class="fas fa-chevron-down"></i></button>
    <div class="fp-top-t">Now Playing</div>
    <button class="fp-more" onclick="openSongOpts()"><i class="fas fa-ellipsis-v"></i></button>
  </div>
  <div class="fp-art-area">
    <div class="fp-img-container">
      <div class="fp-img-glow"></div>
      <img class="fp-img" id="fp-img" src="" alt="" loading="lazy"/>
    </div>
  </div>
  <div class="fp-meta">
    <div class="fp-meta-txt"><div class="fp-title" id="fp-title">&mdash;</div><div class="fp-artist" id="fp-artist">&mdash;</div></div>
    <button class="fp-hrt" id="fp-hrt" onclick="toggleLikeCur()"><i class="fas fa-heart"></i></button>
  </div>
  <div class="fp-prog" onclick="fullSeek(event)">
    <div class="fp-track"><div class="fp-fill" id="fp-fill"></div><div class="fp-knob" id="fp-knob"></div></div>
  </div>
  <div class="fp-times"><span id="fp-cur">0:00</span><span id="fp-dur">0:00</span></div>
  <div class="fp-ctrls">
    <button class="fp-c" id="fp-shuf" onclick="toggleShuffle()"><i class="fas fa-random"></i></button>
    <button class="fp-c" onclick="prevSong()"><i class="fas fa-step-backward"></i></button>
    <button class="fp-play" onclick="togglePlay()"><i class="fas fa-play" id="fp-pi"></i></button>
    <button class="fp-c" onclick="nextSong()"><i class="fas fa-step-forward"></i></button>
    <button class="fp-c" id="fp-rep" onclick="toggleRepeat()"><i class="fas fa-redo"></i></button>
  </div>
  <div class="fp-vol">
    <i class="fas fa-volume-down"></i>
    <input type="range" class="vsl" id="vol-sl" min="0" max="100" value="80" oninput="setVol(this.value)"/>
    <i class="fas fa-volume-up"></i>
  </div>
  <div class="fp-xtra">
    <button class="fx" onclick="showLyrics()" title="Lyrics"><i class="fas fa-align-left"></i></button>
    <button class="fx" onclick="addToPLModal()" title="Add to playlist"><i class="fas fa-plus-circle"></i></button>
    <button class="fx" onclick="downloadSong()" title="Download"><i class="fas fa-download"></i></button>
    <button class="fx" onclick="shareSong()" title="Share"><i class="fas fa-share-alt"></i></button>
  </div>
</div>
<div class="mo" id="mo-opts" onclick="closeMoOnBg(event,'mo-opts')">
  <div class="ms"><div class="mh-bar"></div><div class="mo-t" id="mo-opts-t">Song Options</div>
    <div class="ma" onclick="toggleLikeCur();closeMo('mo-opts')"><i class="fas fa-heart"></i> Like / Unlike</div>
    <div class="ma" onclick="addToPLModal()"><i class="fas fa-plus"></i> Add to Playlist</div>
    <div class="ma" onclick="showLyrics()"><i class="fas fa-align-left"></i> View Lyrics</div>
    <div class="ma" onclick="downloadSong();closeMo('mo-opts')"><i class="fas fa-download"></i> Download</div>
    <div class="ma" onclick="shareSong()"><i class="fas fa-share"></i> Share</div>
    <div class="ma" onclick="closeMo('mo-opts')"><i class="fas fa-times"></i> Cancel</div>
  </div>
</div>
<div class="mo" id="mo-playlist-opts" onclick="closeMoOnBg(event,'mo-playlist-opts')">
  <div class="ms"><div class="mh-bar"></div><div class="mo-t" id="mo-playlist-opts-t">Playlist Options</div>
    <div class="ma" onclick="openRenamePl();closeMo('mo-playlist-opts')"><i class="fas fa-edit"></i> Rename Playlist</div>
    <div class="ma" onclick="deleteCurPlaylist();closeMo('mo-playlist-opts')"><i class="fas fa-trash"></i> Delete Playlist</div>
    <div class="ma" onclick="closeMo('mo-playlist-opts')"><i class="fas fa-times"></i> Cancel</div>
  </div>
</div>
<div class="mo" id="mo-lyrics" onclick="closeMoOnBg(event,'mo-lyrics')">
  <div class="ms" style="max-height:85vh"><div class="mh-bar"></div><div class="mo-t" id="ly-title">Lyrics</div><div class="ly-box" id="ly-body"><p style="text-align:center;color:var(--t2)">Loading...</p></div></div>
</div>
<div class="mo" id="mo-pl" onclick="closeMoOnBg(event,'mo-pl')">
  <div class="ms"><div class="mh-bar"></div><div class="mo-t">Add to Playlist</div><div id="mo-pl-list"></div><div class="ma" onclick="openNewPl();closeMo('mo-pl')"><i class="fas fa-plus"></i> New Playlist</div></div>
</div>
<div class="im" id="im-newpl">
  <div class="im-box"><h3>Create Playlist</h3><input class="im-inp" id="im-inp" placeholder="Playlist name..." maxlength="40" onkeydown="if(event.key==='Enter')confirmNewPl()"/><div class="im-btns"><button class="ibtn ibc" onclick="closeMo('im-newpl')">Cancel</button><button class="ibtn ibo" onclick="confirmNewPl()">Create</button></div></div>
</div>
<div class="im" id="im-renamepl">
  <div class="im-box"><h3>Rename Playlist</h3><input class="im-inp" id="im-rename-inp" placeholder="New playlist name..." maxlength="40" onkeydown="if(event.key==='Enter')confirmRenamePl()"/><div class="im-btns"><button class="ibtn ibc" onclick="closeMo('im-renamepl')">Cancel</button><button class="ibtn ibo" onclick="confirmRenamePl()">Rename</button></div></div>
</div>
<div class="im" id="im-send-notif">
  <div class="im-box"><h3>Send Notification</h3><input class="im-inp" id="notif-title-inp" placeholder="Notification title..." maxlength="80"/><input class="im-inp" id="notif-msg-inp" placeholder="Notification message..." maxlength="200"/><div class="im-btns"><button class="ibtn ibc" onclick="closeMo('im-send-notif')">Cancel</button><button class="ibtn ibo" onclick="confirmSendNotif()">Send</button></div></div>
</div>
<audio id="aud" preload="metadata"></audio>
<script>
/* ══════════════════════════════════════════════════
   LET LYRE - Music Streaming App
   With PHP + MySQL Backend & Google Authentication
═══════════════════════════════════════════════════ */

const PHP_USER = <?php echo $currentUserJson; ?>;
const API = 'https://elitejiosaavn-api.vercel.app';
const APP_LOGO = 'https://iili.io/fzn2aRf.png';
const GOOGLE_CLIENT_ID = '756188842044-04nggq5go2tf2roqmgk2qt0cg4p8fat1.apps.googleusercontent.com';
const aud = document.getElementById('aud');

let curSong = null, curQueue = [], curIdx = 0, playing = false;
let shuffle = false, repeat = false, currentUser = null;
let homeLang = '', saType = '', saTitle2 = '', saPage = 1, saItems = [];
let saLoading = false, saQuery = '', saItemType = 'song';
let srchQ = '', srchPage = 1, srchLoading = false, isSearchLoading = false;
let srchResults = { songs: [], albums: [], artists: [], playlists: [] };
let currentPlaylist = null, currentArtist = null, currentAlbum = null;
let userPlaylistsCache = [], initAppDone = false;
let homeScrollObserver = null, seeAllObserver = null, searchScrollObserver = null;
let categoriesLoadIndex = 0, isLoadingMoreCategories = false, autoRefreshTimer = null;
const CATEGORIES_BATCH_SIZE = 10;

// ─── Storage ─────────────────────────────────────────────────
const S = {
  g: function(k) { try { return JSON.parse(localStorage.getItem('letlyre_'+k)); } catch(e) { return null; } },
  s: function(k, v) { try { localStorage.setItem('letlyre_'+k, JSON.stringify(v)); } catch(e) {} },
  liked: function() { return S.g('liked') || []; },
  recent: function() { return S.g('recent') || []; },
  downloads: function() { return S.g('downloads') || []; }
};

// ─── PHP API Helper ──────────────────────────────────────────
function apiCall(action, data) {
  var fd = new FormData();
  if (data) for (var k in data) if (data.hasOwnProperty(k)) fd.append(k, data[k]);
  return fetch('?action='+encodeURIComponent(action), {
    method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd
  }).then(function(r) { return r.json(); }).catch(function(e) {
    console.error('apiCall error:', action, e); return { error: 'Network error' };
  });
}

// ─── Image Helpers ───────────────────────────────────────────
function getImg(s) {
  var a = s.image || [];
  var h = a.find(function(x) { return x.quality==='500x500'; }) || a.find(function(x) { return x.quality==='150x150'; }) || a[a.length-1];
  return (h && h.url) || APP_LOGO;
}
function getArtistImg(s) {
  var a = s.image || [];
  var h = a.find(function(x) { return x.quality==='500x500'; }) || a.find(function(x) { return x.quality==='150x150'; }) || a[a.length-1];
  return (h && h.url) || APP_LOGO;
}
function smartImg(el, url) {
  if (!el) return;
  el.src = APP_LOGO;
  if (!url || url===APP_LOGO) return;
  var img = new Image();
  img.crossOrigin = 'anonymous';
  img.onload = function() { el.src = url; };
  img.src = url;
}
function initImgs(root) {
  if (!root) return;
  root.querySelectorAll('img[data-src]').forEach(function(img) {
    var url = img.getAttribute('data-src');
    if (url && url!==APP_LOGO) smartImg(img, url);
  });
}
function getDl(s) {
  var a = s.downloadUrl || [];
  var h = a.find(function(x) { return x.quality==='320kbps'; }) || a.find(function(x) { return x.quality==='160kbps'; }) || a[a.length-1];
  return (h && h.url) || '';
}
function getArtist(s) {
  if (s.artists && s.artists.primary && s.artists.primary.length) {
    var names = s.artists.primary.map(function(a) { return a.name; });
    return names.length>2 ? names.slice(0,2).join(', ')+'...' : names.join(', ');
  }
  if (s.primaryArtists) { var n=s.primaryArtists.replace(/&amp;/g,'&'), p=n.split(',').map(function(x){return x.trim();}); return p.length>2?p.slice(0,2).join(', ')+'...':p.join(', '); }
  if (s.singers) { var n=s.singers.replace(/&amp;/g,'&'), p=n.split(',').map(function(x){return x.trim();}); return p.length>2?p.slice(0,2).join(', ')+'...':p.join(', '); }
  return '\u2014';
}
function getAlbumArtists(a) {
  if (a.artists && a.artists.primary && a.artists.primary.length) { var n=a.artists.primary.map(function(x){return x.name;}); return n.length>2?n.slice(0,2).join(', ')+'...':n.join(', '); }
  if (a.artist) return a.artist;
  if (a.artists && a.artists.length) { var n=a.artists.map(function(x){return x.name||x;}); return n.length>2?n.slice(0,2).join(', ')+'...':n.join(', '); }
  if (a.primaryArtist) return a.primaryArtist;
  if (a.subtitle) return a.subtitle;
  return '\u2014';
}
function fmtT(s) { if (!s||isNaN(s)) return '0:00'; return Math.floor(s/60)+':'+String(Math.floor(s%60)).padStart(2,'0'); }
function fmtDur(d) { if (!d) return ''; return /^\d+$/.test(String(d)) ? fmtT(+d) : String(d); }
function isLiked(s) { return s && S.liked().some(function(x){return x.id===s.id;}); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function slugify(s) { return String(s||'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,''); }

// ─── Google Sign-In ──────────────────────────────────────────
function handleGoogleSignIn() {
  google.accounts.id.prompt();
}

function handleGoogleCredentialResponse(response) {
  if (!response || !response.credential) {
    showToast('Google sign-in failed. Please try again.');
    return;
  }
  var fd = new FormData();
  fd.append('credential', response.credential);
  fetch('google-callback.php', { method: 'POST', body: fd })
    .then(function(r) {
      if (!r.ok) showToast('Server returned '+r.status+'. Check server logs.');
      return r.json();
    })
    .then(function(data) {
      if (data.success && data.user) {
        currentUser = data.user;
        updateAuthUI();
        loadUserPlaylists();
        document.getElementById('auth-screen').classList.remove('on');
        showToast('Signed in as '+data.user.name);
      } else {
        showToast(data.error || 'Sign-in failed. Please try again.');
      }
    })
    .catch(function(e) {
      console.error('Google callback error:', e);
      showToast('Sign-in error. Check that google-callback.php exists and has no PHP errors.');
    });
}

function dismissAuth() {
  document.getElementById('auth-screen').classList.remove('on');
  showToast('Continuing as guest');
}

async function signOut() {
  var r = await apiCall('logout');
  if (r.success) {
    currentUser = null;
    userPlaylistsCache = [];
    updateAuthUI();
    showToast('Signed out');
    goScreen('home');
    document.getElementById('auth-screen').classList.add('on');
  }
}

// ─── Auth UI ─────────────────────────────────────────────────
function updateAuthUI() {
  var btn = document.getElementById('auth-btn');
  var sout = document.getElementById('signout-btn');
  var pn = document.getElementById('prof-name');
  var pe = document.getElementById('prof-email');
  var pa = document.getElementById('prof-av');
  if (currentUser) {
    btn.innerHTML = '<i class="fas fa-user-check"></i>';
    btn.style.color = 'var(--acc)';
    sout.style.display = 'flex';
    pn.textContent = currentUser.name || 'User';
    pe.textContent = currentUser.email || '';
    if (currentUser.avatar) {
      pa.innerHTML = '<img src="'+esc(currentUser.avatar)+'" alt="Avatar" loading="lazy">';
      pa.style.background = 'none';
      pa.style.border = 'none';
    } else {
      pa.innerHTML = '<i class="fas fa-user"></i>';
      pa.style.background = 'var(--g1)';
      pa.style.border = '3px solid var(--acc)';
    }
  } else {
    btn.innerHTML = '<i class="fas fa-user"></i>';
    btn.style.color = 'var(--t1)';
    sout.style.display = 'none';
    pn.textContent = 'Guest User';
    pe.textContent = 'Sign in to sync';
    pa.innerHTML = '<i class="fas fa-user"></i>';
    pa.style.background = 'var(--g1)';
    pa.style.border = '3px solid var(--acc)';
  }
}

// ─── Theme ────────────────────────────────────────────────────
function applyTheme() { if (S.g('theme')==='light') setLightTheme(); else setDarkTheme(); }
function setLightTheme() { document.documentElement.setAttribute('data-theme','light'); document.getElementById('thm-ico').className='fas fa-sun'; document.getElementById('dm-tog').classList.add('on'); }
function setDarkTheme() { document.documentElement.removeAttribute('data-theme'); document.getElementById('thm-ico').className='fas fa-moon'; document.getElementById('dm-tog').classList.remove('on'); }
function toggleTheme() {
  if (document.documentElement.getAttribute('data-theme')==='light') { setDarkTheme(); S.s('theme','dark'); }
  else { setLightTheme(); S.s('theme','light'); }
}

// ─── Screen Navigation ───────────────────────────────────────
const MAIN_SCREENS = ['home','search','library','profile'];
function goScreen(name, id) {
  if (!currentUser && (name==='profile'||name==='library'||name==='playlist-detail')) {
    document.getElementById('auth-screen').classList.add('on');
    showToast('Please sign in to access this feature.');
    return;
  }
  document.querySelectorAll('.scr').forEach(function(s){s.classList.remove('on');});
  document.getElementById('scr-'+name).classList.add('on');
  document.getElementById('scr-'+name).scrollTop = 0;
  if (MAIN_SCREENS.includes(name)) { document.querySelectorAll('.ni').forEach(function(n){n.classList.remove('on');}); document.getElementById('ni-'+name).classList.add('on'); }
  if (name==='library') renderLib();
  if (name==='profile') updateStats();
  if (name==='playlist-detail' && id) loadPlaylistDetail(id);
  else if (name==='artist-detail' && id) loadArtistDetail(id);
  else if (name==='album-detail' && id) loadAlbumDetail(id);
}

// ─── Playlists ───────────────────────────────────────────────
async function loadUserPlaylists() {
  if (!currentUser) { userPlaylistsCache = []; renderPlaylists(); return; }
  var r = await apiCall('get_playlists');
  if (r && r.playlists) {
    userPlaylistsCache = r.playlists;
    renderPlaylists();
    updateStats();
  }
}

async function confirmNewPl() {
  if (!currentUser) { showToast('You must be signed in.'); return; }
  var name = document.getElementById('im-inp').value.trim();
  if (!name) { showToast('Enter a playlist name'); return; }
  var r = await apiCall('create_playlist', { name: name });
  if (r.success) {
    userPlaylistsCache.push({ id: r.id, name: r.name, songs: [] });
    closeMo('im-newpl');
    showToast('"'+r.name+'" created!');
    renderPlaylists();
    updateStats();
  } else { showToast(r.error || 'Failed to create playlist.'); }
}

async function confirmRenamePl() {
  if (!currentUser || !currentPlaylist) return;
  var name = document.getElementById('im-rename-inp').value.trim();
  if (!name) { showToast('Enter a name'); return; }
  var r = await apiCall('rename_playlist', { id: currentPlaylist.id, name: name });
  if (r.success) { currentPlaylist.name = name; closeMo('im-renamepl'); showToast('Renamed!'); renderPlaylists(); updateStats(); }
  else { showToast(r.error || 'Rename failed.'); }
}

async function deleteCurPlaylist() {
  if (!currentUser || !currentPlaylist) return;
  if (!confirm('Delete "'+currentPlaylist.name+'"?')) return;
  var r = await apiCall('delete_playlist', { id: currentPlaylist.id });
  if (r.success) {
    userPlaylistsCache = userPlaylistsCache.filter(function(p){return p.id!==currentPlaylist.id;});
    currentPlaylist = null;
    showToast('Playlist deleted');
    renderPlaylists();
    updateStats();
    goScreen('library');
  } else { showToast('Delete failed.'); }
}

async function addToPLModal() {
  closeMo('mo-opts');
  if (!curSong) { showToast('No song selected.'); return; }
  if (!currentUser) { document.getElementById('auth-screen').classList.add('on'); showToast('Please sign in.'); return; }
  var el = document.getElementById('mo-pl-list');
  el.innerHTML = '';
  if (!userPlaylistsCache.length) el.innerHTML = '<div class="empty" style="padding:16px">No playlists yet.</div>';
  else {
    userPlaylistsCache.forEach(function(pl) {
      var d = document.createElement('div');
      d.className = 'ma';
      var already = pl.songs.some(function(s){return s.id===curSong.id;});
      d.innerHTML = '<i class="fas '+(already?'fa-check-circle':'fa-list')+'"></i> '+esc(pl.name)+' <span style="margin-left:auto;font-size:11px;color:var(--t2)">'+(pl.songs?pl.songs.length:0)+' songs</span>';
      if (already) { d.style.color='var(--acc)'; d.style.pointerEvents='none'; }
      d.onclick = async function() {
        if (!already) {
          var r = await apiCall('add_song', { playlist_id: pl.id, song_id: curSong.id, song_data: JSON.stringify(curSong) });
          if (r.success) { pl.songs.push(curSong); showToast('Added to "'+pl.name+'"'); } else showToast('Failed.');
        }
        closeMo('mo-pl');
        renderPlaylists();
        updateStats();
      };
      el.appendChild(d);
    });
  }
  document.getElementById('mo-pl').classList.add('on');
}

async function removeFromPlaylist(plId, songId) {
  if (!currentUser) { showToast('Sign in required.'); return; }
  closeMo('mo-opts');
  if (!confirm('Remove this song?')) return;
  var r = await apiCall('remove_song', { playlist_id: plId, song_id: songId });
  if (r.success) {
    var pl = userPlaylistsCache.find(function(p){return p.id===plId;});
    if (pl) pl.songs = pl.songs.filter(function(s){return s.id!==songId;});
    showToast('Song removed!');
    if (document.getElementById('scr-playlist-detail').classList.contains('on') && currentPlaylist && currentPlaylist.id===plId) loadPlaylistDetail(plId);
    renderPlaylists();
    updateStats();
  } else showToast('Failed.');
}

// ─── Announcements ───────────────────────────────────────────
let currentAnnouncement=null, announceTimerInterval=null, announceCountdownValue=0, announceDismissed=false, announceSessionShown=false;

async function fetchActiveAnnouncement() {
  var cached = S.g('announce_cache');
  if (cached && cached.id) {
    var now = new Date().toISOString();
    if (cached.start_date<=now && cached.end_date>=now && cached.status==='active') return cached;
  }
  var r = await apiCall('get_announcement');
  if (r && r.id) { S.s('announce_cache', r); return r; }
  return null;
}

function showAnnouncement(ann) {
  return new Promise(function(resolve) {
    currentAnnouncement = ann; announceDismissed = false; announceSessionShown = true;
    document.getElementById('announce-img').src = ann.image_url || APP_LOGO;
    document.getElementById('announce-title').textContent = ann.title || 'Special Announcement';
    document.getElementById('announce-desc').textContent = ann.description || '';
    document.getElementById('announce-badge').textContent = ann.badge || 'Promotion';
    announceCountdownValue = ann.countdown_seconds || 5;
    var btn = document.getElementById('announce-close-btn'), cnt = document.getElementById('announce-countdown');
    btn.classList.remove('enabled'); btn.disabled = true; btn.style.pointerEvents = 'none'; cnt.textContent = announceCountdownValue;
    document.getElementById('announce-img-wrap').onclick = function() {
      if (ann.redirect_url) { trackAnnounceClick(ann.id); window.open(ann.redirect_url,'_blank'); }
    };
    document.getElementById('announcement-overlay').classList.add('on');
    if (announceTimerInterval) clearInterval(announceTimerInterval);
    announceTimerInterval = setInterval(function() {
      announceCountdownValue--; cnt.textContent = Math.max(0,announceCountdownValue);
      if (announceCountdownValue<=0) { clearInterval(announceTimerInterval); announceTimerInterval=null; btn.classList.add('enabled'); btn.disabled=false; btn.style.pointerEvents='auto'; btn.textContent='Continue'; }
    }, 1000);
    trackAnnounceView(ann.id);
    window._announceResolve = resolve;
  });
}

function dismissAnnouncement() {
  if (announceCountdownValue>0) return;
  if (announceTimerInterval) clearInterval(announceTimerInterval); announceTimerInterval=null;
  var o = document.getElementById('announcement-overlay');
  o.classList.add('closing');
  setTimeout(function(){ o.classList.remove('on','closing'); if (window._announceResolve) { window._announceResolve(); window._announceResolve=null; } }, 400);
}

async function trackAnnounceView(id) {
  var v = S.g('visitor_id');
  if (!v) { v='v_'+Date.now()+'_'+Math.random().toString(36).slice(2,8); S.s('visitor_id',v); }
  apiCall('track_view', { announcement_id: id, visitor_id: v, clicked: 0 });
}
async function trackAnnounceClick(id) {
  var v = S.g('visitor_id');
  if (!v) { v='v_'+Date.now()+'_'+Math.random().toString(36).slice(2,8); S.s('visitor_id',v); }
  apiCall('track_view', { announcement_id: id, visitor_id: v, clicked: 1 });
}

async function checkAndShowAnnouncement() {
  try {
    var ann = await fetchActiveAnnouncement();
    if (ann && !announceSessionShown) await showAnnouncement(ann);
  } catch(e) { console.warn('Announcement error:',e); }
  if (!currentUser) document.getElementById('auth-screen').classList.add('on');
}

// ─── API Calls ───────────────────────────────────────────────
async function callApi(endpoint, params) {
  var qs = Object.keys(params||{}).filter(function(k){return params[k]!==undefined&&params[k]!==null;}).map(function(k){return encodeURIComponent(k)+'='+encodeURIComponent(params[k]);}).join('&');
  var url = API+endpoint+(qs?'?'+qs:'');
  try {
    var c = new AbortController();
    var id = setTimeout(function(){c.abort();},8000);
    var r = await fetch(url,{signal:c.signal});
    clearTimeout(id);
    if (!r.ok) { if (r.status===404) return null; throw new Error('HTTP '+r.status); }
    var d = await r.json();
    return d.success ? d.data : null;
  } catch(e) {
    if (e.name==='AbortError') { console.warn('API timeout:',url); showToast('API timed out.',3000); }
    else { console.error('API error:',url,e); showToast('Failed to fetch data.',3000); }
    return null;
  }
}

async function apiSongs(q,page,limit) { var d=await callApi('/api/search/songs',{query:q,page:(page||1)-1,limit:limit||20}); return (d&&d.results)||[]; }
async function apiTrendingSongs(p,l) { var d=await callApi('/api/trending/songs',{page:p||1,limit:l||20}); return (d&&d.results)||[]; }
async function apiTrendingAlbums(p,l) { var d=await callApi('/api/trending/albums',{page:p||1,limit:l||20}); return (d&&d.results)||[]; }
async function apiTrendingPlaylists(p,l) { var d=await callApi('/api/trending/playlists',{page:p||1,limit:l||20}); return (d&&d.results)||[]; }
async function apiTrendingArtists(p,l) { var d=await callApi('/api/trending/artists',{page:p||1,limit:l||20}); return (d&&d.results)||[]; }
async function apiHomeArtistRecommendations(p,l) { var d=await callApi('/api/home/artist-recommendations',{page:p||1,limit:l||20}); return (d&&d.results)||[]; }
async function apiAlbumById(id) { return await callApi('/api/albums',{id:id}); }
async function apiArtistById(id) { return await callApi('/api/artists',{id:id,songCount:100,albumCount:100}); }
async function apiArtistSongs(aId,p,l) { var d=await callApi('/api/artists/'+aId+'/songs',{page:p||0,limit:l||20,sortBy:'popularity',sortOrder:'desc'}); return (d&&d.songs)||[]; }
async function apiArtistAlbums(aId,p,l) { var d=await callApi('/api/artists/'+aId+'/albums',{page:p||0,limit:l||20,sortBy:'popularity',sortOrder:'desc'}); return (d&&d.albums)||[]; }
async function apiPlaylistById(id) { return await callApi('/api/playlists',{id:id,page:0,limit:1000}); }
async function apiGlobalSearch(q) { var d=await callApi('/api/search',{query:q}); return { songs:(d&&d.songs&&d.songs.results)||[], albums:(d&&d.albums&&d.albums.results)||[], artists:(d&&d.artists&&d.artists.results)||[], playlists:(d&&d.playlists&&d.playlists.results)||[] }; }
async function apiGetSongById(id) { var d=await callApi('/api/songs',{id:id}); return d?d[0]:null; }

// ─── Categories ──────────────────────────────────────────────
const CATEGORY_CONFIG = [
  {id:'90s-hindi',title:'90s Hindi Hits',icon:'fa-compact-disc',query:'90s hindi songs',color:'#f87171'},
  {id:'old-is-gold',title:'Old is Gold',icon:'fa-record-vinyl',query:'old is gold hindi songs',color:'#fbbf24'},
  {id:'2010s-hindi',title:'2010s Bollywood',icon:'fa-compact-disc',query:'2010s hindi songs',color:'#f43f5e'},
  {id:'2000s-hindi',title:'2000s Bollywood',icon:'fa-compact-disc',query:'2000s bollywood songs',color:'#fb7185'},
  {id:'80s-retro',title:'80s Classics',icon:'fa-record-vinyl',query:'80s songs bollywood',color:'#f59e0b'},
  {id:'70s-golden',title:'70s Golden Era',icon:'fa-record-vinyl',query:'70s bollywood songs',color:'#fbbf24'},
  {id:'romantic-hindi-90s',title:'90s Romantic Hindi',icon:'fa-heart',query:'90s romantic hindi songs',color:'#f472b6'},
  {id:'sad-hindi-90s',title:'90s Sad Hindi',icon:'fa-cloud-rain',query:'90s sad songs hindi',color:'#6366f1'},
  {id:'party-hindi-90s',title:'90s Party Hindi',icon:'fa-glass-cheers',query:'90s dance songs hindi',color:'#f59e0b'},
  {id:'hindi',title:'Hindi Hits',icon:'fa-music',query:'hindi songs',color:'#ef4444'},
  {id:'arijit',title:'Arijit Singh Picks',icon:'fa-microphone',query:'Arijit Singh top songs',color:'#a855f7'},
  {id:'shreya',title:'Shreya Ghoshal Hits',icon:'fa-music',query:'Shreya Ghoshal hits',color:'#f472b6'},
  {id:'kk',title:'KK Hits',icon:'fa-microphone',query:'kk songs',color:'#f87171'},
  {id:'sonu',title:'Sonu Nigam',icon:'fa-microphone',query:'sonu nigam songs',color:'#fbbf24'},
  {id:'atif',title:'Atif Aslam',icon:'fa-microphone',query:'atif aslam songs',color:'#fb7185'},
  {id:'armaan',title:'Armaan Malik',icon:'fa-microphone',query:'armaan malik songs',color:'#f43f5e'},
  {id:'neha',title:'Neha Kakkar',icon:'fa-microphone',query:'neha kakkar songs',color:'#ec4899'},
  {id:'romantic',title:'Romantic Anthems',icon:'fa-heart',query:'romantic love songs',color:'#ec4899'},
  {id:'sad',title:'Melancholy Moods',icon:'fa-cloud-rain',query:'sad emotional songs',color:'#6366f1'},
  {id:'party',title:'Party Boosters',icon:'fa-glass-cheers',query:'party dance songs',color:'#f59e0b'},
  {id:'devotional',title:'Divine Melodies',icon:'fa-pray',query:'devotional bhajan',color:'#14b8a6'},
  {id:'new',title:'New Releases',icon:'fa-star',query:'new songs 2025',color:'#4ecdc4'},
  {id:'holi',title:'Holi Songs',icon:'fa-palette',query:'holi songs',color:'#f97316'},
  {id:'diwali',title:'Diwali Songs',icon:'fa-fire-extinguisher',query:'diwali songs',color:'#facc15'},
  {id:'navratri',title:'Navratri Garba',icon:'fa-drum',query:'garba songs',color:'#fb923c'},
  {id:'punjabi',title:'Punjabi Power',icon:'fa-drum',query:'punjabi songs',color:'#84cc16'},
  {id:'sidhu',title:'Sidhu Moosewala',icon:'fa-drum',query:'sidhu moosewala songs',color:'#84cc16'},
  {id:'diljit',title:'Diljit Dosanjh',icon:'fa-drum',query:'diljit songs',color:'#65a30d'},
  {id:'karanaujla',title:'Karan Aujla',icon:'fa-drum',query:'karan aujla songs',color:'#4d7c0f'},
  {id:'romantic-punjabi',title:'Punjabi Romantic',icon:'fa-heart',query:'punjabi romantic songs',color:'#84cc16'},
  {id:'sad-punjabi',title:'Punjabi Sad',icon:'fa-cloud-rain',query:'punjabi sad songs',color:'#65a30d'},
  {id:'party-punjabi',title:'Punjabi Party',icon:'fa-glass-cheers',query:'punjabi party songs',color:'#4d7c0f'},
  {id:'tamil',title:'Tamil Beats',icon:'fa-music',query:'tamil songs',color:'#0ea5e9'},
  {id:'telugu',title:'Telugu Hits',icon:'fa-music',query:'telugu songs',color:'#6366f1'},
  {id:'bengali',title:'Bengali Vibes',icon:'fa-music',query:'bengali songs',color:'#22c55e'},
  {id:'malayalam',title:'Malayalam Magic',icon:'fa-music',query:'malayalam songs',color:'#14b8a6'},
  {id:'kannada',title:'Kannada Tracks',icon:'fa-music',query:'kannada songs',color:'#84cc16'},
  {id:'marathi',title:'Marathi Hits',icon:'fa-music',query:'marathi songs',color:'#f97316'},
  {id:'gujarati',title:'Gujarati Garba',icon:'fa-music',query:'gujarati songs',color:'#eab308'},
  {id:'assamese',title:'Assamese Music',query:'assamese songs',color:'#10b981'},
  {id:'english',title:'English Hits',icon:'fa-globe',query:'english songs',color:'#3b82f6'},
  {id:'party-english',title:'English Party Hits',icon:'fa-glass-cheers',query:'english party songs',color:'#2563eb'},
  {id:'romantic-english',title:'English Love Songs',icon:'fa-heart',query:'english romantic songs',color:'#3b82f6'},
  {id:'sad-english',title:'English Sad Songs',icon:'fa-cloud-rain',query:'english sad songs',color:'#1d4ed8'},
  {id:'kpop',title:'K-Pop Fever',icon:'fa-globe',query:'kpop songs',color:'#ec4899'},
  {id:'jpop',title:'J-Pop Hits',icon:'fa-globe',query:'jpop songs',color:'#f472b6'},
  {id:'latin',title:'Latin Beats',icon:'fa-globe',query:'latin songs',color:'#f97316'},
  {id:'afrobeats',title:'Afrobeats',icon:'fa-globe',query:'afrobeats songs',color:'#22c55e'},
  {id:'hiphop',title:'Hip Hop Flow',icon:'fa-headphones',query:'hip hop rap songs',color:'#7e22ce'},
  {id:'edm',title:'Electronic Beats',icon:'fa-bolt',query:'electronic dance music',color:'#0ea5e9'},
  {id:'rock',title:'Rock Music',icon:'fa-guitar',query:'rock songs',color:'#dc2626'},
  {id:'pop',title:'Pop Hits',icon:'fa-music',query:'pop songs',color:'#2563eb'},
  {id:'jazz',title:'Jazz Classics',icon:'fa-music',query:'jazz music',color:'#9333ea'},
  {id:'classical',title:'Classical Music',icon:'fa-music',query:'classical music',color:'#0d9488'},
  {id:'fusion',title:'Fusion Flavors',icon:'fa-blender-phone',query:'fusion indian music',color:'#facc15'},
  {id:'instrumental',title:'Instrumental Peace',icon:'fa-drum-steelpan',query:'instrumental music',color:'#4ade80'},
  {id:'retro',title:'Retro Classics',icon:'fa-record-vinyl',query:'old classic songs',color:'#f97316'},
  {id:'indie',title:'Indie Discoveries',icon:'fa-guitar',query:'indie pop songs',color:'#06b6d4'},
  {id:'workout',title:'Workout Motivation',icon:'fa-dumbbell',query:'workout motivation songs',color:'#ef4444'},
  {id:'happy',title:'Happy Mood',icon:'fa-smile',query:'happy songs',color:'#facc15'},
  {id:'chill',title:'Chill Vibes',icon:'fa-cloud',query:'chill songs',color:'#60a5fa'},
  {id:'focus',title:'Focus Music',icon:'fa-brain',query:'focus music',color:'#34d399'},
  {id:'study',title:'Study Playlist',icon:'fa-book',query:'study music',color:'#818cf8'},
  {id:'lofi',title:'LoFi Beats',icon:'fa-headphones',query:'lofi hip hop',color:'#a78bfa'},
  {id:'sleep',title:'Sleep & Relax',icon:'fa-bed',query:'sleep meditation music',color:'#3b82f6'},
  {id:'travel',title:'Travel Playlist',icon:'fa-road',query:'travel road trip songs',color:'#94a3b8'}
];
// ─── Home Loading ─────────────────────────────────────────────
async function loadHome(lang) {
  homeLang = lang||''; categoriesLoadIndex = 0; isLoadingMoreCategories = false;
  document.getElementById('home-sections').innerHTML = '<div class="sh"><div class="sh-t"><i class="fas fa-fire" style="color:#ff6b6b;margin-right:8px"></i>Trending Songs</div><div class="sh-a" onclick="openSeeAll(\'trending-songs\',\'Trending Songs\',\'song\')">See all</div></div><div class="hrow" id="row-trending-songs"><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div></div><div class="sh"><div class="sh-t"><i class="fas fa-compact-disc" style="color:#4ecdc4;margin-right:8px"></i>New Albums</div><div class="sh-a" onclick="openSeeAll(\'trending-albums\',\'New Albums\',\'album\')">See all</div></div><div class="hrow" id="row-new-albums"><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div></div><div class="sh"><div class="sh-t"><i class="fas fa-list-music" style="color:#9966ff;margin-right:8px"></i>Trending Playlists</div><div class="sh-a" onclick="openSeeAll(\'trending-playlists\',\'Trending Playlists\',\'playlist\')">See all</div></div><div class="hrow" id="row-trending-playlists"><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div></div><div class="sh"><div class="sh-t"><i class="fas fa-microphone" style="color:#a855f7;margin-right:8px"></i>Trending Artists</div><div class="sh-a" onclick="openSeeAll(\'trending-artists\',\'Trending Artists\',\'artist\')">See all</div></div><div class="hrow" id="row-trending-artists"><div class="ac sk" style="width:90px;height:120px;background-color:var(--bg2);border-radius:12px;"></div><div class="ac sk" style="width:90px;height:120px;background-color:var(--bg2);border-radius:12px;"></div><div class="ac sk" style="width:90px;height:120px;background-color:var(--bg2);border-radius:12px;"></div><div class="ac sk" style="width:90px;height:120px;background-color:var(--bg2);border-radius:12px;"></div></div><div class="sh"><div class="sh-t"><i class="fas fa-user-friends" style="color:#4fc3f7;margin-right:8px"></i>Artist Recommendations</div><div class="sh-a" onclick="openSeeAll(\'artist-recommendations\',\'Artist Recommendations\',\'artist\')">See all</div></div><div class="hrow" id="row-artist-recommendations"><div class="ac sk" style="width:90px;height:120px;background-color:var(--bg2);border-radius:12px;"></div><div class="ac sk" style="width:90px;height:120px;background-color:var(--bg2);border-radius:12px;"></div><div class="ac sk" style="width:90px;height:120px;background-color:var(--bg2);border-radius:12px;"></div><div class="ac sk" style="width:90px;height:120px;background-color:var(--bg2);border-radius:12px;"></div></div><div id="categories-container"></div><div id="home-scroll-trigger" style="text-align:center;padding:20px;color:var(--t2);display:none"><i class="fas fa-spinner fa-pulse fa-lg"></i></div>';
  await Promise.all([loadTrendingSongsSection(homeLang),loadNewAlbumsSection(homeLang),loadTrendingPlaylistsSection(homeLang),loadTrendingArtistsSection(homeLang),loadArtistRecommendationsSection(homeLang)]);
  var cc = document.getElementById('categories-container');
  var fc = homeLang ? CATEGORY_CONFIG.filter(function(c){return c.id.includes(homeLang)||!c.id.match(/(hindi|english|punjabi|tamil|telugu|bengali|marathi|kannada|gujarati|korean)/);}) : CATEGORY_CONFIG;
  await loadNextCategoryBatch(fc, cc);
  document.getElementById('home-scroll-trigger').style.display = 'block';
  setupHomeInfiniteScroll(fc, cc);
}

async function loadTrendingSongsSection(lang) {
  var c = document.getElementById('row-trending-songs'); c.innerHTML = '';
  var s = lang ? await apiSongs(lang+' trending',1,10) : await apiTrendingSongs(1,10);
  if (!s||!s.length) { c.innerHTML = '<div style="padding:10px;text-align:center;color:var(--t2);width:100%;">No trending songs found.</div>'; return; }
  renderCardRow(c, s);
}
async function loadNewAlbumsSection(lang) {
  var c = document.getElementById('row-new-albums'); c.innerHTML = '';
  var a = lang ? (await apiGlobalSearch(lang+' new albums')).albums : await apiTrendingAlbums(1,10);
  if (!a||!a.length) { c.innerHTML = '<div style="padding:10px;text-align:center;color:var(--t2);width:100%;">No new albums found.</div>'; return; }
  a.forEach(function(al){c.appendChild(makeAlbumCard(al));});
}
async function loadTrendingPlaylistsSection(lang) {
  var c = document.getElementById('row-trending-playlists'); c.innerHTML = '';
  var p = lang ? (await apiGlobalSearch(lang+' playlists')).playlists : await apiTrendingPlaylists(1,10);
  if (!p||!p.length) { c.innerHTML = '<div style="padding:10px;text-align:center;color:var(--t2);width:100%;">No trending playlists found.</div>'; return; }
  p.forEach(function(pl){c.appendChild(makePlaylistCard(pl));});
}
async function loadTrendingArtistsSection(lang) {
  var c = document.getElementById('row-trending-artists'); c.innerHTML = '';
  var a = lang ? (await apiGlobalSearch(lang+' popular artists')).artists : await apiTrendingArtists(1,10);
  if (!a||!a.length) { c.innerHTML = '<div style="padding:10px;text-align:center;color:var(--t2);width:100%;">No trending artists found.</div>'; return; }
  a.forEach(function(ar){c.appendChild(makeArtistCard(ar));});
}
async function loadArtistRecommendationsSection(lang) {
  var c = document.getElementById('row-artist-recommendations'); c.innerHTML = '';
  var a = lang ? (await apiGlobalSearch(lang+' artists')).artists : await apiHomeArtistRecommendations(1,10);
  if (!a||!a.length) { c.innerHTML = '<div style="padding:10px;text-align:center;color:var(--t2);width:100%;">No artist recommendations found.</div>'; return; }
  a.forEach(function(ar){c.appendChild(makeArtistCard(ar));});
}
function createCategorySection(cat) {
  var s = document.createElement('div'); s.className = 'category-section'; s.dataset.categoryId = cat.id;
  s.innerHTML = '<div class="sh"><div class="sh-t"><i class="fas '+cat.icon+'" style="color:'+cat.color+';margin-right:8px"></i>'+cat.title+'</div><div class="sh-a" onclick="openSeeAll(\'category-'+cat.id+'\',\''+cat.title+'\',\'song\',\''+cat.query+'\')">See all</div></div><div class="hrow"><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div></div>';
  return s;
}
async function loadCategorySongs(cat, container) {
  var s = await apiSongs(cat.query+(homeLang?' '+homeLang:''),1,10);
  container.innerHTML = '';
  if (!s.length) { container.innerHTML = '<div style="padding:10px;text-align:center;color:var(--t2);width:100%;">No songs found.</div>'; return; }
  renderCardRow(container, s);
}
async function loadNextCategoryBatch(fc, cc) {
  if (isLoadingMoreCategories) return; isLoadingMoreCategories = true;
  var end = Math.min(categoriesLoadIndex+CATEGORIES_BATCH_SIZE, fc.length);
  var batch = fc.slice(categoriesLoadIndex, end);
  await Promise.all(batch.map(async function(cat) { var s=createCategorySection(cat); cc.appendChild(s); return loadCategorySongs(cat, s.querySelector('.hrow')); }));
  categoriesLoadIndex = end; isLoadingMoreCategories = false;
  if (categoriesLoadIndex>=fc.length) document.getElementById('home-scroll-trigger').innerHTML = '<div style="text-align:center;padding:20px;color:var(--t2);font-size:13px">\u2014 You\'ve reached the end \u2014</div>';
}
function setupHomeInfiniteScroll(fc, cc) {
  var t = document.getElementById('home-scroll-trigger'); if (!t) return;
  if (homeScrollObserver) homeScrollObserver.disconnect();
  homeScrollObserver = new IntersectionObserver(function(entries) { entries.forEach(function(e) { if (e.isIntersecting && !isLoadingMoreCategories && categoriesLoadIndex<fc.length) loadNextCategoryBatch(fc,cc); }); },{rootMargin:'50px'});
  homeScrollObserver.observe(t);
}
function setLang(el, lang) { document.querySelectorAll('#lang-pills .pill').forEach(function(p){p.classList.remove('on');}); el.classList.add('on'); loadHome(lang); }

// ─── Renderers ────────────────────────────────────────────────
function renderCardRow(container, songs) { songs.forEach(function(s,i){container.appendChild(makeSongCard(s,songs,i));}); }

function makeSongCard(song, queue, idx) {
  var d = document.createElement('div'); d.className = 'sc'; var lk = isLiked(song);
  d.innerHTML = '<div class="sc-iw"><img class="sc-img" src="'+APP_LOGO+'" data-src="'+getImg(song)+'" alt="'+esc(song.name)+'" loading="lazy" onerror="this.src=APP_LOGO"/><div class="sc-ov"><div class="sc-pb"><i class="fas fa-play"></i></div></div></div><div class="sc-hrt '+(lk?'on':'')+'" onclick="event.stopPropagation();cardHrt(this,event)"><i class="fas fa-heart"></i></div><div class="sc-info"><div class="sc-name">'+esc(song.name)+'</div><div class="sc-art">'+esc(getArtist(song))+'</div></div>';
  d._song = song; d.querySelector('.sc-hrt')._song = song; d.onclick = function(){startPlay(song,queue,idx);};
  initImgs(d); return d;
}
function makeAlbumCard(album) {
  var d = document.createElement('div'); d.className = 'sc';
  d.innerHTML = '<div class="sc-iw"><img class="sc-img" src="'+APP_LOGO+'" data-src="'+getImg(album)+'" alt="'+esc(album.name)+'" loading="lazy" onerror="this.src=APP_LOGO"/><div class="sc-ov"><div class="sc-pb"><i class="fas fa-compact-disc"></i></div></div></div><div class="sc-info"><div class="sc-name">'+esc(album.name)+'</div><div class="sc-art">'+esc(getAlbumArtists(album))+'</div></div>';
  d._album = album; d.onclick = function(){goScreen('album-detail',album.id);}; initImgs(d); return d;
}
function makeArtistCard(artist) {
  var d = document.createElement('div'); d.className = 'ac';
  d.innerHTML = '<img class="ac-img" src="'+APP_LOGO+'" data-src="'+getArtistImg(artist)+'" alt="'+esc(artist.name)+'" loading="lazy" onerror="this.src=APP_LOGO"/><div class="ac-name">'+esc(artist.name)+'</div>';
  d._artist = artist; d.onclick = function(){goScreen('artist-detail',artist.id);}; initImgs(d); return d;
}
function makePlaylistCard(pl) {
  var d = document.createElement('div'); d.className = 'sc';
  d.innerHTML = '<div class="sc-iw"><img class="sc-img" src="'+APP_LOGO+'" data-src="'+getImg(pl)+'" alt="'+esc(pl.name)+'" loading="lazy" onerror="this.src=APP_LOGO"/><div class="sc-ov"><div class="sc-pb"><i class="fas fa-list-music"></i></div></div></div><div class="sc-info"><div class="sc-name">'+esc(pl.name)+'</div><div class="sc-art">'+(pl.songCount?pl.songCount+' songs':'Playlist')+'</div></div>';
  initImgs(d); d.onclick = function() { apiPlaylistById(pl.id).then(function(fp){ if(fp){ currentPlaylist = {id:fp.id,name:fp.name,songs:fp.songs||[]}; goScreen('playlist-detail',pl.id); } else showToast('Could not load playlist.'); }).catch(function(e){console.error(e);showToast('Failed.');}); }; return d;
}
function cardHrt(el, e) { e.stopPropagation(); doLike(el._song, el); }

function makeRow(item, queue, idx, showNum, type, contextId) {
  var d = document.createElement('div'); d.className = 'sr'; var lk = type==='song'?isLiked(item):false;
  var cur = curSong && curSong.id===item.id;
  var numH = showNum ? '<div class="sr-num">'+(cur?'<div class="pi"><div class="pi-b"></div><div class="pi-b"></div><div class="pi-b"></div></div>':(idx+1))+'</div>' : '';
  var iUrl='', tTxt='', aTxt='', clickH=function(){}, ctxH='';
  if (type==='song') { iUrl=getImg(item); tTxt=esc(item.title||item.name); aTxt=esc(getArtist(item)); clickH=function(){startPlay(item,queue,idx);}; ctxH='event.stopPropagation();ctxSong(this._item,'+(contextId?"'"+contextId+"'":"''")+')'; }
  else if (type==='album') { iUrl=getImg(item); tTxt=esc(item.title||item.name); aTxt=esc(getAlbumArtists(item)); clickH=function(){goScreen('album-detail',item.id);}; ctxH='event.stopPropagation();showToast(\'No options for albums here.\')'; }
  else if (type==='artist') { iUrl=getArtistImg(item); tTxt=esc(item.title||item.name); aTxt=item.description||(item.followerCount?item.followerCount+' followers':'Artist'); clickH=function(){goScreen('artist-detail',item.id);}; ctxH='event.stopPropagation();showToast(\'No options for artists here.\')'; }
  else if (type==='playlist') { iUrl=getImg(item); tTxt=esc(item.title||item.name); aTxt=item.description||(item.songCount?item.songCount+' songs':'Playlist'); clickH=function(){apiPlaylistById(item.id).then(function(fp){if(fp){currentPlaylist={id:fp.id,name:fp.name,songs:fp.songs||[]};goScreen('playlist-detail',item.id);}else showToast('Could not load.');}).catch(function(e){console.error(e);showToast('Failed.');});}; ctxH='event.stopPropagation();showToast(\'No options for playlists here.\')'; }
  d.innerHTML = numH+'<img class="sr-img" src="'+APP_LOGO+'" data-src="'+iUrl+'" alt="'+tTxt+'" loading="lazy" onerror="this.src=APP_LOGO"/><div class="sr-info"><div class="sr-name" style="'+(cur?'color:var(--acc)':'')+'">'+tTxt+'</div><div class="sr-art">'+aTxt+'</div></div><div class="sr-acts">'+(type==='song'&&item.duration?'<span class="sr-dur">'+fmtDur(item.duration)+'</span>':'')+(type==='song'?'<div class="sr-h '+(lk?'on':'')+'" onclick="event.stopPropagation();rowHrt(this)"><i class="fas fa-heart"></i></div>':'')+'<div class="sr-m" onclick="'+ctxH+'"><i class="fas fa-ellipsis-v"></i></div></div>';
  d._item = item; if (type==='song') { d._song=item; d.querySelector('.sr-h')._song=item; }
  d.onclick = clickH; initImgs(d); return d;
}

function rowHrt(el) { doLike(el._song, el); }

function makeMediaCard(item, type) {
  var d = document.createElement('div'); d.className = 'media-card'; var i='', t='', s='', ch=function(){};
  if (type==='song') { i=getImg(item); t=esc(item.title||item.name); s=esc(getArtist(item)); ch=async function(){var u=getDl(item);if(u){startPlay(item,[item],0);}else{showToast('Loading...');try{var f=await apiGetSongById(item.id);if(f&&f.downloadUrl&&f.downloadUrl.length)startPlay(f,[f],0);else showToast('Unavailable.');}catch(e){showToast('Failed.');}}}; }
  else if (type==='album') { i=getImg(item); t=esc(item.title||item.name); s=esc(getAlbumArtists(item)); ch=function(){goScreen('album-detail',item.id);}; }
  else if (type==='artist') { i=getArtistImg(item); t=esc(item.title||item.name); s=item.description||(item.followerCount?item.followerCount+' followers':'Artist'); ch=function(){goScreen('artist-detail',item.id);}; }
  else if (type==='playlist') { i=getImg(item); t=esc(item.title||item.name); s=item.description||(item.songCount?item.songCount+' songs':'Playlist'); ch=function(){apiPlaylistById(item.id).then(function(fp){if(fp){currentPlaylist={id:fp.id,name:fp.name,songs:fp.songs||[]};goScreen('playlist-detail',item.id);}else showToast('Could not load.');}).catch(function(e){console.error(e);showToast('Failed.');});}; }
  d.innerHTML = '<img class="media-card-img" src="'+APP_LOGO+'" data-src="'+i+'" alt="'+t+'" loading="lazy" onerror="this.src=APP_LOGO"/><div class="media-card-info"><div class="media-card-title">'+t+'</div><div class="media-card-subtitle">'+s+'</div></div><div class="media-card-type">'+type+'</div>';
  d.onclick = ch; initImgs(d); return d;
}

// ─── See All ──────────────────────────────────────────────────
async function openSeeAll(type, title, itemType, query) {
  saType=type; saTitle2=title; saItemType=itemType; saQuery=query; saPage=1; saItems=[];
  document.getElementById('sa-title').textContent=title; document.getElementById('sa-list').innerHTML=''; document.getElementById('sa-loading').style.display='block';
  goScreen('seeall'); await fetchSAPage();
}
function closeSeeAll() { goScreen('home'); saType=''; saQuery=''; saItems=[]; saItemType='song'; }
async function fetchSAPage() {
  if (saLoading) return; saLoading=true; var newItems=[]; const limit=25; var ls=homeLang?' '+homeLang:'';
  try {
    if (saType==='trending-songs') newItems=await apiTrendingSongs(saPage,limit);
    else if (saType==='trending-albums') newItems=await apiTrendingAlbums(saPage,limit);
    else if (saType==='trending-playlists') newItems=await apiTrendingPlaylists(saPage,limit);
    else if (saType==='trending-artists') newItems=await apiTrendingArtists(saPage,limit);
    else if (saType==='artist-recommendations') newItems=await apiHomeArtistRecommendations(saPage,limit);
    else if (saType.startsWith('category-')) newItems=await apiSongs(saQuery+ls,saPage,limit);
    else if (saType==='artist-songs') newItems=await apiArtistSongs(saQuery,saPage-1,limit);
    else if (saType==='artist-albums') newItems=await apiArtistAlbums(saQuery,saPage-1,limit);
    else if (saType==='search-songs') { var d=await callApi('/api/search/songs',{query:saQuery,page:saPage-1,limit:limit}); newItems=(d&&d.results)||[]; }
    else if (saType==='search-albums') { var d=await callApi('/api/search/albums',{query:saQuery,page:saPage-1,limit:limit}); newItems=(d&&d.results)||[]; }
    else if (saType==='search-artists') { var d=await callApi('/api/search/artists',{query:saQuery,page:saPage-1,limit:limit}); newItems=(d&&d.results)||[]; }
    else if (saType==='search-playlists') { var d=await callApi('/api/search/playlists',{query:saQuery,page:saPage-1,limit:limit}); newItems=(d&&d.results)||[]; }
  } catch(e) { console.error('fetchSAPage error:',e); showToast('Failed.'); newItems=[]; }
  var el=document.getElementById('sa-list');
  if (!newItems.length && saPage===1) { el.innerHTML='<div class="empty"><i class="fas fa-music"></i>No items found.</div>'; document.getElementById('sa-loading').style.display='none'; saLoading=false; return; }
  if (!newItems.length && saPage>1) { document.getElementById('sa-loading').innerHTML='<div style="text-align:center;padding:20px;color:var(--t2);font-size:13px">\u2014 That\'s all \u2014</div>'; document.getElementById('sa-loading').style.display='block'; saLoading=false; return; }
  newItems.forEach(function(item){saItems.push(item);el.appendChild(makeRow(item,saItems,saItems.length-1,true,saItemType));});
  saLoading=false; document.getElementById('sa-loading').style.display=newItems.length>=limit?'block':'none';
}
async function loadMoreSeeAll() { if (saLoading||!saType) return; saPage++; await fetchSAPage(); }

// ─── Search ───────────────────────────────────────────────────
let searchTimeout = null;
function onQ(v) {
  document.getElementById('q-clr').style.display=v?'block':'none'; clearTimeout(searchTimeout);
  if (!v.trim()) { document.getElementById('browse-view').style.display='block'; document.getElementById('results-view').style.display='none'; srchQ=''; return; }
  searchTimeout=setTimeout(function(){doSearch(v);},400);
}
async function doSearch(q) {
  srchQ=q; srchResults={songs:[],albums:[],artists:[],playlists:[]}; srchLoading=true;
  document.getElementById('browse-view').style.display='none'; document.getElementById('results-view').style.display='block';
  document.getElementById('res-lbl').textContent='Results for "'+esc(q)+'"';
  document.getElementById('res-list').innerHTML='<div style="padding:24px;text-align:center;color:var(--t2)"><i class="fas fa-spinner fa-spin fa-lg"></i> Searching...</div>';
  var results=await apiGlobalSearch(q);
  try { var ms=await callApi('/api/search/songs',{query:q,page:0,limit:50}); if(ms&&ms.results&&ms.results.length){var eIds=new Set(results.songs.map(function(s){return s.id;}));ms.results.forEach(function(s){if(!eIds.has(s.id)){results.songs.push(s);eIds.add(s.id);}});} } catch(e){}
  var el=document.getElementById('res-list'); el.innerHTML='';
  if (!results||Object.values(results).every(function(a){return a.length===0;})) { el.innerHTML='<div class="empty"><i class="fas fa-search"></i>No results for "'+esc(q)+'"</div>'; srchLoading=false; return; }
  srchResults=results;
  if (results.songs.length) { var ss=document.createElement('div'); ss.className='search-section'; ss.innerHTML='<div class="sh"><div class="sh-t">Songs</div><div class="sh-a" onclick=\'openSeeAll("search-songs","Songs for '+esc(q)+'","song","'+esc(q)+'")\'>See all</div></div>'; var sm=document.createElement('div'); sm.className='mcards'; results.songs.forEach(function(s){sm.appendChild(makeMediaCard(s,'song'));}); ss.appendChild(sm); el.appendChild(ss); }
  if (results.albums.length) { var as=document.createElement('div'); as.className='search-section'; as.innerHTML='<div class="sh"><div class="sh-t">Albums</div><div class="sh-a" onclick=\'openSeeAll("search-albums","Albums for '+esc(q)+'","album","'+esc(q)+'")\'>See all</div></div>'; results.albums.forEach(function(a){as.appendChild(makeMediaCard(a,'album'));}); el.appendChild(as); }
  if (results.artists.length) { var ars=document.createElement('div'); ars.className='search-section'; ars.innerHTML='<div class="sh"><div class="sh-t">Artists</div><div class="sh-a" onclick=\'openSeeAll("search-artists","Artists for '+esc(q)+'","artist","'+esc(q)+'")\'>See all</div></div>'; results.artists.forEach(function(a){ars.appendChild(makeMediaCard(a,'artist'));}); el.appendChild(ars); }
  if (results.playlists.length) { var ps=document.createElement('div'); ps.className='search-section'; ps.innerHTML='<div class="sh"><div class="sh-t">Playlists</div><div class="sh-a" onclick=\'openSeeAll("search-playlists","Playlists for '+esc(q)+'","playlist","'+esc(q)+'")\'>See all</div></div>'; results.playlists.forEach(function(p){ps.appendChild(makeMediaCard(p,'playlist'));}); el.appendChild(ps); }
  srchLoading=false;
}
function clearQ() { document.getElementById('q-inp').value=''; document.getElementById('q-clr').style.display='none'; document.getElementById('browse-view').style.display='block'; document.getElementById('results-view').style.display='none'; srchQ=''; }
function qG(g) { document.getElementById('q-inp').value=g; onQ(g); goScreen('search'); }

// ─── Artist Detail ────────────────────────────────────────────
async function loadArtistDetail(id) {
  document.getElementById('artist-detail-top-title').textContent='Loading...'; document.getElementById('artist-detail-img').src=APP_LOGO; document.getElementById('artist-detail-name').textContent=''; document.getElementById('artist-detail-followers').textContent='';
  document.getElementById('artist-top-songs-list').innerHTML='<div class="sr sk" style="height:70px;margin:8px;"></div><div class="sr sk" style="height:70px;margin:8px;"></div>';
  document.getElementById('artist-albums-list').innerHTML='<div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div><div class="sc sk" style="width:140px;height:200px;background-color:var(--bg2);"></div>';
  document.getElementById('artist-top-songs-see-all').style.display='none'; document.getElementById('artist-albums-see-all').style.display='none';
  var ad=await apiArtistById(id); if (!ad) { showToast('Artist not found.'); goScreen('search'); return; }
  currentArtist=ad; document.getElementById('artist-detail-top-title').textContent=esc(ad.name); smartImg(document.getElementById('artist-detail-img'),getArtistImg(ad));
  document.getElementById('artist-detail-name').textContent=esc(ad.name); document.getElementById('artist-detail-followers').textContent=ad.followerCount?ad.followerCount+' followers':'';
  var tsl=document.getElementById('artist-top-songs-list'); tsl.innerHTML='';
  if (ad.topSongs&&ad.topSongs.length){ad.topSongs.forEach(function(s,i){tsl.appendChild(makeRow(s,ad.topSongs,i,true,'song'));}); document.getElementById('artist-top-songs-see-all').style.display='block'; document.getElementById('artist-top-songs-see-all').onclick=function(){openSeeAll('artist-songs',ad.name+"'s Top Songs",'song',ad.id);};}
  else tsl.innerHTML='<div class="empty" style="padding:10px;">No top songs found.</div>';
  var al=document.getElementById('artist-albums-list'); al.innerHTML='';
  if (ad.topAlbums&&ad.topAlbums.length){ad.topAlbums.forEach(function(a){al.appendChild(makeAlbumCard(a));}); document.getElementById('artist-albums-see-all').style.display='block'; document.getElementById('artist-albums-see-all').onclick=function(){openSeeAll('artist-albums',ad.name+"'s Albums",'album',ad.id);};}
  else al.innerHTML='<div class="empty" style="padding:10px; width:100%;">No albums found.</div>';
}

// ─── Album Detail ─────────────────────────────────────────────
async function loadAlbumDetail(id) {
  document.getElementById('album-detail-top-title').textContent='Loading...'; var ah=document.getElementById('album-detail-header'); ah.style.backgroundImage='none'; ah.style.backgroundColor='var(--bg3)';
  document.getElementById('album-detail-img').src=APP_LOGO; document.getElementById('album-detail-title').textContent=''; document.getElementById('album-detail-artist').textContent=''; document.getElementById('album-detail-year').textContent='';
  document.getElementById('album-detail-songs-list').innerHTML='<div class="sr sk" style="height:70px;margin:8px;"></div><div class="sr sk" style="height:70px;margin:8px;"></div>'; document.getElementById('album-detail-empty').style.display='none'; document.getElementById('album-detail-play-all-btn').style.display='none';
  var ad=await apiAlbumById(id); if (!ad) { showToast('Album not found.'); goScreen('search'); return; }
  currentAlbum=ad; document.getElementById('album-detail-top-title').textContent=esc(ad.name);
  ah.style.backgroundImage="url('"+getImg(ad)+"')"; ah.style.backgroundColor='transparent'; smartImg(document.getElementById('album-detail-img'),getImg(ad));
  document.getElementById('album-detail-title').textContent=esc(ad.name); document.getElementById('album-detail-artist').textContent=esc(getAlbumArtists(ad)); document.getElementById('album-detail-year').textContent=ad.year?'Released: '+ad.year:'';
  var sl=document.getElementById('album-detail-songs-list'); sl.innerHTML='';
  if (ad.songs&&ad.songs.length){ad.songs.forEach(function(s,i){sl.appendChild(makeRow(s,ad.songs,i,true,'song'));}); document.getElementById('album-detail-play-all-btn').style.display='flex'; document.getElementById('album-detail-play-all-btn').onclick=function(){startPlay(ad.songs[0],ad.songs,0);};}
  else document.getElementById('album-detail-empty').style.display='block';
}

// ─── Like ─────────────────────────────────────────────────────
function doLike(song, el) {
  if (!song) return; var liked=S.liked(); var i=liked.findIndex(function(s){return s.id===song.id;});
  if (i>-1) { liked.splice(i,1); if(el) el.classList.remove('on'); showToast('Removed from Liked'); }
  else { liked.unshift(song); if(el){el.classList.add('on');el.classList.add('hpop');setTimeout(function(){el.classList.remove('hpop');},350);} showToast('Added to Liked'); }
  S.s('liked',liked); updateLikeUI(); updateStats();
  if (document.getElementById('lib-lk').style.display==='block') renderLiked();
}
function toggleLikeCur() { if (curSong) doLike(curSong, null); }
function updateLikeUI() { if (!curSong) return; var lk=isLiked(curSong); document.getElementById('pb-hrt').classList.toggle('on',lk); document.getElementById('fp-hrt').classList.toggle('on',lk); }

// ─── Player Engine ────────────────────────────────────────────
function startPlay(song, queue, idx) {
  curSong=song; curQueue=queue; curIdx=idx; var i=getImg(song), n=song.name||'\u2014', a=getArtist(song), u=getDl(song);
  ['pb-thumb','fp-img'].forEach(function(id){var el=document.getElementById(id);if(el) smartImg(el,i);});
  document.getElementById('pb-title').textContent=n; document.getElementById('pb-art').textContent=a;
  document.getElementById('fp-title').textContent=n; document.getElementById('fp-artist').textContent=a;
  document.getElementById('pbar').classList.add('on'); document.getElementById('mo-opts-t').textContent=n; updateLikeUI();
  var rc=S.recent().filter(function(s){return s.id!==song.id;}); rc.unshift(song); S.s('recent',rc.slice(0,50));
  if (u) { aud.src=u; aud.play().then(function(){setPlaying(true);}).catch(function(e){console.error(e);setPlaying(true);showToast('Playback failed.');}); }
  else { setPlaying(true); showToast('Audio unavailable.'); }
}
function setPlaying(v) {
  playing=v; var ic=v?'fa-pause':'fa-play'; document.getElementById('pb-pi').className='fas '+ic; document.getElementById('fp-pi').className='fas '+ic;
  var cs=document.querySelector('.scr.on');
  if (cs) { if (cs.id==='scr-seeall'&&saItemType==='song') fetchSAPage(); if (cs.id==='scr-search'&&srchQ) doSearch(srchQ); if (cs.id==='scr-library') renderLib(); if (cs.id==='scr-playlist-detail'&&currentPlaylist) loadPlaylistDetail(currentPlaylist.id); if (cs.id==='scr-artist-detail'&&currentArtist) loadArtistDetail(currentArtist.id); if (cs.id==='scr-album-detail'&&currentAlbum) loadAlbumDetail(currentAlbum.id); }
}
function togglePlay() { if (!curSong) { showToast('No song selected!'); return; } playing?aud.pause():aud.play().catch(function(e){console.error(e);showToast('Playback error.');}); }
function nextSong() { if (!curQueue.length) return; curIdx=shuffle?Math.floor(Math.random()*curQueue.length):(curIdx+1)%curQueue.length; startPlay(curQueue[curIdx],curQueue,curIdx); }
function prevSong() { if (!curQueue.length) return; if (aud.currentTime>3){aud.currentTime=0;return;} curIdx=(curIdx-1+curQueue.length)%curQueue.length; startPlay(curQueue[curIdx],curQueue,curIdx); }
function toggleShuffle() { shuffle=!shuffle; document.getElementById('fp-shuf').classList.toggle('on',shuffle); showToast(shuffle?'Shuffle On':'Shuffle Off'); }
function toggleRepeat() { repeat=!repeat; aud.loop=repeat; document.getElementById('fp-rep').classList.toggle('on',repeat); showToast(repeat?'Repeat On':'Repeat Off'); }
function setVol(v) { aud.volume=v/100; }

// ─── Audio ────────────────────────────────────────────────────
function setupAudio() {
  var rafRunning=false;
  function updateProgress() { if(!aud.duration){rafRunning=false;return;} var p=(aud.currentTime/aud.duration)*100; document.getElementById('pb-fill').style.width=p+'%'; document.getElementById('fp-fill').style.width=p+'%'; document.getElementById('fp-knob').style.left=p+'%'; document.getElementById('fp-cur').textContent=fmtT(aud.currentTime); document.getElementById('fp-dur').textContent=fmtT(aud.duration); rafRunning=false; }
  function scheduleRAF() { if(!rafRunning){rafRunning=true;requestAnimationFrame(updateProgress);} }
  aud.addEventListener('timeupdate',scheduleRAF,{passive:true});
  aud.addEventListener('ended',function(){if(!repeat)nextSong();else aud.play();});
  aud.addEventListener('play',function(){setPlaying(true);});
  aud.addEventListener('pause',function(){setPlaying(false);});
  aud.volume=S.g('volume')||0.8; document.getElementById('vol-sl').value=aud.volume*100;
  document.getElementById('vol-sl').addEventListener('change',function(e){S.s('volume',e.target.value/100);});
}
function miniSeek(e) { if (!aud.duration) return; var r=e.currentTarget.getBoundingClientRect(); aud.currentTime=((e.clientX-r.left)/r.width)*aud.duration; }
function fullSeek(e) { if (!aud.duration) return; var r=document.getElementById('fp-track').getBoundingClientRect(); aud.currentTime=Math.max(0,Math.min(1,(e.clientX-r.left)/r.width))*aud.duration; }

function setupDraggableSeek() {
  var pb=document.querySelector('.pb-prog'), ft=document.querySelector('.fp-track'); if(!pb||!ft) return;
  function startDrag(e,tr) { e.preventDefault(); function onMove(ev){var cx=ev.touches?ev.touches[0].clientX:ev.clientX,rt=tr.getBoundingClientRect(),pct=Math.max(0,Math.min(1,(cx-rt.left)/rt.width));if(aud.duration)aud.currentTime=pct*aud.duration;} function onEnd(){document.removeEventListener('mousemove',onMove);document.removeEventListener('mouseup',onEnd);document.removeEventListener('touchmove',onMove);document.removeEventListener('touchend',onEnd);if(pb)pb.classList.remove('dragging');} document.addEventListener('mousemove',onMove,{passive:true}); document.addEventListener('mouseup',onEnd); document.addEventListener('touchmove',onMove,{passive:true}); document.addEventListener('touchend',onEnd); if(pb)pb.classList.add('dragging'); }
  pb.addEventListener('mousedown',function(e){startDrag(e,pb);}); pb.addEventListener('touchstart',function(e){startDrag(e,pb);},{passive:true});
  ft.addEventListener('mousedown',function(e){startDrag(e,ft);}); ft.addEventListener('touchstart',function(e){startDrag(e,ft);},{passive:true});
}

// ─── Full Player ──────────────────────────────────────────────
function openFP() { if (curSong) document.getElementById('fp').classList.add('on'); else showToast('No song playing.'); }
function closeFP() { document.getElementById('fp').classList.remove('on'); }

// ─── Library ─────────────────────────────────────────────────
function libTab(t) { ['pl','lk','rc','dl'].forEach(function(x){document.getElementById('lib-'+x).style.display=x===t?'block':'none';document.getElementById('lt-'+x).classList.toggle('on',x===t);}); if(t==='pl')renderPlaylists(); if(t==='lk')renderLiked(); if(t==='rc')renderRecent(); if(t==='dl')renderDownloads(); }
function renderLib(t) { libTab(t||'pl'); }
function renderPlaylists() {
  var el=document.getElementById('pl-list'); el.innerHTML=''; if (!userPlaylistsCache.length) { el.innerHTML='<div class="empty"><i class="fas fa-music"></i>No playlists yet.<br>Create one!</div>'; return; }
  var GR=['linear-gradient(135deg,#00ff88,#00cc6a)','linear-gradient(135deg,#00ccff,#0099ff)','linear-gradient(135deg,#9966ff,#6600cc)','linear-gradient(135deg,#ff6699,#ff3366)','linear-gradient(135deg,#ffcc00,#ff9900)'];
  userPlaylistsCache.forEach(function(pl,i) {
    var d=document.createElement('div'); d.className='plc'; var li=pl.songs&&pl.songs.length>0?getImg(pl.songs[pl.songs.length-1]):'';
    d.innerHTML='<div class="pl-th" style="background-image:url(\''+li+'\'); background-color:'+GR[i%GR.length]+'">'+(li?'':'<i class="fas fa-music"></i>')+'</div><div style="flex:1;min-width:0"><div class="pl-name">'+esc(pl.name)+'</div><div class="pl-meta">'+(pl.songs?pl.songs.length:0)+' songs</div></div><button style="font-size:14px;color:var(--t2);padding:8px" onclick="event.stopPropagation();ctxPlaylist(this)" data-playlist-id="'+pl.id+'"><i class="fas fa-ellipsis-v"></i></button>';
    d._playlist=pl; d.onclick=function(){goScreen('playlist-detail',pl.id);}; el.appendChild(d);
  });
}
async function loadPlaylistDetail(id) {
  var pl=userPlaylistsCache.find(function(p){return p.id===id;}); if(!pl){showToast('Not found!');goScreen('library');return;} currentPlaylist=pl;
  document.getElementById('pd-top-title').textContent=esc(pl.name); document.getElementById('pd-title').textContent=esc(pl.name); document.getElementById('pd-songs-count').textContent=pl.songs.length+' songs'; document.getElementById('pd-owner').textContent=currentUser?currentUser.name:'You';
  var pd=document.getElementById('pd-list'); pd.innerHTML=''; document.getElementById('pd-empty').style.display='none';
  if (!pl.songs.length) { document.getElementById('pd-empty').style.display='block'; document.getElementById('pd-play-all-btn').style.display='none'; document.getElementById('pd-banner').style.backgroundImage='none'; document.getElementById('pd-banner').style.backgroundColor='var(--bg3)'; }
  else { document.getElementById('pd-play-all-btn').style.display='flex'; document.getElementById('pd-play-all-btn').onclick=function(){startPlay(pl.songs[0],pl.songs,0);}; var ls=pl.songs[pl.songs.length-1]; document.getElementById('pd-banner').style.backgroundImage="url('"+getImg(ls)+"')"; document.getElementById('pd-banner').style.backgroundColor='transparent'; pl.songs.forEach(function(s,i){pd.appendChild(makeRow(s,pl.songs,i,true,'song',pl.id));}); }
}
function renderLiked() { var el=document.getElementById('liked-list'), lk=S.liked(); el.innerHTML=''; if(!lk.length){el.innerHTML='<div class="empty"><i class="fas fa-heart"></i>No liked songs yet.</div>';return;} lk.forEach(function(s,i){el.appendChild(makeRow(s,lk,i,true,'song'));}); }
function renderRecent() { var el=document.getElementById('rc-list'), rc=S.recent(); el.innerHTML=''; if(!rc.length){el.innerHTML='<div class="empty"><i class="fas fa-clock"></i>No recent plays.</div>';return;} rc.forEach(function(s,i){el.appendChild(makeRow(s,rc,i,true,'song'));}); }
function renderDownloads() { var el=document.getElementById('dl-list'), dl=S.downloads(); el.innerHTML=''; if(!dl.length){el.innerHTML='<div class="empty"><i class="fas fa-download"></i>No downloads yet.</div>';return;} dl.forEach(function(s,i){el.appendChild(makeRow(s,dl,i,true,'song'));}); }

function ctxPlaylist(el) { var pid=el.dataset.playlistId; currentPlaylist=userPlaylistsCache.find(function(p){return p.id===pid;}); if(!currentPlaylist){showToast('Not found.');return;} document.getElementById('mo-playlist-opts-t').textContent='Options for "'+esc(currentPlaylist.name)+'"'; document.getElementById('mo-playlist-opts').classList.add('on'); }
function openNewPl() { if(!currentUser){document.getElementById('auth-screen').classList.add('on');showToast('Please sign in.');return;} document.getElementById('im-inp').value='';document.getElementById('im-newpl').classList.add('on');setTimeout(function(){document.getElementById('im-inp').focus();},150); }
function openRenamePl() { if(!currentUser||!currentPlaylist){showToast('Select a playlist.');return;} document.getElementById('im-rename-inp').value=currentPlaylist.name; document.getElementById('im-renamepl').classList.add('on');setTimeout(function(){document.getElementById('im-rename-inp').focus();},150); }
function ctxSong(song,plId) { curSong=song; updateLikeUI(); openSongOpts(plId); }
function openSongOpts(plId) { if(!curSong) return; document.getElementById('mo-opts-t').textContent=curSong.name||'Options'; var rm=document.querySelector('.ma[data-action="remove-from-playlist"]'); if(rm)rm.remove(); if(plId){var pl=userPlaylistsCache.find(function(p){return p.id===plId;}); if(pl&&pl.songs.some(function(s){return s.id===curSong.id;})){rm=document.createElement('div');rm.className='ma';rm.dataset.action='remove-from-playlist';rm.innerHTML='<i class="fas fa-minus-circle"></i> Remove from "'+esc(pl.name)+'"';rm.onclick=function(){removeFromPlaylist(plId,curSong.id);};document.getElementById('mo-opts').querySelector('.ms').insertBefore(rm,document.getElementById('mo-opts').querySelector('.ma:last-child'));}} document.getElementById('mo-opts').classList.add('on'); }
function openPlOpts() { if (!currentPlaylist) return; document.getElementById('mo-playlist-opts').classList.add('on'); }

// ─── Download ─────────────────────────────────────────────────
async function downloadSong() { closeMo('mo-opts'); if(!curSong){showToast('No song selected.');return;} var url=getDl(curSong); if(!url){showToast('Download not available.');return;} var dl=S.downloads(); if(!dl.find(function(s){return s.id===curSong.id;})){dl.push(curSong);S.s('downloads',dl);} try{var c=new AbortController();var id=setTimeout(function(){c.abort();},15000);var r=await fetch(url,{signal:c.signal});clearTimeout(id);if(!r.ok)throw new Error('Network error');var b=await r.blob();var bu=URL.createObjectURL(b);var a=document.createElement('a');a.href=bu;a.download=(curSong.name||'song')+'.mp3';document.body.appendChild(a);a.click();document.body.removeChild(a);URL.revokeObjectURL(bu);showToast('Download started!');updateStats();if(document.getElementById('lib-dl').style.display==='block')renderDownloads();}catch(e){console.error('Download failed:',e);showToast('Download failed.');var dl2=S.downloads().filter(function(s){return s.id!==curSong.id;});S.s('downloads',dl2);updateStats();} }

// ─── Share ────────────────────────────────────────────────────
function shareSong() { closeMo('mo-opts'); if(!curSong) return; var shareHash='#song-'+curSong.id; var shareUrl=window.location.origin+window.location.pathname+shareHash; if(navigator.share){navigator.share({title:'Listen to "'+curSong.name+'"',text:'Check out "'+curSong.name+'" by '+getArtist(curSong)+' on Let Lyre!',url:shareUrl}).then(function(){showToast('Shared!');}).catch(function(e){if(e.name!=='AbortError')showToast('Share cancelled');});}else{navigator.clipboard.writeText(shareUrl).then(function(){showToast('Link copied!');}).catch(function(){prompt('Copy link:',shareUrl);});} }

// ─── Lyrics ───────────────────────────────────────────────────
async function showLyrics() { closeMo('mo-opts'); document.getElementById('ly-title').textContent=curSong?curSong.name:'Lyrics'; document.getElementById('ly-body').innerHTML='<p style="text-align:center;color:var(--t2);"><i class="fas fa-spinner fa-spin"></i> Loading...</p>'; document.getElementById('mo-lyrics').classList.add('on'); var found=false; if(curSong&&curSong.id){try{var d=await callApi('/api/lyrics/'+curSong.id);if(d&&d.lyrics){document.getElementById('ly-body').innerHTML=d.lyrics.split('\n').map(function(l){return '<p>'+(esc(l)||'&nbsp;')+'</p>';}).join('');found=true;}}catch(e){console.error(e);}} if(!found) document.getElementById('ly-body').innerHTML='<p style="text-align:center;">Lyrics not available.</p>'; }

// ─── Modals ───────────────────────────────────────────────────
function closeMo(id) { document.getElementById(id).classList.remove('on'); }
function closeMoOnBg(e,id) { if(e.target===e.currentTarget) closeMo(id); }

// ─── Stats ────────────────────────────────────────────────────
function updateStats() { document.getElementById('st-lk').textContent=S.liked().length; document.getElementById('st-pl').textContent=userPlaylistsCache.length; document.getElementById('st-dl').textContent=S.downloads().length; }

// ─── Clear Data ──────────────────────────────────────────────
async function clearAll() { if(!confirm('Clear ALL local data?')) return; ['liked','recent','downloads','theme','volume'].forEach(function(k){localStorage.removeItem('letlyre_'+k);}); showToast('Local data cleared'); updateStats(); loadHome(homeLang); renderLib('pl'); if(currentUser&&confirm('Delete cloud playlists too?')){var r=await apiCall('delete_playlist_all');if(r&&r.success){userPlaylistsCache=[];renderPlaylists();updateStats();showToast('Cloud cleared.');}} }

// ─── Notifications ───────────────────────────────────────────
function togglePushNotifications() { var en=!(S.g('notifications')===false); en=!en; S.s('notifications',en); document.getElementById('notif-tog').classList.toggle('on',en); if(en&&window.OneSignal) window.OneSignal.Notifications.requestPermission(); showToast(en?'Notifications enabled':'Notifications disabled'); }
function openSendNotifModal() { document.getElementById('notif-title-inp').value=''; document.getElementById('notif-msg-inp').value=''; document.getElementById('im-send-notif').classList.add('on'); setTimeout(function(){document.getElementById('notif-title-inp').focus();},150); }
async function confirmSendNotif() { var t=document.getElementById('notif-title-inp').value.trim(), m=document.getElementById('notif-msg-inp').value.trim(); if(!t||!m){showToast('Enter both');return;} closeMo('im-send-notif'); showToast('Sending...',5000); var sent=await sendOneSignalNotification(t,m); showToast(sent?'Sent!':'Failed.',3000); }

// ─── Toast ────────────────────────────────────────────────────
let toastTimeout = null;
function showToast(msg, dur) { dur = dur || 3000; var t=document.getElementById('toast'); t.textContent=msg; t.classList.add('on'); clearTimeout(toastTimeout); toastTimeout=setTimeout(function(){t.classList.remove('on');},dur); }

// ─── OneSignal ────────────────────────────────────────────────
async function getOneSignalPlayerId() { if(!window.OneSignal) return null; try{return await window.OneSignal.User.getOnesignalId();}catch(e){return null;} }
async function sendOneSignalNotification(title,msg,url) { var key=ONESIGNAL_REST_API_KEY; if(!key){showToast('API key not set.',3000);return false;} try{var body={app_id:ONESIGNAL_APP_ID,headings:{en:title},contents:{en:msg},included_segments:['Subscribed Users']};if(url)body.url=url;var r=await fetch('https://onesignal.com/api/v1/notifications',{method:'POST',headers:{'Content-Type':'application/json','Authorization':'Basic '+key},body:JSON.stringify(body)});var d=await r.json();if(d.id)return true;console.error('Notif failed:',d);return false;}catch(e){console.error(e);return false;} }
async function sendWelcomeNotification(n) { return await sendOneSignalNotification('Welcome to Let Lyre!','Hey '+(n||'Music Lover')+'! Start exploring!',window.location.origin+window.location.pathname); }
async function sendNewSongsNotification(s,a) { return await sendOneSignalNotification('New Song Added!',s+' by '+a+' is now available!',window.location.origin+window.location.pathname); }

// ─── PWA ──────────────────────────────────────────────────────
function registerServiceWorker() { if('serviceWorker'in navigator){navigator.serviceWorker.register('/sw.js').catch(function(e){console.warn('SW failed:',e);});} }
function setupPWAManifest() { /* manifest.json is linked in <head> */ }

// ─── Routing ──────────────────────────────────────────────────
function handleRouting() { var hash=window.location.hash; if(hash==='#privacy-policy'||hash==='#terms-conditions'){goScreen(hash.replace('#',''));return;} var m=hash&&hash.match(/^#song-([a-zA-Z0-9]+)$/); if(m&&m[1]){var sid=m[1];if(sid){apiGetSongById(sid).then(function(song){if(song){startPlay(song);openFP();window.location.hash='';}else{showToast('Song not found.');goScreen('home');window.location.hash='';}}).catch(function(e){console.error(e);showToast('Error.');goScreen('home');window.location.hash='';});}return;} goScreen('home'); }
window.addEventListener('hashchange',function(){handleRouting();});

// ─── Keyboard Shortcuts ───────────────────────────────────────
document.addEventListener('keydown',function(e){if(e.target.tagName==='INPUT') return; switch(e.code){case'Space':e.preventDefault();togglePlay();break;case'ArrowRight':e.preventDefault();nextSong();break;case'ArrowLeft':e.preventDefault();prevSong();break;case'ArrowUp':e.preventDefault();aud.volume=Math.min(1,aud.volume+0.05);document.getElementById('vol-sl').value=aud.volume*100;S.s('volume',aud.volume);showToast('Volume: '+Math.round(aud.volume*100)+'%',1000);break;case'ArrowDown':e.preventDefault();aud.volume=Math.max(0,aud.volume-0.05);document.getElementById('vol-sl').value=aud.volume*100;S.s('volume',aud.volume);showToast('Volume: '+Math.round(aud.volume*100)+'%',1000);break;case'KeyL':if(curSong){e.preventDefault();toggleLikeCur();}break;case'Escape':closeFP();['mo-opts','mo-lyrics','mo-pl','im-newpl','mo-playlist-opts','im-renamepl'].forEach(closeMo);break;} });

// ─── Touch Gestures ───────────────────────────────────────────
var touchStartY=0; document.getElementById('fp').addEventListener('touchstart',function(e){touchStartY=e.touches[0].clientY;},{passive:true}); document.getElementById('fp').addEventListener('touchend',function(e){if(e.changedTouches[0].clientY-touchStartY>100)closeFP();},{passive:true});

// ─── OneSignal Config ─────────────────────────────────────────
var ONESIGNAL_APP_ID = "1a726ab3-3a6a-4e11-845f-e8912fd668f6";
var ONESIGNAL_REST_API_KEY = ""; // Set your OneSignal REST API Key here

// ─── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  initApp();
});

async function initApp() {
  applyTheme(); setupAudio(); setupPWAManifest(); registerServiceWorker(); setupDraggableSeek();
  if (typeof google !== 'undefined' && google.accounts) {
    google.accounts.id.initialize({
      client_id: GOOGLE_CLIENT_ID,
      callback: handleGoogleCredentialResponse
    });
  }
  document.getElementById('notif-tog').classList.toggle('on',S.g('notifications')!==false);
  var authResolved=false, initialContentLoaded=false;
  var splashEl=document.getElementById('splash');
  var lsTimeout=setTimeout(function(){if(!splashEl.classList.contains('gone')){splashEl.classList.add('gone');showToast('Loading took too long.',5000);}},8000);
  var finishLoad=function(){if(authResolved&&initialContentLoaded&&!splashEl.classList.contains('gone')){splashEl.classList.add('gone');clearTimeout(lsTimeout);}};

  // Set currentUser from PHP session if available
  if (PHP_USER && PHP_USER.id) {
    currentUser = PHP_USER;
    updateAuthUI();
  }
  authResolved=true; finishLoad();

  if (window.location.hash && window.location.hash.includes('access_token')) { window.history.replaceState(null,'',window.location.pathname+window.location.search); }

  try { await loadHome(''); } catch(e) { console.error('Home load error:',e); showToast('Failed to load content.',5000); } finally { initialContentLoaded=true; finishLoad(); }

  updateStats(); setupScrollObservers(); startAutoRefresh();
  if (currentUser) { loadUserPlaylists().catch(function(e){console.error('Playlist load error:',e);}); }
  initAppDone=true; handleRouting(); checkAndShowAnnouncement();
}

function setupScrollObservers() {
  var saEl=document.getElementById('sa-loading'); if (seeAllObserver) seeAllObserver.disconnect();
  seeAllObserver=new IntersectionObserver(function(entries){entries.forEach(function(e){if(e.isIntersecting&&saType&&!saLoading)loadMoreSeeAll();});},{rootMargin:'100px'});
  if (saEl) seeAllObserver.observe(saEl);
}

function startAutoRefresh() { if(autoRefreshTimer) clearInterval(autoRefreshTimer); autoRefreshTimer=setInterval(function(){if(document.getElementById('scr-home').classList.contains('on'))refreshHomeContent();},120000); }
async function refreshHomeContent() { await Promise.all([loadTrendingSongsSection(homeLang),loadNewAlbumsSection(homeLang),loadTrendingPlaylistsSection(homeLang),loadTrendingArtistsSection(homeLang),loadArtistRecommendationsSection(homeLang)]); }

// Scheduled notifications
var scheduledNotifTimer=null;
function initScheduledNotifications() { checkAndSendDailyGreetings(); if(scheduledNotifTimer) clearInterval(scheduledNotifTimer); scheduledNotifTimer=setInterval(checkAndSendDailyGreetings,30000); }
async function checkAndSendDailyGreetings() { if(S.g('notifications')===false) return; var now=new Date(), h=now.getHours(), m=now.getMinutes(), today=now.toDateString(); if(h===7&&m===0&&S.g('sent_gm')!==today){S.s('sent_gm',today);await sendOneSignalNotification('Good Morning','Rise and shine!');} if(h===12&&m===0&&S.g('sent_ga')!==today){S.s('sent_ga',today);await sendOneSignalNotification('Good Afternoon','Enjoy your day!');} if(h===21&&m===0&&S.g('sent_gn')!==today){S.s('sent_gn',today);await sendOneSignalNotification('Good Night','Time to wind down.');} }
</script>
</body>
</html>

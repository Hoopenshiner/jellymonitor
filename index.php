<?php
session_start();

/* ================= CONFIG ================= */
$ADMIN_PASSWORD = "youradminpassword";
$JELLYFIN_URL = "yourjellyfinurl";
$API_KEY = "yourjellyfinapikey";
$QB_URL = rtrim(getenv('QB_URL') ?: getenv('QBITTORRENT_URL') ?: "yourqbittorrenturl", '/');
$QB_USERNAME = getenv('QB_USERNAME') ?: getenv('QBITTORRENT_USERNAME') ?: "admin";
$QB_PASSWORD = getenv('QB_PASSWORD') ?: getenv('QBITTORRENT_PASSWORD') ?: "yourqbittorrentpassword";
$RADARR_URL = rtrim(getenv('RADARR_URL') ?: "yourraddarurl", '/');
$RADARR_API_KEY = getenv('RADARR_API_KEY') ?: "yourradarrapikey";
$SONARR_URL = rtrim(getenv('SONARR_URL') ?: "yoursonarrurl", '/');
$SONARR_API_KEY = getenv('SONARR_API_KEY') ?: "yoursonarrapikey";

/* ================= LOGOUT (FIXED) ================= */
if (isset($_GET['logout'])) {
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Location: admin.php");
    exit;
}

/* ================= LOGIN ================= */
$login_error = "";

if (isset($_POST['admin_password'])) {
    if (hash_equals($ADMIN_PASSWORD, $_POST['admin_password'])) {
        $_SESSION['admin_logged_in'] = true;
        header("Location: admin.php");
        exit;
    }

    $login_error = "Wrong password.";
}

if (empty($_SESSION['admin_logged_in'])):
?>
<!DOCTYPE html>
<html>
<head>
<title>Admin Login</title>
<link rel="icon" href="download.svg" type="image/svg+xml">
<style>
body{
    margin:0;
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    font-family:Arial;
    background:#0b0f14;
    color:#e6edf3;
}
.login-card{
    width:320px;
    background:#161b22;
    border:1px solid #30363d;
    border-radius:8px;
    padding:24px;
}
h2{margin:0 0 16px;font-size:20px;}
input,button{
    width:100%;
    box-sizing:border-box;
    padding:10px;
    border-radius:6px;
    font-size:14px;
}
input{
    border:1px solid #30363d;
    background:#0d1117;
    color:white;
    margin-bottom:10px;
}
button{
    border:none;
    background:#00aaff;
    color:white;
    cursor:pointer;
}
.error{color:#f85149;font-size:13px;margin-bottom:10px;}
</style>
</head>
<body>
<form class="login-card" method="POST">
<h2>Admin Login</h2>
<?php if($login_error): ?><div class="error"><?= htmlspecialchars($login_error) ?></div><?php endif; ?>
<input type="password" name="admin_password" placeholder="Password" autofocus required>
<button>Login</button>
</form>
</body>
</html>
<?php
exit;
endif;

/* ================= DB ================= */
$conn = new mysqli("db", "appuser", "dbpass", "jellyfin");
if ($conn->connect_error) die("DataBase error");

/* ================= DELETE USER ================= */
if (isset($_GET['delete_user'])) {
    $id = intval($_GET['delete_user']);

    $stmt = $conn->prepare("SELECT jellyfin_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    $jellyfin_id = $row['jellyfin_id'] ?? null;

    if ($jellyfin_id) {
        $ch = curl_init("$JELLYFIN_URL/Users/" . $jellyfin_id);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Emby-Token: $API_KEY"]);
        curl_exec($ch);
        curl_close($ch);
    }

    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    header("Location: admin.php");
    exit;
}

/* ================= DELETE CODE ================= */
if (isset($_GET['delete_code'])) {
    $id = intval($_GET['delete_code']);

    $stmt = $conn->prepare("DELETE FROM invite_codes WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    header("Location: admin.php");
    exit;
}

/* ================= ADD INVITE CODE ================= */
if (isset($_POST['new_code'])) {
    $code = trim($_POST['new_code']);
    $uses = intval($_POST['uses']);

    if ($code !== '') {
        $stmt = $conn->prepare("INSERT INTO invite_codes (code, uses_left) VALUES (?, ?)");
        $stmt->bind_param("si", $code, $uses);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: admin.php");
    exit;
}

/* ================= MAX USERS ================= */
if (isset($_POST['update_max'])) {
    $new_max = intval($_POST['max_users']);

    $stmt = $conn->prepare("UPDATE settings SET max_users = ? WHERE id = 1");
    $stmt->bind_param("i", $new_max);
    $stmt->execute();
    $stmt->close();

    header("Location: admin.php");
    exit;
}

/* ================= SETTINGS ================= */
$settings = $conn->query("SELECT max_users FROM settings WHERE id = 1");
$current_max = ($settings && $row = $settings->fetch_assoc()) ? $row['max_users'] : 25;

/* ================= USERS ================= */
$users = $conn->query("SELECT id, username, phone, created_at FROM users ORDER BY created_at DESC");
$codes = $conn->query("SELECT * FROM invite_codes ORDER BY created_at DESC");

$total_users = $users->num_rows;

/* ================= ACTIVE SESSIONS ================= */
function getSessions($url,$key){
    $ch=curl_init("$url/Sessions");
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch,CURLOPT_HTTPHEADER,["X-Emby-Token:$key"]);
    $data=json_decode(curl_exec($ch),true);
    curl_close($ch);

    $out=[];

    if(is_array($data)){
        foreach($data as $s){
            if(empty($s['NowPlayingItem']) || empty($s['UserName'])) continue;

            $item=$s['NowPlayingItem'];

            $pos=$s['PlayState']['PositionTicks'] ?? 0;
            $dur=$item['RunTimeTicks'] ?? 1;

            $pct=$dur>0?($pos/$dur)*100:0;
            $pct=max(0,min(100,$pct));

            $title = !empty($item['SeriesName'])
                ? $item['SeriesName']." - ".($item['Name'] ?? '')
                : ($item['Name'] ?? 'Unknown');

            $out[]=[
                "user"=>$s['UserName'],
                "title"=>$title,
                "progress"=>$pct
            ];
        }

        /* ================= FIX: stable ordering ================= */
        usort($out, function($a, $b) {
            // primary: highest progress first
            if ($a['progress'] !== $b['progress']) {
                return $b['progress'] <=> $a['progress'];
            }

            // secondary: alphabetical username
            return strcmp($a['user'], $b['user']);
        });
    }

    return $out;
}

function formatBytes($bytes){
    $bytes = (float)$bytes;
    $units = ['B','KB','MB','GB','TB'];

    for($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++){
        $bytes /= 1024;
    }

    return ($i === 0 ? round($bytes) : round($bytes, 1)) . ' ' . $units[$i];
}

function formatSpeed($bytes){
    return formatBytes($bytes) . '/s';
}

function formatEta($seconds){
    $seconds = (int)$seconds;
    if($seconds <= 0 || $seconds >= 8640000) return 'Unknown';
    if($seconds < 60) return $seconds . 's';
    if($seconds < 3600) return floor($seconds / 60) . 'm';
    if($seconds < 86400) return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
    return floor($seconds / 86400) . 'd ' . floor(($seconds % 86400) / 3600) . 'h';
}

function qbitRequest($baseUrl, $path, $cookieFile, $postFields = null){
    $ch = curl_init($baseUrl . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);

    if($postFields !== null){
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    }

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['body' => $body, 'error' => $error, 'code' => $code];
}

function getQbitStatus($baseUrl, $username, $password){
    $cookieFile = tempnam(sys_get_temp_dir(), 'qbit_');

    if($cookieFile === false){
        return ['ok' => false, 'error' => 'Could not create qBittorrent session file.', 'torrents' => []];
    }

    try {
        if($username !== '' && $password !== ''){
            $login = qbitRequest(
                $baseUrl,
                '/api/v2/auth/login',
                $cookieFile,
                http_build_query(['username' => $username, 'password' => $password])
            );

            if($login['error'] || trim((string)$login['body']) !== 'Ok.'){
                return [
                    'ok' => false,
                    'error' => 'qBittorrent login failed. Set QB_USERNAME and QB_PASSWORD for this app container.',
                    'torrents' => []
                ];
            }
        }

        $main = qbitRequest($baseUrl, '/api/v2/sync/maindata?rid=0', $cookieFile);
        if($main['error'] || $main['code'] >= 400){
            return [
                'ok' => false,
                'error' => $main['error'] ?: 'qBittorrent API returned HTTP ' . $main['code'] . '.',
                'torrents' => []
            ];
        }

        $mainData = json_decode($main['body'], true);
        if(!is_array($mainData)){
            return ['ok' => false, 'error' => 'qBittorrent returned an unreadable response.', 'torrents' => []];
        }

        $torrentsResponse = qbitRequest($baseUrl, '/api/v2/torrents/info?sort=added_on&reverse=true', $cookieFile);
        $torrentsData = json_decode($torrentsResponse['body'], true);
        $torrents = [];

        if(is_array($torrentsData)){
            foreach(array_slice($torrentsData, 0, 20) as $torrent){
                $torrents[] = [
                    'name' => $torrent['name'] ?? 'Unknown',
                    'state' => $torrent['state'] ?? 'unknown',
                    'progress' => round((float)($torrent['progress'] ?? 0) * 100, 1),
                    'downloaded' => formatBytes($torrent['downloaded'] ?? 0),
                    'size' => formatBytes($torrent['size'] ?? 0),
                    'dlspeed' => formatSpeed($torrent['dlspeed'] ?? 0),
                    'upspeed' => formatSpeed($torrent['upspeed'] ?? 0),
                    'eta' => formatEta($torrent['eta'] ?? 0)
                ];
            }
        }

        $server = $mainData['server_state'] ?? [];

        return [
            'ok' => true,
            'error' => '',
            'connection' => $server['connection_status'] ?? 'unknown',
            'dlspeed' => formatSpeed($server['dl_info_speed'] ?? 0),
            'upspeed' => formatSpeed($server['up_info_speed'] ?? 0),
            'free_space' => formatBytes($server['free_space_on_disk'] ?? 0),
            'total_torrents' => is_array($torrentsData) ? count($torrentsData) : 0,
            'downloading' => is_array($torrentsData) ? count(array_filter($torrentsData, function($torrent){
                return in_array($torrent['state'] ?? '', ['downloading', 'metaDL', 'forcedDL'], true);
            })) : 0,
            'torrents' => $torrents
        ];
    } finally {
        if(is_file($cookieFile)) unlink($cookieFile);
    }
}

function arrRequest($baseUrl, $apiKey, $path){
    if($apiKey === ''){
        return ['ok' => false, 'error' => 'Missing API key.', 'data' => null];
    }

    $ch = curl_init($baseUrl . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Api-Key: ' . $apiKey]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if($error || $code >= 400){
        return ['ok' => false, 'error' => $error ?: 'HTTP ' . $code, 'data' => null];
    }

    $data = json_decode($body, true);
    if($body !== '' && $data === null){
        return ['ok' => false, 'error' => 'Unreadable response.', 'data' => null];
    }

    return ['ok' => true, 'error' => '', 'data' => $data];
}

function getArrServiceStatus($name, $baseUrl, $apiKey, $libraryPath){
    if($apiKey === ''){
        return [
            'name' => $name,
            'ok' => false,
            'error' => 'Set ' . strtoupper($name) . '_API_KEY.',
            'version' => '',
            'library_total' => 0,
            'queue_total' => 0,
            'queue' => []
        ];
    }

    $status = arrRequest($baseUrl, $apiKey, '/api/v3/system/status');
    if(!$status['ok']){
        return [
            'name' => $name,
            'ok' => false,
            'error' => $status['error'],
            'version' => '',
            'library_total' => 0,
            'queue_total' => 0,
            'queue' => []
        ];
    }

    $library = arrRequest($baseUrl, $apiKey, $libraryPath);
    $queue = arrRequest($baseUrl, $apiKey, '/api/v3/queue?' . http_build_query([
        'page' => 1,
        'pageSize' => 4,
        'sortKey' => 'timeleft',
        'sortDirection' => 'ascending'
    ]));

    $records = is_array($queue['data']['records'] ?? null) ? $queue['data']['records'] : [];
    $queueItems = [];

    foreach($records as $item){
        $queueItems[] = [
            'title' => $item['title'] ?? ($item['movie']['title'] ?? ($item['series']['title'] ?? 'Unknown')),
            'status' => $item['status'] ?? ($item['trackedDownloadStatus'] ?? 'queued'),
            'progress' => round((float)($item['sizeleft'] ?? 0) > 0 && (float)($item['size'] ?? 0) > 0
                ? (1 - ((float)$item['sizeleft'] / (float)$item['size'])) * 100
                : 0, 1),
            'sizeleft' => formatBytes($item['sizeleft'] ?? 0)
        ];
    }

    return [
        'name' => $name,
        'ok' => true,
        'error' => '',
        'version' => $status['data']['version'] ?? '',
        'library_total' => is_array($library['data']) ? count($library['data']) : 0,
        'queue_total' => (int)($queue['data']['totalRecords'] ?? count($queueItems)),
        'queue' => $queueItems
    ];
}

function getArrStatus($radarrUrl, $radarrKey, $sonarrUrl, $sonarrKey){
    return [
        'radarr' => getArrServiceStatus('radarr', $radarrUrl, $radarrKey, '/api/v3/movie'),
        'sonarr' => getArrServiceStatus('sonarr', $sonarrUrl, $sonarrKey, '/api/v3/series')
    ];
}

if(isset($_GET['live'])){
    header('Content-Type: application/json');
    echo json_encode(getSessions($JELLYFIN_URL,$API_KEY));
    exit;
}

if(isset($_GET['qbit_live'])){
    header('Content-Type: application/json');
    echo json_encode(getQbitStatus($QB_URL,$QB_USERNAME,$QB_PASSWORD));
    exit;
}

if(isset($_GET['arr_live'])){
    header('Content-Type: application/json');
    echo json_encode(getArrStatus($RADARR_URL,$RADARR_API_KEY,$SONARR_URL,$SONARR_API_KEY));
    exit;
}

$active=getSessions($JELLYFIN_URL,$API_KEY);
$qbit=getQbitStatus($QB_URL,$QB_USERNAME,$QB_PASSWORD);
$arr=getArrStatus($RADARR_URL,$RADARR_API_KEY,$SONARR_URL,$SONARR_API_KEY);
?>

<!DOCTYPE html>
<html>
<head>
<title>Admin Panel</title>
<link rel="icon" href="download.svg" type="image/svg+xml">

<style>
body{
    margin:0;
    font-family:Arial;
    background:#0b0f14;
    color:#e6edf3;
    padding:10px;
    font-size:13px;
}

.container{max-width:1800px;margin:auto;}

.topbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    background:#161b22;
    border:1px solid #30363d;
    padding:8px 12px;
    border-radius:8px;
    margin-bottom:10px;
}

.stats{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.stat{font-size:13px;color:#c9d1d9;}
.stat b{color:white;}

.logout{
    background:#f85149;
    padding:6px 10px;
    border-radius:6px;
    color:white;
    text-decoration:none;
    font-size:13px;
}

.grid{
    display:grid;
    grid-template-columns:repeat(4, minmax(0, 1fr));
    gap:10px;
}

.card{
    background:#161b22;
    border:1px solid #30363d;
    border-radius:8px;
    padding:10px;
}

.card h3{
    margin:0 0 8px;
    font-size:15px;
}

/* hide scrollbar */
.table-wrap{
    max-height:210px;
    overflow-y:auto;
    scrollbar-width:none;
    -ms-overflow-style:none;
}
.wide-card .table-wrap{max-height:220px;}
.table-wrap::-webkit-scrollbar{display:none;}

table{
    width:100%;
    border-collapse:collapse;
}

th,td{
    padding:5px 6px;
    border-bottom:1px solid #2a2f36;
    font-size:12px;
}

th{color:#9da7b1;}
tr:hover{background:#1b222b;}

/* USERS */
.badge{
    background:#00aaff;
    padding:2px 5px;
    border-radius:14px;
    font-size:11px;
}

.active-user{
    background:#2ea043;
    padding:2px 5px;
    border-radius:14px;
    font-size:11px;
}

/* NOW PLAYING */
.now-playing-user{
    background:#00aaff;
    padding:2px 6px;
    border-radius:14px;
    font-size:11px;
}

/* PROGRESS */
.progress-bar{
    width:100%;
    height:4px;
    background:#2a2f36;
    border-radius:4px;
    margin-top:4px;
    overflow:hidden;
}

.progress-fill{
    height:100%;
    background:#00aaff;
}

/* % text */
.small-muted{
    color:#9da7b1;
    font-size:10px;
    margin-top:1px;
}

.wide-card{
    grid-column:1 / -1;
}

.qbit-summary{
    display:grid;
    grid-template-columns:repeat(5, minmax(120px, 1fr));
    gap:8px;
    margin-bottom:8px;
}

.qbit-stat{
    background:#0d1117;
    border:1px solid #30363d;
    border-radius:6px;
    padding:7px;
}

.qbit-stat span{
    display:block;
    color:#9da7b1;
    font-size:10px;
    margin-bottom:3px;
}

.qbit-stat b{
    color:white;
    font-size:13px;
}

.status-ok{color:#3fb950;}
.status-warn{color:#f2cc60;}
.status-error{color:#f85149;}
.torrent-name{max-width:520px;word-break:break-word;}

.arr-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:8px;
}

.arr-service{
    background:#0d1117;
    border:1px solid #30363d;
    border-radius:6px;
    padding:7px;
    min-width:0;
}

.arr-service h4{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    margin:0 0 6px;
    font-size:13px;
}

.arr-pill{
    background:#2ea043;
    border-radius:14px;
    color:white;
    font-size:11px;
    padding:2px 6px;
}

.arr-line{
    display:flex;
    justify-content:space-between;
    gap:8px;
    color:#c9d1d9;
    font-size:11px;
    margin-top:4px;
}

.arr-line b{color:white;}
.arr-queue{margin-top:7px;}
.arr-queue-item{border-top:1px solid #2a2f36;padding-top:6px;margin-top:6px;}
.arr-title{font-size:11px;word-break:break-word;}

@media (max-width: 1300px){
    .grid{grid-template-columns:1fr 1fr;}
}

@media (max-width: 850px){
    .grid{grid-template-columns:1fr;}
    .qbit-summary{grid-template-columns:1fr 1fr;}
    .arr-grid{grid-template-columns:1fr;}
}

/* FORMS */
.inline-form{
    display:flex;
    gap:8px;
    align-items:center;
}

.inline-form input{
    width:120px;
    padding:5px 7px;
    border-radius:6px;
    border:1px solid #30363d;
    background:#0d1117;
    color:white;
}

.inline-form button{
    padding:5px 9px;
    border:none;
    border-radius:6px;
    background:#00aaff;
    color:white;
    cursor:pointer;
}

/* DELETE */
.delete{
    color:#f85149;
    font-size:16px;
    font-weight:bold;
    text-decoration:none;
}
</style>
</head>

<body>

<div class="container">

<div class="topbar">
<div class="stats">
<div class="stat">Total <b><?= $total_users ?></b></div>
<div class="stat">Active <b id="active-count"><?= count($active) ?></b></div>

<form method="POST" class="inline-form">
<div class="stat">Max</div>
<input type="number" name="max_users" value="<?= $current_max ?>">
<button name="update_max">Save</button>
</form>
</div>

<a class="logout" href="?logout=1">Logout</a>
</div>

<div class="grid">

<!-- USERS -->
<div class="card">
<h3>Users</h3>

<div class="table-wrap">
<table>
<tr><th>User</th><th>Phone</th><th>Created</th><th></th></tr>

<?php foreach($users as $u): ?>
<tr>
<td>
<?php
$isActive=false;
foreach($active as $a){
    if($a['user']==$u['username']){$isActive=true;break;}
}
?>
<span id="user-status-<?= $u['id'] ?>" data-username="<?= htmlspecialchars($u['username']) ?>" class="<?= $isActive?'active-user':'badge' ?>">
<?= htmlspecialchars($u['username']) ?>
</span>
</td>
<td><?= htmlspecialchars($u['phone']) ?></td>
<td><?= $u['created_at'] ?></td>
<td><a class="delete" href="?delete_user=<?= $u['id'] ?>">×</a></td>
</tr>
<?php endforeach; ?>
</table>
</div>
</div>

<!-- INVITES -->
<div class="card">
<h3>Invite Codes</h3>

<form method="POST" class="inline-form">
<input name="new_code" placeholder="Code" required>
<input name="uses" type="number" value="1" min="1">
<button>Add</button>
</form>

<div class="table-wrap">
<table>
<tr><th>Code</th><th>Uses</th><th></th></tr>

<?php foreach($codes as $c): ?>
<tr>
<td><?= htmlspecialchars($c['code']) ?></td>
<td><?= $c['uses_left'] ?></td>
<td><a class="delete" href="?delete_code=<?= $c['id'] ?>">×</a></td>
</tr>
<?php endforeach; ?>
</table>
</div>
</div>

<!-- NOW PLAYING -->
<div class="card">
<h3>Now Playing</h3>

<div class="table-wrap">
<table id="now">
<tr><th>User</th><th>Watching</th></tr>

<?php foreach($active as $a): ?>
<tr>
<td><span class="now-playing-user"><?= htmlspecialchars($a['user']) ?></span></td>
<td>
<?= htmlspecialchars($a['title']) ?>

<div class="progress-bar">
<div class="progress-fill" style="width:<?= $a['progress'] ?>%"></div>
</div>

<div class="small-muted"><?= round($a['progress']) ?>%</div>
</td>
</tr>
<?php endforeach; ?>
</table>
</div>

</div>

<!-- RADARR / SONARR -->
<div class="card">
<h3>Radarr & Sonarr</h3>

<div id="arr">
<div class="arr-grid">
<?php foreach(['radarr' => 'Radarr', 'sonarr' => 'Sonarr'] as $key => $label): ?>
<?php $service = $arr[$key]; ?>
<div class="arr-service">
<h4><?= $label ?> <span class="<?= $service['ok'] ? 'arr-pill' : 'status-error' ?>"><?= $service['ok'] ? 'Online' : 'Offline' ?></span></h4>
<?php if(!$service['ok']): ?>
<div class="small-muted status-error"><?= htmlspecialchars($service['error']) ?></div>
<?php else: ?>
<div class="arr-line"><span>Library</span><b><?= $service['library_total'] ?></b></div>
<div class="arr-line"><span>Queue</span><b><?= $service['queue_total'] ?></b></div>
<div class="arr-line"><span>Version</span><b><?= htmlspecialchars($service['version']) ?></b></div>

<div class="arr-queue">
<?php foreach($service['queue'] as $item): ?>
<div class="arr-queue-item">
<div class="arr-title"><?= htmlspecialchars($item['title']) ?></div>
<div class="progress-bar">
<div class="progress-fill" style="width:<?= min(100, max(0, $item['progress'])) ?>%"></div>
</div>
<div class="small-muted"><?= htmlspecialchars($item['status']) ?> &middot; <?= htmlspecialchars($item['sizeleft']) ?> left</div>
</div>
<?php endforeach; ?>
<?php if(empty($service['queue'])): ?>
<div class="small-muted">Queue is clear.</div>
<?php endif; ?>
</div>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
</div>
</div>

<!-- QBITTORRENT -->
<div class="card wide-card">
<h3>qBittorrent</h3>

<div id="qbit">
<?php if(!$qbit['ok']): ?>
<div class="small-muted status-error"><?= htmlspecialchars($qbit['error']) ?></div>
<?php else: ?>
<div class="qbit-summary">
<div class="qbit-stat"><span>Status</span><b class="<?= ($qbit['connection'] ?? '') === 'connected' ? 'status-ok' : 'status-warn' ?>"><?= htmlspecialchars($qbit['connection']) ?></b></div>
<div class="qbit-stat"><span>Downloading</span><b><?= $qbit['downloading'] ?> / <?= $qbit['total_torrents'] ?></b></div>
<div class="qbit-stat"><span>Download</span><b><?= htmlspecialchars($qbit['dlspeed']) ?></b></div>
<div class="qbit-stat"><span>Upload</span><b><?= htmlspecialchars($qbit['upspeed']) ?></b></div>
<div class="qbit-stat"><span>Free Space</span><b><?= htmlspecialchars($qbit['free_space']) ?></b></div>
</div>

<div class="table-wrap">
<table>
<tr><th>Name</th><th>Status</th><th>Progress</th><th>Speed</th><th>ETA</th></tr>

<?php foreach($qbit['torrents'] as $torrent): ?>
<tr>
<td class="torrent-name"><?= htmlspecialchars($torrent['name']) ?><div class="small-muted"><?= htmlspecialchars($torrent['downloaded']) ?> of <?= htmlspecialchars($torrent['size']) ?></div></td>
<td><?= htmlspecialchars($torrent['state']) ?></td>
<td>
<div><?= htmlspecialchars($torrent['progress']) ?>%</div>
<div class="progress-bar">
<div class="progress-fill" style="width:<?= min(100, max(0, $torrent['progress'])) ?>%"></div>
</div>
</td>
<td>
<div><?= htmlspecialchars($torrent['dlspeed']) ?></div>
<div class="small-muted"><?= htmlspecialchars($torrent['upspeed']) ?> up</div>
</td>
<td><?= htmlspecialchars($torrent['eta']) ?></td>
</tr>
<?php endforeach; ?>

<?php if(empty($qbit['torrents'])): ?>
<tr><td colspan="5" class="small-muted">No torrents found.</td></tr>
<?php endif; ?>
</table>
</div>
<?php endif; ?>
</div>
</div>

</div>
</div>

<script>
function escapeHtml(value){
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[char]));
}

async function refresh(){
    const r = await fetch("admin.php?live=1", { cache: 'no-store' });
    const d = await r.json();

    let html = `<tr><th>User</th><th>Watching</th></tr>`;
    const activeUsers = new Set(d.map(x=>x.user));

    d.forEach(x=>{
        html += `
        <tr>
        <td><span class="now-playing-user">${escapeHtml(x.user)}</span></td>
        <td>
        ${escapeHtml(x.title)}

        <div class="progress-bar">
            <div class="progress-fill" style="width:${Math.max(0, Math.min(100, Number(x.progress) || 0))}%"></div>
        </div>

        <div class="small-muted">${Math.round(Number(x.progress) || 0)}%</div>

        </td>
        </tr>`;
    });

    document.getElementById("now").innerHTML = html;
    document.getElementById("active-count").textContent = d.length;

    document.querySelectorAll('[data-username]').forEach(el=>{
        if(activeUsers.has(el.dataset.username)){
            el.className = 'active-user';
        } else {
            el.className = 'badge';
        }
    });
}

async function refreshQbit(){
    const r = await fetch("admin.php?qbit_live=1", { cache: 'no-store' });
    const d = await r.json();
    const el = document.getElementById("qbit");

    if(!d.ok){
        el.innerHTML = `<div class="small-muted status-error">${escapeHtml(d.error || 'Unable to load qBittorrent.')}</div>`;
        return;
    }

    const statusClass = d.connection === 'connected' ? 'status-ok' : 'status-warn';
    let html = `
    <div class="qbit-summary">
        <div class="qbit-stat"><span>Status</span><b class="${statusClass}">${escapeHtml(d.connection)}</b></div>
        <div class="qbit-stat"><span>Downloading</span><b>${escapeHtml(d.downloading)} / ${escapeHtml(d.total_torrents)}</b></div>
        <div class="qbit-stat"><span>Download</span><b>${escapeHtml(d.dlspeed)}</b></div>
        <div class="qbit-stat"><span>Upload</span><b>${escapeHtml(d.upspeed)}</b></div>
        <div class="qbit-stat"><span>Free Space</span><b>${escapeHtml(d.free_space)}</b></div>
    </div>

    <div class="table-wrap">
    <table>
    <tr><th>Name</th><th>Status</th><th>Progress</th><th>Speed</th><th>ETA</th></tr>`;

    if(d.torrents.length === 0){
        html += `<tr><td colspan="5" class="small-muted">No torrents found.</td></tr>`;
    } else {
        d.torrents.forEach(torrent => {
            const progress = Math.max(0, Math.min(100, Number(torrent.progress) || 0));
            html += `
            <tr>
            <td class="torrent-name">${escapeHtml(torrent.name)}<div class="small-muted">${escapeHtml(torrent.downloaded)} of ${escapeHtml(torrent.size)}</div></td>
            <td>${escapeHtml(torrent.state)}</td>
            <td>
                <div>${escapeHtml(torrent.progress)}%</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width:${progress}%"></div>
                </div>
            </td>
            <td>
                <div>${escapeHtml(torrent.dlspeed)}</div>
                <div class="small-muted">${escapeHtml(torrent.upspeed)} up</div>
            </td>
            <td>${escapeHtml(torrent.eta)}</td>
            </tr>`;
        });
    }

    html += `</table></div>`;
    el.innerHTML = html;
}

function renderArrService(service, label){
    const statusClass = service.ok ? 'arr-pill' : 'status-error';
    const statusText = service.ok ? 'Online' : 'Offline';

    let html = `
    <div class="arr-service">
    <h4>${label} <span class="${statusClass}">${statusText}</span></h4>`;

    if(!service.ok){
        html += `<div class="small-muted status-error">${escapeHtml(service.error)}</div></div>`;
        return html;
    }

    html += `
    <div class="arr-line"><span>Library</span><b>${escapeHtml(service.library_total)}</b></div>
    <div class="arr-line"><span>Queue</span><b>${escapeHtml(service.queue_total)}</b></div>
    <div class="arr-line"><span>Version</span><b>${escapeHtml(service.version)}</b></div>
    <div class="arr-queue">`;

    if(service.queue.length === 0){
        html += `<div class="small-muted">Queue is clear.</div>`;
    } else {
        service.queue.forEach(item => {
            const progress = Math.max(0, Math.min(100, Number(item.progress) || 0));
            html += `
            <div class="arr-queue-item">
            <div class="arr-title">${escapeHtml(item.title)}</div>
            <div class="progress-bar">
                <div class="progress-fill" style="width:${progress}%"></div>
            </div>
            <div class="small-muted">${escapeHtml(item.status)} &middot; ${escapeHtml(item.sizeleft)} left</div>
            </div>`;
        });
    }

    html += `</div></div>`;
    return html;
}

async function refreshArr(){
    const r = await fetch("admin.php?arr_live=1", { cache: 'no-store' });
    const d = await r.json();
    document.getElementById("arr").innerHTML = `
    <div class="arr-grid">
    ${renderArrService(d.radarr, 'Radarr')}
    ${renderArrService(d.sonarr, 'Sonarr')}
    </div>`;
}

refresh();
refreshArr();
refreshQbit();
setInterval(refresh, 3000);
setInterval(refreshArr, 10000);
setInterval(refreshQbit, 5000);
</script>

</body>
</html>

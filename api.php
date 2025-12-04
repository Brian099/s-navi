<?php
// api.php - 后台 API（JSON 存储），新增 toggle action 支持直接切换 enabled 状态，以及 fetch_icon / upload_icon
session_start();
$dataFile = __DIR__ . '/data/services.json';
if (!file_exists($dataFile)) {
    if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0755, true);
    file_put_contents($dataFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
if (!isset($_SESSION['admin'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'err'=>'未登录']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
header('Content-Type: application/json; charset=utf-8');

function load_services($file) {
    $fp = fopen($file, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : [];
}
function save_services($file, $arr) {
    $tmp = $file . '.tmp';
    $fp = fopen($tmp, 'c');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode(array_values($arr), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return rename($tmp, $file);
}

if ($action === 'get' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $services = load_services($dataFile);
    foreach ($services as $row) {
        if (($row['id'] ?? 0) == $id) {
            echo json_encode(['ok'=>true,'row'=>$row]);
            exit;
        }
    }
    echo json_encode(['ok'=>false,'err'=>'未找到']);
    exit;
}

if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $services = load_services($dataFile);
    $found = false;
    foreach ($services as $k => $v) {
        if (($v['id'] ?? 0) == $id) { $found = true; unset($services[$k]); break; }
    }
    if (!$found) { echo json_encode(['ok'=>false,'err'=>'未找到']); exit; }
    $ok = save_services($dataFile, $services);
    echo json_encode(['ok'=>$ok]);
    exit;
}

// Toggle enabled 状态（AJAX 可调用）
if ($action === 'toggle' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $services = load_services($dataFile);
    $found = false;
    foreach ($services as $k => $v) {
        if (($v['id'] ?? 0) == $id) {
            $found = true;
            $services[$k]['enabled'] = empty($services[$k]['enabled']) ? 1 : 0;
            $newVal = $services[$k]['enabled'];
            break;
        }
    }
    if (!$found) {
        echo json_encode(['ok'=>false,'err'=>'未找到']);
        exit;
    }
    $ok = save_services($dataFile, $services);
    if ($ok) echo json_encode(['ok'=>true,'enabled'=>$newVal]);
    else echo json_encode(['ok'=>false,'err'=>'保存失败']);
    exit;
}

// Helper: 获取 content-type -> ext
function mime_to_ext($mime) {
    static $map = [
        'image/png'=>'png','image/jpeg'=>'jpg','image/jpg'=>'jpg','image/gif'=>'gif',
        'image/svg+xml'=>'svg','image/webp'=>'webp','image/x-icon'=>'ico','image/vnd.microsoft.icon'=>'ico',
        'application/octet-stream'=>'bin'
    ];
    return $map[strtolower($mime)] ?? '';
}

// Helper: 使用 curl 下载 favicon（返回 [success, data, content_type, ext]）
function curl_fetch($url, $timeout = 8) {
    if (!function_exists('curl_init')) return [false, null, null, null];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'favicon-fetcher/1.0');
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($code >= 200 && $code < 300 && $data !== false && strlen($data) > 0) {
        $ext = '';
        if ($ct) {
            $ext = mime_to_ext(explode(';', $ct)[0]);
        }
        // try to guess by url path if ext missing
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $pathExt = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: '');
        if ($pathExt && in_array($pathExt, ['png','jpg','jpeg','gif','svg','webp','ico'])) {
            $ext = $pathExt === 'jpeg' ? 'jpg' : $pathExt;
        }
        return [true, $data, $ct, $ext];
    }
    return [false, null, null, null];
}

// Fetch favicon based on existing service links
if ($action === 'fetch_icon' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $services = load_services($dataFile);
    $found = false;
    foreach ($services as $k => $v) {
        if (($v['id'] ?? 0) == $id) {
            $found = true;
            $rowIndex = $k;
            $row = $v;
            break;
        }
    }
    if (!$found) { echo json_encode(['ok'=>false,'err'=>'未找到']); exit; }

    // candidate URLs in order：如果某一项为空则跳过
    $candidates = [];
    foreach (['link_v6','link_v4','link_lan'] as $key) {
        if (!empty($row[$key])) $candidates[] = $row[$key];
    }
    if (empty($candidates)) {
        echo json_encode(['ok'=>false,'err'=>'没有可尝试的网址']); exit;
    }

    $success = false;
    $savedPath = '';

    foreach ($candidates as $u) {
        $u = trim($u);
        if ($u === '') continue;

        // 先尝试用 parse_url 获取 host/port/scheme
        $p = parse_url($u);
        $host = $p['host'] ?? null;
        $port = $p['port'] ?? null;
        $scheme = $p['scheme'] ?? null;

        // 如果 parse_url 没能正确解析（例如用户填写了 "host:port" 或没有 scheme），手动解析 host[:port]
        if (!$host) {
            $tmp = preg_replace('#^https?://#i','',$u);
            $tmp = preg_replace('#/.*$#','',$tmp);
            if ($tmp !== '') {
                // 尝试分离 host 和 port
                if (strpos($tmp, ':') !== false) {
                    list($hostPart, $portPart) = explode(':', $tmp, 2);
                    $host = $hostPart;
                    // 只取数字端口
                    if (preg_match('/^\d+$/', $portPart)) $port = (int)$portPart;
                } else {
                    $host = $tmp;
                }
            }
        }

        if (!$host) continue;

        // 如果 URL 中带了路径但没有 scheme，保留 host:port 形式用于拼接 favicon
        $hostPort = $host . ($port ? ':' . $port : '');

        // 如果提供了 scheme，则优先使用，否则尝试 https 再 http
        $schemes = $scheme ? [$scheme] : ['https','http'];

        foreach ($schemes as $s) {
            $faviconUrl = $s . '://' . $hostPort . '/favicon.ico';
            list($ok, $data, $ct, $ext) = curl_fetch($faviconUrl, 8);
            if ($ok) {
                // 允许的类型或尽量保存
                $allowed = ['png','jpg','jpeg','gif','svg','webp','ico','bin'];
                if ($ext === '') {
                    // 尝试从 content-type 推断
                    $ext = mime_to_ext(explode(';', ($ct?:''))[0]) ?: '';
                }
                if ($ext === 'jpeg') $ext = 'jpg';
                if (!in_array($ext, $allowed)) {
                    // 尝试从 url path 的后缀
                    $pathExt = strtolower(pathinfo(parse_url($faviconUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: '');
                    if (in_array($pathExt, $allowed)) $ext = $pathExt;
                }
                if ($ext === '') $ext = 'ico';
                $targetDir = __DIR__ . '/uploads';
                if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
                $fn = 'icon_fetch_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $target = $targetDir . '/' . $fn;
                if (file_put_contents($target, $data) !== false) {
                    // update json
                    $services[$rowIndex]['icon'] = 'uploads/' . $fn;
                    $oksave = save_services($dataFile, $services);
                    if ($oksave) {
                        $success = true;
                        $savedPath = 'uploads/' . $fn;
                        break 2;
                    } else {
                        // failed to save json, remove file
                        @unlink($target);
                        echo json_encode(['ok'=>false,'err'=>'保存 metadata 失败']); exit;
                    }
                }
            }
        }
    }

    if ($success) {
        echo json_encode(['ok'=>true,'icon'=>$savedPath]);
    } else {
        echo json_encode(['ok'=>false,'err'=>'全部尝试下载失败']);
    }
    exit;
}

// Upload icon for a specific service (与 save 中上传逻辑相同，但作为独立 action)
if ($action === 'upload_icon' && isset($_POST['id'])) {
    $services = load_services($dataFile);
    $id = (int)$_POST['id'];
    $found = false;
    foreach ($services as $k => $v) {
        if (($v['id'] ?? 0) == $id) {
            $found = true;
            $rowIndex = $k;
            break;
        }
    }
    if (!$found) { echo json_encode(['ok'=>false,'err'=>'未找到']); exit; }

    if (empty($_FILES['icon']) || $_FILES['icon']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok'=>false,'err'=>'未提供文件或上传出错']); exit;
    }
    $up = $_FILES['icon'];
    $ext = strtolower(pathinfo($up['name'], PATHINFO_EXTENSION));
    $allowed = ['png','jpg','jpeg','gif','svg','webp','ico'];
    if (!in_array($ext, $allowed)) {
        echo json_encode(['ok'=>false,'err'=>'不支持的图标格式']); exit;
    }
    $targetDir = __DIR__ . '/uploads';
    if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
    $fn = 'icon_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $targetDir . '/' . $fn;
    if (!move_uploaded_file($up['tmp_name'], $target)) {
        echo json_encode(['ok'=>false,'err'=>'上传失败']); exit;
    }
    $services[$rowIndex]['icon'] = 'uploads/' . $fn;
    $ok = save_services($dataFile, $services);
    if ($ok) echo json_encode(['ok'=>true,'icon'=>'uploads/' . $fn]);
    else {
        @unlink($target);
        echo json_encode(['ok'=>false,'err'=>'保存 metadata 失败']);
    }
    exit;
}

// 新增/更新记录（save） - 保持之前的逻辑（保留 icon 若未上传）
if ($action === 'save') {
    $services = load_services($dataFile);
    $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
    $name = trim($_POST['name'] ?? '');
    if ($name === '') { echo json_encode(['ok'=>false,'err'=>'名称不能为空']); exit; }
    $description = trim($_POST['description'] ?? '');
    $link_v6 = trim($_POST['link_v6'] ?? '');
    $link_v4 = trim($_POST['link_v4'] ?? '');
    $link_lan = trim($_POST['link_lan'] ?? '');
    $enabled = isset($_POST['enabled']) ? 1 : 0;

    // 处理上传文件（如果有）
    $uploaded_icon_path = '';
    if (!empty($_FILES['icon']) && $_FILES['icon']['error'] === UPLOAD_ERR_OK) {
        $up = $_FILES['icon'];
        $ext = strtolower(pathinfo($up['name'], PATHINFO_EXTENSION));
        $allowed = ['png','jpg','jpeg','gif','svg','webp'];
        if (!in_array($ext, $allowed)) {
            echo json_encode(['ok'=>false,'err'=>'不支持的图标格式']); exit;
        }
        $targetDir = __DIR__ . '/uploads';
        if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
        $fn = 'icon_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = $targetDir . '/' . $fn;
        if (!move_uploaded_file($up['tmp_name'], $target)) {
            echo json_encode(['ok'=>false,'err'=>'上传失败']); exit;
        }
        $uploaded_icon_path = 'uploads/' . $fn;
    }

    if ($id) {
        // 更新
        $found = false;
        foreach ($services as $k => $v) {
            if (($v['id'] ?? 0) == $id) {
                $found = true;
                $services[$k]['name'] = $name;
                $services[$k]['description'] = $description;
                $services[$k]['link_v6'] = $link_v6;
                $services[$k]['link_v4'] = $link_v4;
                $services[$k]['link_lan'] = $link_lan;
                $services[$k]['enabled'] = $enabled;
                if ($uploaded_icon_path !== '') {
                    $services[$k]['icon'] = $uploaded_icon_path;
                }
                break;
            }
        }
        if (!$found) { echo json_encode(['ok'=>false,'err'=>'未找到要更新的记录']); exit; }
    } else {
        // 新增：id 为 max+1，sort_order 自动放到末尾（max+1）
        $max = 0;
        foreach ($services as $v) if (($v['id'] ?? 0) > $max) $max = $v['id'];
        $new_sort = $max + 1;
        $new = [
            'id' => $max + 1,
            'name' => $name,
            'description' => $description,
            'icon' => $uploaded_icon_path !== '' ? $uploaded_icon_path : '',
            'link_v6' => $link_v6,
            'link_v4' => $link_v4,
            'link_lan' => $link_lan,
            'sort_order' => $new_sort,
            'enabled' => $enabled
        ];
        $services[] = $new;
    }

    $ok = save_services($dataFile, $services);
    echo json_encode(['ok'=>$ok]);
    exit;
}

// reorder - 保持原实现
if ($action === 'reorder') {
    $input = file_get_contents('php://input');
    $ids = null;
    if ($input) {
        $json = json_decode($input, true);
        if (is_array($json) && isset($json['order']) && is_array($json['order'])) {
            $ids = array_map('intval', $json['order']);
        } elseif (is_array($json) && !empty($json)) {
            $ids = array_map('intval', $json);
        }
    }
    if ($ids === null && isset($_POST['order']) && is_array($_POST['order'])) {
        $ids = array_map('intval', $_POST['order']);
    }
    if ($ids === null) {
        echo json_encode(['ok'=>false,'err'=>'未提供 order 列表']); exit;
    }

    $services = load_services($dataFile);
    $byId = [];
    foreach ($services as $k => $v) $byId[intval($v['id'])] = $k;

    $newServices = [];
    $orderIndex = 1;
    $usedIds = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $k = $byId[$id];
            $services[$k]['sort_order'] = $orderIndex++;
            $newServices[] = $services[$k];
            $usedIds[] = $id;
        }
    }
    foreach ($services as $v) {
        if (!in_array(intval($v['id']), $usedIds, true)) {
            $v['sort_order'] = $orderIndex++;
            $newServices[] = $v;
        }
    }

    $ok = save_services($dataFile, $newServices);
    echo json_encode(['ok'=>$ok]);
    exit;
}

echo json_encode(['ok'=>false,'err'=>'未知 action']);
exit;
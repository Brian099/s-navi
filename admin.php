<?php
// admin.php - 后台（JSON 存储）
// 已合并 admin.css 内容到 assets/style.css，所以这里只加载 assets/style.css
session_start();

// 确保会话安全（可选）
session_regenerate_id(true);

// 配置数据文件
$dataFile = __DIR__ . '/data/services.json';
if (!file_exists($dataFile)) {
    if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0755, true);
    file_put_contents($dataFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ==================== 主要修改部分开始 ====================

// 直接从环境变量获取管理员密码
// 方法1：使用 getenv() 读取环境变量
$ADMIN_PASS = getenv('ADMIN_PASSWORD');

// 方法2：使用 $_SERVER 读取（Docker 注入的环境变量也会在这里）
if (empty($ADMIN_PASS) && isset($_SERVER['ADMIN_PASSWORD'])) {
    $ADMIN_PASS = $_SERVER['ADMIN_PASSWORD'];
}

// 方法3：如果都没有设置，使用默认密码（仅用于开发）
if (empty($ADMIN_PASS)) {
    // 这里可以设置一个安全的默认密码，或者直接禁止访问
    $ADMIN_PASS = 'changeme'; // 生产环境务必通过环境变量设置
    
    // 或者直接显示错误信息并退出
    // die('管理员密码未配置，请设置 ADMIN_PASSWORD 环境变量');
}

// 安全建议：在生产环境强制要求设置密码
if ($ADMIN_PASS === 'changeme') {
    // 可以记录日志或显示警告
    error_log('警告：正在使用默认管理员密码，请在生产环境设置 ADMIN_PASSWORD 环境变量');
}

// ==================== 主要修改部分结束 ====================

// 登录处理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    if (isset($_POST['password']) && $_POST['password'] === $ADMIN_PASS) {
        // 如果密码使用哈希存储（推荐）
        $_SESSION['admin'] = true;
        $_SESSION['login_time'] = time();
        header('Location: admin.php');
        exit;
    } else {
        $err = "密码错误";
        // 记录失败尝试（可选）
        error_log('管理员登录失败：' . $_SERVER['REMOTE_ADDR']);
    }
}

// 增加会话超时（可选）
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > 3600)) {
    // 1小时后会话过期
    session_destroy();
    header('Location: admin.php');
    exit;
}

// 登出处理
if (!empty($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// 如果不是登录状态，显示登录表单
if (!isset($_SESSION['admin'])) {
    // 登录表单
    ?>
    <!doctype html>
    <html lang="zh-CN">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>管理登录</title>
        <link rel="stylesheet" href="assets/style.css">
    </head>
    <body class="admin-login" style="
			background: linear-gradient(-45deg, #6b1db5, #000000, #9f054e, #8a2be2);
            background-size: 200% 200%;
            animation: gradientBG 15s ease infinite;">
      <div class="login-box">
        <h2>后台登录</h2>
        <?php 
        if (!empty($err)) {
            echo "<p class='error'>".htmlspecialchars($err)."</p>";
        }
        
        // 显示提示信息（如果使用默认密码）
        if ($ADMIN_PASS === 'changeme') {
            echo "<p class='warning'>警告：正在使用默认密码，请设置 ADMIN_PASSWORD 环境变量</p>";
        }
        ?>
        <form method="post">
          <input type="hidden" name="action" value="login">
          <div>
            <input type="password" name="password" placeholder="请输入管理员密码" required autofocus>
          </div>
          <div>
            <button type="submit">登录</button>
          </div>
        </form>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// 已登录，读取 services
$raw = file_get_contents($dataFile);
$services = json_decode($raw, true);
if (!is_array($services)) $services = [];
usort($services, function($a,$b){
    $sa = $a['sort_order'] ?? 0; $sb = $b['sort_order'] ?? 0;
    if ($sa === $sb) return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
    return $sa <=> $sb;
});
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>导航管理</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body style="
			background: linear-gradient(-45deg, #6b1db5, #000000, #9f054e, #8a2be2);
            background-size: 200% 200%;
            animation: gradientBG 15s ease infinite;">
  <header class="topbar admin-top">
    <div class="brand"><h1>导航管理</h1></div>
    <div>
      <a href="index.php" target="_blank">查看前台</a> | 
      <a href="?logout=1">登出</a> | 
      <span class="muted">登录时间：<?php echo date('Y-m-d H:i:s', $_SESSION['login_time'] ?? time()); ?></span>
    </div>
  </header>

  <main class="container admin">
    <section class="admin-actions">
      <div>
        <button id="btn-new" class="primary">新增服务</button>
        <button id="save-order-btn">保存排序</button>
      </div>
      <p class="muted">注：拖拽表格行改变排序,点击状态可切换启用/停用</p>
    </section>

    <section>
      <table class="admin-table">
        <thead><tr>
          <th>ID</th>
          <th>图标</th>
          <th>名称</th>
          <th>描述</th>
          <th>v6</th>
          <th>v4</th>
          <th>局域网</th>
          <th>状态</th>
          <th>操作</th>
        </tr></thead>
        <tbody id="svc-list">
          <?php foreach($services as $s): ?>
            <tr data-id="<?=htmlspecialchars($s['id'])?>" draggable="true">
              <td><?=htmlspecialchars($s['id'])?></td>
              <td><img src="<?=htmlspecialchars($s['icon'] ?: 'assets/sample/icon-placeholder.png')?>" class="thumb" id="icon-<?=htmlspecialchars($s['id'])?>"></td>
              <td><?=htmlspecialchars($s['name'])?></td>
              <td class="mono"><?=htmlspecialchars($s['description'])?></td>
              <td class="mono"><?=htmlspecialchars($s['link_v6'])?></td>
              <td class="mono"><?=htmlspecialchars($s['link_v4'])?></td>
              <td class="mono"><?=htmlspecialchars($s['link_lan'])?></td>
              <td>
                <?php if (!empty($s['enabled'])): ?>
                  <span class="status-badge enabled" data-id="<?=htmlspecialchars($s['id'])?>">启用</span>
                <?php else: ?>
                  <span class="status-badge disabled" data-id="<?=htmlspecialchars($s['id'])?>">禁用</span>
                <?php endif; ?>
              </td>
              <td>
                <button class="edit-btn" data-id="<?=htmlspecialchars($s['id'])?>">编辑</button>
                <button class="del-btn" data-id="<?=htmlspecialchars($s['id'])?>">删除</button>
                <!-- 新增：获取图标 / 上传图标 -->
                <button class="fetch-icon-btn" data-id="<?=htmlspecialchars($s['id'])?>">获取图标</button>
                <button class="upload-icon-btn" data-id="<?=htmlspecialchars($s['id'])?>">上传图标</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  </main>

  <!-- Modal overlay & modal（overlay 默认隐藏，通过 .show 控制显示） -->
  <div id="modal-overlay" class="modal-overlay" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
      <header class="modal-header">
        <h3 id="modal-title">新增服务</h3>
        <button id="modal-close" class="modal-close" aria-label="关闭">&times;</button>
      </header>
      <div class="modal-body">
        <form id="svc-form" enctype="multipart/form-data">
          <input type="hidden" name="id" value="">
          <div class="form-row"><label>名称 <input name="name" required></label></div>
          <div class="form-row"><label>描述 <input name="description"></label></div>

          <!-- 注意：图标文件输入已移出弹窗（改为行内上传按钮） -->

          <div class="form-row"><label>v6 链接 <input name="link_v6"></label></div>
          <div class="form-row"><label>v4 链接 <input name="link_v4"></label></div>
          <div class="form-row"><label>局域网 链接 <input name="link_lan"></label></div>

          <div class="form-row"><label style="display: block;">启用 <input name="enabled" type="checkbox" checked></label></div>

          <div class="form-actions">
            <button type="submit" class="primary">保存</button>
            <button type="button" id="modal-reset">重置</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="assets/app.js"></script>
  <script>
    // 后台 AJAX 状态切换由 assets/app.js 处理
  </script>
</body>
</html>
<?php
// index.php - 前台导航页面（JSON 存储）
// 直接从环境变量读取服务器名称

/**
 * 检查名称是否有效
 */
function isValidServerName($value) {
    return isset($value) && 
           $value !== '' && 
           $value !== '_' && 
           $value !== null &&
           trim($value) !== '';
}

/**
 * 获取服务器名称，优先级：
 * 1. NAV_SERVER_NAME 环境变量
 * 2. SERVER_NAME 环境变量
 * 3. 默认值 'BrianServer'
 */
function getServerName() {
    // 方法1：从环境变量读取（Docker注入）
    $envNav = getenv('NAV_SERVER_NAME');
    if (isValidServerName($envNav)) {
        return trim($envNav);
    }
    
    // 方法2：尝试从 $_SERVER 读取
    if (isset($_SERVER['NAV_SERVER_NAME']) && isValidServerName($_SERVER['NAV_SERVER_NAME'])) {
        return trim($_SERVER['NAV_SERVER_NAME']);
    }
    
    // 方法3：尝试 SERVER_NAME 环境变量
    $envSrv = getenv('SERVER_NAME');
    if (isValidServerName($envSrv)) {
        return trim($envSrv);
    }
    
    if (isset($_SERVER['SERVER_NAME']) && isValidServerName($_SERVER['SERVER_NAME'])) {
        return trim($_SERVER['SERVER_NAME']);
    }
    
    // 方法4：使用默认值
    return 'BrianServer';
}

// 获取服务器名称
$serverName = getServerName();

// 读取服务数据
$dataFile = __DIR__ . '/data/services.json';
if (!file_exists($dataFile)) {
    if (!is_dir(__DIR__ . '/data')) {
        mkdir(__DIR__ . '/data', 0755, true);
    }
    file_put_contents($dataFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$raw = file_get_contents($dataFile);
$services = json_decode($raw, true);
if (!is_array($services)) {
    $services = [];
}

// 排序和筛选启用的服务
usort($services, function($a, $b) {
    $sa = $a['sort_order'] ?? 0;
    $sb = $b['sort_order'] ?? 0;
    if ($sa === $sb) {
        return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
    }
    return $sa <=> $sb;
});

$services = array_filter($services, function($s) {
    return ($s['enabled'] ?? 0) == 1;
});
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars($serverName)?> - 导航</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="<?=htmlspecialchars($serverName)?> 导航页面">
  <link rel="stylesheet" href="assets/style.css">
  
  <?php
  // 可选：从环境变量读取主题色或自定义CSS
  $themeColor = getenv('THEME_COLOR') ?: '#2c3e50';
  $customCss = getenv('CUSTOM_CSS') ?: '';
  ?>
  <style>
    :root {
      --primary-color: <?=htmlspecialchars($themeColor)?>;
    }
    <?php if ($customCss): ?>
    /* 自定义CSS（如果通过环境变量设置） */
    <?=htmlspecialchars($customCss)?>
    <?php endif; ?>
  </style>
</head>
<body>
  <div class="bg"></div>

  <!-- 右上角单独一行的模式切换 -->
  <div class="mode-top-right">
    <button data-mode="v6" class="mode-btn">V6</button>
    <button data-mode="v4" class="mode-btn">V4</button>
    <button data-mode="lan" class="mode-btn">局域网</button>
  </div>

  <!-- 中间独立一行：服务器名称与时间 -->
  <header class="hero-row">
    <div class="hero">
      <h1 id="server-name"><?=htmlspecialchars($serverName)?></h1>
      <p class="dot">|</p>
      <div class="clock-box" aria-hidden="false">
        <div id="time" class="time">--:--:--</div>
        <div id="date" class="date">----/--/--</div>
      </div>
    </div>
  </header>

  <main class="container">
    <section class="apps">
      <div class="grid">
        <?php foreach ($services as $s): ?>
          <?php
            $icon = 'assets/sample/icon-placeholder.png';
            if (!empty($s['icon'])) {
                // 判断是否是完整URL
                if (strpos($s['icon'], 'http://') === 0 || strpos($s['icon'], 'https://') === 0) {
                    $icon = $s['icon'];
                } 
                // 判断是否是base64编码的图片
                elseif (strpos($s['icon'], 'data:image/') === 0) {
                    $icon = $s['icon'];
                }
                // 检查本地文件是否存在
                elseif (file_exists(__DIR__ . '/' . $s['icon'])) {
                    $icon = $s['icon'];
                }
            }
          ?>
          <div class="card" 
               data-id="<?=htmlspecialchars($s['id'])?>"
               data-v6="<?=htmlspecialchars($s['link_v6'] ?? '')?>"
               data-v4="<?=htmlspecialchars($s['link_v4'] ?? '')?>"
               data-lan="<?=htmlspecialchars($s['link_lan'] ?? '')?>"
               aria-label="<?=htmlspecialchars($s['name'] ?? '服务')?>">
            <img class="icon" 
                 src="<?=htmlspecialchars($icon)?>" 
                 alt="<?=htmlspecialchars($s['name'] ?? '')?> 图标"
                 loading="lazy">
            <div class="meta">
              <div class="title"><?=htmlspecialchars($s['name'] ?? '')?></div>
              <div class="desc"><?=htmlspecialchars($s['description'] ?? '')?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </main>

  <footer class="foot">
    <small>Designed by Brian • Made by AI <a href="admin.php">管理</a></small>
  </footer>

  <script src="assets/app.js"></script>
  <script>
    // 服务器名称显示处理
    document.addEventListener('DOMContentLoaded', function() {
        const serverNameElement = document.getElementById('server-name');
        
        // 从环境变量获取（前端也可通过其他方式获取）
        const envServerName = '<?=htmlspecialchars($serverName)?>';
        
        // 如果有自定义标题，可以在这里处理
        const pageTitle = '<?=htmlspecialchars($serverName)?> - 导航';
        document.title = pageTitle;
        
        // 点击卡片，根据模式打开对应链接
        document.querySelectorAll('.card').forEach(card => {
            card.addEventListener('click', () => {
                const mode = localStorage.getItem('nav_mode') || 'v6';
                const url = card.dataset[mode];
                
                if (!url) {
                    alert('此服务尚未填写对应链接');
                    return;
                }
                
                // 在新窗口打开链接
                window.open(url, '_blank');
            });
            
            // 添加键盘支持
            card.addEventListener('keypress', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    card.click();
                }
            });
        });
        
        // 模式按钮事件（确保app.js中有相关功能）
        document.querySelectorAll('.mode-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const mode = this.dataset.mode;
                localStorage.setItem('nav_mode', mode);
                
                // 更新按钮状态
                document.querySelectorAll('.mode-btn').forEach(b => {
                    b.classList.toggle('active', b.dataset.mode === mode);
                });
            });
        });
        
        // 初始设置模式按钮状态
        const currentMode = localStorage.getItem('nav_mode') || 'v6';
        document.querySelectorAll('.mode-btn').forEach(btn => {
            if (btn.dataset.mode === currentMode) {
                btn.classList.add('active');
            }
        });
    });
  </script>
</body>
</html>
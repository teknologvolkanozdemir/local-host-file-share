<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

$config = require __DIR__ . '/config.php';
$error = '';
$notice = '';
$db = null;

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function database(array $config): PDO
{
    return new PDO(
        'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
        $config['db_user'],
        $config['db_password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function can(string $permission): bool
{
    return !empty($_SESSION['user']['is_owner']) || !empty($_SESSION['user'][$permission]);
}

function requirePermission(string $permission): void
{
    if (!can($permission)) {
        http_response_code(403);
        throw new RuntimeException('Bu işlem için yetkiniz yok.');
    }
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function redirectHome(): never
{
    header('Location: index.php');
    exit;
}

function textExtension(string $name): bool
{
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), [
        'txt', 'rtf', 'md', 'csv', 'json', 'xml', 'log', 'html', 'htm',
        'css', 'js', 'php', 'yaml', 'yml', 'ini', 'conf', 'sql',
    ], true);
}

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

try {
    $db = database($config);
    $userCount = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable $exception) {
    $userCount = 0;
    $error = 'Veritabanına bağlanılamadı. README dosyasındaki kurulum adımlarını ve veritabanı ayarlarını kontrol edin.';
}

if ($db instanceof PDO && isset($_GET['download'], $_SESSION['user'])) {
    try {
        $statement = $db->prepare('SELECT original_name, storage_name FROM files WHERE id = ?');
        $statement->execute([(int)$_GET['download']]);
        $file = $statement->fetch();
        if (!$file) {
            http_response_code(404);
            exit('Dosya bulunamadı.');
        }
        $path = $config['storage_path'] . DIRECTORY_SEPARATOR . $file['storage_name'];
        if (!is_file($path)) {
            http_response_code(404);
            exit('Dosya diskte bulunamadı.');
        }
        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($path));
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($file['original_name']));
        readfile($path);
        exit;
    } catch (Throwable $exception) {
        http_response_code(500);
        exit('Dosya indirilemedi.');
    }
}

if ($db instanceof PDO && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Oturum doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.');
        }
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'setup' && $userCount === 0) {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if (!preg_match('/^[\pL\pN_.-]{3,80}$/u', $username) || strlen($password) < 12) {
                throw new RuntimeException('Kullanıcı adı 3-80 karakter olmalı; parola en az 12 karakter olmalıdır.');
            }
            $statement = $db->prepare('INSERT INTO users (username, password_hash, is_owner) VALUES (?, ?, 1)');
            $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$db->lastInsertId(), 'username' => $username, 'is_owner' => 1];
            redirectHome();
        }

        if ($action === 'login' && $userCount > 0 && !isset($_SESSION['user'])) {
            $statement = $db->prepare('SELECT * FROM users WHERE username = ?');
            $statement->execute([trim((string)($_POST['username'] ?? ''))]);
            $user = $statement->fetch();
            if (!$user || !password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
                throw new RuntimeException('Kullanıcı adı veya parola hatalı.');
            }
            session_regenerate_id(true);
            unset($user['password_hash']);
            $_SESSION['user'] = $user;
            redirectHome();
        }

        if ($action === 'logout') {
            unset($_SESSION['user']);
            session_regenerate_id(true);
            redirectHome();
        }

        if (!isset($_SESSION['user'])) {
            throw new RuntimeException('Devam etmek için oturum açın.');
        }

        if ($action === 'upload') {
            requirePermission('can_upload');
            $upload = $_FILES['file'] ?? null;
            if (!$upload || $upload['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Dosya yüklenemedi. Dosya boyutunu ve PHP yükleme sınırlarını kontrol edin.');
            }
            if ($upload['size'] > $config['max_upload_bytes']) {
                throw new RuntimeException('Dosya boyutu 100 MB sınırını aşıyor.');
            }
            $name = basename(str_replace('\\', '/', (string)$upload['name']));
            if ($name === '' || strlen($name) > 255 || str_contains($name, "\0")) {
                throw new RuntimeException('Dosya adı geçersiz veya çok uzun.');
            }
            if (!is_dir($config['storage_path']) && !mkdir($config['storage_path'], 0700, true) && !is_dir($config['storage_path'])) {
                throw new RuntimeException('Güvenli dosya alanı oluşturulamadı.');
            }
            $storageName = bin2hex(random_bytes(32));
            $destination = $config['storage_path'] . DIRECTORY_SEPARATOR . $storageName;
            if (!move_uploaded_file($upload['tmp_name'], $destination)) {
                throw new RuntimeException('Dosya güvenli alana taşınamadı.');
            }
            try {
                $statement = $db->prepare('INSERT INTO files (original_name, storage_name, uploaded_by) VALUES (?, ?, ?)');
                $statement->execute([$name, $storageName, $_SESSION['user']['id']]);
            } catch (Throwable $exception) {
                @unlink($destination);
                throw $exception;
            }
            $notice = 'Dosya çalışma alanına yüklendi.';
        } elseif ($action === 'delete_file') {
            requirePermission('can_delete');
            $statement = $db->prepare('SELECT storage_name FROM files WHERE id = ?');
            $statement->execute([(int)($_POST['file_id'] ?? 0)]);
            $file = $statement->fetch();
            if (!$file) {
                throw new RuntimeException('Dosya bulunamadı.');
            }
            $db->prepare('DELETE FROM files WHERE id = ?')->execute([(int)$_POST['file_id']]);
            @unlink($config['storage_path'] . DIRECTORY_SEPARATOR . $file['storage_name']);
            $notice = 'Dosya silindi.';
        } elseif ($action === 'save_text') {
            requirePermission('can_edit');
            $id = (int)($_POST['file_id'] ?? 0);
            $statement = $db->prepare('SELECT original_name, storage_name FROM files WHERE id = ?');
            $statement->execute([$id]);
            $file = $statement->fetch();
            if (!$file || !textExtension($file['original_name'])) {
                throw new RuntimeException('Bu dosya türü metin düzenleyicide düzenlenemez.');
            }
            $content = (string)($_POST['content'] ?? '');
            if (strlen($content) > $config['max_edit_bytes']) {
                throw new RuntimeException('Düzenlenebilir dosyalar en fazla 2 MB olabilir.');
            }
            $path = $config['storage_path'] . DIRECTORY_SEPARATOR . $file['storage_name'];
            if (file_put_contents($path, $content, LOCK_EX) === false) {
                throw new RuntimeException('Dosya kaydedilemedi.');
            }
            $notice = 'Dosya kaydedildi.';
        } elseif ($action === 'create_note') {
            requirePermission('can_edit');
            $name = trim((string)($_POST['note_name'] ?? ''));
            if ($name === '') {
                $name = 'not-' . date('Ymd-His') . '.txt';
            }
            if (strlen($name) > 255 || !textExtension($name) || basename($name) !== $name) {
                throw new RuntimeException('Not adı geçersiz. Desteklenen metin türlerinden bir uzantı kullanın.');
            }
            $content = (string)($_POST['content'] ?? '');
            if (strlen($content) > $config['max_edit_bytes']) {
                throw new RuntimeException('Not en fazla 2 MB olabilir.');
            }
            if (!is_dir($config['storage_path']) && !mkdir($config['storage_path'], 0700, true) && !is_dir($config['storage_path'])) {
                throw new RuntimeException('Güvenli dosya alanı oluşturulamadı.');
            }
            $storageName = bin2hex(random_bytes(32));
            $path = $config['storage_path'] . DIRECTORY_SEPARATOR . $storageName;
            if (file_put_contents($path, $content, LOCK_EX) === false) {
                throw new RuntimeException('Not oluşturulamadı.');
            }
            try {
                $statement = $db->prepare('INSERT INTO files (original_name, storage_name, uploaded_by) VALUES (?, ?, ?)');
                $statement->execute([$name, $storageName, $_SESSION['user']['id']]);
            } catch (Throwable $exception) {
                @unlink($path);
                throw $exception;
            }
            $notice = 'Yeni metin dosyası oluşturuldu.';
        } elseif ($action === 'create_user') {
            requirePermission('can_manage_users');
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if (!preg_match('/^[\pL\pN_.-]{3,80}$/u', $username) || strlen($password) < 12) {
                throw new RuntimeException('Kullanıcı adı 3-80 karakter olmalı; parola en az 12 karakter olmalıdır.');
            }
            $statement = $db->prepare(
                'INSERT INTO users (username, password_hash, can_manage_users, can_upload, can_edit, can_delete)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                isset($_POST['can_manage_users']) ? 1 : 0,
                isset($_POST['can_upload']) ? 1 : 0,
                isset($_POST['can_edit']) ? 1 : 0,
                isset($_POST['can_delete']) ? 1 : 0,
            ]);
            $notice = 'Kullanıcı oluşturuldu.';
        } elseif ($action === 'delete_user') {
            requirePermission('can_manage_users');
            $id = (int)($_POST['user_id'] ?? 0);
            if ($id === (int)$_SESSION['user']['id']) {
                throw new RuntimeException('Kendi hesabınızı buradan silemezsiniz.');
            }
            $statement = $db->prepare('DELETE FROM users WHERE id = ? AND is_owner = 0');
            $statement->execute([$id]);
            if ($statement->rowCount() === 0) {
                throw new RuntimeException('Kullanıcı bulunamadı veya ana kullanıcı silinemez.');
            }
            $notice = 'Kullanıcı hesabı silindi.';
        } elseif (!in_array($action, ['upload', 'delete_file', 'save_text', 'create_note', 'create_user', 'delete_user'], true)) {
            throw new RuntimeException('İstenen işlem tanınmadı.');
        }
    } catch (Throwable $exception) {
        $error = $exception instanceof PDOException && (string)$exception->getCode() === '23000'
            ? 'Bu kullanıcı adı zaten kullanılıyor.'
            : $exception->getMessage();
    }
}

$loggedIn = isset($_SESSION['user']);
$editing = null;
$editContent = '';
if ($loggedIn && $db instanceof PDO && isset($_GET['edit']) && can('can_edit')) {
    $statement = $db->prepare('SELECT id, original_name, storage_name FROM files WHERE id = ?');
    $statement->execute([(int)$_GET['edit']]);
    $editing = $statement->fetch();
    if ($editing && textExtension($editing['original_name'])) {
        $path = $config['storage_path'] . DIRECTORY_SEPARATOR . $editing['storage_name'];
        if (is_file($path) && filesize($path) <= $config['max_edit_bytes']) {
            $editContent = (string)file_get_contents($path);
        } elseif (is_file($path)) {
            $error = '2 MB üzerindeki dosyalar metin düzenleyicide açılamaz.';
            $editing = null;
        }
    } else {
        $editing = null;
    }
}

$files = [];
$users = [];
if ($loggedIn && $db instanceof PDO) {
    $files = $db->query(
        'SELECT files.id, files.original_name, files.created_at, users.username
         FROM files LEFT JOIN users ON users.id = files.uploaded_by ORDER BY files.created_at DESC'
    )->fetchAll();
    if (can('can_manage_users')) {
        $users = $db->query('SELECT id, username, is_owner, can_manage_users, can_upload, can_edit, can_delete FROM users ORDER BY is_owner DESC, username')->fetchAll();
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ortak Çalışma Alanı</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/app.js" defer></script>
</head>
<body>
<main class="container">
    <header class="topbar">
        <div><p class="eyebrow">YEREL AĞ DOSYA PAYLAŞIMI</p><h1>Ortak çalışma alanı</h1></div>
        <?php if ($loggedIn): ?>
            <form method="post" class="logout"><?php echo csrfField(); ?><input type="hidden" name="action" value="logout"><button class="button secondary">Çıkış · <?php echo h($_SESSION['user']['username']); ?></button></form>
        <?php endif; ?>
    </header>

    <?php if ($error): ?><p class="message error" role="alert"><?php echo h($error); ?></p><?php endif; ?>
    <?php if ($notice): ?><p class="message success" role="status"><?php echo h($notice); ?></p><?php endif; ?>

    <?php if (!$db instanceof PDO): ?>
        <section class="card"><h2>Kurulum gerekli</h2><p>Veritabanını oluşturup <code>database.sql</code> dosyasını içe aktarın; ardından README dosyasındaki veritabanı ayarlarını yapın.</p></section>
    <?php elseif ($userCount === 0): ?>
        <section class="card narrow">
            <h2>Ana kullanıcı hesabını oluşturun</h2>
            <p>İlk oluşturulan hesap çalışma alanının sahibidir. Güçlü ve benzersiz bir parola seçin.</p>
            <form method="post" class="stack">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="setup">
                <label>Kullanıcı adı<input name="username" required minlength="3" maxlength="80" autocomplete="username"></label>
                <label>Parola<input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
                <button class="button">Ana hesabı oluştur</button>
            </form>
        </section>
    <?php elseif (!$loggedIn): ?>
        <section class="card narrow">
            <h2>Oturum açın</h2>
            <form method="post" class="stack">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="login">
                <label>Kullanıcı adı<input name="username" required autocomplete="username"></label>
                <label>Parola<input type="password" name="password" required autocomplete="current-password"></label>
                <button class="button">Oturum aç</button>
            </form>
        </section>
    <?php else: ?>
        <p class="intro">Aynı ağdaki ekip üyeleri dosyaları güvenli alanda paylaşabilir, indirebilir ve yetkileri ölçüsünde düzenleyebilir.</p>
        <section class="card">
            <div class="section-heading"><div><h2>Dosyalar</h2><p>Her tür dosya uzantısı yüklenebilir ve indirilebilir.</p></div></div>
            <?php if (can('can_upload')): ?>
                <form method="post" enctype="multipart/form-data" class="upload-form">
                    <?php echo csrfField(); ?><input type="hidden" name="action" value="upload">
                    <label class="file-picker">Dosya seçin<input type="file" name="file" required></label>
                    <button class="button">Dosyayı yükle</button><span class="hint">En fazla 100 MB</span>
                </form>
            <?php endif; ?>
            <?php if (can('can_edit')): ?>
                <details class="new-note">
                    <summary>Metin dosyası / zengin not oluştur</summary>
                    <form method="post" class="stack note-form">
                        <?php echo csrfField(); ?><input type="hidden" name="action" value="create_note">
                        <label>Dosya adı<input name="note_name" placeholder="not.html veya not.txt" maxlength="255"></label>
                        <div class="toolbar" aria-label="Metin biçimlendirme">
                            <button type="button" data-command="bold" aria-label="Kalın"><strong>B</strong></button>
                            <button type="button" data-command="italic" aria-label="İtalik"><em>I</em></button>
                            <button type="button" data-command="underline" aria-label="Altı çizili"><u>U</u></button>
                            <button type="button" data-command="insertUnorderedList" aria-label="Madde işaretli liste">• Liste</button>
                            <button type="button" data-command="insertOrderedList" aria-label="Numaralı liste">1. Liste</button>
                        </div>
                        <div class="rich-editor" contenteditable="true" role="textbox" aria-label="Yeni metin içeriği" aria-multiline="true"></div>
                        <textarea name="content" class="editor-source" aria-label="Dosya içeriği"></textarea>
                        <p class="hint">Biçimlendirme HTML dosyalarında saklanır. .txt ve .rtf dosyalarında içerik düz metin olarak saklanır.</p>
                        <button class="button">Dosya oluştur</button>
                    </form>
                </details>
            <?php endif; ?>
            <?php if (!$files): ?>
                <p class="empty">Henüz paylaşılmış dosya yok.</p>
            <?php else: ?>
                <div class="table-wrap"><table>
                    <thead><tr><th>Dosya adı</th><th>Yükleyen</th><th>Tarih</th><th>İşlemler</th></tr></thead>
                    <tbody><?php foreach ($files as $file): ?>
                        <tr>
                            <td data-label="Dosya adı"><?php echo h($file['original_name']); ?></td>
                            <td data-label="Yükleyen"><?php echo h($file['username'] ?? 'Silinmiş kullanıcı'); ?></td>
                            <td data-label="Tarih"><?php echo h(date('Y-m-d H:i', strtotime($file['created_at']))); ?></td>
                            <td class="actions" data-label="İşlemler">
                                <a class="button small secondary" href="?download=<?php echo (int)$file['id']; ?>">İndir</a>
                                <?php if (can('can_edit') && textExtension($file['original_name'])): ?><a class="button small secondary" href="?edit=<?php echo (int)$file['id']; ?>">Düzenle</a><?php endif; ?>
                                <?php if (can('can_delete')): ?><form method="post" data-confirm="Bu dosya kalıcı olarak silinsin mi?"><?php echo csrfField(); ?><input type="hidden" name="action" value="delete_file"><input type="hidden" name="file_id" value="<?php echo (int)$file['id']; ?>"><button class="button small danger">Sil</button></form><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>

        <?php if ($editing): ?>
            <section class="card">
                <div class="section-heading"><div><h2>Metin düzenleyici</h2><p><?php echo h($editing['original_name']); ?></p></div><a href="index.php" class="button secondary">Kapat</a></div>
                <form method="post" class="stack">
                    <?php echo csrfField(); ?><input type="hidden" name="action" value="save_text"><input type="hidden" name="file_id" value="<?php echo (int)$editing['id']; ?>">
                    <label for="file-content">Dosya içeriği</label><textarea id="file-content" name="content" class="source-editor"><?php echo h($editContent); ?></textarea>
                    <p class="hint">Düz metin ve RTF dahil desteklenen metin dosyaları; en fazla 2 MB.</p><button class="button">Değişiklikleri kaydet</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if (can('can_manage_users')): ?>
            <section class="card">
                <h2>Kullanıcı hesapları ve yetkiler</h2>
                <p>Ana kullanıcı diğer hesapları ve dosya işlemi yetkilerini belirler.</p>
                <form method="post" class="stack user-create">
                    <?php echo csrfField(); ?><input type="hidden" name="action" value="create_user">
                    <label>Kullanıcı adı<input name="username" required minlength="3" maxlength="80"></label>
                    <label>Başlangıç parolası<input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
                    <fieldset><legend>Yetkiler</legend>
                        <label class="check"><input type="checkbox" name="can_manage_users"> Hesap oluşturma / silme</label>
                        <label class="check"><input type="checkbox" name="can_upload"> Dosya yükleme</label>
                        <label class="check"><input type="checkbox" name="can_edit"> Metin düzenleme ve oluşturma</label>
                        <label class="check"><input type="checkbox" name="can_delete"> Dosya silme</label>
                    </fieldset>
                    <button class="button">Hesap oluştur</button>
                </form>
                <div class="table-wrap"><table><thead><tr><th>Kullanıcı</th><th>Yetkiler</th><th>İşlem</th></tr></thead><tbody>
                    <?php foreach ($users as $user): ?><tr><td><?php echo h($user['username']); ?><?php if ($user['is_owner']): ?> <span class="badge">Ana kullanıcı</span><?php endif; ?></td><td><?php echo $user['is_owner'] ? 'Tüm yetkiler' : h(implode(', ', array_filter([
                        $user['can_manage_users'] ? 'hesap yönetimi' : '',
                        $user['can_upload'] ? 'yükleme' : '',
                        $user['can_edit'] ? 'düzenleme' : '',
                        $user['can_delete'] ? 'silme' : '',
                    ])) ?: 'Yetki yok'); ?></td><td>
                        <?php if (!$user['is_owner']): ?><form method="post" data-confirm="Bu kullanıcı hesabı silinsin mi?"><?php echo csrfField(); ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>"><button class="button small danger">Hesabı sil</button></form><?php endif; ?>
                    </td></tr><?php endforeach; ?>
                </tbody></table></div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
    <footer>Dosyalar web kök dizininin dışında saklanır; bu çalışma alanını yalnızca güvendiğiniz yerel ağda kullanın.</footer>
</main>
</body>
</html>

<?php
declare(strict_types=1);

namespace EPejabat\Core;

final class PortalSettings
{
    public const PERMISSION = 'core.portal.manage';

    private const LIMITS = [
        'HERO' => 1,
        'ACTIVITY' => 6,
        'QUICK_LINK' => 4,
    ];

    /**
     * Defaults keep the public portal usable while a fresh database is being
     * prepared. The migration seeds the same values as the editable source of
     * truth.
     */
    public static function defaults(): array
    {
        return [
            'HERO' => [
                1 => [
                    'content_type' => 'HERO',
                    'slot' => 1,
                    'title' => 'Kesihatan komuniti, diperkasa secara digital.',
                    'caption' => 'Satu pintu masuk untuk program, borang dan perkhidmatan digital PKD Kota Bharu.',
                    'image_path' => '/assets/images/portal/hero-default.svg',
                    'target_url' => '/training/portal',
                    'is_active' => 1,
                ],
            ],
            'ACTIVITY' => [
                1 => self::defaultItem('ACTIVITY', 1, 'Program & latihan', 'Temui program terkini untuk warga kesihatan.', '/training/portal'),
                2 => self::defaultItem('ACTIVITY', 2, 'Daftar program', 'Daftar penyertaan dan urus rekod program anda.', '/training'),
                3 => self::defaultItem('ACTIVITY', 3, 'Semak status', 'Semak status permohonan melalui portal awam.', '/public-program.php'),
                4 => self::defaultItem('ACTIVITY', 4, 'Borang digital', 'Akses borang dan permohonan dalam talian.', '/eforms'),
                5 => self::defaultItem('ACTIVITY', 5, 'Direktori staf', 'Cari maklumat organisasi dan perkhidmatan staf.', '/staff'),
                6 => self::defaultItem('ACTIVITY', 6, 'Akaun saya', 'Urus profil, keselamatan dan rekod peribadi.', '/profile'),
            ],
            'QUICK_LINK' => [
                1 => self::defaultItem('QUICK_LINK', 1, 'Portal Awam', '', '/training/portal', ''),
                2 => self::defaultItem('QUICK_LINK', 2, 'Daftar Program', '', '/training', ''),
                3 => self::defaultItem('QUICK_LINK', 3, 'Senarai Program', '', '/training', ''),
                4 => self::defaultItem('QUICK_LINK', 4, 'Borang Digital', '', '/eforms', ''),
            ],
        ];
    }

    private static function defaultItem(string $type, int $slot, string $title, string $caption, string $url, string $image = '/assets/images/portal/activity-default.svg'): array
    {
        return [
            'content_type' => $type,
            'slot' => $slot,
            'title' => $title,
            'caption' => $caption,
            'image_path' => $image,
            'target_url' => $url,
            'is_active' => 1,
        ];
    }

    public static function available(\PDO $p): bool
    {
        return (bool) Database::query(
            $p,
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            ['portal_banners']
        )->fetchColumn();
    }

    public static function rows(\PDO $p, string $type): array
    {
        if (!isset(self::LIMITS[$type])) {
            throw new \InvalidArgumentException('Jenis kandungan portal tidak sah.');
        }

        $rows = self::defaults()[$type];
        if (self::available($p)) {
            foreach (Database::query(
                $p,
                'SELECT content_type,slot,title,caption,image_path,target_url,is_active,updated_at FROM portal_banners WHERE content_type=? ORDER BY slot',
                [$type]
            )->fetchAll() as $row) {
                $slot = (int) $row['slot'];
                if ($slot >= 1 && $slot <= self::LIMITS[$type]) {
                    $rows[$slot] = array_replace($rows[$slot], $row, ['slot' => $slot]);
                }
            }
        }

        ksort($rows);
        return array_values($rows);
    }

    public static function save(\PDO $p, int $actor, array $data, array $file): void
    {
        Authorization::require($p, $actor, self::PERMISSION);
        if (!self::available($p)) {
            throw new \RuntimeException('Jadual portal belum tersedia. Jalankan migrasi sistem terlebih dahulu.');
        }

        $type = Core::text($data['content_type'] ?? '', 20);
        if (!isset(self::LIMITS[$type])) {
            throw new \InvalidArgumentException('Jenis kandungan portal tidak sah.');
        }
        $slot = Core::id($data['slot'] ?? null);
        if ($slot > self::LIMITS[$type]) {
            throw new \InvalidArgumentException('Slot kandungan portal tidak sah.');
        }

        $title = Core::text($data['title'] ?? '', $type === 'QUICK_LINK' ? 80 : 190);
        $caption = Core::text($data['caption'] ?? '', 1000, false);
        $targetUrl = self::targetUrl($data['target_url'] ?? '', $type !== 'HERO');
        $newImage = $type === 'QUICK_LINK' ? null : self::upload($file);
        $oldImage = null;

        try {
            Core::tx($p, static function () use ($p, $actor, $type, $slot, $title, $caption, $targetUrl, $newImage, &$oldImage): void {
                $old = Database::query(
                    $p,
                    'SELECT * FROM portal_banners WHERE content_type=? AND slot=? FOR UPDATE',
                    [$type, $slot]
                )->fetch();
                $oldImage = is_array($old) ? ($old['image_path'] ?? null) : null;
                $defaults = self::defaults()[$type][$slot];
                $image = $type === 'QUICK_LINK' ? '' : ($newImage ?? $oldImage ?? $defaults['image_path']);

                Database::query(
                    $p,
                    'INSERT INTO portal_banners(content_type,slot,title,caption,image_path,target_url,is_active,updated_by) VALUES(?,?,?,?,?,?,1,?) '
                    . 'ON DUPLICATE KEY UPDATE title=VALUES(title),caption=VALUES(caption),image_path=VALUES(image_path),target_url=VALUES(target_url),is_active=1,updated_by=VALUES(updated_by)',
                    [$type, $slot, $title, $caption, $image, $targetUrl, $actor]
                );
                Audit::record(
                    $p,
                    'portal.content_updated',
                    $actor,
                    null,
                    strtolower($type).':'.$slot,
                    'core',
                    null,
                    $old ?: null,
                    ['title' => $title, 'caption' => $caption, 'image_path' => $image, 'target_url' => $targetUrl]
                );
            });
        } catch (\Throwable $e) {
            if ($newImage !== null) {
                self::removeUpload($newImage);
            }
            throw $e;
        }

        if ($newImage !== null && is_string($oldImage) && $oldImage !== $newImage) {
            self::removeUpload($oldImage);
        }
    }

    private static function targetUrl(mixed $value, bool $required): string
    {
        $url = Core::text($value, 500, $required);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '\\')
            && preg_match("#\A/[A-Za-z0-9._~!$&'()*+,;=:@%/?\#-]*\z#", $url)) {
            return $url;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null) {
            throw new \InvalidArgumentException('Pautan mesti laluan dalaman bermula dengan / atau URL HTTPS yang sah.');
        }
        return $url;
    }

    private static function upload(array $file): ?string
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($error !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException('Muat naik imej gagal. Cuba semula dengan fail JPEG, PNG atau WebP.');
        }

        $tmp = $file['tmp_name'];
        $size = filesize($tmp);
        if ($size === false || $size < 1 || $size > 5 * 1024 * 1024) {
            throw new \InvalidArgumentException('Saiz imej mestilah tidak melebihi 5 MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) {
            throw new \InvalidArgumentException('Format imej mestilah JPEG, PNG atau WebP.');
        }
        $dimensions = @getimagesize($tmp);
        if (!is_array($dimensions) || ($dimensions[0] ?? 0) < 1 || ($dimensions[1] ?? 0) < 1
            || $dimensions[0] > 10000 || $dimensions[1] > 10000 || $dimensions[0] * $dimensions[1] > 40000000) {
            throw new \InvalidArgumentException('Dimensi imej tidak sah atau terlalu besar.');
        }

        $directory = APP_ROOT.'/assets/uploads/portal';
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new \RuntimeException('Direktori imej portal tidak dapat disediakan.');
        }
        @chmod($directory, 0755);
        $name = bin2hex(random_bytes(24)).'.'.$extensions[$mime];
        $destination = $directory.'/'.$name;
        if (!move_uploaded_file($tmp, $destination)) {
            throw new \RuntimeException('Imej portal tidak dapat disimpan.');
        }
        @chmod($destination, 0644);
        return '/assets/uploads/portal/'.$name;
    }

    private static function removeUpload(string $path): void
    {
        if (!preg_match('#\A/assets/uploads/portal/[a-f0-9]{48}\.(?:jpg|png|webp)\z#', $path)) {
            return;
        }
        $file = APP_ROOT.$path;
        $directory = realpath(APP_ROOT.'/assets/uploads/portal');
        $parent = realpath(dirname($file));
        if ($directory !== false && $parent !== false && hash_equals($directory, $parent) && is_file($file)) {
            @unlink($file);
        }
    }

    public static function render(\PDO $p, int $actor): string
    {
        Authorization::require($p, $actor, self::PERMISSION);
        if (!self::available($p)) {
            return '<section class="card"><h2>Migrasi diperlukan</h2><p>Jalankan migrasi sistem untuk menyediakan jadual <code>portal_banners</code>.</p></section>';
        }

        $hero = self::rows($p, 'HERO')[0];
        $html = '<div class="portal-settings"><section class="welcome-panel"><div><p class="eyebrow">PENTADBIRAN PORTAL</p><h2>Kandungan halaman utama</h2><p>Kemas kini imej, kapsyen dan pautan. JPEG, PNG atau WebP sahaja; maksimum 5 MB.</p></div><a class="button secondary" href="/">Pratonton portal</a></section>';
        $html .= self::editor($hero, 'Hero utama', 'Cadangan nisbah imej 16:9 atau 2:1. Kapsyen dipaparkan di atas imej.');

        $html .= '<section class="card portal-settings-section"><div class="portal-settings-heading"><div><p class="eyebrow">PAUTAN PANTAS</p><h2>Menu 1 – Menu 4</h2></div><p>Label dan pautan ini dipaparkan secara mendatar di bawah header.</p></div><div class="portal-settings-grid quick-link-editors">';
        foreach (self::rows($p, 'QUICK_LINK') as $row) {
            $html .= self::editor($row, 'Menu '.$row['slot'], 'Gunakan laluan dalaman seperti /training atau URL HTTPS.', true);
        }
        $html .= '</div></section>';

        $html .= '<section class="card portal-settings-section"><div class="portal-settings-heading"><div><p class="eyebrow">GALERI PORTAL</p><h2>Enam kad aktiviti</h2></div><p>Setiap kad mempunyai imej, tajuk, kapsyen dan pautan sasaran.</p></div><div class="portal-settings-grid">';
        foreach (self::rows($p, 'ACTIVITY') as $row) {
            $html .= self::editor($row, 'Aktiviti '.$row['slot'], 'Cadangan nisbah imej 4:3.');
        }
        return $html.'</div></section></div>';
    }

    private static function editor(array $row, string $heading, string $help, bool $compact = false): string
    {
        $type = (string) $row['content_type'];
        $slot = (int) $row['slot'];
        $image = self::safeImage((string) ($row['image_path'] ?? ''), self::defaults()[$type][$slot]['image_path']);
        $preview = $type === 'QUICK_LINK' ? '' : '<img class="portal-settings-preview" data-image-preview src="'.self::e($image).'" alt="Pratonton '.self::e($heading).'">';
        $file = $type === 'QUICK_LINK' ? '' : '<label>Imej baharu (pilihan)<input data-image-input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>';
        $caption = $type === 'QUICK_LINK' ? '' : '<label>Kapsyen<textarea name="caption" maxlength="1000">'.self::e($row['caption'] ?? '').'</textarea></label>';
        $form = '<form method="post" enctype="multipart/form-data" class="portal-content-form">'
            . '<input type="hidden" name="csrf" value="'.Support::csrf().'">'
            . '<input type="hidden" name="action" value="portal_content_save">'
            . '<input type="hidden" name="content_type" value="'.self::e($type).'">'
            . '<input type="hidden" name="slot" value="'.$slot.'">'
            . '<label>Label / tajuk<input name="title" value="'.self::e($row['title'] ?? '').'" maxlength="'.($type === 'QUICK_LINK' ? 80 : 190).'" required></label>'
            . $caption
            . '<label>Pautan sasaran<input name="target_url" value="'.self::e($row['target_url'] ?? '').'" maxlength="500" placeholder="/training/portal atau https://…"'.($type === 'HERO' ? '' : ' required').'></label>'
            . $file
            . '<button>Simpan '.self::e(strtolower($heading)).'</button></form>';
        return '<article class="portal-settings-editor'.($compact ? ' is-compact' : '').'"><div><h3>'.self::e($heading).'</h3><p>'.self::e($help).'</p></div>'.$preview.$form.'</article>';
    }

    public static function safeImage(string $path, string $fallback): string
    {
        if (preg_match('#\A/assets/(?:images/portal/[a-z0-9._-]+\.svg|uploads/portal/[a-f0-9]{48}\.(?:jpg|png|webp))\z#', $path)) {
            return $path;
        }
        return $fallback;
    }

    private static function e(mixed $value): string
    {
        return Support::escape($value);
    }
}

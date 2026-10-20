<?php
declare(strict_types=1);

namespace EPejabat\Core;

final class Ui
{
    public static function portalName(): string
    {
        $p = $GLOBALS['pdo'] ?? null;
        if ($p instanceof \PDO) {
            $name = Database::query($p, "SELECT setting_value FROM system_settings WHERE setting_key='portal_name'")->fetchColumn();
            if (is_string($name) && trim($name) !== '') {
                return $name;
            }
        }
        return 'PKD KOTA BHARU DIGITAL PORTAL';
    }

    public static function navigation(?\PDO $p, ?array $user): string
    {
        $public = [['/', 'Utama'], ['/training/portal', 'Portal Awam'], ['/public-program.php', 'Semak Status']];
        if (!$user) {
            $public[] = ['/login', 'Log Masuk'];
        }
        $html = self::navSection('Akses Awam', 'nav-public', $public);
        if (!$user) {
            return $html;
        }

        $uid = (int) ($user['id'] ?? 0);
        $super = $p instanceof \PDO && Authorization::super($p, $uid);
        $allowed = static fn(string $permission): bool => $p instanceof \PDO && Authorization::any($p, $uid, $permission);
        $staff = [
            ['/dashboard', $super ? 'Dashboard Pentadbir' : 'Dashboard Staf'],
            ['/eforms', 'Borang Saya / e-Forms'],
            ['/eforms#my-submissions', 'Permohonan Saya'],
            ['/profile', 'Profil'],
        ];
        $canReview = $super || $allowed('eforms.submissions.review') || $allowed('eforms.submissions.manage');
        $canTemplates = $super || $allowed('eforms.templates.manage');
        $module = [];
        if (!$super && $canReview) {
            $module[] = ['/eforms#approval-queue', 'Semakan & Kelulusan (Approval Queue)'];
            $module[] = ['/eforms#submission-list', 'Senarai Submission'];
        }
        if (!$super && ($canReview || $canTemplates)) {
            $module[] = ['/eforms#form-status', 'Status Borang'];
        }
        if (!$super && $canTemplates) {
            $module[] = ['/eforms?studio=new', 'Template Pentadbiran (Form Builder)'];
        }
        $html .= self::navSection('Ruang Kerja Staf', 'nav-staff', $staff, $module, 'Pengurusan e-Forms');

        $system = [];
        if ($super) {
            $system[] = ['/eforms?studio=new', 'Template Pentadbiran (Form Builder)'];
            $system[] = ['/eforms#approval-queue', 'Submission Tracking & Approval Queue'];
        }
        $adminLinks = [
            [['core.portal.manage'], '/admin/portal-settings', 'Kandungan Portal Utama'],
            [['core.staff.view', 'core.staff.manage'], '/staff', 'Pengurusan Akaun'],
            [['core.registrations.review'], '/registration-admin', 'Kelulusan Pendaftaran'],
            [['core.roles.manage', 'core.users.manage'], '/roles', 'Pengurusan Pengguna (Roles & Scope)'],
            [['core.organisations.manage'], '/organisations', 'Pengurusan Organisasi'],
            [['core.master.manage'], '/master-data', 'Data Induk'],
            [['core.documents.manage'], '/documents', 'Pengurusan Dokumen'],
            [['core.audit.view'], '/audit', 'Audit Trail'],
            [['core.settings.manage'], '/settings', 'Tetapan Sistem'],
            [['core.modules.manage'], '/modules', 'Pengurusan Modul'],
        ];
        foreach ($adminLinks as [$permissions, $url, $label]) {
            $show = $super;
            foreach ($permissions as $permission) {
                if ($allowed($permission)) {
                    $show = true;
                    break;
                }
            }
            if ($show) {
                $system[] = [$url, $label];
            }
        }
        if ($super || $allowed('core.migrations.manage')) {
            $system[] = ['/upgrade', 'Sistem / Migrasi'];
        }
        if ($system) {
            $html .= self::navSection('Pentadbiran Sistem', 'nav-system', $system);
        }
        return $html.'<form class="nav-logout" action="/logout" method="post"><input type="hidden" name="csrf" value="'.Support::csrf().'"><button>Log Keluar</button></form>';
    }

    private static function navSection(string $title, string $id, array $links, array $secondary = [], string $secondaryTitle = ''): string
    {
        $html = '<section class="nav-section" aria-labelledby="'.$id.'"><p class="nav-section-title" id="'.$id.'">'.Support::escape($title).'</p>';
        foreach ($links as [$url, $label]) {
            $html .= self::navLink($url, $label);
        }
        if ($secondary) {
            $html .= '<p class="nav-subheading">'.Support::escape($secondaryTitle).'</p>';
            foreach ($secondary as [$url, $label]) {
                $html .= self::navLink($url, $label);
            }
        }
        return $html.'</section>';
    }

    private static function navLink(string $url, string $label): string
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $requestPath = rtrim((string) parse_url($requestUri, PHP_URL_PATH), '/') ?: '/';
        $base = Support::basePath();
        if ($base !== '' && ($requestPath === $base || str_starts_with($requestPath, $base.'/'))) {
            $requestPath = substr($requestPath, strlen($base)) ?: '/';
        }
        $target = rtrim((string) parse_url($url, PHP_URL_PATH), '/') ?: '/';
        $targetQuery = parse_url($url, PHP_URL_QUERY);
        $requestQuery = parse_url($requestUri, PHP_URL_QUERY);
        $current = !str_contains($url, '#') && $requestPath === $target
            && ($targetQuery === null ? $requestQuery === null : $targetQuery === $requestQuery)
            ? ' aria-current="page"' : '';
        return '<a href="'.Support::escape($url).'"'.$current.'>'.Support::escape($label).'</a>';
    }

    private static function requestPath(): string
    {
        $path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';
        $base = Support::basePath();
        if ($base !== '' && ($path === $base || str_starts_with($path, $base.'/'))) {
            return substr($path, strlen($base)) ?: '/';
        }
        return $path;
    }

    public static function layout(string $title, string $body, string $nav = ''): never
    {
        $e = Support::escape($title);
        $user = $GLOBALS['ui_user'] ?? null;
        $pdo = ($GLOBALS['pdo'] ?? null) instanceof \PDO ? $GLOBALS['pdo'] : null;
        $version = Support::escape($GLOBALS['ui_versions']['app_version'] ?? '1.0.0');
        $nav = self::navigation($pdo, $user);
        $path = self::requestPath();
        $isHome = in_array($path, ['/', '/index.php'], true);
        clearstatcache(true, APP_ROOT.'/assets/app.css');
        clearstatcache(true, APP_ROOT.'/assets/app.js');
        $portal = Support::escape(self::portalName());
        $cv = substr(hash_file('sha256', APP_ROOT.'/assets/app.css'), 0, 16);
        $jv = substr(hash_file('sha256', APP_ROOT.'/assets/app.js'), 0, 16);
        $training = Support::escape($GLOBALS['ui_versions']['module_version.training'] ?? 'belum dipasang');

        $account = $user
            ? '<a class="header-action" href="/dashboard">Akaun</a><form class="header-logout" action="/logout" method="post"><input type="hidden" name="csrf" value="'.Support::csrf().'"><button>Log keluar</button></form>'
            : '<a class="header-action" href="/login">Log masuk</a><a class="header-action is-gold" href="/register">Daftar</a>';
        $search = $isHome
            ? '<form class="portal-search" role="search" action="/" method="get"><label class="visually-hidden" for="portal-search-input">Cari perkhidmatan portal</label><span aria-hidden="true">⌕</span><input id="portal-search-input" name="q" type="search" placeholder="Cari aktiviti atau perkhidmatan…" autocomplete="off"></form>'
            : '<span class="header-service">PEJABAT DIGITAL</span>';
        $heading = $isHome ? '' : '<div class="page-heading"><p class="eyebrow">Pejabat Kesihatan Daerah · Perkhidmatan digital</p><h1>'.$e.'</h1><p class="page-context">'.($user ? 'Profil, latihan dan perkhidmatan dalam skop akses anda.' : 'Maklumat dan perkhidmatan warga kesihatan.').'</p></div>';
        $bottomNav = $isHome ? self::bottomNavigation((bool) $user) : '';
        $bodyClass = $isHome ? 'portal-home-page' : 'app-page';

        ob_start(static fn(string $html): string => Support::withBasePath($html));
        echo '<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#123d70"><title>'.$e.' | '.$portal.'</title><link rel="stylesheet" href="/assets/bootstrap.min.css"><link rel="stylesheet" href="/assets/app.css?v='.$cv.'"><script src="/assets/bootstrap.bundle.min.js" defer></script><script src="/assets/jsQR.js" defer></script><script src="/assets/app.js?v='.$jv.'" defer></script></head><body class="'.$bodyClass.'"><a class="skip" href="#main">Langkau ke kandungan</a><header class="topbar"><a class="brand" href="/" aria-label="PKD Kota Bharu Digital Portal — halaman utama"><img class="brand-logo" src="/assets/images/portal/pkdkb-mark.svg" alt="Logo PKD Kota Bharu" width="54" height="54"><span class="brand-copy"><strong>'.$portal.'</strong><small>Kementerian Kesihatan Malaysia</small></span></a>'.$search.'<div class="portal-account-actions">'.$account.'</div><button id="menu-toggle" class="menu-toggle" aria-controls="main-nav" aria-expanded="false" aria-label="Buka navigasi">☰ <span>Menu</span></button></header><div class="shell"><nav id="main-nav" aria-label="Navigasi utama"><p class="nav-heading">'.($user ? 'RUANG KERJA' : 'PORTAL AWAM').'</p>'.$nav.'</nav><main id="main" tabindex="-1">'.$heading.$body.'</main></div><footer><strong>'.$portal.'</strong><span>Kementerian Kesihatan Malaysia</span><span>Application '.$version.' · Training '.$training.'</span></footer>'.$bottomNav.'<div id="toast" role="status" aria-live="polite" hidden></div><dialog id="confirm-dialog"><h2>Sahkan tindakan</h2><p>Teruskan perubahan ini?</p><div class="actions"><button type="button" id="confirm-no">Batal</button><button type="button" id="confirm-yes">Teruskan</button></div></dialog></body></html>';
        exit;
    }

    public static function home(\PDO $p, ?array $user): string
    {
        $hero = PortalSettings::rows($p, 'HERO')[0];
        $heroImage = PortalSettings::safeImage((string) $hero['image_path'], '/assets/images/portal/hero-default.svg');
        $quickLinks = array_values(array_filter(PortalSettings::rows($p, 'QUICK_LINK'), static fn(array $row): bool => (bool) $row['is_active']));
        $activities = array_values(array_filter(PortalSettings::rows($p, 'ACTIVITY'), static fn(array $row): bool => (bool) $row['is_active']));

        $html = '<section class="portal-quick-links" aria-label="Pautan pantas">';
        foreach ($quickLinks as $index => $link) {
            $html .= '<a href="'.Support::escape($link['target_url']).'"'.self::externalAttrs((string) $link['target_url']).'><span>'.sprintf('%02d', $index + 1).'</span>'.Support::escape($link['title']).'</a>';
        }
        $html .= '</section><div id="portal-search-status" class="portal-search-status" role="status" aria-live="polite"></div>';

        $html .= '<section class="portal-feature-grid" aria-label="Sorotan portal"><article class="portal-hero"><img src="'.Support::escape($heroImage).'" alt="'.Support::escape($hero['title']).'"><div class="portal-hero-shade"></div><div class="portal-hero-copy"><p class="portal-kicker">PORTAL DIGITAL PKD KOTA BHARU</p><h1>'.Support::escape($hero['title']).'</h1><p>'.Support::escape($hero['caption']).'</p>';
        if ($hero['target_url'] !== '') {
            $html .= '<a class="portal-hero-cta" href="'.Support::escape($hero['target_url']).'"'.self::externalAttrs((string) $hero['target_url']).'>Terokai sekarang <span aria-hidden="true">→</span></a>';
        }
        $html .= '</div></article>'.self::announcements($p).'</section>';

        $html .= '<section class="portal-activities" aria-labelledby="portal-activities-title"><div class="portal-section-heading"><div><p class="portal-kicker">PILIHAN UNTUK ANDA</p><h2 id="portal-activities-title">Aktiviti & perkhidmatan</h2></div><span>'.count($activities).' pilihan</span></div><div class="portal-activity-grid">';
        foreach ($activities as $activity) {
            $image = PortalSettings::safeImage((string) $activity['image_path'], '/assets/images/portal/activity-default.svg');
            $url = (string) $activity['target_url'];
            $html .= '<a class="portal-activity-card" data-portal-card href="'.Support::escape($url).'"'.self::externalAttrs($url).'><figure><img src="'.Support::escape($image).'" alt="" loading="lazy"><span class="portal-card-arrow" aria-hidden="true">↗</span></figure><div><p class="portal-card-index">AKTIVITI '.sprintf('%02d', (int) $activity['slot']).'</p><h3>'.Support::escape($activity['title']).'</h3><p>'.Support::escape($activity['caption']).'</p></div></a>';
        }
        return $html.'</div></section>';
    }

    private static function announcements(\PDO $p): string
    {
        $items = [];
        try {
            foreach (Content::publicRows($p, 'training') as $row) {
                if (in_array($row['type_key'], ['ANNOUNCEMENT', 'BANNER'], true)) {
                    $items[] = $row;
                }
                if (count($items) === 3) {
                    break;
                }
            }
        } catch (\Throwable) {
            $items = [];
        }
        if (!$items) {
            $items = [
                ['title' => 'Akses sehenti', 'body' => 'Program, borang dan maklumat penting dalam satu portal.', 'cta_url' => '/training/portal'],
                ['title' => 'Mesra telefon', 'body' => 'Direka mobile-first untuk capaian pantas di mana-mana.', 'cta_url' => ''],
                ['title' => 'Akses terkawal', 'body' => 'Ruang pentadbiran dilindungi mengikut peranan pengguna.', 'cta_url' => '/login'],
            ];
        }

        $html = '<aside class="portal-announcements" aria-labelledby="portal-announcement-title"><div class="portal-announcement-heading"><p class="portal-kicker">TERKINI</p><h2 id="portal-announcement-title">Pengumuman</h2><span class="portal-live-dot">Live</span></div><div class="portal-announcement-list">';
        foreach ($items as $index => $item) {
            $title = Support::escape($item['title'] ?? 'Makluman portal');
            $body = Support::escape($item['body'] ?? '');
            $url = (string) ($item['cta_url'] ?? '');
            $content = '<span class="portal-announcement-number">'.sprintf('%02d', $index + 1).'</span><span><strong>'.$title.'</strong><small>'.$body.'</small></span>';
            $html .= $url !== '' ? '<a href="'.Support::escape($url).'"'.self::externalAttrs($url).'>'.$content.'</a>' : '<div>'.$content.'</div>';
        }
        return $html.'</div><a class="portal-all-programmes" href="/training/portal">Lihat semua maklumat <span aria-hidden="true">→</span></a></aside>';
    }

    private static function externalAttrs(string $url): string
    {
        return str_starts_with($url, 'https://') ? ' target="_blank" rel="noopener noreferrer"' : '';
    }

    private static function bottomNavigation(bool $loggedIn): string
    {
        $links = [
            ['/', 'Home', self::icon('home')],
            ['/training/portal', 'Portal Awam', self::icon('calendar')],
            ['/training', 'Borang Daftar Program', self::icon('heart')],
            ['/training', 'Senarai Program', self::icon('briefcase')],
            [$loggedIn ? '/profile' : '/login', 'Akaun Saya', self::icon('user')],
        ];
        $html = '<nav class="portal-bottom-nav" aria-label="Navigasi mudah alih">';
        foreach ($links as $index => [$url, $label, $icon]) {
            $html .= '<a href="'.$url.'"'.($index === 0 ? ' aria-current="page"' : '').'>'.$icon.'<span>'.Support::escape($label).'</span></a>';
        }
        return $html.'</nav>';
    }

    private static function icon(string $name): string
    {
        $paths = [
            'home' => '<path d="M3 10.5 12 3l9 7.5V21a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z"/>',
            'calendar' => '<path d="M5 3v3m14-3v3M3 9h18M5 5h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z"/>',
            'heart' => '<path d="M20.8 5.7a5.5 5.5 0 0 0-7.8 0L12 6.8l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 22l8.8-8.5a5.5 5.5 0 0 0 0-7.8Z"/>',
            'briefcase' => '<path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 9h18v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Zm0 5c5 3 13 3 18 0m-9-2v5"/>',
            'user' => '<path d="M20 21a8 8 0 0 0-16 0m8-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/>',
        ];
        return '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$paths[$name].'</svg>';
    }

    public static function camera(): string
    {
        return '<section class="card" id="qr-camera"><h2>Imbas QR kehadiran</h2><p>Kamera memerlukan HTTPS dan kebenaran anda. Token boleh dimasukkan secara manual.</p><button type="button" id="camera-start">Buka kamera</button><button type="button" id="camera-stop" hidden>Hentikan kamera</button><video id="camera-video" playsinline muted hidden aria-label="Pratonton kamera QR"></video><canvas id="camera-canvas" hidden></canvas><p id="camera-status" role="status" aria-live="polite"></p></section>';
    }
}

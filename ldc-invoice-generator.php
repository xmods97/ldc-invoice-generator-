<?php
/**
 * Plugin Name: LDC Invoice Generator
 * Description: Private invoice/proposal builder with saved records, printing/PDF, JSON transfer, and email delivery.
 * Version: 0.9.28
 * Author: xmods97
 * Author URI: https://github.com/xmods97
 * Update URI: https://github.com/xmods97/ldc-invoice-generator-
 */

if (!defined('ABSPATH')) { exit; }

final class LDC_Invoice_Generator {
    private const VERSION = '0.9.28';
    private const SLUG = 'ldc-invoice-generator';
    private const PAGE_SLUG = 'invoice-builder';
    private const LIST_PAGE_SLUG = 'invoice-list';
    private const SETTINGS_PAGE_SLUG = 'invoice-settings';
    private const KEY_OPTION = 'ldc_invoice_access_key';
    private const RECORDS_OPTION = 'ldc_invoice_records';
    private const COMPANY_OPTION = 'ldc_invoice_company_settings';
    private const AUTO_UPDATE_OPTION = 'ldc_invoice_auto_updates';
    private const AUTO_UPDATE_HOOK = 'ldc_invoice_auto_update_check';
    private const UPDATE_API = 'https://api.github.com/repos/xmods97/ldc-invoice-generator-/releases/latest';
    private const UPDATE_REPO = 'https://github.com/xmods97/ldc-invoice-generator-';
    private const UPDATE_ASSET = 'ldc-invoice-generator.zip';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_shortcode('ldc_invoice_builder', [$this, 'render_frontend']);
        add_shortcode('ldc_invoice_list', [$this, 'render_list_frontend']);
        add_shortcode('ldc_invoice_settings', [$this, 'render_settings_frontend']);
        add_action('init', [$this, 'ensure_pages']);
        add_action('template_redirect', [$this, 'serve_standalone_page']);
        add_action('wp_ajax_ldc_send_invoice', [$this, 'send_invoice']);
        add_action('wp_ajax_nopriv_ldc_send_invoice', [$this, 'send_invoice']);
        add_action('wp_ajax_ldc_save_company_settings', [$this, 'save_company_settings_public']);
        add_action('wp_ajax_nopriv_ldc_save_company_settings', [$this, 'save_company_settings_public']);
        add_action('wp_ajax_ldc_security_check', [$this, 'security_check']);
        add_action('wp_ajax_nopriv_ldc_security_check', [$this, 'security_check']);
        foreach (['ldc_list_invoices', 'ldc_save_invoice', 'ldc_delete_invoice', 'ldc_download_backup'] as $action) {
            add_action('wp_ajax_' . $action, [$this, 'manage_invoices']);
            add_action('wp_ajax_nopriv_' . $action, [$this, 'manage_invoices']);
        }
        add_filter('update_plugins_github.com', [$this, 'check_github_update'], 10, 4);
        add_filter('plugins_api', [$this, 'github_plugin_information'], 20, 3);
        add_filter('auto_update_plugin', [$this, 'allow_automatic_updates'], 10, 2);
        add_filter('plugin_auto_update_setting_html', [$this, 'automatic_update_setting_html'], 10, 3);
        add_action('admin_post_ldc_toggle_auto_updates', [$this, 'toggle_automatic_updates']);
        add_action(self::AUTO_UPDATE_HOOK, [$this, 'run_automatic_update']);
        add_action('init', [$this, 'ensure_auto_update_schedule']);
    }

    public function register_menu(): void {
        add_menu_page('Invoice Generator', 'Invoices', 'manage_options', self::SLUG, [$this, 'render_page'], 'dashicons-media-document', 26);
        add_submenu_page(self::SLUG, 'Company Settings', 'Company Settings', 'manage_options', self::SLUG . '-settings', [$this, 'render_settings_page']);
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'toplevel_page_' . self::SLUG) { return; }
        $this->load_assets();
    }

    private function load_assets(): void {
        $base = plugin_dir_url(__FILE__);
        wp_enqueue_style('ldc-invoice-admin', $base . 'assets/admin.css', [], self::VERSION);
        wp_enqueue_script('ldc-pdfmake', $base . 'assets/vendor/pdfmake.min.js', [], '0.2.20', true);
        wp_enqueue_script('ldc-pdfmake-fonts', $base . 'assets/vendor/vfs_fonts.js', ['ldc-pdfmake'], '0.2.20', true);
        wp_enqueue_script('ldc-invoice-admin', $base . 'assets/admin.js', ['ldc-pdfmake-fonts'], self::VERSION, true);
        wp_enqueue_script('ldc-invoice-list', $base . 'assets/list.js', [], self::VERSION, true);
        wp_enqueue_script('ldc-invoice-settings', $base . 'assets/settings.js', [], self::VERSION, true);
        $company = $this->get_company_settings();
        wp_localize_script('ldc-invoice-admin', 'LDCInvoice', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ldc_invoice_send'),
            'accessKey' => $this->get_access_key(),
            'logoUrl' => $this->get_logo_url(),
            'version' => self::VERSION,
            'builderUrl' => add_query_arg('key', rawurlencode($this->get_access_key()), home_url('/' . self::PAGE_SLUG . '/')),
            'listUrl' => add_query_arg('key', rawurlencode($this->get_access_key()), home_url('/' . self::LIST_PAGE_SLUG . '/')),
            'settingsUrl' => add_query_arg('key', rawurlencode($this->get_access_key()), home_url('/' . self::SETTINGS_PAGE_SLUG . '/')),
            'company' => $company,
        ]);
    }

    private function get_company_settings(): array {
        $saved = get_option(self::COMPANY_OPTION, []);
        return wp_parse_args(is_array($saved) ? $saved : [], [
            'company_name' => '',
            'license_number' => '',
            'phone' => '',
            'address_line_1' => '',
            'address_line_2' => '',
            'default_tax_rate' => '0',
        ]);
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) { return; }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ldc_company_nonce'])) {
            check_admin_referer('ldc_save_company', 'ldc_company_nonce');
            $settings = [
                'company_name' => sanitize_text_field(wp_unslash($_POST['company_name'] ?? '')),
                'license_number' => sanitize_text_field(wp_unslash($_POST['license_number'] ?? '')),
                'phone' => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
                'address_line_1' => sanitize_text_field(wp_unslash($_POST['address_line_1'] ?? '')),
                'address_line_2' => sanitize_text_field(wp_unslash($_POST['address_line_2'] ?? '')),
                'default_tax_rate' => (string) max(0, (float) wp_unslash($_POST['default_tax_rate'] ?? '0')),
            ];
            update_option(self::COMPANY_OPTION, $settings, false);
            $this->set_automatic_updates(!empty($_POST['auto_updates']));
            echo '<div class="notice notice-success"><p>Company settings saved.</p></div>';
        }
        $settings = $this->get_company_settings();
        ?><div class="wrap"><h1>Invoice Company Settings</h1><p>These values are stored only in this WordPress database and are not part of the plugin files or update package.</p><form method="post"><?php wp_nonce_field('ldc_save_company', 'ldc_company_nonce'); ?><table class="form-table" role="presentation"><tbody><?php
        $fields = [
            'company_name' => 'Company name',
            'license_number' => 'License number',
            'phone' => 'Phone number',
            'address_line_1' => 'Address line 1',
            'address_line_2' => 'Address line 2',
            'default_tax_rate' => 'Default sales tax rate (%)',
        ];
        foreach ($fields as $name => $label) {
            $type = $name === 'default_tax_rate' ? 'number' : 'text';
            $step = $type === 'number' ? ' step="0.001" min="0"' : '';
            echo '<tr><th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '" value="' . esc_attr($settings[$name]) . '"' . $step . '></td></tr>';
        }
        ?></tbody></table><p><label><input type="checkbox" name="auto_updates" value="1" <?php checked((bool) get_option(self::AUTO_UPDATE_OPTION, false)); ?>> <strong>Enable automatic plugin updates from GitHub Releases</strong></label></p><p><strong>Logo:</strong> the plugin uses the Site Logo configured under Appearance → Customize / Site Editor.</p><?php submit_button('Save company settings'); ?></form></div><?php
    }

    private function get_logo_url(): string {
        $custom_logo_id = (int) get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $logo = wp_get_attachment_image_url($custom_logo_id, 'full');
            if ($logo) { return $logo; }
        }
        $site_icon = get_site_icon_url(512);
        return $site_icon ?: includes_url('images/w-logo-blue-white-bg.png');
    }

    private function get_plugin_icon_url(): string {
        return add_query_arg('v', self::VERSION, plugin_dir_url(__FILE__) . 'assets/invoice-builder-icon.png');
    }

    private function output_social_meta(string $page_type): void {
        $company = $this->get_company_settings();
        $company_name = $company['company_name'] ?: get_bloginfo('name');
        $labels = [
            'builder' => 'Invoice Generator',
            'list' => 'Saved Invoices',
            'settings' => 'Company Settings',
        ];
        $descriptions = [
            'builder' => 'Private invoice and project proposal workspace.',
            'list' => 'Private saved invoice archive.',
            'settings' => 'Private invoice company settings.',
        ];
        $title = trim($company_name . ' - ' . ($labels[$page_type] ?? 'Invoice Workspace'));
        $description = $descriptions[$page_type] ?? 'Private invoice workspace.';
        $image = $this->get_plugin_icon_url();
        $image_width = 512;
        $image_height = 512;
        $url = home_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
        echo "\n" . '<meta property="og:type" content="website">' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr($company_name) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
        echo '<meta property="og:image:secure_url" content="' . esc_url(set_url_scheme($image, 'https')) . '">' . "\n";
        if ($image_width && $image_height) {
            echo '<meta property="og:image:width" content="' . esc_attr((string) $image_width) . '">' . "\n";
            echo '<meta property="og:image:height" content="' . esc_attr((string) $image_height) . '">' . "\n";
        }
        echo '<meta property="og:image:alt" content="LDC Invoice Generator logo">' . "\n";
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($description) . '">' . "\n";
        echo '<meta name="twitter:image" content="' . esc_url($image) . '">' . "\n";
    }

    private function get_latest_release(bool $force = false): ?array {
        $cache_key = 'ldc_invoice_github_release';
        if ($force) { delete_site_transient($cache_key); }
        $cached = get_site_transient($cache_key);
        if (is_array($cached)) { return $cached; }
        $api_url = $force ? add_query_arg('nocache', time(), self::UPDATE_API) : self::UPDATE_API;
        $response = wp_remote_get($api_url, [
            'timeout' => 12,
            'headers' => ['Accept' => 'application/vnd.github+json', 'Cache-Control' => $force ? 'no-cache' : 'max-age=300'],
            'user-agent' => 'WordPress/LDC-Invoice-Generator',
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return null; }
        $release = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($release) || empty($release['tag_name'])) { return null; }
        $package = '';
        foreach ((array) ($release['assets'] ?? []) as $asset) {
            if (($asset['name'] ?? '') === self::UPDATE_ASSET) {
                $package = esc_url_raw((string) ($asset['browser_download_url'] ?? ''));
                break;
            }
        }
        if (!$package) { return null; }
        $data = [
            'version' => ltrim((string) $release['tag_name'], 'vV'),
            'package' => $package,
            'url' => esc_url_raw((string) ($release['html_url'] ?? self::UPDATE_REPO)),
            'body' => sanitize_textarea_field((string) ($release['body'] ?? '')),
            'published_at' => sanitize_text_field((string) ($release['published_at'] ?? '')),
        ];
        set_site_transient($cache_key, $data, 5 * MINUTE_IN_SECONDS);
        return $data;
    }

    public function check_github_update($update, array $plugin_data, string $plugin_file, array $locales) {
        if ($plugin_file !== plugin_basename(__FILE__)) { return $update; }
        $force = isset($_GET['force-check']) && current_user_can('update_plugins');
        $release = $this->get_latest_release($force);
        if (!$release || version_compare((string) $plugin_data['Version'], $release['version'], '>=')) { return false; }
        return [
            'id' => self::UPDATE_REPO,
            'slug' => self::SLUG,
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'tested' => get_bloginfo('version'),
            'requires_php' => '7.4',
            'autoupdate' => (bool) get_option(self::AUTO_UPDATE_OPTION, false),
            'icons' => [
                '1x' => $this->get_plugin_icon_url(),
                '2x' => $this->get_plugin_icon_url(),
                'default' => $this->get_plugin_icon_url(),
            ],
            'banners' => [],
        ];
    }

    public function github_plugin_information($result, string $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::SLUG) { return $result; }
        $release = $this->get_latest_release();
        if (!$release) { return $result; }
        return (object) [
            'name' => 'Invoice Generator',
            'slug' => self::SLUG,
            'version' => $release['version'],
            'author' => '<a href="https://github.com/xmods97">xmods97</a>',
            'homepage' => self::UPDATE_REPO,
            'download_link' => $release['package'],
            'requires_php' => '7.4',
            'last_updated' => $release['published_at'],
            'sections' => [
                'description' => 'Private invoice and project proposal builder with PDF printing, saved records, import/export, email delivery, and configurable company settings.',
                'changelog' => nl2br(esc_html($release['body'] ?: 'See the GitHub release for details.')),
            ],
            'icons' => [
                '1x' => $this->get_plugin_icon_url(),
                '2x' => $this->get_plugin_icon_url(),
                'default' => $this->get_plugin_icon_url(),
            ],
        ];
    }

    public function allow_automatic_updates($update, $item) {
        $slug = is_object($item) ? (string) ($item->slug ?? '') : '';
        $plugin = is_object($item) ? (string) ($item->plugin ?? '') : '';
        if ($slug === self::SLUG || $plugin === plugin_basename(__FILE__)) {
            return (bool) get_option(self::AUTO_UPDATE_OPTION, false);
        }
        return $update;
    }

    public function automatic_update_setting_html($html, $plugin_file, $plugin_data) {
        if ($plugin_file !== plugin_basename(__FILE__)) { return $html; }
        $enabled = (bool) get_option(self::AUTO_UPDATE_OPTION, false);
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=ldc_toggle_auto_updates&enabled=' . ($enabled ? '0' : '1')),
            'ldc_toggle_auto_updates'
        );
        $label = $enabled ? 'Disable automatic updates' : 'Enable automatic updates';
        $status = $enabled ? 'Automatic updates enabled.' : 'Automatic updates disabled.';
        return '<span class="ldc-auto-update-status">' . esc_html($status) . '</span><br><a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    public function toggle_automatic_updates(): void {
        if (!current_user_can('update_plugins')) { wp_die('You are not allowed to change plugin update settings.'); }
        check_admin_referer('ldc_toggle_auto_updates');
        $this->set_automatic_updates(!empty($_GET['enabled']));
        wp_safe_redirect(admin_url('plugins.php'));
        exit;
    }

    private function set_automatic_updates(bool $enabled): void {
        update_option(self::AUTO_UPDATE_OPTION, $enabled, false);
        if ($enabled) {
            $this->ensure_auto_update_schedule();
            if (!wp_next_scheduled(self::AUTO_UPDATE_HOOK, ['immediate'])) {
                wp_schedule_single_event(time() + 30, self::AUTO_UPDATE_HOOK, ['immediate']);
            }
        } else {
            wp_clear_scheduled_hook(self::AUTO_UPDATE_HOOK);
        }
    }

    public function ensure_auto_update_schedule(): void {
        if (get_option(self::AUTO_UPDATE_OPTION, false) && !wp_next_scheduled(self::AUTO_UPDATE_HOOK)) {
            wp_schedule_event(time() + 300, 'twicedaily', self::AUTO_UPDATE_HOOK);
        }
    }

    public function run_automatic_update(): void {
        if (!get_option(self::AUTO_UPDATE_OPTION, false) || get_transient('ldc_invoice_update_lock')) { return; }
        set_transient('ldc_invoice_update_lock', 1, 5 * MINUTE_IN_SECONDS);
        delete_site_transient('update_plugins');
        wp_update_plugins();
        $updates = get_site_transient('update_plugins');
        $plugin_file = plugin_basename(__FILE__);
        if (!is_object($updates) || empty($updates->response[$plugin_file])) {
            delete_transient('ldc_invoice_update_lock');
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $upgrader->upgrade($plugin_file, ['clear_update_cache' => true]);
        delete_transient('ldc_invoice_update_lock');
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) { return; }
        $page = get_page_by_path(self::PAGE_SLUG);
        $url = add_query_arg('key', rawurlencode($this->get_access_key()), $page ? get_permalink($page) : home_url('/' . self::PAGE_SLUG . '/'));
        echo '<div class="notice notice-info"><p><strong>Private client link:</strong> <a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($url) . '</a></p></div>';
        $this->render_builder(false);
    }

    public function render_frontend(): string {
        $key = sanitize_text_field(wp_unslash($_GET['key'] ?? ''));
        if (!$key || !hash_equals($this->get_access_key(), $key)) {
            status_header(403);
            return '<div class="ldc-access-error"><h1>Private invoice builder</h1><p>This link is incomplete or no longer valid. Request a new private link from the site administrator.</p></div>';
        }
        $this->load_assets();
        ob_start();
        $this->render_builder(true);
        return (string) ob_get_clean();
    }

    public function render_list_frontend(): string {
        $key = sanitize_text_field(wp_unslash($_GET['key'] ?? ''));
        if (!$key || !hash_equals($this->get_access_key(), $key)) {
            status_header(403);
            return '<div class="ldc-access-error"><h1>Private invoice archive</h1><p>This link is incomplete or no longer valid.</p></div>';
        }
        $this->load_assets();
        ob_start();
        $this->render_invoice_list(true);
        return (string) ob_get_clean();
    }

    public function render_settings_frontend(): string {
        $key = sanitize_text_field(wp_unslash($_GET['key'] ?? ''));
        if (!$key || !hash_equals($this->get_access_key(), $key)) {
            status_header(403);
            return '<div class="ldc-access-error"><h1>Private company settings</h1><p>This link is incomplete or no longer valid.</p></div>';
        }
        $this->load_assets();
        ob_start();
        $this->render_public_company_settings();
        return (string) ob_get_clean();
    }

    public function serve_standalone_page(): void {
        $is_builder = is_page(self::PAGE_SLUG);
        $is_list = is_page(self::LIST_PAGE_SLUG);
        $is_settings = is_page(self::SETTINGS_PAGE_SLUG);
        if (!$is_builder && !$is_list && !$is_settings) { return; }
        $key = sanitize_text_field(wp_unslash($_GET['key'] ?? ''));
        $valid = $key && hash_equals($this->get_access_key(), $key);
        if (!$valid) { status_header(403); } else { $this->load_assets(); }
        add_filter('wpseo_frontend_presenters', '__return_empty_array', 999);
        nocache_headers();
        ?><!doctype html><html <?php language_attributes(); ?>><head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <meta name="robots" content="noindex,nofollow,noarchive">
            <title>Private Invoice Builder</title>
            <?php $this->output_social_meta($is_settings ? 'settings' : ($is_list ? 'list' : 'builder')); ?>
            <?php wp_head(); ?>
        </head><body class="ldc-standalone-page<?php echo is_admin_bar_showing() ? ' admin-bar' : ''; ?>"><?php
        if ($valid) {
            if ($is_settings) { $this->render_public_company_settings(); }
            elseif ($is_list) { $this->render_invoice_list(true); }
            else { $this->render_builder(true); }
        } else {
            echo '<div class="ldc-access-error"><h1>Private invoice builder</h1><p>This link is incomplete or no longer valid. Request a new private link from the site administrator.</p></div>';
        }
        wp_footer();
        ?></body></html><?php
        exit;
    }

    private function render_builder(bool $frontend): void {
        $list_url = add_query_arg('key', rawurlencode($this->get_access_key()), home_url('/' . self::LIST_PAGE_SLUG . '/'));
        $settings_url = add_query_arg('key', rawurlencode($this->get_access_key()), home_url('/' . self::SETTINGS_PAGE_SLUG . '/'));
        $company = $this->get_company_settings();
        ?>
        <div class="<?php echo $frontend ? 'ldc-frontend ' : 'wrap '; ?>ldc-app" id="ldc-invoice-app">
            <div class="ldc-toolbar">
                <div class="ldc-app-brand"><img src="<?php echo esc_url($this->get_logo_url()); ?>" alt="<?php echo esc_attr($company['company_name'] ?: 'Company logo'); ?>"><div><h1>Invoice Generator</h1><p>Fill in the fields, review the invoice, then save it as PDF or send it by email.</p><a class="ldc-plugin-credit" href="https://github.com/xmods97" target="_blank" rel="noopener">Plugin by xmods97 · v<?php echo esc_html(self::VERSION); ?></a></div></div>
            </div>
            <nav class="ldc-toolbar-actions ldc-sticky-actions" aria-label="Invoice actions"><button type="button" class="button button-primary" id="ldc-print">Print / PDF</button><button type="button" class="button ldc-send-button ldc-send-email-trigger">Send invoice by email</button><a class="button" href="<?php echo esc_url($list_url); ?>">Invoice list</a><a class="button" href="<?php echo esc_url($settings_url); ?>">Company settings</a><button type="button" class="button" id="ldc-new-invoice">New</button><button type="button" class="button" id="ldc-save-draft">Save invoice</button><button type="button" class="button" id="ldc-export-json">Export current</button></nav>
            <div class="ldc-notice" id="ldc-notice" hidden></div>
            <div class="ldc-layout">
                <form class="ldc-form" id="ldc-invoice-form" autocomplete="off">
                    <section class="ldc-panel">
                        <h2>Invoice details</h2>
                        <div class="ldc-grid two">
                            <?php $this->field('invoice_number', 'Invoice number', 'INV-2026-001'); ?>
                            <?php $this->field('invoice_date', 'Invoice date', '', 'date'); ?>
                            <?php $this->field('client_name', 'Client name'); ?>
                            <?php $this->field('client_company', 'Client company'); ?>
                            <?php $this->field('client_email', 'Client email', '', 'email'); ?>
                            <?php $this->field('client_phone', 'Client phone'); ?>
                            <?php $this->field('client_contact', 'Client contact person'); ?>
                            <?php $this->field('project_type', 'Invoice / project type', 'Hardscape Project'); ?>
                        </div>
                        <?php $this->field('client_address', 'Client billing address'); ?>
                        <?php $this->field('client_city_state_zip', 'Client city / state / ZIP'); ?>
                        <?php $this->field('project_address', 'Project address'); ?>
                        <?php $this->field('project_name', 'Project name', 'Front Yard / Hardscape'); ?>
                        <div class="ldc-grid two">
                            <?php $this->field('project_owner', 'Project owner / homeowner'); ?>
                            <?php $this->field('project_manager', 'Estimator / project manager'); ?>
                            <?php $this->field('project_start', 'Estimated start date', '', 'date'); ?>
                            <?php $this->field('project_end', 'Estimated completion date', '', 'date'); ?>
                        </div>
                        <?php $this->field('project_permit', 'Permit / reference number'); ?>
                    </section>
                    <section class="ldc-panel">
                        <h2>Project overview</h2>
                        <?php $this->textarea('project_overview', 'Overview', 'This proposal outlines the construction of...'); ?>
                        <?php $this->textarea('standards', 'Standards / general notes', 'All work will be completed in accordance with applicable building standards and local codes.'); ?>
                    </section>
                    <section class="ldc-panel">
                        <div class="ldc-section-heading"><h2>Scope of work</h2><button type="button" class="button" id="ldc-add-scope">+ Add section</button></div>
                        <div id="ldc-scope-list"></div>
                    </section>
                    <section class="ldc-panel">
                        <h2>Investment summary</h2>
                        <label class="ldc-toggle"><input type="checkbox" name="auto_calculate_total" value="1"><span><strong>Calculate total from work items</strong><small>Turn this on to total the work item prices automatically.</small></span></label>
                        <label class="ldc-toggle"><input type="checkbox" name="apply_sales_tax" value="1"><span><strong>Apply US sales tax</strong><small>Turn this on when sales tax should be added to the invoice.</small></span></label>
                        <div class="ldc-grid two">
                            <?php $this->field('total', 'Subtotal before tax', '0.00', 'number', '0.01'); ?>
                            <?php $this->field('tax_rate', 'Sales tax rate (%)', $company['default_tax_rate'], 'number', '0.001'); ?>
                        </div>
                        <?php $this->field('tax_note', 'Tax note', 'Included'); ?>
                        <?php $this->textarea('includes', 'Price includes (one item per line)', "Labor\nMaterials\nTax\nHaul-away and disposal\nApplicable discounts"); ?>
                        <?php $this->textarea('exclusions', 'Additional notes / exclusions'); ?>
                    </section>
                    <section class="ldc-panel">
                        <div class="ldc-section-heading"><h2>Payment schedule</h2><button type="button" class="button" id="ldc-add-payment">+ Add payment</button></div>
                        <div id="ldc-payment-list"></div>
                        <p class="ldc-total-check" id="ldc-total-check"></p>
                    </section>
                    <section class="ldc-panel">
                        <h2>Email</h2>
                        <div class="ldc-grid two">
                            <?php $this->field('email_subject', 'Subject', 'Your project proposal and invoice'); ?>
                            <?php $this->field('email_cc', 'CC', '', 'email'); ?>
                        </div>
                        <?php $this->textarea('email_message', 'Message', "Hello,\n\nPlease find your project proposal and invoice below.\n\nThank you."); ?>
                        <button type="button" class="button button-large ldc-send-button ldc-send-email-trigger">Send invoice by email</button>
                    </section>
                </form>
                <aside class="ldc-preview-column">
                    <div class="ldc-preview-label">Live preview</div>
                    <article class="ldc-paper" id="ldc-invoice-preview" aria-label="Invoice preview"></article>
                    <div class="ldc-preview-actions"><button type="button" class="button ldc-send-button ldc-send-email-trigger">Send invoice by email</button></div>
                </aside>
            </div>
        </div>
        <?php
    }

    private function render_invoice_list(bool $frontend): void {
        $builder_url = add_query_arg('key', rawurlencode($this->get_access_key()), home_url('/' . self::PAGE_SLUG . '/'));
        $settings_url = add_query_arg('key', rawurlencode($this->get_access_key()), home_url('/' . self::SETTINGS_PAGE_SLUG . '/'));
        $company = $this->get_company_settings();
        ?>
        <div class="<?php echo $frontend ? 'ldc-frontend ' : 'wrap '; ?>ldc-app" id="ldc-invoice-list-app">
            <div class="ldc-toolbar">
                <div class="ldc-app-brand"><img src="<?php echo esc_url($this->get_logo_url()); ?>" alt="<?php echo esc_attr($company['company_name'] ?: 'Company logo'); ?>"><div><h1>Saved Invoices</h1><p>Open, export, import, or delete saved invoices.</p><a class="ldc-plugin-credit" href="https://github.com/xmods97" target="_blank" rel="noopener">Plugin by xmods97 · v<?php echo esc_html(self::VERSION); ?></a></div></div>
            </div>
            <nav class="ldc-toolbar-actions ldc-sticky-actions" aria-label="Invoice archive actions"><a class="button button-primary" href="<?php echo esc_url($builder_url); ?>">Back to generator</a><a class="button" href="<?php echo esc_url($settings_url); ?>">Company settings</a><button type="button" class="button" id="ldc-list-backup">Download backup</button><button type="button" class="button" id="ldc-list-export-selected">Export selected JSON</button><button type="button" class="button" id="ldc-list-export-all">Export all JSON</button><button type="button" class="button" id="ldc-list-excel-selected">Export selected Excel</button><button type="button" class="button" id="ldc-list-excel-all">Export all Excel</button><button type="button" class="button ldc-danger" id="ldc-list-delete-selected">Delete selected</button><button type="button" class="button" id="ldc-list-import">Import JSON</button><input type="file" id="ldc-list-import-file" accept="application/json,.json" hidden></nav>
            <div class="ldc-notice" id="ldc-list-notice" hidden></div>
            <section class="ldc-panel ldc-list-panel">
                <div class="ldc-section-heading"><h2>Invoice archive</h2><span id="ldc-list-count">Loading...</span></div>
                <div class="ldc-list-table-wrap">
                    <table class="ldc-list-table">
                        <thead><tr><th class="ldc-list-select"><input type="checkbox" id="ldc-list-select-all" aria-label="Select all invoices"></th><th>Invoice</th><th>Client</th><th>Project</th><th>Project address</th><th>Total</th><th>Updated</th><th>Actions</th></tr></thead>
                        <tbody id="ldc-list-body"><tr><td colspan="8">Loading invoices...</td></tr></tbody>
                    </table>
                </div>
            </section>
        </div>
        <?php
    }

    private function render_public_company_settings(): void {
        $company = $this->get_company_settings();
        $builder_url = add_query_arg('key', rawurlencode($this->get_access_key()), home_url('/' . self::PAGE_SLUG . '/'));
        $list_url = add_query_arg('key', rawurlencode($this->get_access_key()), home_url('/' . self::LIST_PAGE_SLUG . '/'));
        ?>
        <div class="ldc-frontend ldc-app" id="ldc-company-settings-app">
            <div class="ldc-toolbar"><div class="ldc-app-brand"><img src="<?php echo esc_url($this->get_logo_url()); ?>" alt="<?php echo esc_attr($company['company_name'] ?: 'Company logo'); ?>"><div><h1>Company Settings</h1><p>These values are stored only in the WordPress database.</p><a class="ldc-plugin-credit" href="https://github.com/xmods97" target="_blank" rel="noopener">Plugin by xmods97 · v<?php echo esc_html(self::VERSION); ?></a></div></div></div>
            <nav class="ldc-toolbar-actions ldc-sticky-actions" aria-label="Settings navigation"><a class="button button-primary" href="<?php echo esc_url($builder_url); ?>">Back to generator</a><a class="button" href="<?php echo esc_url($list_url); ?>">Invoice list</a><button type="button" class="button" id="ldc-company-save">Save settings</button></nav>
            <div class="ldc-notice" id="ldc-company-notice" hidden></div>
            <form class="ldc-panel ldc-company-form" id="ldc-company-form" autocomplete="off">
                <h2>Company information</h2>
                <div class="ldc-grid two">
                    <?php $this->public_setting_field('company_name', 'Company name', $company['company_name']); ?>
                    <?php $this->public_setting_field('license_number', 'License number', $company['license_number']); ?>
                    <?php $this->public_setting_field('phone', 'Phone number', $company['phone']); ?>
                    <?php $this->public_setting_field('default_tax_rate', 'Default sales tax rate (%)', $company['default_tax_rate'], 'number', '0.001'); ?>
                </div>
                <?php $this->public_setting_field('address_line_1', 'Address line 1', $company['address_line_1']); ?>
                <?php $this->public_setting_field('address_line_2', 'Address line 2', $company['address_line_2']); ?>
                <label class="ldc-toggle"><input type="checkbox" name="auto_updates" value="1" <?php checked((bool) get_option(self::AUTO_UPDATE_OPTION, false)); ?>><span><strong>Enable automatic plugin updates</strong><small>WordPress will install newer public GitHub Releases automatically while keeping this plugin active.</small></span></label>
                <p class="ldc-settings-help"><strong>Logo:</strong> the generator uses the Site Logo configured in WordPress Appearance settings.</p>
            </form>
            <section class="ldc-panel ldc-security-panel">
                <div class="ldc-section-heading"><h2>Security check</h2><button type="button" class="button button-primary" id="ldc-security-check">Run security check</button></div>
                <p class="ldc-settings-help">Checks invoice encryption, private access, backup protection, and server support without showing client data.</p>
                <div class="ldc-security-results" id="ldc-security-results" aria-live="polite"></div>
            </section>
        </div>
        <?php
    }

    private function public_setting_field(string $name, string $label, string $value, string $type = 'text', string $step = ''): void {
        printf('<label class="ldc-field"><span>%1$s</span><input type="%2$s" name="%3$s" value="%4$s" %5$s></label>', esc_html($label), esc_attr($type), esc_attr($name), esc_attr($value), $step ? 'step="' . esc_attr($step) . '" min="0"' : '');
    }

    private function get_access_key(): string {
        $key = (string) get_option(self::KEY_OPTION, '');
        if (!$key) {
            $key = wp_generate_password(32, false, false);
            update_option(self::KEY_OPTION, $key, false);
        }
        return $key;
    }

    public static function activate(): void {
        if (!get_option(self::KEY_OPTION)) {
            update_option(self::KEY_OPTION, wp_generate_password(32, false, false), false);
        }
        if (!get_page_by_path(self::PAGE_SLUG)) {
            wp_insert_post([
                'post_title' => 'Invoice Builder',
                'post_name' => self::PAGE_SLUG,
                'post_content' => '[ldc_invoice_builder]',
                'post_status' => 'publish',
                'post_type' => 'page',
                'comment_status' => 'closed',
            ]);
        }
        if (!get_page_by_path(self::LIST_PAGE_SLUG)) {
            wp_insert_post([
                'post_title' => 'Invoice List',
                'post_name' => self::LIST_PAGE_SLUG,
                'post_content' => '[ldc_invoice_list]',
                'post_status' => 'publish',
                'post_type' => 'page',
                'comment_status' => 'closed',
            ]);
        }
        if (!get_page_by_path(self::SETTINGS_PAGE_SLUG)) {
            wp_insert_post([
                'post_title' => 'Invoice Settings',
                'post_name' => self::SETTINGS_PAGE_SLUG,
                'post_content' => '[ldc_invoice_settings]',
                'post_status' => 'publish',
                'post_type' => 'page',
                'comment_status' => 'closed',
            ]);
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::AUTO_UPDATE_HOOK);
    }

    public function ensure_pages(): void {
        if (!get_page_by_path(self::PAGE_SLUG) || !get_page_by_path(self::LIST_PAGE_SLUG) || !get_page_by_path(self::SETTINGS_PAGE_SLUG)) { self::activate(); }
    }

    private function field(string $name, string $label, string $placeholder = '', string $type = 'text', string $step = ''): void {
        printf('<label class="ldc-field"><span>%1$s</span><input type="%2$s" name="%3$s" placeholder="%4$s" %5$s></label>', esc_html($label), esc_attr($type), esc_attr($name), esc_attr($placeholder), $step ? 'step="' . esc_attr($step) . '" min="0"' : '');
    }

    private function textarea(string $name, string $label, string $placeholder = ''): void {
        printf('<label class="ldc-field"><span>%1$s</span><textarea name="%2$s" rows="4" placeholder="%3$s"></textarea></label>', esc_html($label), esc_attr($name), esc_attr($placeholder));
    }

    private function authorize_request(): void {
        check_ajax_referer('ldc_invoice_send', 'nonce');
        $access_key = sanitize_text_field(wp_unslash($_POST['access_key'] ?? ''));
        if (!current_user_can('manage_options') && (!$access_key || !hash_equals($this->get_access_key(), $access_key))) {
            wp_send_json_error(['message' => 'This private link is not valid.'], 403);
        }
    }

    private function encryption_key(): string {
        $material = implode('|', [
            defined('AUTH_KEY') ? AUTH_KEY : '',
            defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '',
            defined('LOGGED_IN_KEY') ? LOGGED_IN_KEY : '',
            defined('NONCE_KEY') ? NONCE_KEY : '',
            $this->get_access_key(),
            home_url('/'),
        ]);
        return hash('sha256', $material, true);
    }

    private function encrypt_payload(array $payload): ?array {
        if (!function_exists('openssl_encrypt') || !function_exists('random_bytes')) { return null; }
        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) { return null; }
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($json, 'aes-256-gcm', $this->encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false || strlen($tag) < 16) { return null; }
        return [
            'encrypted' => true,
            'cipher' => 'aes-256-gcm',
            'version' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($ciphertext),
        ];
    }

    private function decrypt_payload(array $payload): ?array {
        if (empty($payload['encrypted']) || ($payload['cipher'] ?? '') !== 'aes-256-gcm' || !function_exists('openssl_decrypt')) { return null; }
        $iv = base64_decode((string) ($payload['iv'] ?? ''), true);
        $tag = base64_decode((string) ($payload['tag'] ?? ''), true);
        $data = base64_decode((string) ($payload['data'] ?? ''), true);
        if ($iv === false || $tag === false || $data === false) { return null; }
        $json = openssl_decrypt($data, 'aes-256-gcm', $this->encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($json) || $json === '') { return null; }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function get_invoice_records(): array {
        $stored = get_option(self::RECORDS_OPTION, []);
        if (is_array($stored) && !empty($stored['encrypted'])) {
            $records = $this->decrypt_payload($stored);
            return is_array($records) ? $records : [];
        }
        if (is_array($stored)) {
            $this->save_invoice_records($stored);
            $this->write_invoice_backup($stored);
            return $stored;
        }
        return [];
    }

    private function save_invoice_records(array $records): void {
        $encrypted = $this->encrypt_payload($records);
        update_option(self::RECORDS_OPTION, $encrypted ?: $records, false);
    }

    private function backup_file_path(): string {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error']) || empty($uploads['basedir'])) { return ''; }
        $dir = trailingslashit($uploads['basedir']) . 'ldc-invoice-backups';
        $filename = 'invoices-backup-' . substr(hash('sha256', $this->get_access_key()), 0, 16) . '.json';
        return trailingslashit($dir) . $filename;
    }

    private function build_backup_payload(array $records): array {
        uasort($records, static fn($a, $b) => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
        return [
            'format' => 'ldc-invoices-backup-v1',
            'plugin_version' => self::VERSION,
            'generated_at' => current_time('mysql'),
            'count' => count($records),
            'invoices' => array_map(static fn($record) => [
                'id' => (string) ($record['id'] ?? ''),
                'data' => is_array($record['data'] ?? null) ? $record['data'] : [],
            ], array_values($records)),
        ];
    }

    private function write_invoice_backup(array $records): void {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error']) || empty($uploads['basedir'])) { return; }
        $dir = trailingslashit($uploads['basedir']) . 'ldc-invoice-backups';
        if (!wp_mkdir_p($dir)) { return; }
        if (!file_exists($dir . '/index.php')) {
            file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        if (!file_exists($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        $backup = $this->build_backup_payload($records);
        $encrypted = $this->encrypt_payload($backup);
        file_put_contents($this->backup_file_path(), wp_json_encode($encrypted ?: $backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function manage_invoices(): void {
        $this->authorize_request();
        $action = sanitize_key(wp_unslash($_POST['action'] ?? ''));
        $records = $this->get_invoice_records();
        if (!is_array($records)) { $records = []; }

        if ($action === 'ldc_list_invoices') {
            uasort($records, static fn($a, $b) => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
            wp_send_json_success(['records' => array_values($records)]);
        }

        if ($action === 'ldc_download_backup') {
            $backup = $this->build_backup_payload($records);
            $this->write_invoice_backup($records);
            wp_send_json_success(['backup' => $backup, 'filename' => 'ldc-invoices-backup-' . gmdate('Y-m-d') . '.json']);
        }

        $id = sanitize_key(wp_unslash($_POST['id'] ?? ''));
        if ($action === 'ldc_delete_invoice') {
            if (!$id || !isset($records[$id])) { wp_send_json_error(['message' => 'Invoice not found.'], 404); }
            unset($records[$id]);
            $this->save_invoice_records($records);
            $this->write_invoice_backup($records);
            wp_send_json_success(['message' => 'Invoice deleted.']);
        }

        if ($action === 'ldc_save_invoice') {
            $json = wp_unslash($_POST['invoice'] ?? '');
            if (strlen($json) > 500000) { wp_send_json_error(['message' => 'Invoice data is too large.'], 413); }
            $data = json_decode($json, true);
            if (!is_array($data)) { wp_send_json_error(['message' => 'Invalid invoice data.'], 422); }
            $id = $id ?: 'inv_' . strtolower(wp_generate_password(16, false, false));
            $record = [
                'id' => $id,
                'invoice_number' => sanitize_text_field((string) ($data['invoice_number'] ?? 'Invoice')),
                'client_name' => sanitize_text_field((string) ($data['client_name'] ?? '')),
                'client_company' => sanitize_text_field((string) ($data['client_company'] ?? '')),
                'project_name' => sanitize_text_field((string) ($data['project_name'] ?? '')),
                'project_type' => sanitize_text_field((string) ($data['project_type'] ?? '')),
                'project_address' => sanitize_text_field((string) ($data['project_address'] ?? '')),
                'total' => sanitize_text_field((string) ($data['total'] ?? '')),
                'updated_at' => current_time('mysql'),
                'data' => $data,
            ];
            $records[$id] = $record;
            $this->save_invoice_records($records);
            $this->write_invoice_backup($records);
            wp_send_json_success(['message' => 'Invoice saved.', 'record' => $record]);
        }

        wp_send_json_error(['message' => 'Unknown operation.'], 400);
    }

    public function save_company_settings_public(): void {
        $this->authorize_request();
        $settings = [
            'company_name' => sanitize_text_field(wp_unslash($_POST['company_name'] ?? '')),
            'license_number' => sanitize_text_field(wp_unslash($_POST['license_number'] ?? '')),
            'phone' => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            'address_line_1' => sanitize_text_field(wp_unslash($_POST['address_line_1'] ?? '')),
            'address_line_2' => sanitize_text_field(wp_unslash($_POST['address_line_2'] ?? '')),
            'default_tax_rate' => (string) max(0, (float) wp_unslash($_POST['default_tax_rate'] ?? '0')),
        ];
        update_option(self::COMPANY_OPTION, $settings, false);
        $this->set_automatic_updates(!empty($_POST['auto_updates']));
        wp_send_json_success(['message' => 'Company settings saved.', 'settings' => $settings]);
    }

    public function security_check(): void {
        $this->authorize_request();
        $stored = get_option(self::RECORDS_OPTION, []);
        $records = $this->get_invoice_records();
        $backup_path = $this->backup_file_path();
        $backup_raw = ($backup_path && file_exists($backup_path)) ? (string) file_get_contents($backup_path) : '';
        $backup_json = $backup_raw ? json_decode($backup_raw, true) : null;
        $stored_json = wp_json_encode($stored);
        $has_plaintext = is_string($stored_json) && preg_match('/client_email|client_phone|project_address|client_address|invoice_number/i', $stored_json);
        $has_openssl = function_exists('openssl_encrypt') && function_exists('openssl_decrypt');
        $backup_dir = $backup_path ? dirname($backup_path) : '';
        $checks = [
            [
                'label' => 'Invoice database storage',
                'status' => is_array($stored) && !empty($stored['encrypted']) ? 'ok' : 'warning',
                'detail' => is_array($stored) && !empty($stored['encrypted']) ? 'Encrypted in WordPress database.' : 'Not encrypted yet or encryption is unavailable.',
            ],
            [
                'label' => 'Cipher',
                'status' => is_array($stored) && (($stored['cipher'] ?? '') === 'aes-256-gcm') ? 'ok' : 'warning',
                'detail' => is_array($stored) && !empty($stored['cipher']) ? (string) $stored['cipher'] : 'No encrypted cipher detected.',
            ],
            [
                'label' => 'OpenSSL support',
                'status' => $has_openssl ? 'ok' : 'warning',
                'detail' => $has_openssl ? 'Available on this server.' : 'OpenSSL is missing; encrypted storage cannot run.',
            ],
            [
                'label' => 'Saved invoice count',
                'status' => 'info',
                'detail' => count($records) . ' invoice(s) readable by the plugin.',
            ],
            [
                'label' => 'Plaintext client data in storage',
                'status' => $has_plaintext ? 'warning' : 'ok',
                'detail' => $has_plaintext ? 'Readable invoice field names were found in raw storage.' : 'No obvious invoice field names found in raw storage.',
            ],
            [
                'label' => 'Backup file',
                'status' => $backup_raw ? 'ok' : 'info',
                'detail' => $backup_raw ? 'Backup snapshot exists.' : 'No backup snapshot yet. Save or download backup once to create it.',
            ],
            [
                'label' => 'Backup encryption',
                'status' => is_array($backup_json) && !empty($backup_json['encrypted']) ? 'ok' : ($backup_raw ? 'warning' : 'info'),
                'detail' => is_array($backup_json) && !empty($backup_json['encrypted']) ? 'Backup file is encrypted on the server.' : ($backup_raw ? 'Backup exists but is not encrypted.' : 'Backup file not found yet.'),
            ],
            [
                'label' => 'Backup folder protection',
                'status' => ($backup_dir && file_exists($backup_dir . '/.htaccess') && file_exists($backup_dir . '/index.php')) ? 'ok' : 'warning',
                'detail' => ($backup_dir && file_exists($backup_dir . '/.htaccess') && file_exists($backup_dir . '/index.php')) ? '.htaccess and index.php protection files exist.' : 'Protection files are missing or backup folder has not been created.',
            ],
            [
                'label' => 'Private access key',
                'status' => strlen($this->get_access_key()) >= 32 ? 'ok' : 'warning',
                'detail' => 'Access key length: ' . strlen($this->get_access_key()) . ' characters.',
            ],
        ];
        wp_send_json_success(['checks' => $checks, 'checked_at' => current_time('mysql'), 'version' => self::VERSION]);
    }

    public function send_invoice(): void {
        $this->authorize_request();
        $rate_key = 'ldc_invoice_mail_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $rate = (int) get_transient($rate_key);
        if ($rate >= 10) { wp_send_json_error(['message' => 'Email limit reached. Please try again later.'], 429); }
        $recipient = sanitize_email(wp_unslash($_POST['recipient'] ?? ''));
        $cc = sanitize_email(wp_unslash($_POST['cc'] ?? ''));
        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? 'Invoice'));
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
        $pdf_data = (string) wp_unslash($_POST['pdf_data'] ?? '');
        $pdf_filename = sanitize_file_name(wp_unslash($_POST['pdf_filename'] ?? 'invoice.pdf'));
        if (!$recipient || !is_email($recipient)) { wp_send_json_error(['message' => 'Enter a valid client email address.'], 422); }
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if ($cc && is_email($cc)) { $headers[] = 'Cc: ' . $cc; }
        $company = $this->get_company_settings();
        $company_name = $company['company_name'] ?: get_bloginfo('name');
        $body = '<div style="margin:0;padding:28px;background:#f3f6f9;font-family:Arial,sans-serif;color:#202428;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;border-collapse:collapse;background:#ffffff;border:1px solid #d9dee4;border-radius:12px;overflow:hidden;">'
            . '<tr><td style="padding:26px 30px 18px;text-align:center;background:#ffffff;">'
            . '<img src="' . esc_url($this->get_logo_url()) . '" alt="' . esc_attr($company_name) . '" style="display:inline-block;width:170px;max-width:60%;height:auto;">'
            . '</td></tr>'
            . '<tr><td style="padding:0 34px 28px;font-size:16px;line-height:1.65;color:#202428;">'
            . wpautop(esc_html($message))
            . '</td></tr>'
            . '</table>'
            . '</td></tr></table>'
            . '</div>';
        if (!preg_match('#^data:application/pdf;base64,#', $pdf_data)) { wp_send_json_error(['message' => 'PDF attachment could not be generated. Please try again.'], 422); }
        $pdf_binary = base64_decode(substr($pdf_data, strpos($pdf_data, ',') + 1), true);
        if ($pdf_binary === false || strncmp($pdf_binary, '%PDF', 4) !== 0) { wp_send_json_error(['message' => 'Generated PDF attachment is invalid.'], 422); }
        $upload = wp_upload_dir();
        $attachment_dir = trailingslashit($upload['basedir']) . 'ldc-invoice-attachments';
        if (!wp_mkdir_p($attachment_dir)) { wp_send_json_error(['message' => 'Could not prepare the PDF attachment folder.'], 500); }
        $attachment_path = trailingslashit($attachment_dir) . ($pdf_filename ?: 'invoice.pdf');
        if (file_put_contents($attachment_path, $pdf_binary) === false) { wp_send_json_error(['message' => 'Could not save the PDF attachment.'], 500); }
        $sent = wp_mail($recipient, $subject, $body, $headers, [$attachment_path]);
        @unlink($attachment_path);
        if (!$sent) { wp_send_json_error(['message' => 'WordPress could not send the email. Check FluentSMTP logs.'], 500); }
        set_transient($rate_key, $rate + 1, HOUR_IN_SECONDS);
        wp_send_json_success(['message' => 'Invoice sent to ' . $recipient . '.']);
    }
}

new LDC_Invoice_Generator();
register_activation_hook(__FILE__, ['LDC_Invoice_Generator', 'activate']);
register_deactivation_hook(__FILE__, ['LDC_Invoice_Generator', 'deactivate']);

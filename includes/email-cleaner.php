<?php
/**
 * Email Cleaner Module for WP Email Collector
 *
 * Objetivo: Validar, limpiar y gestionar correos electrónicos en WordPress.
 *
 * - Validación básica y avanzada (API externa)
 * - Bloqueo de nuevos correos inválidos
 * - Panel admin para limpieza masiva
 * - Integración con WooCommerce, formularios y registro
 * - Procesamiento en lotes vía AJAX
 * - CRON automático opcional
 *
 * @author: GitHub Copilot (2025)
 */

if (!defined('ABSPATH')) exit;

class RCX_Email_Cleaner {
    // Usar la tabla de suscriptores del plugin
    const DB_TABLE = 'wec_subscribers';
    const MENU_SLUG = 'rcx-email-cleaner';
    const AJAX_ACTION_VALIDATE_BATCH = 'rcx_validate_batch';
    const CRON_HOOK = 'rcx_daily_email_validation';
    const BATCH_SIZE = 50;

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Admin menu
        add_action('admin_menu', [$this, 'register_admin_menu']);
        // AJAX batch validation
        add_action('wp_ajax_' . self::AJAX_ACTION_VALIDATE_BATCH, [$this, 'ajax_validate_batch']);
        // CRON
        add_action(self::CRON_HOOK, [$this, 'cron_validate_pending']);
        // WooCommerce
        add_filter('woocommerce_checkout_fields', [$this, 'filter_woocommerce_checkout_fields']);
        add_action('woocommerce_checkout_process', [$this, 'block_invalid_checkout_email']);
        // User registration
        add_action('user_profile_update_errors', [$this, 'on_user_registration'], 10, 3);
        // TODO: Integrar con formularios (CF7, Elementor, etc.)
        // DB install
        register_activation_hook(__FILE__, [$this, 'install_db_table']);
    }

    // 1. Admin Menu
    public function register_admin_menu() {
        // Solo agregar si no existe ya el submenú (evitar duplicados)
        global $submenu;
        $parent_slug = 'wec-campaigns';
        $submenu_exists = false;
        if (isset($submenu[$parent_slug])) {
            foreach ($submenu[$parent_slug] as $item) {
                if (isset($item[2]) && $item[2] === self::MENU_SLUG) {
                    $submenu_exists = true;
                    break;
                }
            }
        }
        if (!$submenu_exists) {
            add_submenu_page(
                $parent_slug,
                'Limpieza de Correos',
                'Limpieza Emails',
                'manage_options',
                self::MENU_SLUG,
                [$this, 'render_admin_page']
            );
        }
    }

    // 2. Render Admin Page
    public function render_admin_page() {
        if (!current_user_can('manage_options')) return;
    $active_count = $this->get_active_count();
    $per_page = 100;
    $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($page - 1) * $per_page;
    $emails = $this->get_active_emails($offset, $per_page);
    $total = $active_count;
    $total_pages = max(1, ceil($total / $per_page));
    $nonce = wp_create_nonce('rcx_email_cleaner');
        ?>
        <div class="wrap">
            <h1>Limpieza de Correos</h1>
            <p><strong>Suscritos activos:</strong> <?php echo intval($active_count); ?></p>
            <button id="rcx-validate-btn" class="button button-primary">Validar lista completa</button>
            <button id="rcx-delete-invalid-btn" class="button">Eliminar inválidos</button>
            <button id="rcx-export-btn" class="button">Exportar CSV</button>
            <hr>
            <table class="widefat">
                <thead><tr><th>Email</th><th>Estado</th><th>Última validación</th><th>Fuente</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($emails as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row->email); ?></td>
                        <td><?php echo esc_html($row->status); ?></td>
                        <td><?php echo esc_html(isset($row->last_checked_at) ? $row->last_checked_at : ''); ?></td>
                        <td><?php echo esc_html(isset($row->source) ? $row->source : ''); ?></td>
                        <td><button class="button rcx-validate-single" data-id="<?php echo intval($row->id); ?>">Validar</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($total_pages > 1): ?>
                    <div class="tablenav">
                        <div class="tablenav-pages" style="margin: 16px 0; display: flex; align-items: center; gap: 8px;">
                            <?php
                            $base_url = remove_query_arg('paged');
                            $first_url = add_query_arg('paged', 1, $base_url);
                            $prev_url = add_query_arg('paged', max(1, $page - 1), $base_url);
                            $next_url = add_query_arg('paged', min($total_pages, $page + 1), $base_url);
                            $last_url = add_query_arg('paged', $total_pages, $base_url);
                            $start = ($page - 1) * $per_page + 1;
                            $end = min($start + $per_page - 1, $total);
                            echo '<span>Mostrando ' . $start . '–' . $end . ' de ' . $total . '</span>';
                            // Botón primero
                            if ($page > 1) {
                                echo '<a class="button" href="' . esc_url($first_url) . '" title="Primera página">«</a>';
                                echo '<a class="button" href="' . esc_url($prev_url) . '" title="Anterior">‹</a>';
                            } else {
                                echo '<span class="button disabled">«</span>';
                                echo '<span class="button disabled">‹</span>';
                            }
                            // Números de página (máx 7)
                            $window = 3;
                            $start_page = max(1, $page - $window);
                            $end_page = min($total_pages, $page + $window);
                            if ($start_page > 1) echo '<span>...</span>';
                            for ($i = $start_page; $i <= $end_page; $i++) {
                                $url = add_query_arg('paged', $i, $base_url);
                                if ($i == $page) {
                                    echo '<span style="margin:0 4px;font-weight:bold;">' . $i . '</span>';
                                } else {
                                    echo '<a style="margin:0 4px;" href="' . esc_url($url) . '">' . $i . '</a>';
                                }
                            }
                            if ($end_page < $total_pages) echo '<span>...</span>';
                            // Botón siguiente y último
                            if ($page < $total_pages) {
                                echo '<a class="button" href="' . esc_url($next_url) . '" title="Siguiente">›</a>';
                                echo '<a class="button" href="' . esc_url($last_url) . '" title="Última página">»</a>';
                            } else {
                                echo '<span class="button disabled">›</span>';
                                echo '<span class="button disabled">»</span>';
                            }
                            ?>
                        </div>
                    </div>
            <?php endif; ?>
        </div>
        <script>
        jQuery(function($){
            $('#rcx-validate-btn').on('click', function(){
                rcx_validate_batch(<?php echo self::BATCH_SIZE; ?>, '<?php echo $nonce; ?>');
            });
            function rcx_validate_batch(batchSize, nonce) {
                $.post(ajaxurl, {
                    action: '<?php echo self::AJAX_ACTION_VALIDATE_BATCH; ?>',
                    batch_size: batchSize,
                    _wpnonce: nonce
                }, function(resp){
                    if(resp.success && resp.data.remaining > 0) {
                        rcx_validate_batch(batchSize, nonce);
                    } else {
                        location.reload();
                    }
                });
            }
        });
        </script>
        <?php
    }

    // Obtener solo el conteo de suscritos activos
    public function get_active_count() {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE;
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'subscribed'");
    }

    // Obtener solo los emails activos
    public function get_active_emails($offset = 0, $limit = 100) {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE status = 'subscribed' ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset));
    }

    // 3. AJAX batch validation
    public function ajax_validate_batch() {
        check_ajax_referer('rcx_email_cleaner');
        if (!current_user_can('manage_options')) wp_send_json_error('No permission');
        $batch = $this->get_next_batch(self::BATCH_SIZE);
        foreach ($batch as $email) {
            $status = $this->validate_email_api($email->email);
            $this->update_status($email->id, $status);
        }
        wp_send_json_success(['remaining' => $this->remaining_emails_count()]);
    }

    // Ya no se crea tabla nueva, se usa la existente de suscriptores
    public function install_db_table() {
        // No hacer nada, tabla ya gestionada por el plugin principal
    }

    // 5. CRUD helpers
    public function get_status_counts() {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE;
        $rows = $wpdb->get_results("SELECT status, COUNT(*) as cnt FROM $table GROUP BY status");
        $out = [];
        foreach ($rows as $r) $out[$r->status] = $r->cnt;
        return $out;
    }
    public function get_emails($offset = 0, $limit = 100) {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset));
    }
    public function get_next_batch($limit = 50) {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE;
        // Solo validar suscriptores activos o sin status
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE status IN ('subscribed','unchecked','unknown') LIMIT %d", $limit));
    }
    public function update_status($id, $status) {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE;
        $wpdb->update($table, [
            'status' => $status,
            'last_checked_at' => current_time('mysql')
        ], ['id' => $id], ['%s', '%s'], ['%d']);
    }
    public function remaining_emails_count() {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE;
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status IN ('subscribed','unchecked','unknown')");
    }

    // 6. Validación básica
    public function validate_email_basic($email) {
        $email = sanitize_email($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        $domain = substr(strrchr($email, "@"), 1);
        if (!checkdnsrr($domain, "MX")) return false;
        return true;
    }

    // 7. Validación avanzada (API externa)
    public function validate_email_api($email) {
        $api_key = defined('RCX_EMAIL_LIST_VERIFY_KEY') ? RCX_EMAIL_LIST_VERIFY_KEY : '';
        if (!$this->validate_email_basic($email)) return 'invalid';
        if (!$api_key) return 'unknown';
        $url = "https://api.emaillistverify.com/api/verifysingle?apikey={$api_key}&email=" . urlencode($email);
        $response = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($response)) return 'api_error';
        $body = trim(wp_remote_retrieve_body($response));
        // Map API response
        $map = [
            'ok' => 'valid',
            'bad' => 'invalid',
            'disposable' => 'disposable',
            'catch-all' => 'risky',
            'unknown' => 'unknown',
            'spam-trap' => 'dangerous',
        ];
        return $map[$body] ?? 'unknown';
    }

    // 8. WooCommerce integration
    public function filter_woocommerce_checkout_fields($fields) {
        // Optionally add custom validation JS or messages
        return $fields;
    }
    public function block_invalid_checkout_email() {
        $email = $_POST['billing_email'] ?? '';
        if (!$this->validate_email_basic($email)) {
            wc_add_notice('Correo electrónico inválido.', 'error');
        }
    }

    // 9. User registration
    public function on_user_registration($errors, $update, $user) {
        $email = $user->user_email ?? '';
        if (!$this->validate_email_basic($email)) {
            $errors->add('invalid_email', 'Correo electrónico inválido.');
        }
    }

    // 10. CRON automático
    public function cron_validate_pending() {
        $batch = $this->get_next_batch(self::BATCH_SIZE);
        foreach ($batch as $email) {
            $status = $this->validate_email_api($email->email);
            $this->update_status($email->id, $status);
        }
    }

    // 11. Utilidad para insertar nuevos emails (opcional, solo si no existe)
    public function insert_email($email, $source = null) {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE;
        $email = sanitize_email($email);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE email=%s", $email));
        if (!$exists) {
            $wpdb->insert($table, [
                'email' => $email,
                'status' => 'unchecked',
                'source' => sanitize_text_field($source),
                'created_at' => current_time('mysql')
            ], ['%s', '%s', '%s', '%s']);
        }
    }
}

// Inicializar módulo
add_action('plugins_loaded', function(){
    RCX_Email_Cleaner::get_instance();
    // CRON setup
    if (!wp_next_scheduled(RCX_Email_Cleaner::CRON_HOOK)) {
        wp_schedule_event(time(), 'daily', RCX_Email_Cleaner::CRON_HOOK);
    }
});

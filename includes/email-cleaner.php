<?php
// Definir la clave de cifrado en wp-config.php, por ejemplo:
// define('RCX_EMAIL_CRYPT_KEY', 'pon-una-clave-segura-aqui');

function rcx_encrypt_api_key($plain) {
    $key = defined('RCX_EMAIL_CRYPT_KEY') ? RCX_EMAIL_CRYPT_KEY : AUTH_KEY;
    $ivlen = openssl_cipher_iv_length('AES-256-CBC');
    $iv = openssl_random_pseudo_bytes($ivlen);
    $ciphertext = openssl_encrypt($plain, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($iv . $ciphertext);
}

function rcx_decrypt_api_key($enc) {
    $key = defined('RCX_EMAIL_CRYPT_KEY') ? RCX_EMAIL_CRYPT_KEY : AUTH_KEY;
    $data = base64_decode($enc);
    $ivlen = openssl_cipher_iv_length('AES-256-CBC');
    $iv = substr($data, 0, $ivlen);
    $ciphertext = substr($data, $ivlen);
    return openssl_decrypt($ciphertext, 'AES-256-CBC', $key, 0, $iv);
}
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
    // AJAX handler: Validate all emails regardless of status
    public function ajax_validate_all() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No permission');
        }
        check_ajax_referer('rcx_email_cleaner');
    global $wpdb;
    $table = $wpdb->prefix . self::DB_TABLE;
    // Get only emails with status en blanco
    $rows = $wpdb->get_results("SELECT id, email FROM $table WHERE status = ''");
        $validated = 0;
        $error_credit = false;
        foreach ($rows as $row) {
            $status = $this->validate_email_api($row->email);
            if ($status === 'error_credit') {
                $error_credit = true;
            }
            $this->update_status($row->id, $status);
            $validated++;
        }
        wp_send_json_success(['validated' => $validated, 'error_credit' => $error_credit]);
    }
    // AJAX handler: Synchronize subscribers table with unique emails from users/comments
    public function ajax_validate_empty() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No permission');
        }
        check_ajax_referer('rcx_email_cleaner');
        global $wpdb;
        $subscribers_table = $wpdb->prefix . self::DB_TABLE;
        $users_table = $wpdb->prefix . 'users';
        $comments_table = $wpdb->prefix . 'comments';
        $now = current_time('mysql');
        // Get all unique emails from users and approved comments
        $emails = $wpdb->get_col(
            "SELECT DISTINCT email FROM (
                SELECT user_email AS email FROM $users_table WHERE user_email != ''
                UNION
                SELECT comment_author_email AS email FROM $comments_table WHERE comment_approved = 1 AND comment_author_email != ''
            ) AS all_emails"
        );
        // Normalize emails to lowercase for comparison
        $emails = array_map('strtolower', $emails);
        $emails = array_unique($emails);
        // Get all emails currently in subscribers table
        $current = $wpdb->get_col("SELECT email FROM $subscribers_table");
        $current = array_map('strtolower', $current);
        $current = array_unique($current);
        // Emails to insert (in $emails but not in $current)
        $to_insert = array_diff($emails, $current);
        // Emails to delete (in $current but not in $emails)
        $to_delete = array_diff($current, $emails);
        $inserted = 0;
        foreach ($to_insert as $email) {
            $wpdb->insert($subscribers_table, [
                'email' => $email,
                'status' => '',
                'created_at' => $now
            ]);
            $inserted++;
        }
        $deleted = 0;
        foreach ($to_delete as $email) {
            $wpdb->delete($subscribers_table, ['email' => $email]);
            $deleted++;
        }
        wp_send_json_success([
            'inserted' => $inserted,
            'deleted' => $deleted,
            'final_total' => count($emails)
        ]);
    }
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
    // AJAX para validar todos
    add_action('wp_ajax_rcx_validate_all', [$this, 'ajax_validate_all']);
    // AJAX para validar solo status vacío
    add_action('wp_ajax_rcx_validate_empty', [$this, 'ajax_validate_empty']);
    // AJAX para resetear status
    add_action('wp_ajax_rcx_reset_status', [$this, 'ajax_reset_status']);
    // AJAX batch validation
    add_action('wp_ajax_' . self::AJAX_ACTION_VALIDATE_BATCH, [$this, 'ajax_validate_batch']);
    // AJAX single validation
    add_action('wp_ajax_ajax_validate_single', [$this, 'ajax_validate_single']);
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


    // 2. Render Admin Page
    public function render_admin_page() {
        // Modal para error de créditos insuficientes
        ?>
        <div id="rcx-credit-error-modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.5);z-index:99999;align-items:center;justify-content:center;">
            <div style="background:#fff;padding:32px 24px;border-radius:8px;max-width:420px;margin:auto;box-shadow:0 8px 32px rgba(0,0,0,0.2);">
                <h2 style="margin-top:0;color:#d94949;">❌ Sin créditos para validar</h2>
                <p>No tienes créditos suficientes en EmailListVerify para validar más correos.<br>Por favor, recarga tu cuenta para continuar.</p>
                <button id="rcx-close-credit-error-modal" class="button" style="margin-top:12px;">Cerrar</button>
            </div>
        </div>
        <script>
        jQuery(function($){
            $(document).on('click', '#rcx-close-credit-error-modal', function(){
                $('#rcx-credit-error-modal').fadeOut();
            });
            $(document).on('rcx-credit-error', function(){
                $('#rcx-credit-error-modal').css('display','flex');
            });
        });
        </script>
        <?php
        if (!current_user_can('manage_options')) return;
        // Permitir guardar la API KEY desde el frontend
        if (isset($_POST['rcx_save_api_key']) && current_user_can('manage_options')) {
            check_admin_referer('rcx_save_api_key');
            $new_key = sanitize_text_field($_POST['rcx_api_key'] ?? '');
            if ($new_key) {
                $enc = rcx_encrypt_api_key($new_key);
                update_option('rcx_email_list_verify_key', $enc);
                echo '<div class="notice notice-success"><p>API KEY guardada correctamente.</p></div>';
            }
        }
        $api_key = get_option('rcx_email_list_verify_key', '');
        if ($api_key) {
            $api_key = rcx_decrypt_api_key($api_key);
        }
        if (!$api_key && defined('RCX_EMAIL_LIST_VERIFY_KEY')) {
            $api_key = RCX_EMAIL_LIST_VERIFY_KEY;
        }
    $active_count = $this->get_active_count();
    $status_counts = $this->get_status_counts();
    $per_page = 100;
    $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($page - 1) * $per_page;
    $emails = $this->get_active_emails($offset, $per_page);
    $total = $active_count;
    $total_pages = max(1, ceil($total / $per_page));
    $nonce = wp_create_nonce('rcx_email_cleaner');
        ?>
        <style>
        #rcx-loading-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(255,255,255,0.75);
            z-index: 99999;
            align-items: center;
            justify-content: center;
        }
        #rcx-loading-overlay .rcx-spinner {
            border: 6px solid #f3f3f3;
            border-top: 6px solid #3498db;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            animation: rcx-spin 1s linear infinite;
        }
        @keyframes rcx-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        </style>
        <div id="rcx-loading-overlay"><div class="rcx-spinner"></div></div>
        <div class="wrap">
            <h1>Limpieza de Correos</h1>
            <div style="margin-bottom:18px;">
                <?php
                $total_count = 0;
                foreach ($status_counts as $count) $total_count += $count;
                ?>
                <strong>Total:</strong> <?php echo intval($total_count); ?> &nbsp;
                <?php
                // Calcular y mostrar porcentajes
                $empty_count = isset($status_counts['']) ? $status_counts[''] : 0;
                $empty_percent = $total_count > 0 ? round(($empty_count / $total_count) * 100, 1) : 0;
                foreach ($status_counts as $status => $count) {
                    if ($status === '') continue;
                    $percent = $total_count > 0 ? round(($count / $total_count) * 100, 1) : 0;
                    $label = $status === 'subscribed' ? 'Suscritos activos' : ucfirst($status);
                    echo '<strong>' . esc_html($label) . ':</strong> ' . intval($count) . ' (' . $percent . '%) &nbsp; ';
                }
                // Mostrar vacíos al final
                echo '<strong>Vacíos:</strong> ' . intval($empty_count) . ' (' . $empty_percent . '%)';
                ?>
            </div>
            <button id="rcx-validate-btn" class="button button-primary" <?php if (!$api_key) echo 'disabled style="opacity:0.5;pointer-events:none;"'; ?>>Recoletar Correos</button>
            <button id="rcx-validate-all-btn" class="button" <?php if (!$api_key) echo 'disabled style="opacity:0.5;pointer-events:none;"'; ?>>Validar TODOS</button>
            <button id="rcx-api-key-btn" class="button" type="button" style="margin-left:10px;">
                <?php echo $api_key ? 'Cambiar API KEY' : 'Configurar API KEY'; ?>
            </button>
            <hr>
            <table class="widefat">
                <thead><tr><th>Email</th><th>Estado</th><th>Última validación</th><th>Fuente</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($emails as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row->email); ?></td>
                        <td><?php echo esc_html($row->status); ?></td>
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
            // Debug: JS loaded
            console.log('RCX Debug: JS loaded');
            window.rcx_debug_log = function(msg) { try { console.log('RCX Debug:', msg); } catch(e){} };

            function showLoading() {
                $('#rcx-loading-overlay').css('display','flex');
            }
            function hideLoading() {
                $('#rcx-loading-overlay').css('display','none');
            }

            // Handler para botón Validar individual (siempre activo)
            $('.rcx-validate-single').on('click', function(e){
                e.preventDefault();
                var id = $(this).data('id');
                var btn = $(this);
                rcx_debug_log('Click en Validar individual, id=' + id);
                btn.prop('disabled', true).text('Validando...');
                showLoading();
                rcx_debug_log('Enviando AJAX a ' + ajaxurl + ' con action=ajax_validate_single, id=' + id);
                $.post(ajaxurl, {
                    action: 'ajax_validate_single',
                    id: id,
                    _wpnonce: '<?php echo $nonce; ?>'
                }, function(resp){
                    rcx_debug_log('Respuesta AJAX recibida: ' + JSON.stringify(resp));
                    hideLoading();
                    if(resp.success) {
                        btn.text('Validado');
                        setTimeout(function(){ btn.text('Validar').prop('disabled', false); /* location.reload(); */ }, 1000);
                    } else {
                        btn.text('Error');
                        setTimeout(function(){ btn.text('Validar').prop('disabled', false); }, 2000);
                    }
                }).fail(function(xhr, status, error){
                    rcx_debug_log('AJAX fail: ' + status + ' ' + error);
                    hideLoading();
                    btn.text('Error');
                    setTimeout(function(){ btn.text('Validar').prop('disabled', false); }, 2000);
                });
            });

            // Validar solo status vacío
            $('#rcx-validate-btn').on('click', function(e){
                showLoading();
                rcx_debug_log('Click en Validar lista (status vacío)');
                $.post(ajaxurl, {
                    action: 'rcx_validate_empty',
                    _wpnonce: '<?php echo $nonce; ?>'
                }, function(resp){
                    hideLoading();
                    alert('Validación terminada. Recarga la página para ver resultados.');
                }).fail(function(){ hideLoading(); });
            });
            // Validar todos
            $('#rcx-validate-all-btn').on('click', function(e){
                showLoading();
                rcx_debug_log('Click en Validar TODOS');
                $.post(ajaxurl, {
                    action: 'rcx_validate_all',
                    _wpnonce: '<?php echo $nonce; ?>'
                }, function(resp){
                    hideLoading();
                    if(resp.success && resp.data && resp.data.error_credit) {
                        $(document).trigger('rcx-credit-error');
                    } else {
                        alert('Validación de todos terminada. Recarga la página para ver resultados.');
                    }
                }).fail(function(){ hideLoading(); });
            });
        });
        </script>
        <div id="rcx-api-warning-modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
            <div style="background:#fff;padding:32px 24px;border-radius:8px;max-width:420px;margin:auto;box-shadow:0 8px 32px rgba(0,0,0,0.2);">
                <h2 style="margin-top:0;color:#d94949;">⚠️ Configurar API KEY</h2>
                <p>Para validar correos con <b>EmailListVerify</b> necesitas una API KEY.</p>
                <ol style="padding-left:18px;">
                    <li>Regístrate en <a href="https://emaillistverify.com/" target="_blank">emaillistverify.com</a></li>
                    <li>Accede a tu panel y copia tu API KEY</li>
                </ol>
                <form method="post" style="margin-top:16px;">
                    <?php wp_nonce_field('rcx_save_api_key'); ?>
                    <input type="text" name="rcx_api_key" placeholder="Pega tu API KEY aquí" style="width:100%;padding:8px;" required />
                    <button type="submit" name="rcx_save_api_key" class="button button-primary" style="margin-top:10px;width:100%;">Guardar API KEY</button>
                </form>
                <p style="margin-top:12px;color:#888;font-size:13px;">La API KEY se guardará de forma segura en la base de datos.</p>
                <button id="rcx-close-modal" class="button" style="margin-top:12px;">Cerrar</button>
            </div>
        </div>
        <script>
        jQuery(function($){
            // Mostrar modal si falta la API key al cargar
            if(<?php echo $api_key ? 'false' : 'true'; ?>) {
                $('#rcx-api-warning-modal').css('display','flex');
            }
            // Botón para abrir el modal manualmente
            $('#rcx-api-key-btn').on('click', function(){
                $('#rcx-api-warning-modal').css('display','flex');
            });
            $('#rcx-close-modal').on('click',function(){
                $('#rcx-api-warning-modal').fadeOut();
            });
        });
        </script>
        <script>
        jQuery(function($){
            var apiKeyMissing = <?php echo $api_key ? 'false' : 'true'; ?>;
            // Disable buttons if API key is missing (extra safety for dynamic UI)
            if(apiKeyMissing) {
                $('#rcx-validate-btn, #rcx-validate-all-btn').prop('disabled', true).css({'opacity':0.5,'pointer-events':'none'});
            }
            $('#rcx-validate-btn').on('click', function(e){
                if(apiKeyMissing) {
                    $('#rcx-api-warning-modal').css('display','flex');
                    return false;
                }
                rcx_validate_batch(<?php echo self::BATCH_SIZE; ?>, '<?php echo $nonce; ?>');
            });
            $('#rcx-validate-all-btn').on('click', function(e){
                if(apiKeyMissing) {
                    $('#rcx-api-warning-modal').css('display','flex');
                    return false;
                }
                rcx_validate_all('<?php echo $nonce; ?>');
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
                        // location.reload(); // Comentado para evitar recarga automática
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
    $sql = "SELECT COUNT(*) FROM $table WHERE status = 'subscribed'";
    $count = (int)$wpdb->get_var($sql);
    error_log('RCX_Email_Cleaner::get_active_count SQL: ' . $sql . ' => ' . $count);
    return $count;
    }

    // Obtener solo los emails activos
    public function get_active_emails($offset = 0, $limit = 100) {
    global $wpdb;
    $table = $wpdb->prefix . self::DB_TABLE;
    $sql = $wpdb->prepare("SELECT * FROM $table WHERE status = 'subscribed' ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset);
    $results = $wpdb->get_results($sql);
    error_log('RCX_Email_Cleaner::get_active_emails SQL: ' . $sql . ' => rows: ' . count($results));
    return $results;
    }

    // 3. AJAX batch validation
    public function ajax_validate_batch() {
        error_log('RCX_Email_Cleaner::ajax_validate_batch INICIO');
        check_ajax_referer('rcx_email_cleaner');
        if (!current_user_can('manage_options')) {
            error_log('RCX_Email_Cleaner::ajax_validate_batch - No permission');
            wp_send_json_error('No permission');
        }
        $batch = $this->get_next_batch(self::BATCH_SIZE);
        error_log('RCX_Email_Cleaner::ajax_validate_batch - Batch count: ' . count($batch));
        foreach ($batch as $email) {
            error_log('RCX_Email_Cleaner::ajax_validate_batch - Validando: id=' . $email->id . ', email=' . $email->email);
            $status = $this->validate_email_api($email->email);
            error_log('RCX_Email_Cleaner::ajax_validate_batch - Resultado: id=' . $email->id . ', status=' . $status);
            $this->update_status($email->id, $status);
        }
        $remaining = $this->remaining_emails_count();
        error_log('RCX_Email_Cleaner::ajax_validate_batch FIN - Remaining: ' . $remaining);
        wp_send_json_success(['remaining' => $remaining]);
    }

    // Validación individual por AJAX
    public function ajax_validate_single() {
        error_log('RCX_Email_Cleaner::ajax_validate_single INICIO');
        error_log('RCX_Email_Cleaner::ajax_validate_single POST: ' . print_r($_POST, true));
        if (!isset($_POST['_wpnonce'])) {
            error_log('RCX_Email_Cleaner::ajax_validate_single - Falta nonce');
            wp_send_json_error('Falta nonce');
        }
        $nonce_ok = wp_verify_nonce($_POST['_wpnonce'], 'rcx_email_cleaner');
        error_log('RCX_Email_Cleaner::ajax_validate_single - Nonce ok? ' . ($nonce_ok ? 'SI' : 'NO'));
        if (!$nonce_ok) {
            error_log('RCX_Email_Cleaner::ajax_validate_single - Nonce inválido');
            wp_send_json_error('Nonce inválido');
        }
        if (!current_user_can('manage_options')) {
            error_log('RCX_Email_Cleaner::ajax_validate_single - No permission');
            wp_send_json_error('No permission');
        }
        $id = intval($_POST['id'] ?? 0);
        error_log('RCX_Email_Cleaner::ajax_validate_single - ID recibido: ' . $id);
        if (!$id) {
            error_log('RCX_Email_Cleaner::ajax_validate_single - ID vacío');
            wp_send_json_error('ID vacío');
        }
        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id));
        if (!$row) {
            error_log('RCX_Email_Cleaner::ajax_validate_single - No encontrado id=' . $id);
            wp_send_json_error('No encontrado');
        }
        error_log('RCX_Email_Cleaner::ajax_validate_single - Validando: id=' . $row->id . ', email=' . $row->email);
        $status = $this->validate_email_api($row->email);
        error_log('RCX_Email_Cleaner::ajax_validate_single - Resultado: id=' . $row->id . ', status=' . $status);
        $this->update_status($row->id, $status);
        error_log('RCX_Email_Cleaner::ajax_validate_single - FIN OK');
        wp_send_json_success(['status' => $status]);
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
    // Solo validar los que nunca han sido validados (unchecked, unknown)
    // Los que ya tienen status final (subscribed, disposable, email_disabled, etc) no se vuelven a validar
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE status IN ('unchecked','unknown') LIMIT %d", $limit));
    }
    public function update_status($id, $status) {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE;
        // Si la API devuelve 'ok', guardar como 'subscribed'
        // Forzar status a string y loguear antes de guardar
        $status_raw = $status;
        $status = is_null($status) ? '' : trim((string)$status);
        error_log('RCX_Email_Cleaner::update_status id=' . $id . ' status_raw=' . var_export($status_raw, true) . ' status_final=' . var_export($status, true));
        if (strtolower($status) === 'ok') {
            $status = 'subscribed';
        }
        if ($status === '' || is_null($status)) {
            $status = 'unknown';
        }
        $result = $wpdb->update($table, [
            'status' => $status,
            'last_checked_at' => current_time('mysql')
        ], ['id' => $id], ['%s', '%s'], ['%d']);
        error_log('RCX_Email_Cleaner::update_status resultado update id=' . $id . ' status=' . var_export($status, true) . ' result=' . var_export($result, true));
        // Si el update falló, intentar forzar status a 'unknown'
        if ($result === false) {
            $wpdb->update($table, [
                'status' => 'unknown',
                'last_checked_at' => current_time('mysql')
            ], ['id' => $id], ['%s', '%s'], ['%d']);
            error_log('RCX_Email_Cleaner::update_status segundo intento id=' . $id . ' status=unknown');
        }
    }
    public function remaining_emails_count() {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE;
        // Solo contar los que realmente pueden ser validados (pendientes)
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status IN ('unchecked','unknown')");
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
        $api_key = get_option('rcx_email_list_verify_key', '');
        if ($api_key) {
            $api_key = rcx_decrypt_api_key($api_key);
        }
        if (!$api_key && defined('RCX_EMAIL_LIST_VERIFY_KEY')) {
            $api_key = RCX_EMAIL_LIST_VERIFY_KEY;
        }
        if (!$this->validate_email_basic($email)) return 'invalid';
        if (!$api_key) {
            // No cambiar el status si no hay API KEY, devolver 'subscribed' para mantener el estado
            return 'subscribed';
        }
        error_log('RCX_Email_Cleaner::validate_email_api API KEY usada: ' . ($api_key ? $api_key : '[VACIA]'));
        if (!$api_key) {
            error_log('RCX_Email_Cleaner::validate_email_api - API KEY VACIA');
            return 'subscribed';
        }
        $url = "https://apps.emaillistverify.com/api/verifyEmail?secret={$api_key}&email=" . urlencode($email);
        $response = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($response)) {
            error_log('RCX_Email_Cleaner::validate_email_api - Error en wp_remote_get');
            return 'api_error';
        }
        $body = trim(wp_remote_retrieve_body($response));
        error_log('RCX_Email_Cleaner::validate_email_api respuesta API: ' . $body);
        // Intentar decodificar como JSON, si falla, usar texto plano
        $status_raw = '';
        $data = json_decode($body, true);
        if (is_array($data) && isset($data['status'])) {
            $status_raw = $data['status'];
        } else {
            $status_raw = strtolower($body);
        }
        // Guardar el valor crudo devuelto por la API
        error_log('RCX_Email_Cleaner::validate_email_api status guardado: ' . $status_raw);
        return $status_raw;
        // Modal para error de API KEY o validación
        ?>
        <div id="rcx-api-error-modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
            <div style="background:#fff;padding:32px 24px;border-radius:8px;max-width:420px;margin:auto;box-shadow:0 8px 32px rgba(0,0,0,0.2);">
                <h2 style="margin-top:0;color:#d94949;">❌ Error con la API KEY</h2>
                <p>No se pudo validar el correo porque la API KEY está vacía, es incorrecta o la API no responde.<br>Verifica tu clave y tus créditos en EmailListVerify.</p>
                <button id="rcx-close-api-error-modal" class="button" style="margin-top:12px;">Cerrar</button>
            </div>
        </div>
        <script>
        jQuery(function($){
            $(document).on('click', '#rcx-close-api-error-modal', function(){
                $('#rcx-api-error-modal').fadeOut();
            });
            // Hook para mostrar modal si la validación falla por API
            $(document).on('rcx-api-error', function(){
                $('#rcx-api-error-modal').css('display','flex');
            });
            // Interceptar respuesta AJAX de validación individual
            $('.rcx-validate-single').on('click', function(e){
                var btn = $(this);
                setTimeout(function(){
                    // Si el botón muestra "Error" tras validar, mostrar modal
                    if(btn.text() === 'Error') {
                        $(document).trigger('rcx-api-error');
                    }
                }, 1200);
            });
        });
        </script>
        <?php
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

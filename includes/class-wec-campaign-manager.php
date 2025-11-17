<?php
/**
 * WP Email Collector - Campaign Manager
 * 
 * Gestiona todas las funcionalidades relacionadas con campañas:
 * - Creación y edición de campañas
 * - Procesamiento de cola de envíos
 * - Gestión de destinatarios
 * - Estados y monitoreo
 * - Cron y programación
 * 
 * @since 7.0.0
 * @requires PHP 7.4+ (WordPress minimum requirement)
 * @requires WordPress 5.0+
 */

if (!defined('ABSPATH')) exit;

// Verificar requisitos mínimos de PHP
if (version_compare(PHP_VERSION, '7.4', '<')) {
    if (is_admin()) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>WP Email Collector:</strong> ';
            printf(
                __('Este plugin requiere PHP 7.4 o superior. Versión actual: %s. Por favor actualiza PHP para usar este plugin.', 'wp-email-collector'),
                PHP_VERSION
            );
            echo '</p></div>';
        });
    }
    return; // No cargar el resto del código
}

/**
 * Interfaz para gestores de plantillas
 * Permite desacoplamiento del Template Manager
 */
interface WEC_Template_Manager_Interface {
    /**
     * Renderiza una plantilla de email
     * @param int $template_id ID de la plantilla
     * @return array|WP_Error [subject, html_content] o error
     */
    public function render_template_content($template_id);
    
    /**
     * Valida una plantilla antes de usarla
     * @param int $template_id ID de la plantilla
     * @return bool|WP_Error true si válida, WP_Error si no
     */
    public function validate_template($template_id);
}

/**
 * Adaptador para Template Manager
 * Permite compatibilidad con implementaciones que no siguen la interfaz
 */
class WEC_Template_Manager_Adapter implements WEC_Template_Manager_Interface {
    /** @var object Instancia del Template Manager */
    private $manager;
    
    /**
     * Constructor del adaptador
     * @param object $template_manager Instancia del Template Manager
     */
    public function __construct($template_manager) {
        $this->manager = $template_manager;
    }
    
    /**
     * {@inheritdoc}
     */
    public function render_template_content($template_id) {
        if (method_exists($this->manager, 'render_template_content')) {
            return $this->manager->render_template_content($template_id);
        }
        return new WP_Error('method_not_found', 'Template Manager no tiene método render_template_content');
    }
    
    /**
     * {@inheritdoc}
     */
    public function validate_template($template_id) {
        if (method_exists($this->manager, 'validate_template')) {
            return $this->manager->validate_template($template_id);
        }
        // Validación básica como fallback
        $post = get_post($template_id);
        return ($post && $post->post_type === 'wec_email_tpl') ? true : new WP_Error('invalid_template', 'Plantilla no válida');
    }
}

if (!class_exists('WEC_Campaign_Manager')) :

class WEC_Campaign_Manager {
    
    /** @var WEC_Campaign_Manager Instancia única */
    private static $instance = null;
    
    /** @var WEC_Template_Manager_Interface|null Gestor de plantillas */
    private $template_manager = null;
    
    /** Constantes para base de datos */
    const DB_TABLE_JOBS = 'wec_jobs';
    const DB_TABLE_ITEMS = 'wec_job_items';
    const DB_TABLE_SUBSCRIBERS = 'wec_subscribers';
    
    /** Constantes para acciones */
    const ADMIN_POST_CAMPAIGN_CREATE = 'wec_create_campaign';
    const ADMIN_POST_CAMPAIGN_UPDATE = 'wec_update_campaign';
    const ADMIN_POST_CAMPAIGN_DELETE = 'wec_delete_campaign';
    const ADMIN_POST_FORCE_CRON = 'wec_force_cron';
    
    /** Constantes para cron */
    const CRON_HOOK = 'wec_process_queue';
    const CRON_SECRET_OPTION = 'wec_cron_secret';
    
    /** Constantes para plantillas */
    const EMAIL_TEMPLATE_POST_TYPE = 'wec_email_tpl';
    
    /**
     * Obtiene la instancia única (Singleton)
     * @return WEC_Campaign_Manager
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor privado para Singleton
     */
    private function __construct() {
        $this->init_hooks();
        $this->init_dependencies();
    }
    
    /**
     * Inicializa dependencias con otros managers
     */
    private function init_dependencies() {
        // Configurar Template Manager si está disponible
        if (class_exists('WEC_Template_Manager')) {
            try {
                $manager = WEC_Template_Manager::get_instance();
                
                if ($manager instanceof WEC_Template_Manager_Interface) {
                    $this->template_manager = $manager;
                } else {
                    // Crear adapter para compatibilidad
                    $this->template_manager = new WEC_Template_Manager_Adapter($manager);
                }
            } catch (Exception $e) {
                error_log('WEC_Campaign_Manager: Error inicializando Template Manager: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Obtiene o crea un secreto seguro para el cron externo
     * @return string Secreto de 32 caracteres para autenticación del cron
     */
    private function get_or_create_cron_secret() {
        // Si hay constante definida, usarla (para compatibilidad)
        if (defined('WEC_CRON_SECRET') && !empty(WEC_CRON_SECRET)) {
            return WEC_CRON_SECRET;
        }
        
        // Obtener secreto almacenado
        $secret = get_option(self::CRON_SECRET_OPTION);
        
        // Si no existe o es el viejo secreto débil, generar uno nuevo
        if (empty($secret) || $secret === 'curren_email_cron_2024') {
            try {
                // Generar secreto criptográficamente seguro
                $secret = wp_generate_password(32, true, true);
                
                // Verificar que el secreto generado es válido y tiene la longitud correcta
                if (strlen($secret) < 32) {
                    throw new Exception('Generated secret too short');
                }
                
                // Guardar en base de datos
                update_option(self::CRON_SECRET_OPTION, $secret, false); // autoload = false
                
                error_log('WEC: Nuevo secreto de cron generado y almacenado de forma segura');
                
            } catch (Exception $e) {
                // Fallback extremo si wp_generate_password falla
                $secret = hash('sha256', uniqid(mt_rand(), true) . time() . wp_salt());
                update_option(self::CRON_SECRET_OPTION, $secret, false);
                
                error_log('WEC: Error generando secreto, usando fallback hash: ' . $e->getMessage());
            }
        }
        
        return $secret;
    }
    
    /**
     * Inicializa los hooks de WordPress
     */
    private function init_hooks() {
        // Handlers para admin-post
        add_action('admin_post_' . self::ADMIN_POST_CAMPAIGN_CREATE, [$this, 'handle_create_campaign']);
        add_action('admin_post_' . self::ADMIN_POST_CAMPAIGN_UPDATE, [$this, 'handle_update_campaign']);
        add_action('admin_post_' . self::ADMIN_POST_CAMPAIGN_DELETE, [$this, 'handle_delete_campaign']);
        add_action('admin_post_' . self::ADMIN_POST_FORCE_CRON, [$this, 'handle_force_cron']);
        
        // Hooks de cron
        add_action(self::CRON_HOOK, [$this, 'process_queue_cron']);
        add_action('wp', [$this, 'setup_recurring_cron']);
        add_action('init', [$this, 'handle_external_cron']);
    }
    
    /**
     * Renderiza la página principal de campañas
     */
    public function render_campaigns_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permisos para acceder a esta página.', 'wp-email-collector'));
        }
        
        global $wpdb;
        $table_jobs = $wpdb->prefix . self::DB_TABLE_JOBS;
        $jobs = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table_jobs} ORDER BY id DESC LIMIT %d", 100));
        $templates = $this->get_available_templates();
        
        $edit_job = isset($_GET['edit_job']) ? intval($_GET['edit_job']) : 0;
        $job_to_edit = null;
        if ($edit_job) {
            $job_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_jobs} WHERE id=%d", $edit_job));
        }
        
        $this->render_campaigns_html($jobs, $templates, $job_to_edit);
    }
    
    /**
     * Renderiza el HTML de la página de campañas
     */
    private function render_campaigns_html($jobs, $templates, $job_to_edit = null) {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Gestión de Campañas', 'wp-email-collector'); ?></h1>
            
            <p>
                <a href="<?php echo esc_url(admin_url('admin-post.php?action=' . self::ADMIN_POST_FORCE_CRON . '&_wpnonce=' . wp_create_nonce('wec_force_cron'))); ?>" 
                   class="button"><?php esc_html_e('Procesar Cola Manualmente', 'wp-email-collector'); ?></a>
                <a href="<?php echo esc_url($this->get_external_cron_url()); ?>" 
                   class="button button-primary" target="_blank"><?php esc_html_e('🔗 Probar Cron Externo', 'wp-email-collector'); ?></a>
            </p>
            
            <?php if ($job_to_edit): ?>
                <?php $this->render_edit_campaign_form($job_to_edit, $templates); ?>
                <hr/>
            <?php endif; ?>
            
            <?php $this->render_create_campaign_form($templates); ?>
            
            <?php $this->render_campaigns_list($jobs); ?>
        </div>
        <?php
        
        // Agregar el modal de vista previa
        echo $this->render_preview_modal_html();
    }
    
    /**
     * Renderiza formulario de edición de campaña
     */
    private function render_edit_campaign_form($job_to_edit, $templates) {
        ?>
        <h2><?php printf(__('Editar campaña #%d', 'wp-email-collector'), intval($job_to_edit->id)); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ADMIN_POST_CAMPAIGN_UPDATE); ?>">
            <input type="hidden" name="job_id" value="<?php echo intval($job_to_edit->id); ?>">
            <?php wp_nonce_field('wec_campaign_update_' . $job_to_edit->id); ?>
            
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Plantilla', 'wp-email-collector'); ?></th>
                    <td class="wec-inline">
                        <select name="tpl_id" id="wec_template_id_edit">
                            <?php foreach($templates as $tpl): ?>
                            <option value="<?php echo esc_attr($tpl->ID); ?>" <?php selected($tpl->ID, $job_to_edit->tpl_id); ?>>
                                <?php echo esc_html($tpl->post_title); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button id="wec-btn-preview-edit" type="button" class="button wec-btn-preview" data-target="#wec_template_id_edit">
                            <?php esc_html_e('Vista previa', 'wp-email-collector'); ?>
                        </button>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Inicio', 'wp-email-collector'); ?></th>
                    <td>
                        <input type="datetime-local" name="start_at" value="<?php echo esc_attr($this->convert_mysql_to_local($job_to_edit->start_at)); ?>">
                        <p class="wec-help"><?php esc_html_e('Déjalo vacío para empezar de inmediato. Hora de Ciudad de México (CDMX)', 'wp-email-collector'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Lote por minuto', 'wp-email-collector'); ?></th>
                    <td>
                        <input type="number" name="rate_per_minute" value="<?php echo intval($job_to_edit->rate_per_minute ?: 100); ?>" min="1" step="1">
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <button class="button button-primary"><?php esc_html_e('Guardar cambios', 'wp-email-collector'); ?></button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wec-campaigns')); ?>" class="button">
                    <?php esc_html_e('Cancelar', 'wp-email-collector'); ?>
                </a>
            </p>
        </form>
        <?php
    }
    
    /**
     * Renderiza formulario de creación de campaña
     */
    private function render_create_campaign_form($templates) {
        ?>
        <h2><?php esc_html_e('Crear nueva campaña', 'wp-email-collector'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ADMIN_POST_CAMPAIGN_CREATE); ?>">
            <?php wp_nonce_field('wec_campaign_create'); ?>
            
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Plantilla', 'wp-email-collector'); ?></th>
                    <td class="wec-inline">
                        <select name="tpl_id" id="wec_template_id_create">
                            <?php foreach($templates as $tpl): ?>
                            <option value="<?php echo esc_attr($tpl->ID); ?>"><?php echo esc_html($tpl->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button id="wec-btn-preview-create" type="button" class="button wec-btn-preview" data-target="#wec_template_id_create">
                            <?php esc_html_e('Vista previa', 'wp-email-collector'); ?>
                        </button>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Destinatarios', 'wp-email-collector'); ?></th>
                    <td>
                        <label>
                            <input type="radio" name="recipients_mode" value="scan" checked>
                            <?php esc_html_e('Usar escaneo de todo el sitio', 'wp-email-collector'); ?>
                        </label><br>
                        <label>
                            <input type="radio" name="recipients_mode" value="paste">
                            <?php esc_html_e('Pegar correos (uno por línea)', 'wp-email-collector'); ?>
                        </label><br>
                        <textarea name="recipients_list" rows="6" cols="80" 
                                  placeholder="correo1@ejemplo.com&#10;correo2@ejemplo.com" 
                                  style="width:100%;max-width:700px;"></textarea>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Inicio', 'wp-email-collector'); ?></th>
                    <td>
                        <input type="datetime-local" name="start_at">
                        <p class="wec-help"><?php esc_html_e('Déjalo vacío para empezar de inmediato. Hora de Ciudad de México (CDMX)', 'wp-email-collector'); ?></p>
                        <p class="wec-help" style="color:#0073aa;">
                            <strong>💡 Tip:</strong> <?php esc_html_e('El sistema ajustará automáticamente tu hora local a la zona horaria del servidor.', 'wp-email-collector'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Lote por minuto', 'wp-email-collector'); ?></th>
                    <td>
                        <input type="number" name="rate_per_minute" value="100" min="1" step="1">
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <button class="button button-primary"><?php esc_html_e('Crear campaña', 'wp-email-collector'); ?></button>
            </p>
        </form>
        <?php
    }
    
    /**
     * Renderiza la lista de campañas
     */
    private function render_campaigns_list($jobs) {
        ?>
        <h2><?php esc_html_e('Campañas recientes', 'wp-email-collector'); ?></h2>
        <table class="wec-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('ID', 'wp-email-collector'); ?></th>
                    <th><?php esc_html_e('Estado', 'wp-email-collector'); ?></th>
                    <th><?php esc_html_e('Plantilla', 'wp-email-collector'); ?></th>
                    <th><?php esc_html_e('Inicio', 'wp-email-collector'); ?></th>
                    <th><?php esc_html_e('Total', 'wp-email-collector'); ?></th>
                    <th><?php esc_html_e('Enviados', 'wp-email-collector'); ?></th>
                    <th><?php esc_html_e('Fallidos', 'wp-email-collector'); ?></th>
                    <th><?php esc_html_e('Acciones', 'wp-email-collector'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($jobs): foreach($jobs as $job): ?>
                <tr>
                    <td>#<?php echo intval($job->id); ?></td>
                    <td><?php echo $this->get_status_html($job->status); ?></td>
                    <td><?php echo esc_html(get_the_title($job->tpl_id)); ?></td>
                    <td><?php echo esc_html($this->format_display_datetime($job->start_at)); ?></td>
                    <td><?php echo intval($job->total); ?></td>
                    <td><?php echo intval($job->sent); ?></td>
                    <td><?php echo intval($job->failed); ?></td>
                    <td class="wec-inline">
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wec-campaigns&edit_job=' . $job->id)); ?>">
                            <?php esc_html_e('Editar', 'wp-email-collector'); ?>
                        </a>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                            <input type="hidden" name="action" value="<?php echo esc_attr(self::ADMIN_POST_CAMPAIGN_DELETE); ?>">
                            <input type="hidden" name="job_id" value="<?php echo intval($job->id); ?>"/>
                            <?php wp_nonce_field('wec_campaign_delete_' . $job->id); ?>
                            <button class="button-link-delete" onclick="return confirm('<?php echo esc_js(sprintf(__('¿Eliminar campaña #%d?', 'wp-email-collector'), $job->id)); ?>')">
                                <?php esc_html_e('Eliminar', 'wp-email-collector'); ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr>
                    <td colspan="8"><?php esc_html_e('No hay campañas.', 'wp-email-collector'); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Obtiene el HTML para mostrar el estado de una campaña
     */
    private function get_status_html($status) {
        $status_configs = [
            'pending' => ['label' => __('⏳ Pendiente', 'wp-email-collector'), 'class' => 'status-pending'],
            'running' => ['label' => __('▶️ Ejecutando', 'wp-email-collector'), 'class' => 'status-running'],
            'done'    => ['label' => __('✅ Completada', 'wp-email-collector'), 'class' => 'status-done'],
            'expired' => ['label' => __('⚠️ Expirada', 'wp-email-collector'), 'class' => 'status-expired'],
        ];
        
        $config = $status_configs[$status] ?? ['label' => esc_html($status), 'class' => ''];
        
        return sprintf(
            '<span class="%s">%s</span>',
            esc_attr($config['class']),
            $config['label']
        );
    }
    
    /**
     * Maneja la creación de nuevas campañas
     */
    public function handle_create_campaign() {
        if (!current_user_can('manage_options')) {
            wp_die(__('No autorizado.', 'wp-email-collector'));
        }
        
        check_admin_referer('wec_campaign_create');
        
        $tpl_id = intval($_POST['tpl_id'] ?? 0);
        $mode = sanitize_text_field($_POST['recipients_mode'] ?? 'scan');
        $list_raw = wp_unslash($_POST['recipients_list'] ?? '');
        $start_at = sanitize_text_field($_POST['start_at'] ?? '');
        $rate_per_min = max(1, min(1000, intval($_POST['rate_per_minute'] ?? 100)));
        
        if (!$tpl_id) {
            wp_die(__('Selecciona una plantilla.', 'wp-email-collector'));
        }
        
        // Validar plantilla si tenemos Template Manager
        if ($this->template_manager) {
            $validation = $this->template_manager->validate_template($tpl_id);
            if (is_wp_error($validation)) {
                wp_die(__('Error en la plantilla: ', 'wp-email-collector') . $validation->get_error_message());
            }
        }
        
        // Construir lista de destinatarios
        $emails = ($mode === 'paste' && trim($list_raw) !== '') 
            ? $this->parse_pasted_emails($list_raw) 
            : $this->gather_emails_full_scan();
        
        // Excluir desuscritos
        $emails = $this->filter_unsubscribed($emails);
        
        if (empty($emails)) {
            wp_die(__('No se encontraron destinatarios válidos.', 'wp-email-collector'));
        }
        
        // Crear campaña en base de datos
        $job_id = $this->create_campaign_in_db($tpl_id, $start_at, $rate_per_min, $emails);
        
        if (!$job_id) {
            wp_die(__('Error al crear la campaña.', 'wp-email-collector'));
        }
        
        // Programar crons para ejecución
        $this->schedule_campaign_crons($start_at);
        
        wp_safe_redirect(admin_url('admin.php?page=wec-campaigns'));
        exit;
    }
    
    /**
     * Maneja la actualización de campañas
     */
    public function handle_update_campaign() {
        if (!current_user_can('manage_options')) {
            wp_die(__('No autorizado.', 'wp-email-collector'));
        }
        
        $job_id = intval($_POST['job_id'] ?? 0);
        check_admin_referer('wec_campaign_update_' . $job_id);
        
        $tpl_id = intval($_POST['tpl_id'] ?? 0);
        $start_at = sanitize_text_field($_POST['start_at'] ?? '');
        $rate_per_min = max(1, min(1000, intval($_POST['rate_per_minute'] ?? 100)));
        
        if (!$job_id || !$tpl_id) {
            wp_die(__('Datos incompletos.', 'wp-email-collector'));
        }
        
        // Actualizar en base de datos
        global $wpdb;
        $table_jobs = $wpdb->prefix . self::DB_TABLE_JOBS;
        
        $data = ['tpl_id' => $tpl_id, 'rate_per_minute' => $rate_per_min];
        $fmt = ['%d', '%d'];
        
        if ($start_at !== '') {
            $data['start_at'] = $this->convert_local_to_mysql($start_at);
            $fmt[] = '%s';
        }
        
        $wpdb->update($table_jobs, $data, ['id' => $job_id], $fmt, ['%d']);
        
        wp_safe_redirect(admin_url('admin.php?page=wec-campaigns'));
        exit;
    }
    
    /**
     * Maneja la eliminación de campañas
     */
    public function handle_delete_campaign() {
        if (!current_user_can('manage_options')) {
            wp_die(__('No autorizado.', 'wp-email-collector'));
        }
        
        $job_id = intval($_POST['job_id'] ?? 0);
        check_admin_referer('wec_campaign_delete_' . $job_id);
        
        global $wpdb;
        $table_jobs = $wpdb->prefix . self::DB_TABLE_JOBS;
        $table_items = $wpdb->prefix . self::DB_TABLE_ITEMS;
        
        $wpdb->delete($table_jobs, ['id' => $job_id], ['%d']);
        $wpdb->delete($table_items, ['job_id' => $job_id], ['%d']);
        
        wp_safe_redirect(admin_url('admin.php?page=wec-campaigns'));
        exit;
    }
    
    /**
     * Maneja la ejecución forzada del cron
     */
    public function handle_force_cron() {
        if (!current_user_can('manage_options')) {
            wp_die(__('No autorizado.', 'wp-email-collector'));
        }
        
        check_admin_referer('wec_force_cron');
        
        // Ejecutar el procesamiento directamente
        $this->process_queue_cron();
        
        wp_safe_redirect(admin_url('admin.php?page=wec-campaigns'));
        exit;
    }
    
    /**
     * Procesa la cola de envíos (función principal del cron)
     */
    public function process_queue_cron() {
        global $wpdb;
        $table_jobs = $wpdb->prefix . self::DB_TABLE_JOBS;
        $table_items = $wpdb->prefix . self::DB_TABLE_ITEMS;
        
        // PASO 1: Marcar como expiradas las campañas pendientes de días anteriores
        $yesterday_end_cdmx = date('Y-m-d 23:59:59', strtotime('-1 day'));
        $yesterday_end_utc = $this->convert_local_to_mysql($yesterday_end_cdmx);
        
        $expired_count = $wpdb->query($wpdb->prepare(
            "UPDATE {$table_jobs} 
             SET status = 'expired' 
             WHERE status = 'pending' 
             AND start_at <= %s", 
            $yesterday_end_utc
        ));
        
        if ($expired_count > 0) {
            error_log("WEC: Se marcaron {$expired_count} campañas como expiradas (de días anteriores)");
        }
        
        // LIMPIEZA: Eliminar campañas expiradas muy antiguas (más de 30 días)
        $cleanup_date = date('Y-m-d 23:59:59', strtotime('-30 days'));
        $cleanup_date_utc = $this->convert_local_to_mysql($cleanup_date);
        
        $cleanup_count = $wpdb->query($wpdb->prepare(
            "DELETE j, i FROM {$table_jobs} j 
             LEFT JOIN {$table_items} i ON j.id = i.job_id 
             WHERE j.status = 'expired' 
             AND j.start_at <= %s", 
            $cleanup_date_utc
        ));
        
        if ($cleanup_count > 0) {
            error_log("WEC: Se eliminaron {$cleanup_count} campañas expiradas antiguas (>30 días)");
        }
        
        // PASO 2: Obtener campaña pendiente cuya hora haya llegado HOY específicamente
        $current_time_utc = $this->get_current_time_cdmx();
        
        $today_start_cdmx = date('Y-m-d 00:00:00');
        $today_end_cdmx = date('Y-m-d 23:59:59');
        $today_start_utc = $this->convert_local_to_mysql($today_start_cdmx);
        $today_end_utc = $this->convert_local_to_mysql($today_end_cdmx);
        
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_jobs} 
             WHERE status IN('pending','running') 
             AND start_at <= %s 
             AND start_at >= %s 
             AND start_at <= %s
             ORDER BY id ASC LIMIT 1", 
            $current_time_utc, 
            $today_start_utc,
            $today_end_utc
        ));
        
        if (!$job) {
            return;
        }
        
        if ($job->status === 'pending') {
            $wpdb->update($table_jobs, ['status' => 'running'], ['id' => $job->id], ['%s'], ['%d']);
        }
        
        $limit = max(1, intval($job->rate_per_minute ?: 100));
        
        // Procesar por lote
        $batch = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_items} WHERE job_id=%d AND status='queued' LIMIT %d", 
            $job->id, $limit
        ));
        
        if (!$batch) {
            // Sin pendientes -> finalizar
            $wpdb->update($table_jobs, ['status' => 'done'], ['id' => $job->id], ['%s'], ['%d']);
            return;
        }
        
        // Procesar el lote
        $this->process_email_batch($job, $batch);
        
        // Programar siguiente ejecución si hay más trabajo pendiente
        $pending_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_items} WHERE job_id=%d AND status='queued'", 
            $job->id
        ));
        
        if ($pending_count > 0) {
            wp_schedule_single_event(time() + 60, self::CRON_HOOK);
        }
        
        // Verificar si hay otros trabajos pendientes para procesar
        $next_job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_jobs} 
             WHERE status IN('pending','running') 
             AND start_at <= %s 
             AND start_at >= %s 
             AND start_at <= %s
             AND id != %d 
             ORDER BY id ASC LIMIT 1", 
            $current_time_utc, 
            $today_start_utc,
            $today_end_utc,
            $job->id 
        ));
        
        if ($next_job) {
            wp_schedule_single_event(time() + 30, self::CRON_HOOK);
        }
    }
    
    /**
     * Procesa un lote de emails para envío
     */
    private function process_email_batch($job, $batch) {
        // Renderizar plantilla una sola vez
        $template_result = $this->render_template_content($job->tpl_id);
        
        if (is_wp_error($template_result)) {
            // Error en la plantilla - marcar job como fallido
            global $wpdb;
            $table_jobs = $wpdb->prefix . self::DB_TABLE_JOBS;
            $wpdb->update($table_jobs, ['status' => 'failed'], ['id' => $job->id], ['%s'], ['%d']);
            error_log("WEC: Error al renderizar plantilla {$job->tpl_id} para job {$job->id}: " . $template_result->get_error_message());
            return;
        }
        
        list($subject, $html_raw) = $template_result;
        
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $sent = 0;
        $failed = 0;
        
        foreach ($batch as $item) {
            if ($this->is_unsubscribed($item->email)) {
                $this->mark_item_failed($item->id, $item->attempts, 'unsubscribed');
                $failed++;
                continue;
            }
            
            // Generar HTML personalizado para cada destinatario
            $html_personal = $this->build_email_html($html_raw, $item->email, [
                'inline'        => true,
                'preserve_css'  => false,
                'reset_links'   => true
            ]);
            
            // Activar filtro HTML solo para este email
            add_filter('wp_mail_content_type', [$this, 'set_mail_content_type']);
            
            $ok = wp_mail($item->email, $subject, $html_personal, $headers);
            
            // Remover filtro inmediatamente después del envío
            remove_filter('wp_mail_content_type', [$this, 'set_mail_content_type']);
            
            if ($ok) {
                $this->mark_item_sent($item->id, $item->attempts);
                $sent++;
            } else {
                $this->mark_item_failed($item->id, $item->attempts, 'wp_mail false');
                $failed++;
            }
        }
        
        // Actualizar totales
        $this->update_job_stats($job->id, $sent, $failed);
    }
    
    /**
     * Configura cron persistente
     */
    public function setup_recurring_cron() {
        // Solo configurar en frontend para evitar conflictos en admin
        if (is_admin()) return;
        
        global $wpdb;
        $table_jobs = $wpdb->prefix . self::DB_TABLE_JOBS;
        $pending_jobs = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_jobs} WHERE status IN(%s, %s)", 'pending', 'running'));
        
        if ($pending_jobs > 0) {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_single_event(time() + 60, self::CRON_HOOK);
            }
        }
    }
    
    /**
     * Maneja cron externo via URL
     */
    public function handle_external_cron() {
        if (!isset($_GET['wec_cron']) || $_GET['wec_cron'] !== 'true') {
            return;
        }
        
        $secret = sanitize_text_field($_GET['secret'] ?? '');
        $expected_secret = $this->get_or_create_cron_secret();
        
        if (!hash_equals($expected_secret, $secret)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "ERROR 403: Clave secreta incorrecta\n";
            echo "Usa: ?wec_cron=true&secret=tu_clave_secreta\n";
            exit;
        }
        
        ob_start();
        
        try {
            $this->process_queue_cron();
            
            global $wpdb;
            $table_jobs = $wpdb->prefix . self::DB_TABLE_JOBS;
            
            $pending_jobs = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_jobs} WHERE status IN(%s, %s)", 'pending', 'running'));
            
            ob_get_clean();
            
            http_response_code(200);
            header('Content-Type: text/plain; charset=UTF-8');
            
            // Respuesta minimalista para producción
            echo "OK\n";
            if ($pending_jobs > 0) {
                echo "PENDING\n";
            } else {
                echo "COMPLETE\n";
            }
            echo date('Y-m-d H:i:s') . "\n";
            
        } catch (Exception $e) {
            ob_end_clean();
            
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            
            // Error mínimo para producción
            echo "ERROR\n";
            echo date('Y-m-d H:i:s') . "\n";
            
            error_log("WEC_EXTERNAL_CRON_ERROR: " . $e->getMessage());
        }
        
        exit;
    }
    
    /**
     * Establece tipo de contenido HTML para emails de campaña
     * NOTA: Este filtro se activa/desactiva solo durante el envío de emails
     * de campaña para evitar afectar otros emails del sitio
     */
    public function set_mail_content_type() {
        return 'text/html';
    }
    
    // ===========================================
    // MÉTODOS AUXILIARES Y DE UTILIDAD
    // ===========================================
    
    /**
     * Obtiene plantillas disponibles
     */
    private function get_available_templates() {
        return get_posts([
            'post_type'   => self::EMAIL_TEMPLATE_POST_TYPE, 
            'numberposts' => -1, 
            'post_status' => ['publish', 'draft']
        ]);
    }
    
    /**
     * Convierte fecha/hora local CDMX a formato MySQL UTC
     */
    private function convert_local_to_mysql($datetime_local) {
        if (empty($datetime_local)) {
            return current_time('mysql');
        }
        
        $cdmx_tz = new DateTimeZone('America/Mexico_City');
        $utc_tz = new DateTimeZone('UTC');
        
        try {
            $dt = new DateTime($datetime_local, $cdmx_tz);
            $dt->setTimezone($utc_tz);
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return current_time('mysql');
        }
    }
    
    /**
     * Convierte fecha MySQL UTC a formato local CDMX para mostrar
     */
    private function convert_mysql_to_local($datetime_mysql) {
        if (empty($datetime_mysql)) {
            return '';
        }
        
        $utc_tz = new DateTimeZone('UTC');
        $cdmx_tz = new DateTimeZone('America/Mexico_City');
        
        try {
            $dt = new DateTime($datetime_mysql, $utc_tz);
            $dt->setTimezone($cdmx_tz);
            return $dt->format('Y-m-d\TH:i');
        } catch (Exception $e) {
            return $datetime_mysql;
        }
    }
    
    /**
     * Formatea fecha MySQL UTC para mostrar en CDMX
     */
    private function format_display_datetime($datetime_mysql) {
        if (empty($datetime_mysql)) {
            return __('Inmediato', 'wp-email-collector');
        }
        
        $utc_tz = new DateTimeZone('UTC');
        $cdmx_tz = new DateTimeZone('America/Mexico_City');
        
        try {
            $dt = new DateTime($datetime_mysql, $utc_tz);
            $dt->setTimezone($cdmx_tz);
            return $dt->format('d/m/Y H:i') . ' CDMX';
        } catch (Exception $e) {
            return $datetime_mysql;
        }
    }
    
    /**
     * Obtiene hora actual CDMX convertida a UTC
     */
    private function get_current_time_cdmx() {
        $current_wp = current_time('mysql');
        
        if (get_option('timezone_string') === 'America/Mexico_City') {
            $cdmx_tz = new DateTimeZone('America/Mexico_City');
            $utc_tz = new DateTimeZone('UTC');
            
            try {
                $dt = new DateTime($current_wp, $cdmx_tz);
                $dt->setTimezone($utc_tz);
                return $dt->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                return $current_wp;
            }
        }
        
        return $current_wp;
    }
    
    /**
     * Escanea todo el sitio en busca de emails
     */
    private function gather_emails_full_scan() {
        $emails = [];
        
        // Usuarios
        $users = get_users(['fields' => ['user_email'], 'number' => 5000]);
        foreach($users as $u) {
            if (is_email($u->user_email)) {
                $emails[] = strtolower($u->user_email);
            }
        }
        
        // Comentarios paginados
        $paged = 1;
        $per = 1000;
        
        do {
            $cmts = get_comments([
                'status' => 'approve',
                'type' => 'comment',
                'number' => $per,
                'paged' => $paged,
                'fields' => 'ids'
            ]);
            
            if (!$cmts) break;
            
            foreach($cmts as $cid) {
                $c = get_comment($cid);
                if ($c && is_email($c->comment_author_email)) {
                    $emails[] = strtolower($c->comment_author_email);
                }
            }
            
            $paged++;
        } while (count($cmts) === $per);
        
        return array_values(array_unique($emails));
    }
    
    /**
     * Parsea lista de emails pegados manualmente
     */
    private function parse_pasted_emails($raw) {
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $out = [];
        
        foreach($lines as $ln) {
            $ln = trim($ln);
            if ($ln && is_email($ln)) {
                $out[] = strtolower($ln);
            }
        }
        
        return array_values(array_unique($out));
    }
    
    /**
     * Filtra emails desuscritos
     */
    private function filter_unsubscribed($emails) {
        $emails = array_values(array_unique(array_map(function($e) {
            return strtolower(trim($e));
        }, $emails)));
        
        if (empty($emails)) return $emails;
        
        // Limitar el tamaño del array para prevenir problemas de memoria/rendimiento
        $max_batch_size = 1000;
        if (count($emails) > $max_batch_size) {
            error_log("WEC: Procesando lista grande de emails (" . count($emails) . "), dividiendo en lotes");
        }
        
        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE_SUBSCRIBERS;
        $blocked = [];
        
        // Procesar en lotes para evitar problemas con consultas muy grandes
        $chunks = array_chunk($emails, $max_batch_size);
        
        foreach ($chunks as $chunk) {
            // Validar que todos los emails en el chunk son válidos
            $valid_emails = array_filter($chunk, function($email) {
                return is_email($email) && strlen($email) <= 254; // RFC 5321 limit
            });
            
            if (empty($valid_emails)) {
                continue;
            }
            
            // Consulta segura usando prepared statements
            $chunk_blocked = $wpdb->get_col($wpdb->prepare(
                "SELECT email FROM {$table} 
                 WHERE status = 'unsubscribed' 
                 AND email IN (" . implode(',', array_fill(0, count($valid_emails), '%s')) . ")",
                ...$valid_emails
            ));
            
            if ($chunk_blocked) {
                $blocked = array_merge($blocked, $chunk_blocked);
            }
        }
        
        if (empty($blocked)) return $emails;
        
        $blocked_lookup = array_flip($blocked);
        $filtered = [];
        
        foreach($emails as $email) {
            if (!isset($blocked_lookup[$email])) {
                $filtered[] = $email;
            }
        }
        
        return $filtered;
    }
    
    /**
     * Verifica si un email está desuscrito
     */
    private function is_unsubscribed($email) {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE_SUBSCRIBERS;
        $email = strtolower(trim($email));
        $st = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE email=%s", $email));
        return ($st === 'unsubscribed');
    }
    
    /**
     * Renderiza contenido de plantilla
     */
    private function render_template_content($tpl_id) {
        if ($this->template_manager) {
            return $this->template_manager->render_template_content($tpl_id);
        }
        
        // Fallback básico
        $post = get_post($tpl_id);
        if (!$post || $post->post_type !== self::EMAIL_TEMPLATE_POST_TYPE) {
            return new WP_Error('template_not_found', __('Plantilla no encontrada.', 'wp-email-collector'));
        }
        
        $subject = get_post_meta($tpl_id, '_wec_subject', true) ?: __('(Sin asunto)', 'wp-email-collector');
        $html = (string) $post->post_content;
        
        if (empty(trim($html))) {
            $html = '<p>' . __('Contenido de plantilla vacío', 'wp-email-collector') . '</p>';
        }
        
        return [$subject, $html];
    }
    
    /**
     * Construye HTML final del email
     */
    private function build_email_html($raw_html, $recipient_email = null, array $opts = []) {
        $defaults = [
            'inline'       => false,
            'preserve_css' => true,
            'reset_links'  => false
        ];
        $opts = array_merge($defaults, $opts);
        
        $html = $raw_html;
        
        // Reemplazar placeholder de unsubscribe
        if ($recipient_email) {
            $html = str_replace('[[UNSUB_URL]]', $this->get_unsub_url($recipient_email), $html);
        }
        
        // Para simplificar, aplicamos transformaciones básicas
        if ($opts['inline']) {
            $html = $this->apply_basic_email_transformations($html);
        }
        
        return $this->wrap_email_html($html);
    }
    
    /**
     * Aplica transformaciones básicas para emails
     */
    private function apply_basic_email_transformations($html) {
        // Asegurar que el HTML tenga estructura
        if (stripos($html, '<html') === false) {
            $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
        }
        
        // Aplicar estilos básicos inline
        $html = str_replace('<body>', '<body style="margin:0;padding:0;font-family:Arial,sans-serif;">', $html);
        
        return $html;
    }
    
    /**
     * Envuelve HTML en estructura de email
     */
    private function wrap_email_html($body_html) {
        if (preg_match('/<html\b/i', $body_html)) {
            return $body_html;
        }
        
        return '<!doctype html>'
             . '<html lang="es">'
             . '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
             . '<body style="margin:0;padding:0;font-family:Arial,sans-serif;">' . $body_html . '</body>'
             . '</html>';
    }
    
    /**
     * Genera URL de baja
     */
    private function get_unsub_url($email) {
        if (empty($email)) return home_url('/unsubscribe/');
        
        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE_SUBSCRIBERS;
        $email = strtolower(trim($email));
        
        $token = $wpdb->get_var($wpdb->prepare("SELECT unsub_token FROM {$table} WHERE email=%s", $email));
        
        if (!$token) {
            try {
                $token = bin2hex(random_bytes(16));
            } catch (Exception $e) {
                // Fallback si random_bytes() falla
                $token = wp_generate_password(32, true, true);
                error_log('WEC: random_bytes() falló, usando wp_generate_password como fallback: ' . $e->getMessage());
            }
            
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (email, unsub_token) VALUES (%s,%s)
                 ON DUPLICATE KEY UPDATE unsub_token=VALUES(unsub_token)",
                $email, $token
            ));
        }
        
        return home_url('/unsubscribe/?e=' . rawurlencode($email) . '&t=' . $token);
    }
    
    /**
     * Crea campaña en base de datos
     */
    private function create_campaign_in_db($tpl_id, $start_at, $rate_per_min, $emails) {
        global $wpdb;
        $table_jobs = $wpdb->prefix . self::DB_TABLE_JOBS;
        $table_items = $wpdb->prefix . self::DB_TABLE_ITEMS;
        
        $start_value = $start_at ? $this->convert_local_to_mysql($start_at) : current_time('mysql');
        
        $wpdb->insert($table_jobs, [
            'tpl_id' => $tpl_id,
            'status' => 'pending',
            'start_at' => $start_value,
            'total' => count($emails),
            'sent' => 0,
            'failed' => 0,
            'created_at' => current_time('mysql'),
            'rate_per_minute' => $rate_per_min,
        ], ['%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d']);
        
        $job_id = $wpdb->insert_id;
        
        if (!$job_id) {
            return false;
        }
        
        // Insertar items
        foreach($emails as $e) {
            $wpdb->insert($table_items, [
                'job_id' => $job_id,
                'email' => $e,
                'status' => 'queued',
                'error' => '',
                'attempts' => 0,
            ], ['%d', '%s', '%s', '%s', '%d']);
        }
        
        return $job_id;
    }
    
    /**
     * Programa crons para ejecución de campaña
     */
    private function schedule_campaign_crons($start_at) {
        $start_time = $start_at ? strtotime($this->convert_local_to_mysql($start_at)) : time();
        
        // Programar cron principal
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event($start_time + 30, self::CRON_HOOK);
        }
        
        // Programar crons de respaldo
        for($i = 1; $i <= 5; $i++) {
            wp_schedule_single_event($start_time + (120 * $i), self::CRON_HOOK);
        }
    }
    
    /**
     * Marca item como enviado
     */
    private function mark_item_sent($item_id, $attempts) {
        global $wpdb;
        $table_items = $wpdb->prefix . self::DB_TABLE_ITEMS;
        $wpdb->update($table_items, 
            ['status' => 'sent', 'attempts' => $attempts + 1], 
            ['id' => $item_id], 
            ['%s', '%d'], ['%d']
        );
    }
    
    /**
     * Marca item como fallido
     */
    private function mark_item_failed($item_id, $attempts, $error) {
        global $wpdb;
        $table_items = $wpdb->prefix . self::DB_TABLE_ITEMS;
        $wpdb->update($table_items, 
            ['status' => 'failed', 'attempts' => $attempts + 1, 'error' => $error], 
            ['id' => $item_id], 
            ['%s', '%d', '%s'], ['%d']
        );
    }
    
    /**
     * Actualiza estadísticas de trabajo
     */
    private function update_job_stats($job_id, $sent, $failed) {
        global $wpdb;
        $table_jobs = $wpdb->prefix . self::DB_TABLE_JOBS;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_jobs} SET sent = sent + %d, failed = failed + %d WHERE id=%d", 
            $sent, $failed, $job_id
        ));
    }
    
    /**
     * Obtiene URL para cron externo
     */
    private function get_external_cron_url() {
        $secret = $this->get_or_create_cron_secret();
        return home_url('/?wec_cron=true&secret=' . urlencode($secret));
    }
    
    /**
     * Renderiza modal de vista previa
     */
    private function render_preview_modal_html() {
        return '
        <div id="wec-preview-modal" style="display:none;">
            <div id="wec-preview-wrap">
                <div class="wec-toolbar">
                    <span id="wec-preview-subject">' . esc_html__('Vista previa del email', 'wp-email-collector') . '</span>
                    <div class="sep"></div>
                    <button type="button" class="button" data-wec-size="mobile">📱 Móvil 360</button>
                    <button type="button" class="button" data-wec-size="tablet">📟 Tablet 600</button>
                    <button type="button" class="button" data-wec-size="desktop">💻 Desktop 800</button>
                    <button type="button" class="button" data-wec-size="full">🖥️ Ancho libre</button>
                </div>
                <div class="wec-canvas">
                    <div class="wec-frame-wrap" id="wec-frame-wrap">
                        <div class="wec-frame-info" id="wec-frame-info">' . esc_html__('Vista previa del template', 'wp-email-collector') . '</div>
                        <iframe id="wec-preview-iframe" src="about:blank"></iframe>
                    </div>
                </div>
            </div>
        </div>';
    }
}

endif; // class_exists('WEC_Campaign_Manager')
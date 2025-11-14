<?php
/**
 * WP Email Collector - SMTP Configuration Manager
 * 
 * Gestiona toda la configuración SMTP del plugin:
 * - Configuración desde UI admin
 * - Configuración desde archivos .env
 * - Setup de PHPMailer
 * - Envío de emails de prueba
 * - Renderizado seguro de plantillas con fallback
 * 
 * @since 3.0.0
 * @requires PHP 7.4+ (WordPress minimum requirement)
 * @requires WordPress 5.0+
 * 
 * Note: Uses anonymous classes (PHP 7.0+ feature) for Template Manager adapter pattern
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
 * Interfaz para renderizadores de plantillas
 * Permite implementaciones futuras sin acoplamiento fuerte
 */
interface WEC_Template_Renderer_Interface {
    /**
     * Renderiza una plantilla de email
     * @param int $template_id ID de la plantilla
     * @return array|WP_Error [subject, html_content] o error
     */
    public function render_template_content($template_id);
}

if (!class_exists('WEC_SMTP_Manager')) :

class WEC_SMTP_Manager {
    
    /** @var WEC_SMTP_Manager Instancia única */
    private static $instance = null;
    
    /** @var WEC_Template_Renderer_Interface|null Renderizador de plantillas */
    private $template_renderer = null;
    
    /** Constantes para configuración */
    const OPT_SMTP = 'wec_smtp_settings';
    const SEND_TEST_ACTION = 'wec_send_test';
    const ENV_PATH = 'programData/emailsWishList/.env';
    const EMAIL_TEMPLATE_POST_TYPE = 'wec_email_tpl';
    
    /**
     * Obtiene la instancia única (Singleton)
     * @return WEC_SMTP_Manager
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
        $this->init_template_renderer();
    }
    
    /**
     * Inicializa el renderizador de plantillas con fallback robusto
     */
    private function init_template_renderer() {
        // Intentar configurar el Template Manager si está disponible
        if (class_exists('WEC_Template_Manager')) {
            try {
                $manager = WEC_Template_Manager::get_instance();
                
                // Verificar si implementa la interfaz (preferido)
                if ($manager instanceof WEC_Template_Renderer_Interface) {
                    $this->template_renderer = $manager;
                } else {
                    // Fallback: verificar si tiene el método necesario (duck typing)
                    if (method_exists($manager, 'render_template_content')) {
                        // Crear un wrapper que adapte el Template Manager a nuestra interfaz
                        $this->template_renderer = new class($manager) implements WEC_Template_Renderer_Interface {
                            private $template_manager;
                            
                            public function __construct($manager) {
                                $this->template_manager = $manager;
                            }
                            
                            public function render_template_content($template_id) {
                                return $this->template_manager->render_template_content($template_id);
                            }
                        };
                    } else {
                        error_log('WEC_SMTP_Manager: WEC_Template_Manager existe pero no implementa WEC_Template_Renderer_Interface ni tiene método render_template_content');
                    }
                }
            } catch (Exception $e) {
                error_log('WEC_SMTP_Manager: Error inicializando Template Manager: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Permite configurar un renderizador de plantillas personalizado
     * @param WEC_Template_Renderer_Interface $renderer
     */
    public function set_template_renderer(WEC_Template_Renderer_Interface $renderer) {
        $this->template_renderer = $renderer;
    }
    
    /**
     * Obtiene la ruta completa del archivo .env
     * @return string
     */
    private function get_env_file_path() {
        return ABSPATH . self::ENV_PATH;
    }
    
    /**
     * Obtiene las plantillas de email disponibles
     * @return WP_Post[]
     */
    private function get_email_templates() {
        return get_posts([
            'post_type'   => self::EMAIL_TEMPLATE_POST_TYPE, 
            'numberposts' => -1, 
            'post_status' => ['publish', 'draft']
        ]);
    }
    
    /**
     * Inicializa los hooks de WordPress
     */
    private function init_hooks() {
        // Setup de PHPMailer
        add_action('phpmailer_init', [$this, 'setup_phpmailer']);
        
        // Handler para envío de emails de prueba
        add_action('admin_post_' . self::SEND_TEST_ACTION, [$this, 'handle_send_test']);
        
        // Filtro para forzar contenido HTML en emails
        add_filter('wp_mail_content_type', [$this, 'set_mail_content_type']);
    }
    
    /**
     * Renderiza la página de configuración SMTP
     */
    public function render_smtp_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permisos para acceder a esta página.', 'wp-email-collector'));
        }
        
        // Verificar si existe archivo .env
        $env_path = $this->get_env_file_path();
        $env = [];
        $env_active = false;
        
        if (file_exists($env_path)) {
            $env = $this->parse_env_file($env_path);
            $env_active = true;
            echo '<div class="notice notice-success"><p>' . sprintf(__('Modo .env activo: usando %s.', 'wp-email-collector'), '<code>' . esc_html($env_path) . '</code>') . '</p></div>';
        }
        
        // Obtener configuración actual (prioridad: .env > base de datos)
        $opts = get_option(self::OPT_SMTP, []);
        $config = $this->get_smtp_config($env, $opts);
        
        // Procesar formulario de guardado
        if (isset($_POST['wec_smtp_save']) && check_admin_referer('wec_smtp_save')) {
            $this->save_smtp_settings($_POST);
            echo '<div class="notice notice-success"><p>' . __('Configuración SMTP guardada correctamente.', 'wp-email-collector') . '</p></div>';
            
            // Actualizar configuración después del guardado
            $opts = get_option(self::OPT_SMTP, []);
            $config = $this->get_smtp_config($env, $opts);
        }
        
        // Mostrar mensaje de test si viene de redirect
        $this->show_test_message();
        
        // Obtener plantillas disponibles
        $templates = $this->get_email_templates();
        
        $this->render_smtp_form($config, $env_active, $templates);
    }
    
    /**
     * Obtiene la configuración SMTP combinada
     */
    private function get_smtp_config($env = [], $opts = []) {
        return [
            'host'      => $env['SMTP_HOST'] ?? ($opts['host'] ?? ''),
            'port'      => $env['SMTP_PORT'] ?? ($opts['port'] ?? 587),
            'user'      => $env['SMTP_USER'] ?? ($opts['user'] ?? ''),
            'pass'      => $env['SMTP_PASS'] ?? ($opts['pass'] ?? ''),
            'secure'    => $env['SMTP_USE_SSL'] ?? ($opts['secure'] ?? ''),
            'from_name' => $env['FROM_NAME'] ?? ($opts['from_name'] ?? ''),
            'from'      => $env['FROM_EMAIL'] ?? ($opts['from'] ?? ''),
        ];
    }
    
    /**
     * Guarda la configuración SMTP con validación completa
     */
    private function save_smtp_settings($post_data) {
        // Sanitizar y validar tipo de cifrado
        $secure = sanitize_text_field($post_data['SMTP_USE_SSL'] ?? '');
        if (!in_array($secure, ['', 'tls', 'ssl'], true)) {
            $secure = '';
        }
        
        $opts = [
            'host'      => sanitize_text_field($post_data['SMTP_HOST'] ?? ''),
            'port'      => intval($post_data['SMTP_PORT'] ?? 0),
            'user'      => sanitize_text_field($post_data['SMTP_USER'] ?? ''),
            'pass'      => sanitize_text_field($post_data['SMTP_PASS'] ?? ''),
            'secure'    => $secure,
            'from'      => sanitize_email($post_data['FROM_EMAIL'] ?? ''),
            'from_name' => sanitize_text_field($post_data['FROM_NAME'] ?? ''),
        ];
        
        update_option(self::OPT_SMTP, $opts);
    }
    
    /**
     * Muestra mensaje del resultado del test con validación de nonce
     */
    private function show_test_message() {
        if (isset($_GET['test']) && isset($_GET['test_nonce'])) {
            // Sanitizar parámetros de entrada
            $test_result = sanitize_key($_GET['test']);
            $test_nonce = sanitize_text_field($_GET['test_nonce']);
            
            // Validar nonce para prevenir ataques de manipulación de URL
            if (!wp_verify_nonce($test_nonce, 'wec_test_result')) {
                return; // Nonce inválido, no mostrar mensaje
            }
            
            if ($test_result === 'ok') {
                echo '<div class="notice notice-success"><p><strong>✅ ' . __('Email de prueba enviado correctamente', 'wp-email-collector') . '</strong> - ' . __('La configuración SMTP funciona.', 'wp-email-collector') . '</p></div>';
            } elseif ($test_result === 'fail') {
                echo '<div class="notice notice-error"><p><strong>❌ ' . __('Error al enviar email de prueba', 'wp-email-collector') . '</strong> - ' . __('Revisa la configuración SMTP.', 'wp-email-collector') . '</p></div>';
            }
        }
    }
    
    /**
     * Renderiza el formulario de configuración SMTP
     */
    private function render_smtp_form($config, $env_active, $templates) {
        ?>
        <div class="wrap">
            <h1><?php echo __('Configuración SMTP', 'wp-email-collector'); ?></h1>
            
            <?php if ($env_active): ?>
            <div class="notice notice-info">
                <p><strong><?php echo __('📁 Modo .env detectado:', 'wp-email-collector'); ?></strong> <?php echo __('La configuración se lee desde archivo .env. Los cambios en este formulario solo se aplicarán si no hay archivo .env.', 'wp-email-collector'); ?></p>
            </div>
            <?php endif; ?>
            
            <h2><?php echo __('Configuración del Servidor', 'wp-email-collector'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('wec_smtp_save'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="SMTP_HOST"><?php echo __('Servidor SMTP', 'wp-email-collector'); ?></label></th>
                        <td>
                            <input id="SMTP_HOST" name="SMTP_HOST" value="<?php echo esc_attr($config['host']); ?>" class="regular-text" placeholder="smtp.ejemplo.com">
                            <p class="description"><?php echo __('El servidor SMTP de tu proveedor de email.', 'wp-email-collector'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="SMTP_PORT"><?php echo __('Puerto', 'wp-email-collector'); ?></label></th>
                        <td>
                            <input id="SMTP_PORT" name="SMTP_PORT" value="<?php echo esc_attr($config['port']); ?>" class="small-text" type="number" placeholder="587">
                            <p class="description"><?php echo __('Puerto del servidor SMTP (587 para TLS, 465 para SSL, 25 sin cifrado).', 'wp-email-collector'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="SMTP_USER"><?php echo __('Usuario SMTP', 'wp-email-collector'); ?></label></th>
                        <td>
                            <input id="SMTP_USER" name="SMTP_USER" value="<?php echo esc_attr($config['user']); ?>" class="regular-text" placeholder="tu-email@ejemplo.com">
                            <p class="description"><?php echo __('Usuario para autenticación SMTP (generalmente tu email).', 'wp-email-collector'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="SMTP_PASS"><?php echo __('Contraseña SMTP', 'wp-email-collector'); ?></label></th>
                        <td>
                            <input id="SMTP_PASS" name="SMTP_PASS" type="password" value="<?php echo esc_attr($config['pass']); ?>" class="regular-text" placeholder="tu-contraseña">
                            <p class="description"><?php echo __('Contraseña o token de aplicación para SMTP.', 'wp-email-collector'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="FROM_NAME"><?php echo __('Nombre del remitente', 'wp-email-collector'); ?></label></th>
                        <td>
                            <input id="FROM_NAME" name="FROM_NAME" value="<?php echo esc_attr($config['from_name']); ?>" class="regular-text" placeholder="Mi Empresa">
                            <p class="description"><?php echo __('El nombre que aparecerá como remitente de los emails.', 'wp-email-collector'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="FROM_EMAIL"><?php echo __('Email del remitente', 'wp-email-collector'); ?></label></th>
                        <td>
                            <input id="FROM_EMAIL" name="FROM_EMAIL" value="<?php echo esc_attr($config['from']); ?>" class="regular-text" type="email" placeholder="noreply@tudominio.com">
                            <p class="description"><?php echo __('La dirección de email que aparecerá como remitente.', 'wp-email-collector'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="SMTP_USE_SSL"><?php echo __('Cifrado', 'wp-email-collector'); ?></label></th>
                        <td>
                            <select name="SMTP_USE_SSL" id="SMTP_USE_SSL">
                                <option value="" <?php selected($config['secure'], ''); ?>><?php echo __('Sin cifrado', 'wp-email-collector'); ?></option>
                                <option value="tls" <?php selected($config['secure'], 'tls'); ?>><?php echo __('TLS (recomendado)', 'wp-email-collector'); ?></option>
                                <option value="ssl" <?php selected($config['secure'], 'ssl'); ?>><?php echo __('SSL', 'wp-email-collector'); ?></option>
                            </select>
                            <p class="description"><?php echo __('Tipo de cifrado de la conexión SMTP.', 'wp-email-collector'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php if (!$env_active): ?>
                <p class="submit">
                    <button class="button button-primary" name="wec_smtp_save" value="1"><?php echo __('Guardar Configuración', 'wp-email-collector'); ?></button>
                </p>
                <?php else: ?>
                <p class="submit">
                    <button class="button" name="wec_smtp_save" value="1" disabled><?php echo __('Configuración desde .env (solo lectura)', 'wp-email-collector'); ?></button>
                </p>
                <?php endif; ?>
            </form>

            <hr>

            <h2><?php echo __('Probar Configuración SMTP', 'wp-email-collector'); ?></h2>
            <p><?php echo __('Envía un email de prueba para verificar que la configuración SMTP funciona correctamente.', 'wp-email-collector'); ?></p>
            <?php $this->render_test_form($templates); ?>

            <?php $this->render_additional_info(); ?>
        </div>
        <?php
        
        // Incluir modal de vista previa si hay plantillas
        if ($templates) {
            echo $this->render_preview_modal();
        }
    }
    
    /**
     * Renderiza el formulario de prueba
     */
    private function render_test_form($templates) {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::SEND_TEST_ACTION); ?>">
            <?php wp_nonce_field('wec_send_test'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="wec_template_id"><?php echo __('Plantilla', 'wp-email-collector'); ?></label></th>
                    <td class="wec-inline">
                        <select name="wec_template_id" id="wec_template_id">
                            <?php if ($templates): foreach ($templates as $tpl): ?>
                            <option value="<?php echo esc_attr($tpl->ID); ?>"><?php echo esc_html($tpl->post_title ?: __('(sin título)', 'wp-email-collector')); ?></option>
                            <?php endforeach; else: ?>
                            <option value=""><?php echo __('No hay plantillas disponibles', 'wp-email-collector'); ?></option>
                            <?php endif; ?>
                        </select>
                        <?php if ($templates): ?>
                        <button id="wec-btn-preview" type="button" class="button"><?php echo __('Vista previa', 'wp-email-collector'); ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="wec_test_email"><?php echo __('Correo destinatario', 'wp-email-collector'); ?></label></th>
                    <td>
                        <input type="email" name="wec_test_email" id="wec_test_email" class="regular-text" required placeholder="prueba@ejemplo.com">
                        <p class="description"><?php echo __('Email donde se enviará la prueba.', 'wp-email-collector'); ?></p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <?php if ($templates): ?>
                <button class="button button-primary"><?php echo __('Enviar Email de Prueba', 'wp-email-collector'); ?></button>
                <?php else: ?>
                <span class="description"><?php echo __('Primero crea una plantilla de email para poder enviar pruebas.', 'wp-email-collector'); ?></span>
                <?php endif; ?>
            </p>
        </form>
        <?php
    }
    
    /**
     * Renderiza información adicional
     */
    private function render_additional_info() {
        $templates = $this->get_email_templates();
        ?>
        <h3><?php echo __('Gestión de Plantillas', 'wp-email-collector'); ?></h3>
        <?php if ($templates): ?>
        <p>
            <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=' . self::EMAIL_TEMPLATE_POST_TYPE)); ?>"><?php echo __('Gestionar Plantillas', 'wp-email-collector'); ?></a>
            <a class="button button-secondary" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . self::EMAIL_TEMPLATE_POST_TYPE)); ?>"><?php echo __('Crear Nueva Plantilla', 'wp-email-collector'); ?></a>
        </p>
        <?php else: ?>
        <p>
            <strong><?php echo __('No hay plantillas creadas.', 'wp-email-collector'); ?></strong> 
            <a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . self::EMAIL_TEMPLATE_POST_TYPE)); ?>"><?php echo __('Crear Primera Plantilla', 'wp-email-collector'); ?></a>
        </p>
        <?php endif; ?>
        
        <h3><?php echo __('Configuración .env (Opcional)', 'wp-email-collector'); ?></h3>
        <p><?php echo __('Para mayor seguridad, puedes configurar los datos SMTP en un archivo', 'wp-email-collector'); ?> <code>.env</code> <?php echo __('en la ruta:', 'wp-email-collector'); ?></p>
        <code><?php echo esc_html($this->get_env_file_path()); ?></code>
        
        <p><?php echo __('Ejemplo de contenido del archivo .env:', 'wp-email-collector'); ?></p>
        <pre style="background: #f0f0f0; padding: 10px; border-radius: 4px; font-family: monospace;">
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=tu-email@gmail.com
SMTP_PASS=tu-contraseña-o-token
SMTP_USE_SSL=tls
FROM_NAME=Tu Nombre
FROM_EMAIL=noreply@tudominio.com
        </pre>
        <?php
    }
    
    /**
     * Renderiza el modal de vista previa
     */
    private function render_preview_modal() {
        return '
        <div id="wec-preview-modal" style="display:none;">
            <div id="wec-preview-wrap">
                <div class="wec-toolbar">
                    <span id="wec-preview-subject">Vista previa del email</span>
                    <div class="sep"></div>
                    <button type="button" class="button" onclick="tb_remove();">Cerrar</button>
                </div>
                <div class="wec-canvas">
                    <div class="wec-frame-wrap">
                        <div class="wec-frame-info">Vista previa del template</div>
                        <iframe id="wec-preview-iframe" src="about:blank"></iframe>
                    </div>
                </div>
            </div>
        </div>';
    }
    
    /**
     * Configura PHPMailer con los ajustes SMTP
     * @param PHPMailer $phpmailer
     */
    public function setup_phpmailer($phpmailer) {
        $config = $this->get_current_smtp_config();
        
        if (empty($config['host'])) {
            return; // No hay configuración SMTP
        }
        
        $phpmailer->isSMTP();
        $phpmailer->Host = $config['host'];
        
        if (!empty($config['port'])) {
            $phpmailer->Port = intval($config['port']);
        }
        
        if (!empty($config['user'])) {
            $phpmailer->SMTPAuth = true;
            $phpmailer->Username = $config['user'];
            $phpmailer->Password = $config['pass'] ?? '';
        }
        
        if (!empty($config['secure'])) {
            $phpmailer->SMTPSecure = $config['secure'];
        }
        
        if (!empty($config['from'])) {
            $phpmailer->setFrom($config['from'], $config['from_name'] ?? '');
        }
        
        // Configuración adicional para mejor compatibilidad
        $phpmailer->CharSet = 'UTF-8';
        $phpmailer->Encoding = 'quoted-printable';
        $phpmailer->WordWrap = 120;
        
        // Configurar timeout para evitar bloqueos
        $phpmailer->Timeout = 30;
        $phpmailer->SMTPTimeout = 30;
        
        // Debug en desarrollo (solo si WP_DEBUG está activo)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $phpmailer->SMTPDebug = 0; // Cambiar a 1 o 2 para debug completo
        }
    }
    
    /**
     * Obtiene la configuración SMTP actual
     * @return array
     */
    public function get_current_smtp_config() {
        $env_path = $this->get_env_file_path();
        $env = [];
        
        if (file_exists($env_path)) {
            $env = $this->parse_env_file($env_path);
        }
        
        $opts = get_option(self::OPT_SMTP, []);
        return $this->get_smtp_config($env, $opts);
    }
    
    /**
     * Maneja el envío de emails de prueba
     */
    public function handle_send_test() {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permisos para acceder a esta función.', 'wp-email-collector'));
        }
        
        check_admin_referer('wec_send_test');
        
        $tpl_id = intval($_POST['wec_template_id'] ?? 0);
        $to = sanitize_email($_POST['wec_test_email'] ?? '');
        
        if (!$tpl_id || !is_email($to)) {
            wp_die(__('Datos inválidos. Verifica que hayas seleccionado una plantilla y un email válido.', 'wp-email-collector'));
        }
        
        // Verificar que la plantilla existe
        $template = get_post($tpl_id);
        if (!$template || $template->post_type !== self::EMAIL_TEMPLATE_POST_TYPE) {
            wp_die(__('Plantilla no encontrada o inválida.', 'wp-email-collector'));
        }
        
        try {
            // Renderizar plantilla con validaciones robustas
            $template_result = $this->render_template_safely($tpl_id);
            
            if (is_wp_error($template_result)) {
                wp_die(sprintf(__('Error en la plantilla: %s', 'wp-email-collector'), $template_result->get_error_message()));
            }
            
            list($subject, $html_content) = $template_result;
            
            // Procesar HTML para compatibilidad con clientes de email
            $html_content = $this->process_email_html($html_content);
            
            // Enviar email
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            $ok = wp_mail($to, $subject, $html_content, $headers);
            
            // Crear nonce para validar el resultado del test
            $test_nonce = wp_create_nonce('wec_test_result');
            
            // Redirect con resultado y nonce
            $redirect_url = admin_url('admin.php?page=wec-smtp&test=' . ($ok ? 'ok' : 'fail') . '&test_nonce=' . $test_nonce);
            wp_safe_redirect($redirect_url);
            
        } catch (Exception $e) {
            wp_die(sprintf(__('Error al enviar email de prueba: %s', 'wp-email-collector'), $e->getMessage()));
        }
        
        exit;
    }
    
    /**
     * Renderiza una plantilla de forma segura con validaciones completas
     * @param int $tpl_id ID de la plantilla
     * @return array|WP_Error [subject, html_content] o error
     */
    private function render_template_safely($tpl_id) {
        // Validar que la plantilla existe
        $template = get_post($tpl_id);
        if (!$template || $template->post_type !== self::EMAIL_TEMPLATE_POST_TYPE) {
            return new WP_Error('invalid_template', __('Plantilla no encontrada o inválida.', 'wp-email-collector'));
        }
        
        // Usar renderizador configurado si está disponible
        if ($this->template_renderer !== null) {
            try {
                $result = $this->template_renderer->render_template_content($tpl_id);
                
                // Validar que el resultado tiene la estructura esperada
                if (is_array($result) && count($result) === 2) {
                    return $result;
                } elseif (is_wp_error($result)) {
                    return $result;
                } else {
                    error_log('WEC_SMTP_Manager: Template renderer devolvió resultado inválido');
                    // Continuar con fallback
                }
            } catch (Exception $e) {
                error_log('WEC_SMTP_Manager: Error en template renderer: ' . $e->getMessage());
                // Continuar con fallback
            }
        }
        
        // Intentar usar WEC_Template_Manager directamente como fallback
        if (class_exists('WEC_Template_Manager')) {
            try {
                $template_manager = WEC_Template_Manager::get_instance();
                
                // Verificar que el método existe antes de llamarlo
                if (method_exists($template_manager, 'render_template_content')) {
                    $result = $template_manager->render_template_content($tpl_id);
                    
                    // Validar que el resultado tiene la estructura esperada
                    if (is_array($result) && count($result) === 2) {
                        return $result;
                    } elseif (is_wp_error($result)) {
                        return $result;
                    }
                }
            } catch (Exception $e) {
                error_log('WEC_SMTP_Manager: Error al usar Template Manager: ' . $e->getMessage());
            }
        }
        
        // Fallback final: renderizar plantilla manualmente
        return $this->render_template_fallback($template);
    }
    
    /**
     * Fallback para renderizar plantilla cuando Template Manager no está disponible
     * @param WP_Post $template
     * @return array|WP_Error [subject, html_content] o error
     */
    private function render_template_fallback($template) {
        try {
            // Obtener asunto de la plantilla
            $subject = get_post_meta($template->ID, '_wec_subject', true);
            if (empty($subject)) {
                $subject = $template->post_title ?: sprintf(__('Email desde %s', 'wp-email-collector'), get_bloginfo('name'));
            }
            
            // Obtener contenido HTML
            $html_content = $template->post_content;
            if (empty($html_content)) {
                return new WP_Error('empty_template', __('La plantilla no tiene contenido.', 'wp-email-collector'));
            }
            
            // Aplicar filtros básicos de WordPress al contenido
            $html_content = apply_filters('the_content', $html_content);
            
            // Reemplazar variables básicas
            $html_content = $this->replace_template_variables($html_content);
            
            // Procesar HTML para compatibilidad con clientes de email
            $html_content = $this->process_email_html($html_content);
            
            return [$subject, $html_content];
            
        } catch (Exception $e) {
            return new WP_Error('fallback_error', sprintf(__('Error en renderizado fallback: %s', 'wp-email-collector'), $e->getMessage()));
        }
    }
    
    /**
     * Reemplaza variables básicas en plantillas cuando no hay Template Manager
     * @param string $content
     * @return string
     */
    private function replace_template_variables($content) {
        $replacements = [
            '{{site_name}}'    => get_bloginfo('name'),
            '{{site_url}}'     => home_url(),
            '{{current_year}}' => wp_date('Y'), 
            '{{current_date}}' => date_i18n(get_option('date_format')),
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }
    
    /**
     * Procesa HTML para compatibilidad con clientes de email
     * Aplica transformaciones necesarias para Gmail y otros clientes
     * @param string $html_content
     * @return string
     */
    private function process_email_html($html_content) {
        // Aplicar transformaciones básicas para compatibilidad con email
        return $this->apply_email_transformations($html_content);
    }
    
    /**
     * Aplica transformaciones básicas para compatibilidad con clientes de email
     * @param string $html_content
     * @return string
     */
    private function apply_email_transformations($html_content) {
        // Asegurar que el HTML tenga estructura completa
        if (stripos($html_content, '<html') === false) {
            $html_content = $this->wrap_in_html_structure($html_content);
        }
        
        // Aplicar estilos inline básicos para mejor compatibilidad
        $html_content = $this->apply_inline_styles($html_content);
        
        // Compatibilidad con Gmail
        $html_content = $this->apply_gmail_compatibility($html_content);
        
        // Reset de enlaces para email
        $html_content = $this->apply_link_resets($html_content);
        
        return $html_content;
    }
    
    /**
     * Envuelve contenido en estructura HTML completa
     * @param string $content
     * @return string
     */
    private function wrap_in_html_structure($content) {
        return '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    ' . $content . '
</body>
</html>';
    }
    
    /**
     * Aplica estilos inline básicos para compatibilidad
     * @param string $html
     * @return string
     */
    private function apply_inline_styles($html) {
        // Aplicar estilos a tablas que no tengan ya un atributo style
        $html = preg_replace_callback('/<table([^>]*?)>/i', function($matches) {
            $attributes = $matches[1];
            
            // Si no tiene style, agregar estilos básicos para tablas
            if (strpos($attributes, 'style=') === false) {
                $attributes .= ' style="border-collapse: collapse; width: 100%;"';
            }
            
            return '<table' . $attributes . '>';
        }, $html);
        
        // Aplicar estilos a celdas TD que no tengan ya un atributo style
        $html = preg_replace_callback('/<td([^>]*?)>/i', function($matches) {
            $attributes = $matches[1];
            
            // Si no tiene style, agregar estilos básicos para celdas
            if (strpos($attributes, 'style=') === false) {
                $attributes .= ' style="padding: 10px; vertical-align: top;"';
            }
            
            return '<td' . $attributes . '>';
        }, $html);
        
        // Aplicar estilos a encabezados TH que no tengan ya un atributo style
        $html = preg_replace_callback('/<th([^>]*?)>/i', function($matches) {
            $attributes = $matches[1];
            
            // Si no tiene style, agregar estilos básicos para encabezados
            if (strpos($attributes, 'style=') === false) {
                $attributes .= ' style="padding: 10px; text-align: left; font-weight: bold;"';
            }
            
            return '<th' . $attributes . '>';
        }, $html);
        
        // Aplicar estilos a párrafos que no tengan ya un atributo style
        $html = preg_replace_callback('/<p(\s[^>]*?)?>/i', function($matches) {
            $attributes = $matches[1] ?? '';
            
            // Si no tiene style, agregar estilos básicos para párrafos
            if (strpos($attributes, 'style=') === false) {
                $attributes .= ' style="margin: 0 0 16px 0; line-height: 1.6;"';
            }
            
            return '<p' . $attributes . '>';
        }, $html);
        
        // Aplicar estilos a enlaces que no tengan ya un atributo style
        $html = preg_replace_callback('/<a(\s[^>]*?)>/i', function($matches) {
            $attributes = $matches[1];
            
            // Si no tiene style, agregar estilos básicos para enlaces
            if (strpos($attributes, 'style=') === false) {
                $attributes .= ' style="color: #0073aa; text-decoration: none;"';
            }
            
            return '<a' . $attributes . '>';
        }, $html);
        
        return $html;
    }
    
    /**
     * Aplica compatibilidad específica para Gmail
     * @param string $html
     * @return string
     */
    private function apply_gmail_compatibility($html) {
        // Gmail no soporta CSS en <head>, asegurar estilos inline
        // Remover comentarios CSS que Gmail puede interpretar mal
        $html = preg_replace('/<!--\s*\[if\s+.*?\]>.*?<!\[endif\]\s*-->/is', '', $html);
        
        // Asegurar que las imágenes tengan dimensiones explícitas
        $html = preg_replace_callback('/<img\b([^>]*?)>/i', function($matches) {
            $attributes = $matches[1];
            
            // Verificar si ya tiene un atributo style (más robusto)
            if (!preg_match('/\bstyle\s*=/i', $attributes)) {
                // Si no tiene style, agregar estilos básicos para imágenes
                $attributes .= ' style="display: block; max-width: 100%; height: auto;"';
            }
            
            return '<img' . $attributes . '>';
        }, $html);
        
        // Forzar display block en divs principales para Gmail
        $html = preg_replace_callback('/<div\b([^>]*?)>/i', function($matches) {
            $attributes = $matches[1];
            
            // Verificar si ya tiene un atributo style (más robusto)
            if (!preg_match('/\bstyle\s*=/i', $attributes)) {
                // Si no tiene style, agregar display block para Gmail
                $attributes .= ' style="display: block;"';
            }
            
            return '<div' . $attributes . '>';
        }, $html);
        
        return $html;
    }
    
    /**
     * Aplica resets de enlaces para mejor renderizado en email
     * @param string $html
     * @return string
     */
    private function apply_link_resets($html) {
        // Asegurar que los enlaces tengan estilos consistentes
        $html = preg_replace_callback('/<a\s+([^>]*?)>(.*?)<\/a>/is', function($matches) {
            $attributes = $matches[1];
            $content = $matches[2];
            
            // Si no tiene style, agregar estilos básicos
            if (strpos($attributes, 'style=') === false) {
                $attributes .= ' style="color: #0073aa; text-decoration: underline;"';
            }
            
            return '<a ' . $attributes . '>' . $content . '</a>';
        }, $html);
        
        return $html;
    }

    /**
     * Establece el tipo de contenido para emails HTML
     * @return string
     */
    public function set_mail_content_type() {
        return 'text/html';
    }
    
    /**
     * Parsea un archivo .env y devuelve array asociativo con manejo robusto de errores
     * @param string $file_path
     * @return array
     */
    private function parse_env_file($file_path) {
        if (!file_exists($file_path)) {
            return [];
        }
        
        // Verificar permisos de lectura antes de intentar leer
        if (!is_readable($file_path)) {
            error_log("WEC_SMTP_Manager: .env file exists but is not readable at {$file_path}");
            return [];
        }
        
        $env = [];
        $lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        if ($lines === false) {
            $error = error_get_last();
            error_log("WEC_SMTP_Manager: Unable to read .env file at {$file_path}. Error: " . ($error['message'] ?? 'Unknown error'));
            return [];
        }
        
        foreach ($lines as $line_number => $line) {
            // Ignorar comentarios
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Buscar formato KEY=VALUE
            if (strpos($line, '=') !== false) {
                $parts = explode('=', $line, 2);
                
                list($key, $value) = $parts;
                $key = trim($key);
                $value = trim($value);
                
                // Validar que la clave no esté vacía
                if (empty($key)) {
                    error_log("WEC_SMTP_Manager: Empty key found on line " . ($line_number + 1) . ": {$line}");
                    continue;
                }
                
                // Remover comillas si las hay
                if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                    (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                    $value = substr($value, 1, -1);
                }
                
                $env[$key] = $value;
            }
        }
        
        return $env;
    }
    
    /**
     * Verifica si la configuración SMTP está completa
     * @return bool
     */
    public function is_smtp_configured() {
        $config = $this->get_current_smtp_config();
        return !empty($config['host']) && !empty($config['user']);
    }
    
    /**
     * Obtiene estadísticas de configuración para debug
     * @return array
     */
    public function get_config_status() {
        $config = $this->get_current_smtp_config();
        $env_path = $this->get_env_file_path();
        
        return [
            'env_file_exists'       => file_exists($env_path),
            'env_file_path'         => $env_path,
            'host_configured'       => !empty($config['host']),
            'auth_configured'       => !empty($config['user']),
            'from_configured'       => !empty($config['from']),
            'encryption'            => $config['secure'] ?: 'none',
            'port'                  => $config['port'] ?: 'default',
            'is_fully_configured'   => $this->is_smtp_configured(),
            'template_renderer'     => $this->template_renderer !== null ? get_class($this->template_renderer) : 'fallback',
            'template_manager_available' => class_exists('WEC_Template_Manager'),
            'template_method_exists' => class_exists('WEC_Template_Manager') && 
                                       method_exists(WEC_Template_Manager::class, 'render_template_content'),
        ];
    }
}

endif; // class_exists('WEC_SMTP_Manager')
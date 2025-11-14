<?php
/**
 * WP Email Collector - SMTP Configuration Manager
 * 
 * Gestiona toda la configuración SMTP del plugin:
 * - Configuración desde UI admin
 * - Configuración desde archivos .env
 * - Setup de PHPMailer
 * - Envío de emails de prueba
 * 
 * @since 3.0.0
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('WEC_SMTP_Manager')) :

class WEC_SMTP_Manager {
    
    /** @var WEC_SMTP_Manager Instancia única */
    private static $instance = null;
    
    /** Constantes para configuración */
    const OPT_SMTP = 'wec_smtp_settings';
    const SEND_TEST_ACTION = 'wec_send_test';
    
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
            wp_die(__('No tienes permisos para acceder a esta página.'));
        }
        
        // Verificar si existe archivo .env
        $env_path = ABSPATH . 'programData/emailsWishList/.env';
        $env = [];
        $env_active = false;
        
        if (file_exists($env_path)) {
            $env = $this->parse_env_file($env_path);
            $env_active = true;
            echo '<div class="notice notice-success"><p>Modo .env activo: usando <code>' . esc_html($env_path) . '</code>.</p></div>';
        }
        
        // Obtener configuración actual (prioridad: .env > base de datos)
        $opts = get_option(self::OPT_SMTP, []);
        $config = $this->get_smtp_config($env, $opts);
        
        // Procesar formulario de guardado
        if (isset($_POST['wec_smtp_save']) && check_admin_referer('wec_smtp_save')) {
            $this->save_smtp_settings($_POST);
            echo '<div class="notice notice-success"><p>Configuración SMTP guardada correctamente.</p></div>';
            
            // Actualizar configuración después del guardado
            $opts = get_option(self::OPT_SMTP, []);
            $config = $this->get_smtp_config($env, $opts);
        }
        
        // Mostrar mensaje de test si viene de redirect
        $this->show_test_message();
        
        // Obtener plantillas disponibles
        $templates = get_posts([
            'post_type'   => 'wec_email_tpl', 
            'numberposts' => -1, 
            'post_status' => ['publish', 'draft']
        ]);
        
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
     * Guarda la configuración SMTP
     */
    private function save_smtp_settings($post_data) {
        $opts = [
            'host'      => sanitize_text_field($post_data['SMTP_HOST'] ?? ''),
            'port'      => intval($post_data['SMTP_PORT'] ?? 0),
            'user'      => sanitize_text_field($post_data['SMTP_USER'] ?? ''),
            'pass'      => sanitize_text_field($post_data['SMTP_PASS'] ?? ''),
            'secure'    => sanitize_text_field($post_data['SMTP_USE_SSL'] ?? ''),
            'from'      => sanitize_email($post_data['FROM_EMAIL'] ?? ''),
            'from_name' => sanitize_text_field($post_data['FROM_NAME'] ?? ''),
        ];
        
        update_option(self::OPT_SMTP, $opts);
    }
    
    /**
     * Muestra mensaje del resultado del test
     */
    private function show_test_message() {
        if (isset($_GET['test'])) {
            if ($_GET['test'] === 'ok') {
                echo '<div class="notice notice-success"><p><strong>✅ Email de prueba enviado correctamente</strong> - La configuración SMTP funciona.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p><strong>❌ Error al enviar email de prueba</strong> - Revisa la configuración SMTP.</p></div>';
            }
        }
    }
    
    /**
     * Renderiza el formulario de configuración SMTP
     */
    private function render_smtp_form($config, $env_active, $templates) {
        ?>
        <div class="wrap">
            <h1>Configuración SMTP</h1>
            
            <?php if ($env_active): ?>
            <div class="notice notice-info">
                <p><strong>📁 Modo .env detectado:</strong> La configuración se lee desde archivo .env. Los cambios en este formulario solo se aplicarán si no hay archivo .env.</p>
            </div>
            <?php endif; ?>
            
            <h2>Configuración del Servidor</h2>
            <form method="post">
                <?php wp_nonce_field('wec_smtp_save'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="SMTP_HOST">Servidor SMTP</label></th>
                        <td>
                            <input id="SMTP_HOST" name="SMTP_HOST" value="<?php echo esc_attr($config['host']); ?>" class="regular-text" placeholder="smtp.ejemplo.com">
                            <p class="description">El servidor SMTP de tu proveedor de email.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="SMTP_PORT">Puerto</label></th>
                        <td>
                            <input id="SMTP_PORT" name="SMTP_PORT" value="<?php echo esc_attr($config['port']); ?>" class="small-text" type="number" placeholder="587">
                            <p class="description">Puerto del servidor SMTP (587 para TLS, 465 para SSL, 25 sin cifrado).</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="SMTP_USER">Usuario SMTP</label></th>
                        <td>
                            <input id="SMTP_USER" name="SMTP_USER" value="<?php echo esc_attr($config['user']); ?>" class="regular-text" placeholder="tu-email@ejemplo.com">
                            <p class="description">Usuario para autenticación SMTP (generalmente tu email).</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="SMTP_PASS">Contraseña SMTP</label></th>
                        <td>
                            <input id="SMTP_PASS" name="SMTP_PASS" type="password" value="<?php echo esc_attr($config['pass']); ?>" class="regular-text" placeholder="tu-contraseña">
                            <p class="description">Contraseña o token de aplicación para SMTP.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="FROM_NAME">Nombre del remitente</label></th>
                        <td>
                            <input id="FROM_NAME" name="FROM_NAME" value="<?php echo esc_attr($config['from_name']); ?>" class="regular-text" placeholder="Mi Empresa">
                            <p class="description">El nombre que aparecerá como remitente de los emails.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="FROM_EMAIL">Email del remitente</label></th>
                        <td>
                            <input id="FROM_EMAIL" name="FROM_EMAIL" value="<?php echo esc_attr($config['from']); ?>" class="regular-text" type="email" placeholder="noreply@tudominio.com">
                            <p class="description">La dirección de email que aparecerá como remitente.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="SMTP_USE_SSL">Cifrado</label></th>
                        <td>
                            <select name="SMTP_USE_SSL" id="SMTP_USE_SSL">
                                <option value="" <?php selected($config['secure'], ''); ?>>Sin cifrado</option>
                                <option value="tls" <?php selected($config['secure'], 'tls'); ?>>TLS (recomendado)</option>
                                <option value="ssl" <?php selected($config['secure'], 'ssl'); ?>>SSL</option>
                            </select>
                            <p class="description">Tipo de cifrado de la conexión SMTP.</p>
                        </td>
                    </tr>
                </table>
                
                <?php if (!$env_active): ?>
                <p class="submit">
                    <button class="button button-primary" name="wec_smtp_save" value="1">Guardar Configuración</button>
                </p>
                <?php else: ?>
                <p class="submit">
                    <button class="button" name="wec_smtp_save" value="1" disabled>Configuración desde .env (solo lectura)</button>
                </p>
                <?php endif; ?>
            </form>

            <hr>

            <h2>Probar Configuración SMTP</h2>
            <p>Envía un email de prueba para verificar que la configuración SMTP funciona correctamente.</p>
            <?php $this->render_test_form($templates); ?>

            <?php $this->render_additional_info(); ?>
        </div>
        <?php
        
        // Incluir modal de vista previa si hay plantillas
        if ($templates) {
            $this->render_preview_modal();
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
                    <th><label for="wec_template_id">Plantilla</label></th>
                    <td class="wec-inline">
                        <select name="wec_template_id" id="wec_template_id">
                            <?php if ($templates): foreach ($templates as $tpl): ?>
                            <option value="<?php echo esc_attr($tpl->ID); ?>"><?php echo esc_html($tpl->post_title ?: '(sin título)'); ?></option>
                            <?php endforeach; else: ?>
                            <option value="">No hay plantillas disponibles</option>
                            <?php endif; ?>
                        </select>
                        <?php if ($templates): ?>
                        <button id="wec-btn-preview" type="button" class="button">Vista previa</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="wec_test_email">Correo destinatario</label></th>
                    <td>
                        <input type="email" name="wec_test_email" id="wec_test_email" class="regular-text" required placeholder="prueba@ejemplo.com">
                        <p class="description">Email donde se enviará la prueba.</p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <?php if ($templates): ?>
                <button class="button button-primary">Enviar Email de Prueba</button>
                <?php else: ?>
                <span class="description">Primero crea una plantilla de email para poder enviar pruebas.</span>
                <?php endif; ?>
            </p>
        </form>
        <?php
    }
    
    /**
     * Renderiza información adicional
     */
    private function render_additional_info() {
        $templates = get_posts([
            'post_type'   => 'wec_email_tpl', 
            'numberposts' => -1, 
            'post_status' => ['publish', 'draft']
        ]);
        ?>
        <h3>Gestión de Plantillas</h3>
        <?php if ($templates): ?>
        <p>
            <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=wec_email_tpl')); ?>">Gestionar Plantillas</a>
            <a class="button button-secondary" href="<?php echo esc_url(admin_url('post-new.php?post_type=wec_email_tpl')); ?>">Crear Nueva Plantilla</a>
        </p>
        <?php else: ?>
        <p>
            <strong>No hay plantillas creadas.</strong> 
            <a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=wec_email_tpl')); ?>">Crear Primera Plantilla</a>
        </p>
        <?php endif; ?>
        
        <h3>Configuración .env (Opcional)</h3>
        <p>Para mayor seguridad, puedes configurar los datos SMTP en un archivo <code>.env</code> en la ruta:</p>
        <code><?php echo esc_html(ABSPATH . 'programData/emailsWishList/.env'); ?></code>
        
        <p>Ejemplo de contenido del archivo .env:</p>
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
        echo '
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
        $env_path = ABSPATH . 'programData/emailsWishList/.env';
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
            wp_die('No autorizado');
        }
        
        check_admin_referer('wec_send_test');
        
        $tpl_id = intval($_POST['wec_template_id'] ?? 0);
        $to = sanitize_email($_POST['wec_test_email'] ?? '');
        
        if (!$tpl_id || !is_email($to)) {
            wp_die('Datos inválidos. Verifica que hayas seleccionado una plantilla y un email válido.');
        }
        
        // Verificar que la plantilla existe
        $template = get_post($tpl_id);
        if (!$template || $template->post_type !== 'wec_email_tpl') {
            wp_die('Plantilla no encontrada o inválida.');
        }
        
        try {
            // Renderizar plantilla usando el Template Manager
            if (class_exists('WEC_Template_Manager')) {
                $template_manager = WEC_Template_Manager::get_instance();
                $template_result = $template_manager->render_template_content($tpl_id);
            } else {
                wp_die('Template Manager no disponible.');
            }
            
            if (is_wp_error($template_result)) {
                wp_die('Error en la plantilla: ' . $template_result->get_error_message());
            }
            
            list($subject, $html_content) = $template_result;
            
            // Enviar email
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            $ok = wp_mail($to, $subject, $html_content, $headers);
            
            // Redirect con resultado
            $redirect_url = admin_url('admin.php?page=wec-smtp&test=' . ($ok ? 'ok' : 'fail'));
            wp_safe_redirect($redirect_url);
            
        } catch (Exception $e) {
            wp_die('Error al enviar email de prueba: ' . $e->getMessage());
        }
        
        exit;
    }
    
    /**
     * Establece el tipo de contenido para emails HTML
     * @return string
     */
    public function set_mail_content_type() {
        return 'text/html';
    }
    
    /**
     * Parsea un archivo .env y devuelve array asociativo
     * @param string $file_path
     * @return array
     */
    private function parse_env_file($file_path) {
        if (!file_exists($file_path)) {
            return [];
        }
        
        $env = [];
        $lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Ignorar comentarios
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Buscar formato KEY=VALUE
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
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
        $env_path = ABSPATH . 'programData/emailsWishList/.env';
        
        return [
            'env_file_exists'    => file_exists($env_path),
            'host_configured'    => !empty($config['host']),
            'auth_configured'    => !empty($config['user']),
            'from_configured'    => !empty($config['from']),
            'encryption'         => $config['secure'] ?: 'none',
            'port'              => $config['port'] ?: 'default',
            'is_fully_configured' => $this->is_smtp_configured(),
        ];
    }
}

endif; // class_exists('WEC_SMTP_Manager')
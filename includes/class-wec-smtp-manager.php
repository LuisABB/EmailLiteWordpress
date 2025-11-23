<?php
/**
 * WP Email Collector - Gestor de Plantillas de Email
 *
 * Gestiona el envío de emails de prueba y la renderización de plantillas.
 *
 * @since 7.1.0
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
    const SEND_TEST_ACTION = 'wec_send_test';
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
     * Renderiza el formulario de prueba de email y gestión de plantillas
     * @param array $templates Lista de plantillas disponibles
     */
    public function render_smtp_form($templates = []) {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Enviar Email de Prueba', 'wp-email-collector'); ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wec_send_test'); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SEND_TEST_ACTION); ?>">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wec_test_email"><?php esc_html_e('Email de destino', 'wp-email-collector'); ?></label></th>
                        <td><input type="email" name="wec_test_email" id="wec_test_email" required class="regular-text" placeholder="tucorreo@ejemplo.com"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wec_template_id"><?php esc_html_e('Plantilla de Email', 'wp-email-collector'); ?></label></th>
                        <td>
                            <select name="wec_template_id" id="wec_template_id" required>
                                <option value=""><?php esc_html_e('Selecciona una plantilla', 'wp-email-collector'); ?></option>
                                <?php foreach ($templates as $tpl): ?>
                                    <option value="<?php echo esc_attr($tpl->ID); ?>"><?php echo esc_html($tpl->post_title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Enviar Email de Prueba', 'wp-email-collector')); ?>
            </form>
        </div>
        <?php
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
        // Handler para envío de emails de prueba
        add_action('admin_post_' . self::SEND_TEST_ACTION, [$this, 'handle_send_test']);
        // Filtro para forzar contenido HTML en emails
        add_filter('wp_mail_content_type', [$this, 'set_mail_content_type']);
    }
    
    /**
     * Renderiza la página de configuración de email de prueba
     */
    public function render_smtp_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permisos para acceder a esta página.', 'wp-email-collector'));
        }
        $this->show_test_message();
        $templates = $this->get_email_templates();
        $this->render_smtp_form($templates);
    }
    
    /**
     * Muestra mensaje del resultado del test con validación de nonce y parámetros seguros
     */
    private function show_test_message() {
        if (isset($_GET['test']) && isset($_GET['test_nonce'])) {
            // Sanitizar parámetros de entrada
            $test_result = sanitize_text_field($_GET['test']);
            $test_nonce = sanitize_text_field($_GET['test_nonce']);
            // Validar que el test result esté en la lista de valores permitidos
            if (!in_array($test_result, ['ok', 'fail'], true)) {
                return; // Valor no permitido, no mostrar mensaje
            }
            // Crear nonce específico para este resultado para validación
            $expected_nonce = wp_create_nonce('wec_test_result_' . $test_result);
            // Validar nonce específico para prevenir ataques de manipulación de URL
            if (!hash_equals($expected_nonce, $test_nonce)) {
                return;
            }
            // Mostrar mensaje según resultado
            if ($test_result === 'ok') {
                echo '<div class="notice notice-success"><p><strong>✅ ' . __('Email de prueba enviado correctamente', 'wp-email-collector') . '</strong> - ' . __('La configuración SMTP funciona.', 'wp-email-collector') . '</p></div>';
            } elseif ($test_result === 'fail') {
                echo '<div class="notice notice-error"><p><strong>❌ ' . __('Error al enviar email de prueba', 'wp-email-collector') . '</strong> - ' . __('Revisa la configuración SMTP.', 'wp-email-collector') . '</p></div>';
            }
        }
    }

    /**
     * Maneja el envío de emails de prueba
     */
    public function handle_send_test() {
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
            // Procesar HTML para compatibilidad con clientes de email (inlining CSS, resets)
            $html_content = $this->build_email_html($html_content, $to, [
                'inline'        => true,    // Activar inlining para Gmail
                'preserve_css'  => false,   // Gmail necesita estilos inline puros  
                'reset_links'   => true     // Aplicar todas las correcciones
            ]);
            // Enviar email con captura de errores
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            $ok = wp_mail($to, $subject, $html_content, $headers);
            // Determinar resultado y crear nonce específico para ese resultado
            $test_result = $ok ? 'ok' : 'fail';
            $test_nonce = wp_create_nonce('wec_test_result_' . $test_result);
            // Redirect con resultado y nonce específico
            $redirect_url = admin_url('admin.php?page=wec-smtp&test=' . $test_result . '&test_nonce=' . $test_nonce);
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
        // OPTIMIZACIÓN: Combinar múltiples elementos relacionados en una sola pasada
        // Procesar todos los elementos de tabla (table, td, th) en un solo regex
        $html = preg_replace_callback('/<(table|td|th)([^>]*?)>/i', function($matches) {
            $tag = strtolower($matches[1]);
            $attributes = $matches[2];
            
            // Si no tiene style, agregar estilos específicos según el elemento
            if (strpos($attributes, 'style=') === false) {
                switch ($tag) {
                    case 'table':
                        $attributes .= ' style="border-collapse: collapse; width: 100%;"';
                        break;
                    case 'td':
                        $attributes .= ' style="padding: 10px; vertical-align: top;"';
                        break;
                    case 'th':
                        $attributes .= ' style="padding: 10px; text-align: left; font-weight: bold;"';
                        break;
                }
            }
            
            return '<' . $tag . $attributes . '>';
        }, $html);
        
        // Procesar elementos de texto (p, a) en una segunda pasada
        $html = preg_replace_callback('/<(p|a)(\s[^>]*?)?>/i', function($matches) {
            $tag = strtolower($matches[1]);
            $attributes = $matches[2] ?? '';
            
            // Si no tiene style, agregar estilos específicos según el elemento
            if (strpos($attributes, 'style=') === false) {
                switch ($tag) {
                    case 'p':
                        $attributes .= ' style="margin: 0 0 16px 0; line-height: 1.6;"';
                        break;
                    case 'a':
                        $attributes .= ' style="color: #0073aa; text-decoration: none;"';
                        break;
                }
            }
            
            return '<' . $tag . $attributes . '>';
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
        // Remover comentarios CSS que Gmail puede interpretar mal usando método seguro
        $html = $this->remove_conditional_comments_safely($html);
        
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
     * Remueve comentarios condicionales de IE de forma segura sin riesgo de backtracking
     * Previene vulnerabilidades de DoS por regex con HTML malicioso
     * @param string $html
     * @return string
     */
    private function remove_conditional_comments_safely($html) {
        // Usar método iterativo con límites de seguridad en lugar de regex anidado
        $max_iterations = 50; // Límite para prevenir loops infinitos
        $iteration = 0;
        
        while ($iteration < $max_iterations) {
            $iteration++;
            
            // Buscar el inicio del comentario condicional de forma segura
            $start_pos = strpos($html, '<!--[if');
            if ($start_pos === false) {
                // También buscar variante con espacios
                $start_pos = strpos($html, '<!-- [if');
                if ($start_pos === false) {
                    break; // No hay más comentarios condicionales
                }
            }
            
            // Buscar el final correspondiente con límite de búsqueda
            $search_start = $start_pos + 7; // Después de "<!--[if" o "<!-- [if"
            $max_search_length = 10000; // Límite máximo de caracteres a procesar
            $search_end = min(strlen($html), $search_start + $max_search_length);
            $search_section = substr($html, $search_start, $search_end - $search_start);
            
            $end_marker = '<![endif]-->';
            $end_pos = strpos($search_section, $end_marker);
            
            if ($end_pos === false) {
                // Comentario condicional malformado o demasiado largo, remover solo el inicio
                $html = substr($html, 0, $start_pos) . substr($html, $start_pos + 7);
                continue;
            }
            
            // Calcular posición absoluta del final
            $absolute_end_pos = $search_start + $end_pos + strlen($end_marker);
            
            // Remover el comentario condicional completo
            $html = substr($html, 0, $start_pos) . substr($html, $absolute_end_pos);
        }
        
        // Log si se alcanzó el límite de iteraciones (posible contenido malicioso)
        if ($iteration >= $max_iterations) {
            error_log('WEC_SMTP_Manager: Reached maximum iterations while removing conditional comments - possible malicious HTML');
        }
        
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
     * Construye el HTML final del email con el mismo nivel de procesamiento que las campañas
     * Aplica CSS inlining, Gmail compatibility y resets como en build_email_html del plugin principal
     * @param string $raw_html Contenido HTML crudo
     * @param string|null $recipient_email Email del destinatario para UNSUB_URL (opcional en tests)
     * @param array $opts Opciones de procesamiento
     * @return string HTML procesado y optimizado para clientes de email
     */
    private function build_email_html($raw_html, $recipient_email = null, array $opts = []) {
        // Configuración por defecto (compatible con el plugin principal)
        $defaults = [
            'inline'       => false,    // Para vista previa
            'preserve_css' => true,     // Conservar estilos CSS
            'reset_links'  => false     // No aplicar resets por defecto
        ];
        $opts = array_merge($defaults, $opts);
        
        $html = $raw_html;
        
        // PASO 1: Reemplazar placeholders si hay email destinatario
        if ($recipient_email) {
            $html = str_replace('[[UNSUB_URL]]', $this->get_unsub_url($recipient_email), $html);
        } else {
            // Para tests, usar URL genérica
            $html = str_replace('[[UNSUB_URL]]', home_url('/unsubscribe/'), $html);
        }
        
        // PASO 2: Agregar estilos de reset para email antes del inlining
        $html = $this->add_email_reset_styles_advanced($html);
        
        // PASO 3: CSS Inlining agresivo para Gmail compatibility
        if ($opts['inline']) {
            $html = $this->inline_css_rules_advanced($html, $opts['preserve_css']);
        }
        
        // PASO 4: Aplicar correcciones de enlaces para máxima compatibilidad
        if ($opts['reset_links']) {
            $html = $this->apply_advanced_link_resets($html);
        }
        
        // PASO 5: Envolver en estructura HTML completa para email
        return $this->wrap_email_html_advanced($html);
    }
    
    /**
     * Aplica estilos CSS de reset avanzados para clientes de email
     * Equivalente a add_email_reset_styles del plugin principal
     */
    private function add_email_reset_styles_advanced($html) {
        $reset_css = '
        <style type="text/css">
            /* Reset básico para clientes de email */
            body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
            table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-collapse: collapse; }
            img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; display: block; }
            
            /* Reset de enlaces crítico para Gmail */
            a, a:link, a:visited, a:hover, a:active, a:focus {
                color: inherit !important;
                text-decoration: none !important;
                border: 0 !important;
                outline: none !important;
            }
            
            /* Forzar visibilidad de botones críticos */
            .btn, .btn-red, .button, a.btn, a.btn-red {
                display: inline-block !important;
                visibility: visible !important;
                opacity: 1 !important;
                background-color: #D94949 !important;
                color: #ffffff !important;
                padding: 12px 22px !important;
                border-radius: 8px !important;
                font-family: Arial, Helvetica, sans-serif !important;
                font-weight: 700 !important;
                font-size: 16px !important;
                text-decoration: none !important;
                border: 0 !important;
                outline: none !important;
                text-align: center !important;
            }
            
            /* Navegación específica */
            .nav-white a, .dark a {
                color: #ffffff !important;
                text-decoration: none !important;
                font-family: Arial, Helvetica, sans-serif !important;
            }
            
            /* Outlook específico */
            .ExternalClass { width: 100%; }
            .ExternalClass, .ExternalClass p, .ExternalClass span, 
            .ExternalClass font, .ExternalClass td, .ExternalClass div {
                line-height: 100%;
            }
            
            /* Clases de color críticas */
            .text-white { color: #ffffff !important; }
            .text-muted { color: #d6d6d6 !important; }
            .gold { color: #D4AF37 !important; }
            .bg-dark { background-color: #000000 !important; }
            
            /* Centrado para vista previa */
            .center, .text-center { text-align: center !important; }
        </style>';
        
        if (preg_match('/<head[^>]*>/i', $html)) {
            return preg_replace('/<head[^>]*>/i', '$0' . $reset_css, $html, 1);
        } else {
            return $reset_css . $html;
        }
    }
    
    /**
     * CSS inlining avanzado equivalente al del plugin principal
     * Convierte reglas CSS a estilos inline para máxima compatibilidad con Gmail
     */
    private function inline_css_rules_advanced($html, $preserve_styles = false) {
        // Si no hay estilos, no hacer nada
        if (!preg_match_all('#<style[^>]*>(.*?)</style>#is', $html, $m)) {
            return $html;
        }
        
        $css_all = implode("\n", $m[1]);
        
        // Remover estilos del HTML si no preservamos
        if (!$preserve_styles) {
            $html = preg_replace('#<style[^>]*>.*?</style>#is', '', $html);
        }
        
        // Aplicar estilos críticos de botones ANTES del procesamiento general
        $html = $this->apply_critical_button_styles_inline_advanced($html, $css_all);
        
        // Procesar reglas CSS simples y seguras
        if (preg_match_all('#([^{]+)\{([^}]+)\}#', $css_all, $rules, PREG_SET_ORDER)) {
            foreach ($rules as $rule) {
                $selector_raw = trim($rule[1]);
                $declarations = trim($rule[2]);
                
                // Procesar cada selector por separado
                $selectors = array_map('trim', explode(',', $selector_raw));
                foreach($selectors as $sel) {
                    if ($sel === '') continue;
                    
                    // Saltar media queries y pseudo-elementos no soportados
                    if (strpos($sel, '@') !== false || strpos($sel, '::') !== false) continue;
                    
                    // Saltar selectores de botones (ya procesados)
                    if (preg_match('/btn/i', $sel)) continue;
                    
                    // Selectores simples (tag, .class, #id)
                    if (preg_match('/^[a-zA-Z]+$/', $sel) || preg_match('/^\.[a-zA-Z0-9_-]+$/', $sel)) {
                        $regex = $this->selector_to_regex_simple($sel);
                        if ($regex) {
                            $html = preg_replace_callback($regex, function($m) use ($declarations){
                                return $this->merge_inline_styles_safe($m[0], $declarations);
                            }, $html);
                        }
                    }
                }
            }
        }
        
        return $html;
    }
    
    /**
     * Aplica estilos críticos a botones de forma segura antes del inlining
     */
    private function apply_critical_button_styles_inline_advanced($html, $css_all) {
        $button_styles = 'display:inline-block!important;visibility:visible!important;opacity:1!important;background-color:#D94949!important;color:#ffffff!important;padding:12px 22px!important;border-radius:8px!important;font-family:Arial,Helvetica,sans-serif!important;font-weight:700!important;font-size:16px!important;text-decoration:none!important;border:0!important;outline:none!important;text-align:center!important;';
        
        // Aplicar estilos a elementos con clase btn
        $html = preg_replace_callback(
            '#<a\b([^>]*\bclass=["\'][^"\']*\bbtn[^"\']*["\'][^>]*)>(.*?)</a>#is',
            function($m) use ($button_styles) {
                $attrs = $m[1];
                $content = $m[2];
                
                if (preg_match('/\sstyle=(["\'])(.*?)\1/i', $attrs, $sm)) {
                    $existing = trim($sm[2]);
                    $combined = $button_styles . ';' . $existing;
                    $attrs = preg_replace('/\sstyle=(["\'])(.*?)\1/i', ' style="' . esc_attr($combined) . '"', $attrs, 1);
                } else {
                    $attrs .= ' style="' . esc_attr($button_styles) . '"';
                }
                
                return '<a' . $attrs . '>' . $content . '</a>';
            },
            $html
        );
        
        return $html;
    }
    
    /**
     * Convierte selectores CSS simples a regex para inlining
     */
    private function selector_to_regex_simple($sel) {
        $sel = trim($sel);
        
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9]*)$/', $sel, $m)) {
            // Tag selector: p, div, etc.
            $tag = $m[1];
            return '#<' . $tag . '\b([^>]*?)>#i';
        } elseif (preg_match('/^\.([a-zA-Z0-9_-]+)$/', $sel, $m)) {
            // Class selector: .btn, .center, etc.
            $class = $m[1];
            return '#<[a-zA-Z][a-zA-Z0-9]*\b(?=[^>]*\bclass=["\'][^"\']*\b' . preg_quote($class, '#') . '\b[^"\']*["\'])[^>]*?>#i';
        }
        
        return null; // Selector no soportado
    }
    
    /**
     * Combina estilos inline de forma segura
     */
    private function merge_inline_styles_safe($element, $new_declarations) {
        $new_decl = trim($new_declarations);
        if ($new_decl !== '' && substr($new_decl, -1) !== ';') {
            $new_decl .= ';';
        }
        
        if (preg_match('/\sstyle=("|\')(.*?)\1/i', $element, $sm)) {
            $existing = trim($sm[2]);
            $combined = $existing . ($existing && substr($existing, -1) !== ';' ? ';' : '') . $new_decl;
            return preg_replace('/\sstyle=("|\')(.*?)\1/i', ' style="' . esc_attr($combined) . '"', $element, 1);
        } else {
            return preg_replace('/>/', ' style="' . esc_attr($new_decl) . '">', $element, 1);
        }
    }
    
    /**
     * Aplica correcciones avanzadas de enlaces para clientes de email
     */
    private function apply_advanced_link_resets($html) {
        // Reset global de enlaces preservando todos los atributos
        $html = preg_replace_callback(
            '#<a\b([^>]*)>#i',
            function($m) {
                $attrs = $m[1];
                $base_styles = 'text-decoration:none!important;border:0!important;outline:none!important;';
                
                if (preg_match('/\sstyle=("|\')(.*?)\1/i', $attrs, $sm)) {
                    $existing_style = trim($sm[2]);
                    if (!preg_match('/(^|;)\s*color\s*:/i', $existing_style)) {
                        $existing_style .= ($existing_style && substr($existing_style, -1) !== ';' ? ';' : '') . 'color:inherit!important;';
                    }
                    $final_style = $existing_style . ($existing_style && substr($existing_style, -1) !== ';' ? ';' : '') . $base_styles;
                    $attrs = preg_replace('/\sstyle=("|\')(.*?)\1/i', ' style="' . esc_attr($final_style) . '"', $attrs, 1);
                } else {
                    $attrs .= ' style="color:inherit!important;' . $base_styles . '"';
                }
                return '<a' . $attrs . '>';
            },
            $html
        );
        
        // Normalizar imágenes dentro de enlaces
        $html = preg_replace_callback(
            '#(<a\b[^>]*>)(.*?)(</a>)#is',
            function($m) {
                $open_tag = $m[1];
                $inner_content = $m[2];
                $close_tag = $m[3];
                
                $inner_content = preg_replace_callback(
                    '#<img\b([^>]*)>#i',
                    function($img_match) {
                        $img_attrs = $img_match[1];
                        $img_styles = 'display:block!important;border:0!important;outline:none!important;text-decoration:none!important;';
                        
                        if (preg_match('/\sstyle=("|\')(.*?)\1/i', $img_attrs)) {
                            $img_attrs = preg_replace('/\sstyle=("|\')(.*?)\1/i', ' style="$2;' . $img_styles . '"', $img_attrs, 1);
                        } else {
                            $img_attrs .= ' style="' . esc_attr($img_styles) . '"';
                        }
                        
                        if (!preg_match('/\bborder\s*=/i', $img_attrs)) {
                            $img_attrs .= ' border="0"';
                        }
                        
                        return '<img' . $img_attrs . '>';
                    },
                    $inner_content
                );
                
                return $open_tag . $inner_content . $close_tag;
            },
            $html
        );
        
        return $html;
    }
    
    /**
     * Envuelve HTML en estructura completa optimizada para email
     */
    private function wrap_email_html_advanced($body_html) {
        if (preg_match('/<html\b/i', $body_html)) {
            // Si ya es HTML completo, agregar elementos faltantes
            if (preg_match('/<head\b[^>]*>/i', $body_html) && !preg_match('/<base\b/i', $body_html)) {
                $base = '<base href="' . esc_url(home_url('/')) . '">';
                $body_html = preg_replace('/<head\b[^>]*>/i', '$0' . $base, $body_html, 1);
            }
            return $body_html;
        }
        
        // Crear HTML completo con metadatos optimizados para email
        $head = '<meta charset="utf-8">'
              . '<meta name="viewport" content="width=device-width,initial-scale=1">'
              . '<meta name="x-apple-disable-message-reformatting">'
              . '<meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no">'
              . '<meta name="color-scheme" content="light only">'
              . '<meta name="supported-color-schemes" content="light">'
              . '<base href="' . esc_url(home_url('/')) . '">'
              . '<!--[if gte mso 9]><xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->';
        
        $body_attrs = 'style="margin:0;padding:0;background-color:#ffffff;font-family:Arial,sans-serif;" '
                    . 'link="#000000" vlink="#000000" alink="#000000" '
                    . 'bgcolor="#ffffff"';
        
        return '<!doctype html>'
             . '<html lang="es" xmlns="http://www.w3.org/1999/xhtml" xmlns:o="urn:schemas-microsoft-com:office:office">'
             . '<head>' . $head . '</head>'
             . '<body ' . $body_attrs . '>' . $body_html . '</body>'
             . '</html>';
    }
    
    /**
     * Genera URL de baja para emails de prueba (método simplificado)
     */
    private function get_unsub_url($email) {
        if (empty($email)) return home_url('/unsubscribe/');
        
        // Generar token criptográficamente seguro usando HMAC-SHA256
        $token = substr(hash_hmac('sha256', $email, wp_salt('auth') . 'wec_unsub'), 0, 32);
        return home_url('/unsubscribe/?e=' . rawurlencode($email) . '&t=' . $token);
    }
    
    /**
     * Establece el tipo de contenido para emails HTML
     * @return string
     */
    public function set_mail_content_type() {
        return 'text/html';
    }
    
}

endif; // class_exists('WEC_SMTP_Manager')
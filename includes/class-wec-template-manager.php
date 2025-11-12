<?php

if (!defined('ABSPATH')) exit;

/**
 * Gestor de plantillas de email para WEC
 * 
 * Se encarga de:
 * - Registrar el Custom Post Type de plantillas
 * - Gestionar metaboxes (asunto, vista previa, info)
 * - Renderizar contenido de plantillas con variables
 * - Validar plantillas antes de usar
 * - Proporcionar funciones auxiliares
 */
class WEC_Template_Manager {
    const CPT_TPL = 'wec_email_tpl';
    const META_SUBJECT = '_wec_subject';
    
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
        // CPT Registration
        add_action('init', [$this, 'register_cpt_templates']);
        
        // Metaboxes
        add_action('add_meta_boxes', [$this, 'add_metaboxes']);
        add_action('save_post_' . self::CPT_TPL, [$this, 'save_subject_metabox']);
        
        // Admin columns
        add_filter('manage_' . self::CPT_TPL . '_posts_columns', [$this, 'add_admin_columns']);
        add_action('manage_' . self::CPT_TPL . '_posts_custom_column', [$this, 'populate_admin_columns'], 10, 2);
        
        // Admin assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }
    
    /**
     * Registra el Custom Post Type para plantillas
     */
    public function register_cpt_templates() {
        $labels = [
            'name'               => 'Email Templates',
            'singular_name'      => 'Email Template',
            'add_new'            => 'Añadir nueva',
            'add_new_item'       => 'Añadir plantilla',
            'edit_item'          => 'Editar plantilla',
            'new_item'           => 'Nueva plantilla',
            'view_item'          => 'Ver plantilla',
            'search_items'       => 'Buscar plantillas',
            'not_found'          => 'No se encontraron plantillas',
            'not_found_in_trash' => 'No hay plantillas en la papelera',
            'menu_name'          => 'Plantillas',
        ];
        
        register_post_type(self::CPT_TPL, [
            'labels'              => $labels,
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'wec-campaigns', // Mantener bajo el menú principal
            'supports'            => ['title', 'editor'],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'menu_icon'           => 'dashicons-email-alt',
            'show_in_admin_bar'   => true,
            'can_export'          => true,
            'menu_position'       => 20,
        ]);
    }
    
    /**
     * Agrega metaboxes a las plantillas
     */
    public function add_metaboxes() {
        add_meta_box(
            'wec_subject_box', 
            'Asunto del correo', 
            [$this, 'render_subject_metabox'], 
            self::CPT_TPL, 
            'side', 
            'high'
        );
        
        add_meta_box(
            'wec_preview_box', 
            'Vista previa', 
            [$this, 'render_preview_metabox'], 
            self::CPT_TPL, 
            'side', 
            'high'
        );
        
        add_meta_box(
            'wec_template_info', 
            'Información de la plantilla', 
            [$this, 'render_info_metabox'], 
            self::CPT_TPL, 
            'normal', 
            'default'
        );
    }
    
    /**
     * Renderiza el metabox del asunto
     */
    public function render_subject_metabox($post) {
        wp_nonce_field('wec_subject_save', '_wec_subject_nonce');
        $subject = get_post_meta($post->ID, self::META_SUBJECT, true);
        ?>
        <p>
            <input type="text" 
                   name="wec_subject" 
                   class="widefat" 
                   value="<?php echo esc_attr($subject); ?>" 
                   placeholder="Ej: No es cualquier reloj, es un Curren ⌚"
                   maxlength="255">
        </p>
        <p class="description">
            <strong>Placeholders disponibles:</strong><br>
            <code>{{site_name}}</code> - Nombre del sitio<br>
            <code>{{site_url}}</code> - URL del sitio<br>
            <code>{{admin_email}}</code> - Email del admin<br>
            <code>{{date}}</code> - Fecha actual<br>
            <code>{{current_year}}</code> - Año actual<br>
            <code>{{current_month}}</code> - Mes actual
        </p>
        <?php
    }
    
    /**
     * Renderiza el metabox de vista previa
     */
    public function render_preview_metabox($post) {
        ?>
        <input type="hidden" id="wec_template_id" value="<?php echo esc_attr($post->ID); ?>">
        <p>
            <button id="wec-btn-preview" type="button" class="button button-primary button-large" style="width: 100%;">
                📧 Vista previa
            </button>
        </p>
        <p class="description">
            Abre la vista previa optimizada para ver cómo se verá el email en diferentes dispositivos.
        </p>
        
        <div id="wec-preview-stats" style="margin-top: 10px; padding: 8px; background: #f9f9f9; border-radius: 3px; font-size: 12px;">
            <strong>Estadísticas:</strong><br>
            <span id="wec-content-length">Calculando...</span>
        </div>
        <?php
        echo $this->render_preview_modal_html();
    }
    
    /**
     * Renderiza metabox informativo
     */
    public function render_info_metabox($post) {
        $usage_count = $this->get_template_usage_count($post->ID);
        $last_used = $this->get_template_last_used($post->ID);
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">Uso en campañas:</th>
                <td>
                    <strong><?php echo intval($usage_count); ?></strong> campañas
                    <?php if ($usage_count > 0): ?>
                        <span style="color: #46b450;">✓ En uso</span>
                    <?php else: ?>
                        <span style="color: #dc3232;">⚠ Sin usar</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($last_used): ?>
            <tr>
                <th scope="row">Último uso:</th>
                <td><?php echo date_i18n('d/m/Y H:i', strtotime($last_used)); ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <th scope="row">Última modificación:</th>
                <td><?php echo get_the_modified_date('d/m/Y H:i', $post); ?></td>
            </tr>
            <tr>
                <th scope="row">Estado:</th>
                <td>
                    <span class="template-status template-status-<?php echo esc_attr($post->post_status); ?>">
                        <?php echo ucfirst($post->post_status === 'publish' ? 'Publicada' : 'Borrador'); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th scope="row">ID de plantilla:</th>
                <td><code><?php echo $post->ID; ?></code></td>
            </tr>
        </table>
        
        <?php if ($usage_count > 0): ?>
        <div style="margin-top: 15px; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 3px;">
            <strong>⚠️ Advertencia:</strong> Esta plantilla está siendo usada en <?php echo $usage_count; ?> campaña(s). 
            Los cambios afectarán a futuras ejecuciones.
        </div>
        <?php endif; ?>
        
        <style>
        .template-status {
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 600;
        }
        .template-status-publish { background: #d4edda; color: #155724; }
        .template-status-draft { background: #fff3cd; color: #856404; }
        .form-table th { width: 140px; }
        </style>
        <?php
    }
    
    /**
     * Guarda el metabox del asunto
     */
    public function save_subject_metabox($post_id) {
        // Verificaciones de seguridad
        if (!isset($_POST['_wec_subject_nonce']) || 
            !wp_verify_nonce($_POST['_wec_subject_nonce'], 'wec_subject_save')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        $subject = sanitize_text_field($_POST['wec_subject'] ?? '');
        update_post_meta($post_id, self::META_SUBJECT, $subject);
        
        // Log para debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WEC: Asunto guardado para plantilla {$post_id}: {$subject}");
        }
    }
    
    /**
     * Agrega columnas personalizadas en el listado
     */
    public function add_admin_columns($columns) {
        // Reorganizar columnas
        $new_columns = [];
        foreach ($columns as $key => $title) {
            $new_columns[$key] = $title;
            if ($key === 'title') {
                $new_columns['subject'] = 'Asunto';
                $new_columns['usage_count'] = 'Uso';
                $new_columns['preview'] = 'Acciones';
            }
        }
        return $new_columns;
    }
    
    /**
     * Rellena las columnas personalizadas
     */
    public function populate_admin_columns($column, $post_id) {
        switch ($column) {
            case 'subject':
                $subject = get_post_meta($post_id, self::META_SUBJECT, true);
                if ($subject) {
                    echo '<strong>' . esc_html($subject) . '</strong>';
                } else {
                    echo '<em style="color:#999;">Sin asunto</em>';
                }
                break;
                
            case 'usage_count':
                $count = $this->get_template_usage_count($post_id);
                if ($count > 0) {
                    echo '<span class="usage-count" style="color: #46b450;"><strong>' . intval($count) . '</strong> campañas</span>';
                } else {
                    echo '<span class="usage-count" style="color: #999;">Sin usar</span>';
                }
                break;
                
            case 'preview':
                ?>
                <div class="template-actions">
                    <button type="button" 
                            class="button button-small wec-btn-preview" 
                            data-template-id="<?php echo esc_attr($post_id); ?>">
                        📧 Vista previa
                    </button>
                </div>
                <?php
                break;
        }
    }
    
    /**
     * Carga assets del admin
     */
    public function enqueue_admin_assets($hook) {
        global $post;
        
        // Solo cargar en páginas de plantillas
        if (($hook === 'post.php' || $hook === 'post-new.php') && 
            isset($post->post_type) && $post->post_type === self::CPT_TPL) {
            
            // Actualizar estadísticas de contenido via JavaScript
            wp_add_inline_script('jquery', '
                jQuery(document).ready(function($) {
                    function updateContentStats() {
                        var content = $("#content").val() || "";
                        var wordCount = content.trim().split(/\s+/).length;
                        var charCount = content.length;
                        $("#wec-content-length").html(
                            "Palabras: " + wordCount + " | Caracteres: " + charCount
                        );
                    }
                    
                    // Actualizar al cargar y al escribir
                    updateContentStats();
                    $("#content").on("input keyup", updateContentStats);
                });
            ');
        }
        
        // Cargar en listado de plantillas
        if ($hook === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === self::CPT_TPL) {
            wp_add_inline_script('jquery', '
                jQuery(document).ready(function($) {
                    // Hacer que los botones de vista previa funcionen en el listado
                    $(document).on("click", ".wec-btn-preview", function(e) {
                        e.preventDefault();
                        var templateId = $(this).data("template-id");
                        if (templateId) {
                            // Abrir vista previa (esto se conectará con el sistema existente)
                            console.log("Preview template:", templateId);
                        }
                    });
                });
            ');
        }
    }
    
    /**
     * Obtiene el HTML del modal de vista previa
     */
    private function render_preview_modal_html() {
        ob_start(); ?>
        <div id="wec-preview-modal" style="display:none;">
            <div id="wec-preview-wrap">
                <div class="wec-toolbar">
                    <div id="wec-preview-subject">Cargando asunto...</div>
                    <span class="sep"></span>
                    <button type="button" class="button" data-wec-size="mobile">📱 Móvil 360</button>
                    <button type="button" class="button" data-wec-size="tablet">📟 Tablet 600</button>
                    <button type="button" class="button" data-wec-size="desktop">💻 Desktop 800</button>
                    <button type="button" class="button" data-wec-size="full">🖥️ Ancho libre</button>
                </div>
                <div class="wec-canvas">
                    <div class="wec-frame-wrap" id="wec-frame-wrap" style="width:800px;">
                        <iframe id="wec-preview-iframe" sandbox="allow-forms allow-same-origin allow-scripts"></iframe>
                        <div class="wec-frame-info" id="wec-frame-info">800px de ancho</div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Renderiza el contenido de una plantilla con variables reemplazadas
     */
    public function render_template_content($template_id) {
        $post = get_post($template_id);
        if (!$post || $post->post_type !== self::CPT_TPL) {
            throw new Exception('Plantilla no encontrada.');
        }
        
        $subject = get_post_meta($template_id, self::META_SUBJECT, true) ?: '(Sin asunto)';
        $html = (string) $post->post_content;
        
        // Contenido por defecto si está vacío
        if (empty(trim($html))) {
            $html = $this->get_default_template_content();
        }
        
        // Reemplazar variables
        $replacements = $this->get_template_variables();
        $html = strtr($html, $replacements);
        $subject = strtr($subject, $replacements);
        
        return [$subject, $html];
    }
    
    /**
     * Obtiene las variables disponibles para plantillas
     */
    public function get_template_variables() {
        return [
            '{{site_name}}'     => get_bloginfo('name'),
            '{{site_url}}'      => home_url('/'),
            '{{admin_email}}'   => get_option('admin_email'),
            '{{date}}'          => date_i18n(get_option('date_format') . ' ' . get_option('time_format')),
            '{{current_year}}'  => date('Y'),
            '{{current_month}}' => date_i18n('F'),
            '{{site_description}}' => get_bloginfo('description'),
        ];
    }
    
    /**
     * Obtiene contenido por defecto para plantillas vacías
     */
    private function get_default_template_content() {
        return '<div style="text-align:center;padding:40px;font-family:Arial,sans-serif;">
            <h2>Plantilla de email</h2>
            <p>Agrega tu código HTML en el editor de contenido.</p>
            <p style="color:#666;">
                <strong>Tip:</strong> Puedes usar variables como {{site_name}} y {{date}}<br>
                El enlace [[UNSUB_URL]] se reemplazará automáticamente.
            </p>
            <div style="margin:20px 0;">
                <a href="#" class="btn" style="background:#D94949;color:#fff;padding:12px 22px;text-decoration:none;border-radius:8px;">
                    Botón de ejemplo
                </a>
            </div>
        </div>';
    }
    
    /**
     * Obtiene cuántas veces se ha usado una plantilla
     */
    private function get_template_usage_count($template_id) {
        global $wpdb;
        $table_jobs = $wpdb->prefix . 'wec_jobs';
        
        // Verificar si la tabla existe
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_jobs}'") !== $table_jobs) {
            return 0;
        }
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_jobs} WHERE tpl_id = %d", 
            $template_id
        ));
    }
    
    /**
     * Obtiene la fecha del último uso de una plantilla
     */
    private function get_template_last_used($template_id) {
        global $wpdb;
        $table_jobs = $wpdb->prefix . 'wec_jobs';
        
        // Verificar si la tabla existe
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_jobs}'") !== $table_jobs) {
            return null;
        }
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(created_at) FROM {$table_jobs} WHERE tpl_id = %d", 
            $template_id
        ));
    }
    
    /**
     * Obtiene todas las plantillas disponibles
     */
    public function get_all_templates($args = []) {
        $defaults = [
            'post_type'   => self::CPT_TPL,
            'post_status' => ['publish', 'draft'],
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC'
        ];
        
        $args = wp_parse_args($args, $defaults);
        return get_posts($args);
    }
    
    /**
     * Valida una plantilla antes de usarla
     */
    public function validate_template($template_id) {
        $post = get_post($template_id);
        
        if (!$post) {
            return new WP_Error('template_not_found', 'Plantilla no encontrada');
        }
        
        if ($post->post_type !== self::CPT_TPL) {
            return new WP_Error('invalid_template_type', 'Tipo de post inválido');
        }
        
        $subject = get_post_meta($template_id, self::META_SUBJECT, true);
        if (empty(trim($subject))) {
            return new WP_Error('missing_subject', 'La plantilla necesita un asunto');
        }
        
        // Validar que el contenido no esté completamente vacío
        $content = trim($post->post_content);
        if (empty($content)) {
            return new WP_Error('missing_content', 'La plantilla necesita contenido');
        }
        
        return true;
    }
    
    /**
     * Crea una plantilla de ejemplo
     */
    public function create_sample_template() {
        $sample_content = '
<div style="max-width: 600px; margin: 0 auto; font-family: Arial, Helvetica, sans-serif; background-color: #ffffff;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f8f9fa; padding: 20px;">
        <tr>
            <td align="center">
                <h1 style="color: #333333; margin: 0;">{{site_name}}</h1>
                <p style="color: #666666; margin: 10px 0;">Newsletter del {{current_month}} {{current_year}}</p>
            </td>
        </tr>
    </table>
    
    <table width="100%" cellpadding="20" cellspacing="0">
        <tr>
            <td>
                <h2 style="color: #333333;">¡Hola!</h2>
                <p style="color: #555555; line-height: 1.6;">
                    Este es un ejemplo de plantilla de email. Puedes personalizar completamente este contenido
                    desde el editor de WordPress.
                </p>
                
                <div class="center" style="text-align: center; margin: 30px 0;">
                    <a href="{{site_url}}" class="btn btn-red" style="background-color: #D94949; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 8px; font-weight: bold;">
                        Visitar sitio web
                    </a>
                </div>
                
                <p style="color: #555555; line-height: 1.6;">
                    Si no deseas recibir más emails, puedes 
                    <a href="[[UNSUB_URL]]" style="color: #D94949;">darte de baja aquí</a>.
                </p>
            </td>
        </tr>
    </table>
    
    <table width="100%" cellpadding="20" cellspacing="0" style="background-color: #f8f9fa; border-top: 1px solid #e9ecef;">
        <tr>
            <td align="center">
                <p style="color: #999999; font-size: 12px; margin: 0;">
                    © {{current_year}} {{site_name}} - Todos los derechos reservados
                </p>
            </td>
        </tr>
    </table>
</div>';

        $template_id = wp_insert_post([
            'post_title'   => 'Plantilla de ejemplo',
            'post_content' => $sample_content,
            'post_status'  => 'draft',
            'post_type'    => self::CPT_TPL,
        ]);
        
        if ($template_id && !is_wp_error($template_id)) {
            update_post_meta($template_id, self::META_SUBJECT, 'Ejemplo: Newsletter de {{site_name}} - {{current_month}} {{current_year}}');
            return $template_id;
        }
        
        return false;
    }
}

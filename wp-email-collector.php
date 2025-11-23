<?php
/**
 * Plugin Name: WP Email Collector
 * Description: Gestiona plantillas de email, campañas con cola y vista previa. Incluye SMTP, WP-Cron, Unsubscribe y CSS Inliner para vista previa/envíos.
 * Version:     6.0.0
 * Author:      Drexora
 * License:     GPLv2 or later
 * Text Domain: wp-email-collector
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Autoloader para las clases WEC
spl_autoload_register(function($class_name) {
    if (strpos($class_name, 'WEC_') === 0) {
        $file_name = 'class-' . strtolower(str_replace('_', '-', $class_name)) . '.php';
        $file_path = plugin_dir_path(__FILE__) . 'includes/' . $file_name;
        
        if (file_exists($file_path)) {
            require_once $file_path;
            return true;
        }
    }
    return false;
});

if ( ! class_exists('WEC_Email_Collector') ) :

final class WEC_Email_Collector {
    /*** Constants ***/
    const DB_VER                     = '3';
    const ROOT_MENU_SLUG             = 'wec_root';
    const CPT_TPL                    = 'wec_email_tpl';
    const META_SUBJECT               = '_wec_subject';
    const AJAX_ACTION_PREV           = 'wec_preview_template';
    const AJAX_NONCE                 = 'wec_preview_nonce';
    const ADMIN_POST_PREVIEW_IFRAME  = 'wec_preview_iframe';
    const AJAX_ACTION_IFRAME         = 'wec_preview_iframe_html';
    
    /*** Database Table Constants ***/
    const DB_TABLE_JOBS              = 'wec_jobs';
    const DB_TABLE_ITEMS             = 'wec_job_items';
    const DB_TABLE_SUBSCRIBERS       = 'wec_subscribers';

    /*** Bootstrap ***/
    public function __construct() {
        // Configurar zona horaria de Ciudad de México
        add_action( 'init', [ $this, 'setup_timezone' ] );
        
        // Menu & assets
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_menu',            [ $this, 'remove_duplicate_menu' ], 999 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // Inicializar Template Manager (reemplaza las líneas de CPT)
        add_action( 'init', [ $this, 'init_template_manager' ], 5 );

        // Inicializar SMTP Manager
        add_action( 'init', [ $this, 'init_smtp_manager' ], 5 );

        // Inicializar Campaign Manager
        add_action( 'init', [ $this, 'init_campaign_manager' ], 5 );

        // AJAX/Preview
        add_action( 'wp_ajax_'  . self::AJAX_ACTION_PREV,   [ $this, 'ajax_preview_template' ] );
        add_action( 'admin_post_' . self::ADMIN_POST_PREVIEW_IFRAME, [ $this, 'handle_preview_iframe' ] );
        add_action( 'wp_ajax_'  . self::AJAX_ACTION_IFRAME, [ $this, 'ajax_preview_iframe_html' ] );

        // Upgrades and migrations only
        add_action( 'admin_init', [ $this, 'maybe_migrate_once' ] );
        add_action( 'admin_init', [ $this, 'maybe_add_columns' ] );

        // Aviso si DOMDocument no está disponible (solo admins)
        add_action( 'admin_notices', function(){
            if ( ! current_user_can('manage_options') ) return;
            if ( ! class_exists('DOMDocument') ) {
                echo '<div class="notice notice-warning"><p><strong>WP Email Collector:</strong> La extensión PHP <code>DOMDocument</code> no está disponible. '
                   . 'La vista previa sigue funcionando, pero el inliner para selectores descendientes (p.ej. <code>.clase a</code>) será limitado.</p></div>';
            }
        } );
    }

    /*** Timezone Setup ***/
    public function setup_timezone() {
        // Configurar zona horaria de Ciudad de México
        if (!get_option('timezone_string')) {
            update_option('timezone_string', 'America/Mexico_City');
        }
    }

    /**
     * Inicializa el gestor de plantillas
     */
    public function init_template_manager() {
        WEC_Template_Manager::get_instance();
    }

    /**
     * Inicializa el gestor SMTP
     */
    public function init_smtp_manager() {
        WEC_SMTP_Manager::get_instance();
    }

    /**
     * Inicializa el gestor de campañas
     */
    public function init_campaign_manager() {
        WEC_Campaign_Manager::get_instance();
    }

    /**
     * Convierte fecha/hora local de CDMX a formato MySQL UTC
     */
    private function convert_local_to_mysql($datetime_local) {
        if (empty($datetime_local)) {
            return current_time('mysql');
        }
        
        // Crear objeto DateTime en zona horaria de CDMX
        $cdmx_tz = new DateTimeZone('America/Mexico_City');
        $utc_tz = new DateTimeZone('UTC');
        
        try {
            // Parsear la fecha como si estuviera en CDMX
            $dt = new DateTime($datetime_local, $cdmx_tz);
            
            // Convertir a UTC para almacenar en base de datos
            $dt->setTimezone($utc_tz);
            $utc_time = $dt->format('Y-m-d H:i:s');
            
            return $utc_time;
        } catch (Exception $e) {
            // Si hay error, usar hora actual
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
            // Parsear como UTC
            $dt = new DateTime($datetime_mysql, $utc_tz);
            
            // Convertir a CDMX
            $dt->setTimezone($cdmx_tz);
            
            return $dt->format('Y-m-d\TH:i');
        } catch (Exception $e) {
            return $datetime_mysql;
        }
    }

    /**
     * Formatea fecha MySQL UTC para mostrar en CDMX con etiqueta
     */
    private function format_display_datetime($datetime_mysql) {
        if (empty($datetime_mysql)) {
            return 'Inmediato';
        }
        
        $utc_tz = new DateTimeZone('UTC');
        $cdmx_tz = new DateTimeZone('America/Mexico_City');
        
        try {
            // Parsear como UTC
            $dt = new DateTime($datetime_mysql, $utc_tz);
            
            // Convertir a CDMX
            $dt->setTimezone($cdmx_tz);
            
            return $dt->format('d/m/Y H:i') . ' CDMX';
        } catch (Exception $e) {
            return $datetime_mysql;
        }
    }

    /**
     * Obtiene la hora actual en zona horaria CDMX convertida a UTC para comparaciones
     */
    private function get_current_time_cdmx() {
        // CORREGIDO: Usar current_time() con timezone de WordPress
        $current_wp = current_time('mysql');
        
        // Si WordPress ya está en CDMX, convertir a UTC
        if (get_option('timezone_string') === 'America/Mexico_City') {
            $cdmx_tz = new DateTimeZone('America/Mexico_City');
            $utc_tz = new DateTimeZone('UTC');
            
            try {
                // Parsear hora actual como CDMX
                $dt = new DateTime($current_wp, $cdmx_tz);
                
                // Convertir a UTC
                $dt->setTimezone($utc_tz);
                $utc_result = $dt->format('Y-m-d H:i:s');
                
                return $utc_result;
            } catch (Exception $e) {
                return $current_wp;
            }
        }
        
        // Si WordPress no está en CDMX, usar hora del servidor
        return $current_wp;
    }

    /*** Assets ***/
    public function enqueue_admin_assets( $hook ) {
        $is_wec      = ( strpos($hook, self::ROOT_MENU_SLUG) !== false );
        $is_wec_page = in_array($hook, [
            'toplevel_page_' . self::ROOT_MENU_SLUG,
            'email-manager_page_wec-campaigns',
            'toplevel_page_wec-campaigns',  // Added: main campaigns page
            'email-manager_page_wec-smtp'
        ]);
        
        // Force load for any page containing 'wec' or 'campaigns'
        $is_wec_related = (strpos($hook, 'wec') !== false || strpos($hook, 'campaigns') !== false);
        
        $is_tpl_list = ( $hook === 'edit.php' && ( $_GET['post_type'] ?? '' ) === self::CPT_TPL );
        $is_tpl_edit = (
            ( $hook === 'post.php'     && get_post_type( intval($_GET['post'] ?? 0) ) === self::CPT_TPL ) ||
            ( $hook === 'post-new.php' && ( $_GET['post_type'] ?? '' ) === self::CPT_TPL )
        );
        
        if ( ! ( $is_wec || $is_wec_page || $is_wec_related || $is_tpl_list || $is_tpl_edit ) ) return;

        add_thickbox();
        wp_register_script( 'wec-admin', false, [ 'jquery','thickbox' ], '3.0.0', true );
        wp_enqueue_script( 'wec-admin' );

        wp_localize_script( 'wec-admin', 'WEC_AJAX', [
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'iframe_action' => self::AJAX_ACTION_IFRAME,
            'preview_nonce' => wp_create_nonce( 'wec_prev_iframe' ),
        ] );

        wp_add_inline_script( 'wec-admin', $this->inline_js() );
        wp_add_inline_style( 'thickbox',   $this->inline_css() );
    }

    private function inline_css(){
        return '
        #TB_window{width:1080px!important;max-width:95vw!important;height:85vh!important;margin-left:calc(-540px + 0.5vw)!important}
        #TB_ajaxContent{width:100%!important;height:calc(85vh - 50px)!important;padding:0!important;overflow:hidden!important}
        #wec-preview-wrap{display:flex;flex-direction:column;height:100%;background:#f3f4f6}
        .wec-toolbar{display:flex;gap:.5rem;align-items:center;padding:10px;border-bottom:1px solid #e5e7eb;background:#fff;position:sticky;top:0;z-index:1}
        .wec-toolbar .button{line-height:28px;height:28px}
        .wec-toolbar .sep{flex:1 1 auto}
        #wec-preview-subject{font-weight:600;color:#111827}
        .wec-canvas{display:flex;justify-content:center;align-items:flex-start;padding:20px;overflow:auto;height:100%}
        .wec-frame-wrap{background:#fff;box-shadow:0 10px 25px rgba(0,0,0,.12);border-radius:10px}
        .wec-frame-info{text-align:center;font-size:12px;color:#6b7280;padding:6px 0}
        #wec-preview-iframe{display:block;border:0;width:100%;height:calc(75vh - 60px);border-radius:10px}
        .wec-table{width:100%;border-collapse:collapse;background:#fff}
        .wec-table th,.wec-table td{padding:10px;border-bottom:1px solid #eee;text-align:left}
        .wec-help{color:#6b7280;font-size:12px;margin-top:6px}
        .wec-inline{display:flex;gap:10px;align-items:center}
        .status-pending{color:#f59e0b;font-weight:600}
        .status-running{color:#3b82f6;font-weight:600;animation:pulse 2s infinite}
        .status-done{color:#10b981;font-weight:600}
        .status-expired{color:#ef4444;font-weight:600}
        @keyframes pulse{0%{opacity:1}50%{opacity:0.5}100%{opacity:1}}
        ';
    }

    private function inline_js(){
        return <<<'JS'
jQuery(function($){
  function setFrameWidth(mode){
    var w=800;
    if(mode==='mobile')w=360;
    else if(mode==='tablet')w=600;
    else if(mode==='desktop')w=800;
    else if(mode==='full')w=Math.min(window.innerWidth*0.9,1000);
    $('#wec-frame-wrap').css('width',w+'px');
    $('#wec-frame-info').text(w+'px de ancho');
  }

  $(document).on('click','.wec-btn-preview, #wec-btn-preview',function(e){
    e.preventDefault();
    
    if (typeof WEC_AJAX === 'undefined') { 
      alert('No se pudo iniciar vista previa - WEC_AJAX no definido'); 
      return; 
    }
    
    // Determinar el selector de plantilla correcto
    var targetSelector = $(this).data('target');
    var $templateSelect;
    
    if (targetSelector) {
      $templateSelect = $(targetSelector);
    } else {
      // Fallback: buscar el selector más cercano o el default
      $templateSelect = $(this).closest('td').find('select[name="tpl_id"], #wec_template_id');
      if (!$templateSelect.length) {
        $templateSelect = $('#wec_template_id, #wec_template_id_edit, #wec_template_id_create').first();
      }
    }
    
    var tplId = $templateSelect.val();
    
    if(!tplId){ 
      alert('Selecciona una plantilla.'); 
      return; 
    }

    tb_show('Vista previa','#TB_inline?height=700&width=1000&inlineId=wec-preview-modal');

    setFrameWidth('desktop');
    $(document).off('click.wecsize').on('click.wecsize','[data-wec-size]',function(){ setFrameWidth($(this).data('wec-size')); });

    var $f = $('#wec-preview-iframe');
    var di = $f[0].contentDocument || $f[0].contentWindow.document;
    if (di) { di.open(); di.write('<!doctype html><html><body style="font-family:sans-serif;padding:24px;color:#6b7280">Cargando vista previa...</body></html>'); di.close(); }

    var url = WEC_AJAX.ajax_url
            + '?action=' + encodeURIComponent(WEC_AJAX.iframe_action)
            + '&wec_nonce=' + encodeURIComponent(WEC_AJAX.preview_nonce)
            + '&tpl_id=' + encodeURIComponent(tplId);

    $.get(url).done(function(html){
        // Actualizar el asunto si está en el HTML
        if (html.indexOf('<title>') !== -1) {
            var titleMatch = html.match(/<title>(.*?)<\/title>/);
            if (titleMatch && titleMatch[1]) {
                $('#wec-preview-subject').text('Vista previa: ' + titleMatch[1]);
            }
        }
        
        var d = $f[0].contentDocument || $f[0].contentWindow.document;
        try{ d.open(); d.write(html); d.close(); }catch(err){
          var blob = new Blob([html], {type: 'text/html'});
          $f.attr('src', URL.createObjectURL(blob));
        }
    }).fail(function(xhr){
        var d = $f[0].contentDocument || $f[0].contentWindow.document;
        var msg = 'Error de vista previa ('+xhr.status+'): '+(xhr.responseText||'No se pudo cargar el contenido');
        if (d) { d.open(); d.write('<div style="font-family:Arial;padding:40px;text-align:center;"><h3 style="color:#e74c3c;">Error en la vista previa</h3><p>'+msg+'</p><button onclick="tb_remove();" style="background:#007cba;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;">Cerrar</button></div>'); d.close(); }
    });
  });
  
  // Manejar botones de cambio de tamaño
  $(document).on('click', '[data-wec-size]', function(e){
    e.preventDefault();
    setFrameWidth($(this).data('wec-size'));
  });
});
JS;
    }

    /*** Admin UI ***/
    public function add_menu() {
        add_menu_page( 'Email Manager','Email Manager','manage_options', 'wec-campaigns', [ $this, 'render_campaigns_page' ], 'dashicons-email', 26 );
        add_submenu_page( 'wec-campaigns', 'Campañas','Campañas','manage_options', 'wec-campaigns', [ $this, 'render_campaigns_page' ] );
        add_submenu_page( 'wec-campaigns', 'Config. SMTP','Config. SMTP','manage_options', 'wec-smtp', [ $this, 'render_smtp_settings' ] );
        add_submenu_page( 'wec-campaigns', 'Email Templates','Email Templates','manage_options', 'edit.php?post_type='.self::CPT_TPL );
    }

    public function remove_duplicate_menu() {
        // Remover el menú principal del CPT que WordPress crea automáticamente
        // pero mantener nuestro submenú personalizado
        remove_menu_page('edit.php?post_type=' . self::CPT_TPL);
    }

    public function render_campaigns_page(){
        // Delegar al Campaign Manager
        $campaign_manager = WEC_Campaign_Manager::get_instance();
        $campaign_manager->render_campaigns_page();
    }

    /*** SMTP ***/
    public function render_smtp_settings(){
        // Delegar al SMTP Manager
        $smtp_manager = WEC_SMTP_Manager::get_instance();
        $smtp_manager->render_smtp_settings();
    }

    /**
     * Construye el HTML final del email.
     * $opts = [
     *   'inline'       => bool (aplicar inliner),
     *   'preserve_css' => bool (dejar <style>),
     *   'reset_links'  => bool (normalizar <a> e <img> dentro de <a>)
     * ]
     */
    private function build_email_html( $raw_html, $recipient_email = null, array $opts = [] ){
        $defaults = [
            'inline'       => false,   // PREVIEW sin inliner
            'preserve_css' => true,    // conservar <style> en preview
            'reset_links'  => false    // no tocar links en preview
        ];
        $opts = array_merge($defaults, $opts);

        $html = $raw_html;

        // PRIMER PASO: Reemplazar placeholders ANTES de cualquier procesamiento
        if ( $recipient_email ) {
            $html = str_replace('[[UNSUB_URL]]', $this->get_unsub_url($recipient_email), $html);
        }

        // Para vista previa, forzar estilos críticos del botón
        if (!$opts['inline']) {
            $html = $this->force_button_visibility($html);
        } else if ($opts['preserve_css']) {
            // Para vista previa con inlining: combinar estilos críticos
            $html = $this->add_email_reset_styles($html);
            $html = $this->force_button_visibility($html);
        }

        // Para envíos reales (sin preserve_css), agregar estilos de reset antes del inlining
        if ( $opts['inline'] && !$opts['preserve_css'] ) {
            $html = $this->add_email_reset_styles($html);
        }

        if ( $opts['inline'] ) {
            // PASO 1: Convertir botones a 100% inline ANTES del CSS inliner
            $html = $this->enforce_button_styles_pre_inline($html);
            
            // PASO 2: Aplicar CSS inliner (ahora los botones son inline puros)
            $html = $this->inline_css_rules( $html, $opts['preserve_css'] );
            
            // PASO 3: Asegurar que elementos críticos estén inline después del CSS inlining
            $html = $this->ensure_critical_inline_styles($html);
        }

        if ( $opts['reset_links'] ) {
            $html = $this->enforce_global_link_reset($html);   // versión mejorada que conserva atributos
            $html = $this->enforce_link_styles($html);         // reglas especiales (DAMA/CABALLERO)
            $html = $this->enforce_button_styles($html);       // estilos críticos para botones
            $html = $this->enforce_navigation_styles($html);   // estilos críticos para navegación
        }

        return $this->wrap_email_html($html);
    }

    private function render_template_content( $tpl_id ){
        $template_manager = WEC_Template_Manager::get_instance();
        return $template_manager->render_template_content($tpl_id);
    }

    /*** CSS inliner + utilities ***/
    private function apply_descendant_inline($html, $class, $tag, $decl){
        if (trim($html) === '') return $html;
        
        // Preservar !important en las declaraciones
        if ($decl !== '' && substr($decl, -1) !== ';') $decl .= ';';

        // Usar DOMDocument si está disponible para mejor precisión
        if (class_exists('DOMDocument')) {
            return $this->apply_descendant_with_dom($html, $class, $tag, $decl);
        }

        // Fallback con regex mejorado
        return $this->apply_descendant_with_regex($html, $class, $tag, $decl);
    }

    private function apply_descendant_with_dom($html, $class, $tag, $decl) {
        $wrapped = false;
        if (!preg_match('/<html\b/i', $html)) {
            $html = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>';
            $wrapped = true;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        $xpath = new \DOMXPath($dom);
        $query = "//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')]//" . $tag;
        
        foreach ($xpath->query($query) as $node){
            $existing = $node->getAttribute('style');
            $combined = $existing . ($existing && substr($existing, -1) !== ';' ? ';' : '') . $decl;
            $node->setAttribute('style', $combined);
        }
        
        $output = $dom->saveHTML();
        if ($wrapped && preg_match('#<body[^>]*>(.*?)</body>#is', $output, $mm)) {
            $output = $mm[1];
        }
        return $output;
    }

    private function apply_descendant_with_regex($html, $class, $tag, $decl) {
        // Patrón mejorado para encontrar elementos dentro de contenedores con clase
        $pattern = '#(<[^>]*class=["\'][^"\']*\b' . preg_quote($class, '#') . '\b[^"\']*["\'][^>]*>)(.*?)(</' . preg_quote($tag, '#') . '[^>]*>)#is';
        
        return preg_replace_callback($pattern, function($matches) use ($tag, $decl) {
            $container = $matches[1];
            $content = $matches[2];
            $end_tag = $matches[3];
            
            // Buscar tags específicos dentro del contenido
            $tag_pattern = '#<' . preg_quote($tag, '#') . '\b([^>]*)>#i';
            $content = preg_replace_callback($tag_pattern, function($tag_match) use ($decl) {
                // Aplicar estilos inline directamente
                $element = $tag_match[0];
                $new_decl = trim($decl);
                if ($new_decl !== '' && substr($new_decl, -1) !== ';') $new_decl .= ';';
                
                if (preg_match('/\sstyle=(["\'])(.*?)\1/i', $element, $sm)){
                    $existing = trim($sm[2]);
                    $combined = $existing . ($existing && substr($existing, -1) !== ';' ? ';' : '') . $new_decl;
                    return preg_replace('/\sstyle=(["\'])(.*?)\1/i', ' style="'.$combined.'"', $element, 1);
                } else {
                    return preg_replace('/>/', ' style="'.esc_attr($new_decl).'">', $element, 1);
                }
            }, $content);
            
            return $container . $content . $end_tag;
        }, $html);
    }

    private function inline_css_rules( $html, $preserve_styles = false ){
        // Si no hay estilos, no hacer nada
        if ( ! preg_match_all( '#<style[^>]*>(.*?)</style>#is', $html, $m ) ) return $html;
        $css_all = implode("\n", $m[1]);

        if ( ! $preserve_styles ) {
            $html = preg_replace('#<style[^>]*>.*?</style>#is', '', $html);
        }

        // PASO 1: Aplicar estilos críticos para botones ANTES del procesamiento general
        $html = $this->apply_critical_button_styles_inline($html, $css_all);
        
        // PASO 2: Procesar solo reglas CSS simples y seguras
        if ( ! preg_match_all( '#([^{]+)\{([^}]+)\}#', $css_all, $rules, PREG_SET_ORDER ) ) return $html;

        foreach ( $rules as $rule ){
            $selector_raw = trim($rule[1]);
            $declarations = trim($rule[2]);

            // Procesar cada selector por separado
            $selectors = array_map('trim', explode(',', $selector_raw));
            foreach($selectors as $sel){
                if ($sel === '') continue;

                // Saltar media queries y pseudo-elementos no soportados
                if (strpos($sel, '@') !== false || strpos($sel, '::') !== false) continue;
                
                // Saltar selectores de botones (ya procesados en PASO 1)
                if (preg_match('/btn/i', $sel)) continue;
                
                // Manejar pseudo-clases de enlaces
                if (preg_match('/^a:(link|visited|hover|active|focus)$/i', $sel)) {
                    $html = $this->apply_link_pseudo_states($html, $declarations);
                    continue;
                }

                // Selectores descendientes con clase
                if (preg_match('/^\.([a-zA-Z0-9_-]+)\s+([a-zA-Z][a-zA-Z0-9_-]*)(?::(link|visited|hover|active|focus))?$/i', $sel, $mm)){
                    $html = $this->apply_descendant_inline($html, $mm[1], $mm[2], $declarations);
                    continue;
                }

                // Manejar específicamente selectores de navegación como ".nav-white a" y ".dark a"
                if (preg_match('/^\.([a-zA-Z0-9_-]+)\s+a$/i', $sel, $nav_match)) {
                    $class_name = $nav_match[1];
                    // Aplicar estilos críticos para navegación con !important
                    $nav_declarations = $declarations;
                    if (strpos($nav_declarations, 'color') !== false && strpos($nav_declarations, '!important') === false) {
                        $nav_declarations = preg_replace('/color\s*:\s*([^;]+);?/', 'color: $1 !important;', $nav_declarations);
                    }
                    if (strpos($nav_declarations, 'text-decoration') !== false && strpos($nav_declarations, '!important') === false) {
                        $nav_declarations = preg_replace('/text-decoration\s*:\s*([^;]+);?/', 'text-decoration: $1 !important;', $nav_declarations);
                    }
                    
                    $html = $this->apply_descendant_inline($html, $class_name, 'a', $nav_declarations);
                    continue;
                }

                // Selectores simples (tag, .class, #id) - SOLO SEGUROS
                if (preg_match('/^[a-zA-Z]+$/', $sel) || preg_match('/^\.[a-zA-Z0-9_-]+$/', $sel) || preg_match('/^#[a-zA-Z0-9_-]+$/', $sel)) {
                    $regex = $this->selector_to_xpath_like_regex($sel);
                    if ($regex) {
                        $html = preg_replace_callback($regex, function($m) use ($declarations){
                            return $this->merge_inline_styles($m[0], $declarations);
                        }, $html);
                    }
                }
            }
        }
        return $html;
    }

    /**
     * Aplica estilos críticos a botones de forma SEGURA
     * Evita romper la estructura HTML
     */
    private function apply_critical_button_styles_inline($html, $css_all) {
        // Extraer estilos de botones del CSS
        $button_styles = 'display:inline-block!important;visibility:visible!important;opacity:1!important;background-color:#D94949!important;color:#ffffff!important;padding:12px 22px!important;border-radius:8px!important;font-family:Arial,Helvetica,sans-serif!important;font-weight:700!important;font-size:16px!important;text-decoration:none!important;border:0!important;outline:none!important;text-align:center!important;';
        
        // Buscar y extraer estilos adicionales de .btn y .btn-red del CSS
        if (preg_match('/\.btn-red\s*\{([^}]+)\}/i', $css_all, $red_match)) {
            $additional_styles = trim($red_match[1]);
            if ($additional_styles) {
                $button_styles .= $additional_styles . ';';
            }
        }
        
        if (preg_match('/\.btn\s*\{([^}]+)\}/i', $css_all, $btn_match)) {
            $additional_styles = trim($btn_match[1]);
            if ($additional_styles) {
                $button_styles .= $additional_styles . ';';
            }
        }
        
        // Aplicar estilos SOLO a enlaces que tengan clases btn
        $html = preg_replace_callback(
            '#<a\b([^>]*\bclass=["\'][^"\']*\bbtn[^"\']*["\'][^>]*)>(.*?)</a>#is',
            function($m) use ($button_styles) {
                $full_opening_tag = $m[1];
                $content = $m[2];
                
                // Aplicar estilos inline de forma segura
                if (preg_match('/\sstyle=(["\'])(.*?)\1/i', $full_opening_tag, $sm)) {
                    $existing = trim($sm[2]);
                    $combined = $button_styles . ';' . $existing;
                    $full_opening_tag = preg_replace('/\sstyle=(["\'])(.*?)\1/i', ' style="' . esc_attr($combined) . '"', $full_opening_tag, 1);
                } else {
                    $full_opening_tag .= ' style="' . esc_attr($button_styles) . '"';
                }
                
                return '<a' . $full_opening_tag . '>' . $content . '</a>';
            },
            $html
        );
        
        return $html;
    }

    /**
     * Aplica estilos de pseudo-estados a todos los enlaces
     */
    private function apply_link_pseudo_states($html, $declarations) {
        return preg_replace_callback(
            '#<a\b([^>]*)>#i',
            function($m) use ($declarations){
                // Inline merge sin $this
                $element = $m[0];
                $new_decl = trim($declarations);
                if ($new_decl !== '' && substr($new_decl, -1) !== ';') $new_decl .= ';';
                
                if (preg_match('/\sstyle=("|\')(.*?)\1/i', $element, $sm)){
                    $existing = trim($sm[2]);
                    $combined = $existing . ($existing && substr($existing, -1) !== ';' ? ';' : '') . $new_decl;
                    return preg_replace('/\sstyle=("|\')(.*?)\1/i', ' style="'.$combined.'"', $element, 1);
                } else {
                    return preg_replace('/>/', ' style="'.esc_attr($new_decl).'">', $element, 1);
                }
            },
            $html
        );
    }

    /**
     * Combina estilos inline existentes con nuevos, preservando !important
     */
    private function merge_inline_styles($element, $new_declarations) {
        // Normalizar declaraciones (preservar !important)
        $new_decl = trim($new_declarations);
        if ($new_decl !== '' && substr($new_decl, -1) !== ';') $new_decl .= ';';
        
        if (preg_match('/\sstyle=("|\')(.*?)\1/i', $element, $sm)){
            $existing = trim($sm[2]);
            $combined = $existing . ($existing && substr($existing, -1) !== ';' ? ';' : '') . $new_decl;
            return preg_replace('/\sstyle=("|\')(.*?)\1/i', ' style="'.$combined.'"', $element, 1);
        } else {
            return preg_replace('/>/', ' style="'.esc_attr($new_decl).'">', $element, 1);
        }
    }

    private function selector_to_xpath_like_regex($sel){
        $sel = trim($sel);
        $tag = '[a-zA-Z][a-zA-Z0-9]*';
        $class = '';
        $id = '';
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9]*)/', $sel, $m)) { $tag = $m[1]; }
        if (preg_match('/\.([a-zA-Z0-9_-]+)/', $sel, $m)) { $class = $m[1]; }
        if (preg_match('/#([a-zA-Z0-9_-]+)/', $sel, $m)) { $id = $m[1]; }

        $re = '<' . $tag . '\b(?=[^>]*?)';
        if ($id)    $re .= '(?=[^>]*\bid=["\']'.$id.'["\'])';
        if ($class) $re .= '(?=[^>]*\bclass=["\'][^"\']*\b'.$class.'\b[^"\']*["\'])';
        $re .= '[^>]*?>';
        return '#'.$re.'#i';
    }

    /**
     * Añade estilos CSS globales para mejorar compatibilidad con clientes de email
     */
    private function add_email_reset_styles($html) {
        $reset_css = '
        <style type="text/css">
            /* Reset básico para clientes de email */
            body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
            table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
            img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
            
            /* Reset de enlaces para todos los estados */
            a, a:link, a:visited, a:hover, a:active, a:focus {
                color: inherit !important;
                text-decoration: none !important;
                border: 0 !important;
                outline: none !important;
            }
            
            /* Específicos para elementos anidados en enlaces */
            a font, a span, a strong, a b, a em, a i {
                color: inherit !important;
                text-decoration: inherit !important;
            }
            
            /* Forzar visibilidad de botones críticos */
            .btn, .btn-red, .button {
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
            }
            
            /* Navegación específica */
            .nav-white a, .dark a {
                color: #ffffff !important;
                text-decoration: none !important;
                font-family: Arial, Helvetica, sans-serif !important;
            }
            
            /* Outlook específico */
            .ExternalClass { width: 100%; }
            .ExternalClass, .ExternalClass p, .ExternalClass span, .ExternalClass font, .ExternalClass td, .ExternalClass div {
                line-height: 100%;
            }
            
            /* Apple Mail específico */
            @media only screen and (max-width: 480px) {
                .mobile-hide { display: none !important; }
                .mobile-center { text-align: center !important; }
            }
            
            /* Asegurar que las clases de color funcionen */
            .text-white { color: #ffffff !important; }
            .text-muted { color: #d6d6d6 !important; }
            .gold { color: #D4AF37 !important; }
            .bg-dark { background-color: #000000 !important; }
        </style>';
        
        if (preg_match('/<head[^>]*>/i', $html)) {
            return preg_replace('/<head[^>]*>/i', '$0' . $reset_css, $html, 1);
        } else {
            return $reset_css . $html;
        }
    }

    private function enforce_link_styles($html){
        // PASO 1A: Centrar contenedores TD que contengan navegación en header (CABALLERO + DAMA)
        $html = preg_replace_callback(
            '#<td\b([^>]*?)>(.*?CABALLERO.*?DAMA.*?)</td>#is',
            function($m) {
                $attrs = $m[1];
                $content = $m[2];
                
                // Forzar centrado AGRESIVO eliminando estilos conflictivos
                $center_styles = 'text-align:center!important;margin:0 auto!important;width:100%!important;';
                
                // Limpiar estilos existentes que puedan interferir
                $attrs = preg_replace('/\sstyle=(["\'])[^"\']*\1/i', '', $attrs);
                $attrs .= ' style="' . esc_attr($center_styles) . '"';
                
                // También forzar centrado en los spans internos
                $content = preg_replace_callback(
                    '#<span\b([^>]*?)>(.*?)</span>#is',
                    function($span_match) {
                        $span_attrs = $span_match[1];
                        $span_content = $span_match[2];
                        
                        // Agregar estilos de centrado a spans
                        $span_center = 'display:inline-block!important;text-align:center!important;margin:0 12px!important;';
                        
                        if (preg_match('/\sstyle=(["\'])(.*?)\1/i', $span_attrs, $span_sm)) {
                            $span_existing = trim($span_sm[2]);
                            $span_combined = $span_existing . (substr($span_existing, -1) !== ';' ? ';' : '') . $span_center;
                            $span_attrs = preg_replace('/\sstyle=(["\'])(.*?)\1/i', ' style="' . esc_attr($span_combined) . '"', $span_attrs, 1);
                        } else {
                            $span_attrs .= ' style="' . esc_attr($span_center) . '"';
                        }
                        
                        return '<span' . $span_attrs . '>' . $span_content . '</span>';
                    },
                    $content
                );
                
                return '<td' . $attrs . '>' . $content . '</td>';
            },
            $html
        );
        
        // PASO 1B: Centrar contenedores TD que contengan las categorías (CABALLERO + DAMA + CAJAS)
        $html = preg_replace_callback(
            '#<td\b([^>]*?)>(.*?CABALLERO.*?DAMA.*?CAJAS.*?)</td>#is',
            function($m) {
                $attrs = $m[1];
                $content = $m[2];
                
                // Forzar centrado AGRESIVO para sección de categorías
                $center_styles = 'text-align:center!important;margin:0 auto!important;width:100%!important;padding:16px 24px!important;';
                
                // Limpiar estilos existentes que puedan interferir
                $attrs = preg_replace('/\sstyle=(["\'])[^"\']*\1/i', '', $attrs);
                $attrs .= ' style="' . esc_attr($center_styles) . '"';
                
                // También forzar centrado en todos los enlaces dentro
                $content = preg_replace_callback(
                    '#<a\b([^>]*?)>(.*?)</a>#is',
                    function($link_match) {
                        $link_attrs = $link_match[1];
                        $link_content = $link_match[2];
                        
                        // Centrar enlaces de categorías
                        $link_center = 'display:block!important;text-align:center!important;margin:6px auto!important;width:100%!important;';
                        
                        if (preg_match('/\sstyle=(["\'])(.*?)\1/i', $link_attrs, $link_sm)) {
                            $link_existing = trim($link_sm[2]);
                            $link_combined = $link_existing . (substr($link_existing, -1) !== ';' ? ';' : '') . $link_center;
                            $link_attrs = preg_replace('/\sstyle=(["\'])(.*?)\1/i', ' style="' . esc_attr($link_combined) . '"', $link_attrs, 1);
                        } else {
                            $link_attrs .= ' style="' . esc_attr($link_center) . '"';
                        }
                        
                        return '<a' . $link_attrs . '>' . $link_content . '</a>';
                    },
                    $content
                );
                
                return '<td' . $attrs . '>' . $content . '</td>';
            },
            $html
        );
        
        // PASO 2: Estilos para enlaces específicos (DAMA/CABALLERO/CAJAS)
        $nav_style = 'color:#ffffff!important;text-decoration:none!important;border:0!important;outline:none!important;font-family:Arial,Helvetica,sans-serif!important;';
        
        $html = preg_replace_callback(
            '#<a\b([^>]*)>(.*?)</a>#is',
            function($m) use ($nav_style){
                $attrs = $m[1];
                $content = $m[2];
                
                // Si contiene DAMA, CABALLERO o CAJAS, aplicar estilos de navegación
                if (stripos($content, 'DAMA') !== false || stripos($content, 'CABALLERO') !== false || stripos($content, 'CAJAS') !== false) {
                    // Aplicar estilos inline críticos (con prioridad)
                    if (preg_match('/\sstyle=("|\')(.*?)\1/i', $attrs, $sm)){
                        $combined = $nav_style . ';' . trim($sm[2]);
                        $attrs = preg_replace('/\sstyle=("|\')(.*?)\1/i', ' style="'.$combined.'"', $attrs, 1);
                    } else {
                        $attrs .= ' style="'.esc_attr($nav_style).'"';
                    }
                }
                
                return '<a' . $attrs . '>' . $content . '</a>';
            },
            $html
        );
        
        return $html;
    }

    /**
     * Fuerza la visibilidad del botón en vista previa
     */
    private function force_button_visibility($html) {
        // Agregar estilos críticos adicionales para vista previa
        $critical_styles = '
        <style type="text/css">
            /* Forzar visibilidad del botón en vista previa */
            .btn, .btn-red, a.btn, a.btn-red {
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
                cursor: pointer !important;
                -webkit-appearance: none !important;
                -moz-appearance: none !important;
                appearance: none !important;
            }
            
            /* Asegurar que el contenedor del botón también sea visible */
            .center, .text-center, [style*="text-align:center"] {
                text-align: center !important;
            }
            
            /* Reset para elementos que puedan estar ocultos */
            table, tr, td {
                display: table !important;
                visibility: visible !important;
            }
            
            tr {
                display: table-row !important;
            }
            
            td {
                display: table-cell !important;
            }
        </style>';
        
        // Insertar los estilos críticos después de los estilos existentes
        if (preg_match('/<\/style>/i', $html)) {
            $html = preg_replace('/<\/style>/i', '</style>' . $critical_styles, $html, 1);
        } else if (preg_match('/<\/head>/i', $html)) {
            $html = preg_replace('/<\/head>/i', $critical_styles . '</head>', $html, 1);
        } else {
            $html = $critical_styles . $html;
        }
        
        return $html;
    }

    /**
     * Asegura que elementos críticos tengan estilos inline después del CSS inlining
     */
    private function ensure_critical_inline_styles($html) {
        // PASO 1: Forzar centrado de elementos con clase "center" Y sus botones descendientes
        $html = preg_replace_callback(
            '#<(td|div|table)\b([^>]*\bclass=["\'][^"\']*\bcenter\b[^"\']*["\'][^>]*?)>(.*?)</\1>#is',
            function($m) {
                $tag = $m[1];
                $attrs = $m[2];
                $content = $m[3];
                
                // Forzar text-align:center en el contenedor
                $center_styles = 'text-align:center!important;';
                
                if (preg_match('/\sstyle=(["\'])(.*?)\1/i', $attrs, $sm)) {
                    $existing = trim($sm[2]);
                    $combined = $existing . (substr($existing, -1) !== ';' ? ';' : '') . $center_styles;
                    $attrs = preg_replace('/\sstyle=(["\'])(.*?)\1/i', ' style="' . esc_attr($combined) . '"', $attrs, 1);
                } else {
                    $attrs .= ' style="' . esc_attr($center_styles) . '"';
                }
                
                // TAMBIÉN centrar cualquier botón dentro de este contenedor
                $content = preg_replace_callback(
                    '/<a\b([^>]*?)>(.*?Comprar.*?)<\/a>/is',
                    function($btn_match) {
                        $btn_attrs = $btn_match[1];
                        $btn_content = $btn_match[2];
                        
                        // SOLO aplicar si NO tiene display:block (evitar conflictos)
                        if (!preg_match('/display:\s*block\s*!important/i', $btn_attrs)) {
                            // Estilos de centrado para el botón
                            $btn_center_styles = 'display:block!important;margin:0 auto!important;text-align:center!important;width:auto!important;max-width:200px!important;';
                            
                            if (preg_match('/\sstyle=(["\'])(.*?)\1/i', $btn_attrs, $btn_sm)) {
                                $btn_existing = trim($btn_sm[2]);
                                $btn_combined = $btn_existing . (substr($btn_existing, -1) !== ';' ? ';' : '') . $btn_center_styles;
                                $btn_attrs = preg_replace('/\sstyle=(["\'])(.*?)\1/i', ' style="' . esc_attr($btn_combined) . '"', $btn_attrs, 1);
                            } else {
                                $btn_attrs .= ' style="' . esc_attr($btn_center_styles) . '"';
                            }
                        }
                        
                        return '<a' . $btn_attrs . '>' . $btn_content . '</a>';
                    },
                    $content
                );
                
                return '<' . $tag . $attrs . '>' . $content . '</' . $tag . '>';
            },
            $html
        );
        
        // PASO 2: Forzar estilos de navegación con texto blanco  
        $html = preg_replace_callback(
            '#<(span|a)\b([^>]*\bclass=["\'][^"\']*\b(nav|text-white)\b[^"\']*["\'][^>]*)>#i',
            function($m) {
                $tag = $m[1];
                $attrs = $m[2];
                
                $nav_styles = 'color:#ffffff!important;font-family:Arial,Helvetica,sans-serif!important;text-decoration:none!important;display:inline-block!important;';
                
                if (preg_match('/\sstyle=(["\'])(.*?)\1/i', $attrs, $sm)) {
                    $existing = trim($sm[2]);
                    $combined = $existing . (substr($existing, -1) !== ';' ? ';' : '') . $nav_styles;
                    $attrs = preg_replace('/\sstyle=(["\'])(.*?)\1/i', ' style="' . esc_attr($combined) . '"', $attrs, 1);
                } else {
                    $attrs .= ' style="' . esc_attr($nav_styles) . '"';
                }
                
                return '<' . $tag . $attrs . '>';
            },
            $html
        );
        
        // PASO 3: Centrado adicional para cualquier botón Comprar que haya quedado sin centrar
        $html = preg_replace_callback(
            '/<a\b([^>]*?)>(.*?Comprar.*?)<\/a>/is',
            function($m) {
                $attrs = $m[1];
                $content = $m[2];
                
                // Solo aplicar si aún no tiene estilos de centrado Y no tiene display:block
                if (!preg_match('/margin:\s*0\s+auto/i', $attrs) && 
                    !preg_match('/display:\s*block\s*!important/i', $attrs) &&
                    !preg_match('/display:\s*inline-block.*display:\s*block/i', $attrs)) {
                    
                    $center_styles = 'display:block!important;margin:0 auto!important;text-align:center!important;width:auto!important;max-width:200px!important;';
                    
                    if (preg_match('/\sstyle=(["\'])(.*?)\1/i', $attrs, $sm)) {
                        $existing = trim($sm[2]);
                        $combined = $existing . (substr($existing, -1) !== ';' ? ';' : '') . $center_styles;
                        $attrs = preg_replace('/\sstyle=(["\'])(.*?)\1/i', ' style="' . esc_attr($combined) . '"', $attrs, 1);
                    } else {
                        $attrs .= ' style="' . esc_attr($center_styles) . '"';
                    }
                }
                
                return '<a' . $attrs . '>' . $content . '</a>';
            },
            $html
        );
        
        return $html;
    }

    /**
     * Aplica estilos críticos a botones ANTES del CSS inlining
     */
    private function enforce_button_styles_pre_inline($html) {
        // Estilos críticos para botones que deben aplicarse antes del inlining
        $button_styles = 'display:inline-block!important;visibility:visible!important;opacity:1!important;background-color:#D94949!important;color:#ffffff!important;padding:12px 22px!important;border-radius:8px!important;font-family:Arial,Helvetica,sans-serif!important;font-weight:700!important;font-size:16px!important;text-decoration:none!important;border:0!important;outline:none!important;text-align:center!important;';
        
        // Aplicar a todos los elementos con clase btn
        $html = preg_replace_callback(
            '#<a\b([^>]*\bclass=["\'][^"\']*\bbtn[^"\']*["\'][^>]*)>(.*?)</a>#is',
            function($m) use ($button_styles) {
                $attrs = $m[1];
                $content = $m[2];
                
                if (preg_match('/\sstyle=(["\'])(.*?)\1/i', $attrs, $sm)) {
                    $existing = trim($sm[2]);
                    $combined = $button_styles . ($existing ? ';' . $existing : '');
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
     * Aplica estilos críticos a botones DESPUÉS del CSS inlining
     */
    private function enforce_button_styles($html) {
        // Forzar estilos de botones que pueden haberse perdido
        $button_styles = 'display:inline-block!important;visibility:visible!important;opacity:1!important;background-color:#D94949!important;color:#ffffff!important;text-decoration:none!important;';
        
        $html = preg_replace_callback(
            '#<a\b([^>]*\bclass=["\'][^"\']*\bbtn[^"\']*["\'][^>]*)>(.*?)</a>#is',
            function($m) use ($button_styles) {
                $attrs = $m[1];
                $content = $m[2];
                
                if (preg_match('/\sstyle=(["\'])(.*?)\1/i', $attrs, $sm)) {
                    $existing = trim($sm[2]);
                    $combined = $existing . ';' . $button_styles;
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
     * Aplica estilos críticos para navegación
     */
    private function enforce_navigation_styles($html) {
        // Estilos para elementos de navegación
        $nav_styles = 'color:#ffffff!important;text-decoration:none!important;font-family:Arial,Helvetica,sans-serif!important;';
        
        $html = preg_replace_callback(
            '#<a\b([^>]*\bclass=["\'][^"\']*\b(nav|navigation)[^"\']*["\'][^>]*)>(.*?)</a>#is',
            function($m) use ($nav_styles) {
                $attrs = $m[1];
                $content = $m[2];
                
                if (preg_match('/\sstyle=(["\'])(.*?)\1/i', $attrs, $sm)) {
                    $existing = trim($sm[2]);
                    $combined = $existing . ';' . $nav_styles;
                    $attrs = preg_replace('/\sstyle=(["\'])(.*?)\1/i', ' style="' . esc_attr($combined) . '"', $attrs, 1);
                } else {
                    $attrs .= ' style="' . esc_attr($nav_styles) . '"';
                }
                
                return '<a' . $attrs . '>' . $content . '</a>';
            },
            $html
        );
        
        return $html;
    }

    /**
     * Reset completo para enlaces y estados
     * - Conserva TODOS los atributos del enlace (href, target, rel, data-*)
     * - Fuerza todos los estados de enlaces (a, a:link, a:visited, a:hover, a:active)
     * - Aplica estilos inline completos para máxima compatibilidad
     */
    private function enforce_global_link_reset($html){
        // 1) Normalizar <a ...> con todos los estados y atributos preservados
        $html = preg_replace_callback(
            '#<a\b([^>]*)>#i',
            function($m){
                $attrs = $m[1];
                // Estilos base que cubren todos los estados problemáticos
                $base_styles = 'text-decoration:none!important;border:0!important;outline:none!important;';
                
                if (preg_match('/\sstyle=("|\')(.*?)\1/i', $attrs, $sm)) {
                    $existing_style = trim($sm[2]);
                    // Solo agregar color si no existe ya
                    if (!preg_match('/(^|;)\s*color\s*:/i', $existing_style)) {
                        $existing_style .= ($existing_style && substr($existing_style, -1) !== ';' ? ';' : '') . 'color:inherit!important;';
                    }
                    $final_style = $existing_style . ($existing_style && substr($existing_style, -1) !== ';' ? ';' : '') . $base_styles;
                    $attrs = preg_replace('/\sstyle=("|\')(.*?)\1/i', ' style="'.$final_style.'"', $attrs, 1);
                } else {
                    $attrs .= ' style="color:inherit!important;'.$base_styles.'"';
                }
                return '<a' . $attrs . '>';
            },
            $html
        );

        // 2) Normalizar <img> dentro de enlaces sin perder estructura
        $html = preg_replace_callback(
            '#(<a\b[^>]*>)(.*?)(</a>)#is',
            function($m){
                $open_tag = $m[1];
                $inner_content = $m[2];
                $close_tag = $m[3];

                // Procesar imágenes dentro del enlace
                $inner_content = preg_replace_callback(
                    '#<img\b([^>]*)>#i',
                    function($img_match){
                        $img_attrs = $img_match[1];
                        $img_styles = 'display:block!important;border:0!important;outline:none!important;text-decoration:none!important;';
                        
                        if (preg_match('/\sstyle=("|\')(.*?)\1/i', $img_attrs)) {
                            $img_attrs = preg_replace('/\sstyle=("|\')(.*?)\1/i', ' style="$2 '.$img_styles.'"', $img_attrs, 1);
                        } else {
                            $img_attrs .= ' style="'.esc_attr($img_styles).'"';
                        }
                        
                        // Agregar border="0" si no existe
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

    private function wrap_email_html( $body_html ) {
        if ( preg_match('/<html\b/i', $body_html) ) {
            // Si ya es HTML completo, agregar elementos faltantes
            if ( preg_match('/<head\b[^>]*>/i', $body_html) && ! preg_match('/<base\b/i', $body_html) ) {
                $base = '<base href="' . esc_url( home_url('/') ) . '">';
                $body_html = preg_replace('/<head\b[^>]*>/i', '$0' . $base, $body_html, 1);
            }
            
            // Agregar atributos legacy para clientes antiguos si no existen
            if (!preg_match('/\blink\s*=/i', $body_html)) {
                $body_html = preg_replace('/<body\b([^>]*)>/i', '<body$1 link="#000000" vlink="#000000" alink="#000000">', $body_html);
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
              . '<base href="' . esc_url( home_url('/') ) . '">'
              . '<!--[if gte mso 9]><xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->'
              . '<style type="text/css">'
              . '/* Estilos críticos inline para máxima compatibilidad */'
              . '.btn, .btn-red { display: inline-block !important; visibility: visible !important; background-color: #D94949 !important; color: #ffffff !important; }'
              . 'a[class*="btn"] { display: inline-block !important; visibility: visible !important; }'
              . '</style>';
        
        $body_attrs = 'style="margin:0;padding:0;background-color:#ffffff;" '
                    . 'link="#000000" vlink="#000000" alink="#000000" '
                    . 'bgcolor="#ffffff"';
        
        return '<!doctype html>'
             . '<html lang="es" xmlns="http://www.w3.org/1999/xhtml" xmlns:o="urn:schemas-microsoft-com:office:office">'
             . '<head>' . $head . '</head>'
             . '<body ' . $body_attrs . '>' . $body_html . '</body>'
             . '</html>';
    }

    /*** Unsubscribe ***/
    private function get_unsub_url($email) {
        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE_SUBSCRIBERS;
        $email = strtolower(trim($email));
        if (empty($email)) return '#';

        $token = $wpdb->get_var($wpdb->prepare("SELECT unsub_token FROM {$table} WHERE email=%s", $email));
        if (!$token) {
            $token = bin2hex(random_bytes(16));
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (email, unsub_token) VALUES (%s,%s)
                 ON DUPLICATE KEY UPDATE unsub_token=VALUES(unsub_token)",
                $email, $token
            ));
        }
        return home_url('/unsubscribe/?e=' . rawurlencode($email) . '&t=' . $token);
    }

    /*** Preview (AJAX -> iframe HTML) ***/
    public function ajax_preview_iframe_html(){
        if ( ! current_user_can('edit_posts') ) wp_die('No autorizado', 403);
        $tpl_id = intval($_GET['tpl_id'] ?? 0);
        $nonce  = $_GET['wec_nonce'] ?? '';
        if ( ! $tpl_id ) wp_die('ID inválido', 400);
        if ( empty($nonce) || ! wp_verify_nonce( $nonce, 'wec_prev_iframe') ) wp_die('Nonce inválido', 403);

        $template_result = $this->render_template_content($tpl_id);
        if (is_wp_error($template_result)) {
            status_header(500);
            echo '<div style="background:#ff0000;color:#fff;padding:20px;font-family:monospace;">';
            echo '<h2>ERROR EN VISTA PREVIA:</h2>';
            echo '<pre>'.esc_html($template_result->get_error_message()).'</pre>';
            echo '</div>';
            exit;
        }

        try{
            list($subject,$html) = $template_result;

            // PREVIEW: SIN inlining - conservar HTML intacto para vista previa
            $full = $this->build_email_html(
                $html,
                null,
                [
                    'inline'        => false,   // ¡DESACTIVAR! El inliner rompe el HTML
                    'preserve_css'  => true,    // Conservar estilos CSS originales
                    'reset_links'   => false    // No aplicar resets que rompen estructura
                ]
            );

            // Agregar indicador sutil de que es vista previa
            $preview_indicator = '<div style="position:fixed;bottom:10px;left:10px;background:rgba(0,0,0,0.7);color:#fff;padding:5px 10px;border-radius:3px;font-size:11px;z-index:9999;">Vista Previa Optimizada</div>';
            if (preg_match('/<body[^>]*>/i', $full, $matches)) {
                $full = str_replace($matches[0], $matches[0] . $preview_indicator, $full);
            }

            nocache_headers();
            status_header(200);
            header('Content-Type: text/html; charset=UTF-8');
            echo $full;
            
        }catch(\Throwable $e){
            status_header(500);
            echo '<div style="background:#ff0000;color:#fff;padding:20px;font-family:monospace;">';
            echo '<h2>ERROR EN VISTA PREVIA:</h2>';
            echo '<pre>'.esc_html($e->getMessage()).'</pre>';
            echo '</div>';
        }
        exit;
    }

    /*** Preview (admin-post iframe fallback) ***/
    public function handle_preview_iframe(){
        $tpl_id = intval($_GET['tpl_id'] ?? 0);
        if ( ! $tpl_id ) wp_die('ID inválido', 400);
        $nonce = $_GET['wec_nonce'] ?? ($_GET['_wpnonce'] ?? '');
        if ( empty($nonce) || ! wp_verify_nonce( $nonce, 'wec_prev_iframe') ) {
            if ( ! current_user_can('edit_post', $tpl_id) ) {
                wp_die('Nonce inválido', 403);
            }
        }
        if ( ! current_user_can('edit_post', $tpl_id) ) wp_die('No autorizado', 403);

        $template_result = $this->render_template_content($tpl_id);
        if (is_wp_error($template_result)) {
            wp_die('Error en vista previa: '.$template_result->get_error_message(), 500);
        }

        try{
            list($subject,$html) = $template_result;

            // PREVIEW HÍBRIDO - inlining agresivo pero conservando estilos para legibilidad
            $full = $this->build_email_html(
                $html,
                null,
                [
                    'inline'        => true,    // Aplicar inlining para ser como Gmail
                    'preserve_css'  => true,    // Conservar estilos para vista previa legible
                    'reset_links'   => true     // Aplicar resets como en envío real
                ]
            );

            header('Content-Type: text/html; charset=UTF-8');
            echo $full;
        }catch(\Throwable $e){
            wp_die('Error en vista previa: '.$e->getMessage(), 500);
        }
        exit;
    }

    /*** AJAX: JSON preview (opcional) ***/
    public function ajax_preview_template(){
        check_ajax_referer( self::AJAX_NONCE );
        $tpl_id = intval($_POST['tpl_id'] ?? 0);
        if( ! $tpl_id ) wp_send_json_error('ID inválido',400);
        if( ! current_user_can('edit_post', $tpl_id) ) wp_send_json_error('No autorizado',403);
        $template_result = $this->render_template_content($tpl_id);
        if (is_wp_error($template_result)) {
            wp_send_json_error($template_result->get_error_message(), 500);
        }
        
        try {
            list($subject,$html) = $template_result;
            $full = $this->build_email_html(
                $html,
                null,
                [
                    'inline'        => true,    // Aplicar inlining para ser como Gmail
                    'preserve_css'  => true,    // Conservar estilos para vista previa legible
                    'reset_links'   => true     // Aplicar resets como en envío real
                ]
            );
            wp_send_json_success([ 'subject'=>$subject, 'html_full'=>$full ]);
        } catch(\Throwable $e){
            wp_send_json_error($e->getMessage(),500);
        }
    }

    /*** Render modal HTML for preview ***/
    private function render_preview_modal_html() {
        return '
        <div id="wec-preview-modal" style="display:none;">
            <div id="wec-preview-wrap">
                <div class="wec-toolbar">
                    <span id="wec-preview-subject">Vista previa del email</span>
                    <div class="sep"></div>
                    <button type="button" class="button" data-wec-size="mobile">📱 Móvil 360</button>
                    <button type="button" class="button" data-wec-size="tablet">📟 Tablet 600</button>
                    <button type="button" class="button" data-wec-size="desktop">💻 Desktop 800</button>
                    <button type="button" class="button" data-wec-size="full">🖥️ Ancho libre</button>
                </div>
                <div class="wec-canvas">
                    <div class="wec-frame-wrap" id="wec-frame-wrap">
                        <div class="wec-frame-info" id="wec-frame-info">Vista previa del template</div>
                        <iframe id="wec-preview-iframe" src="about:blank"></iframe>
                    </div>
                </div>
            </div>
        </div>';
    }

    /*** DB Install & columns ***/
    public function maybe_install_tables(){
        // Llamar a la función global silenciosa
        wec_install_tables();
    }

    public function maybe_migrate_once(){
        if ( defined('DOING_AJAX') && DOING_AJAX ) return;
        $stored = get_option('wec_db_ver', '');
        if ( $stored === self::DB_VER ) return;
        $this->maybe_install_tables();
        update_option('wec_db_ver', self::DB_VER);
    }

    public function maybe_add_columns(){
        global $wpdb;
        $table_jobs  = $wpdb->prefix . self::DB_TABLE_JOBS;
        $col = $wpdb->get_results( $wpdb->prepare("SHOW COLUMNS FROM {$table_jobs} LIKE %s", 'rate_per_minute' ) );
        if( empty($col) ){
            $wpdb->query("ALTER TABLE {$table_jobs} ADD COLUMN rate_per_minute INT UNSIGNED NOT NULL DEFAULT 100");
        }
    }

    // ...existing code...
}

/* ---------- Página de baja de suscripción ---------- */
add_shortcode('email_unsubscribe', function() {
    if (empty($_GET['e']) || empty($_GET['t'])) return '<p>Solicitud inválida.</p>';
    $email = sanitize_email($_GET['e']);
    $token = sanitize_text_field($_GET['t']);
    if (!is_email($email)) return '<p>Correo inválido.</p>';

    global $wpdb;
    $table = $wpdb->prefix . 'wec_subscribers';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE email=%s AND unsub_token=%s",
        $email, $token
    ));

    if (!$row) return '<p>Enlace inválido o ya utilizado.</p>';

    $wpdb->update($table, ['status' => 'unsubscribed'], ['email' => $email]);

    return '<h2>Has cancelado tu suscripción a los correos de Drexora.</h2>
            <p>Puedes volver a unirte cuando quieras 🕒</p>';
});

endif; // class_exists('WEC_Email_Collector')

/*** Inicializar el plugin ***/
function wec_init_plugin() {
    new WEC_Email_Collector();
}
add_action('plugins_loaded', 'wec_init_plugin');

/*** Función para instalación silenciosa ***/
function wec_install_tables() {
    if (!class_exists('WEC_Email_Collector')) return;
    
    // Capturar cualquier output inesperado
    ob_start();
    
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    
    // Tabla jobs
    $table_jobs = $wpdb->prefix . 'wec_jobs';
    $sql1 = "CREATE TABLE {$table_jobs} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tpl_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        start_at DATETIME NOT NULL,
        total INT UNSIGNED NOT NULL DEFAULT 0,
        sent INT UNSIGNED NOT NULL DEFAULT 0,
        failed INT UNSIGNED NOT NULL DEFAULT 0,
        rate_per_minute INT UNSIGNED NOT NULL DEFAULT 100,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY tpl_id (tpl_id),
        KEY status (status),
        KEY start_at (start_at)
    ) {$charset};";
    
    // Tabla items
    $table_items = $wpdb->prefix . 'wec_job_items';
    $sql2 = "CREATE TABLE {$table_items} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        job_id BIGINT UNSIGNED NOT NULL,
        email VARCHAR(190) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'queued',
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        error TEXT NULL,
        PRIMARY KEY (id),
        KEY job_id (job_id),
        KEY status (status),
        KEY email (email)
    ) {$charset};";
    
    // Para la tabla subscribers, ser MUY conservador para evitar errores
    $table_subs = $wpdb->prefix . 'wec_subscribers';
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_subs)) == $table_subs;
    
    if (!$table_exists) {
        // Solo crear si no existe absolutamente
        $result = $wpdb->query("CREATE TABLE IF NOT EXISTS {$table_subs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(190) NOT NULL,
            status ENUM('subscribed','unsubscribed') NOT NULL DEFAULT 'subscribed',
            unsub_token VARCHAR(64) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_email (email)
        ) {$charset}");
        if ($result === false) {
            // Si falla la creación, continuar sin mostrar error en producción
            // La tabla se creará en un intento posterior
        }
    } else {
        // Si la tabla existe, verificar que el índice único exista y no esté duplicado
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table_subs} WHERE Column_name = 'email'");
        $unique_exists = false;
        foreach ($indexes as $idx) {
            if ($idx->Non_unique == 0) {
                $unique_exists = true;
                break;
            }
        }
        if (!$unique_exists) {
            // Si no existe un índice único, crearlo
            $wpdb->query("ALTER TABLE {$table_subs} ADD UNIQUE KEY uniq_email (email)");
        }
    }
    // Si la tabla existe, NO TOCAR NADA más para evitar problemas de índices
    
    // Ejecutar las otras tablas sin output
    @dbDelta($sql1);
    @dbDelta($sql2);
    
    // Programar cron si no existe
    if (!wp_next_scheduled('wec_process_queue')) {
        wp_schedule_event(time(), 'every_five_minutes', 'wec_process_queue');
    }
    
    // Marcar versión
    update_option('wec_db_ver', '3');
    
    // Limpiar cualquier output
    ob_end_clean();
}

/*** Hooks de activación/desactivación ***/
register_activation_hook(__FILE__, 'wec_install_tables');

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('wec_process_queue');
});

/*** Función alternativa para casos problemáticos - USAR CON CUIDADO ***/
function wec_force_recreate_subscribers_table() {
/**
 * Función de reparación para limpiar índices duplicados en la tabla de suscriptores.
 * Elimina todos los índices en 'email' excepto uno único.
 * Puede ejecutarse manualmente desde WP-CLI o añadiendo un botón temporal en el admin.
 */
function wec_repair_subscribers_indexes() {
    global $wpdb;
    $table_subs = $wpdb->prefix . 'wec_subscribers';
    $indexes = $wpdb->get_results("SHOW INDEX FROM {$table_subs} WHERE Column_name = 'email'");
    $unique_found = false;
    $to_drop = [];
    foreach ($indexes as $idx) {
        // Si ya hay un índice único, marcar los demás para eliminar
        if ($idx->Non_unique == 0 && !$unique_found) {
            $unique_found = true;
        } else {
            $to_drop[] = $idx->Key_name;
        }
    }
    // Eliminar los índices sobrantes
    foreach ($to_drop as $key_name) {
        $wpdb->query("ALTER TABLE {$table_subs} DROP INDEX `{$key_name}`");
    }
    // Si no había ningún índice único, crear uno
    if (!$unique_found) {
        $wpdb->query("ALTER TABLE {$table_subs} ADD UNIQUE KEY uniq_email (email)");
    }
}
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table_subs = $wpdb->prefix . 'wec_subscribers';
    
    // ADVERTENCIA: Esto eliminará todos los datos de suscriptores
    $wpdb->query("DROP TABLE IF EXISTS {$table_subs}");
    
    // Crear tabla limpia
    $wpdb->query("CREATE TABLE {$table_subs} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(190) NOT NULL,
        status ENUM('subscribed','unsubscribed') NOT NULL DEFAULT 'subscribed',
        unsub_token VARCHAR(64) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_email (email)
    ) {$charset}");
}

/*** Intervalos de cron personalizados ***/
add_filter('cron_schedules', function($schedules) {
    $schedules['every_five_minutes'] = [
        'interval' => 5 * MINUTE_IN_SECONDS,
        'display' => __('Cada 5 minutos')
    ];
    return $schedules;
});

<?php
/**
 * Plugin Name: WP Email Collector
 * Description: Gestiona plantillas de email, campañas con cola y vista previa. Incluye SMTP, WP-Cron, Unsubscribe y CSS Inliner para vista previa/envíos.
 * Version:     2.5.1-hotfix
 * Author:      Curren México
 * License:     GPLv2 or later
 * Text Domain: wp-email-collector
 */

if ( ! defined( 'ABSPATH' ) ) exit;

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

    const OPT_SMTP                   = 'wec_smtp_settings';

    const CRON_HOOK                  = 'wec_process_queue';
    const DB_TABLE_JOBS              = 'wec_jobs';
    const DB_TABLE_ITEMS             = 'wec_job_items';
    const DB_TABLE_SUBSCRIBERS       = 'wec_subscribers';

    const SEND_TEST_ACTION           = 'wec_send_test';
    const ADMIN_POST_CAMPAIGN_CREATE = 'wec_create_campaign';
    const ADMIN_POST_CAMPAIGN_UPDATE = 'wec_update_campaign';
    const ADMIN_POST_CAMPAIGN_DELETE = 'wec_delete_campaign';

    /*** Bootstrap ***/
    public function __construct() {
        // Menu & assets
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // CPT Plantillas
        add_action( 'init',                  [ $this, 'register_cpt_templates' ] );
        add_action( 'add_meta_boxes',        [ $this, 'add_metaboxes' ] );
        add_action( 'save_post_' . self::CPT_TPL, [ $this, 'save_subject_metabox' ] );

        // AJAX/Preview
        add_action( 'wp_ajax_'  . self::AJAX_ACTION_PREV,   [ $this, 'ajax_preview_template' ] );
        add_action( 'admin_post_' . self::ADMIN_POST_PREVIEW_IFRAME, [ $this, 'handle_preview_iframe' ] );
        add_action( 'wp_ajax_'  . self::AJAX_ACTION_IFRAME, [ $this, 'ajax_preview_iframe_html' ] );

        // Mailing
        add_filter( 'wp_mail_content_type', function(){ return 'text/html'; } );
        add_action( 'phpmailer_init', [ $this, 'setup_phpmailer' ] );

        // Agregar handlers para admin-post
        add_action( 'admin_post_' . self::SEND_TEST_ACTION,            [ $this, 'handle_send_test' ] );
        add_action( 'admin_post_' . self::ADMIN_POST_CAMPAIGN_CREATE,  [ $this, 'handle_create_campaign' ] );
        add_action( 'admin_post_' . self::ADMIN_POST_CAMPAIGN_UPDATE,  [ $this, 'handle_update_campaign' ] );
        add_action( 'admin_post_' . self::ADMIN_POST_CAMPAIGN_DELETE,  [ $this, 'handle_delete_campaign' ] );
        add_action( 'admin_post_wec_force_cron',                      [ $this, 'handle_force_cron' ] );

        // Cron
        add_action( self::CRON_HOOK, [ $this, 'process_queue_cron' ] );

        // Install/Upgrades
        register_activation_hook( __FILE__, [ $this, 'maybe_install_tables' ] );
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

    /*** Assets ***/
    public function enqueue_admin_assets( $hook ) {
        $is_wec      = ( strpos($hook, self::ROOT_MENU_SLUG) !== false );
        $is_wec_page = in_array($hook, [
            'toplevel_page_' . self::ROOT_MENU_SLUG,
            'email-manager_page_wec-campaigns',
            'email-manager_page_wec-smtp'
        ]);
        $is_tpl_list = ( $hook === 'edit.php' && ( $_GET['post_type'] ?? '' ) === self::CPT_TPL );
        $is_tpl_edit = (
            ( $hook === 'post.php'     && get_post_type( intval($_GET['post'] ?? 0) ) === self::CPT_TPL ) ||
            ( $hook === 'post-new.php' && ( $_GET['post_type'] ?? '' ) === self::CPT_TPL )
        );
        
        if ( ! ( $is_wec || $is_wec_page || $is_tpl_list || $is_tpl_edit ) ) return;

        add_thickbox();
        wp_register_script( 'wec-admin', false, [ 'jquery','thickbox' ], '2.5.1-hotfix', true );
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
    console.log('Preview button clicked');
    
    if (typeof WEC_AJAX === 'undefined') { 
      alert('No se pudo iniciar vista previa - WEC_AJAX no definido'); 
      console.error('WEC_AJAX not defined'); 
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
    console.log('Template ID:', tplId, 'from selector:', $templateSelect.attr('id'));
    
    if(!tplId){ 
      alert('Selecciona una plantilla.'); 
      return; 
    }

    tb_show('Vista previa','#TB_inline?height=700&width=1000&inlineId=wec-preview-modal');

    setFrameWidth('desktop');
    $(document).off('click.wecsize').on('click.wecsize','[data-wec-size]',function(){ setFrameWidth($(this).data('wec-size')); });

    var $f = $('#wec-preview-iframe');
    var di = $f[0].contentDocument || $f[0].contentWindow.document;
    if (di) { di.open(); di.write('<!doctype html><html><body style="font-family:sans-serif;padding:24px;color:#6b7280">Cargando...</body></html>'); di.close(); }

    var url = WEC_AJAX.ajax_url
            + '?action=' + encodeURIComponent(WEC_AJAX.iframe_action)
            + '&wec_nonce=' + encodeURIComponent(WEC_AJAX.preview_nonce)
            + '&tpl_id=' + encodeURIComponent(tplId);
    
    console.log('Preview URL:', url);

    $.get(url).done(function(html){
        console.log('Preview HTML received, length:', html.length);
        console.log('HTML contains "Comprar":', html.indexOf('Comprar') !== -1);
        
        var d = $f[0].contentDocument || $f[0].contentWindow.document;
        try{ d.open(); d.write(html); d.close(); }catch(err){
          console.error('Error writing to iframe:', err);
          var blob = new Blob([html], {type: 'text/html'});
          $f.attr('src', URL.createObjectURL(blob));
        }
    }).fail(function(xhr){
        console.error('Preview failed:', xhr);
        var d = $f[0].contentDocument || $f[0].contentWindow.document;
        var msg = 'Error de vista previa ('+xhr.status+'): '+(xhr.responseText||'');
        if (d) { d.open(); d.write('<pre style="font-family:monospace;padding:16px;white-space:pre-wrap;">'+msg+'</pre>'); d.close(); }
    });
  });
});
JS;
    }

    /*** Admin UI ***/
    public function add_menu() {
        add_menu_page( 'Email Manager','Email Manager','manage_options', self::ROOT_MENU_SLUG, [ $this, 'render_panel' ], 'dashicons-email', 26 );
        add_submenu_page( self::ROOT_MENU_SLUG, 'Panel','Panel','manage_options', self::ROOT_MENU_SLUG, [ $this, 'render_panel' ] );
        add_submenu_page( self::ROOT_MENU_SLUG, 'Campañas','Campañas','manage_options', 'wec-campaigns', [ $this, 'render_campaigns_page' ] );
        add_submenu_page( self::ROOT_MENU_SLUG, 'Config. SMTP','Config. SMTP','manage_options', 'wec-smtp', [ $this, 'render_smtp_settings' ] );
    }

    public function render_panel(){
        if ( ! current_user_can('manage_options') ) return;
        $templates = get_posts([ 'post_type'=> self::CPT_TPL, 'numberposts'=> -1, 'post_status'=> ['publish','draft'] ]);
        ?>
        <div class="wrap">
            <h1>Email Manager — Panel</h1>
            <h2>Enviar prueba</h2>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SEND_TEST_ACTION); ?>">
                <?php wp_nonce_field( 'wec_send_test' ); ?>
                <table class="form-table">
                    <tr>
                      <th><label for="wec_template_id">Plantilla</label></th>
                      <td class="wec-inline">
                        <select name="wec_template_id" id="wec_template_id">
                          <?php foreach($templates as $tpl): ?>
                          <option value="<?php echo esc_attr($tpl->ID); ?>"><?php echo esc_html($tpl->post_title ?: '(sin título)'); ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button id="wec-btn-preview" type="button" class="button">Vista previa</button>
                      </td>
                    </tr>
                    <tr>
                      <th><label for="wec_test_email">Correo</label></th>
                      <td><input type="email" name="wec_test_email" id="wec_test_email" class="regular-text" required></td>
                    </tr>
                </table>
                <p><button class="button button-primary">Enviar prueba</button></p>
            </form>
            
            <?php 
            // Agregar el modal de vista previa al Panel
            echo $this->render_preview_modal_html(); 
            ?>
            
            <!-- Debug temporal: verificar que el JavaScript funcione -->
        </div>
        <?php
    }

    public function render_campaigns_page(){
        if( ! current_user_can('manage_options') ) return;
        global $wpdb;
        $table_jobs  = $wpdb->prefix . self::DB_TABLE_JOBS;
        $jobs = $wpdb->get_results( "SELECT * FROM {$table_jobs} ORDER BY id DESC LIMIT 100" );
        $templates = get_posts([ 'post_type'=> self::CPT_TPL, 'numberposts'=> -1, 'post_status'=> ['publish','draft'] ]);

        $edit_job = isset($_GET['edit_job']) ? intval($_GET['edit_job']) : 0;
        $job_to_edit = null;
        if($edit_job){
            $job_to_edit = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$table_jobs} WHERE id=%d", $edit_job) );
        }
        ?>
        <div class="wrap">
          <h1>Campañas</h1>
          
          <?php if(isset($_GET['show_debug'])): ?>
          <div class="notice notice-info">
              <h3>Estado del Sistema</h3>
              <ul>
                  <li><strong>WP Cron habilitado:</strong> <?php echo defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? '❌ No' : '✅ Sí'; ?></li>
                  <li><strong>Próximo cron programado:</strong> <?php 
                      $next_cron = wp_next_scheduled(self::CRON_HOOK);
                      echo $next_cron ? date('Y-m-d H:i:s', $next_cron) : 'Ninguno programado';
                  ?></li>
                  <li><strong>Hora actual WordPress:</strong> <?php echo current_time('mysql'); ?></li>
                  <li><strong>Trabajos pendientes:</strong> <?php 
                      global $wpdb;
                      $pending = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wec_jobs WHERE status IN('pending','running')");
                      echo intval($pending);
                  ?></li>
                  <li><strong>Items en cola:</strong> <?php 
                      $queued = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wec_job_items WHERE status='queued'");
                      echo intval($queued);
                  ?></li>
              </ul>
              <p><a href="<?php echo admin_url('admin.php?page=wec-campaigns'); ?>" class="button">Ocultar Debug</a></p>
          </div>
          <?php else: ?>
          <p>
              <a href="<?php echo admin_url('admin.php?page=wec-campaigns&show_debug=1'); ?>" class="button button-secondary">Mostrar Estado del Sistema</a>
              <a href="<?php echo admin_url('admin-post.php?action=wec_force_cron&_wpnonce=' . wp_create_nonce('wec_force_cron')); ?>" class="button">Procesar Cola Manualmente</a>
          </p>
          <?php endif; ?>
          
          <?php if($job_to_edit): ?>
          <h2>Editar campaña #<?php echo intval($job_to_edit->id); ?></h2>
          <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ADMIN_POST_CAMPAIGN_UPDATE); ?>">
            <input type="hidden" name="job_id" value="<?php echo intval($job_to_edit->id); ?>">
            <?php wp_nonce_field( 'wec_campaign_update_'.$job_to_edit->id ); ?>
            <table class="form-table">
              <tr>
                <th>Plantilla</th>
                <td class="wec-inline">
                  <select name="tpl_id" id="wec_template_id_edit">
                    <?php foreach($templates as $tpl): ?>
                    <option value="<?php echo esc_attr($tpl->ID); ?>" <?php selected($tpl->ID, $job_to_edit->tpl_id); ?>><?php echo esc_html($tpl->post_title); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button id="wec-btn-preview-edit" type="button" class="button wec-btn-preview" data-target="#wec_template_id_edit">Vista previa</button>
                </td>
              </tr>
              <tr>
                <th>Inicio</th>
                <td><input type="datetime-local" name="start_at" value="<?php echo esc_attr( str_replace(' ', 'T', $job_to_edit->start_at) ); ?>">
                  <p class="wec-help">Déjalo vacío para empezar de inmediato.</p>
                </td>
              </tr>
              <tr>
                <th>Lote por minuto</th>
                <td><input type="number" name="rate_per_minute" value="<?php echo intval($job_to_edit->rate_per_minute ?: 100); ?>" min="1" step="1"></td>
              </tr>
            </table>
            <p><button class="button button-primary">Guardar cambios</button></p>
          </form>
          <hr/>
          <?php endif; ?>

          <h2>Crear campaña</h2>
          <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ADMIN_POST_CAMPAIGN_CREATE); ?>">
            <?php wp_nonce_field( 'wec_campaign_create' ); ?>
            <table class="form-table">
              <tr>
                <th>Plantilla</th>
                <td class="wec-inline">
                  <select name="tpl_id" id="wec_template_id_create">
                    <?php foreach($templates as $tpl): ?>
                    <option value="<?php echo esc_attr($tpl->ID); ?>"><?php echo esc_html($tpl->post_title); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button id="wec-btn-preview-create" type="button" class="button wec-btn-preview" data-target="#wec_template_id_create">Vista previa</button>
                </td>
              </tr>
              <tr>
                <th>Destinatarios</th>
                <td>
                  <label><input type="radio" name="recipients_mode" value="scan" checked> Usar escaneo de todo el sitio</label><br>
                  <label><input type="radio" name="recipients_mode" value="paste"> Pegar correos (uno por línea)</label><br>
                  <textarea name="recipients_list" rows="6" cols="80" placeholder="correo1@ejemplo.com&#10;correo2@ejemplo.com" style="width:100%;max-width:700px;"></textarea>
                </td>
              </tr>
              <tr>
                <th>Inicio</th>
                <td>
                  <input type="datetime-local" name="start_at">
                  <p class="wec-help">Déjalo vacío para empezar de inmediato.</p>
                </td>
              </tr>
              <tr>
                <th>Lote por minuto</th>
                <td><input type="number" name="rate_per_minute" value="100" min="1" step="1"></td>
              </tr>
            </table>
            <p><button class="button button-primary">Crear campaña</button></p>
          </form>

          <h2>Campañas recientes</h2>
          <table class="wec-table">
            <thead><tr>
              <th>ID</th><th>Estado</th><th>Plantilla</th><th>Inicio</th><th>Total</th><th>Enviados</th><th>Fallidos</th><th>Acciones</th>
            </tr></thead>
            <tbody>
            <?php if($jobs): foreach($jobs as $job): ?>
              <tr>
                <td>#<?php echo intval($job->id); ?></td>
                <td><?php echo esc_html($job->status); ?></td>
                <td><?php echo esc_html(get_the_title($job->tpl_id)); ?></td>
                <td><?php echo esc_html($job->start_at); ?></td>
                <td><?php echo intval($job->total); ?></td>
                <td><?php echo intval($job->sent); ?></td>
                <td><?php echo intval($job->failed); ?></td>
                <td class="wec-inline">
                  <a class="button" href="<?php echo esc_url( admin_url('admin.php?page=wec-campaigns&edit_job='.$job->id) ); ?>">Editar</a>
                  <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ADMIN_POST_CAMPAIGN_DELETE); ?>">
                    <input type="hidden" name="job_id" value="<?php echo intval($job->id); ?>"/>
                    <?php wp_nonce_field( 'wec_campaign_delete_'.$job->id ); ?>
                    <button class="button-link-delete" onclick="return confirm('¿Eliminar campaña #<?php echo intval($job->id); ?>?')">Eliminar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="8">No hay campañas.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php
        // Agregar el modal de vista previa para las campañas
        echo $this->render_preview_modal_html();
    }

    /*** Campaign Handlers ***/
    public function handle_create_campaign(){
        if( ! current_user_can('manage_options') ) wp_die('No autorizado.');
        check_admin_referer( 'wec_campaign_create' );
        $tpl_id = intval($_POST['tpl_id'] ?? 0);
        $mode   = sanitize_text_field($_POST['recipients_mode'] ?? 'scan');
        $list_raw = wp_unslash($_POST['recipients_list'] ?? '');
        $start_at = sanitize_text_field($_POST['start_at'] ?? '');
        $rate_per_min = max(1, intval($_POST['rate_per_minute'] ?? 100));

        if(!$tpl_id) wp_die('Selecciona una plantilla.');

        // Construir lista de destinatarios
        $emails = ($mode === 'paste' && trim($list_raw) !== '') ? $this->parse_pasted_emails($list_raw) : $this->gather_emails_full_scan();

        // Excluir desuscritos
        $emails = $this->filter_unsubscribed($emails);

        global $wpdb;
        $table_jobs  = $wpdb->prefix . self::DB_TABLE_JOBS;
        $table_items = $wpdb->prefix . self::DB_TABLE_ITEMS;

        // start_at: si viene vacío, ahora mismo
        $start_value = $start_at ? $start_at : current_time('mysql');

        $wpdb->insert($table_jobs,[
            'tpl_id'   => $tpl_id,
            'status'   => 'pending',
            'start_at' => $start_value,
            'total'    => count($emails),
            'sent'     => 0,
            'failed'   => 0,
            'created_at'=> current_time('mysql'),
            'rate_per_minute' => $rate_per_min,
        ], ['%d','%s','%s','%d','%d','%d','%s','%d']);
        $job_id = $wpdb->insert_id;

        foreach($emails as $e){
            $wpdb->insert($table_items,[
                'job_id' => $job_id,
                'email'  => $e,
                'status' => 'queued',
                'error'  => '',
                'attempts'=> 0,
            ], ['%d','%s','%s','%s','%d']);
        }

        // Programar cron inmediato
        if( ! wp_next_scheduled( self::CRON_HOOK ) ){
            wp_schedule_single_event( time() + 30, self::CRON_HOOK );
        }

        wp_redirect( admin_url('admin.php?page=wec-campaigns') );
        exit;
    }

    public function handle_update_campaign(){
        if( ! current_user_can('manage_options') ) wp_die('No autorizado.');
        $job_id = intval($_POST['job_id'] ?? 0);
        check_admin_referer( 'wec_campaign_update_'.$job_id );
        $tpl_id = intval($_POST['tpl_id'] ?? 0);
        $start_at = sanitize_text_field($_POST['start_at'] ?? '');
        $rate_per_min = max(1, intval($_POST['rate_per_minute'] ?? 100));

        if(!$job_id || !$tpl_id) wp_die('Datos incompletos.');

        global $wpdb;
        $table_jobs  = $wpdb->prefix . self::DB_TABLE_JOBS;
        $data = [ 'tpl_id'=>$tpl_id, 'rate_per_minute'=>$rate_per_min ];
        $fmt  = [ '%d','%d' ];
        if($start_at !== ''){ $data['start_at'] = $start_at; $fmt[] = '%s'; }
        $wpdb->update($table_jobs, $data, ['id'=>$job_id], $fmt, ['%d']);

        wp_safe_redirect( admin_url('admin.php?page=wec-campaigns') );
        exit;
    }

    public function handle_delete_campaign(){
        if( ! current_user_can('manage_options') ) wp_die('No autorizado.');
        $job_id = intval($_POST['job_id'] ?? 0);
        check_admin_referer( 'wec_campaign_delete_'.$job_id );
        global $wpdb;
        $table_jobs  = $wpdb->prefix . self::DB_TABLE_JOBS;
        $table_items = $wpdb->prefix . self::DB_TABLE_ITEMS;
        $wpdb->delete( $table_jobs, ['id'=>$job_id], ['%d'] );
        $wpdb->delete( $table_items, ['job_id'=>$job_id], ['%d'] );
        wp_safe_redirect( admin_url('admin.php?page=wec-campaigns') );
        exit;
    }

    public function handle_force_cron(){
        if( ! current_user_can('manage_options') ) wp_die('No autorizado.');
        check_admin_referer( 'wec_force_cron' );
        
        // Ejecutar el procesamiento directamente
        $this->process_queue_cron();
        
        wp_redirect( admin_url('admin.php?page=wec-campaigns') );
        exit;
    }

    /*** Cron: procesar cola ***/
    public function process_queue_cron(){
        global $wpdb;
        $table_jobs  = $wpdb->prefix . self::DB_TABLE_JOBS;
        $table_items = $wpdb->prefix . self::DB_TABLE_ITEMS;

        // Obtener campaña pendiente cuya hora haya llegado
        $job = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$table_jobs} WHERE status IN('pending','running') AND start_at <= %s ORDER BY id ASC LIMIT 1", current_time('mysql') ) );
        if( ! $job ) {
            return;
        }

        if( $job->status === 'pending' ){
            $wpdb->update($table_jobs, ['status'=>'running'], ['id'=>$job->id], ['%s'], ['%d']);
        }

        $limit = max(1, intval($job->rate_per_minute ?: 100));

        // Procesar por lote
        $batch = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$table_items} WHERE job_id=%d AND status='queued' LIMIT %d", $job->id, $limit) );
        if( ! $batch ){
            // Sin pendientes -> finalizar
            $wpdb->update($table_jobs, ['status'=>'done'], ['id'=>$job->id], ['%s'], ['%d']);
            return;
        }

        // Render de plantilla una sola vez
        list($subject, $html_raw) = $this->render_template_content( $job->tpl_id );

        // Envío REAL: inliner + resets (para clientes de correo)
        $base_html = $this->build_email_html(
            $html_raw,
            null,
            [
                'inline'        => true,
                'preserve_css'  => true,
                'reset_links'   => true
            ]
        );

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        $sent = 0; $failed = 0;
        foreach($batch as $item){
            if ( $this->is_unsubscribed($item->email) ) {
                $wpdb->update($table_items, ['status'=>'failed','attempts'=>$item->attempts+1,'error'=>'unsubscribed'], ['id'=>$item->id], ['%s','%d','%s'], ['%d']);
                $failed++;
                continue;
            }
            $html_personal = str_replace('[[UNSUB_URL]]', $this->get_unsub_url($item->email), $base_html);
            $ok = wp_mail( $item->email, $subject, $html_personal, $headers );
            if( $ok ){
                $wpdb->update($table_items, ['status'=>'sent','attempts'=>$item->attempts+1], ['id'=>$item->id], ['%s','%d'], ['%d']);
                $sent++;
            }else{
                $wpdb->update($table_items, ['status'=>'failed','attempts'=>$item->attempts+1,'error'=>'wp_mail false'], ['id'=>$item->id], ['%s','%d','%s'], ['%d']);
                $failed++;
            }
        }
        // Actualizar totales
        $wpdb->query( $wpdb->prepare("UPDATE {$table_jobs} SET sent = sent + %d, failed = failed + %d WHERE id=%d", $sent, $failed, $job->id ) );

        if( (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$table_items} WHERE job_id=%d AND status='queued'", $job->id ) ) > 0 ){
            wp_schedule_single_event( time() + 60, self::CRON_HOOK );
        }
    }

    /*** SMTP ***/
    public function render_smtp_settings(){
        if( ! current_user_can('manage_options') ) return;
        $env_path = ABSPATH . 'programData/emailsWishList/.env';
        $env = [];
        if( file_exists( $env_path ) ){
            $env = $this->parse_env_file( $env_path );
            echo '<div class="notice notice-success"><p>Modo .env activo: usando <code>'.esc_html($env_path).'</code>.</p></div>';
        }

        $opts = get_option( self::OPT_SMTP, [] );
        $host = $env['SMTP_HOST'] ?? ($opts['host'] ?? '');
        $port = $env['SMTP_PORT'] ?? ($opts['port'] ?? '');
        $user = $env['SMTP_USER'] ?? ($opts['user'] ?? '');
        $pass = $env['SMTP_PASS'] ?? ($opts['pass'] ?? '');
        $secure = $env['SMTP_USE_SSL'] ?? ($opts['secure'] ?? '');
        $from_name = $env['FROM_NAME'] ?? ($opts['from_name'] ?? '');
        $from  = $env['FROM_EMAIL'] ?? ($opts['from'] ?? '');

        if( isset($_POST['wec_smtp_save']) && check_admin_referer('wec_smtp_save') ){
            $opts = [
              'host' => sanitize_text_field($_POST['SMTP_HOST'] ?? ''),
              'port' => intval($_POST['SMTP_PORT'] ?? 0),
              'user' => sanitize_text_field($_POST['SMTP_USER'] ?? ''),
              'pass' => sanitize_text_field($_POST['SMTP_PASS'] ?? ''),
              'secure'=> sanitize_text_field($_POST['SMTP_USE_SSL'] ?? ''),
              'from' => sanitize_email($_POST['FROM_EMAIL'] ?? ''),
              'from_name'=> sanitize_text_field($_POST['FROM_NAME'] ?? ''),
            ];
            update_option( self::OPT_SMTP, $opts );
            echo '<div class="notice notice-success"><p>SMTP guardado.</p></div>';
            $host=$opts['host'];$port=$opts['port'];$user=$opts['user'];$pass=$opts['pass'];$secure=$opts['secure'];$from=$opts['from'];$from_name=$opts['from_name'];
        }
        ?>
        <div class="wrap"><h1>Configuración SMTP</h1>
        <form method="post">
            <?php wp_nonce_field('wec_smtp_save'); ?>
            <table class="form-table">
              <tr><th><label for="SMTP_HOST">SMTP_HOST</label></th><td><input id="SMTP_HOST" name="SMTP_HOST" value="<?php echo esc_attr($host); ?>" class="regular-text"></td></tr>
              <tr><th><label for="SMTP_PORT">SMTP_PORT</label></th><td><input id="SMTP_PORT" name="SMTP_PORT" value="<?php echo esc_attr($port?:587); ?>" class="small-text"></td></tr>
              <tr><th><label for="SMTP_USER">SMTP_USER</label></th><td><input id="SMTP_USER" name="SMTP_USER" value="<?php echo esc_attr($user); ?>" class="regular-text"></td></tr>
              <tr><th><label for="SMTP_PASS">SMTP_PASS</label></th><td><input id="SMTP_PASS" name="SMTP_PASS" type="password" value="<?php echo esc_attr($pass); ?>" class="regular-text"></td></tr>
              <tr><th><label for="FROM_NAME">FROM_NAME</label></th><td><input id="FROM_NAME" name="FROM_NAME" value="<?php echo esc_attr($from_name); ?>" class="regular-text"></td></tr>
              <tr><th><label for="FROM_EMAIL">FROM_EMAIL</label></th><td><input id="FROM_EMAIL" name="FROM_EMAIL" value="<?php echo esc_attr($from); ?>" class="regular-text"></td></tr>
              <tr><th><label for="SMTP_USE_SSL">SMTP_USE_SSL</label></th>
                <td>
                  <select id="SMTP_USE_SSL" name="SMTP_USE_SSL">
                    <option value="" <?php selected($secure,'');?>>(ninguna)</option>
                    <option value="tls" <?php selected($secure,'tls');?>>TLS (587)</option>
                    <option value="ssl" <?php selected($secure,'ssl');?>>SSL (465)</option>
                  </select>
                </td>
              </tr>
            </table>
            <p>
              <button class="button" type="button" onclick="window.location='<?php echo esc_js( admin_url('edit.php?post_type='.self::CPT_TPL) ); ?>'">Volver a Email Templates</button>
              <button class="button button-primary" name="wec_smtp_save" value="1">Guardar</button>
            </p>
        </form></div>
        <?php
    }

    public function setup_phpmailer( $phpmailer ){
        $opts = get_option( self::OPT_SMTP, [] );
        $env_path = ABSPATH . 'programData/emailsWishList/.env';
        if( file_exists($env_path) ){
            $env = $this->parse_env_file($env_path);
            $opts['host'] = $env['SMTP_HOST'] ?? ($opts['host'] ?? '');
            $opts['port'] = $env['SMTP_PORT'] ?? ($opts['port'] ?? '');
            $opts['user'] = $env['SMTP_USER'] ?? ($opts['user'] ?? '');
            $opts['pass'] = $env['SMTP_PASS'] ?? ($opts['pass'] ?? '');
            $opts['secure'] = $env['SMTP_USE_SSL'] ?? ($opts['secure'] ?? '');
            $opts['from_name'] = $env['FROM_NAME'] ?? ($opts['from_name'] ?? '');
            $opts['from'] = $env['FROM_EMAIL'] ?? ($opts['from'] ?? '');
        }
        if( empty($opts['host']) ) return;
        $phpmailer->isSMTP();
        $phpmailer->Host = $opts['host'];
        if( ! empty($opts['port']) ) $phpmailer->Port = intval($opts['port']);
        if( ! empty($opts['user']) ){
            $phpmailer->SMTPAuth = true;
            $phpmailer->Username = $opts['user'];
            $phpmailer->Password = $opts['pass'] ?? '';
        }
        if( ! empty($opts['secure']) ) $phpmailer->SMTPSecure = $opts['secure'];
        if( ! empty($opts['from']) )   $phpmailer->setFrom( $opts['from'], $opts['from_name'] ?? '' );
    }

    /*** Send test ***/
    public function handle_send_test(){
        if( ! current_user_can('manage_options') ) wp_die('No autorizado');
        check_admin_referer( 'wec_send_test' );
        $tpl_id = intval($_POST['wec_template_id'] ?? 0);
        $to     = sanitize_email($_POST['wec_test_email'] ?? '');
        if( ! $tpl_id || ! is_email($to) ) wp_die('Datos inválidos.');

        list($subject,$html_raw) = $this->render_template_content($tpl_id);

        // Envío REAL: inliner + resets
        $html_final = $this->build_email_html(
            $html_raw,
            $to,
            [
                'inline'        => true,    // Activar inlining para Gmail
                'preserve_css'  => false,   // Gmail necesita estilos inline puros
                'reset_links'   => true     // Aplicar todas las correcciones
            ]
        );

        $ok = wp_mail( $to, $subject, $html_final, [ 'Content-Type: text/html; charset=UTF-8' ] );
        wp_safe_redirect( admin_url('admin.php?page='.self::ROOT_MENU_SLUG.'&test='.($ok?'ok':'fail')) );
        exit;
    }

    /*** CPT ***/
    public function register_cpt_templates(){
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
        ];
        register_post_type( self::CPT_TPL, [
            'labels'        => $labels,
            'public'        => false,
            'show_ui'       => true,
            'show_in_menu'  => self::ROOT_MENU_SLUG,
            'supports'      => [ 'title','editor' ],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
        ] );
    }

    public function add_metaboxes(){
        add_meta_box( 'wec_subject_box', 'Asunto del correo', [ $this,'render_subject_metabox' ], self::CPT_TPL, 'side', 'high' );
        add_meta_box( 'wec_preview_box', 'Vista previa',        [ $this,'render_preview_metabox' ], self::CPT_TPL, 'side', 'high' );
    }

    public function render_subject_metabox( $post ){
        wp_nonce_field( 'wec_subject_save', '_wec_subject_nonce' );
        $subject = get_post_meta( $post->ID, self::META_SUBJECT, true );
        echo '<p><input type="text" name="wec_subject" class="widefat" value="'.esc_attr($subject).'" placeholder="Ej: No es cualquier reloj, es un Curren ⌚"></p>';
        echo '<p class="description">Placeholders: <code>{{site_name}}</code>, <code>{{site_url}}</code>, <code>{{admin_email}}</code>, <code>{{date}}</code></p>';
    }

    public function render_preview_metabox( $post ){
        echo '<input type="hidden" id="wec_template_id" value="'.esc_attr($post->ID).'">';
        echo '<p><button id="wec-btn-preview" type="button" class="button button-primary">Vista previa</button></p>';
        echo $this->render_preview_modal_html();
    }

    private function render_preview_modal_html(){
        ob_start(); ?>
        <div id="wec-preview-modal" style="display:none;">
          <div id="wec-preview-wrap">
            <div class="wec-toolbar">
              <div id="wec-preview-subject">Asunto...</div><span class="sep"></span>
              <button type="button" class="button" data-wec-size="mobile">Móvil 360</button>
              <button type="button" class="button" data-wec-size="tablet">Tablet 600</button>
              <button type="button" class="button" data-wec-size="desktop">Desktop 800</button>
              <button type="button" class="button" data-wec-size="full">Ancho libre</button>
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

    public function save_subject_metabox( $post_id ){
        if( ! isset($_POST['_wec_subject_nonce']) || ! wp_verify_nonce($_POST['_wec_subject_nonce'],'wec_subject_save') ) return;
        if( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if( ! current_user_can( 'edit_post', $post_id ) ) return;
        $subject = sanitize_text_field( $_POST['wec_subject'] ?? '' );
        update_post_meta( $post_id, self::META_SUBJECT, $subject );
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

        if ( $recipient_email ) {
            $html = str_replace('[[UNSUB_URL]]', $this->get_unsub_url($recipient_email), $html);
        }

        return $this->wrap_email_html($html);
    }

    private function gather_emails_full_scan(){
        $emails = [];
        $users = get_users(['fields'=>['user_email'], 'number' => 5000]);
        foreach($users as $u){ if(is_email($u->user_email)) $emails[] = strtolower($u->user_email); }
        // Comentarios paginados
        $paged = 1; $per = 1000;
        do {
            $cmts = get_comments(['status'=>'approve','type'=>'comment','number'=>$per,'paged'=>$paged,'fields'=>'ids']);
            if(!$cmts) break;
            foreach($cmts as $cid){
                $c = get_comment($cid);
                if($c && is_email($c->comment_author_email)) $emails[] = strtolower($c->comment_author_email);
            }
            $paged++;
        } while (count($cmts)===$per);
        return array_values( array_unique($emails) );
    }

    private function parse_pasted_emails( $raw ){
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $out = [];
        foreach($lines as $ln){
            $ln = trim($ln);
            if($ln && is_email($ln)) $out[] = strtolower($ln);
        }
        return array_values(array_unique($out));
    }

    private function filter_unsubscribed( $emails ){
        $emails = array_values(array_unique(array_map(function($e){ return strtolower(trim($e)); }, $emails)));
        if (empty($emails)) return $emails;
        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE_SUBSCRIBERS;
        $placeholders = implode(',', array_fill(0, count($emails), '%s'));
        $sql = $wpdb->prepare("SELECT email FROM {$table} WHERE status='unsubscribed' AND email IN ($placeholders)", $emails);
        $blocked = $wpdb->get_col($sql);
        if (!$blocked) return $emails;
        $blocked = array_flip($blocked);
        $out = [];
        foreach($emails as $e){ if (!isset($blocked[$e])) $out[] = $e; }
        return $out;
    }

    private function is_unsubscribed( $email ){
        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE_SUBSCRIBERS;
        $email = strtolower(trim($email));
        $st = $wpdb->get_var( $wpdb->prepare("SELECT status FROM {$table} WHERE email=%s", $email) );
        return ( $st === 'unsubscribed' );
    }

    private function render_template_content( $tpl_id ){
        $post = get_post( $tpl_id );
        if( ! $post || $post->post_type !== self::CPT_TPL ) throw new \Exception('Plantilla no encontrada.');
        $subject = get_post_meta( $tpl_id, self::META_SUBJECT, true ) ?: '(Sin asunto)';
        
        // Usar el contenido del post de WordPress directamente
        $html = (string) $post->post_content;
        
        // Si el contenido está vacío, mostrar mensaje de ayuda
        if (empty(trim($html))) {
            $html = '<div style="text-align:center;padding:40px;font-family:Arial,sans-serif;">
                <h2>Plantilla vacía</h2>
                <p>Agrega tu código HTML en el editor de contenido de esta plantilla.</p>
                <p style="color:#666;">Tip: Pega tu HTML completo del email aquí.</p>
            </div>';
        }
        
        $repl = [
            '{{site_name}}'   => get_bloginfo('name'),
            '{{site_url}}'    => home_url('/'),
            '{{admin_email}}' => get_option('admin_email'),
            '{{date}}'        => date_i18n( get_option('date_format') . ' ' . get_option('time_format') ),
        ];
        $html = strtr($html, $repl);
        return [ $subject, $html ];
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
                
                if (preg_match('/\sstyle=("|\')(.*?)\1/i', $element, $sm)){
                    $existing = trim($sm[2]);
                    $combined = $existing . ($existing && substr($existing, -1) !== ';' ? ';' : '') . $new_decl;
                    return preg_replace('/\sstyle=("|\')(.*?)\1/i', ' style="'.$combined.'"', $element, 1);
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
     * Detecta y fuerza estilos de botones ANTES del CSS inlining
     * Funciona mientras las clases CSS aún existen
     */
    private function enforce_button_styles_pre_inline($html) {
        // Estilos para botones rojos (like Comprar) - CENTRADO CON DISPLAY:BLOCK
        $button_styles = 'display:block!important;visibility:visible!important;opacity:1!important;background-color:#D94949!important;color:#ffffff!important;padding:12px 22px!important;border-radius:8px!important;font-family:Arial,Helvetica,sans-serif!important;font-weight:700!important;font-size:16px!important;text-decoration:none!important;border:0!important;outline:none!important;text-align:center!important;line-height:1.2!important;cursor:pointer!important;vertical-align:top!important;mso-border-alt:none!important;word-break:break-word!important;margin:0 auto!important;width:auto!important;max-width:200px!important;';
        
        // Buscar enlaces con clases que contengan "btn" y convertirlos a botones 100% inline
        $html = preg_replace_callback(
            '/<a\b([^>]*class="[^"]*btn[^"]*"[^>]*)>(.*?)<\/a>/is',
            function($m) use ($button_styles) {
                $attrs = $m[1];
                $content = $m[2];
                
                // Extraer href del enlace original
                $href = '';
                if (preg_match('/\bhref=(["\'])(.*?)\1/i', $attrs, $href_match)) {
                    $href = $href_match[2];
                }
                
                // Crear botón completamente inline SIN clases CSS
                $inline_button = sprintf(
                    '<a href="%s" style="%s">%s</a>',
                    esc_attr($href),
                    esc_attr($button_styles),
                    $content
                );
                
                return $inline_button;
            },
            $html
        );
        
        return $html;
    }

    /**
     * Asegura que los botones sean siempre visibles con estilos inline completos
     */
    private function enforce_button_styles($html) {
        // Estilos para botones rojos (like Comprar)
        $button_styles = 'display:inline-block!important;visibility:visible!important;opacity:1!important;background-color:#D94949!important;color:#ffffff!important;padding:12px 22px!important;border-radius:8px!important;font-family:Arial,Helvetica,sans-serif!important;font-weight:700!important;font-size:16px!important;text-decoration:none!important;border:0!important;outline:none!important;text-align:center!important;';
        
        // NUEVA ESTRATEGIA: Buscar por contenido "Comprar" en lugar de clases (MÁS ESPECÍFICO)
        $html = preg_replace_callback(
            '/<a\b([^>]*)>([^<]*Comprar[^<]*)<\/a>/is',
            function($m) use ($button_styles) {
                $attrs = $m[1];
                $content = $m[2];
                
                // Solo procesar si el contenido es específicamente un botón (no navegación)
                $clean_content = strip_tags($content);
                $clean_content = preg_replace('/\s+/', ' ', trim($clean_content));
                
                // Filtrar falsos positivos - debe ser solo "Comprar" o variantes cercanas
                if (preg_match('/^(Comprar|Comprar\s+Ahora|Buy\s+Now)$/i', $clean_content)) {
                    // Aplicar estilos de botón de forma AGRESIVA
                    if (preg_match('/\sstyle=("|\')(.*?)\1/i', $attrs, $sm)) {
                        $existing = trim($sm[2]);
                        $combined = $button_styles . ';' . $existing;
                        $attrs = preg_replace('/\sstyle=("|\')(.*?)\1/i', ' style="'.$combined.'"', $attrs, 1);
                    } else {
                        $attrs .= ' style="' . $button_styles . '"';
                    }
                    
                    return '<a' . $attrs . '>' . $content . '</a>';
                }
                
                // Si no es un botón real, devolver sin cambios
                return $m[0];
            },
            $html
        );
        
        return $html;
    }

    /**
     * Fuerza estilos críticos para elementos de navegación (nav-white, dark, etc.)
     * Gmail es muy estricto con estilos en enlaces de navegación
     */
    private function enforce_navigation_styles($html) {
        // Patrones para elementos de navegación comunes
        $nav_patterns = [
            // Enlaces dentro de contenedores con clase nav-white
            [
                'container_class' => 'nav-white',
                'styles' => 'color:#ffffff!important;text-decoration:none!important;font-family:Arial,Helvetica,sans-serif!important;'
            ],
            // Enlaces dentro de contenedores con clase dark
            [
                'container_class' => 'dark',
                'styles' => 'color:#ffffff!important;text-decoration:none!important;font-family:Arial,Helvetica,sans-serif!important;'
            ],
            // Enlaces dentro de contenedores con clase bg-dark
            [
                'container_class' => 'bg-dark',
                'styles' => 'color:#ffffff!important;text-decoration:none!important;font-family:Arial,Helvetica,sans-serif!important;'
            ]
        ];

        foreach ($nav_patterns as $nav_config) {
            $class = $nav_config['container_class'];
            $styles = $nav_config['styles'];
            
            // Patrón para encontrar contenedores con la clase específica y sus enlaces descendientes
            $pattern = '#(<[^>]*\bclass=["\'][^"\']*\b' . preg_quote($class, '#') . '\b[^"\']*["\'][^>]*>)(.*?)(</[^>]+>)#is';
            
            $html = preg_replace_callback(
                $pattern,
                function($m) use ($styles) {
                    $open_tag = $m[1];
                    $content = $m[2];
                    $close_tag = $m[3];
                    
                    // Aplicar estilos a todos los enlaces dentro del contenedor
                    $content = preg_replace_callback(
                        '#<a\b([^>]*)>#i',
                        function($link_match) use ($styles) {
                            $attrs = $link_match[1];
                            
                            // Agregar o combinar estilos
                            if (preg_match('/\sstyle=("|\')(.*?)\1/i', $attrs, $sm)) {
                                $existing = trim($sm[2]);
                                $combined = $existing . ($existing && substr($existing, -1) !== ';' ? ';' : '') . $styles;
                                $attrs = preg_replace('/\sstyle=("|\')(.*?)\1/i', ' style="'.$combined.'"', $attrs, 1);
                            } else {
                                $attrs .= ' style="' . esc_attr($styles) . '"';
                            }
                            
                            return '<a' . $attrs . '>';
                        },
                        $content
                    );
                    
                    return $open_tag . $content . $close_tag;
                },
                $html
            );
        }
        
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
                line-height: 1.2 !important;
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
                        
                        // SOLO aplicar si NO tiene ya display:block (evitar conflictos)
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

        try{
            list($subject,$html) = $this->render_template_content($tpl_id);

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

        try{
            list($subject,$html) = $this->render_template_content($tpl_id);

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
        try {
            list($subject,$html) = $this->render_template_content($tpl_id);
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

    /*** DB Install & columns ***/
    public function maybe_install_tables(){
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table_jobs  = $wpdb->prefix . self::DB_TABLE_JOBS;
        $table_items = $wpdb->prefix . self::DB_TABLE_ITEMS;
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
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
        dbDelta($sql1);
        dbDelta($sql2);

        $sql3 = "CREATE TABLE {$wpdb->prefix}".self::DB_TABLE_SUBSCRIBERS." (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(190) NOT NULL,
            status ENUM('subscribed','unsubscribed') NOT NULL DEFAULT 'subscribed',
            unsub_token VARCHAR(64) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_wec_email (email),
            KEY status (status)
        ) {$charset};";
        dbDelta($sql3);
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

    /*** .env parser ***/
    private function parse_env_file( $path ){
        $out = [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if(!$lines) return $out;
        foreach($lines as $line){
            if (strlen($line) === 0 || $line[0]==='#' || strpos($line,'=')===false) continue;
            list($k,$v) = explode('=', $line, 2);
            $k = trim($k); $v = trim($v);
            $v = trim($v, "\"'");
            $out[$k] = $v;
        }
        return $out;
    }
}

new WEC_Email_Collector();

endif; // class exists

/* ---------- Página de baja de suscripción ---------- */
add_shortcode('email_unsubscribe', function() {
    if (empty($_GET['e']) || empty($_GET['t'])) return '<p>Solicitud inválida.</p>';
    $email = sanitize_email($_GET['e']);
    $token = sanitize_text_field($_GET['t']);
    if (!is_email($email)) return '<p>Correo inválido.</p>';

    global $wpdb;
    $table = $wpdb->prefix . WEC_Email_Collector::DB_TABLE_SUBSCRIBERS;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE email=%s AND unsub_token=%s",
        $email, $token
    ));

    if (!$row) return '<p>Enlace inválido o ya utilizado.</p>';

    $wpdb->update($table, ['status' => 'unsubscribed'], ['email' => $email]);

    return '<h2>Has cancelado tu suscripción a los correos de Curren México.</h2>
            <p>Puedes volver a unirte cuando quieras 🕒</p>';
});
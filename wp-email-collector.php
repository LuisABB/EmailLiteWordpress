<?php
/**
 * Plugin Name: WP Email Collector
 * Description: Gestiona plantillas de email, campañas con cola y vista previa. Incluye SMTP y ejecución por WP-Cron.
 * Version:     2.1.0
 * Author:      Curren México
 * License:     GPLv2 or later
 * Text Domain: wp-email-collector
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists('WEC_Email_Collector') ) :

class WEC_Email_Collector {
    const ROOT_MENU_SLUG    = 'wec_root';
    const CPT_TPL           = 'wec_email_tpl';
    const META_SUBJECT      = '_wec_subject';
    const AJAX_ACTION_PREV  = 'wec_preview_template';
    const AJAX_NONCE        = 'wec_preview_nonce';

    // SMTP
    const OPT_SMTP          = 'wec_smtp_settings';

    // Cron & DB
    const CRON_HOOK             = 'wec_process_queue';
    const DB_TABLE_JOBS         = 'wec_jobs';
    const DB_TABLE_ITEMS        = 'wec_job_items';

    // Admin-post actions
    const SEND_TEST_ACTION  = 'wec_send_test';
    const ADMIN_POST_CAMPAIGN_CREATE = 'wec_create_campaign';
    const ADMIN_POST_CAMPAIGN_UPDATE = 'wec_update_campaign';
    const ADMIN_POST_CAMPAIGN_DELETE = 'wec_delete_campaign';

    public function __construct() {
        // Menu & assets
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // CPT Plantillas
        add_action( 'init', [ $this, 'register_cpt_templates' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
        add_action( 'save_post_' . self::CPT_TPL, [ $this, 'save_subject_metabox' ] );

        // AJAX preview
        add_action( 'wp_ajax_' . self::AJAX_ACTION_PREV, [ $this, 'ajax_preview_template' ] );

        // Admin-post handlers
        add_action( 'admin_post_' . self::SEND_TEST_ACTION, [ $this, 'handle_send_test' ] );
        add_action( 'admin_post_' . self::ADMIN_POST_CAMPAIGN_CREATE, [ $this, 'handle_create_campaign' ] );
        add_action( 'admin_post_' . self::ADMIN_POST_CAMPAIGN_UPDATE, [ $this, 'handle_update_campaign' ] );
        add_action( 'admin_post_' . self::ADMIN_POST_CAMPAIGN_DELETE, [ $this, 'handle_delete_campaign' ] );

        // Email type & SMTP
        add_filter( 'wp_mail_content_type', function(){ return 'text/html'; } );
        add_action( 'phpmailer_init', [ $this, 'setup_phpmailer' ] );

        // Cron
        add_action( self::CRON_HOOK, [ $this, 'process_queue_cron' ] );

        // Install DB
        register_activation_hook( __FILE__, [ $this, 'maybe_install_tables' ] );
        add_action( 'admin_init', [ $this, 'maybe_install_tables' ] );
        add_action( 'admin_init', [ $this, 'maybe_add_columns' ] );
    }

    /* ---------- Admin assets (JS + Thickbox) ---------- */
    public function enqueue_admin_assets( $hook ) {
        $is_wec = ( strpos($hook, self::ROOT_MENU_SLUG) !== false );
        $is_tpl_list = ( $hook === 'edit.php' && ( $_GET['post_type'] ?? '' ) === self::CPT_TPL );
        $is_tpl_edit = (
            ( $hook === 'post.php'     && get_post_type( intval($_GET['post'] ?? 0) ) === self::CPT_TPL ) ||
            ( $hook === 'post-new.php' && ( $_GET['post_type'] ?? '' ) === self::CPT_TPL )
        );
        if ( ! ( $is_wec || $is_tpl_list || $is_tpl_edit ) ) return;

        add_thickbox();
        wp_register_script( 'wec-admin', false, [ 'jquery','thickbox' ], '2.1.0', true );
        wp_enqueue_script( 'wec-admin' );
        wp_localize_script( 'wec-admin', 'WEC_AJAX', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'action'   => self::AJAX_ACTION_PREV,
            'nonce'    => wp_create_nonce( self::AJAX_NONCE ),
        ] );
        wp_add_inline_script( 'wec-admin', $this->inline_js() );
        wp_add_inline_style( 'thickbox', $this->inline_css() );
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

  $(document).on('click','#wec-btn-preview',function(e){
    e.preventDefault();
    var tplId=$('#wec_template_id').val();
    if(!tplId){ alert('Selecciona una plantilla.'); return; }
    tb_show('Vista previa de plantilla','#TB_inline?height=600&width=900&inlineId=wec-preview-modal');
    $('#wec-preview-subject').text('Cargando asunto...');
    var $frame=$('#wec-preview-iframe');
    var d=$frame[0].contentWindow||$frame[0].contentDocument;
    if(d&&d.document)d=d.document;
    if(d&&d.open){ d.open(); d.write('<!doctype html><html><body style="font-family:sans-serif;padding:24px;color:#6b7280">Cargando...</body></html>'); d.close(); }
    setFrameWidth('desktop');
    $.post(WEC_AJAX.ajax_url,{action:WEC_AJAX.action,_ajax_nonce:WEC_AJAX.nonce,tpl_id:tplId},function(resp){
      if(!resp||!resp.success){
        $('#wec-preview-subject').text('Error');
        return;
      }
      $('#wec-preview-subject').text('Asunto: '+resp.data.subject);
      var d2=$frame[0].contentWindow||$frame[0].contentDocument;
      if(d2&&d2.document)d2=d2.document;
      if(d2&&d2.open){ d2.open(); d2.write(resp.data.html_full); d2.close(); }
    });
  });

  $(document).on('click','[data-wec-size]',function(){ setFrameWidth($(this).data('wec-size')); });
});
JS;
    }

    /* ---------- Menú ---------- */
    public function add_menu() {
        add_menu_page( 'Email Manager','Email Manager','manage_options', self::ROOT_MENU_SLUG, [ $this,'render_panel' ], 'dashicons-email', 26 );
        add_submenu_page( self::ROOT_MENU_SLUG, 'Panel','Panel','manage_options', self::ROOT_MENU_SLUG, [ $this,'render_panel' ] );
        add_submenu_page( self::ROOT_MENU_SLUG, 'Campañas','Campañas','manage_options', 'wec-campaigns', [ $this,'render_campaigns_page' ] );
        add_submenu_page( self::ROOT_MENU_SLUG, 'Config. SMTP','Config. SMTP','manage_options', 'wec-smtp', [ $this,'render_smtp_settings' ] );
    }

    /* ---------- Panel principal ---------- */
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
        </div>
        <?php echo $this->render_preview_modal_html(); ?>
        <?php
    }

    /* ---------- Campañas ---------- */
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
                  <select name="tpl_id" id="wec_template_id">
                    <?php foreach($templates as $tpl): ?>
                    <option value="<?php echo esc_attr($tpl->ID); ?>" <?php selected($tpl->ID, $job_to_edit->tpl_id); ?>><?php echo esc_html($tpl->post_title); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button id="wec-btn-preview" type="button" class="button">Vista previa</button>
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
                  <select name="tpl_id" id="wec_template_id">
                    <?php foreach($templates as $tpl): ?>
                    <option value="<?php echo esc_attr($tpl->ID); ?>"><?php echo esc_html($tpl->post_title); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button id="wec-btn-preview" type="button" class="button">Vista previa</button>
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
        <?php echo $this->render_preview_modal_html(); ?>
        <?php
    }

    /* ---------- Crear/Actualizar/Eliminar campañas ---------- */
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
        if($mode === 'paste' && trim($list_raw) !== ''){
            $emails = $this->parse_pasted_emails($list_raw);
        } else {
            $emails = $this->gather_emails_full_scan();
        }

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

        // Programar cron inmediato (por si wp-cron está activo)
        if( ! wp_next_scheduled( self::CRON_HOOK ) ){
            wp_schedule_single_event( time() + 30, self::CRON_HOOK );
        }

        wp_safe_redirect( admin_url('admin.php?page=wec-campaigns') );
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

    /* ---------- Cron: procesar cola ---------- */
    public function process_queue_cron(){
        global $wpdb;
        $table_jobs  = $wpdb->prefix . self::DB_TABLE_JOBS;
        $table_items = $wpdb->prefix . self::DB_TABLE_ITEMS;

        // Obtener campaña pendiente cuya hora haya llegado
        $job = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$table_jobs} WHERE status IN('pending','running') AND start_at <= %s ORDER BY id ASC LIMIT 1", current_time('mysql') ) );
        if( ! $job ) return;

        if( $job->status === 'pending' ){
            $wpdb->update($table_jobs, ['status'=>'running'], ['id'=>$job->id], ['%s'], ['%d']);
        }

        $limit = max(1, intval($job->rate_per_minute ?: 100));

        // Procesar por lote según rate_per_minute
        $batch = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$table_items} WHERE job_id=%d AND status='queued' LIMIT %d", $job->id, $limit) );
        if( ! $batch ){
            // Sin pendientes -> finalizar
            $wpdb->update($table_jobs, ['status'=>'done'], ['id'=>$job->id], ['%s'], ['%d']);
            return;
        }

        // Render de plantilla una sola vez
        list($subject, $html) = $this->render_template_content( $job->tpl_id );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        $sent = 0; $failed = 0;
        foreach($batch as $item){
            $ok = wp_mail( $item->email, $subject, $html, $headers );
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

        // Reprogramar siguiente corrida si quedan pendientes (cada minuto)
        $remaining = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$table_items} WHERE job_id=%d AND status='queued'", $job->id ) );
        if( $remaining > 0 ){
            wp_schedule_single_event( time() + 60, self::CRON_HOOK );
        }
    }

    /* ---------- SMTP ---------- */
    public function render_smtp_settings(){
        if( ! current_user_can('manage_options') ) return;
        // Modo .env (ruta por convención del screenshot)
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
        // .env prioridad
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

    /* ---------- Send test ---------- */
    public function handle_send_test(){
        if( ! current_user_can('manage_options') ) wp_die('No autorizado');
        check_admin_referer( 'wec_send_test' );
        $tpl_id = intval($_POST['wec_template_id'] ?? 0);
        $to     = sanitize_email($_POST['wec_test_email'] ?? '');
        if( ! $tpl_id || ! is_email($to) ) wp_die('Datos inválidos.');
        list($subject,$html) = $this->render_template_content($tpl_id);
        $ok = wp_mail( $to, $subject, $html, [ 'Content-Type: text/html; charset=UTF-8' ] );
        wp_safe_redirect( admin_url('admin.php?page='.self::ROOT_MENU_SLUG.'&test='.($ok?'ok':'fail')) );
        exit;
    }

    /* ---------- CPT Plantillas ---------- */
    public function register_cpt_templates(){
        $labels = [
            'name' => 'Email Templates',
            'singular_name' => 'Email Template',
            'add_new' => 'Añadir nueva',
            'add_new_item' => 'Añadir plantilla',
            'edit_item' => 'Editar plantilla',
            'new_item' => 'Nueva plantilla',
            'view_item' => 'Ver plantilla',
            'search_items' => 'Buscar plantillas',
            'not_found' => 'No se encontraron plantillas',
            'not_found_in_trash' => 'No hay plantillas en la papelera',
        ];
        register_post_type( self::CPT_TPL, [
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => self::ROOT_MENU_SLUG,
            'supports' => [ 'title','editor' ],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ] );
    }

    public function add_metaboxes(){
        add_meta_box( 'wec_subject_box', 'Asunto del correo', [ $this,'render_subject_metabox' ], self::CPT_TPL, 'side', 'high' );
        add_meta_box( 'wec_preview_box', 'Vista previa', [ $this,'render_preview_metabox' ], self::CPT_TPL, 'side', 'high' );
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

    /* ---------- Helpers ---------- */
    private function gather_emails_full_scan(){
        $emails = [];
        // users
        $users = get_users(['fields'=>['user_email']]);
        foreach($users as $u){
            if(is_email($u->user_email)) $emails[] = strtolower($u->user_email);
        }
        // comments
        $cmts = get_comments(['status'=>'approve','type'=>'comment']);
        foreach($cmts as $c){
            if(is_email($c->comment_author_email)) $emails[] = strtolower($c->comment_author_email);
        }
        // unique
        $emails = array_values( array_unique($emails) );
        return $emails;
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

    /* ---------- Render de plantilla ---------- */
    private function render_template_content( $tpl_id ){
        $post = get_post( $tpl_id );
        if( ! $post || $post->post_type !== self::CPT_TPL ) throw new \Exception('Plantilla no encontrada.');
        $subject = get_post_meta( $tpl_id, self::META_SUBJECT, true ) ?: '(Sin asunto)';
        $content_raw = $post->post_content;
        $html = current_user_can('unfiltered_html') ? $content_raw : wp_kses_post($content_raw);
        $repl = [
            '{{site_name}}'   => get_bloginfo('name'),
            '{{site_url}}'    => home_url('/'),
            '{{admin_email}}' => get_option('admin_email'),
            '{{date}}'        => date_i18n( get_option('date_format') . ' ' . get_option('time_format') ),
        ];
        $html = strtr($html, $repl);
        return [ $subject, $html ];
    }

    /* ---------- AJAX preview ---------- */
    public function ajax_preview_template(){
        if( ! current_user_can('manage_options') ) wp_send_json_error('No autorizado',403);
        check_ajax_referer( self::AJAX_NONCE );
        $tpl_id = intval($_POST['tpl_id'] ?? 0);
        if( ! $tpl_id ) wp_send_json_error('ID inválido',400);
        try {
            list($subject,$html) = $this->render_template_content($tpl_id);
            $full = '<!doctype html><html><head><meta charset="utf-8"><title>Vista previa</title></head><body style="margin:0">'.$html.'</body></html>';
            wp_send_json_success([ 'subject'=>$subject, 'html_full'=>$full ]);
        } catch(\Throwable $e){
            wp_send_json_error($e->getMessage(),500);
        }
    }

    /* ---------- DB Install & columns ---------- */
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
    }

    public function maybe_add_columns(){
        global $wpdb;
        $table_jobs  = $wpdb->prefix . self::DB_TABLE_JOBS;
        $col = $wpdb->get_results( $wpdb->prepare("SHOW COLUMNS FROM {$table_jobs} LIKE %s", 'rate_per_minute' ) );
        if( empty($col) ){
            $wpdb->query("ALTER TABLE {$table_jobs} ADD COLUMN rate_per_minute INT UNSIGNED NOT NULL DEFAULT 100");
        }
    }

    /* ---------- .env parser ---------- */
    private function parse_env_file( $path ){
        $out = [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if(!$lines) return $out;
        foreach($lines as $line){
            if (strlen($line) === 0 || $line[0]==='#' || strpos($line,'=')===false) continue;
            list($k,$v) = explode('=', $line, 2);
            $k = trim($k); $v = trim(trim($v), "\"'");
            $out[$k] = $v;
        }
        return $out;
    }
}

new WEC_Email_Collector();

endif; // class exists
?>
<?php
/**
 * WP Email Collector - Category Manager
 * Gestión de categorías y segmentación de suscriptores
 * 
 * @package WP_Email_Collector
 * @since 9.0.0
 */

if (!defined('ABSPATH')) exit;

class WEC_Category_Manager {
    
    private static $instance = null;
    private $table_categories;
    private $table_subscriber_categories;
    
    const TABLE_CATEGORIES = 'wec_categories';
    const TABLE_SUBSCRIBER_CATEGORIES = 'wec_subscriber_categories';
    
    /**
     * Singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;
        $this->table_categories = $wpdb->prefix . self::TABLE_CATEGORIES;
        $this->table_subscriber_categories = $wpdb->prefix . self::TABLE_SUBSCRIBER_CATEGORIES;
        
        $this->init_hooks();
    }
    
    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        add_action('admin_menu', array($this, 'register_admin_menu'), 15);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_wec_save_category', array($this, 'ajax_save_category'));
        add_action('wp_ajax_wec_delete_category', array($this, 'ajax_delete_category'));
        add_action('wp_ajax_wec_assign_categories', array($this, 'ajax_assign_categories'));
        add_action('wp_ajax_wec_get_subscriber_categories', array($this, 'ajax_get_subscriber_categories'));
        add_action('wp_ajax_wec_bulk_assign_category', array($this, 'ajax_bulk_assign_category'));
        add_action('wp_ajax_wec_add_subscriber', array($this, 'ajax_add_subscriber'));
        add_action('wp_ajax_wec_delete_subscriber', array($this, 'ajax_delete_subscriber'));
    }
    
    /**
     * Registrar menú de administración
     */
    public function register_admin_menu() {
        add_submenu_page(
            'wec-campaigns',
            'Gestión de Suscriptores',
            'Suscriptores',
            'manage_options',
            'wec-subscribers',
            array($this, 'render_subscribers_page')
        );
        
        add_submenu_page(
            'wec-campaigns',
            'Categorías de Suscriptores',
            'Categorías',
            'manage_options',
            'wec-categories',
            array($this, 'render_categories_page')
        );
    }
    
    /**
     * Cargar assets CSS/JS
     */
    public function enqueue_assets($hook) {
        if (strpos($hook, 'wec-categories') === false && strpos($hook, 'wec-campaigns') === false && strpos($hook, 'wec-subscribers') === false) {
            return;
        }
        
        // Color Picker para categorías
        if (strpos($hook, 'wec-categories') !== false) {
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');
        }
        
        wp_add_inline_style('thickbox', $this->get_inline_css());
        
        wp_register_script('wec-categories', false, array('jquery'), '9.0.0', true);
        wp_enqueue_script('wec-categories');
        
        wp_localize_script('wec-categories', 'wecCategories', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wec_categories_nonce'),
            'strings' => array(
                'confirmDelete' => '¿Estás seguro de eliminar esta categoría?',
                'saved' => 'Categoría guardada correctamente',
                'error' => 'Error al guardar la categoría',
                'selectCategory' => 'Selecciona una categoría',
                'confirmBulkAssign' => '¿Asignar categoría a los suscriptores seleccionados?',
                'selectSubscribers' => 'Selecciona al menos un suscriptor',
                'categoriesUpdated' => 'Categorías actualizadas correctamente'
            )
        ));
        
        wp_add_inline_script('wec-categories', $this->get_inline_js());
    }
    
    /**
     * CSS inline
     */
    private function get_inline_css() {
        return '
        .wec-category-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            color: #fff;
            margin: 2px;
            white-space: nowrap;
        }
        .wec-category-color {
            display: inline-block;
            width: 20px;
            height: 20px;
            border-radius: 3px;
            vertical-align: middle;
            border: 1px solid #ddd;
        }
        .wec-loading {
            pointer-events: none;
            opacity: 0.6;
            position: relative;
        }
        .wec-loading::after {
            content: "";
            position: absolute;
            width: 16px;
            height: 16px;
            top: 50%;
            left: 50%;
            margin-left: -8px;
            margin-top: -8px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #3498db;
            border-radius: 50%;
            animation: wec-spin 1s linear infinite;
        }
        @keyframes wec-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .wec-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.3);
            z-index: 99999;
            justify-content: center;
            align-items: center;
        }
        .wec-overlay.active {
            display: flex;
        }
        .wec-spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: wec-spin 1s linear infinite;
        }
        .wec-category-form {
            background: #fff;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .wec-category-list {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .wec-category-item {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .wec-category-item:last-child {
            border-bottom: none;
        }
        .wec-category-info {
            flex: 1;
        }
        .wec-category-name {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 4px;
        }
        .wec-category-description {
            color: #666;
            font-size: 12px;
        }
        .wec-category-actions {
            display: flex;
            gap: 5px;
        }
        .wec-category-stats {
            margin: 0 15px;
            padding: 10px 15px;
            background: #f9f9f9;
            border-radius: 3px;
            font-size: 12px;
            color: #666;
        }
        .wec-categories-selector {
            margin: 10px 0;
        }
        .wec-categories-selector label {
            display: block;
            margin: 5px 0;
        }
        .wec-bulk-category-selector {
            display: inline-block;
            margin-left: 10px;
        }
        .wec-subscribers-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            margin-top: 20px;
        }
        .wec-subscribers-table th,
        .wec-subscribers-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        .wec-subscribers-table th {
            background: #f9fafb;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            color: #6b7280;
        }
        .wec-subscribers-table tr:hover {
            background: #f9fafb;
        }
        .wec-subscriber-actions {
            display: flex;
            gap: 5px;
        }
        .wec-bulk-actions {
            background: #fff;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .wec-filter-bar {
            background: #fff;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .wec-modal {
            display: none;
            position: fixed;
            z-index: 100000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .wec-modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #888;
            border-radius: 4px;
            width: 500px;
            max-width: 90%;
        }
        .wec-modal-close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .wec-modal-close:hover {
            color: #000;
        }
        ';
    }
    
    /**
     * JavaScript inline
     */
    private function get_inline_js() {
        return <<<'JS'
jQuery(function($){
    // ====================
    // FUNCIONES DE LOADING
    // ====================
    
    function showLoading() {
        if($('#wec-loading-overlay').length === 0) {
            $('body').append('<div id="wec-loading-overlay" class="wec-overlay"><div class="wec-spinner"></div></div>');
        }
        $('#wec-loading-overlay').addClass('active');
    }
    
    function hideLoading() {
        $('#wec-loading-overlay').removeClass('active');
    }
    
    function setButtonLoading($button, loading) {
        if(loading) {
            $button.addClass('wec-loading').prop('disabled', true);
            $button.data('original-text', $button.text());
            $button.text('Cargando...');
        } else {
            $button.removeClass('wec-loading').prop('disabled', false);
            if($button.data('original-text')) {
                $button.text($button.data('original-text'));
            }
        }
    }
    
    // ====================
    // GESTIÓN DE CATEGORÍAS
    // ====================
    
    // Guardar categoría
    $(document).on('submit', '#wec-category-form', function(e){
        e.preventDefault();
        var $form = $(this);
        var $submitBtn = $form.find('button[type="submit"]');
        
        setButtonLoading($submitBtn, true);
        
        var formData = $(this).serialize();
        formData += '&action=wec_save_category&nonce=' + wecCategories.nonce;
        
        $.post(wecCategories.ajaxurl, formData, function(response){
            setButtonLoading($submitBtn, false);
            if(response.success) {
                showLoading();
                alert(response.data.message || wecCategories.strings.saved);
                location.reload();
            } else {
                alert(response.data || wecCategories.strings.error);
            }
        }).fail(function(){
            setButtonLoading($submitBtn, false);
            alert('Error de conexión');
        });
    });
    
    // Eliminar categoría
    $(document).on('click', '.wec-delete-category', function(e){
        e.preventDefault();
        if(!confirm(wecCategories.strings.confirmDelete)) return;
        
        var $btn = $(this);
        var categoryId = $(this).data('id');
        
        setButtonLoading($btn, true);
        
        $.post(wecCategories.ajaxurl, {
            action: 'wec_delete_category',
            nonce: wecCategories.nonce,
            category_id: categoryId
        }, function(response){
            setButtonLoading($btn, false);
            if(response.success) {
                showLoading();
                location.reload();
            } else {
                alert(response.data || wecCategories.strings.error);
            }
        }).fail(function(){
            setButtonLoading($btn, false);
            alert('Error de conexión');
        });
    });
    
    // Editar categoría
    $(document).on('click', '.wec-edit-category', function(e){
        e.preventDefault();
        var item = $(this).closest('.wec-category-item');
        
        $('#category_id').val($(this).data('id'));
        $('#category_name').val(item.find('.wec-category-name').text().trim());
        $('#category_slug').val($(this).data('slug'));
        $('#category_description').val(item.find('.wec-category-description').text().trim());
        $('#category_color').val($(this).data('color')).trigger('change');
        
        $('html, body').animate({scrollTop: 0}, 500);
        $('#category_name').focus();
    });
    
    // Limpiar formulario
    $(document).on('click', '#wec-cancel-edit', function(e){
        e.preventDefault();
        $('#wec-category-form')[0].reset();
        $('#category_id').val('');
    });
    
    // Color picker
    if($('.wec-color-picker').length) {
        $('.wec-color-picker').wpColorPicker();
    }
    
    // Auto-generar slug
    $('#category_name').on('blur', function(){
        if(!$('#category_slug').val()) {
            var slug = $(this).val().toLowerCase()
                .replace(/á/g, 'a').replace(/é/g, 'e').replace(/í/g, 'i')
                .replace(/ó/g, 'o').replace(/ú/g, 'u').replace(/ñ/g, 'n')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
            $('#category_slug').val(slug);
        }
    });
    
    // ====================
    // GESTIÓN DE SUSCRIPTORES
    // ====================
    
    // Seleccionar todos los suscriptores de la página
    $('#select-all-page').on('change', function(){
        $('.subscriber-checkbox').prop('checked', $(this).prop('checked'));
        updateBulkCount();
    });
    
    // Seleccionar todos los suscriptores (global)
    $('#select-all-subscribers').on('change', function(){
        $('.subscriber-checkbox').prop('checked', $(this).prop('checked'));
        $('#select-all-page').prop('checked', $(this).prop('checked'));
        updateBulkCount();
    });
    
    // Actualizar contador al seleccionar individuales
    $(document).on('change', '.subscriber-checkbox', function(){
        updateBulkCount();
        
        // Actualizar estado de "seleccionar todos"
        var total = $('.subscriber-checkbox').length;
        var checked = $('.subscriber-checkbox:checked').length;
        $('#select-all-page').prop('checked', total === checked);
        $('#select-all-subscribers').prop('checked', total === checked);
    });
    
    // Actualizar contador de seleccionados
    function updateBulkCount() {
        var count = $('.subscriber-checkbox:checked').length;
        if(count > 0) {
            $('#bulk-selected-count').text(count + ' suscriptor(es) seleccionado(s)');
        } else {
            $('#bulk-selected-count').text('');
        }
    }
    
    // Mostrar/ocultar selector de categoría según acción
    $('#bulk-action-selector').on('change', function(){
        if($(this).val() === 'assign' || $(this).val() === 'remove') {
            $('#bulk-category-selector').show();
        } else {
            $('#bulk-category-selector').hide();
        }
    });
    
    // Aplicar acción masiva
    $('#apply-bulk-action').on('click', function(){
        var action = $('#bulk-action-selector').val();
        var categoryId = $('#bulk-category-selector').val();
        var subscriberIds = [];
        
        $('.subscriber-checkbox:checked').each(function(){
            subscriberIds.push($(this).val());
        });
        
        if(!action) {
            alert('Selecciona una acción');
            return;
        }
        
        if(subscriberIds.length === 0) {
            alert(wecCategories.strings.selectSubscribers || 'Selecciona al menos un suscriptor');
            return;
        }
        
        if((action === 'assign' || action === 'remove') && !categoryId) {
            alert('Selecciona una categoría');
            return;
        }
        
        if(!confirm(wecCategories.strings.confirmBulkAssign || '¿Aplicar esta acción a ' + subscriberIds.length + ' suscriptor(es)?')) {
            return;
        }
        
        var $btn = $(this);
        setButtonLoading($btn, true);
        
        var data = {
            action: 'wec_bulk_assign_category',
            nonce: wecCategories.nonce,
            subscriber_ids: subscriberIds,
            category_id: categoryId,
            bulk_action: action
        };
        
        $.post(wecCategories.ajaxurl, data, function(response){
            setButtonLoading($btn, false);
            if(response.success) {
                showLoading();
                alert(wecCategories.strings.categoriesUpdated || 'Categorías actualizadas correctamente');
                location.reload();
            } else {
                alert(response.data || wecCategories.strings.error);
            }
        }).fail(function(){
            setButtonLoading($btn, false);
            alert('Error de conexión');
        });
    });
    
    // Abrir modal para editar categorías de un suscriptor
    $(document).on('click', '.edit-categories-btn', function(){
        var subscriberId = $(this).data('subscriber-id');
        var subscriberEmail = $(this).data('subscriber-email');
        
        var $btn = $(this);
        setButtonLoading($btn, true);
        
        $('#modal-subscriber-id').val(subscriberId);
        $('#modal-subscriber-email').text(subscriberEmail);
        
        // Limpiar checkboxes
        $('.category-checkbox').prop('checked', false);
        
        // Obtener categorías actuales del suscriptor
        $.post(wecCategories.ajaxurl, {
            action: 'wec_get_subscriber_categories',
            nonce: wecCategories.nonce,
            subscriber_id: subscriberId
        }, function(response){
            setButtonLoading($btn, false);
            if(response.success && response.data) {
                response.data.forEach(function(catId){
                    $('.category-checkbox[value="' + catId + '"]').prop('checked', true);
                });
            }
            
            // Mostrar modal
            $('#edit-categories-modal').fadeIn(300);
        }).fail(function(){
            setButtonLoading($btn, false);
            alert('Error de conexión');
        });
    });
    
    // Cerrar modal
    $('.wec-modal-close, #cancel-edit-categories').on('click', function(){
        $('#edit-categories-modal').fadeOut(300);
    });
    
    // Cerrar modal al hacer clic fuera
    $(document).on('click', '.wec-modal', function(e){
        if(e.target === this) {
            $(this).fadeOut(300);
        }
    });
    
    // Guardar categorías del suscriptor
    $('#save-subscriber-categories').on('click', function(){
        var subscriberId = $('#modal-subscriber-id').val();
        var categoryIds = [];
        
        $('.category-checkbox:checked').each(function(){
            categoryIds.push($(this).val());
        });
        
        var $btn = $(this);
        setButtonLoading($btn, true);
        
        $.post(wecCategories.ajaxurl, {
            action: 'wec_assign_categories',
            nonce: wecCategories.nonce,
            subscriber_id: subscriberId,
            category_ids: categoryIds
        }, function(response){
            setButtonLoading($btn, false);
            if(response.success) {
                $('#edit-categories-modal').fadeOut(300);
                
                // Actualizar la celda de categorías en la tabla
                var row = $('tr[data-subscriber-id="' + subscriberId + '"]');
                var cell = row.find('.subscriber-categories-cell');
                
                if(response.data && response.data.html) {
                    cell.html(response.data.html);
                } else {
                    showLoading();
                    location.reload();
                }
            } else {
                alert(response.data || wecCategories.strings.error);
            }
        }).fail(function(){
            setButtonLoading($btn, false);
            alert('Error de conexión');
        });
    });
    
    // ====================
    // AGREGAR SUSCRIPTORES
    // ====================
    
    // Mostrar/ocultar formulario de agregar suscriptor
    $('#wec-show-add-subscriber').on('click', function(e){
        e.preventDefault();
        $('#wec-add-subscriber-form').slideToggle(300);
    });
    
    // Cancelar agregar suscriptor
    $('#cancel-add-subscriber').on('click', function(){
        $('#wec-add-subscriber-form').slideUp(300);
        $('#single-email').val('');
        $('#bulk-emails').val('');
        $('.single-category-checkbox').prop('checked', false);
    });
    
    // Agregar un solo suscriptor
    $('#add-single-subscriber').on('click', function(){
        var email = $('#single-email').val().trim();
        
        if(!email) {
            alert('Ingresa un correo electrónico');
            return;
        }
        
        // Validación básica de email
        var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if(!emailRegex.test(email)) {
            alert('Ingresa un correo electrónico válido');
            return;
        }
        
        var categoryIds = [];
        $('.single-category-checkbox:checked').each(function(){
            categoryIds.push($(this).val());
        });
        
        var $btn = $(this);
        setButtonLoading($btn, true);
        
        $.post(wecCategories.ajaxurl, {
            action: 'wec_add_subscriber',
            nonce: wecCategories.nonce,
            email: email,
            category_ids: categoryIds
        }, function(response){
            setButtonLoading($btn, false);
            if(response.success) {
                showLoading();
                alert(response.data.message || 'Suscriptor agregado correctamente');
                location.reload();
            } else {
                alert(response.data || 'Error al agregar suscriptor');
            }
        }).fail(function(){
            setButtonLoading($btn, false);
            alert('Error de conexión');
        });
    });
    
    // Agregar múltiples suscriptores
    $('#add-bulk-subscribers').on('click', function(){
        var emailsText = $('#bulk-emails').val().trim();
        
        if(!emailsText) {
            alert('Ingresa al menos un correo electrónico');
            return;
        }
        
        var emails = emailsText.split('\n').map(function(e){ return e.trim(); }).filter(function(e){ return e.length > 0; });
        
        if(emails.length === 0) {
            alert('No se encontraron correos válidos');
            return;
        }
        
        if(!confirm('¿Agregar ' + emails.length + ' correo(s)? Se agregarán sin categoría y con status vacío para validación posterior.')) {
            return;
        }
        
        var $btn = $(this);
        setButtonLoading($btn, true);
        
        $.post(wecCategories.ajaxurl, {
            action: 'wec_add_subscriber',
            nonce: wecCategories.nonce,
            emails: emails,
            bulk: true
        }, function(response){
            setButtonLoading($btn, false);
            if(response.success) {
                showLoading();
                alert(response.data.message || 'Suscriptores agregados correctamente');
                location.reload();
            } else {
                alert(response.data || 'Error al agregar suscriptores');
            }
        }).fail(function(){
            setButtonLoading($btn, false);
            alert('Error de conexión');
        });
    });
    
    // ====================
    // ELIMINAR SUSCRIPTOR
    // ====================
    
    // Eliminar un suscriptor
    $(document).on('click', '.delete-subscriber-btn', function(){
        var subscriberId = $(this).data('subscriber-id');
        var subscriberEmail = $(this).data('subscriber-email');
        
        if(!confirm('¿Estás seguro de eliminar el suscriptor "' + subscriberEmail + '"?\n\nEsta acción no se puede deshacer.')) {
            return;
        }
        
        var $btn = $(this);
        setButtonLoading($btn, true);
        
        $.post(wecCategories.ajaxurl, {
            action: 'wec_delete_subscriber',
            nonce: wecCategories.nonce,
            subscriber_id: subscriberId
        }, function(response){
            setButtonLoading($btn, false);
            if(response.success) {
                showLoading();
                alert('Suscriptor eliminado correctamente');
                location.reload();
            } else {
                alert(response.data || 'Error al eliminar suscriptor');
            }
        }).fail(function(){
            setButtonLoading($btn, false);
            alert('Error de conexión');
        });
    });
});
JS;
    }
    
    /**
     * Crear categorías por defecto
     */
    public function create_default_categories() {
        $defaults = array(
            array(
                'name' => 'General',
                'slug' => 'general',
                'description' => 'Suscriptores generales sin categoría específica',
                'color' => '#95a5a6'
            ),
            array(
                'name' => 'Ofertas',
                'slug' => 'ofertas',
                'description' => 'Suscriptores interesados en ofertas y promociones',
                'color' => '#e74c3c'
            ),
            array(
                'name' => 'Proveedores',
                'slug' => 'proveedores',
                'description' => 'Proveedores y contactos B2B',
                'color' => '#3498db'
            )
        );
        
        foreach ($defaults as $category) {
            $this->create_category($category);
        }
    }
    
    /**
     * Crear nueva categoría
     */
    public function create_category($data) {
        global $wpdb;
        
        $defaults = array(
            'name' => '',
            'slug' => '',
            'description' => '',
            'color' => '#3498db'
        );
        
        $data = wp_parse_args($data, $defaults);
        
        // Generar slug si no existe
        if (empty($data['slug'])) {
            $data['slug'] = sanitize_title($data['name']);
        }
        
        // Verificar si ya existe
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_categories} WHERE slug = %s",
            $data['slug']
        ));
        
        if ($exists) {
            return new WP_Error('category_exists', 'La categoría ya existe');
        }
        
        $result = $wpdb->insert(
            $this->table_categories,
            array(
                'name' => sanitize_text_field($data['name']),
                'slug' => sanitize_title($data['slug']),
                'description' => sanitize_textarea_field($data['description']),
                'color' => sanitize_hex_color($data['color'])
            ),
            array('%s', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'Error al crear la categoría');
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Obtener todas las categorías
     */
    public function get_categories($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'orderby' => 'name',
            'order' => 'ASC'
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $table_subscribers = $wpdb->prefix . 'wec_subscribers';
        
        // Obtener categorías con conteo correcto
        $categories = $wpdb->get_results(
            "SELECT c.*, 
            (SELECT COUNT(*) FROM {$this->table_subscriber_categories} 
             WHERE category_id = c.id) as explicit_count
            FROM {$this->table_categories} c
            ORDER BY {$args['orderby']} {$args['order']}"
        );
        
        // Para cada categoría, calcular el conteo real
        foreach ($categories as $cat) {
            if ($cat->slug === 'general') {
                // Para General: contar los que tienen General asignado + los que no tienen ninguna categoría
                $count_with_general = intval($cat->explicit_count);
                $count_without_categories = intval($wpdb->get_var(
                    "SELECT COUNT(DISTINCT s.id) 
                    FROM {$table_subscribers} s
                    WHERE s.status = 'subscribed'
                    AND s.id NOT IN (SELECT DISTINCT subscriber_id FROM {$this->table_subscriber_categories})"
                ));
                $cat->subscriber_count = $count_with_general + $count_without_categories;
            } else {
                // Para otras categorías: solo los que tienen esa categoría asignada
                $cat->subscriber_count = intval($cat->explicit_count);
            }
        }
        
        return $categories;
    }
    
    /**
     * Obtener categoría por ID
     */
    public function get_category($category_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_categories} WHERE id = %d",
            $category_id
        ));
    }
    
    /**
     * Asignar categorías a un suscriptor
     */
    public function assign_categories_to_subscriber($subscriber_id, $category_ids) {
        global $wpdb;
        
        // Eliminar asignaciones anteriores
        $wpdb->delete(
            $this->table_subscriber_categories,
            array('subscriber_id' => $subscriber_id),
            array('%d')
        );
        
        // Si no hay categorías, asignar "General" por defecto
        if (empty($category_ids)) {
            $general = $wpdb->get_var(
                "SELECT id FROM {$this->table_categories} WHERE slug = 'general' LIMIT 1"
            );
            if ($general) {
                $category_ids = array($general);
            }
        }
        
        // Insertar nuevas asignaciones
        foreach ((array)$category_ids as $category_id) {
            $wpdb->insert(
                $this->table_subscriber_categories,
                array(
                    'subscriber_id' => $subscriber_id,
                    'category_id' => $category_id
                ),
                array('%d', '%d')
            );
        }
        
        return true;
    }
    
    /**
     * Obtener categorías de un suscriptor
     */
    public function get_subscriber_categories($subscriber_id) {
        global $wpdb;
        
        $categories = $wpdb->get_results($wpdb->prepare(
            "SELECT c.* 
            FROM {$this->table_categories} c
            INNER JOIN {$this->table_subscriber_categories} sc ON c.id = sc.category_id
            WHERE sc.subscriber_id = %d
            ORDER BY c.name ASC",
            $subscriber_id
        ));
        
        // Si no tiene categorías asignadas, devolver "General" por defecto
        if (empty($categories)) {
            $general = $wpdb->get_row(
                "SELECT * FROM {$this->table_categories} WHERE slug = 'general' LIMIT 1"
            );
            
            if ($general) {
                return array($general);
            }
        }
        
        return $categories;
    }
    
    /**
     * Obtener suscriptores de una o varias categorías
     */
    public function get_subscribers_by_categories($category_ids, $status = 'subscribed') {
        global $wpdb;
        $table_subscribers = $wpdb->prefix . 'wec_subscribers';
        
        if (empty($category_ids)) {
            return array();
        }
        
        // Si es "all", obtener todos los suscriptores
        if ($category_ids === 'all' || (is_array($category_ids) && in_array('all', $category_ids))) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table_subscribers} WHERE status = %s ORDER BY email ASC",
                $status
            ));
        }
        
        $category_ids = array_map('intval', (array)$category_ids);
        $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
        
        $sql = "SELECT DISTINCT s.* 
                FROM {$table_subscribers} s
                INNER JOIN {$this->table_subscriber_categories} sc ON s.id = sc.subscriber_id
                WHERE sc.category_id IN ($placeholders)
                AND s.status = %s
                ORDER BY s.email ASC";
        
        $params = array_merge($category_ids, array($status));
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }
    
    /**
     * Renderizar página de categorías
     */
    public function render_categories_page() {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos suficientes');
        }
        
        $categories = $this->get_categories();
        ?>
        <div class="wrap">
            <h1>Gestión de Categorías de Suscriptores</h1>
            
            <div class="wec-category-form">
                <h2>Agregar/Editar Categoría</h2>
                <form id="wec-category-form">
                    <input type="hidden" id="category_id" name="category_id" value="">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="category_name">Nombre *</label></th>
                            <td>
                                <input type="text" id="category_name" name="name" class="regular-text" required 
                                       placeholder="Ej: Ofertas Especiales">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="category_slug">Slug *</label></th>
                            <td>
                                <input type="text" id="category_slug" name="slug" class="regular-text" required 
                                       pattern="[a-z0-9-]+" placeholder="ej: ofertas-especiales">
                                <p class="description">Solo letras minúsculas, números y guiones. Se genera automáticamente.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="category_description">Descripción</label></th>
                            <td>
                                <textarea id="category_description" name="description" class="large-text" rows="3" 
                                          placeholder="Descripción breve de esta categoría"></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="category_color">Color</label></th>
                            <td>
                                <input type="text" id="category_color" name="color" class="wec-color-picker" value="#3498db">
                                <p class="description">Color para identificar visualmente esta categoría</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary">Guardar Categoría</button>
                        <button type="button" id="wec-cancel-edit" class="button">Cancelar</button>
                    </p>
                </form>
            </div>
            
            <div class="wec-category-list">
                <h2 style="padding: 15px; margin: 0; border-bottom: 1px solid #f0f0f0;">Categorías Existentes</h2>
                <?php if (empty($categories)): ?>
                    <div class="wec-category-item">
                        <p>No hay categorías creadas. Crea tu primera categoría usando el formulario arriba.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($categories as $category): ?>
                        <div class="wec-category-item">
                            <div style="display: flex; align-items: center; flex: 1;">
                                <span class="wec-category-color" style="background: <?php echo esc_attr($category->color); ?>"></span>
                                <div class="wec-category-info" style="margin-left: 15px;">
                                    <div class="wec-category-name"><?php echo esc_html($category->name); ?></div>
                                    <div class="wec-category-description"><?php echo esc_html($category->description); ?></div>
                                </div>
                            </div>
                            <div class="wec-category-stats">
                                <strong><?php echo intval($category->subscriber_count); ?></strong> suscriptores
                            </div>
                            <div class="wec-category-actions">
                                <button class="button wec-edit-category" 
                                        data-id="<?php echo esc_attr($category->id); ?>"
                                        data-slug="<?php echo esc_attr($category->slug); ?>"
                                        data-color="<?php echo esc_attr($category->color); ?>">
                                    Editar
                                </button>
                                <?php if ($category->slug !== 'general'): ?>
                                    <button class="button wec-delete-category" 
                                            data-id="<?php echo esc_attr($category->id); ?>">
                                        Eliminar
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX: Guardar categoría
     */
    public function ajax_save_category() {
        check_ajax_referer('wec_categories_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes');
        }
        
        $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
        $data = array(
            'name' => sanitize_text_field($_POST['name']),
            'slug' => sanitize_title($_POST['slug']),
            'description' => sanitize_textarea_field($_POST['description']),
            'color' => sanitize_hex_color($_POST['color'])
        );
        
        global $wpdb;
        
        if ($category_id > 0) {
            // Actualizar
            $result = $wpdb->update(
                $this->table_categories,
                $data,
                array('id' => $category_id),
                array('%s', '%s', '%s', '%s'),
                array('%d')
            );
            
            if ($result === false) {
                wp_send_json_error('Error al actualizar la categoría');
            }
            
            wp_send_json_success(array(
                'message' => 'Categoría actualizada correctamente',
                'category_id' => $category_id
            ));
        } else {
            // Crear
            $result = $this->create_category($data);
            
            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            }
            
            wp_send_json_success(array(
                'message' => 'Categoría creada correctamente',
                'category_id' => $result
            ));
        }
    }
    
    /**
     * AJAX: Eliminar categoría
     */
    public function ajax_delete_category() {
        check_ajax_referer('wec_categories_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes');
        }
        
        $category_id = intval($_POST['category_id']);
        
        // No permitir eliminar categoría General
        global $wpdb;
        $category = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_categories} WHERE id = %d",
            $category_id
        ));
        
        if ($category && $category->slug === 'general') {
            wp_send_json_error('No se puede eliminar la categoría General');
        }
        
        $result = $wpdb->delete(
            $this->table_categories,
            array('id' => $category_id),
            array('%d')
        );
        
        if ($result === false) {
            wp_send_json_error('Error al eliminar la categoría');
        }
        
        wp_send_json_success('Categoría eliminada correctamente');
    }
    
    /**
     * AJAX: Obtener categorías de un suscriptor
     */
    public function ajax_get_subscriber_categories() {
        check_ajax_referer('wec_categories_nonce', 'nonce');
        
        $subscriber_id = intval($_POST['subscriber_id']);
        $categories = $this->get_subscriber_categories($subscriber_id);
        
        // Devolver solo los IDs para los checkboxes
        $category_ids = array();
        foreach ($categories as $cat) {
            $category_ids[] = $cat->id;
        }
        
        wp_send_json_success($category_ids);
    }
    
    /**
     * AJAX: Asignar categorías a suscriptor
     */
    public function ajax_assign_categories() {
        check_ajax_referer('wec_categories_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes');
        }
        
        $subscriber_id = intval($_POST['subscriber_id']);
        $category_ids = isset($_POST['category_ids']) ? array_map('intval', $_POST['category_ids']) : array();
        
        $result = $this->assign_categories_to_subscriber($subscriber_id, $category_ids);
        
        if ($result) {
            // Obtener las categorías actualizadas (incluye General por defecto si está vacío)
            $categories = $this->get_subscriber_categories($subscriber_id);
            
            // Generar el HTML de las categorías
            $html = '';
            foreach ($categories as $cat) {
                $html .= sprintf(
                    '<span class="wec-category-badge" style="background-color: %s;">%s</span> ',
                    esc_attr($cat->color),
                    esc_html($cat->name)
                );
            }
            
            wp_send_json_success(array(
                'message' => 'Categorías asignadas correctamente',
                'html' => $html
            ));
        } else {
            wp_send_json_error('Error al asignar categorías');
        }
    }
    
    /**
     * AJAX: Asignación masiva de categoría
     */
    public function ajax_bulk_assign_category() {
        check_ajax_referer('wec_categories_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes');
        }
        
        $subscriber_ids = isset($_POST['subscriber_ids']) ? array_map('intval', $_POST['subscriber_ids']) : array();
        $category_id = intval($_POST['category_id']);
        $bulk_action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : 'assign';
        
        if (empty($subscriber_ids) || !$category_id) {
            wp_send_json_error('Datos incompletos');
        }
        
        global $wpdb;
        $count = 0;
        
        if ($bulk_action === 'remove') {
            // Eliminar categoría de los suscriptores
            foreach ($subscriber_ids as $subscriber_id) {
                $result = $wpdb->delete(
                    $this->table_subscriber_categories,
                    array(
                        'subscriber_id' => $subscriber_id,
                        'category_id' => $category_id
                    ),
                    array('%d', '%d')
                );
                
                if ($result) {
                    $count++;
                }
            }
            
            wp_send_json_success(array(
                'message' => "Categoría eliminada de {$count} suscriptores",
                'count' => $count
            ));
        } else {
            // Asignar categoría a los suscriptores
            foreach ($subscriber_ids as $subscriber_id) {
                // Verificar si ya existe
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_subscriber_categories} 
                    WHERE subscriber_id = %d AND category_id = %d",
                    $subscriber_id, $category_id
                ));
                
                if (!$exists) {
                    $result = $wpdb->insert(
                        $this->table_subscriber_categories,
                        array(
                            'subscriber_id' => $subscriber_id,
                            'category_id' => $category_id
                        ),
                        array('%d', '%d')
                    );
                    
                    if ($result) {
                        $count++;
                    }
                }
            }
            
            wp_send_json_success(array(
                'message' => "Categoría asignada a {$count} suscriptores",
                'count' => $count
            ));
        }
    }
    
    /**
     * AJAX: Agregar nuevo(s) suscriptor(es)
     */
    public function ajax_add_subscriber() {
        check_ajax_referer('wec_categories_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes');
        }
        
        global $wpdb;
        $table_subscribers = $wpdb->prefix . 'wec_subscribers';
        
        $is_bulk = isset($_POST['bulk']) && $_POST['bulk'];
        
        if ($is_bulk) {
            // Agregar múltiples correos
            $emails = isset($_POST['emails']) ? array_map('sanitize_email', $_POST['emails']) : array();
            
            if (empty($emails)) {
                wp_send_json_error('No se proporcionaron correos');
            }
            
            $added = 0;
            $duplicates = 0;
            $invalid = 0;
            
            foreach ($emails as $email) {
                if (!is_email($email)) {
                    $invalid++;
                    continue;
                }
                
                // Verificar si ya existe
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_subscribers} WHERE email = %s",
                    $email
                ));
                
                if ($exists) {
                    $duplicates++;
                    continue;
                }
                
                // Insertar con status vacío (para validación posterior)
                $result = $wpdb->insert(
                    $table_subscribers,
                    array(
                        'email' => $email,
                        'status' => '', // Status vacío para validar después
                        'created_at' => current_time('mysql')
                    ),
                    array('%s', '%s', '%s')
                );
                
                if ($result) {
                    $added++;
                }
            }
            
            $message = "Agregados: {$added}";
            if ($duplicates > 0) $message .= " | Duplicados: {$duplicates}";
            if ($invalid > 0) $message .= " | Inválidos: {$invalid}";
            
            wp_send_json_success(array(
                'message' => $message,
                'added' => $added,
                'duplicates' => $duplicates,
                'invalid' => $invalid
            ));
            
        } else {
            // Agregar un solo correo con categorías opcionales
            $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
            $category_ids = isset($_POST['category_ids']) ? array_map('intval', $_POST['category_ids']) : array();
            
            if (empty($email) || !is_email($email)) {
                wp_send_json_error('Email inválido');
            }
            
            // Verificar si ya existe
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_subscribers} WHERE email = %s",
                $email
            ));
            
            if ($exists) {
                wp_send_json_error('El correo ya existe en la base de datos');
            }
            
            // SIEMPRE insertar con status vacío para validación posterior
            $result = $wpdb->insert(
                $table_subscribers,
                array(
                    'email' => $email,
                    'status' => '', // Status vacío SIEMPRE
                    'created_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s')
            );
            
            if (!$result) {
                wp_send_json_error('Error al agregar el suscriptor');
            }
            
            $subscriber_id = $wpdb->insert_id;
            
            // Asignar categorías si se seleccionaron
            if (!empty($category_ids)) {
                foreach ($category_ids as $category_id) {
                    $wpdb->insert(
                        $this->table_subscriber_categories,
                        array(
                            'subscriber_id' => $subscriber_id,
                            'category_id' => $category_id
                        ),
                        array('%d', '%d')
                    );
                }
                $msg = 'Suscriptor agregado correctamente con categorías. Recuerda validarlo desde "Limpieza Emails"';
            } else {
                $msg = 'Suscriptor agregado correctamente. Recuerda validarlo desde "Limpieza Emails"';
            }
            
            wp_send_json_success(array(
                'message' => $msg,
                'subscriber_id' => $subscriber_id
            ));
        }
    }
    
    /**
     * Render la página de gestión de suscriptores
     */
    public function render_subscribers_page() {
        global $wpdb;
        
        // Obtener filtros
        $category_filter = isset($_GET['category']) ? intval($_GET['category']) : 0;
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Paginación
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Obtener suscriptores
        $subscribers_data = $this->get_all_subscribers($category_filter, $status_filter, $search, $per_page, $offset);
        $subscribers = $subscribers_data['subscribers'];
        $total = $subscribers_data['total'];
        $total_pages = ceil($total / $per_page);
        
        // Obtener todas las categorías para los filtros
        $categories = $this->get_categories();
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Gestión de Suscriptores</h1>
            <a href="#" class="page-title-action" id="wec-show-add-subscriber">Agregar Nuevo</a>
            <hr class="wp-header-end">
            
            <!-- Formulario para agregar suscriptor (oculto por defecto) -->
            <div id="wec-add-subscriber-form" style="display: none; background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px; margin: 20px 0;">
                <h2>Agregar Nuevo Suscriptor</h2>
                
                <div style="margin-bottom: 20px;">
                    <h3>Opción 1: Agregar un solo correo</h3>
                    <div style="display: flex; gap: 10px; align-items: flex-start; flex-wrap: wrap;">
                        <div>
                            <label><strong>Email:</strong></label><br>
                            <input type="email" id="single-email" placeholder="ejemplo@correo.com" style="width: 300px;">
                        </div>
                        <div>
                            <label><strong>Categorías (opcional):</strong></label><br>
                            <div id="single-categories-selector" style="border: 1px solid #ddd; padding: 10px; border-radius: 4px; max-height: 150px; overflow-y: auto;">
                                <?php foreach ($categories as $cat): ?>
                                    <label style="display: block; margin: 5px 0;">
                                        <input type="checkbox" class="single-category-checkbox" value="<?php echo esc_attr($cat->id); ?>">
                                        <span class="wec-category-badge" style="background-color: <?php echo esc_attr($cat->color); ?>;">
                                            <?php echo esc_html($cat->name); ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div style="align-self: flex-end;">
                            <button type="button" class="button button-primary" id="add-single-subscriber">Agregar Suscriptor</button>
                        </div>
                    </div>
                </div>
                
                <hr>
                
                <div style="margin-bottom: 20px;">
                    <h3>Opción 2: Agregar múltiples correos</h3>
                    <p style="color: #666;">Ingresa un correo por línea. Los correos se agregarán SIN categoría y con status vacío para validación posterior.</p>
                    <textarea id="bulk-emails" rows="8" style="width: 100%; font-family: monospace;" placeholder="correo1@ejemplo.com&#10;correo2@ejemplo.com&#10;correo3@ejemplo.com"></textarea>
                    <button type="button" class="button button-primary" id="add-bulk-subscribers">Agregar Masivamente</button>
                </div>
                
                <button type="button" class="button" id="cancel-add-subscriber">Cancelar</button>
            </div>
            
            <!-- Filtros -->
            <div class="wec-filter-bar">
                <form method="get" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <input type="hidden" name="page" value="wec-subscribers">
                    
                    <label>
                        <strong>Buscar:</strong>
                        <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Email..." style="width: 200px;">
                    </label>
                    
                    <label>
                        <strong>Categoría:</strong>
                        <select name="category">
                            <option value="0">Todas las categorías</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo esc_attr($cat->id); ?>" <?php selected($category_filter, $cat->id); ?>>
                                    <?php echo esc_html($cat->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    
                    <button type="submit" class="button">Filtrar</button>
                    
                    <?php if ($category_filter || $search): ?>
                        <a href="?page=wec-subscribers" class="button">Limpiar filtros</a>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- Acciones masivas -->
            <div class="wec-bulk-actions">
                <label>
                    <input type="checkbox" id="select-all-subscribers"> <strong>Seleccionar todos</strong>
                </label>
                
                <label>
                    <strong>Acciones masivas:</strong>
                    <select id="bulk-action-selector">
                        <option value="">Seleccionar acción</option>
                        <option value="assign">Asignar a categoría</option>
                        <option value="remove">Eliminar de categoría</option>
                    </select>
                </label>
                
                <select id="bulk-category-selector" style="display:none;">
                    <option value="">Seleccionar categoría</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo esc_attr($cat->id); ?>">
                            <?php echo esc_html($cat->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <button type="button" class="button button-primary" id="apply-bulk-action">Aplicar</button>
                <span id="bulk-selected-count" style="margin-left: 10px; color: #666;"></span>
            </div>
            
            <!-- Tabla de suscriptores -->
            <table class="wec-subscribers-table">
                <thead>
                    <tr>
                        <th style="width: 30px;"><input type="checkbox" id="select-all-page"></th>
                        <th>Email</th>
                        <th>Estado</th>
                        <th>Categorías</th>
                        <th>Fecha de registro</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($subscribers)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px;">
                                <p style="color: #666; font-size: 14px;">No se encontraron suscriptores.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($subscribers as $sub): ?>
                            <tr data-subscriber-id="<?php echo esc_attr($sub->id); ?>">
                                <td>
                                    <input type="checkbox" class="subscriber-checkbox" value="<?php echo esc_attr($sub->id); ?>">
                                </td>
                                <td>
                                    <strong><?php echo esc_html($sub->email); ?></strong>
                                </td>
                                <td>
                                    <?php
                                    if (empty($sub->status)) {
                                        echo '<span style="color: #f59e0b;">⚠ Pendiente validación</span>';
                                    } elseif ($sub->status === 'subscribed') {
                                        echo '<span style="color: #16a34a;">✓ Activo</span>';
                                    } else {
                                        echo '<span style="color: #9ca3af;">' . esc_html($sub->status) . '</span>';
                                    }
                                    ?>
                                </td>
                                <td class="subscriber-categories-cell">
                                    <?php
                                    $sub_categories = $this->get_subscriber_categories($sub->id);
                                    foreach ($sub_categories as $cat) {
                                        echo sprintf(
                                            '<span class="wec-category-badge" style="background-color: %s;">%s</span> ',
                                            esc_attr($cat->color),
                                            esc_html($cat->name)
                                        );
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php echo esc_html(mysql2date('d/m/Y H:i', $sub->created_at)); ?>
                                </td>
                                <td class="wec-subscriber-actions">
                                    <button type="button" class="button button-small edit-categories-btn" 
                                            data-subscriber-id="<?php echo esc_attr($sub->id); ?>"
                                            data-subscriber-email="<?php echo esc_attr($sub->email); ?>">
                                        Editar categorías
                                    </button>
                                    <button type="button" class="button button-small delete-subscriber-btn" 
                                            data-subscriber-id="<?php echo esc_attr($sub->id); ?>"
                                            data-subscriber-email="<?php echo esc_attr($sub->email); ?>"
                                            style="color: #b32d2e;">
                                        Eliminar
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Paginación -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom" style="margin-top: 20px;">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo number_format($total); ?> elementos</span>
                        <?php
                        $base_url = add_query_arg(array(
                            'page' => 'wec-subscribers',
                            'category' => $category_filter,
                            's' => $search
                        ), admin_url('admin.php'));
                        
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%', $base_url),
                            'format' => '',
                            'current' => $current_page,
                            'total' => $total_pages,
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;'
                        ));
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Modal para editar categorías -->
        <div id="edit-categories-modal" class="wec-modal">
            <div class="wec-modal-content">
                <span class="wec-modal-close">&times;</span>
                <h2>Editar categorías de <span id="modal-subscriber-email"></span></h2>
                <input type="hidden" id="modal-subscriber-id">
                
                <div style="margin: 20px 0;">
                    <p><strong>Selecciona las categorías:</strong></p>
                    <?php foreach ($categories as $cat): ?>
                        <label style="display: block; margin: 10px 0;">
                            <input type="checkbox" class="category-checkbox" value="<?php echo esc_attr($cat->id); ?>">
                            <span class="wec-category-badge" style="background-color: <?php echo esc_attr($cat->color); ?>;">
                                <?php echo esc_html($cat->name); ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                
                <div style="margin-top: 20px; text-align: right;">
                    <button type="button" class="button" id="cancel-edit-categories">Cancelar</button>
                    <button type="button" class="button button-primary" id="save-subscriber-categories">Guardar</button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Obtener todos los suscriptores con filtros
     */
    private function get_all_subscribers($category_id = 0, $status = '', $search = '', $limit = 20, $offset = 0) {
        global $wpdb;
        
        $table_subscribers = $wpdb->prefix . 'wec_subscribers';
        $table_sub_cats = $this->table_subscriber_categories;
        
        // Mostrar suscriptores con status 'subscribed' O status vacío (para validar)
        $where = array("(s.status = 'subscribed' OR s.status = '')");
        
        // Filtro por categoría
        if ($category_id > 0) {
            // Obtener el slug de la categoría para verificar si es "General"
            $category = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_categories} WHERE id = %d",
                $category_id
            ));
            
            if ($category && $category->slug === 'general') {
                // Para categoría General: mostrar TODOS los suscriptores
                // (los que tienen General asignado O los que no tienen ninguna categoría)
                $where[] = "(
                    s.id IN (SELECT subscriber_id FROM {$table_sub_cats} WHERE category_id = {$category_id})
                    OR s.id NOT IN (SELECT DISTINCT subscriber_id FROM {$table_sub_cats})
                )";
            } else {
                // Para otras categorías: solo los que tienen esa categoría asignada
                $where[] = $wpdb->prepare(
                    "s.id IN (SELECT subscriber_id FROM {$table_sub_cats} WHERE category_id = %d)",
                    $category_id
                );
            }
        }
        
        // Búsqueda por email
        if (!empty($search)) {
            $where[] = $wpdb->prepare("s.email LIKE %s", '%' . $wpdb->esc_like($search) . '%');
        }
        
        $where_clause = implode(' AND ', $where);
        
        // Contar total
        $total = $wpdb->get_var("SELECT COUNT(DISTINCT s.id) FROM {$table_subscribers} s WHERE {$where_clause}");
        
        // Obtener suscriptores
        $subscribers = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT s.* FROM {$table_subscribers} s 
             WHERE {$where_clause} 
             ORDER BY s.created_at DESC 
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
        
        return array(
            'subscribers' => $subscribers,
            'total' => intval($total)
        );
    }

    /**
     * Renderizar el selector de categorías
     */
    public function render_category_selector($selected_ids = array(), $name = 'category_ids[]') {
        $categories = $this->get_categories();
        
        if (empty($categories)) {
            echo '<p>No hay categorías disponibles. <a href="' . admin_url('admin.php?page=wec-categories') . '">Crear categorías</a></p>';
            return;
        }
        
        $selected_ids = (array) $selected_ids;
        
        echo '<div class="wec-categories-selector">';
        foreach ($categories as $category) {
            $checked = in_array($category->id, $selected_ids) ? 'checked' : '';
            echo '<label style="display: block; margin: 5px 0;">';
            echo '<input type="checkbox" name="' . esc_attr($name) . '" value="' . esc_attr($category->id) . '" ' . $checked . '> ';
            echo '<span class="wec-category-color" style="background: ' . esc_attr($category->color) . '"></span> ';
            echo esc_html($category->name);
            echo '</label>';
        }
        echo '</div>';
    }
    
    /**
     * AJAX: Eliminar un suscriptor
     */
    public function ajax_delete_subscriber() {
        // Verificar nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wec_categories_nonce')) {
            wp_send_json_error('Nonce inválido');
            return;
        }

        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos');
            return;
        }

        // Validar subscriber_id
        if (!isset($_POST['subscriber_id'])) {
            wp_send_json_error('ID de suscriptor no proporcionado');
            return;
        }

        $subscriber_id = intval($_POST['subscriber_id']);
        
        if ($subscriber_id <= 0) {
            wp_send_json_error('ID de suscriptor inválido');
            return;
        }

        global $wpdb;
        
        // Verificar que el suscriptor existe
        $subscriber_table = $wpdb->prefix . 'wec_subscribers';
        $subscriber = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$subscriber_table} WHERE id = %d",
            $subscriber_id
        ));
        
        if (!$subscriber) {
            wp_send_json_error('Suscriptor no encontrado');
            return;
        }
        
        // Eliminar en cascada
        
        // 1. Eliminar asignaciones de categorías
        $subscriber_categories_table = $wpdb->prefix . 'wec_subscriber_categories';
        $wpdb->delete(
            $subscriber_categories_table,
            array('subscriber_id' => $subscriber_id),
            array('%d')
        );
        
        // 2. Eliminar historial de campañas (si existe la tabla)
        $job_items_table = $wpdb->prefix . 'wec_job_items';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$job_items_table}'") === $job_items_table) {
            $wpdb->delete(
                $job_items_table,
                array('subscriber_id' => $subscriber_id),
                array('%d')
            );
        }
        
        // 3. Eliminar el suscriptor
        $result = $wpdb->delete(
            $subscriber_table,
            array('id' => $subscriber_id),
            array('%d')
        );
        
        if ($result === false) {
            wp_send_json_error('Error al eliminar el suscriptor de la base de datos');
            return;
        }
        
        wp_send_json_success(array(
            'message' => 'Suscriptor eliminado correctamente'
        ));
    }
}

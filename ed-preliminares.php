<?php
/**
 * Plugin Name: ED Preliminares (Versión Final Simple)
 * Description: Captura preliminares (teléfono + datos parciales) desde el formulario principal de la landing sin necesidad de que el usuario envíe el formulario. Elimina el preliminar automáticamente si el formulario se envía con éxito.
 * Author: Emprendedor Digital
 * Version: 1.0.3
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class ED_Preliminares_Simple {

    private static $instance = null;
    public $table_name;

    /**
     * Singleton
     */
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor: engancha hooks
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ed_preliminares';

        // AJAX frontend (loggeado y no loggeado)
        add_action( 'wp_ajax_ed_guardar_preliminar',        array( $this, 'ajax_guardar_preliminar' ) );
        add_action( 'wp_ajax_nopriv_ed_guardar_preliminar', array( $this, 'ajax_guardar_preliminar' ) );

        // ✅ NUEVO: eliminar preliminar cuando el formulario se envía
        add_action( 'wp_ajax_ed_eliminar_preliminar',        array( $this, 'ajax_eliminar_preliminar' ) );
        add_action( 'wp_ajax_nopriv_ed_eliminar_preliminar', array( $this, 'ajax_eliminar_preliminar' ) );

        // Cron para limpiar
        add_action( 'ed_limpiar_preliminares_event', array( $this, 'limpiar_preliminares_antiguos' ) );

        // Menú admin
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

        // JS inline en el footer del frontend
        add_action( 'wp_footer', array( $this, 'imprimir_js_inline' ) );
    }

    /**
     * Al activar el plugin: crear tabla + programar cron diario
     */
    public static function on_activation() {
        $inst = self::get_instance();
        $inst->crear_tabla();

        if ( ! wp_next_scheduled( 'ed_limpiar_preliminares_event' ) ) {
            wp_schedule_event( time(), 'daily', 'ed_limpiar_preliminares_event' );
        }
    }

    /**
     * Crear tabla ed_preliminares si no existe
     */
    public function crear_tabla() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(190) DEFAULT NULL,
            email VARCHAR(190) DEFAULT NULL,
            telefono VARCHAR(50) NOT NULL,
            landing_url TEXT NULL,
            ultima_actividad DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY telefono (telefono(20))
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * AJAX: guardar preliminar
     * Regla: solo guarda si teléfono tiene al menos 10 dígitos
     */
    public function ajax_guardar_preliminar() {
        // No matamos por nonce para evitar problemas de caché
        $nombre       = isset($_POST['nombre'])   ? sanitize_text_field( $_POST['nombre'] )   : '';
        $email        = isset($_POST['email'])    ? sanitize_email( $_POST['email'] )         : '';
        $telefono_raw = isset($_POST['telefono']) ? sanitize_text_field( $_POST['telefono'] ) : '';
        $landing      = isset($_POST['landing'])  ? esc_url_raw( $_POST['landing'] )          : '';

        // Normalizar teléfono: solo dígitos
        $telefono_digits = preg_replace('/\D+/', '', $telefono_raw);

        // Debe tener mínimo 10 dígitos
        if ( strlen($telefono_digits) < 10 ) {
            wp_send_json_success( array(
                'status' => 'ignored',
                'reason' => 'telefono_incompleto',
                'tel'    => $telefono_digits,
            ) );
        }

        if ( empty($landing) ) {
            $landing = home_url( add_query_arg( array(), $_SERVER['REQUEST_URI'] ?? '' ) );
        }

        global $wpdb;
        $table_name = $this->table_name;

        // Aseguramos que la tabla exista por si acaso
        $this->crear_tabla();

        $ahora = current_time( 'mysql' );

        // ¿Ya existe registro con ese teléfono + landing?
        $existente_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE telefono = %s AND landing_url = %s LIMIT 1",
            $telefono_digits,
            $landing
        ) );

        if ( $existente_id ) {
            $wpdb->update(
                $table_name,
                array(
                    'nombre'           => $nombre,
                    'email'            => $email,
                    'ultima_actividad' => $ahora,
                ),
                array( 'id' => $existente_id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $table_name,
                array(
                    'nombre'           => $nombre,
                    'email'            => $email,
                    'telefono'         => $telefono_digits,
                    'landing_url'      => $landing,
                    'ultima_actividad' => $ahora,
                ),
                array( '%s', '%s', '%s', '%s', '%s' )
            );
        }

        wp_send_json_success( array(
            'status' => 'saved',
            'tel'    => $telefono_digits,
        ) );
    }

    /**
     * ✅ NUEVO: AJAX eliminar preliminar
     * Se usa cuando el formulario se envía (para que no queden "preliminares" de gente que sí compró/contactó).
     * Borra por teléfono + landing_url.
     */
public function ajax_eliminar_preliminar() {
    $telefono_raw     = isset($_POST['telefono']) ? sanitize_text_field( $_POST['telefono'] ) : '';
    $landing          = isset($_POST['landing']) ? esc_url_raw( $_POST['landing'] ) : '';
    $landing_original = isset($_POST['landing_original']) ? esc_url_raw( $_POST['landing_original'] ) : '';

    $telefono_digits = preg_replace('/\D+/', '', $telefono_raw);

    if ( strlen($telefono_digits) < 10 ) {
        wp_send_json_success(array('status'=>'ignored','reason'=>'telefono_incompleto'));
    }

    // ✅ Si llega la landing original, usamos esa (esto arregla el caso de página de gracias)
    if ( !empty($landing_original) ) {
        $landing = $landing_original;
    }

    if ( empty($landing) ) {
        $landing = home_url( add_query_arg( array(), $_SERVER['REQUEST_URI'] ?? '' ) );
    }

    global $wpdb;
    $table_name = $this->table_name;

    $this->crear_tabla();

    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$table_name} WHERE telefono = %s AND landing_url = %s",
        $telefono_digits,
        $landing
    ) );

    wp_send_json_success(array('status'=>'deleted','tel'=>$telefono_digits));
}


    /**
     * Borrar preliminares con más de 13 días
     */
    public function limpiar_preliminares_antiguos() {
        global $wpdb;
        $table_name = $this->table_name;

        $timestamp_local = current_time( 'timestamp' );
        $limite = date( 'Y-m-d H:i:s', strtotime( '-13 days', $timestamp_local ) );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE ultima_actividad < %s",
                $limite
            )
        );
    }

    /**
     * Menú de administración para ver preliminares
     */
    public function add_admin_menu() {
        add_menu_page(
            'Preliminares ED',
            'Preliminares ED',
            'manage_options',
            'ed-preliminares-simple',
            array( $this, 'render_admin_page' ),
            'dashicons-filter',
            26
        );
    }

    /**
     * Página de administración: listado de preliminares (últimos 200)
     */
    public function render_admin_page() {
        if ( ! current_user_can('manage_options') ) {
            return;
        }

        global $wpdb;
        $registros = $wpdb->get_results( "SELECT * FROM {$this->table_name} ORDER BY ultima_actividad DESC LIMIT 200" );
        ?>
        <div class="wrap">
            <h1>Preliminares ED</h1>
            <p>Usuarios que dejaron sus datos parcialmente en las landings. Si el usuario envía el formulario, el preliminar se elimina automáticamente.</p>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Teléfono</th>
                        <th>Email</th>
                        <th>Landing</th>
                        <th>Última actividad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( $registros ) : ?>
                        <?php foreach ( $registros as $r ) : ?>
                            <tr>
                                <td><?php echo esc_html( $r->nombre ); ?></td>
                                <td><?php echo esc_html( $r->telefono ); ?></td>
                                <td><?php echo esc_html( $r->email ); ?></td>
                                <td>
                                    <?php if ( ! empty( $r->landing_url ) ) : ?>
                                        <a href="<?php echo esc_url( $r->landing_url ); ?>" target="_blank">Ver landing</a>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $r->ultima_actividad ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="5">Aún no hay preliminares guardados.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <p style="margin-top:12px; font-size:12px; opacity:0.7;">
                *Los registros se eliminan automáticamente después de 13 días.
            </p>
        </div>
        <?php
    }

    /**
     * JS inline: engancha el PRIMER <form> de la página
     * - Guarda preliminar cuando el teléfono llega a 10+ dígitos
     * - ✅ Elimina preliminar cuando el form se envía con éxito (o por submit como fallback)
     */
   public function imprimir_js_inline() {
    if ( is_admin() ) return;

    $ajax_url = admin_url( 'admin-ajax.php' );
    ?>
<script>
(function(){
  var ajaxUrl = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";

  function toQS(obj){
    var p = new URLSearchParams();
    Object.keys(obj).forEach(function(k){ p.append(k, obj[k]); });
    return p.toString();
  }

  function beacon(data){
    try{
      var blob = new Blob([toQS(data)], {type:'application/x-www-form-urlencoded; charset=UTF-8'});
      if (navigator.sendBeacon) return navigator.sendBeacon(ajaxUrl, blob);
    }catch(e){}
    // fallback fetch
    try{
      fetch(ajaxUrl,{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body: toQS(data),
        keepalive:true
      });
      return true;
    }catch(e){}
    return false;
  }

  // ✅ PLAN B: si estamos en la página de gracias, intentamos borrar con lo guardado
  function intentarBorradoDesdeGracias(){
    try{
      var tel = localStorage.getItem('ed_pre_tel') || '';
      var landingOriginal = localStorage.getItem('ed_pre_landing') || '';
      if (tel && tel.length >= 10 && landingOriginal){
        beacon({
          action: 'ed_eliminar_preliminar',
          telefono: tel,
          landing_original: landingOriginal
        });
        // limpiamos para no repetir
        localStorage.removeItem('ed_pre_tel');
        localStorage.removeItem('ed_pre_landing');
      }
    }catch(e){}
  }

  function iniciar(){
    var form = document.querySelector('form');
    console.log('ED Preliminares: form encontrado =', form ? 'sí' : 'no');

    // Si no hay form (muchas páginas de gracias no lo tienen), igual intentamos el plan B
    if(!form){
      intentarBorradoDesdeGracias();
      return;
    }

    var timeoutId = null;
    var lastValidPhone = '';

    function getField(sel){
      try{ var el = form.querySelector(sel); return el ? (el.value || '') : ''; }catch(e){ return ''; }
    }

    function getTelDigits(){
      var tel = getField(
        '[name^="form_fields[telefono]"], ' +
        '#telefono, ' +
        'input[type="tel"], ' +
        'input[name*="tel"], ' +
        'input[name*="whats"], ' +
        'input[name*="whatsapp"]'
      );
      return (tel || '').replace(/\D+/g,'');
    }

    function guardarPreliminar(){
      var tel = getTelDigits();
      if(!tel || tel.length < 10) return;

      lastValidPhone = tel;

      var nombre = getField('[name^="form_fields[nombre]"], #nombre');
      var email  = getField('[name^="form_fields[email]"], #email');

      // guardamos (fetch normal)
      fetch(ajaxUrl,{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body: toQS({
          action:'ed_guardar_preliminar',
          telefono: tel,
          nombre: nombre,
          email: email,
          landing: window.location.href
        }),
        keepalive:true
      }).then(r=>r.text()).then(t=>console.log('ED Preliminares: guardado', t)).catch(()=>{});
    }

    function programarGuardado(){
      if(timeoutId) clearTimeout(timeoutId);
      timeoutId = setTimeout(guardarPreliminar, 1200);
    }

    form.addEventListener('input', function(e){
      var tel = getTelDigits();
      if(tel && tel.length >= 10) lastValidPhone = tel;
      programarGuardado();
    });

    // ✅ CLAVE: al enviar, borramos antes del redirect (beacon)
    form.addEventListener('submit', function(){
      var tel = getTelDigits();
      if(tel && tel.length >= 10) lastValidPhone = tel;

      // guardamos datos para plan B (por si el beacon no alcanza)
      try{
        if(lastValidPhone && lastValidPhone.length >= 10){
          localStorage.setItem('ed_pre_tel', lastValidPhone);
          localStorage.setItem('ed_pre_landing', window.location.href);
        }
      }catch(e){}

      // borrar YA, usando landing_original (la landing actual)
      beacon({
        action:'ed_eliminar_preliminar',
        telefono: lastValidPhone,
        landing_original: window.location.href
      });

      // extra: por si el navegador dispara pagehide rápido
      window.addEventListener('pagehide', function(){
        beacon({
          action:'ed_eliminar_preliminar',
          telefono: lastValidPhone,
          landing_original: window.location.href
        });
      }, { once:true });
    });

    // Por si esta página también es una gracias (caso raro), intentamos igual
    intentarBorradoDesdeGracias();
  }

  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', function(){ setTimeout(iniciar, 300); });
  } else {
    setTimeout(iniciar, 300);
  }
})();
</script>

    <?php
}


}

ED_Preliminares_Simple::get_instance();
register_activation_hook( __FILE__, array( 'ED_Preliminares_Simple', 'on_activation' ) );

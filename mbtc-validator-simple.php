<?php
/**
 * Plugin Name: mBTC Validator Simple
 * Description: Permet aux validateurs connectés de soumettre des preuves à l'orchestrateur mBTC via une API sécurisée.
 * Version: 2.0.2 (Ajout logs et correction test AJAX)
 * Author: Alain St-Germain
 * License: GPL v3 or later
 * Text Domain: mbtc-validator
 */

if (!defined('ABSPATH')) exit;

class MBTC_Validator_Simple_Plugin {

    private $ajax_url;
    
    public function __construct() {
        $this->ajax_url = admin_url('admin-ajax.php');
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_shortcode('mbtc_submit_proof', [$this, 'submit_proof_shortcode']);
        
        // Actions AJAX
        add_action('wp_ajax_mbtc_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_mbtc_upload_proof', [$this, 'ajax_upload_proof']);
        add_action('wp_ajax_mbtc_view_logs', [$this, 'ajax_view_logs']);
        add_action('wp_ajax_mbtc_clear_logs', [$this, 'ajax_clear_logs']);
        add_action('wp_ajax_mbtc_test_manual_upload', [$this, 'ajax_test_manual_upload']);
        add_action('wp_ajax_mbtc_log_ajaxurl_status', [$this, 'ajax_log_ajaxurl_status']);
        
        // Actions AJAX pour le test sans authentification (utilisé dans le shortcode)
        add_action('wp_ajax_mbtc_test_connection_no_auth', [$this, 'ajax_test_connection_no_auth']);
        add_action('wp_ajax_nopriv_mbtc_test_connection_no_auth', [$this, 'ajax_test_connection_no_auth']);
        
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
        
        // Scripts pour l'admin (utilise notre propre variable)
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // Scripts pour le front-end (shortcode)
        add_action('wp_enqueue_scripts', [$this, 'enqueue_front_scripts']);
        
        // INJECTION GLOBALE AJAXURL - PRIORITÉ EXTRÊME
        add_action('wp_print_scripts', [$this, 'inject_ajaxurl_pre_scripts'], -999);
        add_action('admin_print_scripts', [$this, 'inject_ajaxurl_pre_scripts'], -999);
        add_action('wp_footer', [$this, 'inject_ajaxurl_post_scripts'], 1);
        add_action('admin_footer', [$this, 'inject_ajaxurl_post_scripts'], 1);
        
        // Diagnostic silencieux en JS (envoi AJAX si manquant)
        add_action('wp_footer', [$this, 'diagnostic_ajaxurl_js'], 999);
        add_action('admin_footer', [$this, 'diagnostic_ajaxurl_js'], 999);
    }

    /**
     * Injection avant tous les scripts (dans le head)
     */
    public function inject_ajaxurl_pre_scripts() {
        $this->debug_log("Injection ajaxurl pré-scripts (head) avec URL : " . $this->ajax_url);
        ?>
        <script type="text/javascript">
        if (typeof ajaxurl === 'undefined') {
            var ajaxurl = '<?php echo esc_js($this->ajax_url); ?>';
            window.ajaxurl = ajaxurl;
            if (window.console) console.log('mBTC: ajaxurl défini en pré-script');
        }
        </script>
        <?php
    }

    /**
     * Injection après tous les scripts (dans le footer)
     */
    public function inject_ajaxurl_post_scripts() {
        $this->debug_log("Injection ajaxurl post-scripts (footer) avec URL : " . $this->ajax_url);
        ?>
        <script type="text/javascript">
        (function() {
            if (typeof ajaxurl === 'undefined') {
                window.ajaxurl = '<?php echo esc_js($this->ajax_url); ?>';
                if (window.console) console.log('mBTC: ajaxurl défini en post-script');
            }
        })();
        </script>
        <?php
    }

    /**
     * Diagnostic JavaScript : envoie une requête AJAX silencieuse pour signaler si ajaxurl est manquant
     * (uniquement si le mode debug est activé)
     */
    public function diagnostic_ajaxurl_js() {
        if (!get_option('mbtc_debug_log', 0)) {
            return; // Ne pas alourdir si debug désactivé
        }
        ?>
        <script type="text/javascript">
        (function() {
            setTimeout(function() {
                if (typeof ajaxurl === 'undefined') {
                    console.warn('mBTC: ajaxurl est indéfini !');
                    // Envoi silencieux au log via AJAX
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '<?php echo esc_js($this->ajax_url); ?>', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.send('action=mbtc_log_ajaxurl_status&status=missing');
                } else {
                    console.log('mBTC: ajaxurl OK =', ajaxurl);
                    // Optionnel : envoyer un log OK
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '<?php echo esc_js($this->ajax_url); ?>', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.send('action=mbtc_log_ajaxurl_status&status=present');
                }
            }, 500); // Laisser le temps aux scripts de se charger
        })();
        </script>
        <?php
    }

    /**
     * Action AJAX pour enregistrer le statut de ajaxurl dans les logs
     */
    public function ajax_log_ajaxurl_status() {
        if (!get_option('mbtc_debug_log', 0)) {
            wp_die(); // Silencieux si debug désactivé
        }
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'unknown';
        $this->debug_log("Statut ajaxurl côté client : " . $status);
        wp_die();
    }

    /**
     * Scripts pour l'administration (utilise mbtc_admin_ajax)
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_mbtc-validator') {
            return;
        }
        
        wp_enqueue_script('mbtc-admin', false, ['jquery'], '3.6.1', true);
        wp_localize_script('mbtc-admin', 'mbtc_admin_ajax', [
            'ajaxurl' => $this->ajax_url,
            'nonce'   => wp_create_nonce('mbtc_ajax_nonce')
        ]);
    }

    /**
     * Scripts pour le front-end (shortcode)
     */
    public function enqueue_front_scripts() {
        global $post;
        if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'mbtc_submit_proof')) {
            $this->debug_log("Front scripts not enqueued – shortcode not found on page ID " . ($post->ID ?? 'unknown'));
            return;
        }
        $this->debug_log("Front scripts enqueued – shortcode present on page ID " . $post->ID);
        
        wp_enqueue_script('mbtc-front', false, ['jquery'], '3.6.1', true);
        wp_localize_script('mbtc-front', 'mbtc_front_ajax', [
            'ajaxurl' => $this->ajax_url,
            'nonce'   => wp_create_nonce('mbtc_ajax_nonce'),
            'debug'   => defined('WP_DEBUG') && WP_DEBUG
        ]);
    }

    public function add_admin_menu() {
        add_options_page(
            __('Configuration mBTC Validator', 'mbtc-validator'),
            __('mBTC Validator', 'mbtc-validator'),
            'manage_options',
            'mbtc-validator',
            [$this, 'render_admin_page']
        );
    }

    public function register_settings() {
        register_setting('mbtc_options', 'mbtc_api_url', 'esc_url_raw');
        register_setting('mbtc_options', 'mbtc_api_token', 'sanitize_text_field');
        register_setting('mbtc_options', 'mbtc_debug_log', 'intval');
    }

    public function render_admin_page() {
        $log_file = WP_CONTENT_DIR . '/mbtc-debug.log';
        ?>
        <div class="wrap">
            <h1><?php _e('Configuration mBTC Validator', 'mbtc-validator'); ?></h1>
            
            <div class="notice notice-info">
                <p><strong>Diagnostic AJAX :</strong> URL = <code><?php echo esc_url($this->ajax_url); ?></code></p>
            </div>
            
            <form method="post" action="options.php">
                <?php settings_fields('mbtc_options'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="mbtc_api_url"><?php _e('URL de l\'API', 'mbtc-validator'); ?></label></th>
                        <td>
                            <input type="url" id="mbtc_api_url" name="mbtc_api_url" value="<?php echo esc_attr(get_option('mbtc_api_url', 'http://localhost:5000/submit')); ?>" class="regular-text" />
                            <p class="description"><?php _e('Exemple : http://192.168.1.100:5000/submit', 'mbtc-validator'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mbtc_api_token"><?php _e('Token secret', 'mbtc-validator'); ?></label></th>
                        <td>
                            <input type="text" id="mbtc_api_token" name="mbtc_api_token" value="<?php echo esc_attr(get_option('mbtc_api_token', '')); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mbtc_debug_log"><?php _e('Mode debug', 'mbtc-validator'); ?></label></th>
                        <td>
                            <input type="checkbox" id="mbtc_debug_log" name="mbtc_debug_log" value="1" <?php checked(get_option('mbtc_debug_log', 0)); ?> />
                            <label for="mbtc_debug_log"><?php _e('Activer les logs détaillés dans', 'mbtc-validator'); ?> <code><?php echo $log_file; ?></code></label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <hr>
            <h2><?php _e('Test de connexion', 'mbtc-validator'); ?></h2>
            <button id="mbtc-test-connection" class="button"><?php _e('Tester la connexion', 'mbtc-validator'); ?></button>
            <span id="mbtc-test-result" style="margin-left:10px;"></span>

            <h2><?php _e('Test manuel d\'upload', 'mbtc-validator'); ?></h2>
            <button id="mbtc-test-upload" class="button"><?php _e('Tester upload manuel', 'mbtc-validator'); ?></button>
            <span id="mbtc-test-upload-result" style="margin-left:10px;"></span>

            <hr>
            <h2><?php _e('Diagnostic', 'mbtc-validator'); ?></h2>
            <button id="mbtc-view-logs" class="button"><?php _e('Afficher les logs récents', 'mbtc-validator'); ?></button>
            <button id="mbtc-clear-logs" class="button"><?php _e('Effacer les logs', 'mbtc-validator'); ?></button>
            <div id="mbtc-logs-display" style="margin-top:10px; background:#f1f1f1; padding:10px; max-height:400px; overflow:auto; display:none;"></div>
            
            <script>
            jQuery(document).ready(function($) {
                if (typeof mbtc_admin_ajax === 'undefined') {
                    console.error('mBTC: mbtc_admin_ajax non trouvé');
                    return;
                }
                
                var ajaxUrl = mbtc_admin_ajax.ajaxurl;
                var nonce = mbtc_admin_ajax.nonce;
                
                $('#mbtc-test-connection').click(function() {
                    $.post(ajaxUrl, { action: 'mbtc_test_connection', _ajax_nonce: nonce })
                        .done(function(response) {
                            $('#mbtc-test-result').html(response.success ? '<span style="color:green">✓ '+response.data+'</span>' : '<span style="color:red">✗ '+response.data+'</span>');
                        })
                        .fail(function() {
                            $('#mbtc-test-result').html('<span style="color:red">✗ Erreur de communication</span>');
                        });
                });

                $('#mbtc-test-upload').click(function() {
                    $.post(ajaxUrl, { action: 'mbtc_test_manual_upload', _ajax_nonce: nonce })
                        .done(function(response) {
                            $('#mbtc-test-upload-result').html(response.success ? '<span style="color:green">✓ '+response.data+'</span>' : '<span style="color:red">✗ '+response.data+'</span>');
                        })
                        .fail(function() {
                            $('#mbtc-test-upload-result').html('<span style="color:red">✗ Erreur de communication</span>');
                        });
                });

                $('#mbtc-view-logs').click(function() {
                    $.post(ajaxUrl, { action: 'mbtc_view_logs', _ajax_nonce: nonce }, function(response) {
                        $('#mbtc-logs-display').html(response.success ? '<pre>'+response.data+'</pre>' : '<p style="color:red">'+response.data+'</p>').show();
                    });
                });

                $('#mbtc-clear-logs').click(function() {
                    if (confirm('<?php _e('Effacer tous les logs ?', 'mbtc-validator'); ?>')) {
                        $.post(ajaxUrl, { action: 'mbtc_clear_logs', _ajax_nonce: nonce }, function(response) {
                            $('#mbtc-logs-display').html(response.success ? '<p style="color:green">'+response.data+'</p>' : '<p style="color:red">'+response.data+'</p>').show();
                        });
                    }
                });
            });
            </script>
        </div>
        <?php
    }

    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=mbtc-validator') . '">' . __('Réglages', 'mbtc-validator') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function submit_proof_shortcode() {
        if (!is_user_logged_in()) {
            return '<p>' . __('Vous devez être connecté pour soumettre une preuve.', 'mbtc-validator') . '</p>';
        }

        if (!wp_script_is('mbtc-front', 'enqueued')) {
            wp_enqueue_script('mbtc-front');
        }

        ob_start();
        ?>
        <style>
        .mbtc-form { max-width:500px; margin:20px 0; padding:20px; background:#f9f9f9; border:1px solid #ddd; border-radius:8px; }
        .mbtc-form input[type="file"] { width:100%; padding:10px; margin-bottom:15px; border:1px solid #ccc; border-radius:4px; box-sizing: border-box;}
        .mbtc-form input[type="submit"] { background:#4CAF50; color:white; padding:12px 20px; border:none; border-radius:4px; cursor:pointer; font-size:16px; width:100%; }
        .mbtc-form input[type="submit"]:hover { background:#45a049; }
        #mbtc-progress-container { margin-top:15px; width:100%; background-color:#f0f0f0; border-radius:5px; display:none; }
        #mbtc-progress-bar { width:0%; height:24px; background-color:#4CAF50; border-radius:5px; transition:width 0.2s ease; text-align:center; line-height:24px; color:white; font-weight:bold; }
        #mbtc-message { margin-top:15px; }
        .mbtc-debug { font-size:12px; color:#666; margin-top:15px; border-top:1px solid #ddd; padding-top:10px; background: #fff; padding: 10px; border-radius: 4px;}
        .status-ok { color: green; font-weight: bold; }
        .status-err { color: red; font-weight: bold; }
        </style>

        <div class="mbtc-form">
            <h3><?php _e('Soumission de preuve mBTC', 'mbtc-validator'); ?></h3>
            <form id="mbtc-upload-form" enctype="multipart/form-data">
                <input type="hidden" name="mbtc_nonce" value="<?php echo wp_create_nonce('mbtc_ajax_nonce'); ?>">
                <label for="proof_file"><?php _e('Fichier de preuve (.json, .gpg)', 'mbtc-validator'); ?>:</label>
                <input type="file" id="proof_file" name="proof_file" accept=".json,.gpg" required>
                <br><br>
                <input type="submit" value="<?php _e('Soumettre la preuve', 'mbtc-validator'); ?>">
            </form>
            
            <div id="mbtc-progress-container"><div id="mbtc-progress-bar">0%</div></div>
            <div id="mbtc-message"></div>
            
            <div class="mbtc-debug">
                <strong><?php _e('État du système:', 'mbtc-validator'); ?></strong>
                <div id="mbtc-js-status"><?php _e('Vérification...', 'mbtc-validator'); ?></div>
                <div id="mbtc-debug-vars"></div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            if (typeof mbtc_front_ajax === 'undefined') {
                window.mbtc_front_ajax = {
                    ajaxurl: '<?php echo esc_js($this->ajax_url); ?>',
                    nonce: '<?php echo wp_create_nonce('mbtc_ajax_nonce'); ?>'
                };
                console.warn('mBTC: mbtc_front_ajax non trouvé, fallback appliqué');
            }
            
            var ajaxUrl = mbtc_front_ajax.ajaxurl;
            var nonce = mbtc_front_ajax.nonce;
            
            var statusDiv = $('#mbtc-js-status');
            var debugDiv = $('#mbtc-debug-vars');
            debugDiv.html('AJAX URL: ' + ajaxUrl + '<br>');
            debugDiv.append('Nonce présent: ' + (nonce ? 'Oui' : 'Non') + '<br>');
            
            // Test AJAX avec action dédiée (publique)
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: { action: 'mbtc_test_connection_no_auth', _ajax_nonce: nonce },
                timeout: 5000,
                success: function(resp) {
                    statusDiv.html('<span class="status-ok">✓ AJAX fonctionnel</span>');
                    debugDiv.append('Test AJAX réussi : ' + (resp.data || 'OK') + '<br>');
                },
                error: function(xhr, status, error) {
                    var errorMsg = '⚠ AJAX non disponible : ' + error;
                    if (xhr.status === 404) errorMsg = '⚠ AJAX non disponible : 404 (admin-ajax.php introuvable)';
                    else if (xhr.status === 403) errorMsg = '⚠ AJAX non disponible : 403 (accès refusé)';
                    else if (status === 'timeout') errorMsg = '⚠ AJAX non disponible : timeout (le serveur ne répond pas)';
                    statusDiv.html('<span class="status-err">' + errorMsg + '</span>');
                    debugDiv.append('Erreur AJAX : ' + error + '<br>');
                    debugDiv.append('Statut : ' + xhr.status + ' - ' + xhr.statusText + '<br>');
                    if (xhr.responseText) {
                        debugDiv.append('Premiers 200 caractères de la réponse : ' + xhr.responseText.substring(0, 200) + '<br>');
                    }
                }
            });
            
            $('#mbtc-upload-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = new FormData(this);
                formData.append('action', 'mbtc_upload_proof');
                formData.append('mbtc_nonce', nonce);
                
                $('#mbtc-progress-container').show();
                $('#mbtc-progress-bar').css('width', '0%').text('0%');
                $('#mbtc-message').empty().html('<em>Envoi en cours...</em>');
                
                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    xhr: function() {
                        var xhr = new XMLHttpRequest();
                        xhr.upload.addEventListener('progress', function(evt) {
                            if (evt.lengthComputable) {
                                var p = Math.round((evt.loaded / evt.total) * 100);
                                $('#mbtc-progress-bar').css('width', p + '%').text(p + '%');
                            }
                        });
                        return xhr;
                    },
                    success: function(resp) {
                        $('#mbtc-progress-bar').css('width', '100%').text('100%');
                        if (resp.success) {
                            $('#mbtc-message').html('<div style="background:#d4edda;color:#155724;padding:15px;border-radius:4px;">✅ ' + resp.data + '</div>');
                            $('#mbtc-upload-form')[0].reset();
                        } else {
                            $('#mbtc-message').html('<div style="background:#f8d7da;color:#721c24;padding:15px;border-radius:4px;">❌ ' + resp.data + '</div>');
                        }
                        setTimeout(function() {
                            $('#mbtc-progress-container').fadeOut();
                        }, 4000);
                    },
                    error: function(xhr, status, error) {
                        $('#mbtc-progress-container').fadeOut();
                        $('#mbtc-message').html('<div style="background:#f8d7da;color:#721c24;padding:15px;border-radius:4px;">❌ Erreur: ' + error + '</div>');
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    // Méthode AJAX de test sans authentification
    public function ajax_test_connection_no_auth() {
        $this->debug_log("AJAX test connection_no_auth called");
        wp_send_json_success(__('Connexion AJAX établie.', 'mbtc-validator'));
    }

    public function ajax_test_connection() {
        $this->debug_log("AJAX test connection called");
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permissions insuffisantes.', 'mbtc-validator'));
        }
        $api_url = get_option('mbtc_api_url');
        $token = get_option('mbtc_api_token');
        if (empty($api_url) || empty($token)) {
            wp_send_json_error(__('Configuration incomplète.', 'mbtc-validator'));
        }
        $health_url = preg_replace('/\/submit$/', '/health', $api_url);
        if ($health_url === $api_url) $health_url = rtrim($api_url, '/') . '/health';

        $this->debug_log("Test connexion vers $health_url");
        $response = wp_remote_get($health_url, [
            'headers' => ['X-Token' => $token],
            'timeout' => 10,
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            $this->debug_log("Test connexion error: " . $response->get_error_message());
            wp_send_json_error(__('Erreur de connexion : ', 'mbtc-validator') . $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($code === 200 && isset($data['status']) && $data['status'] === 'healthy') {
            $this->debug_log("Test connexion successful");
            wp_send_json_success(__('Connexion réussie à l\'API.', 'mbtc-validator'));
        } else {
            $this->debug_log("Test connexion unexpected response: code $code, body $body");
            wp_send_json_error(sprintf(__('Réponse inattendue (code %s).', 'mbtc-validator'), $code));
        }
    }

    public function ajax_test_manual_upload() {
        $this->debug_log("AJAX test manual upload called");
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permissions insuffisantes.', 'mbtc-validator'));
        }
        $faux_contenu = json_encode(['test' => 'manuel', 'time' => time()]);
        $tmp_path = tempnam(sys_get_temp_dir(), 'mbtc_test_');
        file_put_contents($tmp_path, $faux_contenu);

        $_FILES['proof_file'] = [
            'name' => 'test-manuel.json',
            'type' => 'application/json',
            'tmp_name' => $tmp_path,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($faux_contenu)
        ];
        $_POST['mbtc_nonce'] = wp_create_nonce('mbtc_ajax_nonce');
        
        $this->ajax_upload_proof();
        if (file_exists($tmp_path)) unlink($tmp_path);
    }

    public function ajax_upload_proof() {
        $this->debug_log("Upload proof request started");
        if (!isset($_POST['mbtc_nonce']) || !wp_verify_nonce($_POST['mbtc_nonce'], 'mbtc_ajax_nonce')) {
            $this->debug_log("Upload proof: invalid nonce");
            wp_send_json_error(__('Nonce invalide.', 'mbtc-validator'));
        }
        if (!is_user_logged_in()) {
            $this->debug_log("Upload proof: user not logged in");
            wp_send_json_error(__('Utilisateur non connecté.', 'mbtc-validator'));
        }
        if (empty($_FILES['proof_file'])) {
            $this->debug_log("Upload proof: no file received");
            wp_send_json_error(__('Aucun fichier reçu.', 'mbtc-validator'));
        }

        $file = $_FILES['proof_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->debug_log("Upload proof: PHP upload error: " . $file['error']);
            wp_send_json_error(__('Erreur upload PHP.', 'mbtc-validator'));
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['json', 'gpg'])) {
            $this->debug_log("Upload proof: invalid extension: $ext");
            wp_send_json_error(__('Extension non autorisée.', 'mbtc-validator'));
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            $this->debug_log("Upload proof: file too large: " . $file['size']);
            wp_send_json_error(__('Fichier trop lourd.', 'mbtc-validator'));
        }

        $api_url = get_option('mbtc_api_url');
        $token = get_option('mbtc_api_token');
        if (empty($api_url) || empty($token)) {
            $this->debug_log("Upload proof: API config missing");
            wp_send_json_error(__('Config API manquante.', 'mbtc-validator'));
        }

        $file_content = file_get_contents($file['tmp_name']);
        $boundary = '----WebKitFormBoundary' . wp_generate_password(24, false);
        
        $body = "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"" . basename($file['name']) . "\"\r\n";
        $body .= "Content-Type: " . ($ext === 'json' ? 'application/json' : 'application/octet-stream') . "\r\n\r\n";
        $body .= $file_content . "\r\n";
        $body .= "--$boundary--\r\n";

        $args = [
            'method' => 'POST',
            'headers' => [
                'X-Token' => $token,
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                'Content-Length' => strlen($body)
            ],
            'body' => $body,
            'timeout' => 30,
            'sslverify' => false
        ];

        $response = wp_remote_post($api_url, $args);

        if (is_wp_error($response)) {
            $this->debug_log("Upload proof: API error: " . $response->get_error_message());
            wp_send_json_error(__('Erreur connexion API.', 'mbtc-validator'));
        }

        $code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $this->debug_log("Upload proof: API response code $code, body: $response_body");
            wp_send_json_error(sprintf(__('Erreur API (Code %s).', 'mbtc-validator'), $code));
        }

        $data = json_decode($response_body, true);
        if (!isset($data['status']) || $data['status'] !== 'ok') {
            $this->debug_log("Upload proof: invalid API response: " . $response_body);
            wp_send_json_error(__('Réponse API invalide.', 'mbtc-validator'));
        }

        $this->debug_log("Upload proof: success, filename " . sanitize_file_name($data['filename']));
        wp_send_json_success(sprintf(__('Succès! Fichier: %s', 'mbtc-validator'), sanitize_file_name($data['filename'])));
    }

    public function ajax_view_logs() {
        if (!current_user_can('manage_options')) wp_send_json_error('Accès refusé');
        $log_file = WP_CONTENT_DIR . '/mbtc-debug.log';
        if (!file_exists($log_file)) wp_send_json_error('Aucun log.');
        $content = file_get_contents($log_file);
        if (empty($content)) wp_send_json_error('Log vide.');
        $lines = array_slice(explode("\n", $content), -100);
        wp_send_json_success(esc_html(implode("\n", $lines)));
    }

    public function ajax_clear_logs() {
        if (!current_user_can('manage_options')) wp_send_json_error('Accès refusé');
        $log_file = WP_CONTENT_DIR . '/mbtc-debug.log';
        if (file_exists($log_file)) unlink($log_file);
        wp_send_json_success('Logs effacés.');
    }

    private function debug_log($msg) {
        error_log("MBTC: $msg");
        if (get_option('mbtc_debug_log', 0)) {
            $log_file = WP_CONTENT_DIR . '/mbtc-debug.log';
            if (is_writable(WP_CONTENT_DIR) || !file_exists($log_file)) {
                file_put_contents($log_file, '['.date('Y-m-d H:i:s')."] $msg\n", FILE_APPEND);
            }
        }
    }
}

new MBTC_Validator_Simple_Plugin();

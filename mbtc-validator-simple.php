<?php
/**
 * Plugin Name: mBTC Validator Simple
 * Description: Permet aux validateurs connectés de soumettre des preuves à l'orchestrateur mBTC via une API sécurisée.
 * Version: 1.9.0
 * Author: Alain St-Germain
 * License: GPL v3 or later
 * Text Domain: mbtc-validator
 */

if (!defined('ABSPATH')) exit;

class MBTC_Validator_Simple {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_shortcode('mbtc_submit_proof', [$this, 'submit_proof_shortcode']);
        add_action('wp_ajax_mbtc_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_mbtc_upload_proof', [$this, 'ajax_upload_proof']);
        add_action('wp_ajax_mbtc_view_logs', [$this, 'ajax_view_logs']);
        add_action('wp_ajax_mbtc_clear_logs', [$this, 'ajax_clear_logs']);
        add_action('wp_ajax_mbtc_test_manual_upload', [$this, 'ajax_test_manual_upload']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);

        // Pour le front-end, on enregistre le script avec localisation
        add_action('wp_enqueue_scripts', [$this, 'register_front_script']);
    }

    public function register_front_script() {
        wp_register_script('mbtc-front', false, ['jquery'], '1.0', true);
        wp_localize_script('mbtc-front', 'mbtc_ajax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mbtc_ajax_nonce')
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
                $('#mbtc-test-connection').click(function() {
                    var data = { action: 'mbtc_test_connection' };
                    $.post(ajaxurl, data, function(response) {
                        $('#mbtc-test-result').html(response.success ? '<span style="color:green">✓ '+response.data+'</span>' : '<span style="color:red">✗ '+response.data+'</span>');
                    }).fail(function() {
                        $('#mbtc-test-result').html('<span style="color:red">✗ <?php _e('Erreur de communication', 'mbtc-validator'); ?></span>');
                    });
                });

                $('#mbtc-test-upload').click(function() {
                    var data = { action: 'mbtc_test_manual_upload' };
                    $.post(ajaxurl, data, function(response) {
                        $('#mbtc-test-upload-result').html(response.success ? '<span style="color:green">✓ '+response.data+'</span>' : '<span style="color:red">✗ '+response.data+'</span>');
                    }).fail(function() {
                        $('#mbtc-test-upload-result').html('<span style="color:red">✗ <?php _e('Erreur de communication', 'mbtc-validator'); ?></span>');
                    });
                });

                $('#mbtc-view-logs').click(function() {
                    $.post(ajaxurl, { action: 'mbtc_view_logs' }, function(response) {
                        $('#mbtc-logs-display').html(response.success ? '<pre>'+response.data+'</pre>' : '<p style="color:red">'+response.data+'</p>').show();
                    });
                });

                $('#mbtc-clear-logs').click(function() {
                    if (confirm('<?php _e('Effacer tous les logs ?', 'mbtc-validator'); ?>')) {
                        $.post(ajaxurl, { action: 'mbtc_clear_logs' }, function(response) {
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
        wp_enqueue_script('mbtc-front'); // Notre script localisé
        ob_start();
        ?>
        <style>
        .mbtc-form { max-width:500px; margin:20px 0; padding:20px; background:#f9f9f9; border:1px solid #ddd; border-radius:8px; }
        .mbtc-form input[type="file"] { width:100%; padding:10px; margin-bottom:15px; border:1px solid #ccc; border-radius:4px; }
        .mbtc-form input[type="submit"] { background:#4CAF50; color:white; padding:12px 20px; border:none; border-radius:4px; cursor:pointer; font-size:16px; }
        .mbtc-form input[type="submit"]:hover { background:#45a049; }
        #mbtc-progress-container { margin-top:15px; width:100%; background-color:#f0f0f0; border-radius:5px; display:none; }
        #mbtc-progress-bar { width:0%; height:24px; background-color:#4CAF50; border-radius:5px; transition:width 0.2s ease; text-align:center; line-height:24px; color:white; font-weight:bold; }
        #mbtc-message { margin-top:15px; }
        .mbtc-debug { font-size:12px; color:#666; margin-top:5px; border-top:1px solid #ddd; padding-top:5px; }
        </style>
        <div class="mbtc-form">
            <form id="mbtc-upload-form" enctype="multipart/form-data">
                <input type="hidden" name="mbtc_nonce" value="<?php echo wp_create_nonce('mbtc_ajax_nonce'); ?>">
                <input type="file" name="proof_file" accept=".json,.gpg" required>
                <input type="submit" value="<?php _e('Soumettre la preuve', 'mbtc-validator'); ?>">
            </form>
            <div id="mbtc-progress-container"><div id="mbtc-progress-bar">0%</div></div>
            <div id="mbtc-message"></div>
            <div class="mbtc-debug">
                <p><strong><?php _e('Diagnostic:', 'mbtc-validator'); ?></strong> <?php _e('Si l\'upload ne fonctionne pas, vérifiez la console (F12) pour les messages ci-dessous.', 'mbtc-validator'); ?></p>
                <div id="mbtc-js-status"></div>
            </div>
        </div>
        <script>
        jQuery(document).ready(function($) {
            console.log('✅ mBTC JS chargé avec succès');
            $('#mbtc-js-status').html('<span style="color:green;">✓ JavaScript chargé</span>');

            // Vérifier la présence de mbtc_ajax.ajaxurl
            if (typeof mbtc_ajax === 'undefined' || !mbtc_ajax.ajaxurl) {
                console.error('❌ mbtc_ajax non défini');
                $('#mbtc-js-status').append('<br><span style="color:red;">❌ mbtc_ajax manquant</span>');
            } else {
                console.log('✅ mbtc_ajax.ajaxurl = ' + mbtc_ajax.ajaxurl);
                $('#mbtc-js-status').append('<br><span style="color:green;">✓ mbtc_ajax.ajaxurl trouvé</span>');
            }

            // Vérifier que le formulaire existe
            if ($('#mbtc-upload-form').length === 0) {
                console.error('❌ Formulaire introuvable');
                $('#mbtc-js-status').append('<br><span style="color:red;">❌ Formulaire introuvable</span>');
                return;
            } else {
                console.log('✅ Formulaire trouvé');
                $('#mbtc-js-status').append('<br><span style="color:green;">✓ Formulaire trouvé</span>');
            }

            // Utilisation de la délégation d'événement pour capturer le submit
            $(document).on('submit', '#mbtc-upload-form', function(e) {
                console.log('🚀 Événement submit intercepté (délégué)');
                e.preventDefault();

                var formData = new FormData(this);
                formData.append('action', 'mbtc_upload_proof');
                formData.append('mbtc_nonce', mbtc_ajax.nonce);

                // Afficher le contenu de FormData dans la console
                console.log('FormData entries:');
                for (var pair of formData.entries()) {
                    console.log(' - ' + pair[0] + ': ' + (pair[1] instanceof File ? pair[1].name + ' (' + pair[1].size + ' octets)' : pair[1]));
                }

                $('#mbtc-progress-container').show();
                $('#mbtc-progress-bar').css('width', '0%').text('0%');
                $('#mbtc-message').empty();

                $.ajax({
                    url: mbtc_ajax.ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    xhr: function() {
                        var xhr = new XMLHttpRequest();
                        xhr.upload.addEventListener('progress', function(evt) {
                            if (evt.lengthComputable) {
                                var p = Math.round((evt.loaded / evt.total) * 100);
                                console.log('📤 Progression: ' + p + '%');
                                $('#mbtc-progress-bar').css('width', p + '%').text(p + '%');
                            }
                        });
                        return xhr;
                    },
                    beforeSend: function() {
                        console.log('📡 Envoi de la requête AJAX vers ' + mbtc_ajax.ajaxurl);
                    },
                    success: function(resp) {
                        console.log('✅ Réponse reçue:', resp);
                        $('#mbtc-progress-bar').css('width', '100%').text('100%');
                        if (resp.success) {
                            $('#mbtc-message').html('<div style="background:#d4edda;color:#155724;padding:10px;">✅ ' + resp.data + '</div>');
                            $('#mbtc-upload-form')[0].reset();
                        } else {
                            $('#mbtc-message').html('<div style="background:#f8d7da;color:#721c24;padding:10px;">❌ ' + resp.data + '</div>');
                        }
                        setTimeout(() => $('#mbtc-progress-container').fadeOut(), 2000);
                    },
                    error: function(xhr, status, error) {
                        console.error('❌ Erreur AJAX', status, error);
                        console.error('Réponse brute:', xhr.responseText);
                        $('#mbtc-progress-container').fadeOut();
                        $('#mbtc-message').html('<div style="background:#f8d7da;color:#721c24;padding:10px;">❌ Erreur AJAX : ' + status + ' - ' + error + '</div>');
                    },
                    complete: function() {
                        console.log('🏁 Requête terminée');
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    private function debug_log($msg) {
        error_log("MBTC: $msg");
        if (get_option('mbtc_debug_log', 0)) {
            file_put_contents(WP_CONTENT_DIR.'/mbtc-debug.log', '['.date('Y-m-d H:i:s')."] $msg\n", FILE_APPEND);
        }
    }

    public function ajax_test_connection() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permissions insuffisantes.', 'mbtc-validator'));
        }
        $api_url = get_option('mbtc_api_url');
        $token = get_option('mbtc_api_token');
        if (empty($api_url) || empty($token)) {
            wp_send_json_error(__('Configuration incomplète.', 'mbtc-validator'));
        }
        $health_url = str_replace('/submit', '/health', $api_url);
        $this->debug_log("Test connexion vers $health_url");
        $response = wp_remote_get($health_url, [
            'headers' => ['X-Token' => $token],
            'timeout' => 10,
        ]);
        if (is_wp_error($response)) {
            $this->debug_log("Erreur test connexion : " . $response->get_error_message());
            wp_send_json_error(__('Erreur de connexion : ', 'mbtc-validator') . $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if ($code === 200 && isset($data['status']) && $data['status'] === 'healthy') {
            $this->debug_log("Test connexion réussi");
            wp_send_json_success(__('Connexion réussie à l\'API.', 'mbtc-validator'));
        } else {
            $this->debug_log("Test connexion échec : code $code, body $body");
            wp_send_json_error(sprintf(__('Réponse inattendue (code %s).', 'mbtc-validator'), $code));
        }
    }

    public function ajax_test_manual_upload() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permissions insuffisantes.', 'mbtc-validator'));
        }
        $faux_contenu = json_encode(['test' => 'manuel', 'time' => time()]);
        $faux_nom = 'test-manuel-' . time() . '.json';
        $tmp = tmpfile();
        fwrite($tmp, $faux_contenu);
        $meta = stream_get_meta_data($tmp);
        $_FILES['proof_file'] = [
            'name' => $faux_nom,
            'type' => 'application/json',
            'tmp_name' => $meta['uri'],
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($faux_contenu)
        ];
        $_POST['mbtc_nonce'] = wp_create_nonce('mbtc_ajax_nonce');
        $this->ajax_upload_proof();
        fclose($tmp);
    }

    public function ajax_upload_proof() {
        $this->debug_log("Upload - POST: " . print_r($_POST, true));
        $this->debug_log("FILES: " . print_r($_FILES, true));

        // Vérifier le nonce
        if (!isset($_POST['mbtc_nonce']) || !wp_verify_nonce($_POST['mbtc_nonce'], 'mbtc_ajax_nonce')) {
            $this->debug_log("Nonce invalide");
            wp_send_json_error(__('Nonce invalide. Veuillez rafraîchir la page.', 'mbtc-validator'));
        }

        // Vérifier que l'utilisateur est connecté
        if (!is_user_logged_in()) {
            $this->debug_log("Utilisateur non connecté");
            wp_send_json_error(__('Vous devez être connecté.', 'mbtc-validator'));
        }

        // Vérifier la présence du fichier
        if (empty($_FILES['proof_file'])) {
            $this->debug_log("Aucun fichier reçu");
            wp_send_json_error(__('Aucun fichier reçu.', 'mbtc-validator'));
        }

        $file = $_FILES['proof_file'];
        $this->debug_log("Fichier reçu : " . print_r($file, true));

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->debug_log("Erreur upload PHP : " . $file['error']);
            wp_send_json_error(__('Erreur lors de l\'upload.', 'mbtc-validator') . ' Code: ' . $file['error']);
        }

        if ($file['size'] > 10 * 1024 * 1024) {
            $this->debug_log("Fichier trop volumineux : " . $file['size']);
            wp_send_json_error(__('Fichier trop volumineux (max 10 Mo).', 'mbtc-validator'));
        }

        $file_content = file_get_contents($file['tmp_name']);
        if ($file_content === false) {
            $this->debug_log("Impossible de lire le fichier temporaire");
            wp_send_json_error(__('Impossible de lire le fichier.', 'mbtc-validator'));
        }

        $api_url = get_option('mbtc_api_url');
        $token = get_option('mbtc_api_token');

        if (empty($api_url) || empty($token)) {
            $this->debug_log("Configuration API incomplète");
            wp_send_json_error(__('Configuration API incomplète. Contactez l\'administrateur.', 'mbtc-validator'));
        }

        $this->debug_log("Envoi vers API : $api_url avec token $token");

        $boundary = wp_generate_password(24, false);
        $body = "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"" . basename($file['name']) . "\"\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= $file_content . "\r\n";
        $body .= "--$boundary--\r\n";

        $args = [
            'method' => 'POST',
            'headers' => [
                'X-Token' => $token,
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $body,
            'timeout' => 30,
        ];

        $this->debug_log("Requête préparée, taille body : " . strlen($body));

        $response = wp_remote_post($api_url, $args);

        if (is_wp_error($response)) {
            $this->debug_log("Erreur wp_remote_post : " . $response->get_error_message());
            wp_send_json_error(__('Erreur de connexion à l\'API : ', 'mbtc-validator') . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $this->debug_log("Réponse API : code $code, body $response_body");

        if ($code !== 200) {
            wp_send_json_error(sprintf(__('L\'API a répondu avec le code %s.', 'mbtc-validator'), $code));
        }

        $data = json_decode($response_body, true);
        if (!isset($data['status']) || $data['status'] !== 'ok') {
            wp_send_json_error(__('Réponse inattendue de l\'API.', 'mbtc-validator'));
        }

        $this->debug_log("Upload réussi, fichier : " . $data['filename']);
        wp_send_json_success(sprintf(__('Preuve soumise avec succès ! Fichier : %s', 'mbtc-validator'), $data['filename']));
    }

    public function ajax_view_logs() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Accès refusé');
        }
        $log_file = WP_CONTENT_DIR . '/mbtc-debug.log';
        if (!file_exists($log_file)) {
            wp_send_json_error('Le fichier de log n\'existe pas encore.');
        }
        $content = file_get_contents($log_file);
        if (empty($content)) {
            wp_send_json_error('Le fichier de log est vide.');
        }
        $lines = explode("\n", $content);
        $lines = array_slice($lines, -100);
        $content = implode("\n", $lines);
        wp_send_json_success(esc_html($content));
    }

    public function ajax_clear_logs() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Accès refusé');
        }
        $log_file = WP_CONTENT_DIR . '/mbtc-debug.log';
        if (file_exists($log_file)) {
            unlink($log_file);
        }
        wp_send_json_success('Logs effacés.');
    }
}

new MBTC_Validator_Simple();

<?php
/**
 * Plugin Name: mBTC Validator Simple
 * Description: Permet aux validateurs connectés de soumettre des preuves à l'orchestrateur mBTC via une API sécurisée.
 * Version: 1.0.0
 * Author: Alain St-Germain
 * License: GPL v3 or later
 * Text Domain: mbtc-validator
 */

// Sécurité : empêcher l'accès direct
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ajouter la page de configuration dans le menu Réglages
 */
add_action('admin_menu', 'mbtc_add_admin_menu');
function mbtc_add_admin_menu() {
    add_options_page(
        __('Configuration mBTC Validator', 'mbtc-validator'),
        __('mBTC Validator', 'mbtc-validator'),
        'manage_options',
        'mbtc-validator',
        'mbtc_render_admin_page'
    );
}

/**
 * Enregistrer les options
 */
add_action('admin_init', 'mbtc_register_settings');
function mbtc_register_settings() {
    register_setting('mbtc_options', 'mbtc_api_url', 'esc_url_raw');
    register_setting('mbtc_options', 'mbtc_api_token', 'sanitize_text_field');
}

/**
 * Afficher la page d'administration
 */
function mbtc_render_admin_page() {
    ?>
    <div class="wrap">
        <h1><?php _e('Configuration mBTC Validator', 'mbtc-validator'); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields('mbtc_options'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="mbtc_api_url"><?php _e('URL de l\'API', 'mbtc-validator'); ?></label>
                    </th>
                    <td>
                        <input type="url" id="mbtc_api_url" name="mbtc_api_url" value="<?php echo esc_attr(get_option('mbtc_api_url', 'http://localhost:5000/submit')); ?>" class="regular-text" />
                        <p class="description"><?php _e('Exemple : http://192.168.1.100:5000/submit', 'mbtc-validator'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="mbtc_api_token"><?php _e('Token secret', 'mbtc-validator'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="mbtc_api_token" name="mbtc_api_token" value="<?php echo esc_attr(get_option('mbtc_api_token', '')); ?>" class="regular-text" />
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <hr>
        <h2><?php _e('Test de connexion', 'mbtc-validator'); ?></h2>
        <button id="mbtc-test-connection" class="button"><?php _e('Tester la connexion', 'mbtc-validator'); ?></button>
        <span id="mbtc-test-result" style="margin-left: 10px;"></span>
        <script>
        jQuery(document).ready(function($) {
            $('#mbtc-test-connection').click(function() {
                var data = {
                    action: 'mbtc_test_connection'
                };
                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        $('#mbtc-test-result').html('<span style="color:green">✓ ' + response.data + '</span>');
                    } else {
                        $('#mbtc-test-result').html('<span style="color:red">✗ ' + response.data + '</span>');
                    }
                }).fail(function() {
                    $('#mbtc-test-result').html('<span style="color:red">✗ Erreur de communication</span>');
                });
            });
        });
        </script>
    </div>
    <?php
}

/**
 * Shortcode [mbtc_submit_proof]
 */
add_shortcode('mbtc_submit_proof', 'mbtc_submit_proof_shortcode');
function mbtc_submit_proof_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>' . __('Vous devez être connecté pour soumettre une preuve.', 'mbtc-validator') . '</p>';
    }

    ob_start();
    ?>
    <form id="mbtc-upload-form" enctype="multipart/form-data">
        <?php wp_nonce_field('mbtc_ajax_nonce', 'mbtc_nonce'); ?>
        <input type="file" name="proof_file" accept=".json,.gpg" required>
        <input type="submit" value="<?php _e('Soumettre la preuve', 'mbtc-validator'); ?>">
    </form>
    <div id="mbtc-message"></div>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('#mbtc-upload-form').on('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            formData.append('action', 'mbtc_upload_proof');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        $('#mbtc-message').html('<div class="notice notice-success is-dismissible"><p>' + response.data + '</p></div>');
                        $('#mbtc-upload-form')[0].reset();
                    } else {
                        $('#mbtc-message').html('<div class="notice notice-error is-dismissible"><p>' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    $('#mbtc-message').html('<div class="notice notice-error is-dismissible"><p><?php _e('Erreur de communication avec le serveur.', 'mbtc-validator'); ?></p></div>');
                }
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Traitement AJAX : test de connexion à l'API
 */
add_action('wp_ajax_mbtc_test_connection', 'mbtc_ajax_test_connection');
function mbtc_ajax_test_connection() {
    // Vérifier les capacités admin
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permissions insuffisantes.', 'mbtc-validator'));
    }

    $api_url = get_option('mbtc_api_url');
    $token = get_option('mbtc_api_token');

    if (empty($api_url) || empty($token)) {
        wp_send_json_error(__('Veuillez d\'abord configurer l\'URL et le token.', 'mbtc-validator'));
    }

    // On teste l'endpoint /health (doit être implémenté côté API)
    $health_url = str_replace('/submit', '/health', $api_url);
    $response = wp_remote_get($health_url, [
        'headers' => ['X-Token' => $token],
        'timeout' => 10,
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(__('Erreur de connexion : ', 'mbtc-validator') . $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($code === 200 && isset($data['status']) && $data['status'] === 'healthy') {
        wp_send_json_success(__('Connexion réussie à l\'API.', 'mbtc-validator'));
    } else {
        wp_send_json_error(sprintf(__('Réponse inattendue (code %s).', 'mbtc-validator'), $code));
    }
}

/**
 * Traitement AJAX : upload de preuve
 */
add_action('wp_ajax_mbtc_upload_proof', 'mbtc_ajax_upload_proof');
function mbtc_ajax_upload_proof() {
    // Vérifier le nonce
    if (!isset($_POST['mbtc_nonce']) || !wp_verify_nonce($_POST['mbtc_nonce'], 'mbtc_ajax_nonce')) {
        wp_send_json_error(__('Nonce invalide. Veuillez rafraîchir la page.', 'mbtc-validator'));
    }

    // Vérifier que l'utilisateur est connecté
    if (!is_user_logged_in()) {
        wp_send_json_error(__('Vous devez être connecté.', 'mbtc-validator'));
    }

    // Vérifier la présence du fichier
    if (empty($_FILES['proof_file'])) {
        wp_send_json_error(__('Aucun fichier reçu.', 'mbtc-validator'));
    }

    $file = $_FILES['proof_file'];

    // Vérifications basiques
    if ($file['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error(__('Erreur lors de l\'upload.', 'mbtc-validator') . ' Code: ' . $file['error']);
    }

    if ($file['size'] > 10 * 1024 * 1024) { // 10 Mo max
        wp_send_json_error(__('Fichier trop volumineux (max 10 Mo).', 'mbtc-validator'));
    }

    // Lire le contenu du fichier
    $file_content = file_get_contents($file['tmp_name']);
    if ($file_content === false) {
        wp_send_json_error(__('Impossible de lire le fichier.', 'mbtc-validator'));
    }

    $api_url = get_option('mbtc_api_url');
    $token = get_option('mbtc_api_token');

    if (empty($api_url) || empty($token)) {
        wp_send_json_error(__('Configuration API incomplète. Contactez l\'administrateur.', 'mbtc-validator'));
    }

    // Construire la requête multipart manuellement
    $boundary = wp_generate_password(24, false);
    $body = "--$boundary\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"" . basename($file['name']) . "\"\r\n";
    $body .= "Content-Type: application/octet-stream\r\n\r\n";
    $body .= $file_content . "\r\n";
    $body .= "--$boundary--\r\n";

    $response = wp_remote_post($api_url, [
        'headers' => [
            'X-Token' => $token,
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        ],
        'body' => $body,
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(__('Erreur de connexion à l\'API : ', 'mbtc-validator') . $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);

    if ($code !== 200) {
        wp_send_json_error(sprintf(__('L\'API a répondu avec le code %s.', 'mbtc-validator'), $code));
    }

    if (!isset($data['status']) || $data['status'] !== 'ok') {
        wp_send_json_error(__('Réponse inattendue de l\'API.', 'mbtc-validator'));
    }

    wp_send_json_success(sprintf(__('Preuve soumise avec succès ! Fichier : %s', 'mbtc-validator'), $data['filename']));
}

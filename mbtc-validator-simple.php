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
 * Classe principale du plugin
 */
class MBTC_Validator_Simple {

    /**
     * Constructeur : initialise les hooks
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_shortcode('mbtc_submit_proof', [$this, 'submit_proof_shortcode']);
        add_action('wp_ajax_mbtc_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_mbtc_upload_proof', [$this, 'ajax_upload_proof']);

        // Ajouter un lien de réglages sur la page des plugins
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
    }

    /**
     * Ajouter le menu dans Réglages
     */
    public function add_admin_menu() {
        add_options_page(
            __('Configuration mBTC Validator', 'mbtc-validator'),
            __('mBTC Validator', 'mbtc-validator'),
            'manage_options',
            'mbtc-validator',
            [$this, 'render_admin_page']
        );
    }

    /**
     * Enregistrer les options
     */
    public function register_settings() {
        register_setting('mbtc_options', 'mbtc_api_url', 'esc_url_raw');
        register_setting('mbtc_options', 'mbtc_api_token', 'sanitize_text_field');
        register_setting('mbtc_options', 'mbtc_debug_log', 'intval');
    }

    /**
     * Afficher la page d'administration
     */
    public function render_admin_page() {
        $log_file = WP_CONTENT_DIR . '/mbtc-debug.log';
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
                    <tr>
                        <th scope="row">
                            <label for="mbtc_debug_log"><?php _e('Mode debug', 'mbtc-validator'); ?></label>
                        </th>
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
            <span id="mbtc-test-result" style="margin-left: 10px;"></span>
            <hr>
            <h2><?php _e('Diagnostic', 'mbtc-validator'); ?></h2>
            <p><?php _e('Si l\'upload ne fonctionne pas, activez le mode debug ci-dessus, reproduisez l\'erreur, puis consultez le fichier de log.', 'mbtc-validator'); ?></p>
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
                        $('#mbtc-test-result').html('<span style="color:red">✗ <?php _e('Erreur de communication', 'mbtc-validator'); ?></span>');
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    /**
     * Ajouter un lien "Réglages" sur la page des plugins
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=mbtc-validator') . '">' . __('Réglages', 'mbtc-validator') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Shortcode [mbtc_submit_proof]
     */
    public function submit_proof_shortcode() {
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
                    error: function(jqXHR, textStatus, errorThrown) {
                        $('#mbtc-message').html('<div class="notice notice-error is-dismissible"><p><?php _e('Erreur AJAX : ', 'mbtc-validator'); ?>' + textStatus + ' - ' + errorThrown + '</p></div>');
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Log debug si activé
     */
    private function debug_log($message) {
        if (get_option('mbtc_debug_log', 0)) {
            $log_file = WP_CONTENT_DIR . '/mbtc-debug.log';
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
        }
    }

    /**
     * Test de connexion à l'API (endpoint /health)
     */
    public function ajax_test_connection() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permissions insuffisantes.', 'mbtc-validator'));
        }

        $api_url = get_option('mbtc_api_url');
        $token = get_option('mbtc_api_token');

        if (empty($api_url) || empty($token)) {
            wp_send_json_error(__('Veuillez d\'abord configurer l\'URL et le token.', 'mbtc-validator'));
        }

        $health_url = str_replace('/submit', '/health', $api_url);
        $this->debug_log("Test de connexion vers $health_url");

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

    /**
     * Upload de preuve
     */
    public function ajax_upload_proof() {
        $this->debug_log("Début upload - Vérification nonce");

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

        // Vérifications basiques
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->debug_log("Erreur upload PHP : " . $file['error']);
            wp_send_json_error(__('Erreur lors de l\'upload.', 'mbtc-validator') . ' Code: ' . $file['error']);
        }

        if ($file['size'] > 10 * 1024 * 1024) { // 10 Mo max
            $this->debug_log("Fichier trop volumineux : " . $file['size']);
            wp_send_json_error(__('Fichier trop volumineux (max 10 Mo).', 'mbtc-validator'));
        }

        // Lire le contenu du fichier
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

        // Construire la requête multipart manuellement
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
}

// Initialisation du plugin
new MBTC_Validator_Simple();

<?php
/**
 * Plugin Name: mBTC Validator Sovereign (Audit Edition)
 * Description: Passerelle securisee vers MBTC-API avec journalisation locale et tableau de bord d'audit
 * Version: 2.0-Audit
 * Author: Alain St-Germain
 * Text Domain: mbtc-validator
 */

// =============================================================================
// FORCER L'UTF-8 ABSOLUMENT
// =============================================================================
mb_internal_encoding('UTF-8');
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

// =============================================================================
// CONSTANTES
// =============================================================================
if (!defined('ABSPATH')) exit;

define('MBTC_LOG_DIR', WP_CONTENT_DIR . '/uploads/mbtc-logs');
define('MBTC_LOG_FILE', MBTC_LOG_DIR . '/audit.log');
define('MBTC_MAX_SIZE', 10 * 1024 * 1024); // 10 Mo
define('MBTC_VERSION', '2.0');

// =============================================================================
// ACTIVATION / DESACTIVATION
// =============================================================================
register_activation_hook(__FILE__, 'mbtc_activate');
register_deactivation_hook(__FILE__, 'mbtc_deactivate');

function mbtc_activate() {
    // Creer les dossiers de logs
    if (!file_exists(MBTC_LOG_DIR)) {
        wp_mkdir_p(MBTC_LOG_DIR);
        file_put_contents(MBTC_LOG_DIR . '/index.php', '<?php // Silence is golden');
        file_put_contents(MBTC_LOG_DIR . '/.htaccess', 'Deny from all');
    }
    
    // Creer la table d'audit
    global $wpdb;
    $table_name = $wpdb->prefix . 'mbtc_audit';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        timestamp datetime DEFAULT CURRENT_TIMESTAMP,
        user_id bigint(20),
        username varchar(100),
        ip_address varchar(45),
        action varchar(50),
        filename varchar(255),
        filesize bigint(20),
        file_hash varchar(64),
        category varchar(100),
        reference varchar(100),
        destination varchar(50),
        response_code int,
        response_body text,
        txid varchar(100),
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY timestamp (timestamp),
        KEY file_hash (file_hash)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Forcer l'UTF-8 sur la table
    $wpdb->query("ALTER TABLE $table_name CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // Option par defaut
    add_option('mbtc_api_url', '');
    add_option('mbtc_api_token', '');
}

function mbtc_deactivate() {
    // Rien a faire
}

// =============================================================================
// FONCTIONS DE JOURNALISATION
// =============================================================================
function mbtc_log_event($level, $message, $user_id = null, $file_name = null) {
    $timestamp = current_time('mysql');
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'CLI';
    $user_login = $user_id ? get_userdata($user_id)->user_login : 'Guest';
    
    $log_entry = sprintf(
        "[%s] [%s] [User: %s] [IP: %s] [File: %s] %s\n",
        $timestamp,
        strtoupper($level),
        $user_login,
        $ip,
        $file_name ? basename($file_name) : 'N/A',
        $message
    );

    $fp = fopen(MBTC_LOG_FILE, 'a');
    if ($fp) {
        flock($fp, LOCK_EX);
        fwrite($fp, mb_convert_encoding($log_entry, 'UTF-8', 'UTF-8'));
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function mbtc_audit_insert($data) {
    global $wpdb;
    
    $default = [
        'user_id' => get_current_user_id(),
        'username' => mb_convert_encoding(wp_get_current_user()->user_login, 'UTF-8', 'UTF-8'),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        'action' => 'upload',
        'filename' => '',
        'filesize' => 0,
        'file_hash' => '',
        'category' => isset($_POST['category']) ? mb_convert_encoding($_POST['category'], 'UTF-8', 'UTF-8') : '',
        'reference' => isset($_POST['reference']) ? mb_convert_encoding($_POST['reference'], 'UTF-8', 'UTF-8') : '',
        'destination' => 'MBTC-API',
        'response_code' => 0,
        'response_body' => '',
        'txid' => 'TX-' . strtoupper(uniqid() . bin2hex(random_bytes(4))),
    ];
    
    $data = wp_parse_args($data, $default);
    
    // Forcer l'UTF-8 sur tous les textes
    foreach (['username', 'filename', 'category', 'reference', 'destination', 'response_body'] as $field) {
        if (!empty($data[$field])) {
            $data[$field] = mb_convert_encoding($data[$field], 'UTF-8', 'UTF-8');
        }
    }
    
    // Calculer le hash si fichier disponible
    if (!empty($data['file_path']) && empty($data['file_hash'])) {
        $data['file_hash'] = hash_file('sha256', $data['file_path']);
    }
    
    $table = $wpdb->prefix . 'mbtc_audit';
    $wpdb->insert($table, $data);
    
    // Journaliser aussi dans le fichier
    mbtc_log_event('AUDIT', 
        "TXID: {$data['txid']} | Fichier: {$data['filename']} | Dest: {$data['destination']} | HTTP: {$data['response_code']}", 
        $data['user_id'], 
        $data['filename']
    );
    
    return $data['txid'];
}

// =============================================================================
// ADMIN MENU
// =============================================================================
add_action('admin_menu', 'mbtc_admin_menu');
function mbtc_admin_menu() {
    // Page de configuration
    add_options_page(
        'mBTC Sovereign', 
        'mBTC Validator', 
        'manage_options', 
        'mbtc-sovereign', 
        'mbtc_render_config_page'
    );
    
    // Menu principal d'audit
    add_menu_page(
        'Audit mBTC',
        'mBTC Audit',
        'manage_options',
        'mbtc-audit',
        'mbtc_render_audit_page',
        'dashicons-shield',
        30
    );
    
    // Sous-page statistiques
    add_submenu_page(
        'mbtc-audit',
        'Statistiques',
        'Statistiques',
        'manage_options',
        'mbtc-stats',
        'mbtc_render_stats_page'
    );
}

// =============================================================================
// PAGE DE CONFIGURATION
// =============================================================================
function mbtc_render_config_page() {
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
    </head>
    <body>
    <div class="wrap">
        <h1>[ Configuration mBTC Validator (Audit v<?= MBTC_VERSION ?>) ]</h1>
        
        <form method="post" action="options.php">
            <?php settings_fields('mbtc_group'); ?>
            
            <table class="form-table">
                <tr>
                    <th><label for="mbtc_api_url">URL de MBTC-API (VPS)</label></th>
                    <td>
                        <input type="url" id="mbtc_api_url" name="mbtc_api_url" 
                               value="<?= esc_attr(get_option('mbtc_api_url')) ?>" 
                               class="regular-text code" placeholder="http://VOTRE_VPS:5000/submit">
                        <p class="description">Endpoint de reception des fichiers.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="mbtc_api_token">Token Secret</label></th>
                    <td>
                        <input type="password" id="mbtc_api_token" name="mbtc_api_token" 
                               value="<?= esc_attr(get_option('mbtc_api_token')) ?>" 
                               class="regular-text code">
                        <p class="description">Cle d'authentification pour MBTC-API.</p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button('Sauvegarder la configuration'); ?>
        </form>
        
        <hr>
        
        <h2>[ Resume rapide ]</h2>
        <?php
        global $wpdb;
        $table = $wpdb->prefix . 'mbtc_audit';
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $last = $wpdb->get_row("SELECT * FROM $table ORDER BY timestamp DESC LIMIT 1");
        ?>
        <div style="background:#fff; padding:15px; border:1px solid #ccd0d4; border-radius:4px;">
            <p><strong>Total soumissions :</strong> <?= intval($total) ?></p>
            <?php if ($last): ?>
            <p><strong>Derniere soumission :</strong> <?= esc_html($last->timestamp) ?> par <?= esc_html($last->username) ?> (<?= esc_html($last->filename) ?>)</p>
            <?php endif; ?>
            <p><a href="<?= admin_url('admin.php?page=mbtc-audit') ?>" class="button button-primary">Voir l'audit complet</a></p>
        </div>
    </div>
    </body>
    </html>
    <?php
}

// =============================================================================
// PAGE D'AUDIT
// =============================================================================
function mbtc_render_audit_page() {
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'mbtc_audit';
    
    // Pagination
    $per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;
    
    // Construction des filtres
    $where = [];
    $where_values = [];
    
    if (!empty($_GET['user'])) {
        $where[] = "username LIKE %s";
        $where_values[] = '%' . $wpdb->esc_like($_GET['user']) . '%';
    }
    
    if (!empty($_GET['category'])) {
        $where[] = "category = %s";
        $where_values[] = $_GET['category'];
    }
    
    if (!empty($_GET['date_from'])) {
        $where[] = "timestamp >= %s";
        $where_values[] = $_GET['date_from'] . ' 00:00:00';
    }
    
    if (!empty($_GET['date_to'])) {
        $where[] = "timestamp <= %s";
        $where_values[] = $_GET['date_to'] . ' 23:59:59';
    }
    
    if (!empty($_GET['status'])) {
        if ($_GET['status'] === 'success') {
            $where[] = "response_code = 200";
        } elseif ($_GET['status'] === 'error') {
            $where[] = "response_code != 200";
        }
    }
    
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Compter le total avec filtres
    if (!empty($where_values)) {
        $count_query = $wpdb->prepare("SELECT COUNT(*) FROM $table $where_sql", $where_values);
    } else {
        $count_query = "SELECT COUNT(*) FROM $table";
    }
    $total = $wpdb->get_var($count_query);
    $total_pages = ceil($total / $per_page);
    
    // Recuperer les entrees
    if (!empty($where_values)) {
        $query = $wpdb->prepare(
            "SELECT * FROM $table $where_sql ORDER BY timestamp DESC LIMIT %d OFFSET %d",
            array_merge($where_values, [$per_page, $offset])
        );
    } else {
        $query = $wpdb->prepare(
            "SELECT * FROM $table ORDER BY timestamp DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );
    }
    $entries = $wpdb->get_results($query);
    
    // Recuperer les categories pour le filtre
    $categories = $wpdb->get_col("SELECT DISTINCT category FROM $table WHERE category != '' ORDER BY category");
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
    </head>
    <body>
    <div class="wrap">
        <h1 class="wp-heading-inline">[ Journal d'Audit mBTC ]</h1>
        <a href="<?= wp_nonce_url(admin_url('admin-post.php?action=mbtc_export_audit_csv'), 'mbtc_export') ?>" class="page-title-action">[ Exporter CSV ]</a>
        <a href="#" id="mbtc-refresh-audit" class="page-title-action">[ Actualiser ]</a>
        <hr class="wp-header-end">
        
        <!-- Formulaire de filtres -->
        <div class="tablenav top">
            <form method="get" class="alignleft actions" accept-charset="UTF-8">
                <input type="hidden" name="page" value="mbtc-audit">
                
                <select name="status">
                    <option value="">Tous les statuts</option>
                    <option value="success" <?= selected($_GET['status'] ?? '', 'success') ?>>[V] Succes</option>
                    <option value="error" <?= selected($_GET['status'] ?? '', 'error') ?>>[X] Echec</option>
                </select>
                
                <select name="category">
                    <option value="">Toutes categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= esc_attr($cat) ?>" <?= selected($_GET['category'] ?? '', $cat) ?>><?= esc_html($cat) ?></option>
                    <?php endforeach; ?>
                </select>
                
                <input type="text" name="user" placeholder="Nom d'utilisateur" value="<?= esc_attr($_GET['user'] ?? '') ?>">
                
                <input type="date" name="date_from" value="<?= esc_attr($_GET['date_from'] ?? '') ?>" placeholder="Date debut">
                <input type="date" name="date_to" value="<?= esc_attr($_GET['date_to'] ?? '') ?>" placeholder="Date fin">
                
                <input type="submit" class="button" value="Filtrer">
                <a href="<?= admin_url('admin.php?page=mbtc-audit') ?>" class="button">Reinitialiser</a>
            </form>
            
            <div class="tablenav-pages">
                <span class="displaying-num"><?= intval($total) ?> entree<?= $total > 1 ? 's' : '' ?></span>
                <?= paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total' => $total_pages,
                    'current' => $current_page
                ]) ?>
            </div>
        </div>
        
        <!-- Tableau des entrees -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="150">Date</th>
                    <th>Utilisateur</th>
                    <th>Fichier</th>
                    <th>Categorie</th>
                    <th>TXID</th>
                    <th>Destination</th>
                    <th width="100">Statut</th>
                    <th width="80">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($entries)): ?>
                <tr>
                    <td colspan="8" style="text-align:center;">Aucune entree trouvee</td>
                </tr>
                <?php endif; ?>
                
                <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><?= esc_html(date('d/m/Y H:i', strtotime($entry->timestamp))) ?></td>
                    <td>
                        <strong><?= esc_html($entry->username) ?></strong><br>
                        <small><?= esc_html($entry->ip_address) ?></small>
                    </td>
                    <td>
                        <?= esc_html(basename($entry->filename)) ?><br>
                        <small><?= size_format($entry->filesize) ?></small>
                    </td>
                    <td><?= esc_html($entry->category ?: '-') ?></td>
                    <td><code><?= esc_html(substr($entry->txid, 0, 12)) ?>...</code></td>
                    <td><?= esc_html($entry->destination) ?></td>
                    <td>
                        <?php if ($entry->response_code == 200): ?>
                            <span style="color:#46b450;">[V] Succes</span>
                        <?php else: ?>
                            <span style="color:#dc3232;">[X] Echec (<?= intval($entry->response_code) ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="button button-small view-details" data-id="<?= intval($entry->id) ?>">Details</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Modal pour les details -->
        <div id="detail-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:white; padding:25px; box-shadow:0 5px 30px rgba(0,0,0,0.3); z-index:1000; max-width:700px; max-height:80vh; overflow:auto; border-radius:8px;">
            <div id="modal-content"></div>
            <div style="text-align:right; margin-top:20px;">
                <button class="button button-primary" onclick="jQuery('#detail-modal').hide()">Fermer</button>
            </div>
        </div>
        <div id="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:999;" onclick="jQuery('#detail-modal, #modal-overlay').hide();"></div>
        
        <script>
        jQuery(document).ready(function($) {
            // Rafraichir la page
            $('#mbtc-refresh-audit').click(function(e) {
                e.preventDefault();
                location.reload();
            });
            
            // Voir les details
            $('.view-details').click(function() {
                var id = $(this).data('id');
                $.post(ajaxurl, {
                    action: 'mbtc_get_audit_detail',
                    id: id,
                    _ajax_nonce: '<?= wp_create_nonce('mbtc_ajax') ?>'
                }, function(r) {
                    if (r.success) {
                        $('#modal-content').html(r.data);
                        $('#detail-modal, #modal-overlay').show();
                    } else {
                        alert('Erreur: ' + r.data);
                    }
                });
            });
            
            // Fermer modal avec Echap
            $(document).keyup(function(e) {
                if (e.key === 'Escape') {
                    $('#detail-modal, #modal-overlay').hide();
                }
            });
        });
        </script>
        
        <style>
        #detail-modal table { width:100%; border-collapse:collapse; }
        #detail-modal th { text-align:left; width:120px; padding:8px; background:#f5f5f5; }
        #detail-modal td { padding:8px; word-break:break-word; }
        </style>
    </div>
    </body>
    </html>
    <?php
}

// =============================================================================
// PAGE STATISTIQUES
// =============================================================================
function mbtc_render_stats_page() {
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'mbtc_audit';
    
    // Statistiques globales
    $total = $wpdb->get_var("SELECT COUNT(*) FROM $table");
    $success = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE response_code = 200");
    $failed = $total - $success;
    
    // Activite par jour (30 derniers jours)
    $daily = $wpdb->get_results(
        "SELECT DATE(timestamp) as date, 
                COUNT(*) as total,
                SUM(CASE WHEN response_code = 200 THEN 1 ELSE 0 END) as success
         FROM $table
         WHERE timestamp > DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(timestamp)
         ORDER BY date"
    );
    
    // Top utilisateurs
    $top_users = $wpdb->get_results(
        "SELECT username, COUNT(*) as count
         FROM $table
         GROUP BY username
         ORDER BY count DESC
         LIMIT 10"
    );
    
    // Par categorie
    $categories = $wpdb->get_results(
        "SELECT category, COUNT(*) as count
         FROM $table
         WHERE category != ''
         GROUP BY category
         ORDER BY count DESC"
    );
    
    // Volume total de donnees
    $total_size = $wpdb->get_var("SELECT SUM(filesize) FROM $table");
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
    </head>
    <body>
    <div class="wrap">
        <h1>[ Statistiques d'Audit ]</h1>
        
        <!-- Cartes KPI -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin: 20px 0;">
            <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666;">Total soumissions</div>
                <div style="font-size: 36px; font-weight: bold;"><?= number_format(intval($total)) ?></div>
            </div>
            
            <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666;">Succes</div>
                <div style="font-size: 36px; font-weight: bold; color: #46b450;"><?= number_format(intval($success)) ?></div>
            </div>
            
            <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666;">Echecs</div>
                <div style="font-size: 36px; font-weight: bold; color: #dc3232;"><?= number_format(intval($failed)) ?></div>
            </div>
            
            <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666;">Volume total</div>
                <div style="font-size: 36px; font-weight: bold;"><?= size_format($total_size) ?></div>
            </div>
        </div>
        
        <!-- Taux de reussite et periode -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h3>Taux de reussite</h3>
                <div style="font-size: 48px; font-weight: bold; text-align: center;">
                    <?= $total > 0 ? round(($success / $total) * 100, 1) : 0 ?>%
                </div>
                <div style="background: #f0f0f1; height: 20px; border-radius: 10px; margin-top: 10px;">
                    <div style="background: #46b450; width: <?= $total > 0 ? ($success / $total) * 100 : 0 ?>%; height: 20px; border-radius: 10px;"></div>
                </div>
            </div>
            
            <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h3>Periode couverte</h3>
                <?php
                $min_date = $wpdb->get_var("SELECT MIN(timestamp) FROM $table");
                $max_date = $wpdb->get_var("SELECT MAX(timestamp) FROM $table");
                ?>
                <p><strong>Premiere :</strong> <?= $min_date ? esc_html(date('d/m/Y H:i', strtotime($min_date))) : '-' ?></p>
                <p><strong>Derniere :</strong> <?= $max_date ? esc_html(date('d/m/Y H:i', strtotime($max_date))) : '-' ?></p>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
            <!-- Graphique activite -->
            <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h3>Activite (30 derniers jours)</h3>
                <canvas id="activity-chart" style="height:300px; width:100%;"></canvas>
            </div>
            
            <!-- Top utilisateurs -->
            <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h3>Top utilisateurs</h3>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Utilisateur</th>
                            <th>Soumissions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_users as $user): ?>
                        <tr>
                            <td><?= esc_html($user->username) ?></td>
                            <td><strong><?= intval($user->count) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Categories -->
            <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h3>Par categorie</h3>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Categorie</th>
                            <th>Fichiers</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td><?= esc_html($cat->category) ?></td>
                            <td><strong><?= intval($cat->count) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Script pour le graphique -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
        jQuery(document).ready(function($) {
            var ctx = document.getElementById('activity-chart').getContext('2d');
            
            var dates = <?= json_encode(array_column($daily, 'date'), JSON_UNESCAPED_UNICODE) ?>;
            var totals = <?= json_encode(array_column($daily, 'total'), JSON_UNESCAPED_UNICODE) ?>;
            var successes = <?= json_encode(array_column($daily, 'success'), JSON_UNESCAPED_UNICODE) ?>;
            
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: dates,
                    datasets: [
                        {
                            label: 'Total',
                            data: totals,
                            borderColor: '#2271b1',
                            backgroundColor: 'rgba(34, 113, 177, 0.1)',
                            tension: 0.1,
                            fill: true
                        },
                        {
                            label: 'Succes',
                            data: successes,
                            borderColor: '#46b450',
                            backgroundColor: 'rgba(70, 180, 80, 0.1)',
                            tension: 0.1,
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    }
                }
            });
        });
        </script>
    </div>
    </body>
    </html>
    <?php
}

// =============================================================================
// ACTIONS AJAX
// =============================================================================
add_action('wp_ajax_mbtc_get_audit_detail', 'mbtc_ajax_get_audit_detail');
function mbtc_ajax_get_audit_detail() {
    check_ajax_referer('mbtc_ajax');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission refusee');
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'mbtc_audit';
    
    $id = intval($_POST['id']);
    $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    
    if (!$entry) {
        wp_send_json_error('Entree introuvable');
    }
    
    // Forcer l'UTF-8
    $entry->username = mb_convert_encoding($entry->username, 'UTF-8', 'UTF-8');
    $entry->filename = mb_convert_encoding($entry->filename, 'UTF-8', 'UTF-8');
    $entry->category = mb_convert_encoding($entry->category, 'UTF-8', 'UTF-8');
    $entry->reference = mb_convert_encoding($entry->reference, 'UTF-8', 'UTF-8');
    $entry->destination = mb_convert_encoding($entry->destination, 'UTF-8', 'UTF-8');
    $entry->response_body = mb_convert_encoding($entry->response_body, 'UTF-8', 'UTF-8');
    
    $html = '<h3>Details de la soumission #' . $entry->id . '</h3>';
    $html .= '<table>';
    $html .= '<tr><th>Timestamp</th><td>' . esc_html($entry->timestamp) . '</td></tr>';
    $html .= '<tr><th>Utilisateur</th><td>' . esc_html($entry->username) . ' (ID: ' . $entry->user_id . ')</td></tr>';
    $html .= '<tr><th>IP</th><td>' . esc_html($entry->ip_address) . '</td></tr>';
    $html .= '<tr><th>Fichier</th><td>' . esc_html($entry->filename) . ' (' . size_format($entry->filesize) . ')</td></tr>';
    $html .= '<tr><th>Hash SHA256</th><td><code>' . esc_html($entry->file_hash) . '</code></td></tr>';
    $html .= '<tr><th>Categorie</th><td>' . esc_html($entry->category ?: '-') . '</td></tr>';
    $html .= '<tr><th>Reference</th><td>' . esc_html($entry->reference ?: '-') . '</td></tr>';
    $html .= '<tr><th>TXID</th><td><code>' . esc_html($entry->txid) . '</code></td></tr>';
    $html .= '<tr><th>Destination</th><td>' . esc_html($entry->destination) . '</td></tr>';
    $html .= '<tr><th>Code HTTP</th><td>' . intval($entry->response_code) . '</td></tr>';
    
    if ($entry->response_body) {
        $html .= '<tr><th>Reponse</th><td><pre style="background:#f5f5f5; padding:10px; max-height:200px; overflow:auto;">' . esc_html($entry->response_body) . '</pre></td></tr>';
    }
    
    $html .= '</table>';
    
    wp_send_json_success($html);
}

// =============================================================================
// EXPORT CSV
// =============================================================================
add_action('admin_post_mbtc_export_audit_csv', 'mbtc_export_audit_csv');
function mbtc_export_audit_csv() {
    if (!current_user_can('manage_options')) {
        wp_die('Permission refusee');
    }
    
    check_admin_referer('mbtc_export');
    
    global $wpdb;
    $table = $wpdb->prefix . 'mbtc_audit';
    
    // Recuperer toutes les entrees (ou les 5000 dernieres pour performance)
    $entries = $wpdb->get_results("SELECT * FROM $table ORDER BY timestamp DESC LIMIT 10000");
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=mbtc-audit-' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // BOM pour Excel (indispensable pour l'UTF-8)
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Entetes CSV
    fputcsv($output, [
        'ID', 'Date', 'Utilisateur', 'IP', 'Fichier', 'Taille (octets)', 'Hash SHA256',
        'Categorie', 'Reference', 'TXID', 'Destination', 'Code HTTP', 'Statut'
    ]);
    
    foreach ($entries as $e) {
        fputcsv($output, [
            intval($e->id),
            mb_convert_encoding($e->timestamp, 'UTF-8', 'UTF-8'),
            mb_convert_encoding($e->username, 'UTF-8', 'UTF-8'),
            mb_convert_encoding($e->ip_address, 'UTF-8', 'UTF-8'),
            mb_convert_encoding($e->filename, 'UTF-8', 'UTF-8'),
            intval($e->filesize),
            $e->file_hash,
            mb_convert_encoding($e->category, 'UTF-8', 'UTF-8'),
            mb_convert_encoding($e->reference, 'UTF-8', 'UTF-8'),
            $e->txid,
            mb_convert_encoding($e->destination, 'UTF-8', 'UTF-8'),
            intval($e->response_code),
            $e->response_code == 200 ? 'Succes' : 'Echec'
        ]);
    }
    
    fclose($output);
    exit;
}

// =============================================================================
// SHORTCODE FRONT-END (CORRIGE UTF-8)
// =============================================================================
add_shortcode('mbtc_submit', 'mbtc_shortcode_submit');
function mbtc_shortcode_submit() {
    // FORCER L'UTF-8 ABSOLUMENT
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    
    if (!is_user_logged_in()) {
        return '<div style="padding:20px; background:#f8d7da; border-left:4px solid #dc3545; border-radius:4px;">
                    <strong>[!] Acces Restreint</strong><br>
                    Veuillez vous connecter pour soumettre une preuve.
                </div>';
    }

    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
    </head>
    <body>
    <div style="max-width:600px; margin:20px auto; padding:30px; border:1px solid #ddd; border-radius:10px; background:#fff;">
        <h3 style="margin-top:0; color:#333;">[+] Soumettre une preuve</h3>
        
        <form id="mbtc-submit-form" enctype="multipart/form-data" accept-charset="UTF-8">
            <?php wp_nonce_field('mbtc_nonce', 'mbtc_nonce_field'); ?>
            
            <div style="margin-bottom:20px;">
                <label style="display:block; margin-bottom:8px; font-weight:600;">Fichier (JSON ou GPG) :</label>
                <input type="file" id="proof_file" name="proof" required accept=".json,.gpg" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;">
                <div id="file-error" style="color:#dc3545; font-size:13px; margin-top:5px; display:none;"></div>
            </div>
            
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:20px;">
                <div>
                    <label style="display:block; margin-bottom:5px;">Categorie</label>
                    <select name="category" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;">
                        <option value="">-- Optionnel --</option>
                        <option value="juridique">[L] Juridique</option>
                        <option value="preuve">[P] Preuve</option>
                        <option value="contrat">[C] Contrat</option>
                        <option value="autre">[A] Autre</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; margin-bottom:5px;">Reference</label>
                    <input type="text" name="reference" placeholder="ex: DOSSIER-001" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;">
                </div>
            </div>
            
            <button type="submit" id="submit-btn" style="width:100%; padding:12px; background:#0073aa; color:white; border:none; border-radius:4px; font-size:16px; cursor:pointer;">
                [+] Envoyer
            </button>
        </form>
        
        <div id="result" style="margin-top:20px; padding:15px; border-radius:4px; display:none;"></div>
        
        <div style="margin-top:20px; font-size:12px; color:#666; text-align:center;">
            Formats: .json, .gpg | Max: 10 Mo | Toutes les soumissions sont journalisees
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // Forcer l'UTF-8 dans les requetes AJAX
        $.ajaxSetup({
            contentType: "application/x-www-form-urlencoded; charset=UTF-8"
        });
        
        $('#mbtc-submit-form').on('submit', function(e) {
            e.preventDefault();
            
            var fileInput = document.getElementById('proof_file');
            var file = fileInput.files[0];
            var $error = $('#file-error');
            var $btn = $('#submit-btn');
            var $result = $('#result');
            
            $error.hide();
            
            if (!file) {
                $error.text('Veuillez selectionner un fichier.').show();
                return;
            }
            
            var ext = file.name.split('.').pop().toLowerCase();
            if (ext !== 'json' && ext !== 'gpg') {
                $error.text('Format non autorise. Utilisez .json ou .gpg.').show();
                return;
            }
            
            if (file.size > <?= MBTC_MAX_SIZE ?>) {
                $error.text('Fichier trop volumineux (max 10 Mo).').show();
                return;
            }
            
            $btn.prop('disabled', true).text('[.] Envoi en cours...');
            $result.hide().removeClass('success error');
            
            var formData = new FormData(this);
            formData.append('action', 'mbtc_frontend_upload');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(r) {
                    $btn.prop('disabled', false).text('[+] Envoyer');
                    
                    if (r.success) {
                        $result.html('[V] ' + r.data).addClass('success').show();
                        $('#mbtc-submit-form')[0].reset();
                    } else {
                        $result.html('[X] ' + r.data).addClass('error').show();
                    }
                },
                error: function(xhr, status, error) {
                    $btn.prop('disabled', false).text('[+] Envoyer');
                    $result.html('[X] Erreur de connexion: ' + error).addClass('error').show();
                }
            });
        });
    });
    </script>
    
    <style>
    #result.success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    #result.error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
    </style>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

// =============================================================================
// TRAITEMENT FRONT-END AJAX
// =============================================================================
add_action('wp_ajax_mbtc_frontend_upload', 'mbtc_handle_frontend_upload');
function mbtc_handle_frontend_upload() {
    check_ajax_referer('mbtc_nonce', 'mbtc_nonce_field');
    
    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error('Utilisateur non connecte');
    }
    
    if (empty($_FILES['proof'])) {
        wp_send_json_error('Aucun fichier recu');
    }
    
    $file = $_FILES['proof'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error('Erreur upload: ' . $file['error']);
    }
    
    // Validations
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['json', 'gpg'])) {
        wp_send_json_error('Format de fichier non autorise');
    }
    
    if ($file['size'] > MBTC_MAX_SIZE) {
        wp_send_json_error('Fichier trop volumineux (max 10 Mo)');
    }
    
    $api_url = get_option('mbtc_api_url');
    $token = get_option('mbtc_api_token');
    
    if (!$api_url || !$token) {
        mbtc_log_event('ERROR', 'Configuration API manquante', $user_id, $file['name']);
        wp_send_json_error('Configuration API manquante');
    }
    
    // Preparer l'envoi
    $boundary = wp_generate_password(24, false);
    $body = "--$boundary\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"" . basename($file['name']) . "\"\r\n";
    $body .= "Content-Type: application/octet-stream\r\n\r\n";
    $body .= file_get_contents($file['tmp_name']) . "\r\n";
    $body .= "--$boundary--\r\n";
    
    $response = wp_remote_post($api_url, [
        'headers' => [
            'X-Token' => $token,
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        ],
        'body' => $body,
        'timeout' => 45,
        'sslverify' => false,
    ]);
    
    $response_code = 0;
    $response_body = '';
    $txid = '';
    
    if (is_wp_error($response)) {
        $response_body = $response->get_error_message();
        mbtc_log_event('NETWORK', 'Echec: ' . $response_body, $user_id, $file['name']);
    } else {
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if ($response_code === 200 && isset($data['status']) && $data['status'] === 'ok') {
            $txid = $data['filename'] ?? '';
            mbtc_log_event('SUCCESS', 'Fichier accepte: ' . $txid, $user_id, $file['name']);
        } else {
            mbtc_log_event('API_ERROR', 'Rejete (HTTP ' . $response_code . ')', $user_id, $file['name']);
        }
    }
    
    // Journaliser dans la base de donnees
    mbtc_audit_insert([
        'user_id' => $user_id,
        'username' => wp_get_current_user()->user_login,
        'filename' => $file['name'],
        'filesize' => $file['size'],
        'file_path' => $file['tmp_name'],
        'category' => $_POST['category'] ?? '',
        'reference' => $_POST['reference'] ?? '',
        'destination' => 'MBTC-API',
        'response_code' => $response_code,
        'response_body' => $response_body,
        'txid' => $txid,
    ]);
    
    if ($response_code === 200) {
        wp_send_json_success('Fichier envoye avec succes. ID: ' . $txid);
    } else {
        wp_send_json_error('Erreur lors de l\'envoi au VPS');
    }
}

// =============================================================================
// ENREGISTREMENT DES OPTIONS
// =============================================================================
add_action('admin_init', function() {
    register_setting('mbtc_group', 'mbtc_api_url');
    register_setting('mbtc_group', 'mbtc_api_token');
});

// =============================================================================
// CHARGEMENT DES TRADUCTIONS
// =============================================================================
add_action('plugins_loaded', function() {
    load_plugin_textdomain('mbtc-validator', false, dirname(plugin_basename(__FILE__)) . '/languages');
});
?>

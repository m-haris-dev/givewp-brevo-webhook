<?php
/**
 * Plugin Name: GiveWP to Brevo
 * Description: Handles GiveWP donations and sends donor info to Brevo contact list. Logs all events.
 * Version: 1.0
 * Author: Muhammad Haris
 */

if (!defined('ABSPATH')) exit;

class GiveWP_Brevo_Integration {
    private $api_key;
    private $list_id;
    private $log_file;

    public function __construct() {
        $this->api_key = get_option('givewp_brevo_api_key');
        $this->list_id = get_option('givewp_brevo_list_id');
        $this->log_file = WP_CONTENT_DIR . '/givewp_brevo_log.txt';

        // Ensure log file exists
        if (!file_exists($this->log_file)) {
            file_put_contents($this->log_file, "Log file created on " . date('Y-m-d H:i:s') . "\n");
        }

        // Register webhook handler
        add_action('rest_api_init', [$this, 'register_webhook_endpoint']);

        // Admin settings & logs menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**  Register Webhook */
    public function register_webhook_endpoint() {
        register_rest_route('givewp-brevo/v1', '/donation/', [
            'methods'  => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true'
        ]);
    }

    /** Handle Incoming Webhook */
    public function handle_webhook(WP_REST_Request $request) {
        $body = $request->get_json_params();
        $this->log_event("Webhook Received: " . json_encode($body));

        $donation_data = $body['data']['donation'] ?? null;
        
        if (!$donation_data || empty($donation_data['email'])) {
            $this->log_event("Error: Donor email missing");
            return new WP_REST_Response(['error' => 'Donor email missing'], 400);
        }

        $email = sanitize_email($donation_data['email']);
        $first_name = sanitize_text_field($donation_data['firstName'] ?? '');
        $last_name = sanitize_text_field($donation_data['lastName'] ?? '');

        if (!$this->api_key || !$this->list_id) {
            $this->log_event("Error: Brevo API Key or List ID missing");
            return new WP_REST_Response(['error' => 'Brevo API Key or List ID missing'], 500);
        }

        // Prepare payload
        $post_data = [
            'email' => $email,
            'attributes' => ['FIRSTNAME' => $first_name, 'LASTNAME' => $last_name],
            'listIds' => [(int) $this->list_id]
        ];

        // Send to Brevo API
        $response = wp_remote_post('https://api.brevo.com/v3/contacts', [
            'method'    => 'POST',
            'headers'   => [
                'accept'        => 'application/json',
                'api-key'       => $this->api_key,
                'content-type'  => 'application/json'
            ],
            'body'      => json_encode($post_data)
        ]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_event("Brevo API Error: $error_message");
            return new WP_REST_Response(['error' => $error_message], 500);
        }

        $this->log_event("Success: Donor added to Brevo - $email");
        return new WP_REST_Response(['success' => 'Donor added successfully', 'email' => $email], 200);
    }

    /**  Fetch Contact Lists from Brevo */
    private function fetch_brevo_lists($api_key) {
        $response = wp_remote_get('https://api.brevo.com/v3/contacts/lists', [
            'headers' => [
                'accept'        => 'application/json',
                'api-key'       => $api_key
            ]
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['lists'])) {
            return [];
        }

        $lists = [];
        foreach ($body['lists'] as $list) {
            $lists[$list['id']] = $list['name'];
        }

        return $lists;
    }

    /**  Add Admin Menu */
    public function add_admin_menu() {
        add_menu_page('GiveWP Brevo', 'GiveWP Brevo', 'manage_options', 'givewp-brevo', [$this, 'settings_page'], 'dashicons-email-alt');
        add_submenu_page('givewp-brevo', 'Logs', 'Logs', 'manage_options', 'givewp-brevo-logs', [$this, 'display_logs']);
    }

    /**  Register Plugin Settings */
    public function register_settings() {
        register_setting('givewp_brevo_settings', 'givewp_brevo_api_key');
        register_setting('givewp_brevo_settings', 'givewp_brevo_list_id');
    }

    /**  Settings Page */
    public function settings_page() {
        $api_key = get_option('givewp_brevo_api_key');
        $selected_list = get_option('givewp_brevo_list_id');
        $lists = $api_key ? $this->fetch_brevo_lists($api_key) : [];

        ?>
        <div class="wrap">
            <h1>GiveWP to Brevo Integration</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('givewp_brevo_settings');
                do_settings_sections('givewp_brevo_settings');
                ?>
                <table class="form-table">
                    <tr>
                        <th><label for="givewp_brevo_api_key">Brevo API Key</label></th>
                        <td><input type="text" name="givewp_brevo_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="givewp_brevo_list_id">Brevo List</label></th>
                        <td>
                            <select name="givewp_brevo_list_id">
                                <option value="">Select a List</option>
                                <?php foreach ($lists as $id => $name) : ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($selected_list, $id); ?>>
                                        <?php echo esc_html($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**  Display Logs in Table */
    public function display_logs() {
        $logs = file_exists($this->log_file) ? file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        ?>
        <div class="wrap">
            <h1>GiveWP to Brevo Logs</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="20%">Timestamp</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log) :
                        list($timestamp, $message) = explode(" - ", $log, 2); ?>
                        <tr>
                            <td><?php echo esc_html($timestamp); ?></td>
                            <td><?php echo esc_html($message); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**  Log Events */
    private function log_event($message) {
        file_put_contents($this->log_file, date('Y-m-d H:i:s') . " - " . $message . "\n", FILE_APPEND);
    }
}

new GiveWP_Brevo_Integration();

<?php
if (!defined('ABSPATH')) exit;

final class RIZ_PWGC_Account {
    private static $instance = null;
    private $endpoint = 'gift-cards';

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'add_endpoint'));
        add_filter('query_vars', array($this, 'query_vars'));
        add_filter('woocommerce_account_menu_items', array($this, 'account_menu_item'), 40);
        add_action('woocommerce_account_' . $this->endpoint . '_endpoint', array($this, 'render_endpoint'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
    }

    public static function activate() {
        $self = self::instance();
        $self->add_endpoint();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public function add_endpoint() {
        add_rewrite_endpoint($this->endpoint, EP_ROOT | EP_PAGES);
    }

    public function query_vars($vars) {
        $vars[] = $this->endpoint;
        return $vars;
    }

    public function account_menu_item($items) {
        $logout = array();
        if (isset($items['customer-logout'])) {
            $logout['customer-logout'] = $items['customer-logout'];
            unset($items['customer-logout']);
        }

        $items[$this->endpoint] = __('Gift Cards', 'riz-pwgc-account');
        return $items + $logout;
    }

    public function enqueue_styles() {
        if (!is_account_page()) return;

        wp_register_style('riz-pwgc-account', false, array(), '1.3.0');
        wp_enqueue_style('riz-pwgc-account');
        wp_add_inline_style('riz-pwgc-account', '
            .riz-pwgc-wrap{margin-top:10px}.riz-pwgc-table{width:100%;border-collapse:collapse}.riz-pwgc-table th,.riz-pwgc-table td{border:1px solid #ddd;padding:10px;text-align:left}.riz-pwgc-table th{background:#f7f7f7}.riz-pwgc-card-number{font-family:monospace}.riz-pwgc-empty{padding:14px;border:1px solid #ddd;background:#fff}.riz-pwgc-error{padding:14px;border-left:4px solid #b32d2e;background:#fff}
            @media(max-width:768px){.riz-pwgc-table thead{display:none}.riz-pwgc-table tr{display:block;margin-bottom:12px;border:1px solid #ddd}.riz-pwgc-table td{display:flex;justify-content:space-between;border:0;border-bottom:1px solid #eee}.riz-pwgc-table td:before{content:attr(data-label);font-weight:600;margin-right:15px}}
        ');
    }

    public function render_endpoint() {
        if (!is_user_logged_in()) {
            echo '<p>' . esc_html__('Please log in to view your gift cards.', 'riz-pwgc-account') . '</p>';
            return;
        }

        if (!class_exists('WooCommerce')) {
            echo '<div class="riz-pwgc-error">WooCommerce is required.</div>';
            return;
        }

        $cards = $this->get_user_gift_cards(get_current_user_id());

        echo '<div class="riz-pwgc-wrap">';
        echo '<h3>' . esc_html__('My Gift Cards', 'riz-pwgc-account') . '</h3>';

        if (empty($cards)) {
            echo '<div class="riz-pwgc-empty">' . esc_html__('No gift cards found for your account.', 'riz-pwgc-account') . '</div>';
            echo '</div>';
            return;
        }
        echo '<table class="shop_table shop_table_responsive riz-pwgc-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Card Number', 'riz-pwgc-account') . '</th>';
        echo '<th>' . esc_html__('Original Amount', 'riz-pwgc-account') . '</th>';
        echo '<th>' . esc_html__('Current Balance', 'riz-pwgc-account') . '</th>';
        echo '<th>' . esc_html__('Used Balance', 'riz-pwgc-account') . '</th>';
        echo '<th>' . esc_html__('Create Date', 'riz-pwgc-account') . '</th>';
        echo '<th>' . esc_html__('Expiration Date', 'riz-pwgc-account') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($cards as $card) {
            $used_balance = max(0, (float) $card['original_amount'] - (float) $card['balance']);
            echo '<tr>';
            echo '<td data-label="' . esc_attr__('Card Number', 'riz-pwgc-account') . '" class="riz-pwgc-card-number">' . esc_html($card['number']) . '</td>';
            echo '<td data-label="' . esc_attr__('Original Amount', 'riz-pwgc-account') . '">' . wp_kses_post(wc_price($card['original_amount'])) . '</td>';
            echo '<td data-label="' . esc_attr__('Current Balance', 'riz-pwgc-account') . '">' . wp_kses_post(wc_price($card['balance'])) . '</td>';
            echo '<td data-label="' . esc_attr__('Used Balance', 'riz-pwgc-account') . '">' . wp_kses_post(wc_price($used_balance)) . '</td>';
            echo '<td data-label="' . esc_attr__('Create Date', 'riz-pwgc-account') . '">' . esc_html($this->format_date($card['create_date'])) . '</td>';
            echo '<td data-label="' . esc_attr__('Expiration Date', 'riz-pwgc-account') . '">' . esc_html($this->format_date($card['expiration_date'], true)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    private function get_user_gift_cards($user_id) {
        global $wpdb;

        $user = get_userdata($user_id);
        if (!$user) return array();

        $emails = array_filter(array_unique(array(
            $user->user_email,
            get_user_meta($user_id, 'billing_email', true),
        )));

        $gift_table = $this->find_table(array('pimwick_gift_card', 'pw_gift_card'));
        $activity_table = $this->find_table(array('pimwick_gift_card_activity', 'pw_gift_card_activity'));
        if (!$gift_table) return array();

        $gift_columns = $this->get_columns($gift_table);
        $activity_columns = $activity_table ? $this->get_columns($activity_table) : array();

        $id_col      = $this->first_existing($gift_columns, array('pimwick_gift_card_id', 'gift_card_id', 'id'));
        $number_col  = $this->first_existing($gift_columns, array('number', 'card_number', 'gift_card_number'));
        $balance_col = $this->first_existing($gift_columns, array('balance', 'amount', 'remaining_balance'));
        $created_col = $this->first_existing($gift_columns, array('create_date', 'created_date', 'date_created', 'created_at'));
        $expiry_col  = $this->first_existing($gift_columns, array('expiration_date', 'expiry_date', 'expires', 'expire_date'));
        $active_col  = $this->first_existing($gift_columns, array('active', 'is_active'));

        if (!$id_col || !$number_col) return array();

        $where_parts = array();
        $params = array();

        foreach (array('recipient_email', 'customer_email', 'email', 'to_email') as $email_col) {
            if (in_array($email_col, $gift_columns, true)) {
                $placeholders = implode(',', array_fill(0, count($emails), '%s'));
                $where_parts[] = "g.`$email_col` IN ($placeholders)";
                $params = array_merge($params, $emails);
            }
        }

        // Do NOT match by activity user_id or purchaser order id.
        // PW Gift Cards activity user is usually purchaser/admin, not the receiver.

        // Match receiver email saved in WooCommerce order item meta.
        if ($activity_table && in_array('note', $activity_columns, true)) {
            $item_ids = $this->get_recipient_order_item_ids($emails);
            if (!empty($item_ids)) {
                $order_note_parts = array();
                foreach ($item_ids as $order_item_id) {
                    $order_note_parts[] = "a.`note` LIKE %s";
                    $params[] = '%order_item_id: ' . absint($order_item_id) . '%';
                }
                $where_parts[] = "EXISTS (SELECT 1 FROM `$activity_table` a WHERE a.`$id_col` = g.`$id_col` AND (" . implode(' OR ', $order_note_parts) . "))";
            }

            // Fallback: some custom PW versions include receiver email directly in activity note.
            $email_note_parts = array();
            foreach ($emails as $email) {
                $email_note_parts[] = "LOWER(a.`note`) LIKE %s";
                $params[] = '%' . $wpdb->esc_like(strtolower($email)) . '%';
            }
            $where_parts[] = "EXISTS (SELECT 1 FROM `$activity_table` a WHERE a.`$id_col` = g.`$id_col` AND (" . implode(' OR ', $email_note_parts) . "))";
        }

        $where = empty($where_parts) ? '1=0' : '(' . implode(' OR ', $where_parts) . ')';
        if ($active_col) $where .= " AND g.`$active_col` = 1";

        $select_balance = $balance_col ? "g.`$balance_col`" : '0';
        if (!$balance_col && $activity_table && in_array('amount', $activity_columns, true)) {
            $select_balance = "(SELECT COALESCE(SUM(a.`amount`),0) FROM `$activity_table` a WHERE a.`$id_col` = g.`$id_col`)";
        }

        // Original amount = highest historical balance where possible.
        // PW keeps transactions in the activity table, so this remains compatible even after partial redemption.
        $select_original_amount = $select_balance;
        if ($activity_table && in_array('balance', $activity_columns, true)) {
            $select_original_amount = "(SELECT COALESCE(MAX(a.`balance`), $select_balance) FROM `$activity_table` a WHERE a.`$id_col` = g.`$id_col`)";
        } elseif ($activity_table && in_array('amount', $activity_columns, true)) {
            $select_original_amount = "(SELECT COALESCE(SUM(CASE WHEN a.`amount` > 0 THEN a.`amount` ELSE 0 END), $select_balance) FROM `$activity_table` a WHERE a.`$id_col` = g.`$id_col`)";
        }

        $sql = "SELECT DISTINCT g.`$id_col` AS id, g.`$number_col` AS number, $select_original_amount AS original_amount, $select_balance AS balance";
        $sql .= $created_col ? ", g.`$created_col` AS create_date" : ", NULL AS create_date";
        $sql .= $expiry_col ? ", g.`$expiry_col` AS expiration_date" : ", NULL AS expiration_date";
        $sql .= " FROM `$gift_table` g WHERE $where ORDER BY g.`$id_col` DESC";

        $prepared = !empty($params) ? $wpdb->prepare($sql, $params) : $sql;
        $rows = $wpdb->get_results($prepared, ARRAY_A);

        return apply_filters('riz_pwgc_account_user_cards', $rows ?: array(), $user_id, $emails);
    }

    private function get_recipient_order_item_ids($emails) {
        global $wpdb;

        $emails = array_values(array_filter(array_unique(array_map('strtolower', $emails))));
        if (empty($emails)) return array();

        $meta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $items_table = $wpdb->prefix . 'woocommerce_order_items';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $meta_table)) !== $meta_table) return array();

        $likely_keys = array(
            'pwgc_to', '_pwgc_to', 'pwgc_recipient_email', '_pwgc_recipient_email',
            'pw_gift_card_to', '_pw_gift_card_to', 'pw_gift_card_recipient_email', '_pw_gift_card_recipient_email',
            'recipient_email', '_recipient_email', 'gift_card_recipient_email', '_gift_card_recipient_email',
            'to', '_to', 'email', '_email', 'To', 'Recipient Email', 'Recipient email', 'Send To', 'Send to'
        );

        $email_placeholders = implode(',', array_fill(0, count($emails), '%s'));
        $key_placeholders = implode(',', array_fill(0, count($likely_keys), '%s'));

        $sql = "SELECT DISTINCT m.order_item_id
                FROM `$meta_table` m
                LEFT JOIN `$items_table` oi ON oi.order_item_id = m.order_item_id
                WHERE oi.order_item_type = 'line_item'
                AND LOWER(TRIM(m.meta_value)) IN ($email_placeholders)
                AND (
                    m.meta_key IN ($key_placeholders)
                    OR LOWER(m.meta_key) LIKE %s
                    OR LOWER(m.meta_key) LIKE %s
                    OR LOWER(m.meta_key) LIKE %s
                )";

        $params = array_merge($emails, $likely_keys, array('%pwgc%', '%recipient%', '%gift%email%'));
        return array_map('absint', $wpdb->get_col($wpdb->prepare($sql, $params)));
    }

    private function find_table($names) {
        global $wpdb;
        foreach ($names as $name) {
            $table = $wpdb->prefix . $name;
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists === $table) return $table;
        }
        return false;
    }

    private function get_columns($table) {
        global $wpdb;
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `$table`", 0);
        return is_array($cols) ? $cols : array();
    }

    private function first_existing($columns, $candidates) {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) return $candidate;
        }
        return false;
    }

    private function format_date($date, $none_if_empty = false) {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return $none_if_empty ? __('None', 'riz-pwgc-account') : '-';
        }
        $timestamp = strtotime($date);
        if (!$timestamp) return $date;
        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }
}

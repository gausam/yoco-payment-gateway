<?php

class class_yoco_wc_error
{
    function __construct()
    {
        $this->create_error_table();
        add_filter( 'manage_edit-shop_order_columns', [$this,'add_order_error_column_header'], 20 );
        add_action( 'manage_shop_order_posts_custom_column', [$this,'add_order_error_column_content']);
    }

    private function create_error_table() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        global $wpdb;
        $table_name = $wpdb->prefix.'yoco_order_errors';
        // Check if table exists
        $sql = "show tables LIKE '".$table_name."'";
        $res = $wpdb->get_results($sql, 'ARRAY_A');
        if (count($res) == 0) {
            $create_ddl = 'CREATE TABLE `' . $table_name . '` (
        `id` mediumint(9) NOT NULL AUTO_INCREMENT,
        `order_id` mediumint(9) DEFAULT NULL,
        `error_code` VARCHAR(25),
        `error_msg` VARCHAR(255),
        UNIQUE KEY `id` (`id`)
        );';
            maybe_create_table('yoco_customer_order_error', $create_ddl);
        }
    }

    public function add_order_error_column_header( $columns ) {

        $new_columns = array();

        foreach ( $columns as $column_name => $column_info ) {

            $new_columns[ $column_name ] = $column_info;

            if ( 'order_status' === $column_name ) {
                $new_columns['order_error'] = __( 'Error', 'yoco_wc_payment_gateway' );
            }
        }

        return $new_columns;
    }

    public function add_order_error_column_content( $column ) {
        global $post;
        global $wpdb;
        $table_name = $wpdb->prefix.'yoco_order_errors';

        if ( 'order_error' === $column ) {
            $order    = wc_get_order( $post->ID );
            $sql = "SELECT error_code, error_msg FROM $table_name WHERE order_id = ".$post->ID. " ORDER BY id DESC LIMIT 1";
            $res = $wpdb->get_results($sql, 'ARRAY_A');
            if ($res) {
//                $output = '<strong>Code: </strong> <em>'.$res[0]['error_code'].'</em><br><strong>Message: </strong> <em>'.$res[0]['error_msg'].'</em>';
                $output = '<em>'.$res[0]['error_msg'].'</em>';
                echo $output;
            }

        }
    }


    static function save_yoco_customer_order_error($order_id, $code, $message) {
        global $wpdb;
        $table_name = $wpdb->prefix.'yoco_order_errors';
        $prep_stmt = "INSERT INTO $table_name (order_id,error_code,error_msg) VALUES (%d,%s, %s)";
        $sql = $wpdb->prepare($prep_stmt, $order_id, $code, $message);
        $res = $wpdb->get_results($sql);
    }
}

(new class_yoco_wc_error());
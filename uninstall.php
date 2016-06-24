<?php

register_uninstall_hook( __FILE__, 'bik_parser_uninstall');
function bik_parser_uninstall() {
	if ( !defined( 'ABSPATH' ) && !defined( 'WP_UNINSTAL_PLUGIN' ) ) exit();
	global $wpdb;
	$table_name = $wpdb->prefix . 'bik'; //полное имя таблицы
	$sql = "DROP TABLE IF EXISTS `$table_name`";
	$wpdb->query( $sql );
	
}
?>

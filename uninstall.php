<?php
/**
 * 插件卸载清理
 * 
 * 删除插件时自动清理数据库中的选项和文章元数据
 * 
 * @package WXS_Zibll_PostChat
 */

// WordPress卸载安全检查
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// 删除插件选项
delete_option( 'wxs_postchat_settings' );
delete_option( 'wxs_postchat_db_version' );

// 批量删除文章元数据
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_wxs_postchat_summary', '_wxs_postchat_content_hash')" );

// 清理残留的计划任务
$crons = _get_cron_array();
if ( ! empty( $crons ) ) {
    $modified = false;
    foreach ( $crons as $timestamp => $cron ) {
        if ( isset( $cron['wxs_postchat_generate_summary_event'] ) ) {
            unset( $crons[ $timestamp ]['wxs_postchat_generate_summary_event'] );
            if ( empty( $crons[ $timestamp ] ) ) {
                unset( $crons[ $timestamp ] );
            }
            $modified = true;
        }
    }
    if ( $modified ) {
        _set_cron_array( $crons );
    }
}

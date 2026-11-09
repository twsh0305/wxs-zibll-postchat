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
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (
    '_wxs_postchat_summary',
    '_wxs_postchat_content_hash',
    '_wxs_postchat_glossary',
    '_wxs_postchat_glossary_hash'
)" );

// 清理残留的计划任务
$crons = _get_cron_array();
if ( ! empty( $crons ) ) {
    $hooks_to_clean = [
        'wxs_postchat_generate_summary_event',
        'wxs_local_generate_summary_event',
        'wxs_local_generate_glossary_event',
    ];
    $modified = false;
    foreach ( $crons as $timestamp => $cron ) {
        foreach ( $hooks_to_clean as $hook ) {
            if ( isset( $cron[ $hook ] ) ) {
                unset( $crons[ $timestamp ][ $hook ] );
                $modified = true;
            }
        }
        if ( empty( $crons[ $timestamp ] ) ) {
            unset( $crons[ $timestamp ] );
        }
    }
    if ( $modified ) {
        _set_cron_array( $crons );
    }
}

// 清理 transient 锁
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wxs_summary_visit_%' OR option_name LIKE '_transient_timeout_wxs_summary_visit_%' OR option_name LIKE '_transient_wxs_glossary_visit_%' OR option_name LIKE '_transient_timeout_wxs_glossary_visit_%' OR option_name LIKE '_transient_wxs_recommend_%' OR option_name LIKE '_transient_timeout_wxs_recommend_%' OR option_name LIKE '_transient_wxs_chat_rate_%' OR option_name LIKE '_transient_timeout_wxs_chat_rate_%' OR option_name LIKE '_transient_wxs_chat_daily_%' OR option_name LIKE '_transient_timeout_wxs_chat_daily_%'" );

// 删除对话记录表
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wxs_chat_logs" );

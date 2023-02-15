<?php

/**
 * Plugin Name:     Algolia Custom Integration
 * Description:     Index WordPress content in Algolia
 * Text Domain:     algolia-custom-integration
 * Version:         1.0.0
 * Author:          Upstatement
 * Author URI:      https://www.upstatement.com
 *
 * @package Algolia_Custom_Integration
 */

// https://www.algolia.com/doc/integration/wordpress/indexing/setting-up-algolia/?language=php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/wp-cli.php';
require_once __DIR__ . '/serializer.php';
require_once __DIR__ . '/algolia_index_cron.php';

add_action('init', 'algolia_init');
add_filter('get_algolia_index_name', 'get_algolia_index_name');
add_filter('update_records', 'update_records', 10, 2);
add_action('save_post', 'algolia_save_post', 10, 3);

/**
 * Initialize Algolia PHP search client
 */
function algolia_init() {
    global $algolia;

    $algolia = \Algolia\AlgoliaSearch\SearchClient::create(
        getenv('ALGOLIA_APPLICATION_ID'),
        getenv('ALGOLIA_ADMIN_API_KEY')
    );

    $algoliaSerializer = new AlgoliaSerializer();
    $algoliaSerializer->run();
}

/**
 * Returns a prefixed Algolia index name for the given name
 * https://www.algolia.com/doc/integration/wordpress/indexing/importing-content/?language=php#customizing-algolia-index-name
 *
 * @param string $name index name without prefix
 *
 * @return string example: local_wp_ (with no $name) or local_wp_global_search
 */
function get_algolia_index_name($name = '') {
    global $wpdb;

    $env_prefix = getenv('ALGOLIA_INDEX_PREFIX') ?: ''; // local, dev, stage, prod, etc.
    $base_prefix = $wpdb->base_prefix; // wp_

    return "${env_prefix}_${base_prefix}${name}";
}

/**
 * Handles split content updating and saving of records in the specified Algolia index
 *
 * @param string $index_name suffix for Algolia index name
 * @param array $records Algolia records to update
 */
function update_records($index_name = 'global_search', $records) {
    global $algolia;

    $canonical_index_name = apply_filters('get_algolia_index_name', $index_name);
    $index = $algolia->initIndex($canonical_index_name);

    // Delete all records using the distinct_key attribute
    $filter_to_delete = 'distinct_key:'.$records[0]['distinct_key'];
    // Make sure to delete split records if they exist
    $index->deleteBy(['filters' => $filter_to_delete]);
    // Then save
    $index->saveObjects($records);
}

/**
 * Automatically reindexes records in Algolia when a post is saved
 * https://www.algolia.com/doc/integration/wordpress/indexing/automatic-updates/?language=php
 *
 * @param integer $id     Post ID
 * @param object  $post   Post object
 * @param bool    $update Whether this is an existing post getting updated
 *
 * @return array
 */
function algolia_save_post($id, $post, $update) {
    global $algolia;

    $post_type = $post->post_type;
    $post_status = $post->post_status;

    $searchable_post_types = getSearchablePostTypes();

    // Bail early if the post being saved is not one of the searchable post types
    if (!in_array($post_type, $searchable_post_types)) {
        return;
    }

    // Only reindex posts that have been published or trashed
    $is_invalid_status = $post_status != 'publish' && $post_status != 'trash';

    if (wp_is_post_revision($id) || wp_is_post_autosave($id) || $is_invalid_status) {
        return $post;
    }

    $filter_name = $post_type.'_to_record';

    // Bail early if filter does not exist
    if (!has_filter($filter_name)) {
        return;
    }

    // Serialize post (serializer.php)
    $records = apply_filters($filter_name, $post);
    if (!$records) {
        return $post;
    }
    $records = (array) $records;

    if ($post_status == 'publish') {
        // Update record(s) in global index
        apply_filters('update_records', 'global_search', $records);

        // Also update  people index if post type is faculty, student, or person
        if ($post_type == 'faculty' || $post_type == 'student' || $post_type == 'person') {
            apply_filters('update_records', 'people_search', $records);
        }
    }

    return $post;
}

/*function algolia_reindex_activation(){
    if(!wp_next_scheduled('algolia_reindex_cron')) {
        wp_schedule_event(time(), 'daily', 'algolia_reindex_cron');
    }
}
register_activation_hook(__FILE__, 'algolia_reindex_activation');

function algolia_reindex_deactivation(){
    $timestamp = wp_next_scheduled('algolia_reindex_cron');
    wp_unschedule_event($timestamp, 'algolia_reindex_cron');
}
register_deactivation_hook (__FILE__, 'algolia_reindex_deactivation');

function index_algolia(){
    $algolia_index_object = new algolia_index();
    $algolia_index_object -> reindex_algolia();
}
add_action('algolia_reindex_cron', 'index_algolia');*/

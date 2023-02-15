<?php
/**
 * Serialization logic for Algolia records
 */

define('THEME_PATH', get_stylesheet_directory() . '/');

class AlgoliaSerializer {
    /**
     * Runs initialization tasks.
     *
     * @return void
     */
    public function run() {
        // Bail early if Algolia plugin is not activated.
        if (!function_exists('get_algolia_index_name')) {
            return;
        }

        add_filter('timber/context', array($this, 'add_config_to_context'));

        add_filter('page_to_record', array($this, 'algolia_page_to_record'));
        add_filter('post_to_record', array($this, 'algolia_post_to_record'));
        add_filter('student_to_record', array($this, 'algolia_student_to_record'));
        add_filter('faculty_to_record', array($this, 'algolia_faculty_to_record'));
        add_filter('person_to_record', array($this, 'algolia_person_to_record'));
        add_filter('project_to_record', array($this, 'algolia_project_to_record'));
        add_filter('dialogue_to_record', array($this, 'algolia_dialogue_to_record'));
        add_filter('resource_to_record', array($this, 'algolia_resource_to_record'));
        add_filter('program_to_record', array($this, 'algolia_program_to_record'));

        add_filter('algolia_get_settings', array($this, 'algolia_get_settings'));
        add_filter('algolia_get_synonyms', array($this, 'algolia_get_synonyms'));
        add_filter('algolia_get_rules', array($this, 'algolia_get_rules'));
    }

    /**
     * Pass Algolia environment variables to Timber context
     *
     * @param array $context Timber context
     *
     * @return array
     */
    function add_config_to_context($context) {
        $context['ALGOLIA_APPLICATION_ID'] = getenv('ALGOLIA_APPLICATION_ID');
        $context['ALGOLIA_SEARCH_ONLY_API_KEY'] = getenv('ALGOLIA_SEARCH_ONLY_API_KEY');
        $context['ALGOLIA_INDEX_PREFIX'] = get_algolia_index_name();
        return $context;
    }

    /**
     * Get default attributes for each Algolia record
     *
     * @param object $post    Post to get record of
     * @param object $blog_id ID of current blog
     *
     * @return array
     */
    function getDefaultRecordAttributes($post, $blog_id) {
        return [
            'objectID' => implode('#', [$blog_id, $post->post_type, $post->ID]),
            'distinct_key' => implode('#', [$blog_id, $post->post_type, $post->ID]),
            'blog_id' => $blog_id,
            'type' => $post->post_type,
            'title' => $post->post_title,
            'date' => $post->post_date,
            'timestamp' => strtotime($post->post_date),
            'url' => get_permalink($post->ID),
        ];
    }

    /**
     * Maps the given post taxonomy terms to the term names
     *
     * @param object $post     Post to get the terms of
     * @param string $taxonomy Name of taxonomy
     *
     * @return array
     */
    function getTermNames($post, $taxonomy) {
        $terms = wp_get_post_terms($post->ID, $taxonomy);

        if (!is_array($terms)) {
            return [];
        }

        return array_map(
            function ($term) {
                return $term->name;
            },
            $terms
        );
    }

    /**
     * Split the content into separate records
     *
     * @param string $attr_name Name of attribute to split
     * @param string $content   Content to split
     *
     * @return array
     */
    function splitContent($attr_name, $content) {
        $char_limit = 1000;

        // Split content into 1000 char chunks
        $split_content = str_split(strip_tags($content), $char_limit);
        // Map each content chunk to a record array
        $content_records = array_map(
            function ($val) use ($attr_name) {
                return array($attr_name => $val);
            }, $split_content
        );

        // Sanitize data to support non UTF-8 content
        // https://github.com/algolia/algoliasearch-wordpress/issues/377
        if (function_exists('_wp_json_sanity_check')) {
            return _wp_json_sanity_check($content_records, 512);
        }

        return $content_records;
    }

    /**
     * Get records for the given post
     *
     * @param object $post          Post to get records for
     * @param array  $post_attrs    Post-specific record attributes
     * @param bool   $split_content Whether or not to split the post content
     *
     * @return array
     */
    function serializeRecord($post, $post_attrs = []) {
        $blog_id = get_current_blog_id();
        $records = [];

        // Split records on post_content
        $records = $this->splitContent('content', $post->post_content);

        // Merge all attributes for each split record and add a unique objectID
        foreach ($records as $key => $split) {
            $records[$key] = array_merge(
                $this->getDefaultRecordAttributes($post, $blog_id),
                $post_attrs,
                $split,
                ['objectID' => implode('-', [$blog_id, $post->post_type, $post->ID, $key])]
            );
        };

        return $records;
    }

    /**
     * Converts a Page to a list of Algolia records
     *
     * @param object $post Post to get record of
     *
     * @return array
     */
    function algolia_page_to_record($post) {
        // Front page ID (string)
        $frontpage_id = get_option('page_on_front');
        $is_front_page = strval($post->ID) === $frontpage_id;

        $page_record_attrs = [
            'is_front_page' => $is_front_page,
            'timestamp' => strtotime($post->date),
            'title' => $is_front_page ? get_bloginfo('name') : $post->post_title,
        ];

        return $this->serializeRecord($post, $page_record_attrs);
    }

    /**
     * Converts a Post to an Algolia record
     *
     * @param object $post Post to get record of
     *
     * @return array
     */
    function algolia_post_to_record($post) {
        $tags = $this->getTermNames($post, 'post_tag');
        $categories = $this->getTermNames($post, 'category');
        $topics = $this->getTermNames($post, 'topic');
        $departments = $this->getTermNames($post, 'department');
        $research_centers = $this->getTermNames($post, 'research_center');
        $semesters = $this->getTermNames($post, 'semester');

        $people_field = get_field('people_in_this_story', $post->ID);
        $people = [];

        if ($people_field && $people_field['selected_posts']) {
            $people = array_map(
                function ($person) {
                    return [
                        'id' => $person->ID,
                        'name' => $person->post_title
                    ];
                },
                $people_field['selected_posts']
            );
        }

        $post_record_attrs = [
            'featured_image' => get_the_post_thumbnail_url($post->ID),
            'introduction' => get_field('introduction', $post->ID),
            'timestamp' => strtotime($post->post_date),
            'people' => $people,
            'tags' => $tags,
            'categories' => $categories,
            'topics' => $topics,
            'departments' => $departments,
            'research_centers' => $research_centers,
            'semesters' => $semesters,
        ];

        return $this->serializeRecord($post, $post_record_attrs);
    }

    /**
     * Converts a Student post to an Algolia record
     *
     * @param object $post Post to get record of
     *
     * @return array
     */
    function algolia_student_to_record($post) {
        $student_types = $this->getTermNames($post, 'student_type');
        $degrees = $this->getTermNames($post, 'degree');
        $topics = $this->getTermNames($post, 'topic');
        $departments = $this->getTermNames($post, 'department');
        $research_centers = $this->getTermNames($post, 'research_center');

        $student_record_attrs = [
            'first_name' => get_field('first_name', $post->ID),
            'last_name' => get_field('last_name', $post->ID),
            'timestamp' => strtotime($post->post_date),
            'headshot' => get_the_post_thumbnail_url($post->ID),
            'areas_of_study' => get_field('areas_of_study', $post->ID),
            'pathway' => get_field('pathway', $post->ID),
            'introduction' => strip_tags(get_field('introduction', $post->ID)),
            'graduation_status' => get_field('graduation_status', $post->ID),
            'student_types' => $student_types,
            'degrees' => $degrees,
            'topics' => $topics,
            'departments' => $departments,
            'research_centers' => $research_centers,
        ];

        return $this->serializeRecord($post, $student_record_attrs);
    }

    /**
     * Converts a Faculty post to an Algolia record
     *
     * @param object $post Post to get record of
     *
     * @return array
     */
    function algolia_faculty_to_record($post) {
        $faculty_types = $this->getTermNames($post, 'faculty_type');
        $topics = $this->getTermNames($post, 'topic');
        $departments = $this->getTermNames($post, 'department');
        $research_centers = $this->getTermNames($post, 'research_center');

        $faculty_record_attrs = [
            'first_name' => get_field('first_name', $post->ID),
            'last_name' => get_field('last_name', $post->ID),
            'timestamp' => strtotime($post->post_date),
            'headshot' => get_the_post_thumbnail_url($post->ID),
            'job_title' => get_field('job_title', $post->ID),
            'bio' => strip_tags(get_field('bio', $post->ID)),
            'phone_number' => get_field('phone_number', $post->ID),
            'email_address' => get_field('email_address', $post->ID),
            'faculty_types' => $faculty_types,
            'topics' => $topics,
            'departments' => $departments,
            'research_centers' => $research_centers,
        ];

        return $this->serializeRecord($post, $faculty_record_attrs);
    }

    /**
     * Converts a Person post to an Algolia record
     *
     * @param object $post Post to get record of
     *
     * @return array
     */
    function algolia_person_to_record($post) {
        $topics = $this->getTermNames($post, 'topic');

        $person_types = wp_get_post_terms($post->ID, 'person_type');
        $staff_types = [];
        $friends_partners_types = [];

        // Group person types into 'Staff' and 'Friends & Partners' lists
        if (is_array($person_types)) {
            foreach($person_types as $type) {
                $refinement_list = get_field('refinement_list', $type);

                if ($refinement_list == 'friends_partners') {
                    $friends_partners_types[] = $type->name;
                } else {
                    $staff_types[] = $type->name;
                }
            }
        }
        
        $person_record_attrs = [
            'first_name' => get_field('first_name', $post->ID),
            'last_name' => get_field('last_name', $post->ID),
            'timestamp' => strtotime($post->post_date),
            'headshot' => get_the_post_thumbnail_url($post->ID),
            'job_title' => get_field('job_title', $post->ID),
            'bio' => strip_tags(get_field('bio', $post->ID)),
            'phone_number' => get_field('phone_number', $post->ID),
            'email_address' => get_field('email_address', $post->ID),
            'staff_types' => $staff_types,
            'friends_partners_types' => $friends_partners_types,
            'topics' => $topics,
        ];

        return $this->serializeRecord($post, $person_record_attrs);
    }

    /**
     * Converts a Project post to an Algolia record
     *
     * @param object $post Post to get record of
     *
     * @return array
     */
    function algolia_project_to_record($post) {
        $topics = $this->getTermNames($post, 'topic');
        $departments = $this->getTermNames($post, 'department');
        $research_centers = $this->getTermNames($post, 'research_center');
        $project_types = $this->getTermNames($post, 'project_type');

        $people = get_field('project_team', $post->ID);

        if ($people) {
            $people = array_map(
                function ($person) {
                    return [
                        'id' => $person->ID,
                        'name' => $person->post_title
                    ];
                },
                $people
            );
        }

        $project_record_attrs = [
            'featured_image' => get_the_post_thumbnail_url($post->ID),
            'timestamp' => strtotime($post->post_date),
            'subhead_text' => get_field('subhead_text', $post->ID),
            'people' => $people,
            'topics' => $topics,
            'departments' => $departments,
            'research_centers' => $research_centers,
            'project_types' => $project_types,
        ];

        return $this->serializeRecord($post, $project_record_attrs);
    }

    /**
     * Converts a Dialogue post to an Algolia record
     *
     * @param object $post Post to get record of
     *
     * @return array
     */
    function algolia_dialogue_to_record($post) {
        $topics = $this->getTermNames($post, 'topic');

        $people = get_field('led_by', $post->ID);

        if ($people) {
            $people = array_map(
                function ($person) {
                    return [
                        'id' => $person->ID,
                        'name' => $person->post_title
                    ];
                },
                $people
            );
        }

        $dialogue_record_attrs = [
            'title' => $post->post_title,
            'session' => get_field('session', $post->ID),
            'timestamp' => strtotime($post->post_date),
            'year' => get_field('year', $post->ID),
            'countries' => get_field('countries', $post->ID),
            'people' => $people,
            'topics' => $topics,
        ];

        return $this->serializeRecord($post, $dialogue_record_attrs);
    }

    /**
     * Converts a Resource post to an Algolia record
     *
     * @param object $post Post to get record of
     *
     * @return array
     */
    function algolia_resource_to_record($post) {
        $audiences = $this->getTermNames($post, 'audience');
        $departments = $this->getTermNames($post, 'department');
        $resource_types = $this->getTermNames($post, 'resource_type');

        $resource_link_type = get_field('resource_link_type', $post->ID);
        $link_field = $resource_link_type == 'Link' ? 'resource_link' : 'resource_file';
        $resource_link = get_field($link_field, $post->ID)['url'];

        $resource_record_attrs = [
            'title' => $post->post_title,
            'resource_source' => get_field('resource_source', $post->ID),
            'timestamp' => strtotime($post->post_date),
            'resource_link_type' => $resource_link_type,
            'resource_link' => $resource_link,
            'audiences' => $audiences,
            'departments' => $departments,
            'resource_types' => $resource_types,
        ];

        return $this->serializeRecord($post, $resource_record_attrs);
    }

    /**
     * Converts a Program post to an Algolia record
     *
     * @param object $post Post to get record of
     *
     * @return array
     */
    function algolia_program_to_record($post) {
        $program_types = $this->getTermNames($post, 'program_type');
        $page_type = get_field('page_type', $post->ID);

        // Bail early if this is not a program landing page
        if ($page_type != 'landing') {
            return;
        }

        $program_record_attrs = [
            'title' => $post->post_title,
            'program_types' => $program_types,
            'timestamp' => strtotime($post->post_date),
        ];

        return $this->serializeRecord($post, $program_record_attrs);
    }

    /**
     * Gets the settings for the given index from its local JSON config
     *
     * @param string $index_name Unprefixed index name (e.g. `global_search`).
     *
     * @return array
     */
    function algolia_get_settings($index_name) {
        $settings_file_path = THEME_PATH . 'algolia-json/' . $index_name . '-settings.json';

        if (!file_exists($settings_file_path)) {
            return false;
        }

        return json_decode(
            file_get_contents($settings_file_path),
            true
        );
    }

    /**
     * Gets the synonyms for the given index from its local JSON config
     *
     * @param string $index_name Unprefixed index name (e.g. `global_search`).
     *
     * @return array
     */
    function algolia_get_synonyms($index_name) {
        $settings_file_path = THEME_PATH . 'algolia-json/' . $index_name . '-synonyms.json';

        if (!file_exists($settings_file_path)) {
            return false;
        }

        return json_decode(
            file_get_contents($settings_file_path),
            true
        );
    }

    /**
     * Gets the rules for the given index from its local JSON config
     *
     * @param string $index_name Unprefixed index name (e.g. `global_search`).
     *
     * @return array
     */
    function algolia_get_rules($index_name) {
        $settings_file_path = THEME_PATH . 'algolia-json/' . $index_name . '-rules.json';

        if (!file_exists($settings_file_path)) {
            return false;
        }

        return json_decode(
            file_get_contents($settings_file_path),
            true
        );
    }
}

<?php

class algolia_index {
    public function reindex_algolia() {
        global $algolia;

        $searchable_post_types = getSearchablePostTypes();

        // PART 1: GLOBAL INDEX --------------------------------------------------------

        $global_index_name = apply_filters('get_algolia_index_name', 'global_search');
        $global_index = $algolia->initIndex($global_index_name);

        // Clear the entire index
        //error_log('Clearing all records from index: '.$global_index_name);
        $global_index->clearObjects()->wait();

        // Get all blog IDs in multisite network
        $all_blog_ids = get_sites(array('fields' => 'ids'));

        foreach ($all_blog_ids as $blog_id) {
            switch_to_blog($blog_id);

            //error_log('Indexing posts from '.$blog_id);

            // Loop through searchable post types then serialize and save each post
            foreach ($searchable_post_types as $post_type) {
                $this->serialize_records($global_index, $post_type);
            }

            restore_current_blog();
        }

        // PART 2: PEOPLE INDEX --------------------------------------------------------

        $people_index_name = apply_filters('get_algolia_index_name', 'people_search');
        $people_index = $algolia->initIndex($people_index_name);

        // Clear the entire index
        //error_log('Clearing all records from index: '.$people_index_name);
        $people_index->clearObjects()->wait();

        // Get an array of people post types from all searchable post types
        $people_post_types = array_filter(
            $searchable_post_types,
            function($type) {
                return $type === 'student' || $type === 'faculty' || $type === 'person';
            }
        );

        foreach ($people_post_types as $post_type) {
            //error_log('Indexing people from '.$people_index);
            $this->serialize_records($people_index, $post_type);
        }
    }


    private function serialize_records($index, $post_type) {
        $paged = 1;
        $count = 0;

        do {
            $posts = new WP_Query([
                'posts_per_page' => 100,
                'paged' => $paged,
                'post_type' => $post_type,
                'post_status' => 'publish',
            ]);

            if (!$posts->have_posts()) {
                break;
            }

            try {
                $records = [];

                // Serialize each post to be saved as Algolia records
                foreach ($posts->posts as $post) {
                    //error_log('Serializing: '.$post->post_title);
                    // Use post type to get corresponding serializer function
                    $filter_name = $post->post_type.'_to_record';

                    // Bail early if filter does not exist
                    if (!has_filter($filter_name)) {
                        throw new Exception("No filter called $filter_name");
                    }

                    // The serialize function will take care of splitting large records
                    $split_records = (array) apply_filters($filter_name, $post);
                    $records = array_merge($records, $split_records);

                    $count++;
                }

            } catch (Exception $e) {
                error_log($e->getMessage());
            }

            try {
                $index->saveObjects($records);
                //error_log($count.'records indexed in Algolia');
            } catch (Exception $e) {
                error_log($e->getMessage());
            }

            $paged++;
        } while (true);
    }
}

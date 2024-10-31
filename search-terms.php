<?php
/*
Plugin Name: Search Terms
Description: Improve the chances of converting searches into sales with the Search Terms plugin for WordPress
Version: 0.0.1
Author: 247wd
*/

if (!defined('ABSPATH')) exit;

global $wpdb_st_db_version;
$wpdb_st_db_version = '0.0.1';

function wpdb_st_install() {
    global $wpdb;
    global $wpdb_st_db_version;

    if (! isset($wpdb) || ! isset($wpdb_st_db_version)) {
        return;
    }

    $table_name = $wpdb->prefix . 'wpdb_search_terms';

    $charset_collate = $wpdb->get_charset_collate();

    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $sql = "CREATE TABLE $table_name (
            `st_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `st_query_text` varchar(255) CHARACTER SET utf8 DEFAULT NULL,
            `st_popularity` int(10) UNSIGNED NOT NULL DEFAULT '0',
            `st_results` int(10) UNSIGNED NOT NULL DEFAULT '0',
            `st_synonym` varchar(255) CHARACTER SET utf8 DEFAULT NULL,
            `st_redirect` varchar(255) CHARACTER SET utf8 DEFAULT NULL,
            `st_updated_at` int(10) UNSIGNED DEFAULT NULL,
            PRIMARY KEY (`st_id`),
            KEY `st_query_text` (`st_query_text`),
            KEY `st_synonym` (`st_synonym`),
            KEY `st_redirect` (`st_redirect`)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        dbDelta($sql);
    }

    update_option('wpdb_st_db_version', $wpdb_st_db_version);
}
register_activation_hook(__FILE__, 'wpdb_st_install');

function wpdb_st_sanitize_string($query_string) {
    $query_string = strtolower($query_string);
    $query_string = stripslashes($query_string);
    $query_string = trim($query_string);
    return sanitize_text_field($query_string);
}

function wpdb_st_get_posts($wp_query) {
    global $wpdb;

    if (! isset($wpdb) || ! $wp_query->is_search() || is_admin()) {
        return;
    }

    $query_string = wpdb_st_sanitize_string($wp_query->query['s']);

    $st_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$wpdb->prefix}wpdb_search_terms` WHERE `st_query_text` = %s", $query_string));

    if (! empty($st_row) && ! empty($st_row->st_redirect)) {
        wp_redirect(esc_url_raw($st_row->st_redirect));
        exit;
    } elseif (!empty($st_row) && !empty($st_row->st_synonym)) {
        $wp_query->query['s'] = $st_row->st_synonym;
        $wp_query->query['s_original'] = $query_string;
        $wp_query->query_vars['s'] = $st_row->st_synonym;
    }
}
add_action('pre_get_posts', 'wpdb_st_get_posts');

function wpdb_st_template_redirect() {
    global $wpdb;
    global $wp_query;

    if(! isset($wpdb) || ! $wp_query->is_search() || is_admin()) {
        return;
    }

    if (! empty($wp_query->query['s_original'])) {
        $query_string = wpdb_st_sanitize_string($wp_query->query['s_original']);
    } else {
        $query_string = wpdb_st_sanitize_string($wp_query->query['s']);
        $st_results = $wp_query->found_posts;
    }

    $st_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$wpdb->prefix}wpdb_search_terms` WHERE `st_query_text` = %s", $query_string));
    if (empty($st_row->st_id)) {
        $data = array(
            'st_query_text' => $query_string,
            'st_popularity' => 1,
            'st_updated_at' => time()
        );
        if (isset($st_results)) {
            $data['st_results'] = $st_results;
        }
        $wpdb->insert(
            $wpdb->prefix . 'wpdb_search_terms',
            $data
        );
    } else {
        $data = array(
            'st_popularity' => $st_row->st_popularity + 1,
            'st_updated_at' => time()
        );
        if (isset($st_results)) {
            $data['st_results'] = $st_results;
        }
        $wpdb->update(
            $wpdb->prefix . 'wpdb_search_terms',
            $data,
            array('st_id' => $st_row->st_id)
        );
    }
}
add_action('template_redirect', 'wpdb_st_template_redirect');

function wpdb_st_admin_menu() {
    add_menu_page('Search Terms', 'Search Terms', 'manage_options', 'search-terms', 'wpdb_st_display_admin_page', 'dashicons-search');
    add_submenu_page('search-terms', 'Add New', 'Add New', 'manage_options', 'edit-search-terms', 'wpdb_st_edit_synonyms');
}
add_action('admin_menu', 'wpdb_st_admin_menu', 99);

function wpdb_st_display_admin_page() {
    global $wpdb;

    if (! isset($wpdb)) {
        return;
    }

    $st_del_id = ! empty($_GET['st_del_id']) ? absint($_GET['st_del_id']) : 0;

    if ($st_del_id > 0) {
        $wpdb->delete($wpdb->prefix . 'wpdb_search_terms', array('st_id' => $st_del_id));
        wp_redirect(admin_url() . '?page=search-terms');
        exit;
    }

    $count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}wpdb_search_terms WHERE 1=1";
    $st_query = "SELECT * FROM {$wpdb->prefix}wpdb_search_terms WHERE 1=1";
    $extra = '';

    $filter_st_query_text = ! empty($_GET['st_query_text']) ? wpdb_st_sanitize_string($_GET['st_query_text']) : '';
    $filter_st_synonym = ! empty($_GET['st_synonym']) ? wpdb_st_sanitize_string($_GET['st_synonym']) : '';
    $filter_st_redirect = ! empty($_GET['st_redirect']) ? wpdb_st_sanitize_string($_GET['st_redirect']) : '';
    $filter_st_order = ! empty($_GET['order']) && 'asc' == $_GET['order'] ? 'ASC' : 'DESC';
    $filter_st_order_new = 'ASC' == $filter_st_order ? 'desc' : 'asc';
    $filter_st_order_by = ! empty($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : '';
    $sortable_columns = array('st_popularity', 'st_results');

    if(! empty($filter_st_query_text)) {
        $extra .= " AND `st_query_text` LIKE('%$filter_st_query_text%')";
    }
    if(! empty($filter_st_synonym)) {
        $extra .= " AND `st_synonym` LIKE('%$filter_st_synonym%')";
    }
    if(! empty($filter_st_redirect)) {
        $extra .= " AND `st_redirect` LIKE('%$filter_st_redirect%')";
    }

    $st_per_page = 20;
    $st_page = isset($_GET['cpage'] ) ? absint($_GET['cpage']) : 1;
    $st_offset = ($st_page * $st_per_page) - $st_per_page;

    if (! empty($filter_st_order_by) && in_array($filter_st_order_by, $sortable_columns) && ! (empty($filter_st_order))) {
        $st_query .= $extra . " ORDER BY `$filter_st_order_by` $filter_st_order LIMIT $st_offset, $st_per_page";
    } else {
        $st_query .= $extra . " ORDER BY `st_updated_at` DESC LIMIT $st_offset, $st_per_page";
    }

    $count_query .= $extra;

    $st_total = $wpdb->get_var($count_query);
    $st_search_queries = $wpdb->get_results($st_query);

    $starting = (($st_page - 1) * $st_per_page) + 1;
    if ($starting > $st_total) {
        $starting = $st_total;
    }
    $ending = $st_per_page * $st_page;
    if ($ending > $st_total) {
        $ending = $st_total;
    }
    ?>
    <style>.tablenav .tablenav-pages a{font-size:12px}.ss-filter input{padding:5px;line-height:28px;height:28px}.ss-filter label{font-weight:700;display:block}.column-action,.column-popularity,.column-results{width:10%}</style>
    <div class="wrap">
        <h1 class="wp-heading-inline">Search Terms</h1>
        <a href="<?php echo admin_url(); ?>admin.php?page=edit-search-terms" class="page-title-action">Add New</a>
        <hr class="wp-header-end">
        <form class="ss-filter" action="<?php echo admin_url(); ?>admin.php" method="get">
            <div class="tablenav top">
                <input type="hidden" name="page" value="search-terms" />
                <input type="text" name="st_query_text" value="<?php if(! empty($filter_st_query_text)) { echo esc_attr($filter_st_query_text); } ?>" placeholder="Search Query" title="Search Query">
                <input type="text" name="st_synonym" value="<?php if(! empty($filter_st_synonym)) { echo esc_attr($filter_st_synonym); } ?>" placeholder="Synonym For" title="Synonym For">
                <input type="text" name="st_redirect" value="<?php if(! empty($filter_st_redirect)) { echo esc_attr($filter_st_redirect); } ?>" placeholder="Redirect Url" title="Redirect Url">
                <input type="submit" value="Filter" class="button">
            </div>
        </form>
        <?php if (isset($st_search_queries) && count($st_search_queries) > 0) { ?>
            <br>
            <table class="wp-list-table widefat fixed striped posts">
                <thead>
                <tr>
                    <th scope="col">Search Query</th>
                    <th scope="col">Synonym For</th>
                    <th scope="col">Redirect URL</th>
                    <th scope="col" class="column-popularity sortable <?php echo esc_attr($filter_st_order_new); ?>">
                        <a href="<?php echo admin_url(); ?>admin.php?page=search-terms&orderby=st_popularity&order=<?php echo esc_attr($filter_st_order_new); ?>">
                            <span>Popularity</span>
                            <span class="sorting-indicator"></span>
                        </a>
                    </th>
                    <th scope="col" class="column-results sortable <?php echo $filter_st_order_new; ?>">
                        <a href="<?php echo admin_url(); ?>admin.php?page=search-terms&orderby=st_results&order=<?php echo esc_attr($filter_st_order_new); ?>">
                            <span>Results</span>
                            <span class="sorting-indicator"></span>
                        </a>
                    </th>
                    <th scope="col" class="column-action">Action</th>
                </tr>
                </thead>
                <tbody id="the-list">
                <?php foreach ($st_search_queries as $st_search_query) {  ?>
                    <tr>
                        <td>
                            <a class="row-title" href="<?php echo admin_url(); ?>admin.php?page=edit-search-terms&st_id=<?php echo esc_attr($st_search_query->st_id); ?>">
                                <?php echo $st_search_query->st_query_text; ?>
                            </a>
                        </td>
                        <td><?php echo esc_html($st_search_query->st_synonym); ?></td>
                        <td><?php echo esc_html($st_search_query->st_redirect); ?></td>
                        <td class="column-popularity"><?php echo esc_html($st_search_query->st_popularity); ?></td>
                        <td class="column-popularity"><?php echo esc_html($st_search_query->st_results); ?></td>
                        <td class="column-action">
                            <a href="<?php echo admin_url(); ?>admin.php?page=search-terms&st_del_id=<?php echo esc_attr($st_search_query->st_id); ?>" class="button button-secondary button-small">Delete</a>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="nr-results">Showing <?php echo esc_html($starting);  ?> to <?php echo esc_html($ending); ?> of <?php echo esc_html($st_total); ?> results</span>
                    <span class="pagination-links">
                        <?php
                        echo paginate_links( array(
                            'base' => add_query_arg( 'cpage', '%#%' ),
                            'format' => '',
                            'prev_text' => __('&laquo;'),
                            'next_text' => __('&raquo;'),
                            'total' => ceil( $st_total / $st_per_page),
                            'current' => $st_page
                        ));
                        ?>
                    </span>
                </div>
            </div>
        <?php } else { ?>
            <p>No results</p>
        <?php } ?>
    </div>
    <?php
}

function wpdb_st_edit_synonyms() {
    global $wpdb;
    $st_id = ! empty($_GET['st_id']) ? absint($_GET['st_id']) : 0;
    $add_id = ! empty($_POST['add_id']) ? absint($_POST['add_id']) : 0;
    $st_edit_id = ! empty($_POST['st_edit_id']) ? absint($_POST['st_edit_id']) : 0;
    $st_query_text = isset($_POST['st_query_text']) ? wpdb_st_sanitize_string($_POST['st_query_text']) : '';
    $st_synonym = isset($_POST['st_synonym']) ? wpdb_st_sanitize_string($_POST['st_synonym']) : '';
    $st_redirect = isset($_POST['st_redirect']) ? wpdb_st_sanitize_string($_POST['st_redirect']) : '';

    $exists = $wpdb->get_var($wpdb->prepare("SELECT `st_id` FROM `{$wpdb->prefix}wpdb_search_terms` WHERE `st_query_text` = %s", $st_query_text));

    if (! empty($st_query_text) && $st_edit_id > 0 && (! $exists || $exists == $st_edit_id)) {
        $wpdb->update(
            $wpdb->prefix . 'wpdb_search_terms',
            array(
                'st_query_text' => $st_query_text,
                'st_synonym' => $st_synonym,
                'st_redirect' => $st_redirect,
                'st_updated_at' => time()
            ),
            array('st_id' => $st_edit_id)
        );
        $success = 1;
    } elseif (! empty($st_query_text) && $add_id > 0 && ! $exists) {
        $wpdb->insert(
            $wpdb->prefix . 'wpdb_search_terms',
            array(
                'st_query_text' => $st_query_text,
                'st_synonym' => $st_synonym,
                'st_redirect' => $st_redirect,
                'st_updated_at' => time()
            )
        );
        $success = 1;
    } elseif (! empty($st_query_text) && $exists) {
        $error = 'Error, Search Query exists';
    }

    if ($st_id > 0) {
        $st_row = $wpdb->get_row("SELECT * FROM `{$wpdb->prefix}wpdb_search_terms` WHERE `st_id` = '$st_id'");
    }
    ?>
    <style>textarea{display:inline-block;vertical-align:middle; width:500px; height: 70px;}label{font-weight:600;width:120px; display: inline-block}.ss-error,.ss-success{display:block;font-weight:600}.ss-error{color:red}.ss-success{color:green}</style>
    <div class="wrap">
        <?php if (! empty($st_row->st_id)) { ?>
            <h2>Edit Search Term</h2>
            <form action="<?php echo admin_url(); ?>admin.php?page=edit-search-terms&st_id=<?php echo esc_attr($st_id); ?>" method="post">
                <input type="hidden" name="st_edit_id" value="<?php echo $st_id; ?>" />
                <p>
                    <label>Search Query:</label>
                    <textarea name="st_query_text"><?php echo esc_textarea($st_row->st_query_text); ?></textarea>
                </p>
                <p>
                    <label>Synonym For:</label>
                    <textarea name="st_synonym"><?php echo esc_textarea($st_row->st_synonym); ?></textarea>
                </p>
                <p>
                    <label>Redirect Url:</label>
                    <textarea name="st_redirect"><?php echo esc_textarea($st_row->st_redirect); ?></textarea>
                </p>
                <p>
                    <input type="submit" value="Update" class="button button-primary button-large">
                    <a href="<?php echo admin_url(); ?>admin.php?page=search-terms" class="button button-default button-large">Return</a>
                </p>
                <p>
                    <?php if (!empty($error)) { ?>
                        <span class="ss-error"><?php echo esc_html($error); ?></span><br>
                    <?php } ?>
                    <?php if (!empty($success)) { ?>
                        <span class="ss-success">Edited successfully.</span><br>
                    <?php } ?>
                </p>
            </form>
        <?php } else { ?>
            <h2>Add Search Term</h2>
            <form action="<?php echo admin_url(); ?>admin.php?page=edit-search-terms" method="post">
                <input type="hidden" name="add_id" value="1">
                <p>
                    <label>Search Query:</label>
                    <textarea name="st_query_text"><?php echo esc_textarea($st_query_text); ?></textarea>
                </p>
                <p>
                    <label>Synonym For:</label>
                    <textarea name="st_synonym"><?php echo esc_textarea($st_synonym); ?></textarea>
                </p>
                <p>
                    <label>Redirect Url:</label>
                    <textarea name="st_redirect"><?php echo esc_textarea($st_redirect); ?></textarea>
                </p>
                <p>
                    <input type="submit" value="Save" class="button button-primary button-large">
                    <a href="<?php echo admin_url(); ?>admin.php?page=search-terms" class="button button-default button-large">Return</a>
                </p>
                <p>
                    <?php if (!empty($error)) { ?>
                        <span class="ss-error"><?php echo esc_html($error); ?></span><br>
                    <?php } ?>
                    <?php if (!empty($success)) { ?>
                        <span class="ss-success">Added successfully.</span><br>
                    <?php } ?>
                </p>
            </form>
        <?php } ?>
    </div>
    <?php
}
